<?php
/**
 * Staff page — review and correct answers
 *
 * System -> Chatbot: review. Two things happen here:
 *
 *   1. conversations that were not answered (or were marked unhelpful) are
 *      listed, so a librarian can see what people actually asked;
 *   2. corrections are written to the overlay file — extra keywords, a new
 *      entry (draft until activated), an entry hidden — never to the content
 *      file itself.
 *
 * `preview` runs the matcher against the candidate overlay without writing
 * anything, so "what will a guest see?" is answered before saving.
 *
 * @license GPLv3
 */

defined('INDEX_AUTH') or die('Direct access is not allowed');

require_once __DIR__ . '/../bootstrap.php';

use Chatbot\Admin;
use Chatbot\Actor;
use Chatbot\Config;
use Chatbot\Faq;
use Chatbot\Http;
use Chatbot\Lang;
use Chatbot\LogStore;
use Chatbot\Overlay;

$chatbotConfig = Config::all();
$chatbotLocale = Lang::locale($chatbotConfig);
$chatbotActor = Actor::fromAdmin();
$e = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

if (!Admin::can($chatbotActor, $chatbotConfig, 'admin_overlay')) {
    echo '<div class="alert alert-warning">' . $e(Lang::t($chatbotLocale, 'channel_unauthorized')) . '</div>';

    return;
}

$chatbotOverlay = new Overlay($chatbotConfig);
$chatbotActorLabel = Admin::label($chatbotActor);
$chatbotNotice = '';
$chatbotPreview = null;
$chatbotMode = '';

$chatbotItems = Faq::items($chatbotConfig);
$chatbotOverlayData = $chatbotOverlay->load();
$chatbotLog = new LogStore($chatbotConfig);

// ---- actions -------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $chatbotAction = preg_replace('/[^a-z_]/', '', (string)$_POST['action']);

    if (!Http::validCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $chatbotNotice = Lang::t($chatbotLocale, 'ui_session_expired');
    } else {
        $chatbotId = trim((string)($_POST['id'] ?? ''));
        $chatbotPhrase = trim((string)($_POST['phrase'] ?? ''));
        $chatbotMessage = trim((string)($_POST['message'] ?? ''));

        if ($chatbotAction === 'revert') {
            $result = $chatbotOverlay->revert($chatbotActorLabel);
            $chatbotNotice = $result['ok'] ? Lang::t($chatbotLocale, 'review_reverted') : $result['error'];
            $chatbotOverlayData = $chatbotOverlay->load();
        } elseif ($chatbotAction === 'keyword') {
            if ($chatbotPhrase !== '' && $chatbotId !== '') {
                $candidate = $chatbotOverlayData;
                $candidate['keywords'][$chatbotId][] = $chatbotPhrase;
                $chatbotPreview = Faq::match($chatbotPhrase, $chatbotConfig, $chatbotLocale, $candidate);
                $chatbotMode = 'preview';
            }

            if (!isset($_POST['preview_only'])) {
                $result = $chatbotOverlay->addKeyword($chatbotId, $chatbotPhrase, $chatbotActorLabel);
                $chatbotNotice = $result['ok'] ? Lang::t($chatbotLocale, 'review_saved') : $result['error'];
                $chatbotOverlayData = $chatbotOverlay->load();
                $chatbotItems = Faq::items($chatbotConfig, $chatbotOverlayData);
            }
        } elseif ($chatbotAction === 'entry') {
            $candidateEntry = [
                'id' => trim((string)($_POST['entry_id'] ?? '')),
                'question' => trim((string)($_POST['question'] ?? '')),
                'keywords' => trim((string)($_POST['keywords'] ?? '')),
                'answer' => trim((string)($_POST['answer'] ?? '')),
                'answer_en' => trim((string)($_POST['answer_en'] ?? '')),
                'link' => trim((string)($_POST['link'] ?? '')),
            ];

            if (!empty($candidateEntry['question'])) {
                $candidate = $chatbotOverlayData;
                $candidateEntry['status'] = 'active';
                $candidate['entries'][] = $candidateEntry;
                $chatbotPreview = Faq::match($candidateEntry['question'], $chatbotConfig, $chatbotLocale, $candidate);
            }

            if (!isset($_POST['preview_only'])) {
                $result = $chatbotOverlay->addEntry($candidateEntry, $chatbotActorLabel, !empty($_POST['activate']));
                $chatbotNotice = $result['ok'] ? Lang::t($chatbotLocale, 'review_saved') : $result['error'];
                $chatbotOverlayData = $chatbotOverlay->load();
                $chatbotItems = Faq::items($chatbotConfig, $chatbotOverlayData);
            }
        } elseif ($chatbotAction === 'activate' || $chatbotAction === 'deactivate') {
            $result = $chatbotOverlay->setActive($chatbotId, $chatbotAction === 'activate', $chatbotActorLabel);
            $chatbotNotice = $result['ok'] ? Lang::t($chatbotLocale, 'review_saved') : $result['error'];
            $chatbotOverlayData = $chatbotOverlay->load();
            $chatbotItems = Faq::items($chatbotConfig, $chatbotOverlayData);
        } elseif ($chatbotAction === 'hide' || $chatbotAction === 'unhide') {
            $result = $chatbotOverlay->setHidden($chatbotId, $chatbotAction === 'hide', $chatbotActorLabel);
            $chatbotNotice = $result['ok'] ? Lang::t($chatbotLocale, 'review_saved') : $result['error'];
            $chatbotOverlayData = $chatbotOverlay->load();
            $chatbotItems = Faq::items($chatbotConfig, $chatbotOverlayData);
        } elseif ($chatbotAction === 'preview') {
            if ($chatbotMessage !== '') {
                $chatbotPreview = Faq::match($chatbotMessage, $chatbotConfig, $chatbotLocale);
                $chatbotMode = 'preview';
            }
        }
    }
}

