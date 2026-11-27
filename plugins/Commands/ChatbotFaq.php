<?php
/**
 * Chatbot plugin — `chatbot:faq`
 *
 * Shows the FAQ content the chat answers from and checks the file for
 * problems before it reaches a guest. Read-only: nothing is written, and the
 * command works on the same file the plugin loads.
 *
 *   php index.php chatbot:faq
 *   php index.php chatbot:faq --json
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class ChatbotFaq extends CliAnythingCommand
{
    protected string $signature = 'chatbot:faq {--json}';

    protected string $description = 'List and validate the chatbot FAQ content';

    protected function handle()
    {
        require_once SB . 'plugins/chatbot/bootstrap.php';

        if (!class_exists(\Chatbot\Faq::class)) {
            $this->error('Chatbot plugin not found in plugins/chatbot.');

            return 2;
        }

        $config = \Chatbot\Config::all();
        $items = \Chatbot\Faq::items($config);
        $problems = \Chatbot\Faq::validate($items);

        if ($this->wantsJson()) {
            $this->output(json_encode([
                'ok' => empty($problems),
                'source' => \Chatbot\Faq::source(),
                'count' => count($items),
                'items' => $items,
                'problems' => $problems,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return empty($problems) ? 0 : 1;
        }

        $this->info('Content: ' . (\Chatbot\Faq::source() === '' ? '(none)' : \Chatbot\Faq::source()));

        if (empty($items)) {
            $this->error('No FAQ entries. Copy plugins/chatbot/Support/faq.sample.php to config/chatbot.faq.php and edit it.');

            return 1;
        }

        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                (string)($item['id'] ?? '-'),
                (string)($item['question'] ?? '-'),
                (string)count((array)($item['keywords'] ?? [])),
            ];
        }

        $this->table(['ID', 'Question', 'Keywords'], $rows);
        $this->newLine();

        if (empty($problems)) {
            $this->success(sprintf('%d FAQ entries, no problems found.', count($items)));

            return 0;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return 1;
    }
}
