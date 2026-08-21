<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Owns the anonymous WP Webhooks AI trial: starting one, remembering it, and
 * reporting what is left.
 *
 * Build with AI used to demand a third-party API key before a new install could
 * see anything work at all. A trial removes that: one click, no account, no key,
 * enough credits for roughly five agent runs.
 *
 * The trial is issued by our API, not minted here, and issuance requires a
 * Cloudflare Turnstile token that only a browser can produce — so starting a
 * trial is always driven from the admin UI, never lazily from a server-side call.
 */
class TrialClient {
  public const OPTION = 'fswa_ai_trial';

  private const SITE_KEY_TRANSIENT = 'fswa_ai_trial_site_key';

  private const DEFAULT_API_BASE = 'https://api.wpwebhooks.org';

  public static function apiBase(): string {
    return rtrim((string) apply_filters('fswa_ai_api_base', self::DEFAULT_API_BASE), '/');
  }

  /**
   * The public Turnstile site key, fetched from our API and cached for a day.
   *
   * Not shipped as a constant: a key baked into the plugin zip could not be
   * rotated without a release, which would break the trial on every install that
   * had not updated. Returns '' when unavailable — the caller sends no token and
   * the API decides whether to accept it (in production it will not).
   */
  public function siteKey(): string {
    $cached = get_transient(self::SITE_KEY_TRANSIENT);
    if (is_string($cached)) {
      return $cached;
    }

    $response = wp_remote_get(self::apiBase() . '/api/ai/trial/config', [
      'timeout' => (int) apply_filters('fswa_ai_http_timeout', 15),
    ]);

    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
      // Cache the miss briefly so a hard-down API doesn't mean a request per click.
      set_transient(self::SITE_KEY_TRANSIENT, '', 5 * MINUTE_IN_SECONDS);

      return '';
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    $key  = (string) ($data['site_key'] ?? '');

    set_transient(self::SITE_KEY_TRANSIENT, $key, DAY_IN_SECONDS);

    return $key;
  }

  /**
   * Exchange a Turnstile token for a trial licence and remember it.
   *
   * @return array<string, mixed>|WP_Error
   */
  public function start(string $turnstileToken): array|WP_Error {
    $response = wp_remote_post(self::apiBase() . '/api/ai/trial', [
      'timeout' => (int) apply_filters('fswa_ai_http_timeout', 30),
      'headers' => ['content-type' => 'application/json'],
      'body'    => wp_json_encode([
        'site_url'        => home_url(),
        'turnstile_token' => $turnstileToken,
      ]),
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300 || empty($data['license_key'])) {
      return new WP_Error(
        'fswa_trial_start_failed',
        (string) ($data['message'] ?? __('Could not start the free trial. Please try again.', 'flowsystems-webhook-actions')),
        ['status' => $code, 'error' => $data['error'] ?? null]
      );
    }

    $state = [
      'license_key' => (string) $data['license_key'],
      'credits'     => (int) ($data['credits'] ?? 0),
      'expires_at'  => (string) ($data['expires_at'] ?? ''),
      'started_at'  => gmdate('c'),
      'exhausted'   => false,
    ];

    update_option(self::OPTION, $state, false);

    return $state;
  }

  /** @return array<string, mixed> */
  public function state(): array {
    $state = get_option(self::OPTION, []);

    return is_array($state) ? $state : [];
  }

  public function key(): string {
    return (string) ($this->state()['license_key'] ?? '');
  }

  public function isStarted(): bool {
    return $this->key() !== '';
  }

  public function creditsRemaining(): int {
    return (int) ($this->state()['credits'] ?? 0);
  }

  public function isExhausted(): bool {
    return (bool) ($this->state()['exhausted'] ?? false);
  }

  /**
   * Remember what the API reported after a call, so the credits chip is accurate
   * without a second round-trip.
   */
  public function rememberCredits(?int $remaining, bool $exhausted = false): void {
    $state = $this->state();
    if ($state === []) {
      return;
    }

    if ($remaining !== null) {
      $state['credits'] = max(0, $remaining);
    }
    if ($exhausted) {
      $state['exhausted'] = true;
    }

    update_option(self::OPTION, $state, false);
  }

  /**
   * Status for the admin UI. `available` drives whether we offer to start one —
   * a site that already burned its trial should be shown the buy/BYOK path, not
   * a button that will fail.
   *
   * @return array<string, mixed>
   */
  public function status(): array {
    $state = $this->state();

    return [
      'started'    => $this->isStarted(),
      'credits'    => $this->creditsRemaining(),
      'exhausted'  => $this->isExhausted(),
      'expires_at' => (string) ($state['expires_at'] ?? ''),
    ];
  }
}
