<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class SnippetsRepository {
  private function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'fswa_pro_snippets';
  }

  /**
   * @return array<int, array>
   */
  public function findAll(string $search = '', array $tags = []): array {
    global $wpdb;
    $table = $this->table();

    $where  = [];
    $values = [];

    if ($search !== '') {
      $where[]  = '(name LIKE %s OR code LIKE %s)';
      $like     = '%' . $wpdb->esc_like($search) . '%';
      $values[] = $like;
      $values[] = $like;
    }

    $sql = "SELECT * FROM {$table}";
    if (!empty($where)) {
      $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY updated_at DESC';

    if (!empty($values)) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own table, named from $wpdb->prefix; clauses are literals and every value goes through $wpdb->prepare().
      $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
    } else {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own table, named from $wpdb->prefix; clauses are literals and every value goes through $wpdb->prepare().
      $rows = $wpdb->get_results($sql, ARRAY_A);
    }

    $rows = $rows ?: [];

    if (!empty($tags)) {
      $rows = array_values(array_filter($rows, function (array $row) use ($tags) {
        $rowTags = json_decode($row['tags'] ?? '[]', true) ?: [];
        return count(array_intersect($tags, $rowTags)) > 0;
      }));
    }

    return array_map([$this, 'decode'], $rows);
  }

  public function find(int $id): ?array {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row($wpdb->prepare(
      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; clauses are literals and every value goes through $wpdb->prepare().
      "SELECT * FROM {$this->table()} WHERE id = %d",
      $id
    ), ARRAY_A);

    return $row ? $this->decode($row) : null;
  }

  /**
   * Create or update a snippet. Pass 'id' in $data to update.
   * Returns the ID of the created/updated row.
   */
  public function upsert(array $data): int {
    global $wpdb;
    $table = $this->table();
    $now   = gmdate('Y-m-d H:i:s');

    if (!empty($data['id'])) {
      $row     = ['updated_at' => $now];
      $formats = ['%s'];

      if (array_key_exists('name', $data)) {
        $row['name'] = sanitize_text_field($data['name']);
        $formats[]   = '%s';
      }
      if (array_key_exists('tags', $data)) {
        $row['tags'] = wp_json_encode(array_map('sanitize_text_field', (array) $data['tags']));
        $formats[]   = '%s';
      }
      if (array_key_exists('code', $data)) {
        $row['code'] = $data['code'];
        $formats[]   = '%s';
      }

      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $wpdb->update($table, $row, ['id' => (int) $data['id']], $formats, ['%d']);
      return (int) $data['id'];
    }

    $row = [
      'name'       => sanitize_text_field($data['name'] ?? ''),
      'tags'       => wp_json_encode(array_map('sanitize_text_field', (array) ($data['tags'] ?? []))),
      'code'       => $data['code'] ?? '',
      'created_at' => $now,
      'updated_at' => $now,
    ];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->insert($table, $row, ['%s', '%s', '%s', '%s', '%s']);
    return (int) $wpdb->insert_id;
  }

  public function delete(int $id): bool {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return (bool) $wpdb->delete($this->table(), ['id' => $id], ['%d']);
  }

  private function decode(array $row): array {
    $row['tags'] = json_decode($row['tags'] ?? '[]', true) ?: [];
    return $row;
  }
}
