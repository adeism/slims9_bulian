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

            self::$values = $values;
        }

        return self::$values;
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
        self::$values = is_null($values) ? null : array_merge(self::defaults(), $values);
    }
}
