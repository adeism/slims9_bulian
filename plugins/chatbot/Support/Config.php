<?php
/**
 * Chatbot configuration
 *
 * Defaults live in this class; a site can override any of them by copying
 * `config/chatbot.sample.php` to `config/chatbot.php` (the file is ignored
 * by git, like the rest of `config/`).
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Config
{
    /** Name passed to the `config()` helper. */
    const FILE = 'chatbot';

    /** @var array|null */
    private static $values = null;

    /** @var array<int,string> values repaired by the last sanitise() */
    private static $repairs = [];

    /**
     * @return array
     */
    public static function defaults(): array
    {
        return [
            // Master switch. When false the menu is not registered and the
            // JSON endpoint answers 404.
            'enabled' => true,

            // Surfaces. Turn one off to hide the chat for guests/members
            // (`opac`) or for staff (`admin`).
            'opac' => true,
            'admin' => true,

            // 'auto' follows $sysconf['default_lang'], otherwise 'id' or 'en'.
            'language' => 'auto',

            // Write gates. Both default to off: the chat can explain and
            // preview a transaction, but not commit it.
            'allow_admin_write' => false,
            'allow_member_write' => false,

            // Staff surfaces beyond the chat itself. Each one is a separate
            // menu entry and can be switched off on its own.
            'admin_history' => true,   // System -> Chatbot: history
            'admin_overlay' => true,   // System -> Chatbot: review (fix answers)
            'admin_rules' => true,     // System -> Chatbot: rules (block lists)

            // Never expose unmasked patron PII through the chat.
            'allow_pii_reveal' => false,

            // Abuse limits (per session).
            'rate_limit_per_minute' => 20,
            'max_writes_per_hour' => 5,

            // Lifetime of a "should I write this?" confirmation token.
            'confirm_ttl' => 180,

            // Longest accepted message, in characters.
            'max_message_length' => 500,

            // How many records a chat answer shows.
            'result_limit' => 5,

            // Catalogue search: ranking weights and typo tolerance. The chat
            // passes this block to the service, which merges it over its own
            // defaults, so a partial block is fine.
            'search' => [
                // Ordering when the message does not ask for another one.
                'sort' => 'relevance',
                // Relevance of one token, by where it matched. "popularity"
                // is not a weight: past loans only break ties.
                'weights' => [
                    'title_exact' => 120,
                    'isbn_exact' => 110,
                    'title_prefix' => 70,
                    'title_contains' => 45,
                    'call_number' => 40,
                    'author' => 30,
                    'subject' => 25,
                    'publisher' => 15,
                    'fulltext' => 35,
                ],
                // Shorten the token when nothing matches exactly ("sejarh").
                'typo' => true,
                // How many titles "did you mean" may offer.
                'suggestions' => 5,
                'min_similarity' => 0.55,
                // Tokens kept after the query is cleaned.
                'max_tokens' => 6,
                // Extra filler words on top of the built-in list.
                'stopwords' => [],
                // Use a FULLTEXT index on biblio.title when the schema has one.
                'fulltext' => true,
            ],

            // Debug: keep the raw query in the reply payload. Off by default
            // because chat text may contain a patron id.
            'log_queries' => false,

            // Optional "read more" link shown in the chat header.
            'help_url' => '',

            // Frequently asked questions. One content file, one page of
            // answers; the plugin ships a sample and prefers
            // config/chatbot.faq.php when it exists. See
            // plugins/chatbot/README.md#faq.
            'faq' => [
                'enabled' => true,
                // Absolute path. Empty = config/chatbot.faq.php, then the
                // bundled sample.
                'file' => '',
                // 0..1. Lower is chattier, higher only answers close matches.
                'threshold' => 0.62,
                // How many other questions to offer after an answer.
                'topics' => 3,
            ],

            // Conversation log. One append-only NDJSON file per month under
            // files/chatbot, each starting with a PHP exit guard: no table,
            // no schema change. Feedback is its own record, merged on read.
            // See plugins/chatbot/README.md#history.
            'log' => [
                'enabled' => true,
                // Directory; empty = SB files/chatbot.
                'dir' => '',
                // Keep nothing older than this (monthly granularity).
                'retention_days' => 90,
                // Rotate one month file above this size.
                'max_bytes' => 5242880,
                // Rotated parts kept per month file.
                'max_files' => 6,
                // false = only a hash of the question, never its text.
                'store_message' => true,
                // Longest question stored, in characters.
                'truncate' => 300,
                // Thumbs up/down on answers.
                'feedback' => true,
            ],

            // Question and answer guard: the short list of things the bot
            // must not discuss or must not say. Empty and inert by default;
            // rules are added from System -> Chatbot: rules.
            // See plugins/chatbot/README.md#rules.
            'guard' => [
                'enabled' => false,
                // Absolute path; empty = SB files/chatbot/guard.php.
                'file' => '',
                // Record a guard hit in the conversation log.
                'log_hits' => true,
            ],

            // Messaging channel webhook (WhatsApp Cloud, Telegram, or a
            // gateway that posts {phone, message}). Off by default; see
            // plugins/chatbot/README.md#messaging-channels.
            'channel' => [
                'enabled' => false,
                // Shared secret for the webhook (`X-Chatbot-Token`).
                'token' => '',
                // WhatsApp Cloud API subscription token (GET verification).
                'verify_token' => '',
                // Explicit identity map. No phone number is matched
                // automatically: these are the only numbers/ids the bot
                // recognises, everything else is a guest.
                //   'whatsapp:628123456789' => 'M001'
                //   'telegram:123456789'    => 'M002'
                'members' => [],
                //   'telegram:987654321'    => 1   (staff user id)
                'staff' => [],
                // Where pending confirmations live (empty = files/chatbot).
                'storage_dir' => '',
                'confirm_words' => ['ya', 'yes', 'ok', 'oke', 'konfirmasi', 'confirm', '1'],
                'cancel_words' => ['batal', 'cancel', 'tidak', 'no', '0'],
            ],
        ];
    }

    /**
     * Defaults <- config/chatbot.php
     *
     * @return array
     */
    public static function all(): array
    {
        if (is_null(self::$values)) {
            $values = self::defaults();

            if (function_exists('config')) {
                $loaded = config(self::FILE, []);
                if (is_array($loaded)) {
                    $values = array_merge($values, $loaded);
                }
            }

            self::$values = self::sanitise($values);
        }

        return self::$values;
    }

    /**
     * Repair a loaded configuration instead of trusting it.
     *
     * A hand-edited `config/chatbot.php` is the one file where a typo reaches
     * the engine before any code can argue with it: `'threshold' => 'abc'`
     * becomes 0.0 and every message is answered by the FAQ, `'members' => ''`
     * is a fatal error the first time a webhook arrives, and
     * `'rate_limit_per_minute' => 0` silently blocks the whole chat.
     *
     * Sections that are not arrays fall back to their defaults, flags accept
     * "on"/"off"/"1"/"0", numbers are clamped to a range that still makes
     * sense, and every repair is recorded so `Config::repairs()` can show it.
     *
     * @param array $values merged configuration
     * @return array
     */
    public static function sanitise(array $values): array
    {
        $defaults = self::defaults();
        self::$repairs = [];

        foreach ([
            'enabled', 'opac', 'admin', 'allow_admin_write', 'allow_member_write',
            'admin_history', 'admin_overlay', 'admin_rules', 'allow_pii_reveal',
            'log_queries',
        ] as $key) {
            $values[$key] = self::flag($values, $key, (bool)($defaults[$key] ?? false));
        }

        $language = strtolower(trim((string)($values['language'] ?? 'auto')));

        if (!in_array($language, ['auto', 'id', 'en'], true)) {
            $language = 'auto';
            self::$repairs[] = 'language';
        }

        $values['language'] = $language;
        $values['rate_limit_per_minute'] = self::intIn($values['rate_limit_per_minute'] ?? 20, 1, 1000, 20, 'rate_limit_per_minute');
        $values['max_writes_per_hour'] = self::intIn($values['max_writes_per_hour'] ?? 5, 1, 500, 5, 'max_writes_per_hour');
        $values['confirm_ttl'] = self::intIn($values['confirm_ttl'] ?? 180, 15, 86400, 180, 'confirm_ttl');
        $values['max_message_length'] = self::intIn($values['max_message_length'] ?? 500, 40, 2000, 500, 'max_message_length');
        $values['result_limit'] = self::intIn($values['result_limit'] ?? 5, 1, 50, 5, 'result_limit');

        // ---- FAQ content ------------------------------------------------
        $faq = self::section($values, 'faq', $defaults['faq']);
        $faq['enabled'] = self::flag($faq, 'enabled', true, 'faq.enabled');
        $faq['threshold'] = self::floatIn($faq['threshold'] ?? 0.62, 0.30, 0.95, 0.62, 'faq.threshold');
        $faq['topics'] = self::intIn($faq['topics'] ?? 3, 0, 10, 3, 'faq.topics');
        $values['faq'] = $faq;

        // ---- log ---------------------------------------------------------
        $log = self::section($values, 'log', $defaults['log']);
        $log['enabled'] = self::flag($log, 'enabled', true, 'log.enabled');
        $log['store_message'] = self::flag($log, 'store_message', true, 'log.store_message');
        $log['feedback'] = self::flag($log, 'feedback', true, 'log.feedback');
        $log['retention_days'] = self::intIn($log['retention_days'] ?? 90, 1, 3650, 90, 'log.retention_days');
        $log['max_bytes'] = self::intIn($log['max_bytes'] ?? 5242880, 4096, 1073741824, 5242880, 'log.max_bytes');
        $log['max_files'] = self::intIn($log['max_files'] ?? 6, 1, 100, 6, 'log.max_files');
        $log['truncate'] = self::intIn($log['truncate'] ?? 300, 40, 2000, 300, 'log.truncate');
        $values['log'] = $log;

        // ---- guard -------------------------------------------------------
        $guard = self::section($values, 'guard', $defaults['guard']);
        $guard['enabled'] = self::flag($guard, 'enabled', false, 'guard.enabled');
        $guard['log_hits'] = self::flag($guard, 'log_hits', true, 'guard.log_hits');
        $values['guard'] = $guard;

        // ---- channel -----------------------------------------------------
        $channel = self::section($values, 'channel', $defaults['channel']);
        $channel['enabled'] = self::flag($channel, 'enabled', false, 'channel.enabled');

        foreach (['members', 'staff'] as $key) {
            if (isset($channel[$key]) && !is_array($channel[$key])) {
                $channel[$key] = [];
                self::$repairs[] = 'channel.' . $key;
            }
        }

        foreach (['confirm_words', 'cancel_words'] as $key) {
            if (isset($channel[$key]) && !is_array($channel[$key])) {
                $channel[$key] = $defaults['channel'][$key];
                self::$repairs[] = 'channel.' . $key;
            }
        }

        $values['channel'] = $channel;

        // ---- catalogue search -------------------------------------------
        $search = self::section($values, 'search', $defaults['search']);
        $sort = strtolower(trim((string)($search['sort'] ?? 'relevance')));

        if (!in_array($sort, ['relevance', 'newest', 'popular'], true)) {
            $sort = 'relevance';
            self::$repairs[] = 'search.sort';
        }

        $search['sort'] = $sort;
        $search['typo'] = self::flag($search, 'typo', true, 'search.typo');
        $search['fulltext'] = self::flag($search, 'fulltext', true, 'search.fulltext');
        $search['suggestions'] = self::intIn($search['suggestions'] ?? 5, 0, 20, 5, 'search.suggestions');
        $search['max_tokens'] = self::intIn($search['max_tokens'] ?? 6, 1, 12, 6, 'search.max_tokens');
        $search['min_similarity'] = self::floatIn($search['min_similarity'] ?? 0.55, 0.0, 1.0, 0.55, 'search.min_similarity');

        if (isset($search['stopwords']) && !is_array($search['stopwords'])) {
            $search['stopwords'] = [];
            self::$repairs[] = 'search.stopwords';
        }

        $weights = isset($search['weights']) && is_array($search['weights'])
            ? $search['weights']
            : (array)$defaults['search']['weights'];

        foreach ($weights as $name => $weight) {
            $weights[$name] = (int)self::floatIn($weight, 0.0, 1000.0, 0.0, 'search.weights.' . $name);
        }

        $search['weights'] = $weights;
        $values['search'] = $search;

        self::$repairs = array_values(array_unique(self::$repairs));

        return $values;
    }

    /**
     * Configuration values repaired by the last `sanitise()` call.
     *
     * @return array<int,string>
     */
    public static function repairs(): array
    {
        return self::$repairs;
    }

    /**
     * @param array  $values
     * @param string $key
     * @param array  $fallback
     * @return array
     */
    private static function section(array $values, string $key, array $fallback): array
    {
        if (!isset($values[$key]) || !is_array($values[$key])) {
            if (isset($values[$key])) {
                self::$repairs[] = $key;
            }

            return $fallback;
        }

        return array_merge($fallback, $values[$key]);
    }

    /**
     * @param array  $values
     * @param string $key
     * @param bool   $default
     * @param string $label
     * @return bool
     */
    private static function flag(array $values, string $key, bool $default, string $label = ''): bool
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }

        $value = $values[$key];

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }

        if (is_string($value)) {
            $text = strtolower(trim($value));

            if (in_array($text, ['1', 'true', 'on', 'yes', 'y'], true)) {
                return true;
            }

            if (in_array($text, ['0', 'false', 'off', 'no', 'n', ''], true)) {
                return false;
            }
        }

        self::$repairs[] = $label === '' ? $key : $label;

        return $default;
    }

    /**
     * @param mixed  $value
     * @param int    $min
     * @param int    $max
     * @param int    $default
     * @param string $label
     * @return int
     */
    private static function intIn($value, int $min, int $max, int $default, string $label): int
    {
        if (is_bool($value) || !is_numeric($value)) {
            self::$repairs[] = $label;

            return $default;
        }

        $number = (int)$value;
        $clamped = max($min, min($max, $number));

        if ($clamped !== $number) {
            self::$repairs[] = $label;
        }

        return $clamped;
    }

    /**
     * @param mixed  $value
     * @param float  $min
     * @param float  $max
     * @param float  $default
     * @param string $label
     * @return float
     */
    private static function floatIn($value, float $min, float $max, float $default, string $label): float
    {
        if (is_bool($value) || !is_numeric($value)) {
            self::$repairs[] = $label;

            return $default;
        }

        $number = (float)$value;
        $clamped = max($min, min($max, $number));

        if (abs($clamped - $number) > 0.000001) {
            self::$repairs[] = $label;
        }

        return round($clamped, 3);
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $values = self::all();

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /**
     * @param string $key
     * @param bool   $default
     * @return bool
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return is_null($parsed) ? (bool)(int)$value : $parsed;
        }

        return (bool)$value;
    }

    /**
     * @param string $key
     * @param int    $default
     * @return int
     */
    public static function int(string $key, int $default = 0): int
    {
        return (int)self::get($key, $default);
    }

    /**
     * Is the chat available on this surface?
     *
     * @param string $surface 'opac' or 'admin'
     * @return bool
     */
    public static function enabled(string $surface): bool
    {
        if (!self::bool('enabled', true)) {
            return false;
        }

        return self::bool($surface === 'admin' ? 'admin' : 'opac', true);
    }

    /**
     * Test helper: replace the resolved configuration.
     *
     * @param array|null $values null restores the real configuration
     * @return void
     */
    public static function override(?array $values = null)
    {
        self::$values = is_null($values) ? null : self::sanitise(array_merge(self::defaults(), $values));
    }
}
