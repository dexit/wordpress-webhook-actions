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
use FlowSystems\WebhookActions\Repositories\EndpointLogRepository;
use FlowSystems\WebhookActions\Repositories\DtoPipelineRepository;
use FlowSystems\WebhookActions\Services\DtoPipelineProcessor;
use FlowSystems\WebhookActions\Services\EndpointAuthenticator;
use FlowSystems\WebhookActions\Services\TemplateRenderer;
use FlowSystems\WebhookActions\Services\CptMapper;
use FlowSystems\WebhookActions\Services\EndpointFunctionRunner;

/**
 * Public receiver for custom incoming webhook endpoints.
 *
 * Route:  /wp-json/fswa/v1/in/{slug}
 * Methods: GET, POST, PUT, PATCH, DELETE (all accepted; filtered per-endpoint)
 *
 * Pipeline per request:
 *   1. Resolve endpoint by slug
 *   2. Check allowed HTTP methods
 *   3. Authenticate (none / HMAC / Basic / Bearer / API-key)
 *   4. Capture payload + query params + headers
 *   5. Store payload → wp_fswa_incoming_payloads
 *   6. Build template context
 *   7. Run CPT mapper (if enabled)
 *   8. Execute custom PHP function (if enabled)
 *   9. Fire configured WP action hooks
 *  10. Write endpoint log → wp_fswa_endpoint_logs
 *  11. Return HTTP response (code + body, optionally template-rendered)
 */
