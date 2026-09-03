<?php

namespace FlowSystems\WebhookActions\Hooks;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\SnippetsRepository;
use FlowSystems\WebhookActions\Repositories\TriggerSnippetsRepository;

/**
 * Carries Code Glue through portable build documents: the assigned pre/post
 * snippet code travels with each exported trigger, and is recreated (snippet +
 * assignment) on import.
 *
 * Rides the exporter's own `fswa_export_trigger` / `fswa_import_trigger` hooks
 * rather than being wired into {@see BuildExporter} directly, so a build
 * document stays readable by an install that has no snippets in it.
 */
class BuildGlueExportHooks {
  private TriggerSnippetsRepository $triggerSnippets;
  private SnippetsRepository $snippets;

  public function __construct() {
    $this->triggerSnippets = new TriggerSnippetsRepository();
    $this->snippets        = new SnippetsRepository();
  }

  public function init(): void {
    add_filter('fswa_export_trigger', [$this, 'exportCodeGlue'], 10, 3);
    add_action('fswa_import_trigger', [$this, 'importCodeGlue'], 10, 3);
  }

  /**
   * Attach the trigger's pre/post Code Glue snippets to its export document.
   *
   * @param array  $doc         Trigger document (name, schema, code_glue).
   * @param int    $webhookId
   * @param string $triggerName
   * @return array
   */
  public function exportCodeGlue(array $doc, int $webhookId, string $triggerName): array {
    $assignment = $this->triggerSnippets->findByWebhookAndTrigger($webhookId, $triggerName);
    if (!$assignment) {
      return $doc;
    }

    $pre  = $this->exportSnippet($assignment['pre_snippet_id'] ?? null, (bool) ($assignment['pre_enabled'] ?? false));
    $post = $this->exportSnippet($assignment['post_snippet_id'] ?? null, (bool) ($assignment['post_enabled'] ?? false));

    if ($pre === null && $post === null) {
      return $doc;
    }

    $doc['code_glue'] = ['pre' => $pre, 'post' => $post];
    return $doc;
  }

  private function exportSnippet(?int $snippetId, bool $enabled): ?array {
    if (empty($snippetId)) {
      return null;
    }
    $snippet = $this->snippets->find((int) $snippetId);
    if (!$snippet) {
      return null;
    }
    return [
      'name'    => $snippet['name'] ?? '',
      'tags'    => $snippet['tags'] ?? [],
      'code'    => $snippet['code'] ?? '',
      'enabled' => $enabled,
    ];
  }

  /**
   * Recreate the pre/post snippets and their assignment on the imported webhook.
   *
   * @param int    $webhookId   The freshly created webhook id.
   * @param string $triggerName
   * @param array  $trigger     Trigger document, possibly carrying code_glue.
   */
  public function importCodeGlue(int $webhookId, string $triggerName, array $trigger): void {
    $glue = $trigger['code_glue'] ?? null;
    if (!is_array($glue)) {
      return;
    }

    $assignment = [];

    if (is_array($glue['pre'] ?? null)) {
      $assignment['pre_snippet_id'] = $this->resolveSnippetId($glue['pre']);
      $assignment['pre_enabled']    = (bool) ($glue['pre']['enabled'] ?? false);
    }
    if (is_array($glue['post'] ?? null)) {
      $assignment['post_snippet_id'] = $this->resolveSnippetId($glue['post']);
      $assignment['post_enabled']    = (bool) ($glue['post']['enabled'] ?? false);
    }

    if (!empty($assignment)) {
      $this->triggerSnippets->upsert($webhookId, $triggerName, $assignment);
    }
  }

  /**
   * Reuse an existing snippet with the same name (updating its code) or create a
   * new one, so re-importing the same build doesn't multiply snippets.
   */
  private function resolveSnippetId(array $snippet): int {
    $name = (string) ($snippet['name'] ?? '');
    $code = (string) ($snippet['code'] ?? '');
    $tags = (array) ($snippet['tags'] ?? []);

    if ($name !== '') {
      foreach ($this->snippets->findAll($name) as $existing) {
        if (($existing['name'] ?? '') === $name) {
          return $this->snippets->upsert([
            'id'   => (int) $existing['id'],
            'code' => $code,
            'tags' => $tags,
          ]);
        }
      }
    }

    return $this->snippets->upsert([
      'name' => $name !== '' ? $name : 'Imported snippet',
      'code' => $code,
      'tags' => $tags,
    ]);
  }
}
