<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * How many times a failed delivery is retried, and how long it waits between
 * attempts.
 *
 * Both answers resolve the same way: the webhook's own setting first, then the
 * site-wide setting, then the shipped default. Anything left null at a level
 * falls through, so a webhook can override the strategy while inheriting the
 * delays.
 *
 * The option names keep their `fswa_pro_` prefix. They hold live settings on
 * every site that ran these features while they were a Pro feature, and
 * renaming them would quietly reset those sites to the defaults.
 */
class RetryPolicy {
  private const OPTION_GLOBAL_MAX_ATTEMPTS = 'fswa_pro_global_max_attempts';
  private const OPTION_BACKOFF_STRATEGY    = 'fswa_pro_backoff_strategy';
  private const OPTION_BACKOFF_BASE_DELAY  = 'fswa_pro_backoff_base_delay';
  private const OPTION_BACKOFF_MAX_DELAY   = 'fswa_pro_backoff_max_delay';

  public const DEFAULT_STRATEGY   = 'exponential';
  public const DEFAULT_BASE_DELAY = 30;
  public const DEFAULT_MAX_DELAY  = 3600;

  public const STRATEGIES = ['exponential', 'linear', 'fixed'];

  /** Bounds every stored retry/backoff value is clamped to. */
  public const MAX_ATTEMPTS_MIN = 1;
  public const MAX_ATTEMPTS_MAX = 100;
  public const DELAY_MIN        = 1;
  public const DELAY_MAX        = 86400;

  /**
   * Max delivery attempts for a webhook: per-webhook → global → $default.
   */
  public function maxAttempts(int $webhookId, int $default): int {
    if (!$this->owned()) {
      return $default;
    }

    if ($webhookId > 0) {
      $perWebhook = $this->webhookColumn($webhookId, 'retry_limit');
      if ($perWebhook !== null) {
        return (int) $perWebhook;
      }
    }

    $global = get_option(self::OPTION_GLOBAL_MAX_ATTEMPTS);

    return $global !== false ? (int) $global : $default;
  }

  /**
   * Seconds to wait before attempt number $attemptNumber, under the resolved
   * strategy. $default is returned untouched when an older Pro still owns
   * retry handling.
   */
  public function backoffDelay(int $webhookId, int $attemptNumber, int $default): int {
    if (!$this->owned()) {
      return $default;
    }

    $config = $this->backoffConfig($webhookId);

    return match ($config['strategy']) {
      'linear' => $config['base_delay'] * $attemptNumber,
      'fixed'  => $config['base_delay'],
      default  => min((int) pow(2, $attemptNumber) * $config['base_delay'], $config['max_delay']),
    };
  }

  /**
   * The resolved strategy and delays for a webhook.
   *
   * @return array{strategy: string, base_delay: int, max_delay: int}
   */
  public function backoffConfig(int $webhookId): array {
    $strategy  = null;
    $baseDelay = null;
    $maxDelay  = null;

    if ($webhookId > 0) {
      $row = $this->webhookRow($webhookId);
      if ($row) {
        $strategy  = $row['backoff_strategy'] ?: null;
        $baseDelay = $row['backoff_base_delay'] !== null ? (int) $row['backoff_base_delay'] : null;
        $maxDelay  = $row['backoff_max_delay'] !== null ? (int) $row['backoff_max_delay'] : null;
      }
    }

    if ($strategy === null) {
      $raw      = get_option(self::OPTION_BACKOFF_STRATEGY);
      $strategy = $raw !== false ? (string) $raw : null;
    }
    if ($baseDelay === null) {
      $raw       = get_option(self::OPTION_BACKOFF_BASE_DELAY);
      $baseDelay = $raw !== false ? (int) $raw : null;
    }
    if ($maxDelay === null) {
      $raw      = get_option(self::OPTION_BACKOFF_MAX_DELAY);
      $maxDelay = $raw !== false ? (int) $raw : null;
    }

    return [
      'strategy'   => $strategy ?? self::DEFAULT_STRATEGY,
      'base_delay' => $baseDelay ?? self::DEFAULT_BASE_DELAY,
      'max_delay'  => $maxDelay ?? self::DEFAULT_MAX_DELAY,
    ];
  }

  // ===================================================================
  // Value clamping — shared by the REST controller and the settings route
  // ===================================================================

  /** @return int|null Clamped attempt count, or null to inherit. */
  public static function clampAttempts(mixed $raw): ?int {
    if ($raw === null || $raw === '') {
      return null;
    }

    return max(self::MAX_ATTEMPTS_MIN, min(self::MAX_ATTEMPTS_MAX, (int) $raw));
  }

  /** @return int|null Clamped delay in seconds, or null to inherit. */
  public static function clampDelay(mixed $raw): ?int {
    if ($raw === null || $raw === '') {
      return null;
    }

    return max(self::DELAY_MIN, min(self::DELAY_MAX, (int) $raw));
  }

  /** @return string|null A known strategy name, or null to inherit. */
  public static function normalizeStrategy(mixed $raw): ?string {
    if ($raw === null || $raw === '') {
      return null;
    }

    $value = sanitize_text_field((string) $raw);

    return in_array($value, self::STRATEGIES, true) ? $value : null;
  }

  // ===================================================================
  // Internals
  // ===================================================================

  /**
   * Whether this plugin resolves retry settings, or an older Pro still does.
   *
   * Pro ≤ 1.8.4 answers the `fswa_max_attempts` / `fswa_backoff_delay` filters
   * itself from the same columns and options. Standing down here means the
   * resolution happens exactly once, in the plugin that owns it.
   */
  private function owned(): bool {
    return (new ProCompatibility())->ownsMovedFeatures();
  }

  private function webhookColumn(int $webhookId, string $column): mixed {
    $row = $this->webhookRow($webhookId);

    return $row[$column] ?? null;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function webhookRow(int $webhookId): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT retry_limit, backoff_strategy, backoff_base_delay, backoff_max_delay
         FROM {$wpdb->prefix}fswa_webhooks WHERE id = %d",
      $webhookId
    ), ARRAY_A);

    return $row ?: null;
  }
}
