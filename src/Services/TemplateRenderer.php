<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Renders mustache-style template tags against a context array.
 *
 * ── Path syntax ────────────────────────────────────────────────────────────
 *   {{received.body.field}}            scalar value
 *   {{received.body.user.name}}        nested dot path
 *   {{received.body.items.0}}          array index (numeric)
 *   {{received.body.items[0]}}         array index (bracket notation)
 *   {{received.body.tags}}             whole array → comma-joined string
 *   {{received.query.param}}           query string param
 *   {{received.headers.x-my-header}}   request header (lowercase key)
 *   {{received.meta.method}}           method / source_ip / endpoint_slug / received_at
 *
 * ── Short aliases ───────────────────────────────────────────────────────────
 *   {{payload.field}}      →  {{received.body.field}}
 *   {{query.param}}        →  {{received.query.param}}
 *   {{headers.x-header}}   →  {{received.headers.x-header}}
 *
 * ── System variables ────────────────────────────────────────────────────────
 *   {{timestamp}}      Unix timestamp
 *   {{datetime}}       Current datetime  Y-m-d H:i:s
 *   {{date}}           Current date      Y-m-d
 *   {{time}}           Current time      H:i:s
 *   {{uuid}}           Random UUID v4
 *   {{site_url}}       site_url()
 *   {{home_url}}       home_url()
 *   {{admin_email}}    get_option('admin_email')
 *   {{blog_name}}      get_option('blogname')
 *
 * ── Modifier syntax ─────────────────────────────────────────────────────────
 *   {{path|modifier}}              e.g.  {{received.body.name|lower}}
 *   {{path|modifier:arg}}          e.g.  {{received.body.text|substr:0:10}}
 *   {{path|modifier1|modifier2}}   chained
 *
 *   Available modifiers:
 *     lower / upper / ucfirst / ucwords / trim / ltrim / rtrim
 *     slug           sanitize_title()
 *     escape / esc   esc_html()
 *     urlencode
 *     base64         base64_encode()
 *     md5            md5 hash
 *     sha256         sha256 hash
 *     nl2br
 *     strip_tags
 *     length         strlen()
 *     int / float
 *     abs / round[:decimals] / floor / ceil
 *     number_format[:decimals]
 *     date:format    wp_date(format, strtotime(value))
 *     strtotime
 *     json           wp_json_encode()
 *     json_pretty    wp_json_encode(…, JSON_PRETTY_PRINT)
 *     default:fallback   return fallback when value is empty
 *     substr:start[:len]
 *     first / last   first or last array element
 *     count          array count
 *     join[:sep]     implode array (default ", ")
 *     keys / values  json-encoded array keys/values
 *     reverse        strrev() or array_reverse()
 *
 * ── Object/array flattening (for CPT meta) ──────────────────────────────────
 *   flatten(['user' => ['name' => 'Jo']]) → ['user_name' => 'Jo']
 */
class TemplateRenderer {

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /**
   * Render all {{…}} tags in a string against $context.
   */
  public static function render(string $template, array $context): string {
    return (string) preg_replace_callback(
      '/\{\{\s*([^}]+?)\s*\}\}/',
      static function (array $m) use ($context): string {
        $inner = trim($m[1]);

        // Split into path + modifiers on unquoted pipe characters
        $parts     = array_map('trim', explode('|', $inner));
        $path      = array_shift($parts);
        $modifiers = $parts;

        // Resolve value (null = path not found → leave tag unchanged)
        $value = self::resolveValue($path, $context);
        if ($value === null) {
          return $m[0];
        }

        // Apply modifiers
        foreach ($modifiers as $mod) {
          $value = self::applyModifier($mod, $value);
        }

        return self::stringify($value);
      },
      $template
    );
  }

  /**
   * Resolve a dot-notation path (with optional bracket index) inside $context.
   *
   * Returns null when the path does not exist.
   */
  public static function resolvePath(string $path, array $context): ?string {
    $value = self::resolveValue($path, $context);
    return $value === null ? null : self::stringify($value);
  }

  /**
   * Flatten a nested array into dot-notated (or custom separator) key-value pairs.
   *
   * @param array<string, mixed> $data
   * @param string $prefix
   * @param string $separator
   * @return array<string, string>
   */
  public static function flatten(array $data, string $prefix = '', string $separator = '_'): array {
    $result = [];

    foreach ($data as $key => $value) {
      $fullKey = $prefix !== '' ? $prefix . $separator . $key : (string) $key;

      if (is_array($value)) {
        $result = array_merge($result, self::flatten($value, $fullKey, $separator));
      } else {
        $result[$fullKey] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
      }
    }

    return $result;
  }

