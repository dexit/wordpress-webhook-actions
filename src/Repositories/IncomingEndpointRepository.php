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
      'name'           => $data['name'],
      'slug'           => $data['slug'],
      'description'    => $data['description'] ?? null,
      'secret_key'     => $data['secret_key'] ?? null,
      'hmac_algorithm' => $data['hmac_algorithm'] ?? 'sha256',
      'hmac_header'    => $data['hmac_header'] ?? null,
      'is_enabled'     => isset($data['is_enabled']) ? (int) $data['is_enabled'] : 1,
      'response_code'  => isset($data['response_code']) ? (int) $data['response_code'] : 200,
      'response_body'  => $data['response_body'] ?? null,
    ];

    $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s'];

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

    $allowed = ['name', 'slug', 'description', 'secret_key', 'hmac_algorithm', 'hmac_header', 'is_enabled', 'response_code', 'response_body'];
    $update  = [];
    $formats = [];

    foreach ($allowed as $field) {
      if (!array_key_exists($field, $data)) {
        continue;
      }
      $update[$field] = $data[$field];
      $formats[]      = in_array($field, ['is_enabled', 'response_code']) ? '%d' : '%s';
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
    $row['id']            = (int) $row['id'];
    $row['is_enabled']    = (bool) $row['is_enabled'];
    $row['response_code'] = (int) $row['response_code'];

    return $row;
  }
}
