<?php
/**
 * Staff page — chatbot history
 *
 * System -> Chatbot: history. Everything on this page is read-only: the log
 * is append-only and this page only filters, pages and exports it. Fixing an
 * answer happens on the review page, which writes the overlay file instead.
 *
 * @license GPLv3
 */

defined('INDEX_AUTH') or die('Direct access is not allowed');

require_once __DIR__ . '/../bootstrap.php';

use Chatbot\Actor;
use Chatbot\Config;
use Chatbot\History;
use Chatbot\Lang;
use Chatbot\LogStore;

$chatbotConfig = Config::all();
$chatbotLocale = Lang::locale($chatbotConfig);
$chatbotActor = Actor::fromAdmin();

if (!History::allowed($chatbotActor, $chatbotConfig)) {
    echo '<div class="alert alert-warning">' . htmlspecialchars(Lang::t($chatbotLocale, 'channel_unauthorized'), ENT_QUOTES, 'UTF-8') . '</div>';

    return;
}

$chatbotLog = new LogStore($chatbotConfig);
$chatbotFilters = History::filters($_GET);
$chatbotBase = History::baseUrl((string)($_SERVER['REQUEST_URI'] ?? 'index.php'));
$e = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

// ---- CSV export ---------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chatbot-log-' . date('Ymd') . '.csv"');
        header('X-Content-Type-Options: nosniff');
    }

    echo History::csv($chatbotLog->records(array_merge($chatbotFilters, ['limit' => History::CSV_LIMIT])));
    exit;
}

$chatbotSummary = History::summary($chatbotLog, $chatbotFilters);
$chatbotPage = History::page($chatbotLog, $chatbotFilters);
$chatbotDetail = null;

if (isset($_GET['req']) && LogStore::validReq((string)$_GET['req'])) {
    $found = $chatbotLog->records(['req' => (string)$_GET['req'], 'since' => 0, 'limit' => 1]);
    $chatbotDetail = $found[0] ?? null;
}

?>
<link rel="stylesheet" href="<?php echo $e(SWB . 'plugins/chatbot/assets/chatbot.css'); ?>">

