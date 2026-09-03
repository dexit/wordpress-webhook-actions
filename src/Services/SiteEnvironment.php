<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * What kind of site this is: a real one on the internet, a local development
 * box, or a throwaway WordPress Playground sandbox.
 *
 * The distinction matters wherever the plugin would put something of this
 * site's into the world — publishing a build, most of all. A Playground
 * instance is a demo that evaporates on refresh, so anything published from
 * one is by definition not a working integration someone runs.
 */
class SiteEnvironment {
  /** Playground serves every instance from this host. */
  private const PLAYGROUND_HOST = 'playground.wordpress.net';

  /**
   * True inside a WordPress Playground sandbox.
   *
   * Checked three ways because none is guaranteed alone: Playground's PHP is
   * compiled to WebAssembly and reports itself in SERVER_SOFTWARE, recent
   * builds define their own version constant, and an instance opened from a
   * blueprint URL is served from the Playground host.
   */
  public function isPlayground(): bool {
    if (defined('WP_PLAYGROUND_VERSION')) {
      return true;
    }

    $software = isset($_SERVER['SERVER_SOFTWARE'])
      ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))
      : '';
    if (stripos($software, 'php.wasm') !== false || stripos($software, 'playground') !== false) {
      return true;
    }

    return $this->host() === self::PLAYGROUND_HOST;
  }

  /**
   * True when this site's address is one nothing on the internet can reach —
   * a development machine, a container, a private network.
   */
  public function isLocal(): bool {
    $host = $this->host();

    if ($host === '' || $host === 'localhost') {
      return true;
    }

    // A bare hostname with no dot, plus the reserved development suffixes.
    if (!str_contains($host, '.') || (bool) preg_match('/\.(local|localhost|test|invalid|example)$/i', $host)) {
      return true;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
      return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    return false;
  }

  /**
   * Whether this site may publish a build to wpwebhooks.org.
   *
   * A published build is a public page carrying this site's configuration, so
   * it has to come from a site that actually runs it. Playground sandboxes and
   * development boxes are excluded — not as a licence check, but because
   * neither is a site whose integration anyone could be said to be using.
   *
   * @return true|string True when allowed, else the reason to show the author.
   */
  public function canPublishBuild(): bool|string {
    if ($this->isPlayground()) {
      return __('This is a WordPress Playground demo — it disappears when you close the tab, so builds cannot be published from it. Install Webhook Actions on your own site to publish.', 'flowsystems-webhook-actions');
    }

    if ($this->isLocal()) {
      return __('This site\'s address is not reachable from the internet, so a build published from it could not be verified or visited. Publish from the site that actually runs this integration.', 'flowsystems-webhook-actions');
    }

    /**
     * Whether this site may publish a build.
     *
     * @param bool|string $allowed True to allow, or the reason string to refuse with.
     */
    return apply_filters('fswa_can_publish_build', true);
  }

  private function host(): string {
    return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
  }
}
