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
    protected string $signature = 'chatbot:faq {--json} {--strict}';

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
        $hidden = \Chatbot\Faq::hiddenByGuard($config);
        $overlay = new \Chatbot\Overlay($config);
        $overlayData = $overlay->load();
        $strict = (bool)$this->flag('strict');

        if ($strict) {
            if (\Chatbot\Faq::source() !== '' && basename(\Chatbot\Faq::source()) === 'faq.sample.php') {
                $problems[] = 'The chat is still answering from the bundled sample.';
            }

            foreach ($hidden as $hiddenId) {
                $problems[] = 'Entry "' . $hiddenId . '" is hidden by an answer rule (guard).';
            }
        }

        if ($this->wantsJson()) {
            $this->output(json_encode([
                'ok' => empty($problems),
                'source' => \Chatbot\Faq::source(),
                'count' => count($items),
                'items' => $items,
                'hidden_by_guard' => $hidden,
                'overlay' => [
                    'file' => $overlay->path(),
                    'version' => (int)($overlayData['version'] ?? 0),
                    'entries' => array_map(function ($entry) {
                        return ['id' => (string)($entry['id'] ?? ''), 'status' => (string)($entry['status'] ?? 'draft')];
                    }, (array)($overlayData['entries'] ?? [])),
                    'hidden' => (array)($overlayData['hidden'] ?? []),
                ],
                'problems' => $problems,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return empty($problems) ? 0 : 1;
        }

        $this->info('Content: ' . (\Chatbot\Faq::source() === '' ? '(none)' : \Chatbot\Faq::source()));
        $this->info('Corrections: ' . $overlay->path() . ' (version ' . (int)($overlayData['version'] ?? 0) . ')');

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

        if (!empty($hidden)) {
            $this->info('Hidden by an answer rule: ' . implode(', ', $hidden));
        }

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
