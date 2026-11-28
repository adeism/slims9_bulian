<?php
/**
 * FAQ overlay: the part staff may change from the browser
 *
 * The content file (`config/chatbot.faq.php`) belongs to the library and is
 * never written by the web server. Corrections land here instead:
 *
 *   files/chatbot/faq.local.php
 *
 *   return [
 *       'keywords' => ['hours' => ['buka sabtu']],
 *       'entries'  => [['id' => 'wifi', 'status' => 'active', ...]],
 *       'hidden'   => ['donation'],
 *   ];
 *
 * The file starts with `<?php return`, so a direct HTTP hit outputs nothing.
 * Every save is atomic (temp file + rename), keeps one `.bak`, is validated
 * first, and appends a line to `audit.ndjson.php` — no content is deleted,
 * only added or hidden.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Overlay
{
    /** File name inside the storage directory. */
    const NAME = 'faq.local.php';

    /** Audit trail. */
    const AUDIT = 'audit.ndjson.php';

    /** How many extra keywords one entry may collect from staff. */
    const MAX_KEYWORDS = 20;

    /** @var array */
    private $config;

    /** @var string */
    private $path;

    /**
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config === [] ? Config::all() : array_merge(Config::defaults(), $config);
        $faq = $this->config['faq'] ?? [];
        $file = trim((string)($faq['overlay_file'] ?? ''));

        $this->path = $file !== '' ? $file : self::defaultPath();
    }

    /**
     * @return string
     */
    public static function defaultPath(): string
    {
        return LogStore::defaultDir() . DIRECTORY_SEPARATOR . self::NAME;
    }

    /**
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return bool
     */
    public function enabled(): bool
    {
        return !empty($this->config['faq']['overlay']);
    }

    /**
     * @return array
     */
    public function emptyData(): array
    {
        return ['keywords' => [], 'entries' => [], 'hidden' => [], 'updated_at' => '', 'version' => 0];
    }

    /**
     * The overlay on disk, always in a shape the rest of the plugin can use.
     *
     * @return array
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return $this->emptyData();
        }

        $data = @include $this->path;

        if (!is_array($data)) {
            return $this->emptyData();
        }

        return $this->normalise($data);
    }

    /**
     * @return bool
     */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Add a phrase to an existing entry.
     *
     * @param string $id
     * @param string $phrase
     * @param string $actor
     * @return array{ok:bool,error:string}
     */
    public function addKeyword(string $id, string $phrase, string $actor = ''): array
    {
        $id = trim($id);
        $phrase = trim(preg_replace('/\s+/u', ' ', $phrase));

        if ($id === '' || $phrase === '') {
            return $this->fail('Both the entry and the phrase are required.');
        }

        $items = Faq::items($this->config, $this->emptyData());
        $known = array_column($items, 'id');

        if (!in_array($id, $known, true)) {
            return $this->fail('There is no FAQ entry with id "' . $id . '".');
        }

        if (Str::length($phrase) > 60) {
            return $this->fail('A keyword must be 60 characters or fewer.');
        }

        $data = $this->load();
        $existing = (array)($data['keywords'][$id] ?? []);

        foreach ($items as $item) {
            if (($item['id'] ?? '') === $id) {
                $existing = array_merge($existing, (array)($item['keywords'] ?? []));
            }
        }

        foreach ($existing as $candidate) {
            if (Str::lower(trim((string)$candidate)) === Str::lower($phrase)) {
                return $this->fail('That phrase is already used for this entry.');
            }
        }

        if (count((array)($data['keywords'][$id] ?? [])) >= self::MAX_KEYWORDS) {
            return $this->fail('This entry already has ' . self::MAX_KEYWORDS . ' staff keywords.');
        }

        $data['keywords'][$id][] = $phrase;
        $saved = $this->save($data, [
            'action' => 'keyword',
            'target' => $id,
            'detail' => $phrase,
            'actor' => $actor,
        ]);

        return $saved ? ['ok' => true, 'error' => ''] : $this->fail('The overlay file could not be written.');
    }

    /**
     * Add a whole entry. It stays a draft until somebody activates it, so a
     * half-written answer cannot reach a guest.
     *
     * @param array  $entry
     * @param string $actor
     * @param bool   $activate false keeps it a draft
     * @return array{ok:bool,error:string}
     */
    public function addEntry(array $entry, string $actor = '', bool $activate = false): array
    {
        $data = $this->load();
        $items = Faq::items($this->config, $this->emptyData());

        $clean = [
            'id' => strtolower(trim((string)($entry['id'] ?? ''))),
            'question' => trim((string)($entry['question'] ?? '')),
            'question_en' => trim((string)($entry['question_en'] ?? '')),
            'keywords' => $this->splitKeywords((string)($entry['keywords'] ?? '')),
            'answer' => trim((string)($entry['answer'] ?? '')),
            'answer_en' => trim((string)($entry['answer_en'] ?? '')),
            'link' => trim((string)($entry['link'] ?? '')),
            'status' => $activate ? 'active' : 'draft',
        ];

        if ($clean['id'] === '') {
            $clean['id'] = 'local-' . substr(sha1($clean['question'] . microtime(true)), 0, 6);
        }

        $clean['id'] = substr(preg_replace('/[^a-z0-9-]/', '-', $clean['id']), 0, 40);

        $known = array_column($items, 'id');

        foreach ((array)$data['entries'] as $index => $existing) {
            $known[] = (string)($existing['id'] ?? ('#' . $index));
        }

        if (in_array($clean['id'], $known, true)) {
            return $this->fail('The id "' . $clean['id'] . '" is already used.');
        }

        $check = self::validateEntry($clean);

        if ($check !== '') {
            return $this->fail($check);
        }

        $data['entries'][] = $clean;

        $saved = $this->save($data, [
            'action' => $activate ? 'entry-active' : 'entry',
            'target' => $clean['id'],
            'detail' => $clean['question'],
            'actor' => $actor,
        ]);

        return $saved ? ['ok' => true, 'error' => ''] : $this->fail('The overlay file could not be written.');
    }

    /**
     * @param string     $id
     * @param bool       $active
     * @param string     $actor
     * @return array{ok:bool,error:string}
     */
    public function setActive(string $id, bool $active, string $actor = ''): array
    {
        $data = $this->load();
        $found = false;

        foreach ((array)$data['entries'] as $index => $entry) {
            if ((string)($entry['id'] ?? '') === $id) {
                $data['entries'][$index]['status'] = $active ? 'active' : 'draft';
                $found = true;
            }
        }

        if (!$found) {
            return $this->fail('There is no overlay entry with id "' . $id . '".');
        }

        $saved = $this->save($data, [
            'action' => $active ? 'activate' : 'deactivate',
            'target' => $id,
            'actor' => $actor,
        ]);

        return $saved ? ['ok' => true, 'error' => ''] : $this->fail('The overlay file could not be written.');
    }

    /**
     * @param string $id
     * @param bool   $hidden
     * @param string $actor
     * @return array{ok:bool,error:string}
     */
    public function setHidden(string $id, bool $hidden, string $actor = ''): array
    {
        $data = $this->load();

        if ($hidden) {
            $items = Faq::items($this->config, $this->emptyData());

            if (!in_array($id, array_column($items, 'id'), true)) {
                return $this->fail('There is no FAQ entry with id "' . $id . '".');
            }

            if (!in_array($id, (array)$data['hidden'], true)) {
                $data['hidden'][] = $id;
            }
        } else {
            $data['hidden'] = array_values(array_filter((array)$data['hidden'], function ($candidate) use ($id) {
                return (string)$candidate !== $id;
            }));
        }

        $saved = $this->save($data, [
            'action' => $hidden ? 'hide' : 'unhide',
            'target' => $id,
            'actor' => $actor,
        ]);

        return $saved ? ['ok' => true, 'error' => ''] : $this->fail('The overlay file could not be written.');
    }

    /**
     * Restore the previous save.
     *
     * @param string $actor
     * @return array{ok:bool,error:string}
     */
    public function revert(string $actor = ''): array
    {
        $backup = $this->path . '.bak';

        if (!is_file($backup)) {
            return $this->fail('There is no previous version to restore.');
        }

        $previous = @include $backup;

        if (!is_array($previous)) {
            return $this->fail('The previous version is not readable.');
        }

        return $this->save($this->normalise($previous), [
            'action' => 'revert',
            'target' => self::NAME,
            'actor' => $actor,
        ]) ? ['ok' => true, 'error' => ''] : $this->fail('The overlay file could not be written.');
    }

    /**
     * Audit rows, newest first.
     *
     * @param int $limit
     * @return array
     */
    public function audit(int $limit = 20): array
    {
        $path = dirname($this->path) . DIRECTORY_SEPARATOR . self::AUDIT;

        if (!is_file($path)) {
            return [];
        }

        $raw = (string)@file_get_contents($path);
        $rows = [];
        $prefix = "<?php exit; ?>\n";

        if (strpos($raw, '<?php exit;') === 0) {
            $raw = substr($raw, strlen($prefix));
        }

        foreach (explode("\n", $raw) as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return array_slice(array_reverse($rows), 0, max(1, $limit));
    }

    /**
     * What would the chat answer with this overlay? Nothing is written.
     *
     * @param array  $overlay
     * @param string $message
     * @param string $locale
     * @return array|null the FAQ match, or null
     */
    public function preview(array $overlay, string $message, string $locale): ?array
    {
        return Faq::match($message, $this->config, $locale, $this->normalise($overlay));
    }

    // -----------------------------------------------------------------
    // Files
    // -----------------------------------------------------------------

    /**
     * @param array $data
     * @param array $audit
     * @return bool
     */
    public function save(array $data, array $audit = []): bool
    {
        $data = $this->normalise($data);
        $data['updated_at'] = gmdate('c');
        $data['version'] = max(0, (int)($data['version'] ?? 0)) + 1;

        $dir = dirname($this->path);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }

        $body = "<?php\n/**\n * Chatbot FAQ overlay, written by the staff review page.\n"
            . " * Generated " . $data['updated_at'] . " - no secrets, safe to delete.\n */\n"
            . "return " . $this->export($data) . ";\n";

        $temp = $this->path . '.tmp-' . substr(sha1(uniqid('', true)), 0, 8);
        $handle = @fopen($temp, 'wb');

        if (!is_resource($handle)) {
            return false;
        }

        $written = @fwrite($handle, $body);
        @fflush($handle);
        @fclose($handle);

        if ($written !== strlen($body)) {
            @unlink($temp);

            return false;
        }

        @chmod($temp, 0640);

        if (is_file($this->path)) {
            @copy($this->path, $this->path . '.bak');
        }

        if (!@rename($temp, $this->path)) {
            @unlink($temp);

            return false;
        }

        if (!empty($audit)) {
            $this->appendAudit($data, $audit);
        }

        return true;
    }

    /**
     * @param array $data
     * @param array $audit
     * @return void
     */
    private function appendAudit(array $data, array $audit): void
    {
        $path = dirname($this->path) . DIRECTORY_SEPARATOR . self::AUDIT;
        $row = [
            'ts' => gmdate('c'),
            'action' => (string)($audit['action'] ?? ''),
            'target' => (string)($audit['target'] ?? ''),
            'detail' => Str::slice((string)($audit['detail'] ?? ''), 0, 120),
            'actor' => Str::slice((string)($audit['actor'] ?? ''), 0, 60),
            'version' => (int)($data['version'] ?? 0),
            'hash' => substr(sha1($this->export($data)), 0, 16),
        ];

        $handle = @fopen($path, 'ab');

        if (!is_resource($handle)) {
            return;
        }

        if (@flock($handle, LOCK_EX)) {
            if ((int)@filesize($path) === 0) {
                @fwrite($handle, "<?php exit; ?>\n");
            }

            @fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            @flock($handle, LOCK_UN);
        }

        @fclose($handle);
    }

    /**
     * @param array $data
     * @return array
     */
    private function normalise(array $data): array
    {
        $clean = $this->emptyData();
        $clean['updated_at'] = (string)($data['updated_at'] ?? '');
        $clean['version'] = (int)($data['version'] ?? 0);

        foreach ((array)($data['keywords'] ?? []) as $id => $phrases) {
            $id = (string)$id;
            $list = [];

            foreach ((array)$phrases as $phrase) {
                $phrase = trim((string)$phrase);

                if ($phrase !== '' && !in_array($phrase, $list, true)) {
                    $list[] = Str::slice($phrase, 0, 60);
                }
            }

            if ($id !== '' && $list !== []) {
                $clean['keywords'][$id] = array_slice($list, 0, self::MAX_KEYWORDS);
            }
        }

        foreach ((array)($data['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = (string)($entry['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $clean['entries'][] = [
                'id' => $id,
                'question' => trim((string)($entry['question'] ?? '')),
                'question_en' => trim((string)($entry['question_en'] ?? '')),
                'keywords' => $this->splitKeywords(is_array($entry['keywords'] ?? null)
                    ? implode(', ', (array)$entry['keywords'])
                    : (string)($entry['keywords'] ?? '')),
                'answer' => trim((string)($entry['answer'] ?? '')),
                'answer_en' => trim((string)($entry['answer_en'] ?? '')),
                'link' => trim((string)($entry['link'] ?? '')),
                'status' => (string)($entry['status'] ?? 'draft') === 'active' ? 'active' : 'draft',
            ];
        }

        foreach ((array)($data['hidden'] ?? []) as $id) {
            $id = trim((string)$id);

            if ($id !== '' && !in_array($id, $clean['hidden'], true)) {
                $clean['hidden'][] = $id;
            }
        }

        return $clean;
    }

    /**
     * @param array $data
     * @return string
     */
    private function export(array $data): string
    {
        return var_export($data, true);
    }

    /**
     * @param string $text
     * @return array
     */
    private function splitKeywords(string $text): array
    {
        $parts = preg_split('/[,;]+/', $text);
        $out = [];

        foreach ((array)$parts as $part) {
            $part = trim($part);

            if ($part !== '' && !in_array($part, $out, true)) {
                $out[] = Str::slice($part, 0, 60);
            }
        }

        return array_slice($out, 0, self::MAX_KEYWORDS);
    }

    /**
     * @param array $entry
     * @return string empty when the entry is usable
     */
    public static function validateEntry(array $entry): string
    {
        $id = (string)($entry['id'] ?? '');

        if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $id)) {
            return 'The id must be 2-40 characters: lowercase letters, digits and dashes.';
        }

        if (trim((string)($entry['question'] ?? '')) === '') {
            return 'A question is required.';
        }

        if (Str::length((string)$entry['question']) > 120) {
            return 'The question must be 120 characters or fewer.';
        }

        if (trim((string)($entry['answer'] ?? '')) === '') {
            return 'An answer is required.';
        }

        if (Str::length((string)$entry['answer']) > 500) {
            return 'The answer must be 500 characters or fewer.';
        }

        if (empty($entry['keywords'])) {
            return 'At least one keyword is required, otherwise nobody will find it.';
        }

        $link = trim((string)($entry['link'] ?? ''));

        if ($link !== '' && !preg_match('#^(https://|/|\./|index\.php)#i', $link)) {
            return 'A link must be https:// or a relative path.';
        }

        return '';
    }

    /**
     * @param string $message
     * @return array{ok:bool,error:string}
     */
    private function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
