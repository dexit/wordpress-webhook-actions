<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Services\RetryPolicy;

/**
 * The site-wide retry and backoff defaults every webhook inherits unless it
 * overrides them. {@see RetryPolicy} reads them; this is where they are set.
 *
 * Route and option names keep their `pro/` prefix from when retry tuning was a
 * Pro feature — they are what shipped admin builds already call and what live
 * sites already have stored.
 */
class RetrySettingsController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'pro/settings';

  private const OPTION_GLOBAL_MAX_ATTEMPTS   = 'fswa_pro_global_max_attempts';
  private const OPTION_BACKOFF_STRATEGY      = 'fswa_pro_backoff_strategy';
  private const OPTION_BACKOFF_BASE_DELAY    = 'fswa_pro_backoff_base_delay';
  private const OPTION_BACKOFF_MAX_DELAY     = 'fswa_pro_backoff_max_delay';

  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getSettings'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [$this, 'updateSettings'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
    ]);
  }

  public function permissionsCheck(): bool|WP_Error {
    return current_user_can('manage_options')
      ? true
      : new WP_Error('rest_forbidden', __('You do not have permission.', 'flowsystems-webhook-actions'), ['status' => 403]);
  }

  public function getSettings(WP_REST_Request $request): WP_REST_Response {
    $maxAttempts = get_option(self::OPTION_GLOBAL_MAX_ATTEMPTS);
    $strategy    = get_option(self::OPTION_BACKOFF_STRATEGY);
    $baseDelay   = get_option(self::OPTION_BACKOFF_BASE_DELAY);
    $maxDelay    = get_option(self::OPTION_BACKOFF_MAX_DELAY);

    return rest_ensure_response([
      'global_max_attempts'   => $maxAttempts !== false ? (int) $maxAttempts : null,
      'backoff_strategy'      => $strategy !== false ? (string) $strategy : null,
      'backoff_base_delay'    => $baseDelay !== false ? (int) $baseDelay : null,
      'backoff_max_delay'     => $maxDelay !== false ? (int) $maxDelay : null,
    ]);
  }

  public function updateSettings(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $response = [];

    // global_max_attempts
    if ($request->has_param('global_max_attempts')) {
      $raw = $request->get_param('global_max_attempts');
      if ($raw === null || $raw === '') {
        delete_option(self::OPTION_GLOBAL_MAX_ATTEMPTS);
        $response['global_max_attempts'] = null;
      } else {
        $value = (int) $raw;
        if ($value < RetryPolicy::MAX_ATTEMPTS_MIN || $value > RetryPolicy::MAX_ATTEMPTS_MAX) {
          return new WP_Error(
            'rest_invalid_param',
            __('global_max_attempts must be between 1 and 100.', 'flowsystems-webhook-actions'),
            ['status' => 400]
          );
        }
        update_option(self::OPTION_GLOBAL_MAX_ATTEMPTS, $value, false);
        $response['global_max_attempts'] = $value;
      }
    }

    // backoff_strategy
    if ($request->has_param('backoff_strategy')) {
      $raw = $request->get_param('backoff_strategy');
      if ($raw === null || $raw === '') {
        delete_option(self::OPTION_BACKOFF_STRATEGY);
        $response['backoff_strategy'] = null;
      } else {
        if (!in_array($raw, RetryPolicy::STRATEGIES, true)) {
          return new WP_Error(
            'rest_invalid_param',
            __('backoff_strategy must be exponential, linear, or fixed.', 'flowsystems-webhook-actions'),
            ['status' => 400]
          );
        }
        update_option(self::OPTION_BACKOFF_STRATEGY, $raw, false);
        $response['backoff_strategy'] = $raw;
      }
    }

    // backoff_base_delay
    if ($request->has_param('backoff_base_delay')) {
      $raw = $request->get_param('backoff_base_delay');
      if ($raw === null || $raw === '') {
        delete_option(self::OPTION_BACKOFF_BASE_DELAY);
        $response['backoff_base_delay'] = null;
      } else {
        $value = (int) $raw;
        if ($value < RetryPolicy::DELAY_MIN || $value > RetryPolicy::DELAY_MAX) {
          return new WP_Error(
            'rest_invalid_param',
            __('backoff_base_delay must be between 1 and 86400 seconds.', 'flowsystems-webhook-actions'),
            ['status' => 400]
          );
        }
        update_option(self::OPTION_BACKOFF_BASE_DELAY, $value, false);
        $response['backoff_base_delay'] = $value;
      }
    }

    // backoff_max_delay
    if ($request->has_param('backoff_max_delay')) {
      $raw = $request->get_param('backoff_max_delay');
      if ($raw === null || $raw === '') {
        delete_option(self::OPTION_BACKOFF_MAX_DELAY);
        $response['backoff_max_delay'] = null;
      } else {
        $value = (int) $raw;
        if ($value < RetryPolicy::DELAY_MIN || $value > RetryPolicy::DELAY_MAX) {
          return new WP_Error(
            'rest_invalid_param',
            __('backoff_max_delay must be between 1 and 86400 seconds.', 'flowsystems-webhook-actions'),
            ['status' => 400]
          );
        }
        update_option(self::OPTION_BACKOFF_MAX_DELAY, $value, false);
        $response['backoff_max_delay'] = $value;
      }
    }

    return rest_ensure_response($response);
  }
}
