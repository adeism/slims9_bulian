<?php
/**
 * OPAC chat page — guests and logged-in members
 *
 * Reached through `index.php?p=chatbot` (registered by
 * `plugins/chatbot/chatbot.plugin.php`) and through
 * `index.php?p=chatbot&action=ask` for the JSON endpoint.
 *
 * The role is read from the memberArea session: with `$_SESSION['mid']` the
 * visitor is a member, without it a guest. Nothing in the request can
 * change that.
 *
 * @license GPLv3
 */

defined('INDEX_AUTH') or die('Direct access is not allowed');

require_once __DIR__ . '/../bootstrap.php';

use Chatbot\Actor;
use Chatbot\Config;
use Chatbot\Http;
use Chatbot\Lang;
use Chatbot\View;

$chatbotConfig = Config::all();
$chatbotLocale = Lang::locale($chatbotConfig);

if (!Config::enabled('opac')) {
    echo '<div class="alert alert-info">' . htmlspecialchars(Lang::t($chatbotLocale, 'ui_disabled'), ENT_QUOTES, 'UTF-8') . '</div>';

    return;
}

$chatbotActor = Actor::fromOpac();

if (Http::isAsk()) {
    Http::handle($chatbotActor, 'opac');
}

echo View::render(
    $chatbotActor,
    'opac',
    SWB . 'index.php?p=chatbot&action=ask',
    SWB . 'plugins/chatbot/assets/',
    $chatbotConfig
);
