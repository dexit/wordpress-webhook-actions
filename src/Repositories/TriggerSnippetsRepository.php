<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class TriggerSnippetsRepository {
  private function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'fswa_pro_trigger_snippets';
  }

  public function findByWebhookAndTrigger(int $webhookId, string $trigger): ?array {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$this->table()} WHERE webhook_id = %d AND trigger_name = %s",
      $webhookId,
      $trigger
    ), ARRAY_A);

    return $row ? $this->cast($row) : null;
  }

  public function upsert(int $webhookId, string $trigger, array $data): bool {
    global $wpdb;
    $table = $this->table();

    $existing = $this->findByWebhookAndTrigger($webhookId, $trigger);

    $row = [
      'pre_snippet_id'  => isset($data['pre_snippet_id']) ? ((int) $data['pre_snippet_id'] ?: null) : ($existing['pre_snippet_id'] ?? null),
      'pre_enabled'     => isset($data['pre_enabled']) ? (int) (bool) $data['pre_enabled'] : ($existing['pre_enabled'] ?? 0),
      'post_snippet_id' => isset($data['post_snippet_id']) ? ((int) $data['post_snippet_id'] ?: null) : ($existing['post_snippet_id'] ?? null),
      'post_enabled'    => isset($data['post_enabled']) ? (int) (bool) $data['post_enabled'] : ($existing['post_enabled'] ?? 0),
    ];

    $formats = ['%d', '%d', '%d', '%d'];

    if ($existing) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      return (bool) $wpdb->update(
        $table,
        $row,
        ['webhook_id' => $webhookId, 'trigger_name' => $trigger],
        $formats,
        ['%d', '%s']
      );
    }

    $row['webhook_id']    = $webhookId;
    $row['trigger_name']  = $trigger;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    return (bool) $wpdb->insert($table, $row, array_merge($formats, ['%d', '%s']));
  }

  private function cast(array $row): array {
    $row['pre_snippet_id']  = $row['pre_snippet_id'] !== null ? (int) $row['pre_snippet_id'] : null;
    $row['pre_enabled']     = (bool) $row['pre_enabled'];
    $row['post_snippet_id'] = $row['post_snippet_id'] !== null ? (int) $row['post_snippet_id'] : null;
    $row['post_enabled']    = (bool) $row['post_enabled'];
    return $row;
  }
}
