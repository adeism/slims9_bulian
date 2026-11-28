<?php
/**
 * Chatbot plugin — `chatbot:ask`
 *
 * Ask the chat engine from the console, without opening a browser. Useful
 * to check what a librarian would see, to script a smoke test, and to run a
 * gated transaction from a cron job with an explicit `--commit`.
 *
 *   php index.php chatbot:ask "pinjaman terlambat"
 *   php index.php chatbot:ask "pinjaman saya" --role=member --as=123
 *   php index.php chatbot:ask "pinjamkan B00001 ke anggota 123" --commit
 *   php index.php chatbot:ask "ringkasan hari ini" --json
 *
 * `--commit` is the console equivalent of the browser's confirmation
 * button: the same gates (allow_write / allow_bulk), the same audit rows.
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class ChatbotAsk extends CliAnythingCommand
{
    protected string $signature = 'chatbot:ask {message?} {--role=} {--as=} {--commit} {--json}';

    protected string $description = 'Ask the SLiMS chatbot a question (staff, member or guest role)';

    protected function handle()
    {
        require_once SB . 'plugins/chatbot/bootstrap.php';

        $message = trim((string)$this->argument('message'));

        if ($message === '') {
            $this->error('Usage: php index.php chatbot:ask "message" [--role=staff|member|guest] [--as=ID] [--commit] [--json]');

            return 2;
        }

        $role = \Chatbot\Intent::normaliseRole((string)($this->option('role') ?: 'staff'));
        $as = trim((string)$this->option('as'));

        if ($role === \Chatbot\Policy::MEMBER && $as === '') {
            $this->error('The member role needs a member id: --as=MEMBERID');

            return 2;
        }

        if ($role === \Chatbot\Policy::STAFF && $as === '') {
            $as = '1';
        }

        $config = \Chatbot\Config::all();
        $actor = \Chatbot\Actor::make($role, $as, $as === '' ? 'Console' : $as);
        $engine = new \Chatbot\Engine($actor, $config, $this->service());

        $intent = \Chatbot\Intent::resolve($message, $role);

        if ($this->flag('commit') && \Chatbot\Policy::isWrite($intent['name'])) {
            $params = \Chatbot\Policy::scope($role, $intent['name'], (array)$intent['params'], $actor, $config);
            $response = $engine->commitWrite(['name' => $intent['name'], 'params' => $params]);
        } else {
            $response = $engine->handle($message);
        }

        if ($this->wantsJson()) {
            $this->output(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $response['ok'] ? 0 : 1;
        }

        $this->renderResponse($response);

        return $response['ok'] ? 0 : 1;
    }

    /**
     * @param array $response
     * @return void
     */
    private function renderResponse(array $response)
    {
        $this->output('<comment>' . strtoupper((string)$response['role']) . '</comment> › ' . (string)($response['reply']['text'] ?? ''));
        $this->newLine();

        foreach ((array)($response['reply']['blocks'] ?? []) as $block) {
            $type = (string)($block['type'] ?? '');

            if ($type === 'notice') {
                $this->output('  <fg=yellow>!</> ' . (string)($block['text'] ?? ''));
            }

            if ($type === 'link') {
                $this->output('  <fg=cyan>link</> ' . (string)($block['label'] ?? '') . ' → ' . (string)($block['href'] ?? ''));
            }

            if ($type === 'facts') {
                foreach ((array)($block['items'] ?? []) as $fact) {
                    $this->justify([(string)($fact['label'] ?? ''), (string)($fact['value'] ?? '')]);
                }
            }

            if ($type === 'list') {
                foreach ((array)($block['items'] ?? []) as $item) {
                    $line = '- ' . (string)($item['title'] ?? '');
                    if (!empty($item['meta'])) {
                        $line .= ' — ' . (string)$item['meta'];
                    }
                    if (!empty($item['badge'])) {
                        $line .= ' [' . (string)$item['badge'] . ']';
                    }
                    $this->output('  ' . $line);
                }
            }
        }

        if (!empty($response['pending']['token'])) {
            $this->newLine();
            $this->output('<fg=yellow>Preview only.</> Re-run with --commit to write (the browser version asks for a confirmation click).');
        }

        if (!empty($response['meta']['committed'])) {
            $this->newLine();
            $this->success('Committed and recorded in system_log.');
        }

        $this->newLine();
        $this->output('<fg=gray>intent: ' . (string)($response['meta']['intent'] ?? '-') . '</>');
    }
}
