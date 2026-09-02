<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Api\AuthHelper;
use FlowSystems\WebhookActions\Repositories\SnippetsRepository;
use FlowSystems\WebhookActions\Repositories\TriggerSnippetsRepository;
use FlowSystems\WebhookActions\Services\SnippetExecutor;
use FlowSystems\WebhookActions\Services\ActivityLogService;
use FlowSystems\WebhookActions\Services\GluePermissions;

/**
 * REST surface for Code Glue snippets.
 *
 * The routes keep their `pro/` prefix now that Code Glue has moved into the
 * free plugin: they are the paths every shipped admin build, saved automation
 * and MCP client already calls, and renaming them would break all of those for
 * a naming preference.
 *
 * Reads are ordinary `read`-scope. Writes run PHP on this site, so on top of
 * the scope check they go through {@see GluePermissions} — `edit_plugins` for a
 * user session, an explicit opt-in for an API token.
 */
class SnippetsController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'pro/snippets';

  private SnippetsRepository $snippets;
  private TriggerSnippetsRepository $triggerSnippets;
  private SnippetExecutor $executor;
  private ActivityLogService $activityLog;
  private GluePermissions $permissions;

  public function __construct() {
    $this->snippets        = new SnippetsRepository();
    $this->triggerSnippets = new TriggerSnippetsRepository();
    $this->executor        = new SnippetExecutor();
    $this->activityLog     = new ActivityLogService();
    $this->permissions     = new GluePermissions();
  }

  public function registerRoutes(): void {
    // Preview — must be registered before /{id} to avoid conflict
    register_rest_route($this->namespace, '/' . $this->rest_base . '/preview', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'previewSnippet'],
        'permission_callback' => [$this, 'writePermissionsCheck'],
        'args' => [
          'code'    => ['type' => 'string', 'required' => true],
          'payload' => ['type' => 'object', 'required' => true],
          'mode'    => ['type' => 'string', 'enum' => ['pre', 'post'], 'default' => 'pre'],
          'postContext' => ['type' => 'object'],
        ],
      ],
    ]);

    // List + create
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'listSnippets'],
        'permission_callback' => [$this, 'readPermissionsCheck'],
        'args' => [
          'search' => ['type' => 'string', 'default' => ''],
          'tags'   => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
        ],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'createSnippet'],
        'permission_callback' => [$this, 'writePermissionsCheck'],
        'args' => [
          'name' => ['type' => 'string', 'required' => true],
          'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
          'code' => ['type' => 'string', 'required' => true],
        ],
      ],
    ]);

    // Single snippet
    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getSnippet'],
        'permission_callback' => [$this, 'readPermissionsCheck'],
        'args' => ['id' => ['type' => 'integer', 'required' => true]],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [$this, 'updateSnippet'],
        'permission_callback' => [$this, 'writePermissionsCheck'],
        'args' => [
          'id'   => ['type' => 'integer', 'required' => true],
          'name' => ['type' => 'string'],
          'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
          'code' => ['type' => 'string'],
        ],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'deleteSnippet'],
        'permission_callback' => [$this, 'writePermissionsCheck'],
        'args' => ['id' => ['type' => 'integer', 'required' => true]],
      ],
    ]);

    // Trigger-snippet assignments
    register_rest_route($this->namespace, '/pro/trigger-snippets/(?P<webhook_id>[\d]+)/trigger/(?P<trigger>[a-zA-Z0-9_%\-\.]+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getTriggerSnippet'],
        'permission_callback' => [$this, 'readPermissionsCheck'],
        'args' => [
          'webhook_id' => ['type' => 'integer', 'required' => true],
          'trigger'    => ['type' => 'string', 'required' => true],
        ],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'saveTriggerSnippet'],
        'permission_callback' => [$this, 'writePermissionsCheck'],
        'args' => [
          'webhook_id'      => ['type' => 'integer', 'required' => true],
          'trigger'         => ['type' => 'string', 'required' => true],
          'pre_snippet_id'  => ['type' => 'integer'],
          'pre_enabled'     => ['type' => 'boolean'],
          'post_snippet_id' => ['type' => 'integer'],
          'post_enabled'    => ['type' => 'boolean'],
        ],
      ],
    ]);
  }

  public function readPermissionsCheck(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  /**
   * Writing a snippet — creating, editing, deleting, assigning it to a live
   * webhook, or preview-running it — means arbitrary PHP executes on this site.
   * Scope alone is not enough for that, so every write also clears
   * {@see GluePermissions}.
   */
  public function writePermissionsCheck(WP_REST_Request $request): bool|WP_Error {
    $scoped = AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
    if ($scoped !== true) {
      return $scoped;
    }

    return $this->permissions->canWrite();
  }

  public function listSnippets(WP_REST_Request $request): WP_REST_Response {
    $search = (string) $request->get_param('search');
    $tags   = (array) ($request->get_param('tags') ?? []);
    return rest_ensure_response($this->snippets->findAll($search, $tags));
  }

  public function createSnippet(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id = $this->snippets->upsert([
      'name' => $request->get_param('name'),
      'tags' => $request->get_param('tags') ?? [],
      'code' => $request->get_param('code'),
    ]);

    $snippet = $this->snippets->find($id);
    if (!$snippet) {
      return new WP_Error('rest_snippet_create_failed', __('Failed to create snippet.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $this->activityLog->log('snippet.created', 'snippet', $id, $snippet['name'] ?? null, [
      'new' => [
        'name' => $snippet['name'] ?? null,
        'tags' => $snippet['tags'] ?? [],
        'code' => $snippet['code'] ?? null,
      ],
    ]);

    return rest_ensure_response($snippet);
  }

  public function getSnippet(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $snippet = $this->snippets->find((int) $request->get_param('id'));
    if (!$snippet) {
      return new WP_Error('rest_snippet_not_found', __('Snippet not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }
    return rest_ensure_response($snippet);
  }

  public function updateSnippet(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id = (int) $request->get_param('id');
    $existing = $this->snippets->find($id);
    if (!$existing) {
      return new WP_Error('rest_snippet_not_found', __('Snippet not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $data = ['id' => $id];
    if ($request->has_param('name')) {
      $data['name'] = $request->get_param('name');
    }
    if ($request->has_param('tags')) {
      $data['tags'] = $request->get_param('tags');
    }
    if ($request->has_param('code')) {
      $data['code'] = $request->get_param('code');
    }

    $changedKeys = array_diff_key($data, ['id' => true]);
    $oldValues   = array_intersect_key($existing, $changedKeys);

    $this->snippets->upsert($data);

    $updated = $this->snippets->find($id);

    $this->activityLog->log('snippet.updated', 'snippet', $id, $updated['name'] ?? null, [
      'old' => $oldValues,
      'new' => $changedKeys,
    ]);

    return rest_ensure_response($updated);
  }

  public function deleteSnippet(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id = (int) $request->get_param('id');
    $snippet = $this->snippets->find($id);
    if (!$snippet) {
      return new WP_Error('rest_snippet_not_found', __('Snippet not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }
    $this->snippets->delete($id);

    $this->activityLog->log('snippet.deleted', 'snippet', $id, $snippet['name'] ?? null, [
      'old' => [
        'name' => $snippet['name'] ?? null,
        'tags' => $snippet['tags'] ?? [],
        'code' => $snippet['code'] ?? null,
      ],
    ]);

    return rest_ensure_response(['deleted' => true]);
  }

  public function previewSnippet(WP_REST_Request $request): WP_REST_Response {
    $code        = (string) $request->get_param('code');
    $payload     = (array) $request->get_param('payload');
    $mode        = (string) ($request->get_param('mode') ?? 'pre');
    $postContext = (array) ($request->get_param('postContext') ?? []);

    $result = $this->executor->execute($code, $payload, $mode, $postContext);

    return rest_ensure_response([
      'result' => $result['result'],
      'error'  => $result['error'],
      'notice' => $result['notice'] ?? null,
      'output' => $result['output'],
    ]);
  }

  public function getTriggerSnippet(WP_REST_Request $request): WP_REST_Response {
    $webhookId = (int) $request->get_param('webhook_id');
    $trigger   = rawurldecode((string) $request->get_param('trigger'));

    $assignment = $this->triggerSnippets->findByWebhookAndTrigger($webhookId, $trigger);

    if (!$assignment) {
      return rest_ensure_response([
        'webhook_id'      => $webhookId,
        'trigger_name'    => $trigger,
        'pre_snippet_id'  => null,
        'pre_enabled'     => false,
        'pre_snippet'     => null,
        'post_snippet_id' => null,
        'post_enabled'    => false,
        'post_snippet'    => null,
      ]);
    }

    $assignment['pre_snippet']  = $assignment['pre_snippet_id']  ? $this->snippets->find($assignment['pre_snippet_id'])  : null;
    $assignment['post_snippet'] = $assignment['post_snippet_id'] ? $this->snippets->find($assignment['post_snippet_id']) : null;

    return rest_ensure_response($assignment);
  }

  public function saveTriggerSnippet(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $webhookId = (int) $request->get_param('webhook_id');
    $trigger   = rawurldecode((string) $request->get_param('trigger'));

    $data = [];
    if ($request->has_param('pre_snippet_id')) {
      $data['pre_snippet_id'] = $request->get_param('pre_snippet_id');
    }
    if ($request->has_param('pre_enabled')) {
      $data['pre_enabled'] = $request->get_param('pre_enabled');
    }
    if ($request->has_param('post_snippet_id')) {
      $data['post_snippet_id'] = $request->get_param('post_snippet_id');
    }
    if ($request->has_param('post_enabled')) {
      $data['post_enabled'] = $request->get_param('post_enabled');
    }

    if (empty($data)) {
      return new WP_Error('rest_no_data', __('No data to update.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $existing   = $this->triggerSnippets->findByWebhookAndTrigger($webhookId, $trigger);
    $oldValues  = $existing
      ? array_intersect_key($existing, array_flip(['pre_snippet_id', 'pre_enabled', 'post_snippet_id', 'post_enabled']))
      : ['pre_snippet_id' => null, 'pre_enabled' => false, 'post_snippet_id' => null, 'post_enabled' => false];

    $this->triggerSnippets->upsert($webhookId, $trigger, $data);

    $assignment = $this->triggerSnippets->findByWebhookAndTrigger($webhookId, $trigger) ?? [
      'webhook_id' => $webhookId, 'trigger_name' => $trigger,
      'pre_snippet_id' => null, 'pre_enabled' => false,
      'post_snippet_id' => null, 'post_enabled' => false,
    ];

    $assignment['pre_snippet']  = !empty($assignment['pre_snippet_id'])  ? $this->snippets->find($assignment['pre_snippet_id'])  : null;
    $assignment['post_snippet'] = !empty($assignment['post_snippet_id']) ? $this->snippets->find($assignment['post_snippet_id']) : null;

    $newValues = array_intersect_key($assignment, array_flip(['pre_snippet_id', 'pre_enabled', 'post_snippet_id', 'post_enabled']));

    $this->activityLog->log('snippet.trigger_saved', 'webhook', $webhookId, null, [
      'trigger' => $trigger,
      'old'     => $oldValues,
      'new'     => $newValues,
    ]);

    return rest_ensure_response($assignment);
  }
}
