<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Executes a list of user-defined Action rules.
 *
 * ── Action definition (one element of `actions_config` JSON) ───────────────
 * {
 *   "id":          "unique-string",       // UUID or short id
 *   "name":        "Forward to Slack",    // display name
 *   "enabled":     true,
 *   "trigger":     "received",            // see trigger constants below
 *   "condition":   "{{received.body.event}} == order.paid",  // optional
 *   "action_type": "http_post",           // see action type constants
 *   "config":      { … }                  // action-type-specific config
 * }
 *
 * ── Trigger values ────────────────────────────────────────────────────────
 *   For incoming endpoints:
 *     "received"          – fires after every successful receive
 *
 *   For outgoing webhooks:
 *     "dispatched_2xx"    – fires after a 2xx response
 *     "dispatched_4xx"    – fires after a 4xx response
 *     "dispatched_5xx"    – fires after a 5xx response
 *     "dispatched_error"  – fires on connection error / WP_Error
 *     "dispatched_any"    – fires after any delivery attempt
 *
 * ── Action types ──────────────────────────────────────────────────────────
 *   http_post         POST JSON body to URL
 *   http_request      Any method + URL + custom headers + body
 *   send_email        wp_mail()
 *   fire_hook         do_action()
 *   update_option     update_option()
 *   set_transient     set_transient()
 */
class ActionRunner {

  /**
   * Run all enabled actions for a given trigger.
   *
   * @param array<int, array<string, mixed>> $actions  Pipeline action definitions
   * @param string                           $trigger  Trigger key (e.g. 'received', 'dispatched_2xx')
   * @param array<string, mixed>             $context  TemplateRenderer context
   */
  public static function run(array $actions, string $trigger, array $context): void {
    foreach ($actions as $action) {
      if (empty($action['enabled'])) {
        continue;
      }

      $actionTrigger = $action['trigger'] ?? 'received';

      // 'dispatched_any' should match any dispatched_* event but NOT 'received'.
      $matches = $actionTrigger === $trigger ||
        ($actionTrigger === 'dispatched_any' && str_starts_with($trigger, 'dispatched_'));

      if (!$matches) {
        continue;
      }

      // Evaluate optional condition
      $condition = trim((string) ($action['condition'] ?? ''));
      if ($condition !== '') {
        $rendered = TemplateRenderer::render($condition, $context);
        if (!self::isTruthy($rendered)) {
          continue;
        }
      }

      self::execute($action, $context);
    }
  }

  /**
   * Execute a single action.
   *
   * @param array<string, mixed> $action
   * @param array<string, mixed> $context
   */
  private static function execute(array $action, array $context): void {
    $type   = $action['action_type'] ?? '';
    $config = (array) ($action['config'] ?? []);

    switch ($type) {
      case 'http_post':
        self::doHttpPost($config, $context);
        break;

      case 'http_request':
        self::doHttpRequest($config, $context);
        break;

      case 'send_email':
        self::doSendEmail($config, $context);
        break;

      case 'fire_hook':
        self::doFireHook($config, $context);
        break;

      case 'update_option':
        self::doUpdateOption($config, $context);
        break;

      case 'set_transient':
        self::doSetTransient($config, $context);
        break;
    }
  }

  // ---------------------------------------------------------------------------
  // Action implementations
  // ---------------------------------------------------------------------------

  /**
   * HTTP POST with JSON body.
   *
   * Config keys:
   *   url      string (required, supports merge tags)
   *   body     string (JSON template, supports merge tags) — defaults to full received context
   *   headers  array  key=>value (values support merge tags)
   *   timeout  int    default 30
   */
  private static function doHttpPost(array $config, array $context): void {
    $url = TemplateRenderer::render($config['url'] ?? '', $context);
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
      return;
    }

    $bodyTemplate = $config['body'] ?? '';
    if ($bodyTemplate !== '') {
      $bodyStr  = TemplateRenderer::render($bodyTemplate, $context);
      $bodyData = json_decode($bodyStr, true);
      $body     = json_last_error() === JSON_ERROR_NONE ? wp_json_encode($bodyData) : $bodyStr;
    } else {
      // Default: send the full received context as payload
      $body = wp_json_encode($context['received'] ?? []);
    }

