<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

class SnippetExecutor {
  /**
   * Execute a PHP code snippet against a payload.
   *
   * Pre-dispatch mode:
   *   Available vars: $payload (the payload AFTER field mapping — this is what the
   *   Dispatcher hands the fswa_webhook_payload filter), $args ($payload['args'] ?? [])
   *   Snippet must `return $array;` — non-array return leaves payload unchanged.
   *
   *   $args is only a convenience alias for $payload['args']. It is populated when no
   *   mapping is configured, or when the mapping keeps unmapped fields
   *   (includeUnmapped defaults to true) — but it is an EMPTY ARRAY under
   *   includeUnmapped:false unless "args" was mapped through explicitly. That case used
   *   to fail silently (a `if ($postId)` guard simply never ran), so it now raises a
   *   notice; see $notice in the return value.
   *
   * Post-dispatch mode:
   *   Available vars: $payload (sent), $originalPayload (pre-mapping; falls back to $payload if no mapping), $responseCode, $responseBody
   *   Return value is ignored (side-effect only).
   *
   * @param string $code
   * @param array  $payload
   * @param string $mode 'pre' | 'post'
   * @param array  $postContext For mode='post': { originalPayload, responseCode, responseBody }
   * @return array{result: array, error: string|null, output: string, notice: string|null}
   */
  public function execute(string $code, array $payload, string $mode = 'pre', array $postContext = []): array {
    $args = $payload['args'] ?? [];
    $code = preg_replace('/^\s*<\?php\s*/i', '', $code) ?? $code;
    $code = $this->expandTemplateVars($code);

    $error  = null;
    $result = $payload;
    // Expansion already turned {{ $args.0.x }} into $args[0]['x'], so one check covers
    // both syntaxes. Reading $args when the mapping stripped it is not a PHP error — it
    // is a silently empty array — so surface it as a notice the caller can log/show.
    $notice = null;
    if (!array_key_exists('args', $payload) && preg_match('/\$args\b/', $code)) {
      $notice = 'This snippet reads $args, but the payload it received has no "args" key — '
        . '$args is an empty array, so any code guarded by it did not run. The field mapping '
        . 'runs before this snippet; with includeUnmapped:false only mapped target names survive. '
        . 'Map the field you need through (e.g. source "args.0" -> target "post_id") and read '
        . '$payload["post_id"] instead. Available keys: '
        . ($payload === [] ? '(none)' : implode(', ', array_keys($payload)));
    }

    $prevHandler = set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
      if (error_reporting() === 0) {
        return false;
      }
      throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
    });

    ob_start();

    try {
      if ($mode === 'post') {
        $originalPayload = $postContext['originalPayload'] ?? null;
        $responseCode    = $postContext['responseCode'] ?? 0;
        $responseBody    = $postContext['responseBody'] ?? '';
        eval($code); // phpcs:ignore Squiz.PHP.Eval.Discouraged
      } else {
        $evalResult = eval($code); // phpcs:ignore Squiz.PHP.Eval.Discouraged
        if (is_array($evalResult)) {
          $result = $evalResult;
        }
      }
    } catch (\Throwable $e) {
      $error = $e->getMessage();
      if ($e->getLine() > 0) {
        $error .= ' (line ' . $e->getLine() . ')';
      }
    }

    $output = (string) ob_get_clean();
    restore_error_handler();

    return [
      'result' => $result,
      'error'  => $error,
      'output' => $output,
      'notice' => $notice,
    ];
  }

  /**
   * Expand {{ $var.path }} shorthand to PHP array access syntax.
   *
   * Works for any in-scope variable, e.g.:
   *   {{ $args.0.total }}            → $args[0]['total']
   *   {{ $payload.key }}             → $payload['key']
   *   {{ $originalPayload.args.0 }}  → $originalPayload['args'][0]
   *   {{ $responseCode }}            → $responseCode
   */
  private function expandTemplateVars(string $code): string {
    return preg_replace_callback(
      '/\{\{\s*\$([a-zA-Z_][a-zA-Z0-9_]*)(?:\.([^\}]+?))?\s*\}\}/',
      function (array $matches): string {
        $accessor = '$' . $matches[1];
        if (!empty($matches[2])) {
          foreach (explode('.', trim($matches[2])) as $segment) {
            $accessor .= is_numeric($segment)
              ? '[' . $segment . ']'
              : "['" . addslashes($segment) . "']";
          }
        }
        return $accessor;
      },
      $code
    ) ?? $code;
  }
}
