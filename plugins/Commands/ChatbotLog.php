<?php
/**
 * Chatbot plugin — `chatbot:log`
 *
 * Reads the conversation log the chat writes under `files/chatbot/`. Useful
 * for the weekly review: what people asked, what never got answered, which
 * answers were marked as unhelpful.
 *
 *   php index.php chatbot:log
 *   php index.php chatbot:log --days=7 --unanswered
 *   php index.php chatbot:log --q=jam --limit=20
 *   php index.php chatbot:log --prune
 *   php index.php chatbot:log --json
 *
 * Read-only apart from `--prune`, which removes monthly files older than
 * `log.retention_days`.
 *
 * @license GPLv3
 */

namespace Commands;

use Chatbot\Config;
use Chatbot\Lang;
use Chatbot\LogStore;
use Commands\Support\CliAnythingCommand;

class ChatbotLog extends CliAnythingCommand
{
    protected string $signature = 'chatbot:log {--days=7} {--unanswered} {--feedback=} {--q=} {--limit=50} {--prune} {--json}';

    protected string $description = 'Review the chatbot conversation log (questions, misses, feedback)';

    protected function handle()
    {
        require_once SB . 'plugins/chatbot/bootstrap.php';

        if (!class_exists(LogStore::class)) {
            $this->error('Chatbot plugin not found in plugins/chatbot.');

            return 2;
        }

        $config = Config::all();
        $locale = Lang::locale($config);
        $log = new LogStore($config);

        if ($this->flag('prune')) {
            $removed = $log->prune();
            $this->success(sprintf('Removed %d file(s) older than the retention window.', $removed));

            return 0;
        }

        if (!$log->enabled()) {
            $this->error(Lang::t($locale, 'history_disabled'));

            return 1;
        }

        $days = max(1, (int)$this->option('days'));
        $filters = [
            'since' => time() - $days * 86400,
            'limit' => max(1, (int)$this->option('limit')),
        ];

        if ($this->flag('unanswered')) {
            $filters['answered'] = false;
        }

        if (trim((string)$this->option('feedback')) !== '') {
            $filters['feedback'] = trim((string)$this->option('feedback'));
        }

        if (trim((string)$this->option('q')) !== '') {
            $filters['q'] = trim((string)$this->option('q'));
        }

        $summary = $log->summary(['since' => $filters['since']]);
        $rows = $log->records($filters);

        if ($this->wantsJson()) {
            $this->output(json_encode([
                'ok' => true,
                'directory' => $log->directory(),
                'days' => $days,
                'summary' => $summary,
                'records' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->info('Log directory: ' . $log->directory());
        $this->info(sprintf(
            'Last %d day(s): %s, %s, %s, %s',
            $days,
            Lang::t($locale, 'history_total', ['count' => $summary['total']]),
            Lang::t($locale, 'history_answered', ['pct' => $summary['answered_pct']]),
            Lang::t($locale, 'history_unanswered', ['count' => $summary['unanswered']]),
            Lang::t($locale, 'history_latency', ['p50' => $summary['p50_ms'], 'p95' => $summary['p95_ms']])
        ));
        $this->newLine();

        if (!empty($summary['top_unanswered'])) {
            $this->info(Lang::t($locale, 'history_misses') . ':');

            foreach ($summary['top_unanswered'] as $miss) {
                $this->output('  ' . str_pad((string)$miss['count'], 4, ' ', STR_PAD_LEFT) . 'x  ' . ($miss['q'] !== '' ? $miss['q'] : '(not stored)'));
            }

            $this->newLine();
        }

        if (empty($rows)) {
            $this->info(Lang::t($locale, 'history_none'));

            return 0;
        }

        $table = [];

        foreach ($rows as $row) {
            $table[] = [
                (string)($row['ts'] ?? ''),
                (string)($row['surface'] ?? ''),
                (string)($row['role'] ?? ''),
                (string)($row['intent'] ?? 'none'),
                (string)($row['faq_id'] ?? ''),
                (string)($row['feedback'] ?? ''),
                (string)($row['q'] ?? ''),
            ];
        }

        $this->table(['Time', 'Surface', 'Role', 'Intent', 'FAQ', 'Feedback', 'Question'], $table);

        return 0;
    }
}
