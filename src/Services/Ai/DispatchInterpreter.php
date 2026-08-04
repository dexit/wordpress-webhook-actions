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

  /**
   * A stable signature for "the endpoint refused this in the same way again":
   * status plus the response body with digits collapsed and whitespace
   * normalised, so a rejection differing only by a number inside the same
   * message (a record index, a timestamp) still counts as the same failure.
   *
   * Deliberately conservative: an endpoint that adds a whole volatile FIELD to
   * each response (a per-request id key) produces a different signature, so the
   * escalation simply does not fire. Missing a loop costs one wasted round;
   * accusing the model of repeating itself when the endpoint actually said
   * something new would send it chasing the wrong thing.
   *
   * @param array{kind:string, status:int, message:string, response:string} $dispatch
   */
  public static function signature(array $dispatch): string {
    $body = strtolower((string) $dispatch['response']);
    $body = preg_replace('/\d+/', '#', $body) ?? $body;

    return md5($dispatch['kind'] . '|' . $dispatch['status'] . '|' . preg_replace('/\s+/', ' ', trim($body)));
  }

  /**
   * Escalate a rejection the endpoint has now refused identically more than
   * once. Retrying the same idea is the failure mode we actually see: told only
   * "fix the payload", a model re-formats the SAME field a second and third way
   * (Airtable's date column: "Y-m-d H:i:s" → date('c') → gmdate with ms) while
   * the real answer is elsewhere in the API's contract — a request-level option,
   * an envelope key, a different column type. So name the loop and redirect it.
   *
   * @param array{kind:string, status:int, message:string, response:string} $dispatch
   * @return array{kind:string, status:int, message:string, response:string, repeat?:int}
   */
  public static function escalate(array $dispatch, int $attempt): array {
    if ($attempt < 2) {
      return $dispatch;
    }

    $dispatch['repeat']   = $attempt;
    $dispatch['message'] .= ' ' . sprintf(
      /* translators: %d: how many times this identical failure has now occurred. */
      __('This is the same rejection for the %d time running — the endpoint returned an identical response, so the last change did not touch what it objects to. Do not reformat that value again. Read the endpoint\'s own error text and ask what its API contract wants that the request does not carry: a request-level option or flag (many APIs need one before they will coerce or accept a value), a wrapper key the record has to sit inside, a field that must be a different type, or a value that must already exist on the far side. Name the specific API and what you believe it needs; if you are not sure, say so plainly and ask the user to confirm it from that API\'s documentation instead of guessing a third format.', 'flowsystems-webhook-actions'),
      $attempt
    );

    return $dispatch;
  }
}
