<?php

namespace FlowSystems\WebhookActions\Database;

class Migrator {
  private const OPTION_KEY = 'fswa_db_version';
  private const CURRENT_VERSION = '1.7.0';

  /**
   * Run pending migrations
   */
  public static function migrate(): void {
    $currentVersion = get_option(self::OPTION_KEY, '0.0.0');

    // Check if critical tables are missing (handles flattened migrations)
    if (self::hasMissingTables()) {
      $currentVersion = '0.0.0';
    }

    if (version_compare($currentVersion, self::CURRENT_VERSION, '>=')) {
      return;
    }

    $migrations = self::getMigrations();

    foreach ($migrations as $version => $migration) {
      if (version_compare($currentVersion, $version, '<')) {
        $migration();
        update_option(self::OPTION_KEY, $version);
      }
    }
  }

  /**
   * Check if any required tables are missing
   */
  private static function hasMissingTables(): bool {
    global $wpdb;

    $requiredTables = [
      $wpdb->prefix . 'fswa_webhooks',
      $wpdb->prefix . 'fswa_webhook_triggers',
      $wpdb->prefix . 'fswa_logs',
      $wpdb->prefix . 'fswa_queue',
      $wpdb->prefix . 'fswa_trigger_schemas',
      $wpdb->prefix . 'fswa_stats',
      $wpdb->prefix . 'fswa_api_tokens',
      $wpdb->prefix . 'fswa_incoming_endpoints',
      $wpdb->prefix . 'fswa_incoming_payloads',
      $wpdb->prefix . 'fswa_endpoint_logs',
      $wpdb->prefix . 'fswa_dto_pipelines',
    ];

    foreach ($requiredTables as $table) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
      if (!$exists) {
        return true;
      }
    }

