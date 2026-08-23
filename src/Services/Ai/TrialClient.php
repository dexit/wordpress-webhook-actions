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

  /**
   * What the grant is, before we have asked. Shown so a site can see what it is
   * being offered without us making an HTTP request just to render a panel; the
   * real value replaces it as soon as the API has been talked to for any reason.
   */
  private const DEFAULT_GRANT = 55;

  /**
   * Credits an average agent turn costs, for turning a balance into "about N
   * runs". Measured over real production usage rather than assumed: 9,329 input
   * + 325 output tokens per call on average, which prices at 11 credits. The
   * config it is estimating originally guessed 7, which is why this is a
   * measurement and not a constant someone picked.
   */
  private const CREDITS_PER_RUN = 11;

  public static function apiBase(): string {
    return rtrim((string) apply_filters('fswa_ai_api_base', self::DEFAULT_API_BASE), '/');
  }

  /**
   * What this site needs in order to claim a trial: the public Turnstile site
   * key, and whether it will be asked for a challenge at all.
   *
   * The key is served rather than shipped as a constant — one baked into the
   * plugin zip could not be rotated without a release, which would break the
   * trial on every install that had not updated.
   *
   * `required` is answered by the API, not guessed here, and it is normally
   * false: a challenge is demanded only where a widget can actually render
   * (Playground). So the ordinary install never loads Cloudflare's script on the
   * way to its first prompt — and never mints a token from a hostname the API
   * does not recognise, which would be rejected precisely because any supplied
   * token must validate.
   *
   * Both fall back to "no key, no challenge" when the API is unreachable; the
   * API then decides whether a token-less request is acceptable.
   *
   * @return array{site_key: string, required: bool}
   */
  public function challengeConfig(): array {
    $cached = get_transient(self::SITE_KEY_TRANSIENT);
    if (is_array($cached)) {
      return $cached;
    }

    $url = add_query_arg(
      ['site_url' => rawurlencode(home_url())],
      self::apiBase() . '/api/ai/trial/config'
    );

    $response = wp_remote_get($url, [
      'timeout' => (int) apply_filters('fswa_ai_http_timeout', 15),
    ]);

    $miss = ['site_key' => '', 'required' => false, 'credits' => self::DEFAULT_GRANT];

    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
      // Cache the miss briefly so a hard-down API doesn't mean a request per click.
      set_transient(self::SITE_KEY_TRANSIENT, $miss, 5 * MINUTE_IN_SECONDS);

      return $miss;
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    // An API old enough not to answer this question still enforces the challenge
    // where it always did, so "field absent" must mean *attempt it*, not skip it.
    // Defaulting the other way would break Playground for exactly as long as it
    // took to deploy the two sides in the wrong order.
    $required = is_array($data) && array_key_exists('challenge_required', $data)
      ? (bool) $data['challenge_required']
      : true;

    $config = [
      'site_key' => (string) ($data['site_key'] ?? ''),
      'required' => $required,
      'credits'  => (int) ($data['credits'] ?? self::DEFAULT_GRANT),
    ];

    // A 200 carrying no key is a misconfiguration at our end (mid-rotation, an
    // unset env var), not a settled answer — so it gets the short negative TTL,
    // not a day. Caching an empty key for a day would silently disable the trial
    // in Playground, where a token is mandatory, for every install that happened
    // to ask during the window.
    set_transient(
      self::SITE_KEY_TRANSIENT,
      $config,
      $config['site_key'] === '' ? 5 * MINUTE_IN_SECONDS : DAY_IN_SECONDS
    );

    return $config;
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
    $state   = $this->state();
    $started = $this->isStarted();

    // What an unclaimed trial is worth. Read from the cached API config when we
    // have one and the shipped default otherwise — deliberately NOT fetched here,
    // because status() renders on every admin load and an offer is not worth an
    // HTTP request per page view.
    $cached = get_transient(self::SITE_KEY_TRANSIENT);
    $grant  = is_array($cached) && isset($cached['credits'])
      ? (int) $cached['credits']
      : (int) apply_filters('fswa_ai_trial_grant', self::DEFAULT_GRANT);

    $credits = $started ? $this->creditsRemaining() : $grant;

    return [
      'started'    => $started,
      'credits'    => $this->creditsRemaining(),
      'exhausted'  => $this->isExhausted(),
      'expires_at' => (string) ($state['expires_at'] ?? ''),
      // The offer, for a site that has not claimed one yet.
      'grant'      => $grant,
      // Credits → something a human can act on. The UI should not be doing this
      // arithmetic itself, because the divisor is a measurement that will move.
      'runs_left'  => (int) max(0, floor($credits / max(1, (int) apply_filters('fswa_ai_trial_credits_per_run', self::CREDITS_PER_RUN)))),
    ];
  }
}
