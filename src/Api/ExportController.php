<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Api\AuthHelper;
use FlowSystems\WebhookActions\Services\Export\BuildExporter;
use FlowSystems\WebhookActions\Services\Export\BuildImporter;
use FlowSystems\WebhookActions\Services\ActivityLogService;

/**
 * REST surface for the portable build import/export feature.
 *
 *   POST fswa/v1/export         -> download a build document
 *   POST fswa/v1/import/analyze -> report credentials + collisions to resolve
 *   POST fswa/v1/import         -> create the webhooks/chains from a document
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

    $document = (new BuildExporter())->export($webhookIds, $chainIds, $all);

    $this->activityLog->log('build.exported', 'build', 0, null, [
      'webhooks' => count($document['webhooks']),
      'chains'   => count($document['chains']),
    ]);

    return rest_ensure_response($document);
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
