<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class EndpointLogRepository {
  private string $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'fswa_endpoint_logs';
  }

  /**
   * Get paginated logs for an endpoint.
   *
   * @param int                  $endpointId
   * @param array<string, mixed> $filters  Keys: auth_result, method, date_from, date_to, page, per_page
   * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
   */
  public function getForEndpoint(int $endpointId, array $filters = []): array {
    global $wpdb;

    $page    = max(1, (int) ($filters['page'] ?? 1));
    $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $where  = ['endpoint_id = ' . (int) $endpointId];
    $params = [];

    if (!empty($filters['auth_result'])) {
      $where[]  = 'auth_result = %s';
      $params[] = sanitize_text_field($filters['auth_result']);
    }

    if (!empty($filters['method'])) {
      $where[]  = 'method = %s';
      $params[] = strtoupper(sanitize_text_field($filters['method']));
    }

    if (!empty($filters['date_from'])) {
      $where[]  = 'received_at >= %s';
      $params[] = sanitize_text_field($filters['date_from']);
    }

    if (!empty($filters['date_to'])) {
      $where[]  = 'received_at <= %s';
      $params[] = sanitize_text_field($filters['date_to']);
    }

    $whereClause = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause}";
    $dataSql  = "SELECT * FROM {$this->table} WHERE {$whereClause} ORDER BY received_at DESC LIMIT %d OFFSET %d";

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

    if (!empty($params)) {
      $total       = (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params));
      $dataParams  = array_merge($params, [$perPage, $offset]);
      $rows        = $wpdb->get_results($wpdb->prepare($dataSql, ...$dataParams), ARRAY_A);
    } else {
      $total = (int) $wpdb->get_var($countSql);
      $rows  = $wpdb->get_results($wpdb->prepare($dataSql, $perPage, $offset), ARRAY_A);
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

    return [
      'items' => array_map([$this, 'castRow'], $rows ?: []),
      'total' => $total,
      'pages' => (int) ceil($total / $perPage),
    ];
  }

  /**
   * Create a log entry.
   *
   * @param array<string, mixed> $data
   * @return int|false
   */
  public function create(array $data): int|false {
    global $wpdb;

    $insert = [
      'endpoint_id'       => (int) $data['endpoint_id'],
      'payload_id'        => isset($data['payload_id']) ? (int) $data['payload_id'] : null,
      'method'            => strtoupper($data['method'] ?? 'POST'),
      'query_params'      => isset($data['query_params']) ? wp_json_encode($data['query_params']) : null,
      'response_code'     => (int) ($data['response_code'] ?? 200),
      'auth_result'       => $data['auth_result'] ?? 'skipped',
      'duration_ms'       => isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
      'source_ip'         => $data['source_ip'] ?? null,
      'error_message'     => $data['error_message'] ?? null,
      'cpt_post_id'       => isset($data['cpt_post_id']) ? (int) $data['cpt_post_id'] : null,
      'function_executed' => (int) ($data['function_executed'] ?? 0),
      'function_output'   => $data['function_output'] ?? null,
      'received_at'       => current_time('mysql'),
    ];

    $formats = ['%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s'];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert($this->table, $insert, $formats);

    return $result !== false ? (int) $wpdb->insert_id : false;
  }

  /**
   * Delete all logs for an endpoint.
   */
  public function deleteForEndpoint(int $endpointId): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    return (int) ($wpdb->delete($this->table, ['endpoint_id' => $endpointId], ['%d']) ?: 0);
  }

  /**
   * Delete a single log entry.
   */
  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    return $wpdb->delete($this->table, ['id' => $id], ['%d']) !== false;
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function castRow(array $row): array {
    $row['id']                = (int) $row['id'];
    $row['endpoint_id']       = (int) $row['endpoint_id'];
    $row['payload_id']        = $row['payload_id'] !== null ? (int) $row['payload_id'] : null;
    $row['response_code']     = (int) $row['response_code'];
    $row['duration_ms']       = $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null;
    $row['cpt_post_id']       = $row['cpt_post_id'] !== null ? (int) $row['cpt_post_id'] : null;
    $row['function_executed'] = (bool) $row['function_executed'];

    // Decode query_params JSON
    if (!empty($row['query_params'])) {
      $decoded = json_decode($row['query_params'], true);
      $row['query_params'] = is_array($decoded) ? $decoded : [];
    } else {
      $row['query_params'] = [];
    }

    return $row;
  }
}
