<?php
/**
 * Chatbot plugin — `chatbot:llm`
 *
 * Shows the configured connection, tests it, and lists the models the server
 * offers. The same settings the staff page writes; useful when the page
 * cannot be reached or when a deployment is checked from the shell.
 *
 *   php index.php chatbot:llm
 *   php index.php chatbot:llm --test
 *   php index.php chatbot:llm --models
 *   php index.php chatbot:llm --test --json
 *
 * Exit codes: 0 reachable / nothing to do, 1 the test failed, 2 not
 * configured (disabled or no model).
 *
 * @license GPLv3
 */

namespace Commands;

use Chatbot\Config;
use Chatbot\Llm;
use Commands\Support\CliAnythingCommand;

class ChatbotLlm extends CliAnythingCommand
{
    protected string $signature = 'chatbot:llm {--test} {--models} {--json}';

    protected string $description = 'Show, save or test the local/OpenAI-compatible model connection';

    protected function handle()
    {
        require_once SB . 'plugins/chatbot/bootstrap.php';

        if (!class_exists(Llm::class)) {
            $this->error('Chatbot plugin not found in plugins/chatbot.');

            return 2;
        }

        $config = Config::all();
        $llm = new Llm($config);
        $status = $llm->status();
        $test = null;

        if ($this->flag('test') || $this->flag('models')) {
            $test = $llm->test();
        }

        if ($this->wantsJson()) {
            $this->output(json_encode([
                'status' => $status,
                'test' => $test,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($status, $test);
        }

        $this->table(['Setting', 'Value'], [
            ['Enabled', $status['enabled'] ? 'yes' : 'no'],
            ['Provider', $status['provider']],
            ['Base URL', $status['base_url'] === '' ? '-' : $status['base_url']],
            ['Host allowed', $status['allowed'] ? 'yes' : 'no (allow_remote)'],
            ['Model', $status['model'] === '' ? '-' : $status['model']],
            ['API key', $status['has_key'] ? $status['key'] : 'not set'],
            ['Timeout', $status['timeout'] . 's'],
            ['Max tokens', (string)$status['max_tokens']],
            ['Temperature', (string)$status['temperature']],
            ['TLS verify', $status['verify_tls'] ? 'on' : 'off'],
        ]);

        if ($status['problems'] !== []) {
            $this->error('adjusted at load: ' . implode(', ', $status['problems']));
        }

        if (is_array($test)) {
            $this->newLine();

            if (!empty($test['ok'])) {
                $this->success(sprintf('reachable in %d ms', (int)$test['ms']));

                if ($this->flag('models') || $this->flag('test')) {
                    if (empty($test['models'])) {
                        $this->output('no models listed');
                    } else {
                        foreach ($test['models'] as $model) {
                            $this->output('- ' . $model);
                        }
                    }
                }
            } else {
                $this->error(sprintf(
                    'connection failed: %s%s',
                    (string)($test['error'] ?? '?'),
                    isset($test['message']) && $test['message'] !== '' ? ' (' . $test['message'] . ')' : ''
                ));
            }
        }

        return $this->exitCode($status, $test);
    }

    /**
     * @param array      $status
     * @param array|null $test
     * @return int
     */
    private function exitCode(array $status, ?array $test): int
    {
        if (!$status['enabled'] || !$status['configured']) {
            return 2;
        }

        if (is_array($test) && empty($test['ok'])) {
            return 1;
        }

        return 0;
    }
}
