<?php
/**
 * Staff page — connection to a local model or an OpenAI-compatible API
 *
 * System -> Chatbot LLM. Two jobs: write the settings (atomically, with an
 * audit row) and prove the connection works before the chat depends on it.
 *
 * The chat does not need this page to answer; the engine is deterministic and
 * the model is opt-in. That is why the page states the current state first and
 * why "test" is a separate button: saving a key should not silently make a
 * network call.
 *
 * @license GPLv3
 */

defined('INDEX_AUTH') or die('Direct access is not allowed');

require_once __DIR__ . '/../bootstrap.php';

use Chatbot\Admin;
use Chatbot\Actor;
use Chatbot\Config;
use Chatbot\Http;
use Chatbot\Lang;
use Chatbot\Llm;
use Chatbot\LlmBreaker;
use Chatbot\LogStore;

$chatbotConfig = Config::all();
$chatbotLocale = Lang::locale($chatbotConfig);
$chatbotActor = Actor::fromAdmin();
$e = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

if (!Admin::can($chatbotActor, $chatbotConfig, 'admin_llm')) {
    echo '<div class="alert alert-warning">' . $e(Lang::t($chatbotLocale, 'channel_unauthorized')) . '</div>';

    return;
}

$chatbotActorLabel = Admin::label($chatbotActor);
$chatbotLogDir = trim((string)($chatbotConfig['log']['dir'] ?? ''));
$chatbotLogDir = $chatbotLogDir !== '' ? $chatbotLogDir : LogStore::defaultDir();
$chatbotBreaker = (new LlmBreaker($chatbotLogDir, $chatbotConfig))->state();
$chatbotAsk = null;
$chatbotNotice = '';
$chatbotNoticeType = 'info';
$chatbotTest = null;
$chatbotLlm = new Llm($chatbotConfig);
$chatbotSettings = $chatbotLlm->settings();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $chatbotAction = preg_replace('/[^a-z_]/', '', (string)$_POST['action']);

    if (!Http::validCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $chatbotNotice = Lang::t($chatbotLocale, 'ui_session_expired');
        $chatbotNoticeType = 'warning';
    } elseif ($chatbotAction === 'save') {
        $candidate = [
            'enabled' => isset($_POST['enabled']),
            'provider' => (string)($_POST['provider'] ?? 'openai'),
            'base_url' => (string)($_POST['base_url'] ?? ''),
            'model' => (string)($_POST['model'] ?? ''),
            'api_key' => (string)($_POST['api_key'] ?? ''),
            'timeout' => (int)($_POST['timeout'] ?? 15),
            'max_tokens' => (int)($_POST['max_tokens'] ?? 256),
            'temperature' => (float)($_POST['temperature'] ?? 0.2),
            'verify_tls' => isset($_POST['verify_tls']),
            'allow_remote' => isset($_POST['allow_remote']),
            'max_calls_per_minute' => (int)($_POST['max_calls_per_minute'] ?? 6),
        ];

        if ($chatbotLlm->save($candidate, [
            'action' => 'llm.save',
            'actor' => $chatbotActorLabel,
        ])) {
            $chatbotNotice = Lang::t($chatbotLocale, 'llm_saved');
            $chatbotNoticeType = 'success';
            $chatbotLlm = new Llm($chatbotConfig);
            $chatbotSettings = $chatbotLlm->settings();
        } else {
            $chatbotNotice = Lang::t($chatbotLocale, 'llm_save_failed');
            $chatbotNoticeType = 'warning';
        }
    } elseif ($chatbotAction === 'test') {
        $chatbotTest = $chatbotLlm->test();
    } elseif ($chatbotAction === 'reset') {
        (new LlmBreaker($chatbotLogDir, $chatbotConfig))->reset();
        $chatbotNotice = Lang::t($chatbotLocale, 'llm_reset_done');
        $chatbotNoticeType = 'success';
    } elseif ($chatbotAction === 'ask') {
        $question = trim((string)($_POST['question'] ?? ''));

        if ($question === '') {
            $chatbotNotice = Lang::t($chatbotLocale, 'ui_empty_message');
            $chatbotNoticeType = 'warning';
        } else {
            $chatbotAsk = $chatbotLlm->chat(Llm::prompt($question, $chatbotLocale));
        }
    }
}

$chatbotStatus = $chatbotLlm->status();
$chatbotProblems = $chatbotStatus['problems'];

?>
<link rel="stylesheet" href="<?php echo $e(SWB . 'plugins/chatbot/assets/chatbot.css'); ?>">

