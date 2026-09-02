<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Who may write Code Glue.
 *
 * A snippet is PHP that {@see SnippetExecutor} runs with `eval()`, so writing
 * one is the same act as editing a plugin file — and it is gated on
 * `edit_plugins`, the capability WordPress uses for "may run code they typed",
 * rather than on `manage_options`.
 *
 * With one deliberate exception. Core denies `edit_plugins` for THREE separate
 * reasons (see `map_meta_cap`): `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, and
 * being a non-super-admin on multisite. Only the first of those is a statement
 * about running code. `DISALLOW_FILE_MODS` means "this site does not install or
 * update plugins from the dashboard", which is what most managed hosts (WP
 * Engine, Kinsta, Pantheon, Flywheel) set by default — and honouring it here
 * would switch Code Glue off for a large number of sites whose owners never
 * asked for that. So that one denial, and only that one, is stepped around for
 * a `manage_options` user on single-site.
 *
 * `DISALLOW_FILE_EDIT` is honoured absolutely, and there is deliberately no
 * setting to turn it back on: it lives in wp-config.php precisely so the
 * dashboard cannot undo it, and a plugin offering a button to do so would be
 * defeating the thing it exists for.
 *
 * The second door is an API token. `AuthHelper::dualAuth` accepts one in place
 * of an admin session, which on a plugin anyone can install would make a
 * full-scope bearer token a remote-code-execution primitive. So token-carried
 * snippet writes are OFF unless the site owner deliberately turns them on —
 * which is what an external AI agent (MCP, Claude Code, Cursor) needs before it
 * can write glue. Reading snippets is unaffected throughout.
 */
class GluePermissions {
  /** Option gating snippet writes that authenticate with an API token rather than a user session. */
  public const OPTION_TOKEN_WRITES = 'fswa_glue_token_writes';

  /** The capability a user session must hold to write a snippet. */
  private const DEFAULT_CAPABILITY = 'edit_plugins';

  /** Why writing was refused. Stable strings — the admin branches on them. */
  public const REASON_FILE_EDIT_DISABLED = 'file_editing_disabled';
  public const REASON_CAPABILITY         = 'capability';
  public const REASON_TOKEN_WRITES_OFF   = 'token_writes_disabled';

  /**
   * Whether the current request may create, edit, delete, preview or assign a
   * Code Glue snippet.
   *
   * @return true|WP_Error True when allowed, else the 403 to return verbatim.
   */
  public function canWrite(): bool|WP_Error {
    if ($this->fileEditingDisabled()) {
      return new WP_Error(
        'fswa_glue_file_editing_disabled',
        $this->reasonMessage(self::REASON_FILE_EDIT_DISABLED),
        ['status' => 403, 'reason' => self::REASON_FILE_EDIT_DISABLED]
      );
    }

    // A signed-in user is judged on their capability, never on the token they
    // may also be carrying — a low-privileged user cannot borrow one to get in.
    if (get_current_user_id() > 0) {
      return $this->capabilityAllows()
        ? true
        : new WP_Error(
          'fswa_glue_forbidden',
          $this->reasonMessage(self::REASON_CAPABILITY),
          ['status' => 403, 'reason' => self::REASON_CAPABILITY]
        );
    }

    if ($this->tokenWritesEnabled()) {
      return true;
    }

    return new WP_Error(
      'fswa_glue_token_writes_disabled',
      $this->reasonMessage(self::REASON_TOKEN_WRITES_OFF),
      ['status' => 403, 'reason' => self::REASON_TOKEN_WRITES_OFF]
    );
  }

  /**
   * Whether Code Glue can be written at all in this request — the same answer
   * as canWrite(), as a plain boolean, for shaping UI rather than refusing.
   */
  public function canWriteNow(): bool {
    return $this->canWrite() === true;
  }

  /**
   * The state of Code Glue for the current request, for the admin to render.
   *
   * @return array{can_write: bool, reason: string, message: string, fixable_in_settings: bool}
   */
  public function status(): array {
    $result = $this->canWrite();

    if ($result === true) {
      return ['can_write' => true, 'reason' => '', 'message' => '', 'fixable_in_settings' => false];
    }

    $reason = (string) ($result->get_error_data()['reason'] ?? self::REASON_CAPABILITY);

    return [
      'can_write' => false,
      'reason'    => $reason,
      'message'   => $result->get_error_message(),
      // Only one of the three has a switch in this plugin. The other two are
      // wp-config.php and the user's role, and saying otherwise would send
      // people looking for a setting that is not there.
      'fixable_in_settings' => $reason === self::REASON_TOKEN_WRITES_OFF,
    ];
  }

  /**
   * Whether an API token (no user session) may write snippets. Off by default.
   */
  public function tokenWritesEnabled(): bool {
    return (bool) get_option(self::OPTION_TOKEN_WRITES, false);
  }

  /**
   * True when the site has switched off editing code from the dashboard.
   *
   * Note this is DISALLOW_FILE_EDIT alone. DISALLOW_FILE_MODS is a different
   * statement — see the class comment — and is handled in capabilityAllows().
   */
  public function fileEditingDisabled(): bool {
    return defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;
  }

  /**
   * True when the site blocks plugin installs and updates from the dashboard.
   */
  public function fileModsDisabled(): bool {
    return defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS;
  }

  /**
   * Whether the signed-in user's role lets them write a snippet.
   */
  private function capabilityAllows(): bool {
    $capability = $this->capability();

    if (current_user_can($capability)) {
      return true;
    }

    // The managed-host case: `edit_plugins` was denied only because the site
    // does not install plugins from the dashboard, which says nothing about
    // whether an administrator may run code. Multisite stays strict — there
    // `edit_plugins` is a super-admin power, and a site admin should not be
    // able to execute PHP across the network by writing a snippet.
    return $capability === self::DEFAULT_CAPABILITY
      && $this->fileModsDisabled()
      && !$this->fileEditingDisabled()
      && !is_multisite()
      && current_user_can('manage_options');
  }

  private function reasonMessage(string $reason): string {
    return match ($reason) {
      self::REASON_FILE_EDIT_DISABLED => __('Code Glue is unavailable: this site sets DISALLOW_FILE_EDIT in wp-config.php, which turns off editing code from the dashboard. That is a server-level choice and nothing in this plugin can override it — a site owner who wants Code Glue here has to change it in wp-config.php. Snippets that are already assigned keep running.', 'flowsystems-webhook-actions'),
      self::REASON_TOKEN_WRITES_OFF   => __('This API token cannot write Code Glue. A token that could would be able to run arbitrary PHP on this site, so it is off by default — turn on "Let API tokens write Code Glue" in Settings to enable it.', 'flowsystems-webhook-actions'),
      default                         => sprintf(
        /* translators: %s: WordPress capability name, e.g. edit_plugins. */
        __('Writing Code Glue runs PHP on this site, so it needs the "%s" capability — the same one WordPress requires to edit plugin code. Ask an administrator to make the change, or to grant your role that capability.', 'flowsystems-webhook-actions'),
        $this->capability()
      ),
    };
  }

  private function capability(): string {
    /**
     * Capability required to write a Code Glue snippet.
     *
     * Snippets are executed as PHP, so this defaults to `edit_plugins` — the
     * capability WordPress uses for "may run code they typed". Lower it only on
     * a site where you trust every user it would let in.
     *
     * @param string $capability Default `edit_plugins`.
     */
    return (string) apply_filters('fswa_glue_capability', self::DEFAULT_CAPABILITY);
  }
}
