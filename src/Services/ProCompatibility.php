<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Keeps this release from colliding with a Pro plugin that has not caught up.
 *
 * Code Glue, `{{ }}` URL templates, per-webhook retry/backoff and publishing a
 * build used to live in Webhook Actions Pro and now ship here. Pro 1.9.0 drops
 * its copies. In the window where an older Pro (≤ 1.8.4) is still installed,
 * BOTH plugins would hook `fswa_webhook_payload`, and every pre-dispatch
 * snippet would run twice on live deliveries — silently double-transforming
 * real payloads rather than erroring.
 *
 * So free simply stands down while an old Pro is present: Pro keeps running
 * those features exactly as it did before the update, and an admin notice says
 * what to do. Pro self-updates, so the window is short.
 */
class ProCompatibility {
  /** The first Pro release that no longer ships the features that moved here. */
  public const PRO_MIN_VERSION = '1.9.0';

  private ?bool $conflict = null;

  /**
   * True when an out-of-date Pro is loaded and still owns the moved features.
   */
  public function hasOutdatedPro(): bool {
    if ($this->conflict !== null) {
      return $this->conflict;
    }

    return $this->conflict = defined('FSWA_PRO_VERSION')
      && version_compare((string) FSWA_PRO_VERSION, self::PRO_MIN_VERSION, '<');
  }

  /**
   * Whether this plugin should own the features that moved out of Pro.
   */
  public function ownsMovedFeatures(): bool {
    return !$this->hasOutdatedPro();
  }

  /**
   * Tell the admin why Code Glue and friends still look like Pro features.
   */
  public function registerNotice(): void {
    if (!$this->hasOutdatedPro()) {
      return;
    }

    add_action('admin_notices', function (): void {
      if (!current_user_can('update_plugins')) {
        return;
      }

      printf(
        '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
        esc_html(sprintf(
          /* translators: %s: required Webhook Actions Pro version, e.g. 1.9.0. */
          __('Update Webhook Actions Pro to %s. Code Glue, dynamic URL templates and retry settings now ship in the free Webhook Actions plugin — until Pro is updated it keeps running its own copies, so nothing breaks, but you are on the old versions of those features.', 'flowsystems-webhook-actions'),
          self::PRO_MIN_VERSION
        ))
      );
    });
  }
}
