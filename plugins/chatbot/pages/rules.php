<?php
/**
 * Staff page — blocked questions and forbidden answers
 *
 * System -> Chatbot: rules. Two scopes:
 *
 *   question — checked before the rule table; the message is refused here
 *   answer   — checked on FAQ content and on every reply before it is shown
 *
 * Rules are intentionally boring: a phrase, a word or a small regex, a
 * category, an action and a mandatory reason. The page shows what a rule
 * would block from the library's own questions, so a rule that is too broad
 * is visible before it starts refusing normal visitors.
 *
 * @license GPLv3
 */

defined('INDEX_AUTH') or die('Direct access is not allowed');

require_once __DIR__ . '/../bootstrap.php';

use Chatbot\Admin;
use Chatbot\Actor;
use Chatbot\Config;
use Chatbot\Faq;
use Chatbot\Guard;
use Chatbot\Http;
use Chatbot\Lang;

$chatbotConfig = Config::all();
$chatbotLocale = Lang::locale($chatbotConfig);
$chatbotActor = Actor::fromAdmin();
$e = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

if (!Admin::can($chatbotActor, $chatbotConfig, 'admin_rules')) {
    echo '<div class="alert alert-warning">' . $e(Lang::t($chatbotLocale, 'channel_unauthorized')) . '</div>';

    return;
}

$chatbotActorLabel = Admin::label($chatbotActor);
$chatbotNotice = '';
$chatbotTest = null;
$chatbotTestText = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $chatbotAction = preg_replace('/[^a-z_]/', '', (string)$_POST['action']);

    if (!Http::validCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $chatbotNotice = Lang::t($chatbotLocale, 'ui_session_expired');
    } else {
        $rules = Guard::rules($chatbotConfig);

        if ($chatbotAction === 'save') {
            $candidate = [
                'id' => trim((string)($_POST['rule_id'] ?? '')),
                'scope' => (string)($_POST['scope'] ?? 'question'),
                'type' => (string)($_POST['type'] ?? 'phrase'),
                'pattern' => trim((string)($_POST['pattern'] ?? '')),
                'category' => (string)($_POST['category'] ?? 'policy'),
                'action' => (string)($_POST['action_value'] ?? 'refuse'),
                'note' => trim((string)($_POST['note'] ?? '')),
                'enabled' => true,
            ];

            $replaced = false;

            foreach ($rules as $index => $rule) {
                if ($candidate['id'] !== '' && $rule['id'] === $candidate['id']) {
                    $rules[$index] = $candidate;
                    $replaced = true;
                }
            }

            if (!$replaced) {
                $rules[] = $candidate;
            }

            $result = Guard::save($rules, $chatbotActorLabel, $chatbotConfig);
            $chatbotNotice = $result['ok'] ? Lang::t($chatbotLocale, 'rules_saved') : $result['error'];
            Guard::flush();
        } elseif ($chatbotAction === 'toggle' || $chatbotAction === 'delete') {
            $id = trim((string)($_POST['id'] ?? ''));
            $kept = [];

            foreach ($rules as $rule) {
                if ($rule['id'] !== $id) {
                    $kept[] = $rule;
                    continue;
                }

                if ($chatbotAction === 'toggle') {
                    $rule['enabled'] = empty($rule['enabled']);
                    $kept[] = $rule;
                }
            }

            $result = Guard::save($kept, $chatbotActorLabel, $chatbotConfig);
            $chatbotNotice = $result['ok']
                ? Lang::t($chatbotLocale, $chatbotAction === 'delete' ? 'rules_deleted' : 'rules_saved')
                : $result['error'];
            Guard::flush();
        } elseif ($chatbotAction === 'test') {
            $chatbotTestText = trim((string)($_POST['message'] ?? ''));

            if ($chatbotTestText !== '') {
                $chatbotTest = Guard::hits($chatbotTestText, $chatbotConfig);
            }
        } elseif ($chatbotAction === 'block' || $chatbotAction === 'out_of_scope') {
            // Quick action from the review queue.
            $phrase = trim((string)($_POST['phrase'] ?? ''));
            $rules[] = [
                'scope' => 'question',
                'type' => 'phrase',
                'pattern' => $phrase,
                'category' => $chatbotAction === 'block' ? (string)($_POST['category'] ?? 'off-topic') : 'off-topic',
                'action' => $chatbotAction === 'block' ? 'refuse' : 'fallback',
                'note' => 'From the review queue, ' . gmdate('Y-m-d'),
                'id' => '',
                'enabled' => true,
            ];

            $result = Guard::save($rules, $chatbotActorLabel, $chatbotConfig);
            $chatbotNotice = $result['ok'] ? Lang::t($chatbotLocale, 'rules_saved') : $result['error'];
            Guard::flush();
        }
    }
}

$chatbotRules = Guard::rules($chatbotConfig);
$chatbotQuestions = Faq::topics($chatbotConfig, $chatbotLocale, 20);
$chatbotGuardOn = Guard::enabled($chatbotConfig);
?>
<link rel="stylesheet" href="<?php echo $e(SWB . 'plugins/chatbot/assets/chatbot.css'); ?>">

