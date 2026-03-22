<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Api\AuthHelper;
use FlowSystems\WebhookActions\Services\HookDiscoveryService;

class TriggersController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'triggers';

  /**
   * Register routes
   */
  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      'methods' => WP_REST_Server::READABLE,
      'callback' => [$this, 'getItems'],
      'permission_callback' => [$this, 'getItemsPermissionsCheck'],
    ]);
  }

  public function getItemsPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  /**
   * Get available triggers
   */
  public function getItems($request): WP_REST_Response {
    $triggers = $this->getAvailableTriggers();

    return rest_ensure_response($triggers);
  }

  /**
   * Get list of available WordPress triggers
   *
   * @return array
   */
  private function getAvailableTriggers(): array {
    $excluded = $this->getExcludedHookPatterns();
    $categories = $this->getCategories();
    $grouped = [];
    $seen = [];

    // Runtime-registered hooks ($wp_filter)
    global $wp_filter;
    foreach (array_keys($wp_filter) as $hookName) {
      if ($this->isExcludedHook($hookName, $excluded)) {
        continue;
      }
      $category = $this->detectHookCategory($hookName);
      $grouped[$category][$hookName] = true;
      $seen[$hookName] = true;
    }

    // Statically scanned hooks (plugins, themes, WP core)
    foreach ((new HookDiscoveryService())->discover() as $hookName => $slug) {
      if (isset($seen[$hookName]) || $this->isExcludedHook($hookName, $excluded)) {
        continue;
      }
      $categoryKey = str_replace('-', '_', $slug);
      if (!isset($categories[$categoryKey])) {
        $categories[$categoryKey] = ucwords(str_replace(['-', '_'], ' ', $slug));
      }
      $grouped[$categoryKey][$hookName] = true;
      $seen[$hookName] = true;
    }

    // Convert sets to sorted arrays
    foreach ($grouped as $category => &$names) {
      $names = array_keys($names);
      sort($names);
    }
    unset($names);

    // Sort categories: static order first, then dynamic alpha
    $staticOrder = array_keys($this->getCategories());
    uksort($grouped, function (string $a, string $b) use ($staticOrder): int {
      $aIdx = array_search($a, $staticOrder, true);
      $bIdx = array_search($b, $staticOrder, true);
      if ($aIdx === false && $bIdx === false) return strcmp($a, $b);
      if ($aIdx === false) return 1;
      if ($bIdx === false) return -1;
      return $aIdx - $bIdx;
    });

    /**
     * Filter the grouped hook list. Array of [ category => [hookName, ...] ].
     *
     * @param array $grouped
     */
    $grouped = apply_filters('fswa_available_triggers', $grouped);

    return [
      'grouped' => $grouped,
      'categories' => $categories,
      'allowCustom' => true,
    ];
  }

  /**
   * Get patterns for hooks to exclude
   *
   * @return array
   */
  private function getExcludedHookPatterns(): array {
    return [
      // Internal WordPress hooks
      '/^_/',
      '/^admin_/',
      '/^wp_ajax/',
      '/^rest_api/',
      '/^oembed/',
      '/^customize_/',
      '/^wp_head$/',
      '/^wp_footer$/',
      '/^wp_enqueue/',
      '/^admin_enqueue/',
      '/^login_/',
      '/^register_/',
      '/^widgets_/',
      '/^sidebar/',
      '/^dynamic_sidebar/',
      '/^get_header/',
      '/^get_footer/',
      '/^get_sidebar/',
      '/^template_/',
      '/^the_content$/',
      '/^the_title$/',
      '/^the_excerpt$/',
      '/^body_class$/',
      '/^post_class$/',
      '/^comment_class$/',
      '/^nav_menu/',
      '/^wp_nav_menu/',
      '/^pre_get/',
      '/^posts_/',
      '/^query$/',
      '/^parse_/',
      '/^sanitize_/',
      '/^clean_/',
      '/^check_/',
      '/^is_/',
      '/^load-/',
      '/^print_/',
      '/^show_/',
      '/^display_/',
      '/^render_/',
      '/^do_/',
      '/^doing_/',
      '/^current_/',
      '/^get_/',
      '/^update_/',
      '/^remove_/',
      '/^has_/',
      '/^can_/',
      '/^woocommerce_before/',
      '/^woocommerce_after/',
      // Filter hooks (usually not useful as triggers)
      '/_filter$/',
      '/_filters$/',
    ];
  }

  /**
   * Check if hook matches excluded patterns
   *
   * @param string $hookName
   * @param array $patterns
   * @return bool
   */
  private function isExcludedHook(string $hookName, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $hookName)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Detect category based on hook name
   *
   * @param string $hookName
   * @return string
   */
  private function detectHookCategory(string $hookName): string {
    $patterns = [
      'woocommerce' => '/^woocommerce/',
      'users' => '/user|login|logout|password|role|profile/',
      'posts' => '/post|publish/',
      'pages' => '/page/',
      'comments' => '/comment/',
      'taxonomy' => '/term|tax|category|tag/',
      'media' => '/attachment|media|upload|image/',
      'plugins' => '/plugin/',
      'options' => '/option/',
    ];

    foreach ($patterns as $category => $pattern) {
      if (preg_match($pattern, $hookName)) {
        return $category;
      }
    }

    return 'other';
  }

  /**
   * Get trigger categories
   *
   * @return array
   */
  private function getCategories(): array {
    return [
      'wordpress' => __('WordPress', 'flowsystems-webhook-actions'),
      'users' => __('Users', 'flowsystems-webhook-actions'),
      'posts' => __('Posts', 'flowsystems-webhook-actions'),
      'pages' => __('Pages', 'flowsystems-webhook-actions'),
      'comments' => __('Comments', 'flowsystems-webhook-actions'),
      'taxonomy' => __('Taxonomy', 'flowsystems-webhook-actions'),
      'media' => __('Media', 'flowsystems-webhook-actions'),
      'plugins' => __('Plugins', 'flowsystems-webhook-actions'),
      'options' => __('Options', 'flowsystems-webhook-actions'),
      'woocommerce' => __('WooCommerce', 'flowsystems-webhook-actions'),
      'other' => __('Other', 'flowsystems-webhook-actions'),
    ];
  }
}
