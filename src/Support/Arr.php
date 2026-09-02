<?php

namespace FlowSystems\WebhookActions\Support;

defined('ABSPATH') || exit;

/**
 * Small array helpers with a floor of PHP 8.0 / WordPress 6.0.
 */
class Arr {

  /**
   * Is this a list — sequential integer keys from 0, the shape a JSON array
   * decodes to?
   *
   * array_is_list() is PHP 8.1, and WordPress only started polyfilling it in
   * 6.5. This plugin supports PHP 8.0 and WP 6.0, so calling it directly is a
   * fatal error on the low end of that range.
   *
   * @param mixed $value
   */
  public static function isList($value): bool {
    if (!is_array($value)) {
      return false;
    }

    if (function_exists('array_is_list')) {
      return array_is_list($value);
    }

    $expected = 0;
    foreach ($value as $key => $_) {
      if ($key !== $expected++) {
        return false;
      }
    }

    return true;
  }
}
