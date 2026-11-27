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