<div class="chatbot-admin">
    <h1><?php echo $e(Lang::t($chatbotLocale, 'history_title')); ?></h1>

    <p class="chatbot-admin__numbers">
        <?php
        echo $e(Lang::t($chatbotLocale, 'history_total', ['count' => $chatbotSummary['total']])) . ' · '
            . $e(Lang::t($chatbotLocale, 'history_answered', ['pct' => $chatbotSummary['answered_pct']])) . ' · '
            . $e(Lang::t($chatbotLocale, 'history_unanswered', ['count' => $chatbotSummary['unanswered']])) . ' · '
            . $e(Lang::t($chatbotLocale, 'history_feedback', [
                'up' => $chatbotSummary['feedback_up'],
                'down' => $chatbotSummary['feedback_down'],
            ])) . ' · '
            . $e(Lang::t($chatbotLocale, 'history_avg', ['ms' => $chatbotSummary['avg_ms']]));
        ?>
    </p>

    <form method="get" class="chatbot-admin__filters">
        <?php
        // Keep whatever the admin template uses to route to this page.
        foreach ($_GET as $chatbotKey => $chatbotValue) {
            if (in_array($chatbotKey, ['days', 'surface', 'role', 'answered', 'feedback', 'q', 'page', 'req', 'export'], true)) {
                continue;
            }

            if (is_scalar($chatbotValue)) {
                echo '<input type="hidden" name="' . $e($chatbotKey) . '" value="' . $e($chatbotValue) . '">';
            }
        }
        ?>
        <label><?php echo $e(Lang::t($chatbotLocale, 'history_from')); ?>
            <select name="days">
                <?php foreach ([1, 7, 30, 90] as $chatbotDays) { ?>
                    <option value="<?php echo $chatbotDays; ?>"<?php echo $chatbotFilters['days'] === $chatbotDays ? ' selected' : ''; ?>>
                        <?php echo $e($chatbotDays . ' ' . Lang::t($chatbotLocale, 'history_from')); ?>
                    </option>
                <?php } ?>
            </select>
        </label>
        <label><?php echo $e(Lang::t($chatbotLocale, 'history_surface')); ?>
            <select name="surface">
                <option value="">*</option>
                <?php foreach (['opac', 'admin', 'whatsapp', 'telegram', 'cli'] as $chatbotSurface) { ?>
                    <option value="<?php echo $e($chatbotSurface); ?>"<?php echo ($chatbotFilters['surface'] ?? '') === $chatbotSurface ? ' selected' : ''; ?>><?php echo $e($chatbotSurface); ?></option>
                <?php } ?>
            </select>
        </label>
        <label><?php echo $e(Lang::t($chatbotLocale, 'history_role')); ?>
            <select name="role">
                <option value="">*</option>
                <?php foreach (['guest', 'member', 'staff'] as $chatbotRole) { ?>
                    <option value="<?php echo $e($chatbotRole); ?>"<?php echo ($chatbotFilters['role'] ?? '') === $chatbotRole ? ' selected' : ''; ?>><?php echo $e($chatbotRole); ?></option>
                <?php } ?>
            </select>
        </label>
        <label><?php echo $e(Lang::t($chatbotLocale, 'history_answered_only')); ?>
            <select name="answered">
                <option value="">*</option>
                <option value="1"<?php echo (isset($chatbotFilters['answered']) && $chatbotFilters['answered']) ? ' selected' : ''; ?>><?php echo $e(Lang::t($chatbotLocale, 'history_answered_only')); ?></option>
                <option value="0"<?php echo (isset($chatbotFilters['answered']) && !$chatbotFilters['answered']) ? ' selected' : ''; ?>><?php echo $e(Lang::t($chatbotLocale, 'history_unanswered_only')); ?></option>
            </select>
        </label>
        <label><?php echo $e(Lang::t($chatbotLocale, 'history_feedback')); ?>
            <select name="feedback">
                <option value="">*</option>
                <option value="down"<?php echo ($chatbotFilters['feedback'] ?? '') === 'down' ? ' selected' : ''; ?>><?php echo $e(Lang::t($chatbotLocale, 'history_feedback_down')); ?></option>
                <option value="up"<?php echo ($chatbotFilters['feedback'] ?? '') === 'up' ? ' selected' : ''; ?>><?php echo $e(Lang::t($chatbotLocale, 'ui_feedback_up')); ?></option>
            </select>
        </label>
        <label><?php echo $e(Lang::t($chatbotLocale, 'history_search')); ?>
            <input type="search" name="q" value="<?php echo $e($chatbotFilters['q'] ?? ''); ?>" maxlength="60">
        </label>
        <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'history_filter')); ?></button>
        <a class="btn btn-default btn-sm" href="<?php echo $e(History::withQuery($chatbotBase, 'export', 'csv')); ?>"><?php echo $e(Lang::t($chatbotLocale, 'history_export')); ?></a>
    </form>

    <?php if (!empty($chatbotSummary['top_unanswered'])) { ?>
        <div class="chatbot-admin__misses">
            <h2><?php echo $e(Lang::t($chatbotLocale, 'history_misses')); ?></h2>
            <ol>
                <?php foreach ($chatbotSummary['top_unanswered'] as $chatbotMiss) { ?>
                    <li><?php echo $e($chatbotMiss['q'] !== '' ? $chatbotMiss['q'] : '(not stored)'); ?>
                        <small>× <?php echo (int)$chatbotMiss['count']; ?></small></li>
                <?php } ?>
            </ol>
        </div>
    <?php } ?>

    <?php if (is_array($chatbotDetail)) { ?>
        <div class="chatbot-admin__detail">
            <h2><?php echo $e(Lang::t($chatbotLocale, 'history_detail')); ?> — <code><?php echo $e($chatbotDetail['req'] ?? ''); ?></code></h2>
            <table class="chatbot-history">
                <tbody>
                <?php
                $chatbotFields = [
                    'history_request' => $chatbotDetail['req'] ?? '',
                    'history_question' => $chatbotDetail['q'] ?? '',
                    'history_intent' => $chatbotDetail['intent'] ?? '',
                    'history_surface' => $chatbotDetail['surface'] ?? '',
                    'history_role' => $chatbotDetail['role'] ?? '',
                    'history_ms' => (string)($chatbotDetail['ms'] ?? ''),
                    'history_feedback' => $chatbotDetail['feedback'] ?? '',
                    'history_from' => $chatbotDetail['ts'] ?? '',
                ];

                foreach ($chatbotFields as $chatbotKey => $chatbotValue) {
                    echo '<tr><th>' . $e(Lang::t($chatbotLocale, $chatbotKey)) . '</th><td>' . $e($chatbotValue) . '</td></tr>';
                }

                if (!empty($chatbotDetail['guard'])) {
                    echo '<tr><th>guard</th><td>' . $e($chatbotDetail['guard']) . '</td></tr>';
                }

                if (!empty($chatbotDetail['err'])) {
                    echo '<tr><th>error</th><td>' . $e($chatbotDetail['err']) . '</td></tr>';
                }
                ?>
                </tbody>
            </table>
            <p><a href="<?php echo $e($chatbotBase); ?>"><?php echo $e(Lang::t($chatbotLocale, 'history_back')); ?></a></p>
        </div>
    <?php } ?>

    <?php if (empty($chatbotPage['rows'])) { ?>
        <p><?php echo $e(Lang::t($chatbotLocale, 'history_none')); ?></p>
    <?php } else { ?>
        <?php echo History::table($chatbotPage['rows'], $chatbotLocale, $chatbotBase); ?>
        <p class="chatbot-admin__pager">
            <?php
            echo $e(Lang::t($chatbotLocale, 'history_page', [
                'page' => $chatbotPage['page'],
                'pages' => $chatbotPage['pages'],
            ]));
            ?>
            <?php if ($chatbotPage['page'] > 1) { ?>
                <a class="btn btn-default btn-sm" href="<?php echo $e(History::withQuery($chatbotBase, 'page', (string)($chatbotPage['page'] - 1))); ?>"><?php echo $e(Lang::t($chatbotLocale, 'history_prev')); ?></a>
            <?php } ?>
            <?php if ($chatbotPage['page'] < $chatbotPage['pages']) { ?>
                <a class="btn btn-default btn-sm" href="<?php echo $e(History::withQuery($chatbotBase, 'page', (string)($chatbotPage['page'] + 1))); ?>"><?php echo $e(Lang::t($chatbotLocale, 'history_next')); ?></a>
            <?php } ?>
        </p>
    <?php } ?>
</div>