  /**
   * Build the standard request context passed to templates and functions.
   *
   * @param array<string, mixed>  $body         Decoded JSON body (or empty array)
   * @param array<string, string> $queryParams  URL query parameters
   * @param array<string, string> $headers      Captured request headers
   * @param array<string, mixed>  $meta         method, source_ip, endpoint_slug, received_at
   * @return array<string, mixed>
   */
  public static function buildContext(
    array $body,
    array $queryParams,
    array $headers,
    array $meta
  ): array {
    return [
      'received' => [
        'body'    => $body,
        'query'   => $queryParams,
        'headers' => $headers,
        'meta'    => $meta,
      ],
    ];
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  /**
   * Resolve a path string to a PHP value (array, scalar, null).
   *
   * Handles:
   *  - System variables (timestamp, uuid, site_url, etc.)
   *  - Short aliases (payload.*, query.*, headers.*)
   *  - Full received.* paths
   *  - Bracket index notation: items[0]
   */
  private static function resolveValue(string $path, array $context): mixed {
    // ── System variables ────────────────────────────────────────────────────
    switch ($path) {
      case 'timestamp':   return (string) time();
      case 'datetime':    return wp_date('Y-m-d H:i:s');
      case 'date':        return wp_date('Y-m-d');
      case 'time':        return wp_date('H:i:s');
      case 'uuid':        return wp_generate_uuid4();
      case 'site_url':    return site_url();
      case 'home_url':    return home_url();
      case 'admin_email': return (string) get_option('admin_email');
      case 'blog_name':   return (string) get_option('blogname');
    }

    // ── Short aliases ────────────────────────────────────────────────────────
    if (str_starts_with($path, 'payload.')) {
      $path = 'received.body.' . substr($path, 8);
    } elseif (str_starts_with($path, 'query.')) {
      $path = 'received.query.' . substr($path, 6);
    } elseif (str_starts_with($path, 'headers.')) {
      $path = 'received.headers.' . substr($path, 8);
    }

    // ── Dot path traversal with bracket-index support ────────────────────────
    // Normalise bracket notation: items[0] → items.0
    $path    = (string) preg_replace('/\[(\d+)\]/', '.$1', $path);
    $parts   = explode('.', $path);
    $current = $context;

    foreach ($parts as $part) {
      if (is_array($current) && array_key_exists($part, $current)) {
        $current = $current[$part];
      } else {
        return null; // path not found
      }
    }

    return $current;
  }

  /**
   * Convert any PHP value to a display string.
   */
  private static function stringify(mixed $value): string {
    if (is_array($value)) {
      // Comma-join scalar leaves; JSON-encode objects/nested arrays
      $scalars = array_filter($value, 'is_scalar');
      if (count($scalars) === count($value)) {
        return implode(', ', array_map('strval', array_values($scalars)));
      }
      return (string) wp_json_encode($value);
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if (is_null($value)) {
      return '';
    }
    return (string) $value;
  }

  /**
   * Apply a single modifier (with optional colon-separated args) to $value.
   *
   * @param string $modifier  e.g. "lower", "substr:0:10", "default:N/A"
   * @param mixed  $value
   * @return mixed
   */
  private static function applyModifier(string $modifier, mixed $value): mixed {
    // Split modifier name and arguments
    $parts = explode(':', $modifier, 3);
    $func  = strtolower($parts[0]);
    $args  = array_slice($parts, 1);
    $str   = is_array($value) ? self::stringify($value) : (string) $value;

    return match ($func) {
      // ── String ───────────────────────────────────────────────────────────
      'lower', 'lowercase' => strtolower($str),
      'upper', 'uppercase' => strtoupper($str),
      'ucfirst'            => ucfirst($str),
      'ucwords'            => ucwords($str),
      'trim'               => trim($str),
      'ltrim'              => ltrim($str),
      'rtrim'              => rtrim($str),
      'slug'               => sanitize_title($str),
      'escape', 'esc'      => esc_html($str),
      'urlencode'          => urlencode($str),
      'base64'             => base64_encode($str),
      'md5'                => md5($str),
      'sha256'             => hash('sha256', $str),
      'nl2br'              => nl2br($str),
      'strip_tags'         => strip_tags($str),
      'length'             => (string) strlen($str),
      'reverse'            => is_array($value) ? array_reverse($value) : strrev($str),

      // ── Substring ────────────────────────────────────────────────────────
      'substr' => isset($args[1])
        ? substr($str, (int) $args[0], (int) $args[1])
        : substr($str, (int) ($args[0] ?? 0)),

      // ── Number ───────────────────────────────────────────────────────────
      'int', 'integer'  => (string) intval($value),
      'float'           => (string) floatval($value),
      'abs'             => (string) abs(floatval($value)),
      'round'           => (string) round(floatval($value), isset($args[0]) ? (int) $args[0] : 0),
      'floor'           => (string) floor(floatval($value)),
      'ceil'            => (string) ceil(floatval($value)),
      'number_format'   => number_format(floatval($value), isset($args[0]) ? (int) $args[0] : 0),

      // ── Date ─────────────────────────────────────────────────────────────
      'date'      => wp_date($args[0] ?? 'Y-m-d', strtotime($str) ?: time()),
      'strtotime' => (string) strtotime($str),

      // ── JSON ─────────────────────────────────────────────────────────────
      'json'       => (string) wp_json_encode($value),
      'json_pretty'=> (string) wp_json_encode($value, JSON_PRETTY_PRINT),

      // ── Default/fallback ─────────────────────────────────────────────────
      'default' => $str !== '' ? $value : ($args[0] ?? ''),

      // ── Array ────────────────────────────────────────────────────────────
      'first'  => is_array($value) ? ($value[array_key_first($value)] ?? '') : $value,
      'last'   => is_array($value) ? ($value[array_key_last($value)]  ?? '') : $value,
      'count'  => is_array($value) ? (string) count($value) : '1',
      'join'   => is_array($value) ? implode($args[0] ?? ', ', $value) : $str,
      'keys'   => is_array($value) ? (string) wp_json_encode(array_keys($value))   : '[]',
      'values' => is_array($value) ? (string) wp_json_encode(array_values($value)) : '[]',

      // Unknown modifier — pass through unchanged
      default => $value,
    };
  }
}
