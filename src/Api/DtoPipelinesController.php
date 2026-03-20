<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\DtoPipelineRepository;
use FlowSystems\WebhookActions\Services\DtoPipelineProcessor;
use FlowSystems\WebhookActions\Services\TemplateRenderer;

/**
 * REST controller for DTO/ETL pipeline CRUD.
 *
 * Routes:
 *   GET    fswa/v1/dto              – list all pipelines
 *   POST   fswa/v1/dto              – create pipeline
 *   GET    fswa/v1/dto/{id}         – get single pipeline
 *   PUT    fswa/v1/dto/{id}         – update pipeline
 *   DELETE fswa/v1/dto/{id}         – delete pipeline
 *   POST   fswa/v1/dto/{id}/test    – test pipeline against a sample payload
 */
class DtoPipelinesController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'dto';

  private DtoPipelineRepository $pipelines;

  public function __construct() {
    $this->pipelines = new DtoPipelineRepository();
  }

  public function registerRoutes(): void {
    // Collection
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getItems'],
        'permission_callback' => [$this, 'readPermission'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'createItem'],
        'permission_callback' => [$this, 'writePermission'],
      ],
    ]);

    // Single
    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getItem'],
        'permission_callback' => [$this, 'readPermission'],
        'args'                => ['id' => ['type' => 'integer']],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [$this, 'updateItem'],
        'permission_callback' => [$this, 'writePermission'],
        'args'                => ['id' => ['type' => 'integer']],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'deleteItem'],
        'permission_callback' => [$this, 'writePermission'],
        'args'                => ['id' => ['type' => 'integer']],
      ],
    ]);

    // Test a pipeline against a sample payload
    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/test', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'testPipeline'],
      'permission_callback' => [$this, 'readPermission'],
      'args'                => [
        'id'      => ['type' => 'integer'],
        'payload' => ['type' => 'object'],
        'query'   => ['type' => 'object'],
        'headers' => ['type' => 'object'],
      ],
    ]);
  }

  // ---------------------------------------------------------------------------
  // Permissions
  // ---------------------------------------------------------------------------

  public function readPermission($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  public function writePermission($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  // ---------------------------------------------------------------------------
  // CRUD
  // ---------------------------------------------------------------------------

  public function getItems(WP_REST_Request $request): WP_REST_Response {
    return rest_ensure_response($this->pipelines->getAll());
  }

  public function getItem(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $pipeline = $this->resolve($request);
    if (is_wp_error($pipeline)) {
      return $pipeline;
    }

    return rest_ensure_response($pipeline);
  }

  public function createItem(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $name = sanitize_text_field($request->get_param('name') ?? '');
    if (empty($name)) {
      return new WP_Error('rest_missing_name', __('Pipeline name is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $slug = sanitize_title($request->get_param('slug') ?? '') ?: sanitize_title($name);
    if (empty($slug)) {
      return new WP_Error('rest_invalid_slug', __('A valid slug could not be derived.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    if ($this->pipelines->slugExists($slug)) {
      return new WP_Error('rest_slug_taken', __('A pipeline with this slug already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }

    $pipelineConfig = $request->get_param('pipeline_config');
    if (!is_array($pipelineConfig)) {
      $pipelineConfig = [];
    }

    $id = $this->pipelines->create([
      'name'            => $name,
      'slug'            => $slug,
      'description'     => sanitize_textarea_field($request->get_param('description') ?? '') ?: null,
      'pipeline_config' => $pipelineConfig,
      'is_enabled'      => (bool) ($request->get_param('is_enabled') ?? true),
    ]);

    if (!$id) {
      return new WP_Error('rest_create_failed', __('Failed to create pipeline.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return rest_ensure_response($this->pipelines->find($id));
  }

  public function updateItem(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $pipeline = $this->resolve($request);
    if (is_wp_error($pipeline)) {
      return $pipeline;
    }

    $id   = $pipeline['id'];
    $data = [];

    if ($request->has_param('name')) {
      $data['name'] = sanitize_text_field($request->get_param('name'));
      if (empty($data['name'])) {
        return new WP_Error('rest_missing_name', __('Pipeline name is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
    }

    if ($request->has_param('slug')) {
      $slug = sanitize_title($request->get_param('slug'));
      if (empty($slug)) {
        return new WP_Error('rest_invalid_slug', __('Slug cannot be empty.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      if ($this->pipelines->slugExists($slug, $id)) {
        return new WP_Error('rest_slug_taken', __('A pipeline with this slug already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
      }
      $data['slug'] = $slug;
    }

    if ($request->has_param('description')) {
      $data['description'] = sanitize_textarea_field($request->get_param('description')) ?: null;
    }

    if ($request->has_param('pipeline_config')) {
      $config = $request->get_param('pipeline_config');
      $data['pipeline_config'] = is_array($config) ? $config : [];
    }

    if ($request->has_param('is_enabled')) {
      $data['is_enabled'] = (bool) $request->get_param('is_enabled');
    }

    if (!$this->pipelines->update($id, $data)) {
      return new WP_Error('rest_update_failed', __('Failed to update pipeline.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return rest_ensure_response($this->pipelines->find($id));
  }

  public function deleteItem(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $pipeline = $this->resolve($request);
    if (is_wp_error($pipeline)) {
      return $pipeline;
    }

    $id = $pipeline['id'];

    if (!$this->pipelines->delete($id)) {
      return new WP_Error('rest_delete_failed', __('Failed to delete pipeline.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return rest_ensure_response(['deleted' => true, 'id' => $id]);
  }

  // ---------------------------------------------------------------------------
  // Test endpoint
  // ---------------------------------------------------------------------------

  /**
   * Test a pipeline against a provided sample payload.
   *
   * POST body:
   *   { "payload": {...}, "query": {...}, "headers": {...} }
   *
   * Returns the resolved DTO output array.
   */
  public function testPipeline(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $pipeline = $this->resolve($request);
    if (is_wp_error($pipeline)) {
      return $pipeline;
    }

    $body    = (array) ($request->get_param('payload') ?? []);
    $query   = (array) ($request->get_param('query')   ?? []);
    $headers = (array) ($request->get_param('headers') ?? []);

    $context = TemplateRenderer::buildContext($body, $query, $headers, [
      'method'        => 'TEST',
      'source_ip'     => '127.0.0.1',
      'endpoint_slug' => 'test',
      'received_at'   => current_time('c'),
    ]);

    $dto = DtoPipelineProcessor::process($pipeline['pipeline_config'], $context);

    return rest_ensure_response([
      'pipeline_id'   => $pipeline['id'],
      'pipeline_slug' => $pipeline['slug'],
      'input'         => $context['received'],
      'dto'           => $dto,
    ]);
  }

  // ---------------------------------------------------------------------------
  // Helper
  // ---------------------------------------------------------------------------

  private function resolve(WP_REST_Request $request): array|WP_Error {
    $id       = (int) $request->get_param('id');
    $pipeline = $this->pipelines->find($id);

    if (!$pipeline) {
      return new WP_Error(
        'rest_pipeline_not_found',
        __('Pipeline not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    return $pipeline;
  }
}
