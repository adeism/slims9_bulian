<?php
/**
 * Chatbot plugin bootstrap
 *
 * Registers a small autoloader for the `Chatbot\` namespace so that the
 * plugin works on a stock SLiMS install without touching composer.json.
 *
 * Every entry point requires this file first:
 *
 *   - plugins/chatbot/chatbot.plugin.php   (menu registration)
 *   - plugins/chatbot/pages/*.php          (screens + JSON endpoint)
 *   - plugins/Commands/Chatbot*.php        (console commands)
 *
 * @license GPLv3
 */

// This file only registers an autoloader, so it is safe to require from
// the plugin loader, a page, or a console command. Every *page* still
// checks INDEX_AUTH itself before rendering anything.

if (!function_exists('chatbot_autoload')) {
    /**
     * Map `Chatbot\Support\Engine` to plugins/chatbot/Support/Engine.php
     *
     * @param string $class
     * @return void
     */
    function chatbot_autoload($class)
    {
        $prefix = 'Chatbot\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = __DIR__ . '/Support/' . $relative . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }

    spl_autoload_register('chatbot_autoload');
}
