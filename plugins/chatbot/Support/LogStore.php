<?php
/**
 * Conversation log
 *
 * One append-only NDJSON file per month under `files/chatbot/`:
 *
 *   files/chatbot/log-202611.ndjson.php
 *
 * No table, no schema change: the log is rotated by size, pruned by age, and
 * starts with a `<?php exit; ?>` guard so a direct HTTP hit returns nothing.
 * Writes never throw: a read-only or full disk must not break an answer.
 *
 * What is stored is deliberately small: no member id (only a salted sha1),
 * no name, no e-mail, no IP, and the message is cut to `log.truncate`
 * characters or reduced to its hash when `log.store_message` is false.
 * Feedback arrives later as its own record and is merged on read.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class LogStore
{
    /** Prefix that makes a direct HTTP hit a no-op. */
    const GUARD = "<?php exit; ?>\n";

    /** Record types. */
    const MESSAGE = 'message';
    const FEEDBACK = 'feedback';

    /** Keys a caller may set; everything else is dropped. */
    const FIELDS = [
        'req', 'surface', 'role', 'locale', 'q', 'q_hash', 'intent', 'faq_id',
        'stage', 'ms', 'write', 'confirm', 'guard', 'feedback', 'err',
    ];

    /** @var array */
    private $config;

    /** @var string */
    private $dir;

    /**
     * @param array $config full chatbot configuration
     */
    public function __construct(array $config = [])
    {
        $defaults = Config::defaults();
        $block = isset($config['log']) && is_array($config['log'])
            ? array_merge($defaults['log'], $config['log'])
            : $defaults['log'];

        $this->config = $block;
        $dir = trim((string)($block['dir'] ?? ''));
        $this->dir = $dir !== '' ? rtrim($dir, '/\\') : self::defaultDir();
    }

    /**
     * Where the log lives when the site has not chosen a directory.
     *
     * @return string
     */
    public static function defaultDir(): string
    {
        $root = defined('SB') ? SB : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;

        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'chatbot';
    }

    /**
     * @return string
     */
    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * @return bool
     */
    public function enabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    /**
     * @return bool
     */
    public function feedbackEnabled(): bool
    {
        return $this->enabled() && !empty($this->config['feedback']);
    }

    /**
     * A short, non-guessable request id: eight hex characters.
     *
     * @return string
     */
    public static function reqId(): string
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (\Throwable $error) {
            return substr(sha1(uniqid('', true)), 0, 8);
        }
    }

    /**
     * Salted hash: enough to follow one actor across messages, useless on its
     * own. The salt never leaves `files/chatbot/.salt`.
     *
     * @param string $id
     * @return string
     */
    public function actorHash(string $id): string
    {
        $id = trim($id);

        if ($id === '') {
            return '';
        }

        return substr(hash_hmac('sha256', $id, $this->salt()), 0, 16);
    }

    /**
     * Keep one message: control characters out, whitespace collapsed, cut.
     *
     * @param string $text
     * @return string
     */
    public function mask(string $text): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $clean = is_string($clean) ? $clean : $text;
        $clean = trim(preg_replace('/\s+/u', ' ', $clean));
        $limit = max(40, (int)($this->config['truncate'] ?? 300));

        return Str::length($clean) > $limit ? Str::slice($clean, 0, $limit - 1) . '…' : $clean;
    }

    /**
     * Store one message. Returns false when the log cannot be written; the
     * caller keeps answering regardless.
     *
     * @param array $entry
     * @return bool
     */
    public function record(array $entry): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $message = (string)($entry['q'] ?? '');
        $row = [
            'kind' => self::MESSAGE,
            'ts' => gmdate('c'),
            'seq' => microtime(true),
        ];

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $entry)) {
                $row[$field] = $entry[$field];
            }
        }

        $row['req'] = self::validReq((string)($entry['req'] ?? '')) ? (string)$entry['req'] : self::reqId();
        $row['role'] = Intent::normaliseRole((string)($row['role'] ?? 'guest'));
        $row['surface'] = substr((string)($row['surface'] ?? 'opac'), 0, 20);
        $row['locale'] = (string)($row['locale'] ?? 'en') === 'id' ? 'id' : 'en';
        $row['actor'] = $this->actorHash((string)($entry['actor_id'] ?? ''));
        $row['q'] = !empty($this->config['store_message']) ? $this->mask($message) : '';
        $row['q_hash'] = substr(sha1(Str::lower($this->mask($message))), 0, 16);
        $row['ms'] = max(0, (int)($row['ms'] ?? 0));
        $row['write'] = (bool)($row['write'] ?? false);
        $row['feedback'] = null;

        foreach (['intent', 'faq_id', 'stage', 'confirm', 'guard', 'err'] as $key) {
            $row[$key] = substr((string)($row[$key] ?? ''), 0, 40);
        }

        return $this->append($row);
    }

    /**
     * Attach a thumbs up/down to an earlier request.
     *
     * @param string $req
     * @param string $value up|down
     * @param array  $extra surface, role, actor_id
     * @return bool
     */
    public function feedback(string $req, string $value, array $extra = []): bool
    {
        if (!$this->feedbackEnabled() || !in_array($value, ['up', 'down'], true) || !self::validReq($req)) {
            return false;
        }

        $row = [
            'kind' => self::FEEDBACK,
            'ts' => gmdate('c'),
            'seq' => microtime(true),
            'req' => $req,
            'feedback' => $value,
            'surface' => substr((string)($extra['surface'] ?? ''), 0, 20),
            'role' => Intent::normaliseRole((string)($extra['role'] ?? 'guest')),
            'actor' => $this->actorHash((string)($extra['actor_id'] ?? '')),
        ];

        return $this->append($row);
    }

    /**
     * Read records, newest first, with feedback merged onto its message.
     *
     * Supported filters: since, until (unix), surface, role, intent, faq_id,
     * answered (bool), feedback (up|down), q (substring), limit, offset.
     *
     * @param array $filters
     * @return array
     */
    public function records(array $filters = []): array
    {
        $rows = $this->rows();
        $messages = [];
        $feedback = [];

        foreach ($rows as $row) {
            $kind = (string)($row['kind'] ?? self::MESSAGE);

            if ($kind === self::FEEDBACK) {
                $feedback[(string)($row['req'] ?? '')] = (string)($row['feedback'] ?? '');
                continue;
            }

            // A later record with the same req wins (there should be none).
            $messages[(string)($row['req'] ?? '')] = $row;
        }

        foreach ($messages as $req => $row) {
            if (isset($feedback[$req]) && $feedback[$req] !== '') {
                $messages[$req]['feedback'] = $feedback[$req];
            }
        }

        $out = array_values($messages);
        usort($out, function ($a, $b) {
            // microtime ordering; the second fallback keeps old rows readable
            $left = (float)($a['seq'] ?? 0);
            $right = (float)($b['seq'] ?? 0);

            if ($left === $right) {
                return strcmp((string)($b['ts'] ?? ''), (string)($a['ts'] ?? ''));
            }

            return $right <=> $left;
        });

        $filtered = [];

        foreach ($out as $row) {
            if (!$this->matches($row, $filters)) {
                continue;
            }

            $filtered[] = $row;
        }

        $offset = max(0, (int)($filters['offset'] ?? 0));
        $limit = max(0, (int)($filters['limit'] ?? 0));

        if ($offset > 0 || $limit > 0) {
            $filtered = array_slice($filtered, $offset, $limit > 0 ? $limit : null);
        }

        return $filtered;
    }

    /**
     * Numbers for the history header and `chatbot:log`.
     *
     * @param array $filters
     * @return array
     */
    public function summary(array $filters = []): array
    {
        $rows = $this->records($filters);
        $total = count($rows);
        $answered = 0;
        $faq = 0;
        $guarded = 0;
        $up = 0;
        $down = 0;
        $ms = 0;
        $intents = [];
        $unanswered = [];
        $writes = 0;

        foreach ($rows as $row) {
            $intent = (string)($row['intent'] ?? '');
            $ms += (int)($row['ms'] ?? 0);
            $intents[$intent === '' ? 'none' : $intent] = ($intents[$intent === '' ? 'none' : $intent] ?? 0) + 1;

            if ((string)($row['guard'] ?? '') !== '') {
                $guarded++;
            }

            if ((string)($row['feedback'] ?? '') === 'up') {
                $up++;
            } elseif ((string)($row['feedback'] ?? '') === 'down') {
                $down++;
            }

            if ((string)($row['faq_id'] ?? '') !== '') {
                $faq++;
                $answered++;
                continue;
            }

            if (in_array($intent, ['', 'none', Intent::NONE], true)) {
                $hash = (string)($row['q_hash'] ?? '');
                $key = $hash === '' ? (string)($row['req'] ?? '') : $hash;

                if (!isset($unanswered[$key])) {
                    $unanswered[$key] = ['q' => (string)($row['q'] ?? ''), 'count' => 0];
                }

                $unanswered[$key]['count']++;
                continue;
            }

            $answered++;
            $writes += !empty($row['write']) ? 1 : 0;
        }

        arsort($intents);
        arsort($unanswered);

        return [
            'total' => $total,
            'answered' => $answered,
            'unanswered' => $total - $answered,
            'answered_pct' => $total > 0 ? round($answered / $total * 100, 1) : 0.0,
            'faq' => $faq,
            'guarded' => $guarded,
            'writes' => $writes,
            'feedback_up' => $up,
            'feedback_down' => $down,
            'avg_ms' => $total > 0 ? (int)round($ms / $total) : 0,
            'intents' => array_slice($intents, 0, 8, true),
            'top_unanswered' => array_slice(array_values($unanswered), 0, 10),
        ];
    }

    /**
     * Delete monthly files older than the retention window.
     *
     * @param int|null $now
     * @return int files removed
     */
    public function prune(?int $now = null): int
    {
        $now = is_null($now) ? time() : $now;
        $days = max(1, (int)($this->config['retention_days'] ?? 90));
        $cutoff = gmdate('Ym', $now - $days * 86400);
        $removed = 0;

        foreach ($this->files() as $file) {
            if (!preg_match('/log-(\d{6})\.ndjson\.php(?:\.\d+)?$/', $file, $match)) {
                continue;
            }

            if ($match[1] >= $cutoff) {
                continue;
            }

            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    // -----------------------------------------------------------------
    // Files
    // -----------------------------------------------------------------

    /**
     * @param array $row
     * @return bool
     */
    private function append(array $row): bool
    {
        $dir = $this->dir;

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }

        $path = $this->path();
        $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        if (!is_string($line)) {
            return false;
        }

        $this->maybeRotate($path, strlen($line));

        $handle = @fopen($path, 'ab');

        if (!is_resource($handle)) {
            return false;
        }

        $locked = @flock($handle, LOCK_EX);

        if ($locked) {
            $size = @filesize($path);

            if ($size === 0) {
                @fwrite($handle, self::GUARD);
            }

            @fwrite($handle, $line);
            @fflush($handle);
            @flock($handle, LOCK_UN);
        }

        @fclose($handle);

        if ($locked && random_int(1, 50) === 1) {
            $this->prune();
        }

        return (bool)$locked;
    }

    /**
     * @param string $path
     * @param int    $incoming
     * @return void
     */
    private function maybeRotate(string $path, int $incoming): void
    {
        $max = max(10240, (int)($this->config['max_bytes'] ?? 5242880));
        $size = @filesize($path);

        if (!is_int($size) || $size + $incoming <= $max) {
            return;
        }

        $parts = max(1, (int)($this->config['max_files'] ?? 6));

        for ($index = $parts - 1; $index >= 1; $index--) {
            $from = $path . '.' . $index;
            $to = $path . '.' . ($index + 1);

            if (@file_exists($to)) {
                @unlink($to);
            }

            if (@file_exists($from)) {
                @rename($from, $to);
            }
        }

        @rename($path, $path . '.1');
    }

    /**
     * @return string
     */
    private function path(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . 'log-' . gmdate('Ym') . '.ndjson.php';
    }

    /**
     * Current month first, then rotated parts, then older months.
     *
     * @return array
     */
    private function files(): array
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . 'log-*.ndjson.php*');

        if (!is_array($files)) {
            return [];
        }

        usort($files, function ($a, $b) {
            return strcmp($b, $a);
        });

        return $files;
    }

    /**
     * @return array
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->files() as $file) {
            $raw = @file_get_contents($file);

            if (!is_string($raw) || $raw === '') {
                continue;
            }

            if (strpos($raw, '<?php exit; ?>') === 0) {
                $raw = substr($raw, strlen(self::GUARD));
            }

            foreach (explode("\n", $raw) as $line) {
                $line = trim($line);

                if ($line === '' || $line[0] !== '{') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        }

        return $rows;
    }

    /**
     * @param array $row
     * @param array $filters
     * @return bool
     */
    private function matches(array $row, array $filters): bool
    {
        $since = isset($filters['since']) ? (int)$filters['since'] : 0;
        $until = isset($filters['until']) ? (int)$filters['until'] : 0;
        $ts = strtotime((string)($row['ts'] ?? ''));

        if ($since > 0 && ($ts === false || $ts < $since)) {
            return false;
        }

        if ($until > 0 && ($ts === false || $ts > $until)) {
            return false;
        }

        if (!empty($filters['req']) && (string)($row['req'] ?? '') !== (string)$filters['req']) {
            return false;
        }

        foreach (['surface', 'role', 'intent', 'faq_id'] as $key) {
            if (isset($filters[$key]) && (string)$filters[$key] !== '' && (string)($row[$key] ?? '') !== (string)$filters[$key]) {
                return false;
            }
        }

        if (isset($filters['answered'])) {
            $isAnswered = !in_array((string)($row['intent'] ?? ''), ['', 'none', Intent::NONE], true);

            if ((bool)$filters['answered'] !== $isAnswered) {
                return false;
            }
        }

        if (!empty($filters['feedback']) && (string)($row['feedback'] ?? '') !== (string)$filters['feedback']) {
            return false;
        }

        if (!empty($filters['q'])) {
            $needle = Str::lower((string)$filters['q']);

            if (strpos(Str::lower((string)($row['q'] ?? '')), $needle) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string
     */
    private function salt(): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . '.salt';
        $salt = @file_get_contents($path);

        if (is_string($salt) && strlen(trim($salt)) >= 32) {
            return trim($salt);
        }

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }

        try {
            $salt = bin2hex(random_bytes(32));
        } catch (\Throwable $error) {
            $salt = sha1(uniqid('', true) . __FILE__);
        }

        @file_put_contents($path, $salt, LOCK_EX);
        @chmod($path, 0600);

        return $salt;
    }

    /**
     * @param string $req
     * @return bool
     */
    public static function validReq(string $req): bool
    {
        return (bool)preg_match('/^[a-f0-9]{8}$/', $req);
    }
}
