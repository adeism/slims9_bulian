<?php
/**
 * Question and answer guard
 *
 * Two lists the library controls:
 *
 *   - `question` rules run before the rule table. A blocked question never
 *     reaches the catalogue or the service.
 *   - `answer` rules run on FAQ content at load time and on every reply text
 *     before it is shown. A forbidden sentence never leaves the bot.
 *
 * Rules live in `files/chatbot/guard.php`, default empty and inert. Matching
 * normalises the text first (case, zero-width characters, punctuation,
 * repeated spaces, digits used as letters: "b3bas" reads as "bebas"), so
 * "bebas  denda!!" and "BEBAS DENDA" hit the same phrase rule while a normal
 * sentence containing the words separately does not.
 *
 * A regex rule must compile, and is limited to 120 characters. A rule that
 * fails to run counts as "no match": a broken guard must not take the chat
 * down, but it must not silently allow everything either — the save path
 * tests compilation, so a bad pattern never reaches the file.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Guard
{
    /** File name inside the storage directory. */
    const NAME = 'guard.php';

    /** Longest accepted regex. */
    const MAX_PATTERN = 120;

    /** Text examined by one rule; keeps a pathological subject cheap. */
    const MAX_SUBJECT = 1000;

    /** Categories a rule must choose from. */
    const CATEGORIES = ['abuse', 'spam', 'injection', 'policy', 'legal', 'medical', 'off-topic'];

    /** Actions. */
    const ACTIONS = ['refuse', 'fallback', 'hide'];

    /** @var array<string,array> rules keyed by path+mtime */
    private static $cache = [];

    /**
     * @return string
     */
    public static function defaultPath(): string
    {
        return LogStore::defaultDir() . DIRECTORY_SEPARATOR . self::NAME;
    }

    /**
     * @param array $config
     * @return string
     */
    public static function path(array $config = []): string
    {
        $file = trim((string)($config['guard']['file'] ?? ''));

        return $file !== '' ? $file : self::defaultPath();
    }

    /**
     * @param array $config
     * @return bool
     */
    public static function enabled(array $config = []): bool
    {
        return !empty($config['guard']['enabled']);
    }

    /**
     * All rules, in the order they were added.
     *
     * @param array $config
     * @return array
     */
    public static function rules(array $config = []): array
    {
        $path = self::path($config);

        if (!is_file($path)) {
            return [];
        }

        $key = $path . '|' . (string)@filemtime($path);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $data = @include $path;

        if (!is_array($data)) {
            return self::$cache[$key] = [];
        }

        $rules = [];

        foreach ((array)($data['rules'] ?? $data) as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $clean = self::normaliseRule($rule);

            if ($clean['id'] !== '' && $clean['pattern'] !== '') {
                $rules[] = $clean;
            }
        }

        return self::$cache[$key] = $rules;
    }

    /**
     * Drop the in-process cache (tests and the rules page after a save).
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * The first rule that blocks this question, or null.
     *
     * @param string $question
     * @param array  $config
     * @return array|null
     */
    public static function question(string $question, array $config = []): ?array
    {
        return self::first($question, 'question', $config);
    }

    /**
     * The first rule that forbids this answer text, or null.
     *
     * @param string $answer
     * @param array  $config
     * @return array|null
     */
    public static function answer(string $answer, array $config = []): ?array
    {
        return self::first($answer, 'answer', $config);
    }

    /**
     * Every rule that would fire, for the "test a sentence" box.
     *
     * @param string $text
     * @param array  $config
     * @return array
     */
    public static function hits(string $text, array $config = []): array
    {
        $hits = [];

        foreach (self::rules($config) as $rule) {
            if (self::matches($text, $rule)) {
                $hits[] = $rule;
            }
        }

        return $hits;
    }

    /**
     * How many of these messages a rule would block: the over-blocking check
     * the rules page warns about.
     *
     * @param array $rule
     * @param array $messages
     * @return int
     */
    public static function impact(array $rule, array $messages): int
    {
        $count = 0;

        foreach ($messages as $message) {
            if (self::matches((string)$message, self::normaliseRule($rule))) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Save the whole rule set, atomically, after validating each rule.
     *
     * @param array  $rules
     * @param string $actor
     * @param array  $config
     * @return array{ok:bool,error:string}
     */
    public static function save(array $rules, string $actor = '', array $config = []): array
    {
        $clean = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                return ['ok' => false, 'error' => 'A rule must be an array.'];
            }

            $error = self::validate($rule);

            if ($error !== '') {
                return ['ok' => false, 'error' => $error];
            }

            $normalised = self::normaliseRule($rule);

            if (isset($clean[$normalised['id']])) {
                return ['ok' => false, 'error' => 'Duplicate rule id "' . $normalised['id'] . '".'];
            }

            $clean[$normalised['id']] = $normalised;
        }

        $path = self::path($config);
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'The rule directory cannot be created.'];
        }

        $data = [
            'rules' => array_values($clean),
            'updated_at' => gmdate('c'),
            'version' => 1,
        ];

        $body = "<?php\n/**\n * Chatbot guard rules, written by the staff rules page.\n"
            . " * Generated " . $data['updated_at'] . " - no secrets, safe to delete.\n */\n"
            . "return " . var_export($data, true) . ";\n";

        $temp = $path . '.tmp-' . substr(sha1(uniqid('', true)), 0, 8);
        $handle = @fopen($temp, 'wb');

        if (!is_resource($handle)) {
            return ['ok' => false, 'error' => 'The rule file cannot be written.'];
        }

        $written = @fwrite($handle, $body);
        @fflush($handle);
        @fclose($handle);

        if ($written !== strlen($body) || !@rename($temp, $path)) {
            @unlink($temp);

            return ['ok' => false, 'error' => 'The rule file cannot be written.'];
        }

        @chmod($path, 0640);
        self::audit($path, [
            'action' => 'guard-save',
            'target' => (string)count($clean) . ' rules',
            'actor' => $actor,
        ]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @param array $rule
     * @return string empty when usable
     */
    public static function validate(array $rule): string
    {
        $scope = (string)($rule['scope'] ?? '');
        $type = (string)($rule['type'] ?? '');
        $pattern = trim((string)($rule['pattern'] ?? ''));
        $category = (string)($rule['category'] ?? '');
        $action = (string)($rule['action'] ?? '');

        if (!in_array($scope, ['question', 'answer'], true)) {
            return 'The scope must be "question" or "answer".';
        }

        if (!in_array($type, ['phrase', 'word', 'regex'], true)) {
            return 'The type must be "phrase", "word" or "regex".';
        }

        if ($pattern === '') {
            return 'A pattern is required.';
        }

        if (Str::length($pattern) > self::MAX_PATTERN) {
            return 'A pattern must be ' . self::MAX_PATTERN . ' characters or fewer.';
        }

        if ($type === 'regex' && !self::compiles($pattern)) {
            return 'That regular expression does not compile.';
        }

        if (!in_array($category, self::CATEGORIES, true)) {
            return 'Pick a category: ' . implode(', ', self::CATEGORIES) . '.';
        }

        if (!in_array($action, self::ACTIONS, true)) {
            return 'The action must be refuse, fallback or hide.';
        }

        if ($scope === 'question' && $action === 'hide') {
            return '"hide" only makes sense for answer rules.';
        }

        if (trim((string)($rule['note'] ?? '')) === '') {
            return 'A note is required: why is this rule here?';
        }

        return '';
    }

    /**
     * @param string $pattern
     * @return bool
     */
    public static function compiles(string $pattern): bool
    {
        return @preg_match(self::delimiters($pattern), '') !== false;
    }

    // -----------------------------------------------------------------
    // Matching
    // -----------------------------------------------------------------

    /**
     * @param string $text
     * @param string $scope
     * @param array  $config
     * @return array|null
     */
    private static function first(string $text, string $scope, array $config): ?array
    {
        if (!self::enabled($config)) {
            return null;
        }

        foreach (self::rules($config) as $rule) {
            if ($rule['scope'] === $scope && self::matches($text, $rule)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param string $text
     * @param array  $rule
     * @return bool
     */
    public static function matches(string $text, array $rule): bool
    {
        if (empty($rule['enabled'])) {
            return false;
        }

        $type = (string)($rule['type'] ?? 'phrase');
        $pattern = trim((string)($rule['pattern'] ?? ''));

        if ($pattern === '') {
            return false;
        }

        if ($type === 'regex') {
            if (!self::compiles($pattern)) {
                return false;
            }

            $subject = Str::slice($text, 0, self::MAX_SUBJECT);

            return @preg_match(self::delimiters($pattern), $subject) === 1;
        }

        $haystack = ' ' . self::normalise($text) . ' ';
        $needle = self::normalise($pattern);

        if ($needle === '') {
            return false;
        }

        if ($type === 'word') {
            foreach (explode(' ', $needle) as $word) {
                if ($word !== '' && strpos($haystack, ' ' . $word . ' ') !== false) {
                    return true;
                }
            }

            return false;
        }

        return strpos($haystack, ' ' . $needle . ' ') !== false;
    }

    /**
     * Lowercase, strip invisible characters and punctuation, collapse spaces,
     * and read digits as the letters people use them for.
     *
     * @param string $text
     * @return string
     */
    public static function normalise(string $text): string
    {
        $text = Str::lower($text);
        $text = (string)preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $text);
        $text = (string)preg_replace('#[^\p{L}\p{N}]+#u', ' ', $text);
        $text = strtr($text, [
            '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's',
        ]);
        $text = (string)preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    // -----------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------

    /**
     * @param array $rule
     * @return array
     */
    public static function normaliseRule(array $rule): array
    {
        $id = preg_replace('/[^a-z0-9-]/', '', Str::lower((string)($rule['id'] ?? '')));

        if ($id === '') {
            $id = 'r-' . substr(sha1((string)($rule['pattern'] ?? '') . (string)($rule['scope'] ?? '')), 0, 8);
        }

        return [
            'id' => $id,
            'scope' => in_array(($rule['scope'] ?? ''), ['question', 'answer'], true) ? (string)$rule['scope'] : 'question',
            'type' => in_array(($rule['type'] ?? ''), ['phrase', 'word', 'regex'], true) ? (string)$rule['type'] : 'phrase',
            'pattern' => trim((string)($rule['pattern'] ?? '')),
            'category' => in_array(($rule['category'] ?? ''), self::CATEGORIES, true) ? (string)$rule['category'] : 'policy',
            'action' => in_array(($rule['action'] ?? ''), self::ACTIONS, true) ? (string)$rule['action'] : 'refuse',
            'note' => Str::slice(trim((string)($rule['note'] ?? '')), 0, 160),
            'enabled' => !isset($rule['enabled']) || (bool)$rule['enabled'],
        ];
    }

    /**
     * @param string $pattern
     * @return string
     */
    private static function delimiters(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~iu';
    }

    /**
     * @param string $path
     * @param array  $row
     * @return void
     */
    private static function audit(string $path, array $row): void
    {
        $file = dirname($path) . DIRECTORY_SEPARATOR . Overlay::AUDIT;
        $handle = @fopen($file, 'ab');

        if (!is_resource($handle)) {
            return;
        }

        if (@flock($handle, LOCK_EX)) {
            if ((int)@filesize($file) === 0) {
                @fwrite($handle, "<?php exit; ?>\n");
            }

            $row['ts'] = gmdate('c');
            $row['target'] = Str::slice((string)($row['target'] ?? ''), 0, 120);
            $row['actor'] = Str::slice((string)($row['actor'] ?? ''), 0, 60);
            @fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            @flock($handle, LOCK_UN);
        }

        @fclose($handle);
    }
}
