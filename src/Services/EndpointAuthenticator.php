<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use WP_REST_Request;
use WP_Error;

/**
 * Verifies incoming request authentication for a custom endpoint.
 *
 * Supported modes:
 *   none     – no verification (open endpoint)
 *   hmac     – HMAC-SHA256/SHA1/SHA512 signature header (GitHub / Stripe / generic)
 *   basic    – HTTP Basic Auth (username:password)
 *   bearer   – Authorization: Bearer <token>
 *   api_key  – static key in a configurable header or query param
 */
class EndpointAuthenticator {

  /** @var array<string, mixed> */
  private array $endpoint;

  /** @var array<string, mixed> */
  private array $config;

  public function __construct(array $endpoint) {
    $this->endpoint = $endpoint;
    $this->config   = !empty($endpoint['auth_config'])
      ? (json_decode($endpoint['auth_config'], true) ?? [])
      : [];
  }

  /**
   * Authenticate the request.
   *
   * @return WP_Error|null  null = authenticated (or auth skipped), WP_Error on failure
   */
  public function authenticate(WP_REST_Request $request): ?WP_Error {
    $mode = $this->endpoint['auth_mode'] ?? 'none';

    // Legacy: if secret_key is set and auth_mode was not yet updated, treat as hmac
    if ($mode === 'none' && !empty($this->endpoint['secret_key'])) {
      $mode = 'hmac';
    }

    switch ($mode) {
      case 'hmac':
        return $this->verifyHmac($request);
      case 'basic':
        return $this->verifyBasic($request);
      case 'bearer':
        return $this->verifyBearer($request);
      case 'api_key':
        return $this->verifyApiKey($request);
      case 'none':
      default:
        return null;
    }
  }

  // ---------------------------------------------------------------------------
  // HMAC
  // ---------------------------------------------------------------------------

  private function verifyHmac(WP_REST_Request $request): ?WP_Error {
    // Secret can live in the dedicated column or in auth_config
    $secret    = $this->endpoint['secret_key'] ?? ($this->config['secret'] ?? '');
    $algorithm = $this->endpoint['hmac_algorithm'] ?? ($this->config['algorithm'] ?? 'sha256');
    $header    = $this->endpoint['hmac_header'] ?? ($this->config['header'] ?? '');

    if (empty($secret)) {
      return null; // no secret configured = skip
    }

    if (empty($header)) {
      $header = $this->detectSignatureHeader($request);
    }

    if (empty($header)) {
      return new WP_Error('fswa_missing_signature', 'Signature header not present.', ['status' => 401]);
    }

    $raw = $request->get_header($header);
    if (empty($raw)) {
      return new WP_Error(
        'fswa_missing_signature',
        /* translators: %s: header name */
        sprintf('Missing signature header: %s', esc_html($header)),
        ['status' => 401]
      );
    }

    $body     = $request->get_body();
    $computed = hash_hmac($algorithm, $body, $secret);
    $provided = $this->extractHexDigest($raw);

    if (!hash_equals($computed, $provided)) {
      return new WP_Error('fswa_invalid_signature', 'Signature verification failed.', ['status' => 401]);
    }

    return null;
  }

  private function detectSignatureHeader(WP_REST_Request $request): string {
    foreach (['x-hub-signature-256', 'x-hub-signature', 'x-stripe-signature'] as $h) {
      if ($request->get_header($h) !== null) {
        return $h;
      }
    }
    return '';
  }

  private function extractHexDigest(string $raw): string {
    // sha256=abc... (GitHub)
    if (preg_match('/^(?:sha256|sha1|sha512)=([0-9a-f]+)$/i', $raw, $m)) {
      return strtolower($m[1]);
    }
    // v1=abc... (Stripe)
    if (preg_match('/v1=([0-9a-f]+)/i', $raw, $m)) {
      return strtolower($m[1]);
    }
    return strtolower($raw);
  }

  // ---------------------------------------------------------------------------
  // Basic Auth
  // ---------------------------------------------------------------------------

  private function verifyBasic(WP_REST_Request $request): ?WP_Error {
    $authHeader = $request->get_header('authorization') ?? '';

    if (!str_starts_with($authHeader, 'Basic ')) {
      return new WP_Error('fswa_auth_failed', 'HTTP Basic authentication required.', ['status' => 401]);
    }

    $decoded = base64_decode(substr($authHeader, 6), true);
    if ($decoded === false || !str_contains($decoded, ':')) {
      return new WP_Error('fswa_auth_failed', 'Malformed Basic auth credentials.', ['status' => 401]);
    }

    [$username, $password] = explode(':', $decoded, 2);

    $expectedUser = $this->config['username'] ?? '';
    $expectedPass = $this->config['password'] ?? '';

    if (!hash_equals($expectedUser, $username) || !hash_equals($expectedPass, $password)) {
      return new WP_Error('fswa_auth_failed', 'Invalid credentials.', ['status' => 401]);
    }

    return null;
  }

  // ---------------------------------------------------------------------------
  // Bearer Token
  // ---------------------------------------------------------------------------

  private function verifyBearer(WP_REST_Request $request): ?WP_Error {
    $authHeader = $request->get_header('authorization') ?? '';

    if (!str_starts_with($authHeader, 'Bearer ')) {
      // Also check query param fallback
      $queryToken = $request->get_param($this->config['param'] ?? 'access_token') ?? '';
      if (empty($queryToken)) {
        return new WP_Error('fswa_auth_failed', 'Bearer token required.', ['status' => 401]);
      }
      $provided = $queryToken;
    } else {
      $provided = substr($authHeader, 7);
    }

    $expected = $this->config['token'] ?? '';

    if (empty($expected) || !hash_equals($expected, $provided)) {
      return new WP_Error('fswa_auth_failed', 'Invalid bearer token.', ['status' => 401]);
    }

    return null;
  }

  // ---------------------------------------------------------------------------
  // API Key
  // ---------------------------------------------------------------------------

  private function verifyApiKey(WP_REST_Request $request): ?WP_Error {
    $headerName = $this->config['header'] ?? 'X-API-Key';
    $paramName  = $this->config['param'] ?? 'api_key';
    $expected   = $this->config['key'] ?? '';

    $provided = $request->get_header($headerName) ?? $request->get_param($paramName) ?? '';

    if (empty($expected) || !hash_equals($expected, (string) $provided)) {
      return new WP_Error('fswa_auth_failed', 'Invalid API key.', ['status' => 401]);
    }

    return null;
  }
}
