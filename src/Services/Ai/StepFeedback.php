<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

/**
 * Feeds the OUTCOME of executed plan steps back to the model.
 *
 * Reads already round-trip: executeReads() appends a `tool` transcript entry
 * that replays into the next prompt. Plan steps did not — their results lived
 * only in execution_json, so the model could propose a step, have it fail, and
 * never learn. It saw the failure only if the user typed it out or it spent a
 * read round on get_logs to look up what its own step had just done.
 *
 * Outcomes accumulate across a run and flush as ONE entry when the run pauses
 * or finishes, so a long plan cannot bury the read results it sits next to.
 */
final class StepFeedback {
  /** Max bytes of encoded result kept per step. */
  private const RESULT_BYTES = 1500;

  /** Marks the entry so the orchestrator replays it in full (see modelMessages). */
  public const KIND = 'step_results';

  /**
   * One executed step's outcome, in the shape the model is shown.
   *
   * @param array<string, mixed>      $step   The step that just ran.
   * @param array<string, mixed>|null $result Ability result, when it produced one.
   * @param string                    $note   Why it stopped, for a non-ok outcome.
   * @return array<string, mixed>
   */
  public static function outcome(array $step, ?array $result, bool $ok, string $note = ''): array {
    $outcome = [
      'id'      => (string) ($step['id'] ?? ''),
      'ability' => (string) ($step['ability'] ?? ''),
      'ok'      => $ok,
    ];
    if ($note !== '') {
      $outcome['note'] = $note;
    }
    if ($result !== null) {
      $outcome['result'] = PayloadRedactor::redact($result);
    }
    return $outcome;
  }

  /**
   * Record one executed step's outcome onto the run.
   *
   * @param array<string, mixed>      $execution Run state, mutated in place.
   * @param array<string, mixed>      $step      The step that just ran.
   * @param array<string, mixed>|null $result    Ability result, when it produced one.
   * @param string                    $note      Why it stopped, for a non-ok outcome.
   */
  public static function record(array &$execution, array $step, ?array $result, bool $ok, string $note = ''): void {
    $outcomes   = is_array($execution['outcomes'] ?? null) ? $execution['outcomes'] : [];
    $outcomes[] = self::outcome($step, $result, $ok, $note);

    $execution['outcomes'] = $outcomes;
  }

  /**
   * Drain the recorded outcomes into a transcript entry, or null when there is
   * nothing to report. Clears the accumulator so each outcome is fed back once.
   *
   * @param array<string, mixed> $execution Run state, mutated in place.
   * @return array{role:string, kind:string, content:string}|null
   */
  public static function flush(array &$execution): ?array {
    $outcomes = is_array($execution['outcomes'] ?? null) ? $execution['outcomes'] : [];
    unset($execution['outcomes']);

    if ($outcomes === []) {
      return null;
    }

    return [
      'role'    => 'tool',
      'kind'    => self::KIND,
      'content' => self::render($outcomes),
    ];
  }

  /**
   * Same rendering for the batch execute() path, which applies a whole plan in
   * one call and has no run state to accumulate onto.
   *
   * @param array<int, array<string, mixed>> $outcomes
   * @return array{role:string, kind:string, content:string}|null
   */
  public static function entry(array $outcomes): ?array {
    if ($outcomes === []) {
      return null;
    }
    return ['role' => 'tool', 'kind' => self::KIND, 'content' => self::render($outcomes)];
  }

  /**
   * @param array<int, array<string, mixed>> $outcomes
   */
  private static function render(array $outcomes): string {
    return "STEP RESULTS (plan steps the plugin just executed on this site — this is what ACTUALLY happened, not a proposal):\n"
      . PayloadRedactor::encodeCapped($outcomes, self::RESULT_BYTES * count($outcomes))
      . "\n\nAny step with \"ok\": false did NOT succeed. Diagnose it from the result above — it already holds the"
      . " response the endpoint sent — instead of re-reading it with get_logs, and never treat a plan step as"
      . ' done just because you proposed it.';
  }
}
