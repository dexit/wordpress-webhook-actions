<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Api\AuthHelper;
use FlowSystems\WebhookActions\Repositories\SnippetsRepository;
use FlowSystems\WebhookActions\Repositories\TriggerSnippetsRepository;
use FlowSystems\WebhookActions\Services\SnippetExecutor;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\GluePermissions;
use FlowSystems\WebhookActions\Services\PayloadTransformer;
use WP_Error;

/**
 * Code Glue abilities for the AI agent and the WP Abilities/MCP surface.
 *
 * Bound from {@see AbilityCatalog::build()} alongside ReadAbilities /
 * WriteAbilities / TestAbilities, so they appear in the agent's system-prompt
 * catalog, in plan execution and in MCP exactly like the built-in abilities.
 *
 * Two different risks, gated differently. WRITING a snippet is running PHP on
 * the site, so every write ability clears {@see GluePermissions} — the same
 * `edit_plugins` / token opt-in check the REST routes use, enforced here
 * because ability callbacks are also reachable from MCP and from the plan
 * executor, neither of which passes through SnippetsController. ASSIGNING one
 * to a LIVE webhook is the moment that PHP starts running on real traffic, so
 * that additionally pauses for a user confirmation; creating, editing and
 * previewing stay frictionless so the agent can iterate code → preview → fix.
 */
class GlueAbilities {
  /** Max bytes of preview output/result returned to the agent. */
  private const PREVIEW_LIMIT = 4096;

