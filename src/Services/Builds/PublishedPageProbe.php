<?php

namespace FlowSystems\WebhookActions\Services\Builds;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Asks whether a just-published build's page is actually live yet.
 *
 * wpwebhooks.org is a static site that fetches published builds at build time,
 * so the URL the API hands back 404s until the site has been rebuilt — around a
 * minute and a half. The admin waits on that, and the wait has to be measured
 * from the server: a cross-origin `fetch` from the browser cannot read a 404
 * (an opaque no-cors response looks identical to a hit), and the site sends no
 * CORS headers for its pages.
 */
class PublishedPageProbe {
  private const HOST = 'wpwebhooks.org';

  /**
   * @return array{live: bool, code: int}|WP_Error
   */
  public function probe(string $url): array|WP_Error {
    if (!$this->isPublishedBuildUrl($url)) {
      return new WP_Error(
        'fswa_publish_status_url',
        __('That is not a published build address.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }

    // Cache-busted: a CDN that cached the 404 during the rebuild would
    // otherwise keep answering with it long after the page went live.
    $target  = add_query_arg('fswa_check', (string) time(), $url);
    $args    = ['timeout' => 10, 'redirection' => 2, 'headers' => ['Cache-Control' => 'no-cache']];
    $response = wp_remote_head($target, $args);

    // Static hosts are inconsistent about HEAD; fall back to a GET rather than
    // reporting "not live" for a page that is.
    $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    if (is_wp_error($response) || $code === 405 || $code === 501) {
      $response = wp_remote_get($target, $args);
      $code     = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    }

    if (is_wp_error($response)) {
      // Not an error the caller should act on — the site being unreachable for
      // a moment is indistinguishable from it still building. Keep waiting.
      return ['live' => false, 'code' => 0];
    }

    return ['live' => $code >= 200 && $code < 300, 'code' => $code];
  }

  /**
   * Only wpwebhooks.org build pages, so this route cannot be turned into an
   * arbitrary outbound request from the admin.
   */
  private function isPublishedBuildUrl(string $url): bool {
    /**
     * Host serving published build pages. Filterable so a local website build
     * can be waited on during development.
     *
     * @param string $host Bare host name, no scheme.
     */
    $allowed = (string) apply_filters('fswa_builds_website_host', self::HOST);

    $parts  = wp_parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    $host   = strtolower($parts['host'] ?? '');
    $path   = $parts['path'] ?? '';

    if (!in_array($scheme, ['http', 'https'], true)) {
      return false;
    }

    if ($host !== $allowed && $host !== 'www.' . $allowed) {
      return false;
    }

    return (bool) preg_match('#^/(integrations|automations)/[a-z0-9-]+/?$#i', $path);
  }
}
