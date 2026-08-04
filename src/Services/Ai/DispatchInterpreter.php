<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

/**
 * Turns a test_dispatch result into an actionable pause for the run, or null
 * when the endpoint accepted the delivery and the run should continue.
 *
 * Unlike {@see ProbeInterpreter} — a probe sends an EMPTY body, so a 4xx there
 * is expected noise and only auth/endpoint/transport failures are worth
 * stopping for — a test dispatch sends the REAL mapped-and-glued payload. Any
 * non-2xx therefore means the build does not work: the endpoint rejected what
 * a live delivery would send, and nothing was created on the far side.
 */
final class DispatchInterpreter {
  /**
   * @param array<string, mixed> $result The test_dispatch ability result.
   * @return array{kind:string, status:int, message:string, response:string}|null
   */
  public static function interpret(array $result): ?array {
    $status = (int) ($result['http_code'] ?? 0);
    $body   = trim((string) ($result['response'] ?? ''));

    if ($status >= 200 && $status < 300) {
      return null;
    }

    // No status at all — the request never got an HTTP response (DNS, TLS,
    // timeout, SSRF block). The log's error message is the useful part.
    if ($status === 0) {
      return [
        'kind'     => 'unreachable',
        'status'   => 0,
        'message'  => __('The test delivery never reached the endpoint. Check the URL is correct and publicly reachable, then retry.', 'flowsystems-webhook-actions'),
        'response' => $body,
      ];
    }

    if (in_array($status, [401, 403], true)) {
      return [
        'kind'    => 'auth',
        'status'  => $status,
        'message' => sprintf(
          /* translators: %d: HTTP status code (401 or 403). */
          __('The endpoint rejected the test delivery with %d — it needs authentication. Attach a credential to the webhook, then retry.', 'flowsystems-webhook-actions'),
          $status
        ),
        'response' => $body,
      ];
    }

    if (in_array($status, [404, 405, 410], true)) {
      return [
        'kind'    => 'endpoint',
        'status'  => $status,
        'message' => sprintf(
          /* translators: %d: HTTP status code (404, 405 or 410). */
          __('The endpoint responded %d — the URL may be wrong or not accept this request. Double-check the endpoint URL on the webhook, then retry.', 'flowsystems-webhook-actions'),
          $status
        ),
        'response' => $body,
      ];
    }

    // Everything else (400, 422, 5xx, stray 3xx): the endpoint was reached but
    // refused the payload we actually send. That is a payload-shape problem —
    // the field mapping or the pre-dispatch Code Glue snippet.
    return [
      'kind'    => 'rejected',
      'status'  => $status,
      'message' => sprintf(
        /* translators: %d: HTTP status code returned by the endpoint. */
        __('The endpoint rejected the test delivery with %d — nothing was created on the far side. Fix the payload (field mapping or the pre-dispatch snippet) and retry before going live.', 'flowsystems-webhook-actions'),
        $status
      ),
      'response' => $body,
    ];
  }
}
