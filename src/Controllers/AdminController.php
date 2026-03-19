<?php

namespace FlowSystems\WebhookActions\Controllers;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\App;
use FlowSystems\WebhookActions\Api\WebhooksController;
use FlowSystems\WebhookActions\Api\LogsController;
use FlowSystems\WebhookActions\Api\TriggersController;
use FlowSystems\WebhookActions\Api\SettingsController;
use FlowSystems\WebhookActions\Api\QueueController;
use FlowSystems\WebhookActions\Api\HealthController;
use FlowSystems\WebhookActions\Api\SchemasController;
use FlowSystems\WebhookActions\Api\ApiTokensController;
use FlowSystems\WebhookActions\Api\IncomingEndpointsController;
use FlowSystems\WebhookActions\Api\IncomingWebhookController;

class AdminController {
  public function __construct() {
    add_action('admin_menu', [$this, 'addMenuPage']);
    add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    add_action('rest_api_init', [$this, 'registerRestRoutes']);
    add_action('admin_notices', [$this, 'showMigrationNotice']);

    // Enrich outgoing webhook payloads triggered by incoming endpoints.
    // Promotes args[0] (received context) to a top-level `received` key so field
    // mappings can reference `received.body.*`, `received.query.*`, etc. directly.
    add_filter('fswa_payload', [$this, 'enrichEndpointTriggerPayload'], 10, 3);
  }

  /**
   * Add admin menu page
   */
  public function addMenuPage(): void {
    add_menu_page(
      __('Webhook Actions', 'flowsystems-webhook-actions'),
      __('Webhook Actions', 'flowsystems-webhook-actions'),
      'manage_options',
      'fswa-webhook-actions',
      [$this, 'renderPage'],
      'dashicons-rest-api',
      80
    );
  }


  /**
   * Render the admin page (Vue SPA mount point)
   */
  public function renderPage(): void {
    echo '<div id="fswa-app"></div>';
  }

  /**
   * Enqueue admin assets
   *
   * @param string $hook
   */
  public function enqueueAssets(string $hook): void {
    if ($hook !== 'toplevel_page_fswa-webhook-actions') {
      return;
    }

    $distPath = App::$path . '/admin/dist';
    $distUrl = App::$url . '/admin/dist';

    $manifestPath = $distPath . '/.vite/manifest.json';

    if (file_exists($manifestPath)) {
      $manifest = json_decode(file_get_contents($manifestPath), true);

      $mainEntry = $manifest['src/main.js'] ?? $manifest['index.html'] ?? null;

      if ($mainEntry) {
        if (!empty($mainEntry['css'])) {
          foreach ($mainEntry['css'] as $index => $cssFile) {
            wp_enqueue_style(
              'fswa-admin-' . $index,
              $distUrl . '/' . $cssFile,
              [],
              App::VERSION
            );
          }
        } elseif (!empty($manifest['style.css']['file'])) {
          wp_enqueue_style(
            'fswa-admin',
            $distUrl . '/' . $manifest['style.css']['file'],
            [],
            App::VERSION
          );
        }

        wp_enqueue_script(
          'fswa-admin',
          $distUrl . '/' . $mainEntry['file'],
          [],
          App::VERSION,
          true
        );

        add_filter('script_loader_tag', function ($tag, $handle) {
          if ($handle === 'fswa-admin') {
            return str_replace(' src', ' type="module" src', $tag);
          }
          return $tag;
        }, 10, 2);
      }
    } else {
      $devUrl = 'http://localhost:5173';

      wp_enqueue_script(
        'fswa-vite-client',
        $devUrl . '/@vite/client',
        [],
        App::VERSION,
        false
      );

      wp_enqueue_script(
        'fswa-admin',
        $devUrl . '/src/main.js',
        ['fswa-vite-client'],
        App::VERSION,
        true
      );

      add_filter('script_loader_tag', function ($tag, $handle) {
        if (in_array($handle, ['fswa-vite-client', 'fswa-admin'])) {
          return str_replace(' src', ' type="module" src', $tag);
        }
        return $tag;
      }, 10, 2);
    }

    wp_localize_script('fswa-admin', 'fswaSettings', [
      'restUrl' => rest_url('fswa/v1/'),
      'nonce' => wp_create_nonce('wp_rest'),
      'adminUrl' => admin_url(),
      'pluginUrl' => App::$url,
    ]);

    wp_add_inline_style('wp-admin', '
            #fswa-app {
                min-height: 500px;
                background: #fff;
                margin: 20px 20px 20px 0;
                padding: 20px;
                border: 1px solid #c3c4c7;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            #fswa-app:empty::before {
                content: "Loading...";
                display: block;
                text-align: center;
                padding: 40px;
                color: #666;
            }
        ');
  }

  /**
   * Register REST API routes
   */
  public function registerRestRoutes(): void {
    (new WebhooksController())->registerRoutes();
    (new LogsController())->registerRoutes();
    (new TriggersController())->registerRoutes();
    (new SettingsController())->registerRoutes();
    (new QueueController())->registerRoutes();
    (new HealthController())->registerRoutes();
    (new SchemasController())->registerRoutes();
    (new ApiTokensController())->registerRoutes();
    (new IncomingEndpointsController())->registerRoutes();
    (new IncomingWebhookController())->registerRoutes();
  }

  /**
   * Enrich payload for fswa_endpoint_{slug} triggers.
   *
   * When an incoming endpoint fires an outgoing webhook, args[0] is the received
   * context array (body, query, headers, meta). We promote it to a top-level
   * `received` key so users can reference paths like `received.body.field` in
   * their webhook field mappings.
   *
   * @param array  $payload The assembled webhook payload
   * @param string $trigger The trigger hook name
   * @param array  $args    Raw hook arguments passed to the trigger
   * @return array
   */
  public function enrichEndpointTriggerPayload(array $payload, string $trigger, array $args): array {
    if (str_starts_with($trigger, 'fswa_endpoint_') && isset($args[0]) && is_array($args[0])) {
      $payload['received'] = $args[0];
    }
    return $payload;
  }

  /**
   * Show migration notice
   */
  public function showMigrationNotice(): void {
    if (get_transient('fswa_migration_notice')) {
      delete_transient('fswa_migration_notice');
?>
      <div class="notice notice-success is-dismissible">
        <p>
          <?php esc_html_e('Flow Systems Webhook Actions: Your webhooks have been migrated to the new database format.', 'flowsystems-webhook-actions'); ?>
        </p>
      </div>
<?php
    }
  }
}
