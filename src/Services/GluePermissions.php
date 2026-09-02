<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Who may write Code Glue.
 *
 * A snippet is PHP that {@see SnippetExecutor} runs with `eval()`, so writing
 * one is the same act as editing a plugin file — and it is gated the same way,
 * on `edit_plugins` rather than `manage_options`. That capability is also where
 * WordPress already honours DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS, so a site
 * that has turned off in-dashboard code editing turns off Code Glue with it.
 *
 * The second door is an API token. `AuthHelper::dualAuth` accepts one in place
 * of an admin session, which on a plugin anyone can install would make a
 * full-scope bearer token a remote-code-execution primitive. So token-carried
 * snippet writes are OFF unless the site owner deliberately turns them on —
 * which is what an external AI agent (MCP, Claude Code, Cursor) needs before it
 * can write glue. Reading snippets is unaffected.
 */
class GluePermissions {
  /** Option gating snippet writes that authenticate with an API token rather than a user session. */
  public const OPTION_TOKEN_WRITES = 'fswa_glue_token_writes';

  /** The capability a user session must hold to write a snippet. */
  private const DEFAULT_CAPABILITY = 'edit_plugins';

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
        __('Code Glue is unavailable: this site has disabled editing code from the dashboard (DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS). Existing snippets keep running; new ones cannot be written here.', 'flowsystems-webhook-actions'),
        ['status' => 403]
      );
    }

    // A signed-in user is judged on their capability, never on the token they
    // may also be carrying — a low-privileged user cannot borrow one to get in.
    if (get_current_user_id() > 0) {
      return current_user_can($this->capability())
        ? true
        : new WP_Error(
          'fswa_glue_forbidden',
          sprintf(
            /* translators: %s: WordPress capability name, e.g. edit_plugins. */
            __('Writing Code Glue runs PHP on this site, so it needs the "%s" capability — the same one WordPress requires to edit plugin code.', 'flowsystems-webhook-actions'),
            $this->capability()
          ),
          ['status' => 403]
        );
    }

    if ($this->tokenWritesEnabled()) {
      return true;
    }

    return new WP_Error(
      'fswa_glue_token_writes_disabled',
      __('This API token cannot write Code Glue. A token that could would be able to run arbitrary PHP on this site, so it is off by default — turn on "Allow API tokens to write Code Glue" in Settings to enable it.', 'flowsystems-webhook-actions'),
      ['status' => 403]
    );
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
   * `current_user_can('edit_plugins')` already returns false in that case, but
   * checking the constants directly lets the refusal say why, and keeps the
   * guarantee if `fswa_glue_capability` is pointed at some other capability.
   */
  public function fileEditingDisabled(): bool {
    return (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT)
      || (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS);
  }

  /**
   * Whether Code Glue can be written at all in this request — the same answer
   * as canWrite(), as a plain boolean, for shaping UI rather than refusing.
   */
  public function canWriteNow(): bool {
    return $this->canWrite() === true;
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