<div class="chatbot-admin">
    <h1><?php echo $e(Lang::t($chatbotLocale, 'rules_title')); ?></h1>

    <p class="chatbot-admin__numbers"><?php echo $e(Lang::t($chatbotLocale, 'rules_intro')); ?></p>

    <?php if ($chatbotNotice !== '') { ?>
        <div class="alert alert-info"><?php echo $e($chatbotNotice); ?></div>
    <?php } ?>

    <?php if (!$chatbotGuardOn) { ?>
        <div class="alert alert-warning"><?php echo $e(Lang::t($chatbotLocale, 'rules_guard_off')); ?></div>
    <?php } ?>

    <?php if (is_array($chatbotTest)) { ?>
        <div class="chatbot-admin__detail">
            <h2><?php echo $e(Lang::t($chatbotLocale, 'rules_test_result')); ?></h2>
            <p><code><?php echo $e($chatbotTestText); ?></code></p>
            <?php if (empty($chatbotTest)) { ?>
                <p><?php echo $e(Lang::t($chatbotLocale, 'rules_no_hits')); ?></p>
            <?php } else { ?>
                <ul>
                    <?php foreach ($chatbotTest as $chatbotHit) { ?>
                        <li><code><?php echo $e($chatbotHit['id']); ?></code>
                            — <?php echo $e($chatbotHit['scope'] . ' / ' . $chatbotHit['type'] . ' / ' . $chatbotHit['action']); ?>
                            (<em><?php echo $e($chatbotHit['pattern']); ?></em>)</li>
                    <?php } ?>
                </ul>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'rules_add')); ?></h2>
        <form method="post" class="chatbot-admin__filters">
            <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
            <input type="hidden" name="action" value="save">
            <label>id <input type="text" name="rule_id" maxlength="24" placeholder="g-001"></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'rules_scope')); ?>
                <select name="scope">
                    <option value="question">question</option>
                    <option value="answer">answer</option>
                </select></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'rules_type')); ?>
                <select name="type">
                    <option value="phrase">phrase</option>
                    <option value="word">word</option>
                    <option value="regex">regex</option>
                </select></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'rules_pattern')); ?>
                <input type="text" name="pattern" maxlength="120" size="34" required></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'rules_category')); ?>
                <select name="category">
                    <?php foreach (Guard::CATEGORIES as $chatbotCategory) { ?>
                        <option value="<?php echo $e($chatbotCategory); ?>"><?php echo $e($chatbotCategory); ?></option>
                    <?php } ?>
                </select></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'rules_action')); ?>
                <select name="action_value">
                    <option value="refuse">refuse</option>
                    <option value="fallback">fallback</option>
                    <option value="hide">hide</option>
                </select></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'rules_note')); ?>
                <input type="text" name="note" maxlength="160" size="34" required></label>
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'rules_save')); ?></button>
        </form>
    </div>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'rules_test')); ?></h2>
        <form method="post" class="chatbot-admin__filters">
            <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
            <input type="hidden" name="action" value="test">
            <label><?php echo $e(Lang::t($chatbotLocale, 'history_question')); ?>
                <input type="text" name="message" size="50" required></label>
            <button type="submit" class="btn btn-default btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'rules_test_btn')); ?></button>
        </form>
    </div>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'rules_list')); ?></h2>

        <?php if (empty($chatbotRules)) { ?>
            <p><?php echo $e(Lang::t($chatbotLocale, 'rules_none')); ?></p>
        <?php } else { ?>
            <table class="chatbot-history">
                <thead><tr>
                    <th>id</th>
                    <th><?php echo $e(Lang::t($chatbotLocale, 'rules_scope')); ?></th>
                    <th><?php echo $e(Lang::t($chatbotLocale, 'rules_pattern')); ?></th>
                    <th><?php echo $e(Lang::t($chatbotLocale, 'rules_action')); ?></th>
                    <th><?php echo $e(Lang::t($chatbotLocale, 'rules_impact')); ?></th>
                    <th><?php echo $e(Lang::t($chatbotLocale, 'rules_note')); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php
                foreach ($chatbotRules as $chatbotRule) {
                    $impact = Guard::impact($chatbotRule, $chatbotQuestions);
                    $enabled = !empty($chatbotRule['enabled']);
                    echo '<tr' . ($enabled ? '' : ' class="chatbot-admin__off"') . '>';
                    echo '<td><code>' . $e($chatbotRule['id']) . '</code></td>';
                    echo '<td>' . $e($chatbotRule['scope']) . '</td>';
                    echo '<td><code>' . $e($chatbotRule['pattern']) . '</code><br><small>' . $e($chatbotRule['type']) . '</small></td>';
                    echo '<td>' . $e($chatbotRule['action']) . '<br><small>' . $e($chatbotRule['category']) . '</small></td>';
                    echo '<td>' . (int)$impact . ' / ' . count($chatbotQuestions) . '</td>';
                    echo '<td>' . $e($chatbotRule['note']) . '</td>';
                    echo '<td><form method="post" class="chatbot-admin__inline">'
                        . '<input type="hidden" name="csrf_token" value="' . $e(Http::token()) . '">'
                        . '<input type="hidden" name="id" value="' . $e($chatbotRule['id']) . '">'
                        . '<button type="submit" class="btn btn-default btn-sm" name="action" value="toggle">'
                        . $e($enabled ? Lang::t($chatbotLocale, 'rules_disable') : Lang::t($chatbotLocale, 'rules_enable'))
                        . '</button>'
                        . '<button type="submit" class="btn btn-default btn-sm" name="action" value="delete">'
                        . $e(Lang::t($chatbotLocale, 'rules_delete'))
                        . '</button></form></td></tr>';
                }
                ?>
                </tbody>
            </table>
            <p><small><?php echo $e(Lang::t($chatbotLocale, 'rules_impact_hint')); ?></small></p>
        <?php } ?>
    </div>
</div>