<div class="chatbot-admin">
    <h1><?php echo $e(Lang::t($chatbotLocale, 'llm_title')); ?></h1>

    <p><?php echo $e(Lang::t($chatbotLocale, 'llm_intro')); ?></p>

    <?php if ($chatbotNotice !== '') { ?>
        <div class="alert alert-<?php echo $e($chatbotNoticeType); ?>"><?php echo $e($chatbotNotice); ?></div>
    <?php } ?>

    <?php if ($chatbotProblems !== []) { ?>
        <div class="alert alert-warning"><?php echo $e(Lang::t($chatbotLocale, 'llm_problems', ['keys' => implode(', ', $chatbotProblems)])); ?></div>
    <?php } ?>

    <?php if (!$chatbotStatus['enabled']) { ?>
        <p><small><?php echo $e(Lang::t($chatbotLocale, 'llm_off_note')); ?></small></p>
    <?php } ?>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'llm_status')); ?></h2>
        <table class="chatbot-history">
            <tbody>
                <tr>
                    <th><?php echo $e(Lang::t($chatbotLocale, 'llm_enabled')); ?></th>
                    <td><?php echo $chatbotStatus['enabled'] ? '✓' : '—'; ?></td>
                </tr>
                <tr>
                    <th><?php echo $e(Lang::t($chatbotLocale, 'llm_base_url')); ?></th>
                    <td><code><?php echo $e($chatbotStatus['base_url'] !== '' ? $chatbotStatus['base_url'] : '—'); ?></code>
                        <?php if (!$chatbotStatus['allowed']) { ?>
                            <br><small><?php echo $e(Lang::t($chatbotLocale, 'llm_remote_blocked')); ?></small>
                        <?php } ?>
                    </td>
                </tr>
                <tr>
                    <th><?php echo $e(Lang::t($chatbotLocale, 'llm_model')); ?></th>
                    <td><code><?php echo $e($chatbotStatus['model'] !== '' ? $chatbotStatus['model'] : '—'); ?></code></td>
                </tr>
                <tr>
                    <th><?php echo $e(Lang::t($chatbotLocale, 'llm_key')); ?></th>
                    <td><?php echo $e($chatbotStatus['has_key']
                        ? Lang::t($chatbotLocale, 'llm_key_set', ['mask' => $chatbotStatus['key']])
                        : Lang::t($chatbotLocale, 'llm_no_key')); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'llm_breaker')); ?></h2>
        <?php
        $chatbotUntil = (int)$chatbotBreaker['until'];
        $chatbotFailures = (int)$chatbotBreaker['failures'];

        if ($chatbotUntil > time()) {
            echo '<p class="chatbot-admin__off">' . $e(Lang::t($chatbotLocale, 'llm_breaker_open', [
                'until' => gmdate('Y-m-d H:i', $chatbotUntil) . ' UTC',
                'failures' => $chatbotFailures,
                'error' => (string)$chatbotBreaker['last_error'],
            ])) . '</p>';
        } elseif ($chatbotFailures > 0) {
            echo '<p>' . $e(Lang::t($chatbotLocale, 'llm_breaker_count', [
                'failures' => $chatbotFailures,
                'error' => (string)$chatbotBreaker['last_error'],
            ])) . '</p>';
        } else {
            echo '<p>' . $e(Lang::t($chatbotLocale, 'llm_breaker_ok')) . '</p>';
        }
        ?>
        <form method="post" class="chatbot-admin__filters">
            <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
            <input type="hidden" name="action" value="reset">
            <button type="submit" class="btn btn-default btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'llm_reset')); ?></button>
        </form>
    </div>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'llm_ask')); ?></h2>
        <form method="post" class="chatbot-admin__filters">
            <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
            <input type="hidden" name="action" value="ask">
            <input type="text" name="question" size="60" maxlength="500" required>
            <button type="submit" class="btn btn-default btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'llm_ask')); ?></button>
        </form>
        <?php if (is_array($chatbotAsk)) { ?>
            <?php if (!empty($chatbotAsk['ok'])) { ?>
                <p><strong><?php echo $e(Lang::t($chatbotLocale, 'llm_answer')); ?>:</strong>
                    <?php echo $e((string)$chatbotAsk['text']); ?></p>
                <p><small><?php echo $e(Lang::t($chatbotLocale, 'llm_test_ok', ['ms' => (int)$chatbotAsk['ms']])); ?></small></p>
            <?php } else { ?>
                <p class="chatbot-admin__off"><?php echo $e(Lang::t($chatbotLocale, 'llm_test_failed', ['error' => (string)($chatbotAsk['error'] ?? '?')])); ?></p>
            <?php } ?>
        <?php } ?>
    </div>

    <?php if (is_array($chatbotTest)) { ?>
        <div class="chatbot-admin__detail">
            <h2><?php echo $e(Lang::t($chatbotLocale, 'llm_test')); ?></h2>
            <?php if (!empty($chatbotTest['ok'])) { ?>
                <p><?php echo $e(Lang::t($chatbotLocale, 'llm_test_ok', ['ms' => (int)$chatbotTest['ms']])); ?></p>
                <?php if (!empty($chatbotTest['models'])) { ?>
                    <p><?php echo $e(Lang::t($chatbotLocale, 'llm_models')); ?>:</p>
                    <ul>
                        <?php foreach ($chatbotTest['models'] as $chatbotModel) { ?>
                            <li><code><?php echo $e($chatbotModel); ?></code></li>
                        <?php } ?>
                    </ul>
                <?php } else { ?>
                    <p><?php echo $e(Lang::t($chatbotLocale, 'llm_no_models')); ?></p>
                <?php } ?>
            <?php } else { ?>
                <p class="chatbot-admin__off"><?php echo $e(Lang::t($chatbotLocale, 'llm_test_failed', ['error' => (string)($chatbotTest['error'] ?? '?')])); ?></p>
                <?php if (!empty($chatbotTest['message'])) { ?>
                    <p><small><code><?php echo $e($chatbotTest['message']); ?></code></small></p>
                <?php } ?>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'llm_save')); ?></h2>
        <form method="post" class="chatbot-admin__filters">
            <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
            <input type="hidden" name="action" value="save">
            <label><input type="checkbox" name="enabled" value="1"<?php echo $chatbotSettings['enabled'] ? ' checked' : ''; ?>>
                <?php echo $e(Lang::t($chatbotLocale, 'llm_enabled')); ?></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'llm_provider')); ?>
                <input type="text" name="provider" maxlength="32" size="14" value="<?php echo $e($chatbotSettings['provider']); ?>"></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'llm_base_url')); ?>
                <input type="url" name="base_url" size="46" value="<?php echo $e($chatbotSettings['base_url']); ?>"></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'llm_model')); ?>
                <input type="text" name="model" maxlength="120" size="26" value="<?php echo $e($chatbotSettings['model']); ?>" placeholder="llama3.1:8b"></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'llm_key')); ?>
                <input type="password" name="api_key" size="30" autocomplete="new-password" placeholder="<?php echo $e(Lang::t($chatbotLocale, 'llm_key_keep')); ?>"></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'llm_timeout')); ?>
                <input type="number" name="timeout" min="3" max="120" value="<?php echo (int)$chatbotSettings['timeout']; ?>"></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'llm_max_tokens')); ?>
                <input type="number" name="max_tokens" min="16" max="4096" value="<?php echo (int)$chatbotSettings['max_tokens']; ?>"></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'llm_temperature')); ?>
                <input type="number" name="temperature" min="0" max="2" step="0.1" value="<?php echo $e((string)$chatbotSettings['temperature']); ?>"></label>
            <label><?php echo $e(Lang::t($chatbotLocale, 'llm_calls_per_minute')); ?>
                <input type="number" name="max_calls_per_minute" min="1" max="60" value="<?php echo (int)$chatbotSettings['max_calls_per_minute']; ?>"></label>
            <label><input type="checkbox" name="verify_tls" value="1"<?php echo $chatbotSettings['verify_tls'] ? ' checked' : ''; ?>>
                <?php echo $e(Lang::t($chatbotLocale, 'llm_verify_tls')); ?></label>
            <label><input type="checkbox" name="allow_remote" value="1"<?php echo $chatbotSettings['allow_remote'] ? ' checked' : ''; ?>>
                <?php echo $e(Lang::t($chatbotLocale, 'llm_allow_remote')); ?></label>
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'llm_save')); ?></button>
        </form>
        <p><small><?php echo $e(Lang::t($chatbotLocale, 'llm_local_hint')); ?></small></p>
    </div>

    <div class="chatbot-admin__detail">
        <h2><?php echo $e(Lang::t($chatbotLocale, 'llm_test')); ?></h2>
        <form method="post" class="chatbot-admin__filters">
            <input type="hidden" name="csrf_token" value="<?php echo $e(Http::token()); ?>">
            <input type="hidden" name="action" value="test">
            <button type="submit" class="btn btn-default btn-sm"><?php echo $e(Lang::t($chatbotLocale, 'llm_test')); ?></button>
        </form>
    </div>
</div>
