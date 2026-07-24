<?php

namespace FlowSystems\WebhookActions\Services\Export;

defined('ABSPATH') || exit;

use WP_Error;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\ChainRepository;
use FlowSystems\WebhookActions\Repositories\ChainLinkRepository;

/**
 * Restores a portable build document produced by {@see BuildExporter} onto this
 * site. Auth is never carried as a secret: each referenced credential must be
 * resolved by the caller to a local vault credential id (existing or freshly
 * created via CredentialsController) and passed in the credential map.
 *
 * Pro restores Code Glue per trigger by listening on `fswa_import_trigger`.
 */
class BuildImporter {
  private WebhookRepository $webhooks;
  private SchemaRepository $schemas;
  private ChainRepository $chains;
  private ChainLinkRepository $links;

  public function __construct() {
    $this->webhooks = new WebhookRepository();
    $this->schemas  = new SchemaRepository();
    $this->chains   = new ChainRepository();
    $this->links    = new ChainLinkRepository();
  }

  /**
   * Validate a document and report what the UI must resolve before import:
   * the credentials that need a local mapping, and any UUID collisions.
   *
   * @return array|WP_Error
   */
  public function analyze(array $document) {
    $error = $this->validate($document);
    if ($error instanceof WP_Error) {
      return $error;
    }

    $needed = [];
    foreach ($document['webhooks'] as $webhook) {
      $auth = $webhook['auth'] ?? [];
      $ref  = $auth['credential_ref'] ?? null;
      $needsAuth = ($auth['mode'] ?? 'none') === 'vault' || !empty($auth['manual_present']);
      if (!$needsAuth) {
        continue;
      }
      // A vault ref points at a credentials[] entry; a manual header has no ref
      // but still needs a credential chosen, keyed by the webhook uuid.
      $key = $ref ?? ('manual:' . ($webhook['uuid'] ?? $webhook['name'] ?? ''));
      if (isset($needed[$key])) {
        continue;
      }
      $needed[$key] = $this->credentialMeta($document, $ref, $webhook);
    }

    $collisions = [];
    foreach ($document['webhooks'] as $webhook) {
      $uuid = $webhook['uuid'] ?? null;
      if ($uuid && $this->webhooks->findByUuid($uuid) !== null) {
        $collisions[] = $uuid;
      }
    }

    return [
      'credentials_needed' => array_values($needed),
      'collisions'         => $collisions,
      'counts'             => [
        'webhooks' => count($document['webhooks']),
        'chains'   => count($document['chains'] ?? []),
      ],
    ];
  }