    return false;
  }

  /**
   * Get all migrations
   *
   * @return array<string, callable>
   */
  private static function getMigrations(): array {
    return [
      '1.0.0' => [self::class, 'migration_1_0_0'],
      '1.1.0' => [self::class, 'migration_1_1_0'],
      '1.2.0' => [self::class, 'migration_1_2_0'],
      '1.3.0' => [self::class, 'migration_1_3_0'],
      '1.4.0' => [self::class, 'migration_1_4_0'],
      '1.5.0' => [self::class, 'migration_1_5_0'],
      '1.6.0' => [self::class, 'migration_1_6_0'],
      '1.7.0' => [self::class, 'migration_1_7_0'],
    ];
  }

  /**
   * Migration 1.0.0 - Create all tables
   */
  public static function migration_1_0_0(): void {
    global $wpdb;

    $charsetCollate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Webhooks table
    $webhooksTable = $wpdb->prefix . 'fswa_webhooks';
    $sqlWebhooks = "CREATE TABLE {$webhooksTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            endpoint_url VARCHAR(2048) NOT NULL,
            auth_header VARCHAR(1024) DEFAULT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_enabled (is_enabled)
        ) {$charsetCollate};";

    dbDelta($sqlWebhooks);

    // Webhook triggers table
    $triggersTable = $wpdb->prefix . 'fswa_webhook_triggers';
    $sqlTriggers = "CREATE TABLE {$triggersTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            webhook_id BIGINT UNSIGNED NOT NULL,
            trigger_name VARCHAR(255) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_webhook (webhook_id),
            KEY idx_trigger (trigger_name)
        ) {$charsetCollate};";

    dbDelta($sqlTriggers);

    // Logs table
    $logsTable = $wpdb->prefix . 'fswa_logs';
    $sqlLogs = "CREATE TABLE {$logsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            webhook_id BIGINT UNSIGNED DEFAULT NULL,
            trigger_name VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            http_code SMALLINT UNSIGNED DEFAULT NULL,
            request_payload LONGTEXT,
            original_payload LONGTEXT DEFAULT NULL,
            mapping_applied TINYINT(1) NOT NULL DEFAULT 0,
            response_body LONGTEXT,
            error_message TEXT,
            duration_ms INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_webhook (webhook_id),
            KEY idx_status (status),
            KEY idx_created (created_at),
            KEY idx_webhook_created (webhook_id, created_at)
        ) {$charsetCollate};";

    dbDelta($sqlLogs);

    // Queue table for webhook jobs
    $queueTable = $wpdb->prefix . 'fswa_queue';
    $sqlQueue = "CREATE TABLE {$queueTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            webhook_id BIGINT UNSIGNED NOT NULL,
            trigger_name VARCHAR(255) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
            locked_at DATETIME DEFAULT NULL,
            locked_by VARCHAR(64) DEFAULT NULL,
            scheduled_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_scheduled (status, scheduled_at),
            KEY idx_locked (locked_at, locked_by),
            KEY idx_webhook (webhook_id)
        ) {$charsetCollate};";

    dbDelta($sqlQueue);

    // Trigger schemas table for payload mapping configuration
    $schemasTable = $wpdb->prefix . 'fswa_trigger_schemas';
    $sqlSchemas = "CREATE TABLE {$schemasTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            webhook_id BIGINT UNSIGNED NOT NULL,
            trigger_name VARCHAR(255) NOT NULL,
            example_payload LONGTEXT DEFAULT NULL,
            field_mapping LONGTEXT DEFAULT NULL,
            include_user_data TINYINT(1) NOT NULL DEFAULT 0,
            captured_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_webhook_trigger (webhook_id, trigger_name),
            KEY idx_webhook (webhook_id)
        ) {$charsetCollate};";

    dbDelta($sqlSchemas);
  }

  /**
   * Migration 1.1.0 - Add event identity and attempt history columns
   */
  public static function migration_1_1_0(): void {
    global $wpdb;

    $logsTable = $wpdb->prefix . 'fswa_logs';
    $queueTable = $wpdb->prefix . 'fswa_queue';

    // Columns to add to fswa_logs
    $logsColumns = [
      'event_uuid'      => "ALTER TABLE {$logsTable} ADD COLUMN event_uuid VARCHAR(36) DEFAULT NULL",
      'event_timestamp' => "ALTER TABLE {$logsTable} ADD COLUMN event_timestamp DATETIME DEFAULT NULL",
      'attempt_history' => "ALTER TABLE {$logsTable} ADD COLUMN attempt_history LONGTEXT DEFAULT NULL",
      'next_attempt_at' => "ALTER TABLE {$logsTable} ADD COLUMN next_attempt_at DATETIME DEFAULT NULL",
    ];

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    foreach ($logsColumns as $column => $sql) {
      $exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$logsTable} LIKE %s",
        $column
      ));
      if (!$exists) {
        $wpdb->query($sql);
      }
    }

    // Add index on event_uuid if not exists
    $indexExists = $wpdb->get_var(
      "SHOW INDEX FROM {$logsTable} WHERE Key_name = 'idx_event_uuid'"
    );
    if (!$indexExists) {
      $wpdb->query("ALTER TABLE {$logsTable} ADD KEY idx_event_uuid (event_uuid)");
    }

    // Add log_id column to fswa_queue
    $logIdExists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$queueTable} LIKE %s",
      'log_id'
    ));
    if (!$logIdExists) {
      $wpdb->query("ALTER TABLE {$queueTable} ADD COLUMN log_id BIGINT UNSIGNED DEFAULT NULL");
      $wpdb->query("ALTER TABLE {$queueTable} ADD KEY idx_log_id (log_id)");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.2.0 - Add persistent stats table and stats_recorded flag on logs
   */
  public static function migration_1_2_0(): void {
    global $wpdb;

    $charsetCollate = $wpdb->get_charset_collate();
    $statsTable     = $wpdb->prefix . 'fswa_stats';
    $logsTable      = $wpdb->prefix . 'fswa_logs';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sqlStats = "CREATE TABLE {$statsTable} (
            `date`                DATE         NOT NULL,
            `webhook_id`          BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `trigger_name`        VARCHAR(255) NOT NULL DEFAULT '',
            `success`             INT UNSIGNED NOT NULL DEFAULT 0,
            `permanently_failed`  INT UNSIGNED NOT NULL DEFAULT 0,
            `sum_duration_ms`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `count_with_duration` INT UNSIGNED NOT NULL DEFAULT 0,
            `http_2xx`            INT UNSIGNED NOT NULL DEFAULT 0,
            `http_4xx`            INT UNSIGNED NOT NULL DEFAULT 0,
            `http_5xx`            INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`date`, `webhook_id`, `trigger_name`)
        ) {$charsetCollate};";

    dbDelta($sqlStats);

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $exists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$logsTable} LIKE %s",
      'stats_recorded'
    ));
    if (!$exists) {
      $wpdb->query("ALTER TABLE {$logsTable} ADD COLUMN stats_recorded TINYINT(1) NOT NULL DEFAULT 0");
      $wpdb->query("ALTER TABLE {$logsTable} ADD KEY idx_stats_recorded (stats_recorded)");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.3.0 - Add API tokens table
   */
  public static function migration_1_3_0(): void {
    global $wpdb;

    $charsetCollate = $wpdb->get_charset_collate();
    $tokensTable    = $wpdb->prefix . 'fswa_api_tokens';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$tokensTable} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name         VARCHAR(255) NOT NULL,
            token_hash   VARCHAR(64) NOT NULL,
            token_hint   VARCHAR(13) NOT NULL,
            scope        VARCHAR(20) NOT NULL DEFAULT 'read',
            expires_at   DATETIME DEFAULT NULL,
            last_used_at DATETIME DEFAULT NULL,
            rotated_at   DATETIME DEFAULT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_token_hash (token_hash),
            KEY idx_expires (expires_at)
        ) {$charsetCollate};";

    dbDelta($sql);
  }

  /**
   * Migration 1.4.0 - Add incoming endpoints and payloads tables
   */
  public static function migration_1_4_0(): void {
    global $wpdb;

    $charsetCollate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Incoming endpoints configuration table
    $endpointsTable = $wpdb->prefix . 'fswa_incoming_endpoints';
    $sqlEndpoints   = "CREATE TABLE {$endpointsTable} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name            VARCHAR(255) NOT NULL,
            slug            VARCHAR(100) NOT NULL,
            description     TEXT DEFAULT NULL,
            secret_key      VARCHAR(255) DEFAULT NULL,
            hmac_algorithm  VARCHAR(20) NOT NULL DEFAULT 'sha256',
            hmac_header     VARCHAR(100) DEFAULT NULL,
            is_enabled      TINYINT(1) NOT NULL DEFAULT 1,
            response_code   SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            response_body   TEXT DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_slug (slug),
            KEY idx_enabled (is_enabled)
        ) {$charsetCollate};";

    dbDelta($sqlEndpoints);

    // Incoming payloads storage table
    $payloadsTable = $wpdb->prefix . 'fswa_incoming_payloads';
    $sqlPayloads   = "CREATE TABLE {$payloadsTable} (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            endpoint_id       BIGINT UNSIGNED NOT NULL,
            payload           LONGTEXT NOT NULL,
            headers           LONGTEXT DEFAULT NULL,
            method            VARCHAR(10) NOT NULL DEFAULT 'POST',
            source_ip         VARCHAR(45) DEFAULT NULL,
            content_type      VARCHAR(255) DEFAULT NULL,
            status            VARCHAR(20) NOT NULL DEFAULT 'received',
            processing_notes  TEXT DEFAULT NULL,
            received_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at      DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_endpoint (endpoint_id),
            KEY idx_status (status),
            KEY idx_received (received_at),
            KEY idx_endpoint_status (endpoint_id, status)
        ) {$charsetCollate};";

    dbDelta($sqlPayloads);
  }

  /**
   * Migration 1.5.0 – Endpoint logs table + auth/methods/CPT/function columns
   */
  public static function migration_1_5_0(): void {
    global $wpdb;

    $charsetCollate  = $wpdb->get_charset_collate();
    $endpointsTable  = $wpdb->prefix . 'fswa_incoming_endpoints';
    $logsTable       = $wpdb->prefix . 'fswa_endpoint_logs';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // ── New columns on wp_fswa_incoming_endpoints ─────────────────────────
    $newColumns = [
      'allowed_methods'    => "ALTER TABLE {$endpointsTable} ADD COLUMN allowed_methods VARCHAR(200) NOT NULL DEFAULT '[\"GET\",\"POST\",\"PUT\",\"PATCH\",\"DELETE\"]'",
      'auth_mode'          => "ALTER TABLE {$endpointsTable} ADD COLUMN auth_mode VARCHAR(20) NOT NULL DEFAULT 'none'",
      'auth_config'        => "ALTER TABLE {$endpointsTable} ADD COLUMN auth_config LONGTEXT DEFAULT NULL",
      'cpt_enabled'        => "ALTER TABLE {$endpointsTable} ADD COLUMN cpt_enabled TINYINT(1) NOT NULL DEFAULT 0",
      'cpt_config'         => "ALTER TABLE {$endpointsTable} ADD COLUMN cpt_config LONGTEXT DEFAULT NULL",
      'function_enabled'   => "ALTER TABLE {$endpointsTable} ADD COLUMN function_enabled TINYINT(1) NOT NULL DEFAULT 0",
      'function_code'      => "ALTER TABLE {$endpointsTable} ADD COLUMN function_code LONGTEXT DEFAULT NULL",
      'hooks_to_fire'      => "ALTER TABLE {$endpointsTable} ADD COLUMN hooks_to_fire TEXT DEFAULT NULL",
    ];

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    foreach ($newColumns as $column => $sql) {
      $exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$endpointsTable} LIKE %s",
        $column
      ));
      if (!$exists) {
        $wpdb->query($sql);
      }
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    // ── New endpoint logs table ───────────────────────────────────────────
    $sqlLogs = "CREATE TABLE {$logsTable} (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            endpoint_id       BIGINT UNSIGNED NOT NULL,
            payload_id        BIGINT UNSIGNED DEFAULT NULL,
            method            VARCHAR(10) NOT NULL DEFAULT 'POST',
            query_params      TEXT DEFAULT NULL,
            response_code     SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            auth_result       VARCHAR(20) NOT NULL DEFAULT 'skipped',
            duration_ms       INT UNSIGNED DEFAULT NULL,
            source_ip         VARCHAR(45) DEFAULT NULL,
            error_message     TEXT DEFAULT NULL,
            cpt_post_id       BIGINT UNSIGNED DEFAULT NULL,
            function_executed TINYINT(1) NOT NULL DEFAULT 0,
            function_output   TEXT DEFAULT NULL,
            received_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_endpoint_id (endpoint_id),
            KEY idx_received_at (received_at),
            KEY idx_endpoint_received (endpoint_id, received_at)
        ) {$charsetCollate};";

    dbDelta($sqlLogs);
  }

  /**
   * Migration 1.6.0 – DTO pipelines table + dto_pipeline_id on endpoints
   */
  public static function migration_1_6_0(): void {
    global $wpdb;

    $charsetCollate = $wpdb->get_charset_collate();
    $dtoTable       = $wpdb->prefix . 'fswa_dto_pipelines';
    $endpointsTable = $wpdb->prefix . 'fswa_incoming_endpoints';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // ── DTO pipelines table ───────────────────────────────────────────────
    $sqlDto = "CREATE TABLE {$dtoTable} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name            VARCHAR(255) NOT NULL,
            slug            VARCHAR(100) NOT NULL,
            description     TEXT DEFAULT NULL,
            pipeline_config LONGTEXT DEFAULT NULL,
            is_enabled      TINYINT(1) NOT NULL DEFAULT 1,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_slug (slug),
            KEY idx_enabled (is_enabled)
        ) {$charsetCollate};";

    dbDelta($sqlDto);

    // ── Add dto_pipeline_id to incoming endpoints ─────────────────────────
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $exists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$endpointsTable} LIKE %s",
      'dto_pipeline_id'
    ));
    if (!$exists) {
      $wpdb->query("ALTER TABLE {$endpointsTable} ADD COLUMN dto_pipeline_id BIGINT UNSIGNED DEFAULT NULL");
      $wpdb->query("ALTER TABLE {$endpointsTable} ADD KEY idx_dto_pipeline (dto_pipeline_id)");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.7.0 – Add actions_config to endpoints and webhooks tables
   */
  public static function migration_1_7_0(): void {
    global $wpdb;

    $endpointsTable = $wpdb->prefix . 'fswa_incoming_endpoints';
    $webhooksTable  = $wpdb->prefix . 'fswa_webhooks';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $exists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$endpointsTable} LIKE %s",
      'actions_config'
    ));
    if (!$exists) {
      $wpdb->query("ALTER TABLE {$endpointsTable} ADD COLUMN actions_config LONGTEXT DEFAULT NULL");
    }

    $exists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$webhooksTable} LIKE %s",
      'actions_config'
    ));
    if (!$exists) {
      $wpdb->query("ALTER TABLE {$webhooksTable} ADD COLUMN actions_config LONGTEXT DEFAULT NULL");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Get current database version
   */
  public static function getCurrentVersion(): string {
    return get_option(self::OPTION_KEY, '0.0.0');
  }

  /**
   * Get target database version
   */
  public static function getTargetVersion(): string {
    return self::CURRENT_VERSION;
  }

  /**
   * Check if migration is needed
   */
  public static function needsMigration(): bool {
    if (version_compare(self::getCurrentVersion(), self::CURRENT_VERSION, '<')) {
      return true;
    }

    // Also check if any tables are missing
    return self::hasMissingTables();
  }
}
