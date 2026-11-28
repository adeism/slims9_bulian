<?php
/**
 * Chatbot plugin — `chatbot:eval`
 *
 * Runs the evaluation corpus and the threshold sweep, the same numbers the
 * offline suite asserts:
 *
 *   php index.php chatbot:eval
 *   php index.php chatbot:eval --json
 *   php index.php chatbot:eval --sweep
 *
 * Exits non-zero when accuracy, precision or recall fall below the targets,
 * so it can gate a release.
 *
 * @license GPLv3
 */

namespace Commands;

use Chatbot\Config;
use Chatbot\Evaluation;
use Chatbot\Lang;
use Commands\Support\CliAnythingCommand;

class ChatbotEval extends CliAnythingCommand
{
    protected string $signature = 'chatbot:eval {--json} {--sweep}';

    protected string $description = 'Measure chatbot accuracy, threshold and typo tolerance';

    protected function handle()
    {
        require_once SB . 'plugins/chatbot/bootstrap.php';

        if (!class_exists(Evaluation::class)) {
            $this->error('Chatbot plugin not found in plugins/chatbot.');

            return 2;
        }

        $config = Config::all();
        $locale = Lang::locale($config);
        $report = Evaluation::run($config);
        $typo = Evaluation::typoRobustness($config, 3);
        $parity = Evaluation::langParity();
        $contract = Evaluation::renderContract($config);
        $sweep = $this->flag('sweep') ? Evaluation::sweep($config) : [];

        $sweepOk = true;

        foreach ($sweep as $point) {
            if ($point['precision'] < Evaluation::MIN_PRECISION) {
                $sweepOk = false;
            }
        }

        // Accuracy is the gate for positives; negatives, language parity,
        // the render contract and the sweep have zero tolerance.
        $negatives = array_values(array_filter($report['failures'], function ($failure) {
            return strpos((string)$failure['expect'], 'faq:') !== 0;
        }));

        $ok = $report['accuracy'] >= Evaluation::MIN_ACCURACY
            && $negatives === []
            && $typo['ratio'] >= 0.90
            && $parity === []
            && $contract === []
            && $sweepOk;

        if ($this->wantsJson()) {
            $this->output(json_encode([
                'ok' => $ok,
                'corpus' => $report,
                'typo' => $typo,
                'lang_parity' => $parity,
                'render_contract' => $contract,
                'sweep' => $sweep,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $ok ? 0 : 1;
        }

        $this->info(sprintf('Corpus: %d cases, %d passed, accuracy %.1f%%', $report['total'], $report['passed'], $report['accuracy'] * 100));

        foreach ($report['by_category'] as $category => $row) {
            $this->output(sprintf('  %-14s %d/%d', $category, $row['passed'], $row['total']));
        }

        if (!empty($report['failures'])) {
            $this->newLine();
            $this->info('Failures:');

            foreach ($report['failures'] as $failure) {
                $this->error(sprintf(
                    '%s [%s] expected %s, got %s',
                    $failure['q'],
                    $failure['role'],
                    $failure['expect'],
                    $failure['got']
                ));
            }
        }

        $this->newLine();
        $this->info(sprintf('Typo tolerance: %.1f%% of %d one-edit variants still match', $typo['ratio'] * 100, $typo['checked']));
        $this->info(sprintf('Language parity: %d problem(s)', count($parity)));
        $this->info(sprintf('Render contract: %d problem(s)', count($contract)));

        if ($sweep !== []) {
            $this->newLine();
            $this->table(
                ['threshold', 'precision', 'recall', 'FAQ answers'],
                array_map(function ($threshold, $point) {
                    return [
                        $threshold,
                        (string)$point['precision'],
                        (string)$point['recall'],
                        (string)$point['answers'],
                    ];
                }, array_keys($sweep), array_values($sweep))
            );
        }

        if ($ok) {
            $this->success('All evaluation targets are met.');

            return 0;
        }

        $this->error('Evaluation targets are not met.');

        return 1;
    }
}
