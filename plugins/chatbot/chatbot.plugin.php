<?php
/**
 * Plugin Name: Chatbot
 * Plugin URI: https://github.com/adeism/slims9_bulian
 * Description: A question-and-answer assistant for guests, members and staff, built on the same CLI-Anything service as the tarsius commands. Guests may search the catalogue, members see only their own loans, staff get circulation and gated transactions that always require confirmation.
 * Version: 1.0.0
 * Author: SLiMS Bulian contributors
 * Author URI: https://slims.web.id
 */

use SLiMS\Plugins;

// Loaded by Plugins::loadPlugins() from sysconfig.inc.php. When this file is
// opened directly the plugin API is not there yet, so bail out quietly.
if (!class_exists(Plugins::class)) {
    return;
}

require_once __DIR__ . '/bootstrap.php';

$chatbotConfig = \Chatbot\Config::all();

if (empty($chatbotConfig['enabled'])) {
    return;
}

$plugin = Plugins::getInstance();

/*
 * OPAC menu: `index.php?p=chatbot`
 * Guests and members share the same page; the session decides which role
 * the engine runs with.
 */
if (!empty($chatbotConfig['opac'])) {
    $plugin->registerMenu('opac', 'Chatbot', __DIR__ . '/pages/opac.php');
}

/*
 * Staff menu: System -> Chatbot
 * The page requires admin/default/session_check.inc.php, so only an
 * authenticated staff session can reach it.
 */
if (!empty($chatbotConfig['admin'])) {
    $plugin->registerMenu('system', 'Chatbot', __DIR__ . '/pages/admin.php');
}

/*
 * Staff history: `System -> Chatbot history`.
 * Read-only view over the append-only conversation log, so problems can be
 * found without reading files on the server.
 */
if (!empty($chatbotConfig['admin']) && !empty($chatbotConfig['admin_history'])) {
    $plugin->registerMenu('system', 'Chatbot history', __DIR__ . '/pages/history.php');
}

/*
 * Staff review: `System -> Chatbot review`.
 * Fixes are written to files/chatbot/faq.local.php; the content file is
 * never touched from the browser.
 */
if (!empty($chatbotConfig['admin']) && !empty($chatbotConfig['admin_overlay'])) {
    $plugin->registerMenu('system', 'Chatbot review', __DIR__ . '/pages/review.php');
}

/*
 * Staff rules: `System -> Chatbot rules`.
 * The blocked-question and forbidden-answer lists; empty and inert until an
 * administrator adds a rule and turns guard.enabled on.
 */
if (!empty($chatbotConfig['admin']) && !empty($chatbotConfig['admin_rules'])) {
    $plugin->registerMenu('system', 'Chatbot rules', __DIR__ . '/pages/rules.php');
}

/*
 * Messaging channel webhook: WhatsApp Cloud, Telegram, or a local gateway
 * posting {phone, message}. Inert unless it is enabled *and* a token is set,
 * exactly like the CLI-Anything HTTP bridge.
 */
$chatbotChannel = \Chatbot\Channel::config($chatbotConfig);

if (!empty($chatbotChannel['enabled']) && trim((string)$chatbotChannel['token']) !== '') {
    $plugin->register('custom_api_route', function ($router) {
        $router->map('POST', '/chatbot/v1/message', function () {
            \Chatbot\Webhook::dispatch();
        });

        // WhatsApp Cloud API subscription handshake.
        $router->map('GET', '/chatbot/v1/message', function () {
            \Chatbot\Webhook::verify();
        });
    });
}
