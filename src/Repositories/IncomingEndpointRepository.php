<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class IncomingEndpointRepository {
  private string $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'fswa_incoming_endpoints';
  }

  /**
   * Get all endpoints
   *
   * @return array<int, array<string, mixed>>
   */
  public function getAll(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results(
      "SELECT * FROM {$this->table} ORDER BY created_at DESC",
      ARRAY_A
    );

    return array_map([$this, 'castRow'], $results ?: []);
  }

  /**
   * Find endpoint by ID
   *
   * @param int $id
   * @return array<string, mixed>|null
   */
  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
      ARRAY_A
    );

    return $row ? $this->castRow($row) : null;
  }

  /**
   * Find endpoint by slug
   *
   * @param string $slug
   * @return array<string, mixed>|null
   */
  public function findBySlug(string $slug): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$this->table} WHERE slug = %s", $slug),
      ARRAY_A
    );

    return $row ? $this->castRow($row) : null;
  }

  /**
   * Create a new endpoint
   *
   * @param array<string, mixed> $data
   * @return int|false New endpoint ID or false on failure
   */
  public function create(array $data): int|false {
    global $wpdb;

    $insert = [
      'name'             => $data['name'],
      'slug'             => $data['slug'],
      'description'      => $data['description'] ?? null,
      'secret_key'       => $data['secret_key'] ?? null,
      'hmac_algorithm'   => $data['hmac_algorithm'] ?? 'sha256',
      'hmac_header'      => $data['hmac_header'] ?? null,
      'is_enabled'       => isset($data['is_enabled']) ? (int) $data['is_enabled'] : 1,
      'response_code'    => isset($data['response_code']) ? (int) $data['response_code'] : 200,
      'response_body'    => $data['response_body'] ?? null,
      'allowed_methods'  => isset($data['allowed_methods']) ? wp_json_encode($data['allowed_methods']) : '["GET","POST","PUT","PATCH","DELETE"]',
      'auth_mode'        => $data['auth_mode'] ?? 'none',
      'auth_config'      => isset($data['auth_config']) ? wp_json_encode($data['auth_config']) : null,
      'cpt_enabled'      => (int) ($data['cpt_enabled'] ?? 0),
      'cpt_config'       => isset($data['cpt_config']) ? wp_json_encode($data['cpt_config']) : null,
      'function_enabled' => (int) ($data['function_enabled'] ?? 0),
      'function_code'    => $data['function_code'] ?? null,
      'hooks_to_fire'    => $data['hooks_to_fire'] ?? null,
      'dto_pipeline_id'  => isset($data['dto_pipeline_id']) ? (int) $data['dto_pipeline_id'] : null,
      'actions_config'   => isset($data['actions_config']) ? wp_json_encode($data['actions_config']) : null,
    ];

    $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s'];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert($this->table, $insert, $formats);

    return $result !== false ? (int) $wpdb->insert_id : false;
  }

  /**
   * Update an endpoint
   *
   * @param int $id
   * @param array<string, mixed> $data
   * @return bool
   */
  public function update(int $id, array $data): bool {
    global $wpdb;

    $intFields = ['is_enabled', 'response_code', 'cpt_enabled', 'function_enabled', 'dto_pipeline_id'];
    $jsonFields = ['allowed_methods', 'auth_config', 'cpt_config', 'actions_config'];
    $allowed = [
      'name', 'slug', 'description', 'secret_key', 'hmac_algorithm', 'hmac_header',
      'is_enabled', 'response_code', 'response_body',
      'allowed_methods', 'auth_mode', 'auth_config',
      'cpt_enabled', 'cpt_config',
      'function_enabled', 'function_code', 'hooks_to_fire',
      'dto_pipeline_id', 'actions_config',
    ];
    $update  = [];
    $formats = [];

    foreach ($allowed as $field) {
      if (!array_key_exists($field, $data)) {
        continue;
      }
      if (in_array($field, $jsonFields, true)) {
        $update[$field] = is_array($data[$field]) ? wp_json_encode($data[$field]) : $data[$field];
        $formats[]      = '%s';
      } elseif (in_array($field, $intFields, true)) {
        $update[$field] = (int) $data[$field];
        $formats[]      = '%d';
      } else {
        $update[$field] = $data[$field];
        $formats[]      = '%s';
      }
    }

    if (empty($update)) {
      return true;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update($this->table, $update, ['id' => $id], $formats, ['%d']);

    return $result !== false;
  }

  /**
   * Delete an endpoint
   *
   * @param int $id
   * @return bool
   */
  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->delete($this->table, ['id' => $id], ['%d']);

    return $result !== false;
  }

  /**
   * Toggle enabled status
   *
   * @param int $id
   * @param bool $enabled
   * @return bool
   */
  public function setEnabled(int $id, bool $enabled): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update(
      $this->table,
      ['is_enabled' => (int) $enabled],
      ['id' => $id],
      ['%d'],
      ['%d']
    );

    return $result !== false;
  }

  /**
   * Check if a slug is already taken (optionally excluding a given ID)
   *
   * @param string $slug
   * @param int|null $excludeId
   * @return bool
   */
  public function slugExists(string $slug, ?int $excludeId = null): bool {
    global $wpdb;

    if ($excludeId !== null) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $count = $wpdb->get_var(
        $wpdb->prepare(
          "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s AND id != %d",
          $slug,
          $excludeId
        )
      );
    } else {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $count = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE slug = %s", $slug)
      );
    }

    return (int) $count > 0;
  }

  /**
   * Cast DB row to typed values
   *
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function castRow(array $row): array {
    $row['id']               = (int) $row['id'];
    $row['is_enabled']       = (bool) $row['is_enabled'];
    $row['response_code']    = (int) $row['response_code'];
    $row['cpt_enabled']      = (bool) ($row['cpt_enabled'] ?? false);
    $row['function_enabled'] = (bool) ($row['function_enabled'] ?? false);
    $row['dto_pipeline_id']  = isset($row['dto_pipeline_id']) && $row['dto_pipeline_id'] !== null
      ? (int) $row['dto_pipeline_id']
      : null;

    // Decode JSON fields
    if (isset($row['allowed_methods']) && is_string($row['allowed_methods'])) {
      $decoded = json_decode($row['allowed_methods'], true);
      $row['allowed_methods'] = is_array($decoded) ? $decoded : ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    } else {
      $row['allowed_methods'] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    }

    if (isset($row['cpt_config']) && is_string($row['cpt_config'])) {
      $decoded = json_decode($row['cpt_config'], true);
      $row['cpt_config'] = is_array($decoded) ? $decoded : null;
    }

    if (isset($row['actions_config']) && is_string($row['actions_config'])) {
      $decoded = json_decode($row['actions_config'], true);
      $row['actions_config'] = is_array($decoded) ? $decoded : [];
    } else {
      $row['actions_config'] = [];
    }

    return $row;
  }
}
