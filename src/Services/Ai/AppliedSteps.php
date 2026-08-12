<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

/**
 * Everything a Build-with-AI conversation actually applied, across every plan
 * it went through.
 *
 * `execution_json['steps']` is the CURRENT plan's state machine, and a re-plan
 * re-seeds it from scratch ({@see PlanExecutor::seedExecution()}). That is right
 * for running, but it means the record of earlier plans disappears: a build
 * whose first plan created the webhook, set the mapping and attached a snippet,
 * then failed its test and got a three-step fix plan, ends up remembering three
 * steps. Anything reading the run afterwards — "Abilities used" on a published
 * build, and the resolver that decides which webhooks a share contains — read a
 * fraction of the work and had no way to know it.
 *
 * So each re-seed snapshots the outgoing run into `execution_json['applied']`,
 * an append-only history, and readers ask for that history plus whatever the
 * current run has settled. Steps pre-marked done by the build ledger (`reused`)
 * are skipped on the way in: they are the SAME object recorded by the run that
 * genuinely made it, and counting them again would double every carried-forward
 * create_webhook.
 */
final class AppliedSteps {
  /** A step that ran to a real outcome, as opposed to one still pending. */
  private const SETTLED = ['done', 'skipped', 'failed', 'reverted'];

  /**
   * Cap on the retained history. A conversation that re-plans dozens of times
   * would otherwise grow execution_json without bound, and the readers below
   * only ever show or resolve the first hundred or so anyway.
   */
  private const MAX_HISTORY = 300;

  /**
   * Every step applied in this conversation, oldest first: the carried-forward
   * history plus the current run's settled steps.
   *
   * @param array<string, mixed> $execution An execution_json array.
   * @return array<int, array<string, mixed>>
   */
  public static function all(array $execution): array {
    $out = [];

    foreach ((array) ($execution['applied'] ?? []) as $step) {
      if (is_array($step)) {
        $out[] = $step;
      }
    }

    foreach (self::settledIn($execution) as $step) {
      $out[] = $step;
    }

    return count($out) > self::MAX_HISTORY ? array_slice($out, -self::MAX_HISTORY) : $out;
  }

  /**
   * The history to carry into a re-seeded execution: identical to all(), which
   * is exactly the point — the run being replaced becomes history.
   *
   * @param array<string, mixed> $prior The execution_json being replaced.
   * @return array<int, array<string, mixed>>
   */
  public static function carryForward(array $prior): array {
    return self::all($prior);
  }

  /**
   * The current run's steps that reached an outcome under their own power.
   *
   * @param array<string, mixed> $execution
   * @return array<int, array<string, mixed>>
   */
  private static function settledIn(array $execution): array {
    $out = [];

    foreach ((array) ($execution['steps'] ?? []) as $step) {
      if (!is_array($step) || !empty($step['reused'])) {
        continue;
      }
      if (!in_array((string) ($step['status'] ?? ''), self::SETTLED, true)) {
        continue;
      }
      $out[] = $step;
    }

    return $out;
  }
}
