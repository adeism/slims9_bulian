<?php
/**
 * Frequently asked questions
 *
 * One file, one page of content: `config/chatbot.faq.php`. The plugin ships a
 * sample (`Support/faq.sample.php`) so the chat answers something useful
 * before anyone edits anything, and falls back to it when the site file is
 * missing.
 *
 * A FAQ does not belong in the catalogue, so it is not a command: matching
 * happens here, in plain PHP, and only when the message does not look like a
 * catalogue search. That keeps the deterministic rule table intact.
 *
 * Matching is deliberately conservative: a keyword phrase typed verbatim wins;
 * otherwise a near-miss word is accepted only when it covers at least a third
 * of what the user typed, so "pinjamkan B00001 ke anggota 123" cannot be
 * answered with the membership page just because it says "anggota".
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Faq
{
    /** Similarity below which a question is not considered answered. */
    const THRESHOLD = 0.62;

    /** How similar a single word must be to count as the same word. */
    const WORD_THRESHOLD = 0.78;

    /** Shortest accepted word. */
    const MIN_WORD = 2;

    /**
     * Without a whole-phrase hit, at least this share of the typed words must
     * be recognised. It stops "pinjamkan B00001 ke anggota 123" from matching
     * the membership entry just because it contains the word "anggota".
     */
    const MIN_COVERAGE = 0.3;

    /** @var array<string,array> loaded files, keyed by path+mtime */
    private static $cache = [];

    /** @var string file the last non-empty load came from */
    private static $source = '';

    /**
     * FAQ defaults, merged under the site's `faq` block.
     *
     * @return array
     */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            // Absolute path, or empty to use config/chatbot.faq.php and then
            // the bundled sample.
            'file' => '',
            'threshold' => self::THRESHOLD,
            // How many alternatives to offer after an answer.
            'topics' => 3,
        ];
    }

    /**
     * Loaded FAQ entries, in file order.
     *
     * @param array $config chatbot configuration
     * @return array
     */
    public static function items(array $config = []): array
    {
        $faq = self::settings($config);

        if (empty($faq['enabled'])) {
            return [];
        }

        foreach (self::candidates($faq) as $path) {
            $items = self::load($path);

            if (!empty($items)) {
                self::$source = $path;

                return $items;
            }
        }

        self::$source = '';

        return [];
    }

    /**
     * Answer a question, or null when nothing is close enough.
     *
     * @param string $message
     * @param array  $config
     * @param string $locale
     * @return array|null the matched entry plus `alternatives`
     */
    public static function match(string $message, array $config = [], string $locale = 'id'): ?array
    {
        $items = self::items($config);

        if (empty($items)) {
            return null;
        }

        $normalised = self::normalise($message);
        $tokens = self::tokens($message);

        if ($normalised === '' || empty($tokens)) {
            return null;
        }

        $settings = self::settings($config);
        $threshold = (float)$settings['threshold'];
        $best = null;
        $others = [];

        foreach ($items as $item) {
            $result = self::score($normalised, $tokens, $item);
            $score = (float)$result['score'];

            if (!$result['phrase'] && $result['coverage'] < self::MIN_COVERAGE) {
                continue;
            }

            if ($score + 0.0001 < $threshold) {
                continue;
            }

            $entry = [
                'id' => (string)($item['id'] ?? ''),
                'question' => self::localised($item, 'question', $locale),
                'answer' => self::localised($item, 'answer', $locale),
                'link' => (string)($item['link'] ?? ''),
                'score' => round($score, 2),
            ];

            if (is_null($best) || $score > $best['score'] + 0.0001) {
                if (!is_null($best)) {
                    $others[] = $best;
                }
                $best = $entry;
            } else {
                $others[] = $entry;
            }
        }

        if (is_null($best)) {
            return null;
        }

        usort($others, function ($left, $right) {
            return $left['score'] === $right['score']
                ? strcmp($left['id'], $right['id'])
                : ($left['score'] < $right['score'] ? 1 : -1);
        });

        $best['alternatives'] = array_slice($others, 0, max(0, (int)$settings['topics']));
        $best['topics'] = (int)$settings['topics'];

        return $best;
    }

    /**
     * Questions to show in the help answer and as chips.
     *
     * @param array  $config
     * @param string $locale
     * @param int    $limit
     * @return array
     */
    public static function topics(array $config = [], string $locale = 'id', int $limit = 6): array
    {
        $questions = [];

        foreach (self::items($config) as $item) {
            $question = self::localised($item, 'question', $locale);

            if ($question !== '') {
                $questions[] = $question;
            }
        }

        return array_slice($questions, 0, max(0, $limit));
    }

    /**
     * File the current content came from (empty when nothing loaded).
     *
     * @return string
     */
    public static function source(): string
    {
        return self::$source;
    }

    /**
     * Structural checks for the content file.
     *
     * @param array $items
     * @return array list of problems, empty when the file is usable
     */
    public static function validate(array $items): array
    {
        $problems = [];
        $seen = [];

        if (empty($items)) {
            $problems[] = 'No entries: the file must return a non-empty array of items.';
        }

        foreach ($items as $index => $item) {
            $position = is_int($index) ? '#' . ($index + 1) : (string)$index;

            if (!is_array($item)) {
                $problems[] = $position . ': not an array.';
                continue;
            }

            $id = trim((string)($item['id'] ?? ''));
            if ($id === '') {
                $problems[] = $position . ': missing "id".';
            } elseif (isset($seen[$id])) {
                $problems[] = $position . ': duplicate id "' . $id . '".';
            } else {
                $seen[$id] = true;
            }

            if (trim(self::localised($item, 'question', 'id')) === '') {
                $problems[] = $position . ': missing "question".';
            }

            if (trim(self::localised($item, 'answer', 'id')) === '') {
                $problems[] = $position . ': missing "answer".';
            }

            $keywords = array_filter((array)($item['keywords'] ?? []), function ($keyword) {
                return trim((string)$keyword) !== '';
            });

            if (empty($keywords)) {
                $problems[] = $position . ': no "keywords", the entry can only be found by its question.';
            }
        }

        return $problems;
    }

    /**
     * Normalised form: lowercase, punctuation to spaces, single spaces.
     *
     * @param string $text
     * @return string
     */
    public static function normalise(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', (string)$text);

        return trim((string)$text);
    }

    /**
     * Words of two characters or more, unique, in order.
     *
     * @param string $text
     * @return array
     */
    public static function tokens(string $text): array
    {
        $normalised = self::normalise($text);

        if ($normalised === '') {
            return [];
        }

        $tokens = [];

        foreach (explode(' ', $normalised) as $word) {
            if (strlen($word) < self::MIN_WORD) {
                continue;
            }

            if (!in_array($word, $tokens, true)) {
                $tokens[] = $word;
            }
        }

        return $tokens;
    }

    /**
     * 0..1 similarity between two words or phrases.
     *
     * @param string $a
     * @param string $b
     * @return float
     */
    public static function similarity(string $a, string $b): float
    {
        $a = substr(self::normalise($a), 0, 255);
        $b = substr(self::normalise($b), 0, 255);
        $length = max(strlen($a), strlen($b));

        if ($length === 0) {
            return 1.0;
        }

        return max(0.0, 1.0 - (levenshtein($a, $b) / $length));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * How well one entry answers one message.
     *
     * @param string $normalised
     * @param array  $tokens
     * @param array  $item
     * @return array{score:float,phrase:bool,coverage:float}
     */
    private static function score(string $normalised, array $tokens, array $item): array
    {
        $best = 0.0;
        $phraseHit = false;
        $matched = [];
        $haystack = ' ' . $normalised . ' ';

        foreach ((array)($item['keywords'] ?? []) as $keyword) {
            $phrase = self::normalise((string)$keyword);

            if ($phrase === '') {
                continue;
            }

            // Whole phrase typed: this is the strongest signal.
            if (strpos($haystack, ' ' . $phrase . ' ') !== false) {
                $best = max($best, 1.0);
                $phraseHit = true;

                foreach ($tokens as $position => $token) {
                    if (strpos(' ' . $phrase . ' ', ' ' . $token . ' ') !== false) {
                        $matched[$position] = true;
                    }
                }

                continue;
            }

            foreach (self::tokens($phrase) as $keywordToken) {
                foreach ($tokens as $position => $token) {
                    $similarity = self::similarity($token, $keywordToken);

                    if ($similarity >= self::WORD_THRESHOLD) {
                        // A near-miss word ("telepn") stays just under a
                        // phrase hit, so the exact phrasing still wins.
                        $best = max($best, 0.9 * $similarity);
                        $matched[$position] = true;
                    }
                }
            }
        }

        // The question text itself, as a weaker signal: it covers the case
        // where someone retypes the FAQ title instead of a keyword.
        foreach (['question', 'question_en'] as $field) {
            $questionTokens = self::tokens((string)($item[$field] ?? ''));

            if (empty($questionTokens)) {
                continue;
            }

            $hits = 0;
            foreach ($questionTokens as $questionToken) {
                foreach ($tokens as $token) {
                    if (self::similarity($token, $questionToken) >= self::WORD_THRESHOLD) {
                        $hits++;
                        break;
                    }
                }
            }

            $best = max($best, 0.75 * ($hits / count($questionTokens)));
        }

        return [
            'score' => $best,
            'phrase' => $phraseHit,
            'coverage' => count($matched) / max(1, count($tokens)),
        ];
    }

    /**
     * @param array $config
     * @return array
     */
    private static function settings(array $config): array
    {
        if ($config === []) {
            $config = Config::all();
        }

        $faq = isset($config['faq']) && is_array($config['faq']) ? $config['faq'] : [];

        return array_merge(self::defaults(), $faq);
    }

    /**
     * Files to try, in order.
     *
     * @param array $faq
     * @return array
     */
    private static function candidates(array $faq): array
    {
        $paths = [];
        $file = trim((string)($faq['file'] ?? ''));

        if ($file !== '') {
            $paths[] = $file;
        }

        if (defined('SB')) {
            $paths[] = rtrim(SB, '/\\') . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'chatbot.faq.php';
        }

        $paths[] = __DIR__ . DIRECTORY_SEPARATOR . 'faq.sample.php';

        return array_values(array_unique($paths));
    }

    /**
     * @param string $path
     * @return array
     */
    private static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $key = $path . '|' . (string)@filemtime($path);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $loaded = null;

        try {
            $loaded = require $path;
        } catch (\Throwable $error) {
            $loaded = null;
        }

        $items = [];

        foreach (is_array($loaded) ? $loaded : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = $item;
        }

        self::$cache[$key] = $items;

        return $items;
    }

    /**
     * Pick the localized field, falling back to Indonesian.
     *
     * @param array  $item
     * @param string $field question|answer
     * @param string $locale
     * @return string
     */
    private static function localised(array $item, string $field, string $locale): string
    {
        $locale = strtolower(trim($locale));

        if ($locale !== 'id') {
            $localised = trim((string)($item[$field . '_' . $locale] ?? ''));

            if ($localised !== '') {
                return $localised;
            }
        }

        return trim((string)($item[$field] ?? ''));
    }
}
