<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Renders mustache-style template tags against a context array.
 *
 * Supported syntax:
 *   {{received.body.field}}          – scalar value
 *   {{received.body.user.name}}      – nested dot path
 *   {{received.body.tags.0}}         – array index
 *   {{received.body.tags}}           – whole array → comma-joined string
 *   {{received.query.param}}         – query string param
 *   {{received.headers.x-my-header}} – request header (lowercase key)
 *   {{received.meta.method}}         – method / source_ip / endpoint_slug / received_at
 *
 * Object/array flattening (for CPT meta):
 *   flatten(['user' => ['name' => 'Jo']]) → ['user_name' => 'Jo']
 */
class TemplateRenderer {

  /**
   * Render all {{…}} tags in a string against $context.
   *
   * @param string               $template
   * @param array<string, mixed> $context
   * @return string
   */
  public static function render(string $template, array $context): string {
    return (string) preg_replace_callback(
      '/\{\{\s*([^}]+?)\s*\}\}/',
      static function (array $m) use ($context): string {
        $resolved = self::resolvePath(trim($m[1]), $context);
        return $resolved ?? $m[0]; // leave tag unchanged when path not found
      },
      $template
    );
  }

  /**
   * Resolve a dot-notation path inside $context.
   *
   * @param string               $path    e.g. "received.body.user.name"
   * @param array<string, mixed> $context
   * @return string|null  null when path does not exist
   */
  public static function resolvePath(string $path, array $context): ?string {
    $parts   = explode('.', $path);
    $current = $context;

    foreach ($parts as $part) {
      if (is_array($current) && array_key_exists($part, $current)) {
        $current = $current[$part];
      } else {
        return null;
      }
    }

    if (is_array($current)) {
      // Flatten to comma-separated string for scalar display
      return implode(', ', array_map('strval', array_values(array_filter($current, 'is_scalar'))));
    }

    if (is_bool($current)) {
      return $current ? 'true' : 'false';
    }

    return (string) $current;
  }

  /**
   * Flatten a nested array into dot-notated (or custom separator) key-value pairs.
   *
   * Examples:
   *   ['user' => ['name' => 'Jo', 'age' => 30]]
   *   → ['user_name' => 'Jo', 'user_age' => '30']
   *
   *   Arrays of objects are indexed:
   *   ['tags' => ['foo', 'bar']]
   *   → ['tags_0' => 'foo', 'tags_1' => 'bar']
   *
   * @param array<string, mixed> $data
   * @param string               $prefix
   * @param string               $separator
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
   * @param array<string, mixed> $body           Decoded JSON body (or empty array)
   * @param array<string, string> $queryParams   URL query parameters
   * @param array<string, string> $headers        Captured request headers
   * @param array<string, mixed> $meta            method, source_ip, endpoint_slug, received_at
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
}
