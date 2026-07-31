<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Api\AuthHelper;
use FlowSystems\WebhookActions\Services\Export\BuildExporter;
use FlowSystems\WebhookActions\Services\Export\BuildImporter;
use FlowSystems\WebhookActions\Services\Export\ConversationBuildResolver;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\ChainRepository;
use FlowSystems\WebhookActions\Services\ActivityLogService;

/**
 * REST surface for the portable build import/export feature.
 *
 *   POST fswa/v1/export          -> download a build document
 *   POST fswa/v1/export/resolve  -> what a given export would contain
 *   POST fswa/v1/import/analyze  -> report credentials + collisions to resolve
 *   POST fswa/v1/import          -> create the webhooks/chains from a document
 */
class ExportController {
  private string $namespace = 'fswa/v1';
  private ActivityLogService $activityLog;

  public function __construct() {
    $this->activityLog = new ActivityLogService();
  }

  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/export', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'exportBuild'],
      'permission_callback' => [$this, 'readPermissions'],
    ]);

    register_rest_route($this->namespace, '/export/resolve', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'resolveBuild'],
      'permission_callback' => [$this, 'readPermissions'],
    ]);

    register_rest_route($this->namespace, '/import/analyze', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'analyzeImport'],
      'permission_callback' => [$this, 'writePermissions'],
    ]);

    register_rest_route($this->namespace, '/import', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'runImport'],
      'permission_callback' => [$this, 'writePermissions'],
    ]);
  }

  public function readPermissions($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_OPERATIONAL);
  }

  public function writePermissions($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  public function exportBuild($request): WP_REST_Response {
    $webhookIds = (array) ($request->get_param('webhook_ids') ?? []);
    $chainIds   = (array) ($request->get_param('chain_ids') ?? []);
    $all        = (bool) $request->get_param('all');
    $options    = $this->exportOptions($request);

    // "Share this build" from the AI Builder names a conversation instead of a
    // hand-picked id list: resolve it to the objects that build created.
    if (!empty($options['conversation_id'])) {
      $resolved   = (new ConversationBuildResolver())->resolve((int) $options['conversation_id']);
      $webhookIds = array_merge($webhookIds, $resolved['webhook_ids']);
      $chainIds   = array_merge($chainIds, $resolved['chain_ids']);
    }

    $document = (new BuildExporter())->export($webhookIds, $chainIds, $all, $options);

    $this->activityLog->log('build.exported', 'build', 0, null, [
      'webhooks'      => count($document['webhooks']),
      'chains'        => count($document['chains']),
      'ai_transcript' => isset($document['ai_build']),
      'anonymized'    => !empty($options['anonymize_site_url']),
      'raw_examples'  => !empty($options['keep_captured_values']),
    ]);

    return rest_ensure_response($document);
  }

  /**
   * What would this export contain? Returns the resolved webhooks and chains
   * with their descriptions, so the share flow can point out the ones that are
   * still undocumented and offer to write a description before sharing.
   */
  public function resolveBuild($request): WP_REST_Response {
    $webhookIds = array_map('intval', (array) ($request->get_param('webhook_ids') ?? []));
    $chainIds   = array_map('intval', (array) ($request->get_param('chain_ids') ?? []));

    $conversationId = (int) $request->get_param('conversation_id');
    if ($conversationId > 0) {
      $resolved   = (new ConversationBuildResolver())->resolve($conversationId);
      $webhookIds = array_merge($webhookIds, $resolved['webhook_ids']);
      $chainIds   = array_merge($chainIds, $resolved['chain_ids']);
    }

    $webhooks = new WebhookRepository();
    $chains   = new ChainRepository();

    $webhookItems = [];
    foreach (array_unique(array_filter($webhookIds)) as $id) {
      $row = $webhooks->find((int) $id);
      if ($row !== null) {
        $webhookItems[] = [
          'id'          => (int) $row['id'],
          'name'        => (string) ($row['name'] ?? ''),
          'description' => (string) ($row['description'] ?? ''),
        ];
      }
    }

    $chainItems = [];
    foreach (array_unique(array_filter($chainIds)) as $id) {
      $row = $chains->find((int) $id);
      if ($row !== null) {
        $chainItems[] = [
          'id'          => (int) $row['id'],
          'name'        => (string) ($row['name'] ?? ''),
          'description' => (string) ($row['description'] ?? ''),
        ];
      }
    }

    return rest_ensure_response(['webhooks' => $webhookItems, 'chains' => $chainItems]);
  }

  /**
   * Normalize the caller's export intent. Unknown keys pass through untouched so
   * extensions can read their own options off the `fswa_export_doc` filter.
   *
   * @return array<string, mixed>
   */
  private function exportOptions($request): array {
    $options = (array) ($request->get_param('options') ?? []);

    $conversationId = (int) ($options['conversation_id'] ?? $request->get_param('conversation_id'));
    if ($conversationId > 0) {
      $options['conversation_id'] = $conversationId;
    } else {
      unset($options['conversation_id']);
    }

    foreach (['include_ai_transcript', 'anonymize_site_url', 'keep_captured_values'] as $flag) {
      if (array_key_exists($flag, $options)) {
        $options[$flag] = rest_sanitize_boolean($options[$flag]);
      }
    }

    return $options;
  }

  public function analyzeImport($request) {
    $document = $request->get_param('document');
    if (!is_array($document)) {
      return new WP_Error('fswa_import_invalid', __('Missing import document.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $result = (new BuildImporter())->analyze($document);
    if ($result instanceof WP_Error) {
      return $result;
    }

    return rest_ensure_response($result);
  }

  public function runImport($request) {
    $document = $request->get_param('document');
    if (!is_array($document)) {
      return new WP_Error('fswa_import_invalid', __('Missing import document.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $credentialMap = [];
    foreach ((array) ($request->get_param('credential_map') ?? []) as $ref => $id) {
      $credentialMap[(string) $ref] = (int) $id;
    }
    $options = (array) ($request->get_param('options') ?? []);

    $result = (new BuildImporter())->import($document, $credentialMap, $options);
    if ($result instanceof WP_Error) {
      return $result;
    }

    $this->activityLog->log('build.imported', 'build', 0, null, $result);

    return rest_ensure_response($result);
  }
}
