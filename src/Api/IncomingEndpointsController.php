<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\IncomingEndpointRepository;
use FlowSystems\WebhookActions\Repositories\IncomingPayloadRepository;
use FlowSystems\WebhookActions\Api\AuthHelper;

/**
 * REST controller for managing custom incoming webhook endpoints.
 *
 * Endpoints created here receive external HTTP requests at:
 *   POST /wp-json/fswa/v1/in/{slug}
 *
 * The received payload is stored in wp_fswa_incoming_payloads for later
 * ETL/DTO pipeline processing (separate feature).
 */
class IncomingEndpointsController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'endpoints';

  private IncomingEndpointRepository $endpoints;
  private IncomingPayloadRepository  $payloads;

  /** Valid HMAC algorithms for signature verification */
  private const VALID_ALGORITHMS = ['sha256', 'sha1', 'sha512'];

  /** Valid HTTP status codes we allow as custom response codes */
  private const VALID_RESPONSE_CODES = [200, 201, 202, 204];

  public function __construct() {
    $this->endpoints = new IncomingEndpointRepository();
    $this->payloads  = new IncomingPayloadRepository();
  }

  /**
   * Register REST routes
   */
  public function registerRoutes(): void {
    // Collection: list + create
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getItems'],
        'permission_callback' => [$this, 'getItemsPermissionsCheck'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'createItem'],
        'permission_callback' => [$this, 'createItemPermissionsCheck'],
      ],
    ]);

    // Single endpoint: read + update + delete
    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getItem'],
        'permission_callback' => [$this, 'getItemPermissionsCheck'],
        'args'                => ['id' => ['type' => 'integer']],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [$this, 'updateItem'],
        'permission_callback' => [$this, 'updateItemPermissionsCheck'],
        'args'                => ['id' => ['type' => 'integer']],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'deleteItem'],
        'permission_callback' => [$this, 'deleteItemPermissionsCheck'],
        'args'                => ['id' => ['type' => 'integer']],
      ],
    ]);

    // Toggle enabled status
    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/toggle', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'toggleItem'],
      'permission_callback' => [$this, 'updateItemPermissionsCheck'],
      'args'                => ['id' => ['type' => 'integer']],
    ]);

    // Payloads for an endpoint: list
    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/payloads', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getPayloads'],
        'permission_callback' => [$this, 'getItemPermissionsCheck'],
        'args'                => [
          'id'        => ['type' => 'integer'],
          'status'    => ['type' => 'string', 'enum' => ['received', 'processed', 'failed']],
          'date_from' => ['type' => 'string'],
          'date_to'   => ['type' => 'string'],
          'page'      => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
          'per_page'  => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
        ],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'deletePayloads'],
        'permission_callback' => [$this, 'deleteItemPermissionsCheck'],
        'args'                => ['id' => ['type' => 'integer']],
      ],
    ]);

    // Single payload: delete + mark-processed
    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/payloads/(?P<payload_id>[\d]+)', [
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'deletePayload'],
        'permission_callback' => [$this, 'deleteItemPermissionsCheck'],
        'args'                => [
          'id'         => ['type' => 'integer'],
          'payload_id' => ['type' => 'integer'],
        ],
      ],
    ]);

    // Mark single payload processed
    register_rest_route(
      $this->namespace,
      '/' . $this->rest_base . '/(?P<id>[\d]+)/payloads/(?P<payload_id>[\d]+)/mark-processed',
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'markPayloadProcessed'],
        'permission_callback' => [$this, 'updateItemPermissionsCheck'],
        'args'                => [
          'id'         => ['type' => 'integer'],
          'payload_id' => ['type' => 'integer'],
          'notes'      => ['type' => 'string'],
        ],
      ]
    );

    // Mark single payload failed
    register_rest_route(
      $this->namespace,
      '/' . $this->rest_base . '/(?P<id>[\d]+)/payloads/(?P<payload_id>[\d]+)/mark-failed',
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'markPayloadFailed'],
        'permission_callback' => [$this, 'updateItemPermissionsCheck'],
        'args'                => [
          'id'         => ['type' => 'integer'],
          'payload_id' => ['type' => 'integer'],
          'notes'      => ['type' => 'string'],
        ],
      ]
    );

    // Purge payloads older than N days
    register_rest_route(
      $this->namespace,
      '/' . $this->rest_base . '/(?P<id>[\d]+)/payloads/purge',
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'purgePayloads'],
        'permission_callback' => [$this, 'deleteItemPermissionsCheck'],
        'args'                => [
          'id'               => ['type' => 'integer'],
          'older_than_days'  => [
            'type'    => 'integer',
            'default' => 30,
            'minimum' => 1,
          ],
        ],
      ]
    );

    // Stats for an endpoint
    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/stats', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [$this, 'getStats'],
      'permission_callback' => [$this, 'getItemPermissionsCheck'],
      'args'                => ['id' => ['type' => 'integer']],
    ]);
  }

  // ---------------------------------------------------------------------------
  // Permission callbacks
  // ---------------------------------------------------------------------------

  public function getItemsPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  public function getItemPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  public function createItemPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  public function updateItemPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  public function deleteItemPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  // ---------------------------------------------------------------------------
  // Endpoint CRUD
  // ---------------------------------------------------------------------------

  /**
   * List all endpoints
   */
  public function getItems(WP_REST_Request $request): WP_REST_Response {
    $endpoints = $this->endpoints->getAll();
    $endpoints = array_map(fn($e) => $this->prepareEndpoint($e, $request), $endpoints);

    return rest_ensure_response($endpoints);
  }

  /**
   * Get a single endpoint
   */
  public function getItem(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    return rest_ensure_response($this->prepareEndpoint($endpoint, $request));
  }

  /**
   * Create an endpoint
   */
  public function createItem(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $name = sanitize_text_field($request->get_param('name') ?? '');
    if (empty($name)) {
      return new WP_Error('rest_missing_name', __('Endpoint name is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $slug = sanitize_title($request->get_param('slug') ?? '');
    if (empty($slug)) {
      $slug = sanitize_title($name);
    }

    if (empty($slug)) {
      return new WP_Error('rest_invalid_slug', __('A valid slug could not be derived from the provided name.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    if ($this->endpoints->slugExists($slug)) {
      return new WP_Error('rest_slug_taken', __('An endpoint with this slug already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }

    $algorithm = sanitize_text_field($request->get_param('hmac_algorithm') ?? 'sha256');
    if (!in_array($algorithm, self::VALID_ALGORITHMS, true)) {
      return new WP_Error('rest_invalid_algorithm', __('Invalid HMAC algorithm. Use sha256, sha1, or sha512.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $responseCode = (int) ($request->get_param('response_code') ?? 200);
    if (!in_array($responseCode, self::VALID_RESPONSE_CODES, true)) {
      return new WP_Error('rest_invalid_response_code', __('Invalid response code. Use 200, 201, 202, or 204.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $data = [
      'name'           => $name,
      'slug'           => $slug,
      'description'    => sanitize_textarea_field($request->get_param('description') ?? ''),
      'secret_key'     => sanitize_text_field($request->get_param('secret_key') ?? ''),
      'hmac_algorithm' => $algorithm,
      'hmac_header'    => sanitize_text_field($request->get_param('hmac_header') ?? ''),
      'is_enabled'     => (bool) ($request->get_param('is_enabled') ?? true),
      'response_code'  => $responseCode,
      'response_body'  => sanitize_textarea_field($request->get_param('response_body') ?? ''),
    ];

    // Normalize empty strings to null for nullable fields
    foreach (['description', 'secret_key', 'hmac_header', 'response_body'] as $field) {
      if ($data[$field] === '') {
        $data[$field] = null;
      }
    }

    $id = $this->endpoints->create($data);
    if (!$id) {
      return new WP_Error('rest_create_failed', __('Failed to create endpoint.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $endpoint = $this->endpoints->find($id);

    return rest_ensure_response($this->prepareEndpoint($endpoint, $request));
  }

  /**
   * Update an endpoint
   */
  public function updateItem(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    $id   = $endpoint['id'];
    $data = [];

    if ($request->has_param('name')) {
      $data['name'] = sanitize_text_field($request->get_param('name'));
      if (empty($data['name'])) {
        return new WP_Error('rest_missing_name', __('Endpoint name is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
    }

    if ($request->has_param('slug')) {
      $slug = sanitize_title($request->get_param('slug'));
      if (empty($slug)) {
        return new WP_Error('rest_invalid_slug', __('Slug cannot be empty.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      if ($this->endpoints->slugExists($slug, $id)) {
        return new WP_Error('rest_slug_taken', __('An endpoint with this slug already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
      }
      $data['slug'] = $slug;
    }

    if ($request->has_param('description')) {
      $data['description'] = sanitize_textarea_field($request->get_param('description')) ?: null;
    }

    if ($request->has_param('secret_key')) {
      $data['secret_key'] = sanitize_text_field($request->get_param('secret_key')) ?: null;
    }

    if ($request->has_param('hmac_algorithm')) {
      $algorithm = sanitize_text_field($request->get_param('hmac_algorithm'));
      if (!in_array($algorithm, self::VALID_ALGORITHMS, true)) {
        return new WP_Error('rest_invalid_algorithm', __('Invalid HMAC algorithm.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      $data['hmac_algorithm'] = $algorithm;
    }

    if ($request->has_param('hmac_header')) {
      $data['hmac_header'] = sanitize_text_field($request->get_param('hmac_header')) ?: null;
    }

    if ($request->has_param('is_enabled')) {
      $data['is_enabled'] = (bool) $request->get_param('is_enabled');
    }

    if ($request->has_param('response_code')) {
      $responseCode = (int) $request->get_param('response_code');
      if (!in_array($responseCode, self::VALID_RESPONSE_CODES, true)) {
        return new WP_Error('rest_invalid_response_code', __('Invalid response code.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      $data['response_code'] = $responseCode;
    }

    if ($request->has_param('response_body')) {
      $data['response_body'] = sanitize_textarea_field($request->get_param('response_body')) ?: null;
    }

    if (!$this->endpoints->update($id, $data)) {
      return new WP_Error('rest_update_failed', __('Failed to update endpoint.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return rest_ensure_response($this->prepareEndpoint($this->endpoints->find($id), $request));
  }

  /**
   * Delete an endpoint and all its stored payloads
   */
  public function deleteItem(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    $id = $endpoint['id'];

    // Purge associated payloads first
    $this->payloads->deleteForEndpoint($id);

    if (!$this->endpoints->delete($id)) {
      return new WP_Error('rest_delete_failed', __('Failed to delete endpoint.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return rest_ensure_response(['deleted' => true, 'id' => $id]);
  }

  /**
   * Toggle endpoint enabled/disabled
   */
  public function toggleItem(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    $id        = $endpoint['id'];
    $newStatus = !$endpoint['is_enabled'];

    if (!$this->endpoints->setEnabled($id, $newStatus)) {
      return new WP_Error('rest_toggle_failed', __('Failed to toggle endpoint status.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return rest_ensure_response($this->prepareEndpoint($this->endpoints->find($id), $request));
  }

  // ---------------------------------------------------------------------------
  // Payload management
  // ---------------------------------------------------------------------------

  /**
   * List payloads for an endpoint
   */
  public function getPayloads(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    $filters = [
      'status'    => $request->get_param('status'),
      'date_from' => $request->get_param('date_from'),
      'date_to'   => $request->get_param('date_to'),
      'page'      => $request->get_param('page'),
      'per_page'  => $request->get_param('per_page'),
    ];

    $result = $this->payloads->getForEndpoint($endpoint['id'], $filters);

    return rest_ensure_response($result);
  }

  /**
   * Delete all payloads for an endpoint
   */
  public function deletePayloads(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    $deleted = $this->payloads->deleteForEndpoint($endpoint['id']);

    return rest_ensure_response(['deleted' => $deleted]);
  }

  /**
   * Delete a single payload
   */
  public function deletePayload(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    $payloadId = (int) $request->get_param('payload_id');
    $payload   = $this->payloads->find($payloadId);

    if (!$payload || $payload['endpoint_id'] !== $endpoint['id']) {
      return new WP_Error('rest_payload_not_found', __('Payload not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    if (!$this->payloads->delete($payloadId)) {
      return new WP_Error('rest_delete_failed', __('Failed to delete payload.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return rest_ensure_response(['deleted' => true, 'id' => $payloadId]);
  }

  /**
   * Mark a payload as processed
   */
  public function markPayloadProcessed(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    $payloadId = (int) $request->get_param('payload_id');
    $payload   = $this->payloads->find($payloadId);

    if (!$payload || $payload['endpoint_id'] !== $endpoint['id']) {
      return new WP_Error('rest_payload_not_found', __('Payload not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $notes = sanitize_textarea_field($request->get_param('notes') ?? '');
    $this->payloads->markProcessed($payloadId, $notes ?: null);

    return rest_ensure_response($this->payloads->find($payloadId));
  }

  /**
   * Mark a payload as failed
   */
  public function markPayloadFailed(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    $payloadId = (int) $request->get_param('payload_id');
    $payload   = $this->payloads->find($payloadId);

    if (!$payload || $payload['endpoint_id'] !== $endpoint['id']) {
      return new WP_Error('rest_payload_not_found', __('Payload not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $notes = sanitize_textarea_field($request->get_param('notes') ?? '');
    $this->payloads->markFailed($payloadId, $notes ?: null);

    return rest_ensure_response($this->payloads->find($payloadId));
  }

  /**
   * Delete payloads older than N days for an endpoint
   */
  public function purgePayloads(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    $days    = max(1, (int) $request->get_param('older_than_days'));
    $deleted = $this->payloads->deleteOlderThan($endpoint['id'], $days);

    return rest_ensure_response(['deleted' => $deleted, 'older_than_days' => $days]);
  }

  /**
   * Get payload stats for an endpoint
   */
  public function getStats(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $endpoint = $this->resolveEndpoint($request);
    if (is_wp_error($endpoint)) {
      return $endpoint;
    }

    return rest_ensure_response($this->payloads->getStats($endpoint['id']));
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Resolve endpoint from request 'id' param
   *
   * @return array<string, mixed>|WP_Error
   */
  private function resolveEndpoint(WP_REST_Request $request): array|WP_Error {
    $id       = (int) $request->get_param('id');
    $endpoint = $this->endpoints->find($id);

    if (!$endpoint) {
      return new WP_Error(
        'rest_endpoint_not_found',
        __('Endpoint not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    return $endpoint;
  }

  /**
   * Prepare endpoint for API response.
   * Hides the secret_key for non-full-scope callers.
   *
   * @param array<string, mixed> $endpoint
   * @param WP_REST_Request $request
   * @return array<string, mixed>
   */
  private function prepareEndpoint(array $endpoint, WP_REST_Request $request): array {
    if (!AuthHelper::requestHasScope($request, AuthHelper::SCOPE_FULL)) {
      unset($endpoint['secret_key']);
    }

    // Always expose the public receiver URL
    $endpoint['receiver_url'] = rest_url('fswa/v1/in/' . $endpoint['slug']);

    return $endpoint;
  }
}
