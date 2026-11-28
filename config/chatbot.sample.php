<?php
/**
 * Chatbot plugin configuration
 *
 * Copy this file to `config/chatbot.php` and adjust it. `config/` is
 * ignored by git apart from the samples, so a site configuration survives
 * a `git pull`.
 *
 * The plugin reads the same CLI-Anything gates as the console and the HTTP
 * bridge (`config/cli_anything.php`): nothing here can open a write that
 * `allow_write` keeps closed.
 */

return [
    /*
     |--------------------------------------------------------------------------
     | Master switch and surfaces
     |--------------------------------------------------------------------------
     |
     | `opac`   = index.php?p=chatbot      (guests and members)
     | `admin`  = System -> Chatbot         (staff)
     |
     | Turning a surface off removes its menu entry and makes its JSON
     | endpoint answer CHATBOT_DISABLED.
     */
    'enabled' => true,
    'opac' => true,
    'admin' => true,

    /*
     |--------------------------------------------------------------------------
     | Language
     |--------------------------------------------------------------------------
     |
     | 'auto' follows $sysconf['default_lang']; 'id' and 'en' force one.
     | Strings live in plugins/chatbot/Support/Lang.php.
     */
    'language' => 'auto',

    /*
     |--------------------------------------------------------------------------
     | Write gates
     |--------------------------------------------------------------------------
     |
     | Both are off by default. With a gate closed the chat still shows the
     | plan ("pinjamkan B00001 ke anggota 123") together with the refusal
     | notice, so a librarian can see what *would* happen.
     |
     | Even with a gate open the browser must click Confirm: the first
     | answer is always a dry-run plus a single-use token.
     */
    'allow_admin_write' => false,
    'allow_member_write' => false,

    /*
     |--------------------------------------------------------------------------
     | Privacy
     |--------------------------------------------------------------------------
     |
     | Keep this false. A chat transcript ends up on screens, in screenshots
     | and sometimes in a ticket, so email/phone/address stay masked even for
     | staff (`member.get` is always called with redaction on).
     */
    'allow_pii_reveal' => false,

    /*
     |--------------------------------------------------------------------------
     | Limits
     |--------------------------------------------------------------------------
     |
     | Counted per browser session. Writes have their own, much smaller,
     | bucket. `confirm_ttl` is how long a Confirm button stays valid.
     */
    'rate_limit_per_minute' => 20,
    'max_writes_per_hour' => 5,
    'confirm_ttl' => 180,
    'max_message_length' => 500,
    'result_limit' => 5,


    /*
     |--------------------------------------------------------------------------
     | FAQ
     |--------------------------------------------------------------------------
     |
     | Routine questions (jam buka, alamat, telepon, denda, keanggotaan) are
     | answered from ONE content file the library owns:
     |
     |   cp plugins/chatbot/Support/faq.sample.php config/chatbot.faq.php
     |
     | Until that file exists the bundled sample is used, so the chat answers
     | out of the box. The sample values are examples -- replace the hours,
     | address, phone number and policies with your own.
     |
     | `threshold` is how close a typed question must be to an entry before it
     | is answered (0..1); below it the message goes to the catalogue search.
     */
    'faq' => [
        'enabled' => true,
        // Absolute path; empty = config/chatbot.faq.php, then the sample.
        'file' => '',
        // Lower = chattier, higher = stricter.
        'threshold' => 0.62,
        // How many other questions to offer after an answer.
        'topics' => 3,
        // Staff corrections from the review page (files/chatbot/faq.local.php)
        'overlay' => true,
        'overlay_file' => '',
    ],

    /*
     |--------------------------------------------------------------------------
     | Conversation log
     |--------------------------------------------------------------------------
     |
     | One append-only NDJSON file per month under files/chatbot. There is no
     | table: the log is rotated by size, pruned by age, starts with a PHP
     | exit guard, and never holds a member id (only a salted hash). The staff
     | page "System -> Chatbot history" reads it.
     */
    'log' => [
        'enabled' => true,
        // Directory; empty = SB files/chatbot.
        'dir' => '',
        'retention_days' => 90,
        'max_bytes' => 5242880,
        'max_files' => 6,
        // false = only a hash of the question, never its text.
        'store_message' => true,
        'truncate' => 300,
        'feedback' => true,
    ],

    /*
     |--------------------------------------------------------------------------
     | Guard: blocked questions, forbidden answers
     |--------------------------------------------------------------------------
     |
     | Off by default, and empty until someone adds a rule in
     | "System -> Chatbot rules". The rules live in files/chatbot/guard.php.
     */
    'guard' => [
        'enabled' => false,
        'file' => '',
        'log_hits' => true,
    ],

    /*
     |--------------------------------------------------------------------------
     | Staff surfaces
     |--------------------------------------------------------------------------
     */
    'admin_history' => true,
    'admin_overlay' => true,
    'admin_rules' => true,
    'admin_llm' => true,       // System -> Chatbot LLM: connection settings

    /*
     |--------------------------------------------------------------------------
     | Local model / OpenAI-compatible API (optional)
     |--------------------------------------------------------------------------
     |
     | Off by default: the chat answers from intents, FAQ and the catalogue
     | service, and no request leaves the server until this is switched on.
     | Everything below speaks the same API (POST {base_url}/chat/completions,
     | GET {base_url}/models): Ollama (`/v1`), LM Studio, llama.cpp server,
     | vLLM, LocalAI, OpenRouter, OpenAI.
     |
     | The staff page System -> Chatbot LLM writes the same keys to
     | files/chatbot/llm.php (0600, outside git), which is merged over this
     | block. Leave `file` empty to use that path.
     |
     | `allow_remote` must be true for any host that is not on the local
     | network; cloud metadata addresses are always refused. Keep `verify_tls`
     | on for anything that leaves the machine.
     */
    'llm' => [
        'enabled' => false,
        'provider' => 'openai',                              // label only
        'base_url' => 'http://127.0.0.1:11434/v1',           // Ollama default
        'model' => '',                                       // e.g. llama3.1:8b
        'api_key' => '',                                     // optional locally
        'timeout' => 15,                                     // seconds, 3-120
        'max_tokens' => 256,                                 // 16-4096
        'temperature' => 0.2,                                // 0-2
        'verify_tls' => true,
        'allow_remote' => false,
        'max_calls_per_minute' => 6,
        'file' => '',                                        // empty = files/chatbot/llm.php
    ],

    /*
     |--------------------------------------------------------------------------
     | Search
     |--------------------------------------------------------------------------
     |
     | Catalogue search is ranked, and it tolerates typos: the query is cleaned
     | of filler words, matched exactly, then with shortened tokens, then turned
     | into a "did you mean" list. This block tunes that behaviour; leave any
     | key out to keep the built-in value.
     |
     | `weights` are per matched token, by field. `fulltext` only has an effect
     | when the schema has a FULLTEXT index on biblio.title (stock SLiMS 9 does
     | not create one); the search never alters your schema.
     |
     | The chat passes this block to the same service the console uses, so a
     | value here applies to WhatsApp/Telegram answers too.
     */
    'search' => [
        // Ordering when the message does not ask for another one:
        // relevance, popular, newest or title.
        'sort' => 'relevance',
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
        // How many titles the "did you mean" answer may show.
        'suggestions' => 5,
        'min_similarity' => 0.55,
        // Tokens kept after the query is cleaned.
        'max_tokens' => 6,
        // Extra filler words, on top of the built-in Indonesian/English list.
        'stopwords' => [],
        // Use a FULLTEXT index on biblio.title when it exists.
        'fulltext' => true,
    ],

    /*
     |--------------------------------------------------------------------------
     | Debugging
     |--------------------------------------------------------------------------
     |
     | log_queries keeps the raw search text in the JSON payload (never in
     | system_log). Leave it off on a public OPAC: the text may contain a
     | member id or a name.
     */
    'log_queries' => false,

    /*
     |--------------------------------------------------------------------------
     | Help link
     |--------------------------------------------------------------------------
     |
     | Optional URL shown to staff ("read more"). Empty hides it.
     */
    'help_url' => '',
    /*
     |--------------------------------------------------------------------------
     | Messaging channels (WhatsApp, Telegram, generic gateway)
     |--------------------------------------------------------------------------
     |
     | One webhook, the same engine. Off by default: switch `enabled` on and
     | give `token` a long random string, then point the gateway at
     | `index.php?p=api/chatbot/v1/message` with that token in
     | `X-Chatbot-Token` (or `Authorization: Bearer`).
     |
     | `verify_token` is only used by the WhatsApp GET handshake.
     |
     | `members` and `staff` are the whole identity model, on purpose: a phone
     | number maps to a member id, a chat id maps to a staff `uid`, and
     | anything else is a guest. Nothing is guessed from the message, so a
     | spoofed number cannot become an account. Example:
     |
     |   'members' => ['whatsapp:628123456789' => 'M001'],
     |   'staff'   => ['telegram:987654321'    => 1],
     |
     | `storage_dir` holds the per-sender pending action and rate-limit
     | counters. Leave it empty for `SB files/chatbot/`; the directory is
     | created 0700 with a deny-all `.htaccess` and a plain `index.html`.
     |
     | `confirm_words` / `cancel_words` are what the user replies instead of
     | clicking a button. Keep them short and unambiguous.
     */
    'channel' => [
        'enabled' => false,
        'token' => '',
        'verify_token' => '',
        'members' => [],
        'staff' => [],
        'storage_dir' => '',
        'confirm_words' => ['ya', 'yes', 'ok', 'oke', 'konfirmasi', 'lanjut', '1'],
        'cancel_words' => ['batal', 'tidak', 'no', 'cancel', 'stop', '0'],
    ],
];
