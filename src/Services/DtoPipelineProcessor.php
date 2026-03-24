<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Executes a DTO/ETL pipeline against a request context.
 *
 * ── Pipeline config (array of field definitions) ───────────────────────────
 *   Each entry is an associative array:
 *   {
 *     "output_key"  : "user_email",           // key in the $dto output array
 *     "source"      : "{{received.body.email|lower|trim}}",  // template tag
 *     "type"        : "string",               // string|int|float|bool|array|json
 *     "default"     : "",                     // fallback when resolved value is empty
 *     "condition"   : "{{received.body.type}} == order",  // optional: skip if false
 *     "label"       : "User Email",           // UI-only, ignored at runtime
 *   }
 *
 * ── Usage ───────────────────────────────────────────────────────────────────
 *   $context = TemplateRenderer::buildContext($body, $query, $headers, $meta);
 *   $dto     = DtoPipelineProcessor::process($pipeline['pipeline_config'], $context);
 *   // $dto is now available as $context['dto'] in function runners, CPT mapper, etc.
 *
 * ── Type coercions ─────────────────────────────────────────────────────────
 *   string  — no-op (already string from TemplateRenderer)
 *   int     — (int) cast
 *   float   — (float) cast
 *   bool    — truthy: "true", "1", "yes", "on" → true; everything else → false
 *   array   — json_decode if JSON, comma-split otherwise
 *   json    — raw JSON string (decoded to array if valid, wrapped otherwise)
 */
class DtoPipelineProcessor {

  /**
   * Process a pipeline config against a request context.
   *
   * @param array<int, array<string, mixed>> $pipelineConfig  Field definitions
   * @param array<string, mixed>             $context         TemplateRenderer context
   * @return array<string, mixed>  The resolved DTO key-value map
   */
  public static function process(array $pipelineConfig, array $context): array {
    $dto = [];

    foreach ($pipelineConfig as $field) {
      $outputKey = trim((string) ($field['output_key'] ?? ''));
      if ($outputKey === '') {
        continue;
      }

      // Evaluate optional condition — skip field if condition is false-y
      $condition = trim((string) ($field['condition'] ?? ''));
      if ($condition !== '' && !self::evaluateCondition($condition, $context)) {
        continue;
      }

      $source  = (string) ($field['source'] ?? '');
      $type    = strtolower(trim((string) ($field['type'] ?? 'string')));
      $default = (string) ($field['default'] ?? '');

      // Resolve source template — could be a bare tag or a full template string
      $resolved = $source !== '' ? TemplateRenderer::render($source, $context) : '';

      // Apply default when resolved value is empty
      if ($resolved === '') {
        $resolved = $default;
      }

      $dto[$outputKey] = self::coerce($resolved, $type, $context);
    }

    return $dto;
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  /**
   * Evaluate a simple condition string.
   *
   * Supported operators: ==, !=, >, >=, <, <=, contains, not_contains
   * Both sides are rendered through TemplateRenderer before comparison.
   *
   * Examples:
   *   "{{received.body.status}} == active"
   *   "{{received.body.amount}} > 0"
   *   "{{received.body.tags}} contains vip"
   *
   * @param string $condition Raw condition expression
   * @param array<string, mixed> $context
   */
  private static function evaluateCondition(string $condition, array $context): bool {
    // Render any template tags in the condition first
    $rendered = TemplateRenderer::render($condition, $context);

    // Try operator patterns
    $operators = ['>=', '<=', '!=', '==', '>', '<', 'not_contains', 'contains'];

    foreach ($operators as $op) {
      if (str_contains($rendered, ' ' . $op . ' ')) {
        [$left, $right] = array_map('trim', explode(' ' . $op . ' ', $rendered, 2));

        return match ($op) {
          '=='          => $left == $right,
          '!='          => $left != $right,
          '>'           => (float) $left > (float) $right,
          '>='          => (float) $left >= (float) $right,
          '<'           => (float) $left < (float) $right,
          '<='          => (float) $left <= (float) $right,
          'contains'    => str_contains($left, $right),
          'not_contains'=> !str_contains($left, $right),
          default       => false,
        };
      }
    }

    // Bare value: truthy check
    return !empty($rendered) && $rendered !== 'false' && $rendered !== '0';
  }

  /**
   * Coerce a resolved string value to the target PHP type.
   *
   * @param mixed $value
   * @return mixed
   */
  private static function coerce(mixed $value, string $type, array $context): mixed {
    $str = (string) $value;

    return match ($type) {
      'int', 'integer' => (int) $str,
      'float', 'double', 'number' => (float) $str,
      'bool', 'boolean' => in_array(strtolower($str), ['true', '1', 'yes', 'on'], true),
      'array' => self::toArray($str),
      'json'  => self::toArray($str), // decoded array
      default => $str, // string
    };
  }

  /**
   * Attempt to parse a string into an array.
   * Tries JSON first, falls back to comma-split.
   *
   * @return array<mixed>
   */
  private static function toArray(string $str): array {
    if ($str === '') {
      return [];
    }

    $decoded = json_decode($str, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      return $decoded;
    }

    // Comma-separated fallback
    return array_values(array_filter(array_map('trim', explode(',', $str))));
  }
}