  /**
   * Import the document.
   *
   * @param array               $document      Portable build document.
   * @param array<string, int>  $credentialMap ref/key => local credential id (0 = no auth).
   * @param array               $options       ['on_collision' => 'copy'|'skip'].
   * @return array|WP_Error Summary of what was created.
   */
  public function import(array $document, array $credentialMap, array $options = []) {
    $error = $this->validate($document);
    if ($error instanceof WP_Error) {
      return $error;
    }

    $onCollision = ($options['on_collision'] ?? 'copy') === 'skip' ? 'skip' : 'copy';

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query('START TRANSACTION');

    try {
      $uuidToNewId = [];
      $created     = ['webhooks' => 0, 'chains' => 0, 'links' => 0, 'skipped' => 0];

      foreach ($document['webhooks'] as $webhook) {
        $newId = $this->importWebhook($webhook, $credentialMap, $onCollision, $created);
        if ($newId > 0 && !empty($webhook['uuid'])) {
          $uuidToNewId[$webhook['uuid']] = $newId;
        }
      }

      foreach ($document['chains'] ?? [] as $chain) {
        $this->importChain($chain, $uuidToNewId, $created);
      }

      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $wpdb->query('COMMIT');

      return $created;
    } catch (\Throwable $e) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $wpdb->query('ROLLBACK');
      return new WP_Error('fswa_import_failed', $e->getMessage(), ['status' => 500]);
    }
  }

  /**
   * @param array<string, int> $credentialMap
   * @param array              $created Mutated in place.
   */
  private function importWebhook(array $webhook, array $credentialMap, string $onCollision, array &$created): int {
    $uuid      = $webhook['uuid'] ?? null;
    $collision = $uuid !== null && $this->webhooks->findByUuid($uuid) !== null;

    if ($collision && $onCollision === 'skip') {
      $created['skipped']++;
      return 0;
    }

    $auth = $webhook['auth'] ?? [];
    $ref  = $auth['credential_ref'] ?? ('manual:' . ($uuid ?? $webhook['name'] ?? ''));
    $credentialId = (int) ($credentialMap[$ref] ?? ($credentialMap[$auth['credential_ref'] ?? ''] ?? 0));

    $data = [
      'name'           => $webhook['name'] ?? '',
      'description'    => $webhook['description'] ?? null,
      'endpoint_url'   => $webhook['endpoint_url'] ?? '',
      'http_method'    => $webhook['http_method'] ?? 'POST',
      'custom_headers' => $webhook['custom_headers'] ?? [],
      'url_params'     => $webhook['url_params'] ?? [],
      // Secrets are never imported: manual auth becomes a chosen vault credential.
      'auth_header'        => null,
      'auth_credential_id' => $credentialId ?: null,
      'is_enabled'         => (bool) ($webhook['is_enabled'] ?? true),
      'is_synchronous'     => (bool) ($webhook['is_synchronous'] ?? false),
      'triggers'           => array_map(
        static fn(array $t): string => (string) ($t['name'] ?? ''),
        $webhook['triggers'] ?? []
      ),
    ];

    // Preserve identity across sites, but on collision let a fresh UUID be minted.
    if ($uuid !== null && !$collision) {
      $data['webhook_uuid'] = $uuid;
    }

    $newId = $this->webhooks->create($data);
    if (!$newId) {
      throw new \RuntimeException('Failed to create imported webhook: ' . ($webhook['name'] ?? ''));
    }
    $newId = (int) $newId;
    $created['webhooks']++;

    foreach ($webhook['triggers'] ?? [] as $trigger) {
      $this->importTrigger($newId, $trigger);
    }

    return $newId;
  }

  private function importTrigger(int $webhookId, array $trigger): void {
    $triggerName = (string) ($trigger['name'] ?? '');
    if ($triggerName === '') {
      return;
    }

    $schema = $trigger['schema'] ?? null;
    if (is_array($schema)) {
      $this->schemas->upsert($webhookId, $triggerName, [
        'example_payload'        => $schema['example_payload'] ?? null,
        'field_mapping'          => $schema['field_mapping'] ?? null,
        'conditions'             => $schema['conditions'] ?? null,
        'conditions_evaluate_on' => $schema['conditions_evaluate_on'] ?? 'original',
        'include_user_data'      => (bool) ($schema['include_user_data'] ?? false),
        'use_shared_example'     => (bool) ($schema['use_shared_example'] ?? true),
      ]);
    }

    /**
     * Let extensions (Pro Code Glue) restore per-trigger data on import.
     *
     * @param int    $webhookId   The freshly created webhook id.
     * @param string $triggerName The trigger name.
     * @param array  $trigger     The trigger document (name, schema, code_glue).
     */
    do_action('fswa_import_trigger', $webhookId, $triggerName, $trigger);
  }

  /**
   * @param array<string, int> $uuidToNewId
   * @param array              $created Mutated in place.
   */
  private function importChain(array $chain, array $uuidToNewId, array &$created): void {
    $name = (string) ($chain['name'] ?? '');
    if ($name === '') {
      return;
    }
    // Avoid the UNIQUE(name) collision by suffixing an existing name.
    if ($this->chains->findByName($name) !== null) {
      $name .= ' (' . gmdate('Y-m-d H:i') . ')';
    }

    $chainId = $this->chains->create([
      'name'        => $name,
      'description' => $chain['description'] ?? null,
    ]);
    if (!$chainId) {
      return;
    }
    $chainId = (int) $chainId;
    $created['chains']++;

    foreach ($chain['links'] ?? [] as $link) {
      $sourceId = (int) ($uuidToNewId[$link['source_uuid'] ?? ''] ?? 0);
      $targetId = (int) ($uuidToNewId[$link['target_uuid'] ?? ''] ?? 0);
      if ($sourceId <= 0 || $targetId <= 0) {
        continue;
      }
      if ($this->links->wouldCreateCycle($sourceId, $targetId)) {
        continue;
      }
      if ($this->links->create($chainId, $sourceId, $targetId)) {
        $created['links']++;
      }
    }
  }

  private function credentialMeta(array $document, ?string $ref, array $webhook): array {
    if ($ref !== null) {
      foreach ($document['credentials'] ?? [] as $cred) {
        if (($cred['ref'] ?? null) === $ref) {
          return $cred;
        }
      }
    }
    // Manual auth header — no metadata beyond the webhook it belongs to.
    return [
      'ref'         => 'manual:' . ($webhook['uuid'] ?? $webhook['name'] ?? ''),
      'name'        => sprintf('%s (auth)', $webhook['name'] ?? 'Imported'),
      'type'        => 'bearer',
      'header_name' => 'Authorization',
      'hint'        => '',
      'manual'      => true,
    ];
  }

  /**
   * @return true|WP_Error
   */
  private function validate(array $document) {
    $meta = $document['fswa_export'] ?? null;
    if (!is_array($meta) || (int) ($meta['version'] ?? 0) !== BuildExporter::SCHEMA_VERSION) {
      return new WP_Error(
        'fswa_import_invalid',
        __('Unrecognized or unsupported export file.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }
    if (!isset($document['webhooks']) || !is_array($document['webhooks'])) {
      return new WP_Error(
        'fswa_import_invalid',
        __('Export file contains no webhooks.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }
    return true;
  }
}
