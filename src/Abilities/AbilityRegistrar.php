<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

/**
 * Exposes the AbilityRegistry to the WordPress Abilities API (WP 6.9+/7.0).
 *
 * This is purely additive: the AgentOrchestrator always calls AbilityRegistry
 * directly, so the AI Builder works on any supported WordPress version. When the
 * Abilities API is present, we ALSO register each ability under the
 * `flowsystems-webhook-actions/*` namespace so external MCP clients (Claude Code,
 * Cursor) and other AI tooling can discover and invoke the exact same toolset via
 * the /wp-abilities/v1/ REST surface and the MCP Adapter.
 */
class AbilityRegistrar {
  private AbilityRegistry $registry;

  public function __construct(?AbilityRegistry $registry = null) {
    $this->registry = $registry ?? new AbilityRegistry();
  }

  /**
   * Hook into the Abilities API init actions when available.
   *
   * Categories and abilities have SEPARATE init hooks — core rejects a category
   * registered from inside `wp_abilities_api_init`.
   */
  public function init(): void {
    if (!function_exists('wp_register_ability')) {
      return; // Abilities API not present — agent still works via direct execution.
    }

    add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
    add_action('wp_abilities_api_init', [$this, 'register']);
  }

  /**
   * Register the ability category.
   *
   * Must run on `wp_abilities_api_categories_init`; core emits a _doing_it_wrong
   * and drops the category anywhere else.
   */
  public function registerCategory(): void {
    if (function_exists('wp_register_ability_category')) {
      wp_register_ability_category('webhook-actions', [
        'label'       => __('Webhook Actions', 'flowsystems-webhook-actions'),
        // Required by core — a category without a description is silently dropped,
        // taking every ability assigned to it with it.
        'description' => __('Create and manage outgoing webhooks: triggers, field mappings, conditions, chains, credentials and delivery logs.', 'flowsystems-webhook-actions'),
      ]);
    }
  }

  /**
   * Register every ability definition.
   */
  public function register(): void {
    foreach ($this->registry->definitions() as $name => $def) {
      $abilityName = AbilityRegistry::NAMESPACE . '/' . self::publicSlug($name);
      $scope       = $def['scope'] ?? 'read';

      wp_register_ability($abilityName, [
        'label'             => $def['label'] ?? $name,
        'description'       => $def['description'] ?? '',
        'category'          => $def['category'] ?? 'webhook-actions',
        'input_schema'      => $def['input_schema'] ?? ['type' => 'object'],
        'output_schema'     => ['type' => 'object'],
        'execute_callback'  => static function (array $input) use ($name) {
          $result = (new AbilityRegistry())->execute($name, $input);
          // wp_register_ability expects the value or a WP_Error — pass either through.
          return $result;
        },
        'permission_callback' => static function () use ($scope) {
          return self::permitted($scope);
        },
        'meta'              => [
          'requires_confirm' => $def['requires_confirm'] ?? false,
          // Without this the ability registers but stays invisible: `public` seeds
          // `show_in_rest`, and both the /wp-abilities/v1/ list and run controllers
          // hard-filter on `show_in_rest` — so MCP clients never see it either.
          'public'           => true,
          // `public` alone is NOT enough for MCP in the wild. WooCommerce and
          // IvyForms each bundle their own older `wordpress/mcp-adapter` in
          // vendor/ (0.1.0 and 0.5.0 as of 2026-08), and whichever copy the
          // autoloader resolves first serves the whole request. The older ones
          // only honour the nested flag, so on a WooCommerce site `meta.public`
          // is silently ignored and every ability reads as "mcp.public!=true".
          // Declaring both is what WooCommerce's own abilities do.
          'mcp'              => [
            'public' => true,
            'type'   => 'tool',
          ],
        ],
      ]);
    }
  }

  /**
   * Map an internal registry key to a valid Abilities API slug.
   *
   * Core validates names against `/^[a-z0-9-]+\/[a-z0-9-]+$/` — underscores are
   * rejected outright. The registry's own keys (and the AI Builder's tool names)
   * stay snake_case; only the public Abilities/MCP surface uses dashes.
   */
  private static function publicSlug(string $name): string {
    return str_replace('_', '-', $name);
  }

  /**
   * Capability gate for ability execution over the Abilities REST surface.
   *
   * Read abilities require a logged-in admin; write abilities require the same.
   * Token-scoped (agent) access for external MCP is mediated by the MCP Adapter /
   * token layer and can be opened up via the filter below without touching code.
   *
   * @param string $scope Required registry scope (read|full).
   */
  private static function permitted(string $scope): bool {
    $allowed = current_user_can('manage_options');

    /**
     * Filter whether the current request may invoke a Webhook Actions ability of
     * the given scope through the Abilities API.
     *
     * @param bool   $allowed Whether access is granted.
     * @param string $scope   Required scope (read|full).
     */
    return (bool) apply_filters('fswa_ability_permitted', $allowed, $scope);
  }
}
