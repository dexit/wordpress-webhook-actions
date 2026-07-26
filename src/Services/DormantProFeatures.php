<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Detects Pro-only per-webhook features that are configured but will NOT run
 * because the Pro plugin is not loaded (deactivated or absent).
 *
 * When Pro is inactive the free Dispatcher still calls the fswa_webhook_payload
 * and fswa_webhook_url hooks, but with no Pro listeners they are no-ops — so
 * assigned Code Glue snippets are silently skipped and {{ }} URL templates are
 * sent literally. This surfaces that silent degradation to the admin UI.
 */
class DormantProFeatures {
  /** @var array<int, string[]>|null webhook_id => enabled glue slugs (lazy). */
  private ?array $glueMapCache = null;

  /**
   * True when the Pro plugin is loaded (its License\LicenseManager class exists).
   * Glue hooks register whenever Pro is active, regardless of license validity,
   * so this — not license state — decides whether Pro features actually run.
   */
  public function proLoaded(): bool {
    return class_exists('FlowSystems\\WebhookActions\\Pro\\License\\LicenseManager');
  }

  /**
   * Pro features configured on a webhook that will not run while Pro is inactive.
   * Returns [] when Pro is loaded or the webhook is disabled.
   *
   * @param array $webhook
   * @return string[] subset of 'pre_glue', 'post_glue', 'url_template'
   */
  public function forWebhook(array $webhook): array {
    if ($this->proLoaded() || empty($webhook['is_enabled'])) {
      return [];
    }

    $features = [];

    $id = (int) ($webhook['id'] ?? 0);
    if ($id > 0 && !empty($this->glueMap()[$id])) {
      $features = $this->glueMap()[$id];
    }

    // Only endpoint_url {{ }} templates depend on Pro (expandUrlTemplates via the
    // fswa_webhook_url filter). url_params / custom_headers use free dot-path
    // resolution, so they keep working without Pro.
    if (str_contains((string) ($webhook['endpoint_url'] ?? ''), '{{')) {
      $features[] = 'url_template';
    }

    return $features;
  }

  /**
   * Map of webhook_id => enabled glue slugs, read straight from the Pro
   * trigger-snippets table. The table persists after Pro is deactivated, and
   * Pro's repository class is unavailable when Pro is off, so we query directly.
   * A missing table (Pro never installed) yields an empty map. Cached per request.
   *
   * @return array<int, string[]>
   */
  private function glueMap(): array {
    if ($this->glueMapCache !== null) {
      return $this->glueMapCache;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'fswa_pro_trigger_snippets';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
      return $this->glueMapCache = [];
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
      "SELECT webhook_id, pre_enabled, pre_snippet_id, post_enabled, post_snippet_id FROM {$table}",
      ARRAY_A
    );

    $map = [];
    foreach ($rows ?: [] as $row) {
      $wid = (int) $row['webhook_id'];
      if (!empty($row['pre_enabled']) && !empty($row['pre_snippet_id'])) {
        $map[$wid][] = 'pre_glue';
      }
      if (!empty($row['post_enabled']) && !empty($row['post_snippet_id'])) {
        $map[$wid][] = 'post_glue';
      }
    }

    return $this->glueMapCache = $map;
  }
}
