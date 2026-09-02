<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Api\AuthHelper;
use FlowSystems\WebhookActions\Services\ActivityLogService;
use FlowSystems\WebhookActions\Services\Export\BuildExporter;
use FlowSystems\WebhookActions\Services\Export\ConversationBuildResolver;
use FlowSystems\WebhookActions\Services\Builds\PublishBuildClient;
use FlowSystems\WebhookActions\Services\Builds\PublishedPageProbe;

/**
 * POST fswa/v1/pro/publish — publish a build to wpwebhooks.org.
 *
 * The document is assembled here with the free plugin's exporter (so Pro's own
 * `fswa_export_trigger` / `fswa_export_doc` hooks attach Code Glue and, when the
 * author opts in, the Build-with-AI conversation), then handed to
 * {@see PublishBuildClient}, which is the only place the license key is read.
 */
class PublishBuildController {
  private string $namespace = 'fswa/v1';
  private string $rest_base = 'pro/publish';

  private const COLLECTIONS = ['integrations', 'automations'];

  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'publish'],
      'permission_callback' => [$this, 'permissionsCheck'],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/status', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [$this, 'status'],
      'permission_callback' => [$this, 'readPermissionsCheck'],
      'args'                => [
        'url' => ['type' => 'string', 'required' => true],
      ],
    ]);
  }

  public function permissionsCheck(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  public function readPermissionsCheck(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  /**
   * GET fswa/v1/pro/publish/status — is the published page live yet?
   *
   * The website is static and rebuilds after a publish, so the URL the API
   * returned 404s for a minute or so. The admin polls this while it waits.
   */
  public function status(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $result = (new PublishedPageProbe())->probe((string) $request->get_param('url'));

    return $result instanceof WP_Error ? $result : rest_ensure_response($result);
  }

  public function publish(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $title      = trim((string) $request->get_param('title'));
    $collection = (string) $request->get_param('collection');

    if ($title === '') {
      return new WP_Error('fswa_publish_invalid', __('Give the build a title.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    // The author files the build under exactly one collection — it is never
    // inferred from the content, and the API rejects anything else.
    if (!in_array($collection, self::COLLECTIONS, true)) {
      return new WP_Error('fswa_publish_invalid', __('Choose whether this is an integration or an automation.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $conversationId = (int) $request->get_param('conversation_id');
    $webhookIds     = array_map('intval', (array) ($request->get_param('webhook_ids') ?? []));
    $chainIds       = array_map('intval', (array) ($request->get_param('chain_ids') ?? []));

    if ($conversationId > 0) {
      $resolved   = (new ConversationBuildResolver())->resolve($conversationId);
      $webhookIds = array_merge($webhookIds, $resolved['webhook_ids']);
      $chainIds   = array_merge($chainIds, $resolved['chain_ids']);
    }

    $options = [
      'include_ai_transcript' => rest_sanitize_boolean($request->get_param('include_ai_transcript')),
      'anonymize_site_url'    => rest_sanitize_boolean($request->get_param('anonymize_site_url')),
      // Not a caller's choice: a published build is a public page, so the
      // personal data in captured example payloads is always redacted.
      'keep_captured_values'  => false,
    ];
    if ($conversationId > 0) {
      $options['conversation_id'] = $conversationId;
    }

    $doc = (new BuildExporter())->export($webhookIds, $chainIds, false, $options);

    if (empty($doc['webhooks'])) {
      return new WP_Error('fswa_publish_empty', __('There is nothing to publish — this build has no webhooks.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $result = (new PublishBuildClient())->publish($doc, [
      'title'           => $title,
      'summary'         => (string) $request->get_param('summary'),
      'author_name'     => (string) $request->get_param('author_name'),
      'author_url'      => (string) $request->get_param('author_url'),
      'author_linkedin' => (string) $request->get_param('author_linkedin'),
      'collection'      => $collection,
    ]);

    if ($result instanceof WP_Error) {
      return $result;
    }

    (new ActivityLogService())->log('build.published', 'build', 0, $title, [
      'slug'            => $result['slug'] ?? '',
      'url'             => $result['url'] ?? '',
      'status'          => $result['status'] ?? '',
      'collection'      => $collection,
      'webhooks'        => count($doc['webhooks']),
      'chains'          => count($doc['chains'] ?? []),
      'ai_transcript'   => isset($doc['ai_build']),
      'anonymized'      => !empty($options['anonymize_site_url']),
      'credits_awarded' => (int) ($result['credits_awarded'] ?? 0),
    ]);

    return rest_ensure_response($result);
  }
}
