<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

/**
 * CRUD operations for DTO/ETL pipeline definitions.
 *
 * Each pipeline stores a JSON `pipeline_config` array of field definitions:
 *   [
 *     { "output_key": "user_email", "source": "{{received.body.email}}", "type": "string", "default": "" },
 *     { "output_key": "order_total", "source": "{{received.body.total}}", "type": "float",  "default": "0" },
 *     ...
 *   ]
 *
 * The pipeline is run by DtoPipelineProcessor::process() which resolves each
 * source template tag, applies optional type-casting, and returns the DTO array.
 */
class DtoPipelineRepository {
  private string $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'fswa_dto_pipelines';
  }

  /**
   * Get all pipelines
   *
   * @return array<int, array<string, mixed>>
   */
  public function getAll(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results(
      "SELECT * FROM {$this->table} ORDER BY name ASC",
      ARRAY_A
    );

    return array_map([$this, 'castRow'], $results ?: []);
  }

  /**
   * Find a pipeline by ID
   *
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
   * Find a pipeline by slug
   *
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
   * Create a new pipeline
   *
   * @param array<string, mixed> $data
   * @return int|false
   */
  public function create(array $data): int|false {
    global $wpdb;

    $insert = [
      'name'            => $data['name'],
      'slug'            => $data['slug'],
      'description'     => $data['description'] ?? null,
      'pipeline_config' => isset($data['pipeline_config'])
        ? (is_array($data['pipeline_config']) ? wp_json_encode($data['pipeline_config']) : $data['pipeline_config'])
        : wp_json_encode([]),
      'is_enabled'      => isset($data['is_enabled']) ? (int) $data['is_enabled'] : 1,
    ];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert($this->table, $insert, ['%s', '%s', '%s', '%s', '%d']);

    return $result !== false ? (int) $wpdb->insert_id : false;
  }

  /**
   * Update a pipeline
   *
   * @param array<string, mixed> $data
   */
  public function update(int $id, array $data): bool {
    global $wpdb;

    $allowed = ['name', 'slug', 'description', 'pipeline_config', 'is_enabled'];
    $update  = [];
    $formats = [];

    foreach ($allowed as $field) {
      if (!array_key_exists($field, $data)) {
        continue;
      }
      if ($field === 'pipeline_config') {
        $update[$field] = is_array($data[$field]) ? wp_json_encode($data[$field]) : $data[$field];
        $formats[]      = '%s';
      } elseif ($field === 'is_enabled') {
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
   * Delete a pipeline by ID
   */
  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->delete($this->table, ['id' => $id], ['%d']);

    return $result !== false;
  }

  /**
   * Check if a slug is already taken (optionally excluding a given ID)
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
   * Cast DB row to typed PHP values
   *
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  public function castRow(array $row): array {
    $row['id']         = (int) $row['id'];
    $row['is_enabled'] = (bool) $row['is_enabled'];

    if (isset($row['pipeline_config']) && is_string($row['pipeline_config'])) {
      $decoded = json_decode($row['pipeline_config'], true);
      $row['pipeline_config'] = is_array($decoded) ? $decoded : [];
    } else {
      $row['pipeline_config'] = [];
    }

    return $row;
  }
}
