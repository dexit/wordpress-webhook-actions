<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Executes admin-defined PHP code snippets in the context of a received webhook payload.
 *
 * The code snippet has access to the following variables:
 *   $payload   – array  – decoded request body
 *   $query     – array  – URL query parameters
 *   $headers   – array  – captured request headers (lowercase keys)
 *   $endpoint  – array  – endpoint configuration row
 *   $context   – array  – full TemplateRenderer context (received.body / query / headers / meta)
 *
 * The snippet may return any value; a non-null return replaces the default response body.
 *
 * Output (echo/print) is captured and returned in $result['output'].
 *
 * ⚠️  SECURITY NOTE: This feature executes arbitrary PHP code and is intentionally
 *     available only to administrators (manage_options) who configure endpoints.
 *     It is equivalent in trust to the Code Snippets plugin or functions.php.
 */
class EndpointFunctionRunner {

  /**
   * Execute the custom function code.
   *
   * @param string               $code     PHP source (without opening <?php tag)
   * @param array<string, mixed> $context  Full template context
   * @param array<string, mixed> $endpoint Endpoint config row
   * @return array{output: string, return: mixed, error: string|null}
   */
  public function run(string $code, array $context, array $endpoint): array {
    if (empty(trim($code))) {
      return ['output' => '', 'return' => null, 'error' => null];
    }

    // Expose convenience variables
    $payload  = $context['received']['body']    ?? [];
    $query    = $context['received']['query']   ?? [];
    $headers  = $context['received']['headers'] ?? [];

    $result = ['output' => '', 'return' => null, 'error' => null];

    ob_start();
    try {
      // phpcs:ignore Squiz.PHP.Eval.Discouraged
      $returnValue = eval($code);
      $result['return'] = $returnValue;
    } catch (\Throwable $e) {
      $result['error'] = $e->getMessage();
    } finally {
      $result['output'] = (string) ob_get_clean();
    }

    return $result;
  }

  /**
   * Fire WordPress action hooks with the payload context.
   *
   * @param string               $hooksCsv  Comma-separated list of hook names
   * @param array<string, mixed> $context   Full template context
   * @param array<string, mixed> $endpoint  Endpoint config row
   */
  public function fireHooks(string $hooksCsv, array $context, array $endpoint): void {
    $hooks = array_filter(array_map('trim', explode(',', $hooksCsv)));

    foreach ($hooks as $hook) {
      $hook = sanitize_key(str_replace([' ', '-'], '_', $hook));
      if (!empty($hook)) {
        /**
         * Dynamic action hook fired when an incoming endpoint receives a request.
         *
         * Hook name format: fswa_endpoint_{hook_name}
         *
         * @param array $context  Full template context (received.body / query / headers / meta).
         * @param array $endpoint Endpoint configuration row.
         */
        do_action('fswa_endpoint_' . $hook, $context, $endpoint);

        // Also fire the raw hook name so integrations can use standard WP hooks
        do_action($hook, $context, $endpoint);
      }
    }
  }
}
