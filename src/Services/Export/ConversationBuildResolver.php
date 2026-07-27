<?php

namespace FlowSystems\WebhookActions\Services\Export;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\AgentConversationRepository;

/**
 * Resolves an AI Builder conversation to the objects it actually built, so
 * "Share this build" can hand {@see BuildExporter} a webhook/chain id set
 * without the user re-picking everything by hand.
 *
 * The source of truth is `execution_json['steps']` — every applied plan step
 * records the ability it ran plus the concrete result it produced. The build
 * ledger is deliberately NOT used: it only dedupes create_webhook and
 * provision_wp_app_password, so it would miss chains entirely.
 */
class ConversationBuildResolver {
  private AgentConversationRepository $conversations;

  public function __construct(?AgentConversationRepository $conversations = null) {
    $this->conversations = $conversations ?? new AgentConversationRepository();
  }

  /**
   * @return array{webhook_ids: int[], chain_ids: int[]}
   */
  public function resolve(int $conversationId): array {
    $conversation = $conversationId > 0 ? $this->conversations->find($conversationId) : null;

    return $conversation === null
      ? ['webhook_ids' => [], 'chain_ids' => []]
      : $this->resolveFromConversation($conversation);
  }

  /**
   * @param array<string, mixed> $conversation A hydrated conversation row.
   * @return array{webhook_ids: int[], chain_ids: int[]}
   */
  public function resolveFromConversation(array $conversation): array {
    $execution = is_array($conversation['execution_json'] ?? null) ? $conversation['execution_json'] : [];
    $steps     = is_array($execution['steps'] ?? null) ? $execution['steps'] : [];

    $webhookIds = [];
    $chainIds   = [];
    $deleted    = [];

    foreach ($steps as $step) {
      if (!is_array($step) || (string) ($step['status'] ?? '') !== 'done') {
        continue;
      }

      $ability = (string) ($step['ability'] ?? '');
      $result  = is_array($step['result'] ?? null) ? $step['result'] : [];
      $input   = is_array($step['input'] ?? null) ? $step['input'] : [];

      switch ($ability) {
        case 'create_webhook':
        case 'update_webhook':
          $webhookIds[] = (int) ($result['webhook']['id'] ?? $this->intInput($input, 'id'));
          break;

        case 'enable_webhook':
          $webhookIds[] = (int) ($result['id'] ?? $this->intInput($input, 'id'));
          break;

        case 'assign_credential':
          $webhookIds[] = (int) ($result['webhook_id'] ?? $this->intInput($input, 'webhook_id'));
          break;

        case 'set_mapping':
        case 'set_conditions':
        case 'test_dispatch':
          $webhookIds[] = $this->intInput($input, 'webhook_id');
          break;

        case 'create_chain':
          $chainIds[] = (int) ($result['chain']['id'] ?? 0);
          break;

        case 'create_chain_link':
          $link         = is_array($result['link'] ?? null) ? $result['link'] : [];
          $chainIds[]   = (int) ($link['chain_id'] ?? $this->intInput($input, 'chain_id'));
          $webhookIds[] = (int) ($link['source_webhook_id'] ?? $this->intInput($input, 'source_webhook_id'));
          $webhookIds[] = (int) ($link['target_webhook_id'] ?? $this->intInput($input, 'target_webhook_id'));
          break;

        case 'delete_webhook':
          $deleted[] = (int) ($result['id'] ?? $this->intInput($input, 'id'));
          break;
      }
    }

    $webhookIds = array_values(array_diff($this->clean($webhookIds), $this->clean($deleted)));

    return [
      'webhook_ids' => $webhookIds,
      'chain_ids'   => $this->clean($chainIds),
    ];
  }

  /**
   * Step inputs may still hold an unresolved `{{step_2.id}}` reference; only a
   * concrete numeric value is usable here.
   *
   * @param array<string, mixed> $input
   */
  private function intInput(array $input, string $key): int {
    $value = $input[$key] ?? null;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * @param int[] $ids
   * @return int[]
   */
  private function clean(array $ids): array {
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
  }
}
