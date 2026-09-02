<?php

namespace FlowSystems\WebhookActions\Services\Builds;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Services\Ai\TrialClient;
use WP_Error;

/**
 * Ships a portable build document to the WP Webhooks API, which content-checks
 * it, publishes it on wpwebhooks.org and pays the author in AI credits.
 *
 * Auth is license_key + site_url in the body. Any licence the API knows this
 * site by will do: a Pro key when Pro is installed, otherwise the anonymous
 * trial key the free plugin mints on its first Build-with-AI run. Publishing is
 * therefore not a paid feature — the 50-credit reward landing in a free site's
 * trial pool is the point.
 */
class PublishBuildClient {
  private const ENDPOINT = 'https://api.wpwebhooks.org/api/builds/publish';

  /** Pro's licence key option, read directly so free does not depend on Pro's classes. */
  private const OPT_PRO_LICENSE_KEY = 'fswa_pro_license_key';

  /** Pro's cached credit summary, the same option HostedTransport keeps warm. */
  private const OPT_PRO_CREDITS = 'fswa_pro_ai_credits';

  /**
   * Publish a build.
   *
   * @param array<string, mixed> $doc  The portable export document.
   * @param array<string, mixed> $meta title, summary, collection, author_*.
   * @return array<string, mixed>|WP_Error The API's success payload.
   */
  public function publish(array $doc, array $meta): array|WP_Error {
    $licenseKey = $this->licenseKey();
    if ($licenseKey === '') {
      return new WP_Error(
        'fswa_publish_license',
        __('Publishing a build needs a licence this site is known by. Run a build with AI once to claim the free trial licence, or enter a Pro key.', 'flowsystems-webhook-actions'),
        ['status' => 403]
      );
    }

    $body = [
      'license_key'     => $licenseKey,
      'site_url'        => home_url(),
      'title'           => (string) ($meta['title'] ?? ''),
      'summary'         => (string) ($meta['summary'] ?? ''),
      'author_name'     => (string) ($meta['author_name'] ?? ''),
      'author_url'      => (string) ($meta['author_url'] ?? ''),
      'author_linkedin' => (string) ($meta['author_linkedin'] ?? ''),
      'collection'      => (string) ($meta['collection'] ?? ''),
      'doc'             => $doc,
    ];

    // Empty optional strings would fail the API's url rules; drop them.
    foreach (['summary', 'author_name', 'author_url', 'author_linkedin'] as $optional) {
      if ($body[$optional] === '') {
        unset($body[$optional]);
      }
    }

    $endpoint = (string) apply_filters('fswa_builds_publish_endpoint', self::ENDPOINT);

    $response = wp_remote_post($endpoint, [
      // The API runs a synchronous AI content check before it answers.
      'timeout' => (int) apply_filters('fswa_builds_publish_timeout', 60),
      'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
      'body'    => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
      return new WP_Error(
        'fswa_publish_unreachable',
        sprintf(
          /* translators: %s: HTTP error message. */
          __('Could not reach the WP Webhooks publishing service: %s', 'flowsystems-webhook-actions'),
          $response->get_error_message()
        ),
        ['status' => 502]
      );
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    $data = is_array($data) ? $data : [];

    if ($code === 201 && !empty($data['ok'])) {
      $this->storeCredits($data['credits'] ?? null);
      return $data;
    }

    return $this->errorFromResponse($code, $data);
  }

  /**
   * @param array<string, mixed> $data
   */
  private function errorFromResponse(int $code, array $data): WP_Error {
    $apiMessage = (string) ($data['message'] ?? '');

    // The content check said no. Its reason is the whole point of the reply —
    // it tells the author what to rewrite, so it is surfaced verbatim.
    if ($code === 422 && ($data['error'] ?? '') === 'content_rejected') {
      return new WP_Error(
        'fswa_publish_rejected',
        $apiMessage !== '' ? $apiMessage : __('This build did not pass our content check.', 'flowsystems-webhook-actions'),
        ['status' => 422, 'categories' => $data['categories'] ?? []]
      );
    }

    if ($code === 422) {
      return new WP_Error(
        'fswa_publish_invalid',
        $apiMessage !== '' ? $apiMessage : __('The publishing service rejected this build.', 'flowsystems-webhook-actions'),
        ['status' => 422]
      );
    }

    // One build, one page. The API answers with the page it made the first
    // time, so this is a link to follow rather than something to retry.
    if ($code === 409 && ($data['error'] ?? '') === 'already_published') {
      return new WP_Error(
        'fswa_publish_duplicate',
        $apiMessage !== '' ? $apiMessage : __('You have already published this build.', 'flowsystems-webhook-actions'),
        [
          'status'    => 409,
          'published' => [
            'slug'         => (string) ($data['slug'] ?? ''),
            'url'          => (string) ($data['url'] ?? ''),
            'status'       => (string) ($data['status'] ?? ''),
            'published_at' => (string) ($data['published_at'] ?? ''),
          ],
        ]
      );
    }

    // Someone else already published this recipe. Not the author's own
    // duplicate, so it gets its own code and its own copy — the useful thing
    // is the existing page, which the API names.
    if ($code === 409 && ($data['error'] ?? '') === 'similar_build_exists') {
      $published = is_array($data['published'] ?? null) ? $data['published'] : [];

      return new WP_Error(
        'fswa_publish_similar',
        $apiMessage !== '' ? $apiMessage : __('The library already has a build like this one.', 'flowsystems-webhook-actions'),
        [
          'status'    => 409,
          'published' => [
            'slug'  => (string) ($published['slug'] ?? ''),
            'url'   => (string) ($published['url'] ?? ''),
            'title' => (string) ($published['title'] ?? ''),
          ],
        ]
      );
    }

    if ($code === 403) {
      return new WP_Error(
        'fswa_publish_license',
        $apiMessage !== '' ? $apiMessage : __('Your license does not allow publishing from this site.', 'flowsystems-webhook-actions'),
        ['status' => 403]
      );
    }

    if ($code === 429) {
      return new WP_Error(
        'fswa_publish_rate_limited',
        $apiMessage !== '' ? $apiMessage : __('Too many builds published from this license — wait a moment and try again.', 'flowsystems-webhook-actions'),
        ['status' => 429]
      );
    }

    if ($code >= 500) {
      return new WP_Error(
        'fswa_publish_server_error',
        __('The publishing service had a temporary problem — nothing was published. Please try again.', 'flowsystems-webhook-actions'),
        ['status' => 502]
      );
    }

    return new WP_Error(
      'fswa_publish_error',
      $apiMessage !== '' ? $apiMessage : sprintf(
        /* translators: %d: HTTP status code. */
        __('The publishing service returned an unexpected error (HTTP %d).', 'flowsystems-webhook-actions'),
        $code
      ),
      ['status' => $code ?: 502]
    );
  }

  /**
   * The licence this site is known by: a Pro key when one is installed,
   * otherwise the anonymous trial key. Pro wins so a paying site's publishes
   * and its rewards land on the licence it actually bought.
   */
  private function licenseKey(): string {
    $pro = trim((string) get_option(self::OPT_PRO_LICENSE_KEY, ''));
    if ($pro !== '') {
      return $pro;
    }

    return (new TrialClient())->key();
  }

  /**
   * The publish reward lands immediately, so refresh whichever cached balance
   * the settings UI is reading — Pro's credit summary when Pro holds the
   * licence, the trial pool when it does not.
   */
  private function storeCredits(mixed $credits): void {
    if (!is_array($credits) || $credits === []) {
      return;
    }

    if (trim((string) get_option(self::OPT_PRO_LICENSE_KEY, '')) !== '') {
      update_option(self::OPT_PRO_CREDITS, [
        'credits'    => $credits,
        'fetched_at' => time(),
      ], false);
      return;
    }

    // The trial pool is one number, summed exactly as HostedTrialTransport
    // sums it — the publish reward lands in topup_remaining, so reading only
    // monthly_remaining would show the author none of what they just earned.
    $monthly = $credits['monthly_remaining'] ?? null;
    $topup   = $credits['topup_remaining'] ?? null;
    if ($monthly !== null || $topup !== null) {
      (new TrialClient())->rememberCredits((int) $monthly + (int) $topup);
    }
  }
}