// ---- the queue -----------------------------------------------------
$chatbotOpen = [];
$chatbotDown = [];

if (!empty($chatbotConfig['log']['enabled'])) {
    $chatbotOpen = $chatbotLog->records([
        'since' => time() - 30 * 86400,
        'answered' => false,
        'limit' => 25,
    ]);
    $chatbotDown = $chatbotLog->records([
        'since' => time() - 30 * 86400,
        'feedback' => 'down',
        'limit' => 25,
    ]);
}

$chatbotIds = array_values(array_map(function ($item) {
    return (string)($item['id'] ?? '');
}, $chatbotItems));

$chatbotAudit = $chatbotOverlay->audit(15);

?>
<link rel="stylesheet" href="<?php echo $e(SWB . 'plugins/chatbot/assets/chatbot.css'); ?>">

<div class="chatbot-admin">
    <h1><?php echo $e(Lang::t($chatbotLocale, 'review_title')); ?></h1>

    <p class="chatbot-admin__numbers">
        <?php
        echo $e(Lang::t($chatbotLocale, 'review_source', [
            'file' => Faq::source() === '' ? '-' : Faq::source(),
        ])) . ' · ' . $e(Lang::t($chatbotLocale, 'review_overlay', [
            'file' => $chatbotOverlay->path(),
            'version' => (int)($chatbotOverlayData['version'] ?? 0),
        ]));
        ?>
    </p>

    <?php if ($chatbotNotice !== '') { ?>
        <div class="alert alert-info"><?php echo $e($chatbotNotice); ?></div>
    <?php } ?>

    <?php if (is_array($chatbotPreview)) { ?>
        <div class="chatbot-admin__detail">
            <h2><?php echo $e(Lang::t($chatbotLocale, 'review_preview')); ?></h2>
            <p><strong><?php echo $e((string)($chatbotPreview['question'] ?? '')); ?></strong>
                (<?php echo $e((string)($chatbotPreview['id'] ?? '')); ?>, <?php echo $e((string)($chatbotPreview['score'] ?? '')); ?>)</p>
            <p><?php echo $e((string)($chatbotPreview['answer'] ?? '')); ?></p>
        </div>
    <?php } elseif ($chatbotMode === 'preview') { ?>
        <div class="alert alert-warning"><?php echo $e(Lang::t($chatbotLocale, 'review_no_match')); ?></div>
    <?php } ?>

    <div class="chatbot-admin__misses">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'review_open')); ?></h2>

        <?php if (empty($chatbotOpen)) { ?>
            <p><?php echo $e(Lang::t($chatbotLocale, 'review_open_none')); ?></p>
        <?php } else { ?>
            <ul class="chatbot-admin__queue">
                <?php foreach ($chatbotOpen as $chatbotRow) { ?>
                    <li>
                        <span class="chatbot-admin__q"><?php echo $e($chatbotRow['q'] !== '' ? $chatbotRow['q'] : '(not stored)'); ?></span>
                        <small><?php echo $e($chatbotRow['ts'] ?? ''); ?> · <?php echo $e($chatbotRow['surface'] ?? ''); ?> · <?php echo $e($chatbotRow['role'] ?? ''); ?></small>
                        <form method="post" class="chatbot-admin__inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
                            <input type="hidden" name="action" value="keyword">
                            <select name="id">
                                <?php foreach ($chatbotIds as $chatbotItemId) { ?>
                                    <option value="<?php echo $e($chatbotItemId); ?>"><?php echo $e($chatbotItemId); ?></option>
                                <?php } ?>
                            </select>
                            <input type="text" name="phrase" value="<?php echo $e($chatbotRow['q'] ?? ''); ?>" maxlength="60" size="30">
                            <button type="submit" class="btn btn-default btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'review_add_keyword')); ?></button>
                            <button type="submit" name="preview_only" value="1" class="btn btn-link btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'review_preview_btn')); ?></button>
                        </form>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>
    </div>

    <?php if (!empty($chatbotDown)) { ?>
        <div class="chatbot-admin__misses">
            <h2><?php echo $e(Lang::t($chatbotLocale, 'history_feedback_down')); ?></h2>
            <ul class="chatbot-admin__queue">
                <?php foreach ($chatbotDown as $chatbotRow) { ?>
                    <li><span class="chatbot-admin__q"><?php echo $e($chatbotRow['q'] ?? ''); ?></span>
                        <small><?php echo $e($chatbotRow['intent'] ?? ''); ?><?php echo !empty($chatbotRow['faq_id']) ? ' · ' . $e($chatbotRow['faq_id']) : ''; ?></small></li>
                <?php } ?>
            </ul>
        </div>
    <?php } ?>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'review_new')); ?></h2>
        <form method="post" class="chatbot-admin__filters">
            <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
            <input type="hidden" name="action" value="entry">
            <label>id <input type="text" name="entry_id" placeholder="wifi" maxlength="40"></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'review_question')); ?>
                <input type="text" name="question" maxlength="120" size="34" required></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'review_keywords')); ?>
                <input type="text" name="keywords" placeholder="wifi, internet, password" size="30" required></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'review_answer')); ?>
                <textarea name="answer" maxlength="500" rows="2" cols="40" required></textarea></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'review_link')); ?>
                <input type="text" name="link" placeholder="index.php?p=member" size="24"></label>
            <label class="chatbot-admin__check">
                <input type="checkbox" name="activate" value="1"> <?php echo $e(Lang::t($chatbotLocale, 'review_activate_now')); ?></label>
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'review_save')); ?></button>
            <button type="submit" name="preview_only" value="1" class="btn btn-link btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'review_preview_btn')); ?></button>
        </form>
    </div>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'review_test')); ?></h2>
        <form method="post" class="chatbot-admin__filters">
            <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
            <input type="hidden" name="action" value="preview">
            <label><?php echo $e(Lang::t($chatbotLocale, 'history_question')); ?>
                <input type="text" name="message" size="50" placeholder="jam buka" required></label>
            <button type="submit" class="btn btn-default btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'review_test_btn')); ?></button>
        </form>
    </div>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'review_entries')); ?></h2>
        <table class="chatbot-history">
            <thead><tr>
                <th>id</th>
                <th><?php echo $e(Lang::t($chatbotLocale, 'review_question')); ?></th>
                <th><?php echo $e(Lang::t($chatbotLocale, 'review_status')); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php
            foreach ((array)$chatbotOverlayData['entries'] as $chatbotEntry) {
                $chatbotEntryId = (string)($chatbotEntry['id'] ?? '');
                $chatbotIsActive = (string)($chatbotEntry['status'] ?? '') === 'active';
                echo '<tr><td>' . $e($chatbotEntryId) . '</td><td>' . $e((string)($chatbotEntry['question'] ?? '')) . '</td>';
                echo '<td>' . $e($chatbotIsActive ? Lang::t($chatbotLocale, 'review_active') : Lang::t($chatbotLocale, 'review_draft')) . '</td><td>';
                echo '<form method="post" class="chatbot-admin__inline">'
                    . '<input type="hidden" name="csrf_token" value="' . $e(Http::token()) . '">'
                    . '<input type="hidden" name="id" value="' . $e($chatbotEntryId) . '">'
                    . '<button type="submit" class="btn btn-default btn-sm" name="action" value="' . ($chatbotIsActive ? 'deactivate' : 'activate') . '">'
                    . $e($chatbotIsActive ? Lang::t($chatbotLocale, 'review_deactivate') : Lang::t($chatbotLocale, 'review_activate'))
                    . '</button>'
                    . '<button type="submit" class="btn btn-default btn-sm" name="action" value="hide">' . $e(Lang::t($chatbotLocale, 'review_hide')) . '</button>'
                    . '</form></td></tr>';
            }
            ?>
            </tbody>
        </table>
    </div>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'review_hidden')); ?></h2>
        <?php if (empty($chatbotOverlayData['hidden'])) { ?>
            <p><?php echo $e(Lang::t($chatbotLocale, 'review_hidden_none')); ?></p>
        <?php } else { ?>
            <ul>
                <?php foreach ((array)$chatbotOverlayData['hidden'] as $chatbotHidden) { ?>
                    <li>
                        <code><?php echo $e($chatbotHidden); ?></code>
                        <form method="post" class="chatbot-admin__inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
                            <input type="hidden" name="action" value="unhide">
                            <input type="hidden" name="id" value="<?php echo $e($chatbotHidden); ?>">
                            <button type="submit" class="btn btn-default btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'review_unhide')); ?></button>
                        </form>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>
    </div>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'review_audit')); ?></h2>
        <?php if (empty($chatbotAudit)) { ?>
            <p><?php echo $e(Lang::t($chatbotLocale, 'review_audit_none')); ?></p>
        <?php } else { ?>
            <table class="chatbot-history">
                <thead><tr>
                    <th>time</th><th>action</th><th>target</th><th>by</th><th>version</th>
                </tr></thead>
                <tbody>
                <?php foreach ($chatbotAudit as $chatbotRow) { ?>
                    <tr>
                        <td><?php echo $e($chatbotRow['ts'] ?? ''); ?></td>
                        <td><?php echo $e($chatbotRow['action'] ?? ''); ?></td>
                        <td><?php echo $e($chatbotRow['target'] ?? ''); ?></td>
                        <td><?php echo $e($chatbotRow['actor'] ?? ''); ?></td>
                        <td><?php echo (int)($chatbotRow['version'] ?? 0); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <form method="post" class="chatbot-admin__inline">
                <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
                <input type="hidden" name="action" value="revert">
                <button type="submit" class="btn btn-default btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'review_revert')); ?></button>
            </form>
        <?php } ?>
    </div>
</div>
