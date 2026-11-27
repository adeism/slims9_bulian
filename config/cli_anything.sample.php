<?php
/**
 * CLI-Anything configuration
 *
 * Copy this file to `config/cli_anything.php` and adjust it.
 * Every value can also be provided through the environment, which is the
 * recommended way for containers:
 *
 *   SLIMS_CLI_ANYTHING_TOKEN
 *   SLIMS_CLI_ANYTHING_ALLOW_WRITE
 *   SLIMS_CLI_ANYTHING_ALLOW_BULK
 *   SLIMS_CLI_ANYTHING_BACKUP_DIR
 *   SLIMS_CLI_ANYTHING_USER_ID
 *
 * Generate a token with:  php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
 */

return [
    /**
     * Master switch for the HTTP bridge plugin
     * (`plugins/cli_anything/cli_anything.plugin.php`).
     *
     * The `tarsius` commands do not depend on this flag; they always work
     * for whoever can already run PHP on the server.
     */
    'enabled' => true,

    /**
     * Shared secret for the HTTP bridge. An empty token disables the bridge
     * completely (no routes are registered, no endpoint answers).
     *
     * Send it as `X-CLI-Anything-Token: <token>` (or `Authorization: Bearer`).
     * Never put the token in a URL that ends up in a log or a browser
     * history: prefer the header.
     */
    'token' => '',

    /**
     * Staff account recorded in `system_log` for every write. Point this at
     * a dedicated account so agent activity is easy to filter.
     */
    'user_id' => 1,
    'user_name' => 'CLI-Anything',

    /**
     * Write gate. While false, `--commit` and POST writes fail with
     * WRITE_DISABLED and the dry run still works.
     */
    'allow_write' => false,

    /**
     * Bulk gate. Any call that would touch more than one record (for
     * example a multi-item checkout) also requires this flag, and triggers
     * a database dump before writing.
     */
    'allow_bulk' => false,

    /**
     * Mask patron e-mail, phone, fax and address in every payload.
     * Keep this on for anything that feeds an LLM.
     */
    'redact_pii' => true,

    /**
     * Allow HTTP clients to bypass the masking with `?reveal=1`.
     * Leave this off unless you really need it.
     */
    'allow_pii_reveal' => false,

    /**
     * Where automatic pre-write backups are stored.
     * Empty string = <slims>/files/backup/
     */
    'backup_dir' => '',

    /**
     * Default page size for list endpoints (hard cap: 100).
     */
    'default_limit' => 20,
];
