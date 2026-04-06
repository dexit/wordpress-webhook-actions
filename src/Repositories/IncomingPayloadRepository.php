<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class IncomingPayloadRepository {
  private string $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'fswa_incoming_payloads';
  }

  /**
   * Get paginated payloads for an endpoint
   *
   * @param int $endpointId
   * @param array<string, mixed> $filters  Keys: status, date_from, date_to, page, per_page
   * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
   */
  public function getForEndpoint(int $endpointId, array $filters = []): array {
    global $wpdb;

    $page     = max(1, (int) ($filters['page'] ?? 1));
    $perPage  = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
    $offset   = ($page - 1) * $perPage;

    $where  = ['ep.endpoint_id = ' . (int) $endpointId];
    $params = [];

    if (!empty($filters['status'])) {
      $where[]  = 'ep.status = %s';
      $params[] = sanitize_text_field($filters['status']);
    }

    if (!empty($filters['date_from'])) {
      $where[]  = 'ep.received_at >= %s';
      $params[] = sanitize_text_field($filters['date_from']);
    }

    if (!empty($filters['date_to'])) {
      $where[]  = 'ep.received_at <= %s';
      $params[] = sanitize_text_field($filters['date_to']);
    }

    $whereClause = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM {$this->table} ep WHERE {$whereClause}";
    $dataSql  = "SELECT ep.* FROM {$this->table} ep WHERE {$whereClause} ORDER BY ep.received_at DESC LIMIT %d OFFSET %d";

    if (!empty($params)) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
      $total = (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params));

      $dataParams   = array_merge($params, [$perPage, $offset]);
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
      $rows         = $wpdb->get_results($wpdb->prepare($dataSql, ...$dataParams), ARRAY_A);
    } else {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
      $total = (int) $wpdb->get_var($countSql);

      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
      $rows = $wpdb->get_results($wpdb->prepare($dataSql, $perPage, $offset), ARRAY_A);
    }

    $items = array_map([$this, 'castRow'], $rows ?: []);

    return [
      'items' => $items,
      'total' => $total,
      'pages' => (int) ceil($total / $perPage),
    ];
  }

  /**
   * Find a single payload by ID
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
   * Store a received payload
   *
   * @param array<string, mixed> $data
   * @return int|false New payload ID or false on failure
   */
  public function create(array $data): int|false {
    global $wpdb;

    $insert = [
      'endpoint_id'  => (int) $data['endpoint_id'],
      'payload'      => $data['payload'],
      'headers'      => $data['headers'] ?? null,
      'method'       => strtoupper($data['method'] ?? 'POST'),
      'source_ip'    => $data['source_ip'] ?? null,
      'content_type' => $data['content_type'] ?? null,
      'status'       => $data['status'] ?? 'received',
      'received_at'  => current_time('mysql'),
    ];

    $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert($this->table, $insert, $formats);

    return $result !== false ? (int) $wpdb->insert_id : false;
  }

  /**
   * Mark a payload as processed
   *
   * @param int $id
   * @param string|null $notes  Optional processing notes
   * @return bool
   */
  public function markProcessed(int $id, ?string $notes = null): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update(
      $this->table,
      [
        'status'           => 'processed',
        'processing_notes' => $notes,
        'processed_at'     => current_time('mysql'),
      ],
      ['id' => $id],
      ['%s', '%s', '%s'],
      ['%d']
    );

    return $result !== false;
  }

  /**
   * Mark a payload as failed
   *
   * @param int $id
   * @param string|null $notes  Failure reason
   * @return bool
   */
  public function markFailed(int $id, ?string $notes = null): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update(
      $this->table,
      [
        'status'           => 'failed',
        'processing_notes' => $notes,
        'processed_at'     => current_time('mysql'),
      ],
      ['id' => $id],
      ['%s', '%s', '%s'],
      ['%d']
    );

    return $result !== false;
  }

  /**
   * Delete a single payload
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
   * Delete all payloads for an endpoint
   *
   * @param int $endpointId
   * @return int Number of rows deleted
   */
  public function deleteForEndpoint(int $endpointId): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->delete($this->table, ['endpoint_id' => $endpointId], ['%d']);

    return (int) ($result ?: 0);
  }

  /**
   * Delete payloads older than N days for an endpoint
   *
   * @param int $endpointId
   * @param int $days
   * @return int Number of rows deleted
   */
  public function deleteOlderThan(int $endpointId, int $days): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $result = $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$this->table} WHERE endpoint_id = %d AND received_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $endpointId,
        $days
      )
    );

    return (int) ($result ?: 0);
  }

  /**
   * Get payload count stats for an endpoint
   *
   * @param int $endpointId
   * @return array<string, int>
   */
  public function getStats(int $endpointId): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT status, COUNT(*) as count FROM {$this->table} WHERE endpoint_id = %d GROUP BY status",
        $endpointId
      ),
      ARRAY_A
    );

    $stats = ['received' => 0, 'processed' => 0, 'failed' => 0, 'total' => 0];

    foreach ($rows ?: [] as $row) {
      $stats[$row['status']] = (int) $row['count'];
      $stats['total']       += (int) $row['count'];
    }

    return $stats;
  }

  /**
   * Cast DB row to typed values
   *
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function castRow(array $row): array {
    $row['id']          = (int) $row['id'];
    $row['endpoint_id'] = (int) $row['endpoint_id'];

    return $row;
  }
}
