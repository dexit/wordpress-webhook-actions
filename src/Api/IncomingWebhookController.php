<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\IncomingEndpointRepository;
use FlowSystems\WebhookActions\Repositories\IncomingPayloadRepository;

/**
 * Public receiver controller for custom incoming webhook endpoints.
 *
 * Each enabled endpoint is exposed at:
 *   POST /wp-json/fswa/v1/in/{slug}
 *
 * No WordPress authentication is required — the endpoint is intentionally
 * public so external services can POST to it. Security is handled via
 * optional HMAC signature verification using a per-endpoint secret key.
 *
 * Received payloads are persisted in wp_fswa_incoming_payloads with status
 * "received" and are ready for downstream ETL/DTO pipeline consumption.
 *
 * Fires the action hook `fswa_incoming_payload_received` after a successful
 * save, enabling third-party code or a future ETL feature to react
 * immediately without polling.
 */
class IncomingWebhookController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'in';

  /** Headers that are safe to capture and store (lowercase) */
  private const CAPTURED_HEADERS = [
    'content-type',
    'content-length',
    'user-agent',
    'x-forwarded-for',
    'x-real-ip',
    'x-request-id',
    'x-correlation-id',
    'x-event-type',
    'x-event-id',
    'x-webhook-id',
    'x-delivery-id',
    'x-github-event',
    'x-gitlab-event',
    'x-stripe-signature',
    'x-hub-signature',
    'x-hub-signature-256',
  ];

  private IncomingEndpointRepository $endpoints;
  private IncomingPayloadRepository  $payloads;

  public function __construct() {
    $this->endpoints = new IncomingEndpointRepository();
    $this->payloads  = new IncomingPayloadRepository();
  }

  /**
   * Register the public receiver route.
   *
   * The route accepts all common HTTP methods so external services using
   * GET, PUT, or PATCH webhooks are also supported.
   */
  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<slug>[a-z0-9\-_]+)', [
      'methods'             => implode(',', [
        WP_REST_Server::READABLE,
        WP_REST_Server::CREATABLE,
        WP_REST_Server::EDITABLE,
        'DELETE',
        'PATCH',
      ]),
      'callback'            => [$this, 'receive'],
      'permission_callback' => '__return_true', // Public — auth handled inside
      'args'                => [
        'slug' => [
          'description'       => __('Endpoint slug.', 'flowsystems-webhook-actions'),
          'type'              => 'string',
          'sanitize_callback' => 'sanitize_title',
          'required'          => true,
        ],
      ],
    ]);
  }

  /**
   * Handle an incoming webhook request.
   */
  public function receive(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $slug     = sanitize_title($request->get_param('slug'));
    $endpoint = $this->endpoints->findBySlug($slug);

    if (!$endpoint) {
      return new WP_Error(
        'fswa_endpoint_not_found',
        __('Webhook endpoint not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    if (!$endpoint['is_enabled']) {
      return new WP_Error(
        'fswa_endpoint_disabled',
        __('This webhook endpoint is disabled.', 'flowsystems-webhook-actions'),
        ['status' => 503]
      );
    }

    // Verify HMAC signature when a secret key is configured
    if (!empty($endpoint['secret_key'])) {
      $signatureError = $this->verifySignature($request, $endpoint);
      if ($signatureError !== null) {
        return $signatureError;
      }
    }

    // Capture the raw body
    $rawBody = $request->get_body();

    // Normalise payload to JSON string for storage
    $payloadJson = $this->normalisePayload($rawBody, $request);

    // Capture a safe subset of request headers
    $headers = $this->captureHeaders($request);

    // Derive client IP
    $sourceIp = $this->resolveClientIp();

    // Persist
    $payloadId = $this->payloads->create([
      'endpoint_id'  => $endpoint['id'],
      'payload'      => $payloadJson,
      'headers'      => wp_json_encode($headers),
      'method'       => $request->get_method(),
      'source_ip'    => $sourceIp,
      'content_type' => $this->getContentType($request),
      'status'       => 'received',
    ]);

    if (!$payloadId) {
      return new WP_Error(
        'fswa_storage_failed',
        __('Failed to store incoming payload.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    /**
     * Fires after an incoming payload has been successfully stored.
     *
     * @param int   $payloadId  ID of the newly created payload record.
     * @param array $endpoint   Endpoint configuration array.
     * @param string $rawBody   Raw request body.
     */
    do_action('fswa_incoming_payload_received', $payloadId, $endpoint, $rawBody);

    return $this->buildResponse($endpoint);
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Verify HMAC signature of the incoming request.
   *
   * Supports the common patterns used by GitHub, Stripe, and generic
   * webhook providers:
   *  - GitHub:  X-Hub-Signature-256: sha256=<hex>
   *  - Stripe:  Stripe-Signature: t=...,v1=<hex>   (first v1 value used)
   *  - Generic: configurable header, value is raw hex digest
   *
   * @return WP_Error|null  null on success, WP_Error on failure
   */
  private function verifySignature(WP_REST_Request $request, array $endpoint): ?WP_Error {
    $secret    = $endpoint['secret_key'];
    $algorithm = $endpoint['hmac_algorithm'] ?? 'sha256';
    $header    = $endpoint['hmac_header'] ?? null;

    // Determine which header to inspect
    if (empty($header)) {
      // Auto-detect common signature headers
      $header = $this->detectSignatureHeader($request);
    }

    if (empty($header)) {
      return new WP_Error(
        'fswa_missing_signature',
        __('Signature header not found. Configure hmac_header or use a common signature header.', 'flowsystems-webhook-actions'),
        ['status' => 401]
      );
    }

    $rawSignature = $request->get_header($header);

    if (empty($rawSignature)) {
      return new WP_Error(
        'fswa_missing_signature',
        /* translators: %s: header name */
        sprintf(__('Missing signature header: %s', 'flowsystems-webhook-actions'), esc_html($header)),
        ['status' => 401]
      );
    }

    $body     = $request->get_body();
    $expected = $this->computeSignature($body, $secret, $algorithm, $rawSignature);

    if (!hash_equals($expected, $this->extractSignature($rawSignature, $algorithm))) {
      return new WP_Error(
        'fswa_invalid_signature',
        __('Signature verification failed.', 'flowsystems-webhook-actions'),
        ['status' => 401]
      );
    }

    return null;
  }

  /**
   * Detect a common HMAC signature header from the request.
   */
  private function detectSignatureHeader(WP_REST_Request $request): string {
    $candidates = [
      'x-hub-signature-256',
      'x-hub-signature',
      'x-stripe-signature',
    ];

    foreach ($candidates as $candidate) {
      if ($request->get_header($candidate) !== null) {
        return $candidate;
      }
    }

    return '';
  }

  /**
   * Compute the expected HMAC digest for the given body.
   */
  private function computeSignature(string $body, string $secret, string $algorithm, string $rawSignature): string {
    // For Stripe format "t=...,v1=<hex>" extract just the body for HMAC
    // Stripe signs "timestamp.body" but we compute just body here for simplicity;
    // full Stripe tolerance is left for a dedicated integration.
    return hash_hmac($algorithm, $body, $secret);
  }

  /**
   * Extract the raw hex digest from various signature header formats.
   *
   * Supports:
   *  - "sha256=<hex>"   (GitHub style)
   *  - "sha1=<hex>"     (GitHub legacy)
   *  - "t=...,v1=<hex>" (Stripe style — takes the first v1 value)
   *  - Plain hex        (Generic)
   */
  private function extractSignature(string $rawSignature, string $algorithm): string {
    // GitHub: sha256=abc123 or sha1=abc123
    if (preg_match('/^(?:sha256|sha1|sha512)=([0-9a-f]+)$/i', $rawSignature, $m)) {
      return strtolower($m[1]);
    }

    // Stripe: t=...,v1=abc123
    if (preg_match('/v1=([0-9a-f]+)/i', $rawSignature, $m)) {
      return strtolower($m[1]);
    }

    // Plain hex
    return strtolower($rawSignature);
  }

  /**
   * Normalise the request body to a JSON string for consistent storage.
   * JSON bodies are stored as-is; form-encoded bodies are converted to JSON.
   */
  private function normalisePayload(string $rawBody, WP_REST_Request $request): string {
    $contentType = $this->getContentType($request);

    // Already JSON — store raw
    if (str_contains($contentType, 'application/json')) {
      // Validate it is actually parseable JSON; fall back to wrapping it if not
      $decoded = json_decode($rawBody, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $rawBody;
      }
    }

    // Form-encoded
    if (str_contains($contentType, 'application/x-www-form-urlencoded') || str_contains($contentType, 'multipart/form-data')) {
      $params = $request->get_body_params();
      if (!empty($params)) {
        return wp_json_encode($params) ?: wp_json_encode(['_raw' => $rawBody]);
      }
    }

    // Fallback: wrap raw body in a JSON envelope
    if (!empty($rawBody)) {
      return wp_json_encode(['_raw' => $rawBody]) ?: '{}';
    }

    // Empty body — store empty object
    return '{}';
  }

  /**
   * Capture a safe, filtered subset of request headers.
   *
   * @return array<string, string>
   */
  private function captureHeaders(WP_REST_Request $request): array {
    $captured = [];

    foreach (self::CAPTURED_HEADERS as $header) {
      $value = $request->get_header($header);
      if ($value !== null) {
        $captured[$header] = $value;
      }
    }

    return $captured;
  }

  /**
   * Resolve the client IP address, respecting common proxy headers.
   */
  private function resolveClientIp(): string {
    // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    // phpcs:enable

    // X-Forwarded-For may be a comma-separated list; take the first entry
    $ip = explode(',', $ip)[0];
    $ip = trim($ip);

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
  }

  /**
   * Extract and normalise the content-type (strip parameters like charset).
   */
  private function getContentType(WP_REST_Request $request): string {
    $ct = $request->get_content_type();
    if (is_array($ct)) {
      return strtolower($ct['value'] ?? '');
    }
    return strtolower((string) $ct);
  }

  /**
   * Build the HTTP response for the caller.
   *
   * Uses the per-endpoint configured response_code and optional response_body.
   * Defaults to 200 OK with a minimal JSON acknowledgement.
   *
   * @param array<string, mixed> $endpoint
   */
  private function buildResponse(array $endpoint): WP_REST_Response {
    $code = (int) ($endpoint['response_code'] ?? 200);
    $body = $endpoint['response_body'] ?? null;

    if (!empty($body)) {
      // Try to decode configured body as JSON for a proper JSON response
      $decoded = json_decode($body, true);
      $data    = (json_last_error() === JSON_ERROR_NONE) ? $decoded : ['message' => $body];
    } else {
      $data = ['received' => true];
    }

    return new WP_REST_Response($data, $code);
  }
}
