<?php
/**
 * Chatbot plugin — `chatbot:selftest`
 *
 * Runs the offline suite (intents, policy, confirmations, rate limits and
 * the engine against a fake CLI-Anything service). No database is touched,
 * so it is safe on a production server.
 *
 *   php index.php chatbot:selftest
 *   php index.php chatbot:selftest --json
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class ChatbotSelftest extends CliAnythingCommand
{
    protected string $signature = 'chatbot:selftest {--json}';

    protected string $description = 'Run the chatbot plugin offline test suite';

    protected function handle()
    {
        require_once SB . 'plugins/chatbot/bootstrap.php';

        if (!class_exists(\Chatbot\SelfTest::class)) {
            $this->error('Chatbot plugin not found in plugins/chatbot.');

            return 2;
        }

        $report = \Chatbot\SelfTest::run();
        $report['config_repairs'] = \Chatbot\Config::repairs();

        if ($this->wantsJson()) {
            $this->output(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $report['failed'] === 0 ? 0 : 1;
        }

        $rows = [];
        foreach ($report['cases'] as $case) {
            $rows[] = [
                $case['ok'] ? 'PASS' : 'FAIL',
                $case['name'],
                $case['ok'] ? '' : $case['detail'],
            ];
        }

        $this->table(['Result', 'Case', 'Detail'], $rows);
        $this->newLine();

        if ($report['failed'] === 0) {
            $this->success(sprintf('%d passed, 0 failed', $report['passed']));
        } else {
            $this->error(sprintf('%d passed, %d failed', $report['passed'], $report['failed']));
        }

        // A repaired config still boots, which is the point, but the typo is
        // worth showing: silently accepting "threshold => abc" is how a chat
        // starts answering everything.
        if ($report['config_repairs'] !== []) {
            $this->newLine();
            $this->error('config/chatbot.php repaired at load: ' . implode(', ', $report['config_repairs']));
        }

        return $report['failed'] === 0 ? 0 : 1;
    }
}