    $headers = ['Content-Type' => 'application/json'];
    foreach ((array) ($config['headers'] ?? []) as $k => $v) {
      $headers[sanitize_text_field($k)] = TemplateRenderer::render((string) $v, $context);
    }

    $timeout = max(5, (int) ($config['timeout'] ?? 30));

    wp_remote_post($url, [
      'headers' => $headers,
      'body'    => $body,
      'timeout' => $timeout,
    ]);
  }

  /**
   * HTTP request with configurable method.
   *
   * Config keys:
   *   url      string (required, supports merge tags)
   *   method   string default POST
   *   body     string (supports merge tags)
   *   headers  array
   *   timeout  int
   */
  private static function doHttpRequest(array $config, array $context): void {
    $url = TemplateRenderer::render($config['url'] ?? '', $context);
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
      return;
    }

    $method  = strtoupper(sanitize_text_field($config['method'] ?? 'POST'));
    $body    = TemplateRenderer::render($config['body'] ?? '', $context);
    $timeout = max(5, (int) ($config['timeout'] ?? 30));

    $headers = ['Content-Type' => 'application/json'];
    foreach ((array) ($config['headers'] ?? []) as $k => $v) {
      $headers[sanitize_text_field($k)] = TemplateRenderer::render((string) $v, $context);
    }

    wp_remote_request($url, [
      'method'  => $method,
      'headers' => $headers,
      'body'    => $body ?: null,
      'timeout' => $timeout,
    ]);
  }

  /**
   * Send an email via wp_mail.
   *
   * Config keys:
   *   to       string  (supports merge tags)
   *   subject  string  (supports merge tags)
   *   message  string  (supports merge tags)
   *   headers  string  (e.g. "Content-Type: text/html")
   */
  private static function doSendEmail(array $config, array $context): void {
    $to      = sanitize_email(TemplateRenderer::render($config['to'] ?? get_option('admin_email'), $context));
    $subject = sanitize_text_field(TemplateRenderer::render($config['subject'] ?? 'Webhook action', $context));
    $message = TemplateRenderer::render($config['message'] ?? '', $context);

    if (empty($to) || empty($message)) {
      return;
    }

    $extraHeaders = TemplateRenderer::render($config['headers'] ?? '', $context);

    wp_mail($to, $subject, $message, $extraHeaders ?: []);
  }

  /**
   * Fire a WordPress action hook.
   *
   * Config keys:
   *   hook  string (required, supports merge tags)
   */
  private static function doFireHook(array $config, array $context): void {
    $hook = sanitize_key(TemplateRenderer::render($config['hook'] ?? '', $context));
    if (empty($hook)) {
      return;
    }

    do_action($hook, $context);
  }

  /**
   * Update a WordPress option.
   *
   * Config keys:
   *   option_name   string (supports merge tags)
   *   option_value  string (supports merge tags)
   *   autoload      bool   default false
   */
  private static function doUpdateOption(array $config, array $context): void {
    $name  = sanitize_key(TemplateRenderer::render($config['option_name'] ?? '', $context));
    $value = TemplateRenderer::render($config['option_value'] ?? '', $context);

    if (empty($name)) {
      return;
    }

    $autoload = (bool) ($config['autoload'] ?? false);
    update_option($name, $value, $autoload);
  }

  /**
   * Set a WordPress transient.
   *
   * Config keys:
   *   transient_key    string (supports merge tags)
   *   transient_value  string (supports merge tags)
   *   expiry           int    default HOUR_IN_SECONDS
   */
  private static function doSetTransient(array $config, array $context): void {
    $key    = sanitize_key(TemplateRenderer::render($config['transient_key'] ?? '', $context));
    $value  = TemplateRenderer::render($config['transient_value'] ?? '', $context);
    $expiry = max(0, (int) ($config['expiry'] ?? HOUR_IN_SECONDS));

    if (empty($key)) {
      return;
    }

    set_transient($key, $value, $expiry);
  }

  // ---------------------------------------------------------------------------
  // Helper
  // ---------------------------------------------------------------------------

  /**
   * Evaluate a rendered string as truthy.
   * "true", "1", "yes", "on" are truthy; everything else false-y.
   */
  private static function isTruthy(string $value): bool {
    $lower = strtolower(trim($value));
    return !empty($lower) && !in_array($lower, ['false', '0', 'no', 'off', ''], true);
  }
}
