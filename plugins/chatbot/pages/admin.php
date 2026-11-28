<?php
/**
 * Staff chat page
 *
 * Registered under the System module, so the page is reached through
 * `admin/plugin_container.php?mod=system&id=<hash>`. The admin session and
 * session check run first: by the time the chat renders, the visitor is an
 * authenticated staff member, and the role is read from `$_SESSION['uid']`.
 *
 * The JSON endpoint is the same file with `&action=ask`.
 *
 * @license GPLv3
 */

defined('INDEX_AUTH') or die('Direct access is not allowed');

require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';

require_once __DIR__ . '/../bootstrap.php';

use Chatbot\Actor;
use Chatbot\Config;
use Chatbot\Http;
use Chatbot\Lang;
use Chatbot\View;

$chatbotConfig = Config::all();
$chatbotLocale = Lang::locale($chatbotConfig);

if (!Config::enabled('admin')) {
    echo '<div class="alert alert-info">' . htmlspecialchars(Lang::t($chatbotLocale, 'ui_disabled'), ENT_QUOTES, 'UTF-8') . '</div>';

    return;
}

$chatbotActor = Actor::fromAdmin();

if (Http::isAsk()) {
    Http::handle($chatbotActor, 'admin');
}

$chatbotModule = isset($_GET['mod']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['mod']) : 'system';
$chatbotId = isset($_GET['id']) ? preg_replace('/[^A-Fa-f0-9]/', '', (string)$_GET['id']) : '';

$chatbotFaqItems = \Chatbot\Faq::items($chatbotConfig);
$chatbotFaqSource = \Chatbot\Faq::source();

$chatbotEndpoint = AWB . 'plugin_container.php?mod=' . rawurlencode($chatbotModule)
    . '&id=' . rawurlencode($chatbotId)
    . '&action=ask';

?>
<div class="chatbot-wrap">
    <p class="chatbot-faq-note">
        <?php
        echo htmlspecialchars(
            Lang::t($chatbotLocale, 'ui_faq_note', [
                'count' => count($chatbotFaqItems),
                'file' => $chatbotFaqSource === '' ? '-' : $chatbotFaqSource,
            ]),
            ENT_QUOTES,
            'UTF-8'
        );
        ?>
    </p>
    <?php
    echo View::render(
        $chatbotActor,
        'admin',
        $chatbotEndpoint,
        SWB . 'plugins/chatbot/assets/',
        $chatbotConfig
    );
    ?>
</div>