class IncomingWebhookController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'in';

  /** Safe headers to capture */
  private const CAPTURED_HEADERS = [
    'content-type', 'content-length', 'user-agent',
    'x-forwarded-for', 'x-real-ip', 'x-request-id',
    'x-correlation-id', 'x-event-type', 'x-event-id',
    'x-webhook-id', 'x-delivery-id',
    'x-github-event', 'x-gitlab-event',
    'x-stripe-signature', 'x-hub-signature', 'x-hub-signature-256',
  ];

  private IncomingEndpointRepository $endpoints;
  private IncomingPayloadRepository  $payloads;
  private EndpointLogRepository      $logs;
  private DtoPipelineRepository      $dtoPipelines;
  private CptMapper                  $cptMapper;
  private EndpointFunctionRunner     $functionRunner;

  public function __construct() {
    $this->endpoints      = new IncomingEndpointRepository();
    $this->payloads       = new IncomingPayloadRepository();
    $this->logs           = new EndpointLogRepository();
    $this->dtoPipelines   = new DtoPipelineRepository();
    $this->cptMapper      = new CptMapper();
    $this->functionRunner = new EndpointFunctionRunner();
  }

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
      'permission_callback' => '__return_true',
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
   * Handle an incoming request.
   */
  public function receive(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $startTime = microtime(true);

    $slug     = sanitize_title($request->get_param('slug'));
    $endpoint = $this->endpoints->findBySlug($slug);

    if (!$endpoint) {
      return new WP_Error('fswa_endpoint_not_found', 'Webhook endpoint not found.', ['status' => 404]);
    }

    if (!$endpoint['is_enabled']) {
      return new WP_Error('fswa_endpoint_disabled', 'This endpoint is disabled.', ['status' => 503]);
    }

    $method = strtoupper($request->get_method());

    // Check allowed methods
    $allowed = $endpoint['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    if (!in_array($method, (array) $allowed, true)) {
      $this->writeLog($endpoint, null, $method, $request, 405, 'skipped', $startTime, 'Method not allowed');
      return new WP_Error(
        'fswa_method_not_allowed',
        sprintf('Method %s is not allowed for this endpoint.', $method),
        ['status' => 405]
      );
    }

    // Authenticate
    $authenticator = new EndpointAuthenticator($endpoint);
    $authError     = $authenticator->authenticate($request);
    $authResult    = $authError ? 'failed' : ((($endpoint['auth_mode'] ?? 'none') === 'none') ? 'skipped' : 'success');

    if ($authError) {
      $this->writeLog($endpoint, null, $method, $request, $authError->get_error_data()['status'] ?? 401, 'failed', $startTime, $authError->get_error_message());
      return $authError;
    }

    // Capture inputs
    $rawBody     = $request->get_body();
    $queryParams = $this->captureQueryParams($request);
    $headers     = $this->captureHeaders($request);
    $sourceIp    = $this->resolveClientIp();
    $contentType = $this->getContentType($request);
    $payloadJson = $this->normaliseBody($rawBody, $request);
    $payloadArr  = json_decode($payloadJson, true) ?? [];

    // Store payload
    $payloadId = $this->payloads->create([
      'endpoint_id'  => $endpoint['id'],
      'payload'      => $payloadJson,
      'headers'      => wp_json_encode($headers),
      'method'       => $method,
      'source_ip'    => $sourceIp,
      'content_type' => $contentType,
      'status'       => 'received',
    ]);

    // Build template context
    $context = TemplateRenderer::buildContext(
      $payloadArr,
      $queryParams,
      $headers,
      [
        'method'        => $method,
        'source_ip'     => $sourceIp,
        'endpoint_slug' => $endpoint['slug'],
        'received_at'   => current_time('c'),
      ]
    );

    // DTO/ETL pipeline — runs before CPT mapper and function runner so $dto is
    // available as {{dto.field}} in templates and as $dto in custom PHP code.
    if (!empty($endpoint['dto_pipeline_id'])) {
      $pipeline = $this->dtoPipelines->find((int) $endpoint['dto_pipeline_id']);
      if ($pipeline && $pipeline['is_enabled'] && !empty($pipeline['pipeline_config'])) {
        $dto = DtoPipelineProcessor::process($pipeline['pipeline_config'], $context);
        // Merge DTO into context under 'dto' key so templates can use {{dto.field}}
        $context['dto'] = $dto;
        // Also expose on received.dto for dot-path access
        $context['received']['dto'] = $dto;
      }
    }

    // CPT mapping
    $cptPostId = null;
    if (!empty($endpoint['cpt_enabled'])) {
      $cptPostId = $this->cptMapper->process($endpoint, $context) ?: null;
    }

    // Custom PHP function
    $functionOutput   = null;
    $functionExecuted = false;
    $functionReturn   = null;
    if (!empty($endpoint['function_enabled']) && !empty($endpoint['function_code'])) {
      $fnResult         = $this->functionRunner->run($endpoint['function_code'], $context, $endpoint);
      $functionExecuted = true;
      $functionOutput   = ($fnResult['output'] ?? '') . ($fnResult['error'] ? ' [ERROR: ' . $fnResult['error'] . ']' : '');
      $functionReturn   = $fnResult['return'];
    }

    // Fire configured hooks
    if (!empty($endpoint['hooks_to_fire'])) {
      $this->functionRunner->fireHooks($endpoint['hooks_to_fire'], $context, $endpoint);
    }

    // Fire generic received action
    do_action('fswa_incoming_payload_received', $payloadId, $endpoint, $rawBody, $context);

    // Fire per-endpoint action — allows outgoing webhooks to subscribe to this endpoint as a trigger.
    // Trigger name: fswa_endpoint_{slug}
    // Args[0]: received context (body, query, headers, meta) — accessible in field mapping as received.*
    // Args[1]: payload ID (int)
    // Args[2]: endpoint info array (id, name, slug)
    do_action(
      'fswa_endpoint_' . $slug,
      $context['received'] ?? [],
      $payloadId,
      ['id' => $endpoint['id'], 'name' => $endpoint['name'], 'slug' => $slug]
    );

    // Write log
    $responseCode = (int) ($endpoint['response_code'] ?? 200);
    $this->writeLog($endpoint, $payloadId, $method, $request, $responseCode, $authResult, $startTime, null, $cptPostId, $functionExecuted, $functionOutput, $queryParams);

    // Build response
    return $this->buildResponse($endpoint, $context, $functionReturn);
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Capture URL query parameters, excluding WordPress internals.
   *
   * @return array<string, string>
   */
  private function captureQueryParams(WP_REST_Request $request): array {
    $params  = $request->get_query_params();
    $exclude = ['rest_route', '_method'];
    $result  = [];

    foreach ($params as $key => $value) {
      $key = (string) $key;
      if (in_array($key, $exclude, true) || str_starts_with($key, '_')) {
        continue;
      }
      $result[sanitize_key($key)] = is_array($value)
        ? array_map('sanitize_text_field', $value)
        : sanitize_text_field((string) $value);
    }

    return $result;
  }

  /**
   * Capture a safe subset of request headers.
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
   * Normalise request body to a JSON string.
   */
  private function normaliseBody(string $rawBody, WP_REST_Request $request): string {
    $ct = $this->getContentType($request);

    if (str_contains($ct, 'application/json')) {
      $decoded = json_decode($rawBody, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $rawBody;
      }
    }

    if (str_contains($ct, 'application/x-www-form-urlencoded') || str_contains($ct, 'multipart/form-data')) {
      $params = $request->get_body_params();
      if (!empty($params)) {
        return wp_json_encode($params) ?: wp_json_encode(['_raw' => $rawBody]);
      }
    }

    if (!empty($rawBody)) {
      // Try JSON first even if content-type wasn't set correctly
      $decoded = json_decode($rawBody, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $rawBody;
      }
      return wp_json_encode(['_raw' => $rawBody]) ?: '{}';
    }

    return '{}';
  }

  /**
   * Resolve client IP.
   */
  private function resolveClientIp(): string {
    // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    // phpcs:enable
    $ip = trim(explode(',', $ip)[0]);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
  }

  /**
   * Get normalised content-type (without params like charset).
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
   * If the endpoint has a response_body template, render it with the context.
   * If the custom function returned a value, use that as response data.
   */
  private function buildResponse(array $endpoint, array $context, mixed $functionReturn): WP_REST_Response {
    $code = (int) ($endpoint['response_code'] ?? 200);
    $body = $endpoint['response_body'] ?? null;

    // Function return takes priority
    if ($functionReturn !== null) {
      $data = is_array($functionReturn) ? $functionReturn : ['result' => (string) $functionReturn];
      return new WP_REST_Response($data, $code);
    }

    if (!empty($body)) {
      // Render merge tags in response body
      $rendered = TemplateRenderer::render($body, $context);
      $decoded  = json_decode($rendered, true);
      $data     = (json_last_error() === JSON_ERROR_NONE) ? $decoded : ['message' => $rendered];
    } else {
      $data = ['received' => true];
    }

    return new WP_REST_Response($data, $code);
  }

  /**
   * Write a log entry.
   */
  private function writeLog(
    array  $endpoint,
    ?int   $payloadId,
    string $method,
    WP_REST_Request $request,
    int    $responseCode,
    string $authResult,
    float  $startTime,
    ?string $errorMessage   = null,
    ?int   $cptPostId       = null,
    bool   $fnExecuted      = false,
    ?string $fnOutput       = null,
    array  $queryParams     = []
  ): void {
    $durationMs = (int) round((microtime(true) - $startTime) * 1000);

    $this->logs->create([
      'endpoint_id'       => $endpoint['id'],
      'payload_id'        => $payloadId,
      'method'            => $method,
      'query_params'      => $queryParams,
      'response_code'     => $responseCode,
      'auth_result'       => $authResult,
      'duration_ms'       => $durationMs,
      'source_ip'         => $this->resolveClientIp(),
      'error_message'     => $errorMessage,
      'cpt_post_id'       => $cptPostId,
      'function_executed' => $fnExecuted,
      'function_output'   => $fnOutput,
    ]);
  }
}
