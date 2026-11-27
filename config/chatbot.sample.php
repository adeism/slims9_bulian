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