  /**
   * The Code Glue ability definitions, keyed by short name, with callbacks
   * bound to this handler.
   *
   * @return array<string, array<string, mixed>>
   */
  public function definitions(): array {
    $definitions = [];

    $definitions['list_snippets'] = [
      'label'        => __('List Code Glue snippets', 'flowsystems-webhook-actions'),
      'description'  => __('List saved Code Glue PHP snippets (id, name, tags — use get_snippet for the code). Snippets transform the payload before dispatch (pre) or run side effects after the response (post) once assigned to a webhook+trigger.', 'flowsystems-webhook-actions'),
      'category'     => 'webhook-actions',
      'scope'        => AuthHelper::SCOPE_READ,
      'input_schema' => [
        'type'       => 'object',
        'properties' => ['search' => ['type' => 'string', 'description' => 'Filter by name or code substring.']],
      ],
      'callback'     => [$this, 'listSnippets'],
    ];

    $definitions['get_snippet'] = [
      'label'        => __('Get a Code Glue snippet', 'flowsystems-webhook-actions'),
      'description'  => __('Get a single Code Glue snippet by id, including its full PHP code.', 'flowsystems-webhook-actions'),
      'category'     => 'webhook-actions',
      'scope'        => AuthHelper::SCOPE_READ,
      'input_schema' => [
        'type'       => 'object',
        'properties' => ['id' => ['type' => 'integer', 'description' => 'Snippet id']],
        'required'   => ['id'],
      ],
      'callback'     => [$this, 'getSnippet'],
    ];

    $definitions['create_snippet'] = [
      'label'        => __('Create a Code Glue snippet', 'flowsystems-webhook-actions'),
      'description'  => __('Create a Code Glue PHP snippet (inert until assigned to a webhook). Write plain PHP without a <?php tag. PRE-dispatch snippets receive $payload — the payload AFTER the field mapping has already run — and MUST end with `return $payload;`, returning the FULL modified array (a non-array return leaves the payload unchanged). CRITICAL: $args is just $payload["args"], so it is an EMPTY ARRAY unless the mapping preserved an "args" key; with includeUnmapped:false only the mapping\'s target names exist and $args[0] is silently missing, so any `if ($postId)` guard around it never runs and the snippet no-ops. Read values from the mapped target names ($payload["post_id"]) — and if you need a source field the mapping drops, add it to set_mapping first (e.g. source "args.0" → target "post_id") and unset it before returning if it must not be sent. The shorthand {{ $args.0.total }} expands to $args[0]["total"] and is only safe when "args" survives the mapping. POST-dispatch snippets receive $payload (as sent), $originalPayload (pre-mapping), $responseCode and $responseBody, and run for side effects only (return value ignored). Always test with preview_snippet (it feeds the real mapped payload) before assign_snippet.', 'flowsystems-webhook-actions'),
      'category'     => 'webhook-actions',
      'scope'        => AuthHelper::SCOPE_FULL,
      'input_schema' => [
        'type'       => 'object',
        'properties' => [
          'name' => ['type' => 'string', 'description' => 'Short descriptive name.'],
          'code' => ['type' => 'string', 'description' => 'PHP code, no <?php tag.'],
          'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required'   => ['name', 'code'],
      ],
      'callback'     => [$this, 'createSnippet'],
    ];

    $definitions['update_snippet'] = [
      'label'        => __('Update a Code Glue snippet', 'flowsystems-webhook-actions'),
      'description'  => __('Update an existing Code Glue snippet\'s name, code or tags. If the snippet is already assigned and enabled on a webhook, the new code runs on the next dispatch — re-test with preview_snippet after editing.', 'flowsystems-webhook-actions'),
      'category'     => 'webhook-actions',
      'scope'        => AuthHelper::SCOPE_FULL,
      'input_schema' => [
        'type'       => 'object',
        'properties' => [
          'id'   => ['type' => 'integer'],
          'name' => ['type' => 'string'],
          'code' => ['type' => 'string'],
          'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required'   => ['id'],
      ],
      'callback'     => [$this, 'updateSnippet'],
    ];

    $definitions['preview_snippet'] = [
      'label'        => __('Preview (test-run) a Code Glue snippet', 'flowsystems-webhook-actions'),
      'description'  => __('Test-run a snippet (by snippet_id, or raw code) against a real captured payload WITHOUT dispatching anything. Pass webhook_id+trigger to use that webhook\'s captured example WITH ITS STORED FIELD MAPPING APPLIED — the exact array the snippet gets at dispatch — or provide an explicit payload to test a shape yourself. Returns input_payload (what the snippet received; check that the keys your code reads are actually there), the resulting payload, any PHP error and printed output. If result is identical to input_payload, your code matched nothing and no-oped — fix it before assigning. Always preview before assigning.', 'flowsystems-webhook-actions'),
      'category'     => 'webhook-actions',
      'scope'        => AuthHelper::SCOPE_FULL,
      'input_schema' => [
        'type'       => 'object',
        'properties' => [
          'snippet_id' => ['type' => 'integer', 'description' => 'Saved snippet to run; omit when passing code.'],
          'code'       => ['type' => 'string', 'description' => 'Raw PHP to run instead of a saved snippet.'],
          'webhook_id' => ['type' => 'integer', 'description' => 'With trigger: use this webhook\'s captured example payload.'],
          'trigger'    => ['type' => 'string'],
          'payload'    => ['type' => 'object', 'description' => 'Explicit test payload; overrides the captured example.'],
          'mode'       => ['type' => 'string', 'enum' => ['pre', 'post'], 'default' => 'pre'],
        ],
      ],
      'callback'     => [$this, 'previewSnippet'],
    ];

    $definitions['assign_snippet'] = [
      'label'            => __('Assign a Code Glue snippet to a webhook', 'flowsystems-webhook-actions'),
      'description'      => __('Attach a snippet to a webhook+trigger and enable it: stage "pre" transforms the payload before dispatch, stage "post" runs side effects after the response. Snippet errors never break a delivery (the payload falls back unmodified and the error is logged). Attaching to a LIVE webhook requires confirmation; on a still-disabled webhook being built it runs without one. Pass snippet_id 0 to unassign, or enabled false to keep it assigned but paused.', 'flowsystems-webhook-actions'),
      'category'         => 'webhook-actions',
      'scope'            => AuthHelper::SCOPE_FULL,
      // Frictionless while the webhook is still disabled (the build under review);
      // a LIVE webhook means the PHP starts running on real traffic immediately —
      // that is the moment worth an explicit pause.
      'requires_confirm' => 'when_live',
      'confirm_notice'   => __('This webhook is LIVE — the snippet\'s PHP will run on every real dispatch from now on. A snippet error never breaks the delivery (the payload is sent unmodified and the error is logged). Confirm to attach it.', 'flowsystems-webhook-actions'),
      'input_schema'     => [
        'type'       => 'object',
        'properties' => [
          'webhook_id' => ['type' => 'integer'],
          'trigger'    => ['type' => 'string'],
          'stage'      => ['type' => 'string', 'enum' => ['pre', 'post'], 'description' => 'pre = transform payload before dispatch; post = run after the response.'],
          'snippet_id' => ['type' => 'integer', 'description' => '0 to unassign.'],
          'enabled'    => ['type' => 'boolean', 'default' => true],
        ],
        'required'   => ['webhook_id', 'trigger', 'stage', 'snippet_id'],
      ],
      'callback'         => [$this, 'assignSnippet'],
    ];

    $definitions['delete_snippet'] = [
      'label'            => __('Delete a Code Glue snippet', 'flowsystems-webhook-actions'),
      'description'      => __('Permanently delete a Code Glue snippet. Requires confirmation. Webhooks referencing it fall back to no snippet.', 'flowsystems-webhook-actions'),
      'category'         => 'webhook-actions',
      'scope'            => AuthHelper::SCOPE_FULL,
      'requires_confirm' => 'always',
      'input_schema'     => [
        'type'       => 'object',
        'properties' => ['id' => ['type' => 'integer']],
        'required'   => ['id'],
      ],
      'callback'         => [$this, 'deleteSnippet'],
    ];

    return $definitions;
  }

  // ===================================================================
  // Implementations
  // ===================================================================

  /**
   * Every write ability starts here. The ability layer is reachable from the
   * plan executor and from MCP as well as from SnippetsController, and only the
   * controller sits behind a REST permission callback — so the capability check
   * belongs on the operation itself, not on one of its doors.
   *
   * @return WP_Error|null The refusal to return, or null when allowed.
   */
  private function denyWrite(): ?WP_Error {
    $allowed = (new GluePermissions())->canWrite();

    return $allowed instanceof WP_Error ? $allowed : null;
  }

  public function listSnippets(array $input): array {
    $rows = (new SnippetsRepository())->findAll((string) ($input['search'] ?? ''));
    return [
      'snippets' => array_map(static fn(array $s) => [
        'id'         => (int) $s['id'],
        'name'       => (string) $s['name'],
        'tags'       => (array) ($s['tags'] ?? []),
        'updated_at' => (string) ($s['updated_at'] ?? ''),
      ], $rows),
    ];
  }

  public function getSnippet(array $input): array|WP_Error {
    $snippet = (new SnippetsRepository())->find((int) ($input['id'] ?? 0));
    if (!$snippet) {
      return new WP_Error('fswa_not_found', __('Snippet not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }
    return ['snippet' => $snippet];
  }

  /**
   * Snippets are eval'd as plain PHP, but models regularly wrap the code in
   * <?php … ?> tags anyway — strip them instead of failing at run time.
   */
  private function normalizeSnippetCode(string $code): string {
    $code = preg_replace('/^\s*<\?(?:php)?\s*/i', '', $code);
    return preg_replace('/\?>\s*$/', '', $code);
  }

  public function createSnippet(array $input): array|WP_Error {
    if ($denied = $this->denyWrite()) {
      return $denied;
    }
    $name = trim((string) ($input['name'] ?? ''));
    $code = $this->normalizeSnippetCode((string) ($input['code'] ?? ''));
    if ($name === '' || trim($code) === '') {
      return new WP_Error('fswa_invalid', __('name and code are required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $repo = new SnippetsRepository();
    $id   = $repo->upsert([
      'name' => $name,
      'code' => $code,
      'tags' => array_map('sanitize_text_field', (array) ($input['tags'] ?? [])),
    ]);
    $snippet = $id ? $repo->find($id) : null;
    if (!$snippet) {
      return new WP_Error('fswa_snippet_failed', __('Failed to create snippet.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['snippet' => $snippet];
  }

  public function updateSnippet(array $input): array|WP_Error {
    if ($denied = $this->denyWrite()) {
      return $denied;
    }
    $id   = (int) ($input['id'] ?? 0);
    $repo = new SnippetsRepository();
    if ($id <= 0 || !$repo->find($id)) {
      return new WP_Error('fswa_not_found', __('Snippet not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $data = ['id' => $id];
    foreach (['name', 'code'] as $key) {
      if (array_key_exists($key, $input)) {
        $data[$key] = $key === 'code' ? $this->normalizeSnippetCode((string) $input[$key]) : (string) $input[$key];
      }
    }
    if (array_key_exists('tags', $input)) {
      $data['tags'] = array_map('sanitize_text_field', (array) $input['tags']);
    }
    $repo->upsert($data);
    return ['snippet' => $repo->find($id)];
  }

  public function previewSnippet(array $input): array|WP_Error {
    if ($denied = $this->denyWrite()) {
      return $denied;
    }
    $code = $this->normalizeSnippetCode((string) ($input['code'] ?? ''));
    if ($code === '' && !empty($input['snippet_id'])) {
      $snippet = (new SnippetsRepository())->find((int) $input['snippet_id']);
      if (!$snippet) {
        return new WP_Error('fswa_not_found', __('Snippet not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
      }
      $code = (string) $snippet['code'];
    }
    if (trim($code) === '') {
      return new WP_Error('fswa_invalid', __('Provide code or a snippet_id to preview.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $mode = ($input['mode'] ?? 'pre') === 'post' ? 'post' : 'pre';

    $payload = is_array($input['payload'] ?? null) ? $input['payload'] : null;
    $source  = 'provided';
    // Pre-mapping payload, kept for post-mode's $originalPayload.
    $rawPayload = $payload;
    if ($payload === null && !empty($input['webhook_id']) && !empty($input['trigger'])) {
      $webhookId = (int) $input['webhook_id'];
      $trigger   = (string) $input['trigger'];
      $resolved  = (new SchemaRepository())->resolveExample($webhookId, $trigger);
      if (!empty($resolved['example'])) {
        $payload    = $resolved['example'];
        $rawPayload = $payload;
        $source     = 'captured (' . ($resolved['source'] ?? 'own') . ')';

        // A snippet runs on the POST-mapping payload: the Dispatcher applies
        // PayloadTransformer::transform() before the fswa_webhook_payload filter.
        // Previewing against the raw capture is the bug that lets a snippet
        // reading $args[0] look correct here and silently no-op live — with
        // includeUnmapped:false the mapped payload has no "args" key at all.
        // applyStoredMapping() is side-effect free (it never captures examples).
        $mapped = (new PayloadTransformer())->applyStoredMapping($webhookId, $trigger, $payload);
        if ($mapped['mapping_applied']) {
          $payload = $mapped['payload'];
          $source .= ' + field mapping applied (this is what the snippet receives at dispatch)';
        }
      }
    }
    if ($payload === null) {
      $payload    = ['args' => []];
      $rawPayload = $payload;
      $source     = 'empty';
    }
    $result = (new SnippetExecutor())->execute($code, $payload, $mode, $mode === 'post' ? [
      'originalPayload' => $rawPayload,
      'responseCode'    => 200,
      'responseBody'    => '',
    ] : []);

    return [
      'payload_source' => $source,
      // The exact array the snippet received. When a mapping is configured this
      // is the mapped payload, so the agent can see which keys actually exist
      // (and that $args is gone) instead of assuming the captured shape.
      'input_payload'  => $this->summarizeInputPayload($payload),
      'result'         => $this->truncateForAgent($result['result']),
      'error'          => $result['error'],
      // Ran clean but against an unexpected payload shape (e.g. $args stripped by
      // the mapping) — fix this before assign_snippet, it means the snippet no-oped.
      'notice'         => $result['notice'] ?? null,
      'output'         => mb_substr($result['output'], 0, self::PREVIEW_LIMIT),
    ];
  }

  public function assignSnippet(array $input): array|WP_Error {
    if ($denied = $this->denyWrite()) {
      return $denied;
    }
    $webhookId = (int) ($input['webhook_id'] ?? 0);
    $trigger   = (string) ($input['trigger'] ?? '');
    // Models routinely send "pre_dispatch"/"post-dispatch" despite the enum — accept them.
    $stage = str_replace(['_dispatch', '-dispatch', 'dispatch'], '', strtolower(trim((string) ($input['stage'] ?? ''))));
    if ($webhookId <= 0 || $trigger === '' || !in_array($stage, ['pre', 'post'], true) || !array_key_exists('snippet_id', $input)) {
      return new WP_Error('fswa_invalid', __('webhook_id, trigger, stage (pre|post) and snippet_id are required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    if (!(new WebhookRepository())->find($webhookId)) {
      return new WP_Error('fswa_not_found', __('Webhook not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $snippetId = (int) $input['snippet_id'];
    if ($snippetId > 0 && !(new SnippetsRepository())->find($snippetId)) {
      return new WP_Error('fswa_not_found', __('Snippet not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $enabled = $snippetId > 0 && (bool) ($input['enabled'] ?? true);
    $repo    = new TriggerSnippetsRepository();
    $repo->upsert($webhookId, $trigger, $stage === 'pre'
      ? ['pre_snippet_id' => $snippetId, 'pre_enabled' => $enabled]
      : ['post_snippet_id' => $snippetId, 'post_enabled' => $enabled]);

    return ['assignment' => $repo->findByWebhookAndTrigger($webhookId, $trigger)];
  }

  public function deleteSnippet(array $input): array|WP_Error {
    if ($denied = $this->denyWrite()) {
      return $denied;
    }
    $id   = (int) ($input['id'] ?? 0);
    $repo = new SnippetsRepository();
    if ($id <= 0 || !$repo->find($id)) {
      return new WP_Error('fswa_not_found', __('Snippet not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }
    $repo->delete($id);
    return ['deleted' => true];
  }

  /**
   * Cap a preview result so a snippet that returns a huge structure doesn't
   * bloat the execution log / MCP response.
   */
  /**
   * The payload the snippet received, shaped for the agent. This field exists so
   * the agent can confirm WHICH KEYS exist before assigning a snippet, so on an
   * oversize payload degrade to the key list rather than truncateForAgent's
   * clipped JSON blob — a cut-off string answers the one question this is for
   * ("is `args` there?") worse than a complete list of top-level keys does.
   *
   * @param array<string, mixed> $payload
   */
  private function summarizeInputPayload(array $payload): mixed {
    $json = wp_json_encode($payload);
    if ($json !== false && strlen($json) <= self::PREVIEW_LIMIT) {
      return $payload;
    }

    return [
      '_keys_only' => true,
      '_note'      => 'Payload too large to inline; these are its top-level keys.',
      'keys'       => array_keys($payload),
    ];
  }

  private function truncateForAgent(mixed $value): mixed {
    $json = wp_json_encode($value);
    if ($json !== false && strlen($json) > self::PREVIEW_LIMIT) {
      return ['_truncated' => true, 'preview' => mb_substr($json, 0, self::PREVIEW_LIMIT)];
    }
    return $value;
  }
}
