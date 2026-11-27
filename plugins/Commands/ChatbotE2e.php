<?php
/**
 * Chatbot plugin — `chatbot:e2e`
 *
 * Opt-in smoke test against the *real* SLiMS database, for a staging copy.
 * It runs a fixed set of read-only conversations through the engine and the
 * live CLI-Anything service, and checks each one reached the intent it should
 * have — the part the offline suite cannot see.
 *
 *   CHATBOT_E2E=1 php index.php chatbot:e2e
 *   CHATBOT_E2E=1 php index.php chatbot:e2e --json
 *
 * Nothing is written: every dialogue below is a read.
 *
 * @license GPLv3
 */

namespace Commands;

use Chatbot\Actor;
use Chatbot\Config;
use Chatbot\Engine;
use Chatbot\Intent;
use Chatbot\Lang;
use Commands\Support\CliAnythingCommand;

class ChatbotE2e extends CliAnythingCommand
{
    protected string $signature = 'chatbot:e2e {--json}';

    protected string $description = 'Read-only chatbot smoke test against the live database (needs CHATBOT_E2E=1)';

    /** @var array<int,array{0:string,1:string,2:string}> message, role, expected intent */
    const DIALOGUES = [
        ['halo', 'guest', 'greeting'],
        ['bantuan', 'guest', 'help'],
        ['jam buka', 'guest', 'faq.answer'],
        ['cari buku sejarah', 'guest', 'biblio.list'],
        ['apakah buku #1 tersedia', 'guest', 'biblio.get'],
        ['pinjaman saya', 'member', 'my.loans'],
        ['denda saya', 'member', 'my.profile'],
        ['profil saya', 'member', 'my.profile'],
        ['pinjaman terlambat', 'staff', 'loan.list'],
        ['ringkasan hari ini', 'staff', 'report.summary'],
        ['anggota 1', 'staff', 'member.get'],
        ['cek item B00001', 'staff', 'item.get'],
    ];

    protected function handle()
    {
        require_once SB . 'plugins/chatbot/bootstrap.php';

        if (getenv('CHATBOT_E2E') !== '1') {
            $this->error('Refusing to run: set CHATBOT_E2E=1 on a staging copy first.');

            return 2;
        }

        $config = Config::all();
        $locale = Lang::locale($config);
        $results = [];
        $failed = 0;

        foreach (self::DIALOGUES as $dialogue) {
            $_SESSION = [];
            \Chatbot\RateLimit::reset();

            $actor = $this->actor($dialogue[1]);
            $engine = new Engine($actor, $config, $this->service());
            $response = $engine->handle($dialogue[0]);
            $intent = (string)($response['meta']['intent'] ?? Intent::NONE);
            $ok = !empty($response['ok'])
                && $intent === $dialogue[2]
                && trim((string)($response['reply']['text'] ?? '')) !== '';

            if (!$ok) {
                $failed++;
            }

            $results[] = [
                'message' => $dialogue[0],
                'role' => $dialogue[1],
                'expected' => $dialogue[2],
                'intent' => $intent,
                'ok' => $ok,
                'error' => (string)($response['error']['code'] ?? ''),
            ];
        }

        if ($this->wantsJson()) {
            $this->output(json_encode([
                'ok' => $failed === 0,
                'passed' => count($results) - $failed,
                'failed' => $failed,
                'dialogues' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed === 0 ? 0 : 1;
        }

        foreach ($results as $result) {
            $line = sprintf(
                '%s  %-34s [%s] expected %-14s got %s',
                $result['ok'] ? 'PASS' : 'FAIL',
                $result['message'],
                $result['role'],
                $result['expected'],
                $result['intent']
            );

            if ($result['ok']) {
                $this->output($line);
            } else {
                $this->error($line . ($result['error'] !== '' ? ' (' . $result['error'] . ')' : ''));
            }
        }

        if ($failed === 0) {
            $this->success(sprintf('%d dialogues passed against the live database.', count($results)));

            return 0;
        }

        $this->error(sprintf('%d of %d dialogues failed.', $failed, count($results)));

        return 1;
    }

    /**
     * @param string $role
     * @return array
     */
    private function actor(string $role): array
    {
        if ($role === 'staff') {
            return ['role' => 'staff', 'id' => '1', 'username' => 'e2e', 'name' => 'E2E'];
        }

        if ($role === 'member') {
            return ['role' => 'member', 'member_id' => '1', 'name' => 'E2E'];
        }

        return Actor::anonymous('guest');
    }
}
