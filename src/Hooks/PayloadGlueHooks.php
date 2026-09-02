<?php

namespace FlowSystems\WebhookActions\Hooks;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\TriggerSnippetsRepository;
use FlowSystems\WebhookActions\Repositories\SnippetsRepository;
use FlowSystems\WebhookActions\Services\SnippetExecutor;

class PayloadGlueHooks {
  private TriggerSnippetsRepository $triggerSnippets;
  private SnippetsRepository $snippets;
  private SnippetExecutor $executor;

  public function __construct() {
    $this->triggerSnippets = new TriggerSnippetsRepository();
    $this->snippets        = new SnippetsRepository();
    $this->executor        = new SnippetExecutor();
  }

  public function init(): void {
    add_filter('fswa_webhook_payload', [$this, 'applyPreDispatchSnippet'], 10, 4);
    add_action('fswa_glue_post_dispatch', [$this, 'applyPostDispatchSnippet'], 10, 7);
    add_filter('fswa_webhook_url', [$this, 'expandUrlTemplates'], 10, 5);
  }

  /**
   * @param array      $payload         The mapped payload about to be sent.
   * @param int        $webhookId
   * @param string     $trigger
   * @param array|null $originalPayload Pre-mapping payload.
   * @return array Modified payload.
   */
  public function applyPreDispatchSnippet(array $payload, int $webhookId, string $trigger, ?array $originalPayload): array {
    $assignment = $this->triggerSnippets->findByWebhookAndTrigger($webhookId, $trigger);

    if (!$assignment || !$assignment['pre_enabled'] || empty($assignment['pre_snippet_id'])) {
      return $payload;
    }

    $snippet = $this->snippets->find((int) $assignment['pre_snippet_id']);
    if (!$snippet || trim($snippet['code']) === '') {
      return $payload;
    }

    $result = $this->executor->execute($snippet['code'], $payload, 'pre');
    if (!empty($result['error'])) {
      // The executor already contained the failure (payload falls back
      // unmodified) — but never silently: surface it for logs and listeners.
      $this->reportSnippetError($webhookId, $trigger, 'pre', (int) $assignment['pre_snippet_id'], (string) $result['error']);
    }
    if (!empty($result['notice'])) {
      // Not a PHP error — the snippet ran clean but against a payload shape it did
      // not expect (typically $args stripped by the mapping), so it quietly did
      // nothing. Same reporting path, so it is just as visible as a hard failure.
      $this->reportSnippetError($webhookId, $trigger, 'pre', (int) $assignment['pre_snippet_id'], (string) $result['notice']);
    }

    return is_array($result['result']) ? $result['result'] : $payload;
  }

  /**
   * @param int        $webhookId
   * @param string     $trigger
   * @param int        $responseCode
   * @param string     $responseBody
   * @param array      $payload         The mapped payload that was sent.
   * @param array      $webhook
   * @param array|null $originalPayload Pre-mapping payload.
   */
  public function applyPostDispatchSnippet(
    int $webhookId,
    string $trigger,
    int $responseCode,
    string $responseBody,
    array $payload,
    array $webhook,
    ?array $originalPayload
  ): void {
    $assignment = $this->triggerSnippets->findByWebhookAndTrigger($webhookId, $trigger);

    if (!$assignment || !$assignment['post_enabled'] || empty($assignment['post_snippet_id'])) {
      return;
    }

    $snippet = $this->snippets->find((int) $assignment['post_snippet_id']);
    if (!$snippet || trim($snippet['code']) === '') {
      return;
    }

    $result = $this->executor->execute($snippet['code'], $payload, 'post', [
      'originalPayload' => $originalPayload,
      'responseCode'    => $responseCode,
      'responseBody'    => $responseBody,
    ]);
    if (!empty($result['error'])) {
      $this->reportSnippetError($webhookId, $trigger, 'post', (int) $assignment['post_snippet_id'], (string) $result['error']);
    }
  }

  /**
   * Surface a live-dispatch snippet failure. The delivery itself is never
   * broken by a snippet error (SnippetExecutor catches every Throwable and the
   * caller falls back to the unmodified payload) — but a silent failure means
   * nobody learns their glue stopped working, so always log and announce it.
   */
  private function reportSnippetError(int $webhookId, string $trigger, string $stage, int $snippetId, string $error): void {
    /**
     * Fires when an assigned Code Glue snippet errors during a real dispatch.
     *
     * @param int    $webhookId Webhook the snippet is assigned to.
     * @param string $trigger   Trigger being dispatched.
     * @param string $stage     'pre' or 'post'.
     * @param int    $snippetId The failing snippet's id.
     * @param string $error     The PHP error message (with line when known).
     */
    do_action('fswa_glue_error', $webhookId, $trigger, $stage, $snippetId, $error);

    error_log(sprintf(
      '[FSWA Code Glue] %s-dispatch snippet #%d error on webhook #%d (%s): %s — payload sent unmodified.',
      $stage,
      $snippetId,
      $webhookId,
      $trigger,
      $error
    ));
  }

  /**
   * Expands {{ field.path }} dot-notation templates in the webhook URL.
   * Runs after fswa_webhook_payload (pre-glue), so pre-glue-injected values are available.
   *
   * @param string $url     Webhook endpoint URL, potentially containing templates
   * @param array  $payload Final post-pre-glue payload
   * @param array  $webhook Webhook configuration (unused, required by filter signature)
   * @param string $trigger Trigger event name (unused, required by filter signature)
   */
  public function expandUrlTemplates(string $url, array $payload, array $webhook, string $trigger, ?array $originalPayload = null): string {
    if (!str_contains($url, '{{')) {
      return $url;
    }

    return (string) preg_replace_callback(
      '/\{\{\s*([\w][\w.]*)\s*\}\}/',
      function (array $m) use ($payload, $originalPayload): string {
        $path = trim($m[1]);
        $value = $this->resolvePayloadPath($payload, $path);
        if ($value === null && $originalPayload !== null) {
          $value = $this->resolvePayloadPath($originalPayload, $path);
        }
        return $value !== null ? rawurlencode((string) $value) : '';
      },
      $url
    );
  }

  private function resolvePayloadPath(array $data, string $path): mixed {
    foreach (explode('.', $path) as $key) {
      if (!is_array($data) || !array_key_exists($key, $data)) {
        return null;
      }
      $data = $data[$key];
    }
    return $data;
  }
}
