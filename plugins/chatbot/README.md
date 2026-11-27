# Chatbot plugin

A question-and-answer assistant for SLiMS 9 Bulian, built **on top of the
CLI-Anything service** — the same class behind `php index.php clianything:*`
and the HTTP bridge. The chat owns no SQL and no circulation rule: it routes
a message to a command, and renders the answer.

Because of that, the three guards you already configured keep working:

| Guard | Where it lives | What the chat inherits |
| --- | --- | --- |
| `allow_write` | `config/cli_anything.php` | a closed gate means the chat can only *plan* a transaction |
| `allow_bulk` + automatic backup | CLI-Anything | multi-item checkout still takes a dump first |
| audit trail | `system_log` (`location = CLI-Anything`) | every commit is recorded, now with the real staff/member id |

## Who sees what

The role comes from the **server session**, never from the request body.

| | Guest (OPAC, not logged in) | Member (OPAC, logged in) | Staff (admin) |
| --- | --- | --- | --- |
| Search the catalogue | ✅ | ✅ | ✅ |
| Title detail and copy availability | ✅ | ✅ | ✅ |
| Item status by code | ✅ | ✅ | ✅ |
| Own loans and fines | ❌ — asked to log in | ✅ own account only | — |
| Any member's loans | ❌ | ❌ | ✅ |
| Daily figures, diagnostics | ❌ | ❌ | ✅ |
| Checkout / check-in / renewal | ❌ | renewal of **own** loan, if enabled | ✅ with confirmation |
| Other member's data | ❌ | ❌ (scope forced server-side) | ✅ redacted PII |
| Delete anything | ❌ | ❌ | ❌ (no such command) |

A guest who types *"pinjaman saya"* gets a login link, not an error. A member
who types *"pinjaman anggota 999"* still only sees their own loans: the engine
overwrites the member id with `$_SESSION['mid']` before calling the service.

## Install

1. Copy the configuration and adjust it:

   ```bash
   cp config/chatbot.sample.php config/chatbot.php
   ```

2. Activate the plugin:

   ```bash
   php index.php plugin:list --type=all      # find the id
   php index.php plugin:activate <id>
   ```

   (Shorter form if you already generated the console shortcut: `php tarsius …`.)

3. Open the surfaces:

   * OPAC: `index.php?p=chatbot` — appears in the OPAC menu as **Chatbot**
   * Staff: **System → Chatbot** (`admin/plugin_container.php?mod=system&id=…`)

4. Check it before trusting it:

   ```bash
   php index.php chatbot:selftest
   php index.php chatbot:ask "pinjaman terlambat"
   ```


### Stock SLiMS, no core changes

The chatbot is additive: it registers a menu and an API hook through the
plugin API that ships with SLiMS 9 Bulian. No file outside `plugins/` and
`config/` is touched, and nothing has to be applied to `admin/`, `lib/` or
`index.php`.

```bash
# from the SLiMS root of a pristine 9 Bulian checkout
cp -r /path/to/repo/plugins/chatbot plugins/
cp /path/to/repo/config/chatbot.sample.php config/chatbot.php
php index.php plugin:list --type=all && php index.php plugin:activate <id>
```

A chatbot-only install still has a data layer: `Support/vendor/CliAnything.php`
is a copy of the CLI-Anything service, loaded only when
`plugins/Commands/Support/CliAnything.php` is absent (see
`Support/vendor/README.md`). Writes stay closed until you add
`config/cli_anything.php` with `allow_write` on — that file is read by the
service itself, so both surfaces obey the same switch:

```php
<?php
return ['allow_write' => true, 'user_id' => 1, 'user_name' => 'Chatbot'];
```

Requirements are the stock ones: PHP 7.4+ (8.x fine), MySQL/MariaDB with the
SLiMS 9 schema, and the bundled `simbio2`/`lib` classes. The chatbot reads
`biblio`, `item`, `member`, `loan`, `fines` — tables every installation has —
and writes through SLiMS' own circulation classes, so loan rules, receipts
and `system_log` entries are the ones the admin module produces.

## What it understands

Deterministic rules, both Indonesian and English. No model, no network call,
no token cost — and every answer maps to a command a human can run.

| You type | Intent | Command behind it |
| --- | --- | --- |
| `cari buku sejarah` / `find book history` | `biblio.list` | `clianything:biblio:list --q=sejarah` |
| `buku populer` / `terlaris` | `biblio.list` (`sort=popular`) | `clianything:biblio:list --sort=popular` |
| `buku terbaru` | `biblio.list` (`sort=newest`) | `clianything:biblio:list --sort=newest` |
| `apakah buku #12 tersedia?` | `biblio.get` | `clianything:biblio:get --id=12` |
| `cek item B00001` | `item.get` | `clianything:item:get --code=B00001` |
| `pinjaman saya` (member) | `my.loans` | `clianything:loan:list --member=<session>` |
| `denda saya` (member) | `my.profile` | `clianything:member:get --id=<session>` |
| `pinjaman terlambat` (staff) | `loan.list` | `clianything:loan:list --status=overdue` |
| `anggota 123` (staff) | `member.get` | `clianything:member:get --id=123` |
| `ringkasan hari ini` (staff) | `report.summary` | `clianything:report` |
| `pinjamkan B00001 ke anggota 123` | `loan.checkout` | `clianything:loan:checkout` |
| `kembalikan B00001` | `loan.checkin` | `clianything:loan:checkin` |
| `perpanjang pinjaman 77` | `loan.extend` | `clianything:loan:extend` |
| `perpanjang pinjaman saya` (member) | `my.extend` | same, own loan only |

Short text with no keyword (`laskar pelangi`) is treated as a catalogue
search rather than a dead end.

## Search that forgives typos

A library catalogue is typed by people in a hurry: `sejarh`, `laskr pelangi`,
`metodelogi`. The search treats that as normal input instead of an error.

**1. Clean the query.** Lowercase, punctuation dropped, filler words removed in
both languages (`buku`, `cari`, `tentang`, `the`, `find`, `populer`, …), at most
six unique tokens of two characters or more. `"Buku tentang Sejarah Indonesia?"`
becomes `["sejarah", "indonesia"]`.

**2. Three stages, each only reached when the previous found nothing.**

| Stage | What it does | Example |
| --- | --- | --- |
| `exact` | every token, matched anywhere in title, ISBN, call number, author, subject or publisher | `sejarah indonesia` |
| `prefix` | the same tokens shortened by two characters, so a typo near the end still lands | `sejarh` → `seja` |
| `suggestion` | "did you mean": titles scored with `similarity()` (normalised Levenshtein) | `laskr pelangi` → *Laskar Pelangi* 86% |

Only when all three fail does the answer become `no_results`, and even then it
keeps the tip block ("a short title, an author, an ISBN").

**3. Rank the matches.** Relevance is a sum over tokens, weighted by where the
match happened, and past loans break ties — so a well-loved title wins against
an equally relevant one, not against a better match.

| Field | Weight | Notes |
| --- | --- | --- |
| title, exact | 120 | case-insensitive, the collation decides |
| ISBN/ISSN, exact | 110 | a barcode scan lands here |
| title, starts with | 70 | |
| title, contains | 45 | |
| call number, starts with | 40 | |
| author | 30 | |
| subject | 25 | |
| publisher | 15 | |
| popularity | tie-break | number of loans ever recorded for that title |

The weights are the `search.weights` block of `config/chatbot.php` (see
[Tuning](#tuning)); the `prefix` stage multiplies them by 0.35, so an
approximate hit never outranks an exact one.

**4. Choose the ordering.** `sort` is part of the command, the HTTP query and
the chat intent:

| `sort` | Order | Ask for it with |
| --- | --- | --- |
| `relevance` (default) | rank score, then popularity, then newest | `cari buku sejarah` |
| `popular` | popularity, then rank score | `buku populer`, `terlaris`, `paling banyak dipinjam` |
| `newest` | `last_update` | `buku terbaru` |
| `title` | alphabetical | console/HTTP only |

"Buku populer" is not a phrase the intent table ignores: it resolves to a
catalogue browse with `sort=popular`, and the reply shows `41x dipinjam` next to
each row so the ordering is visible, not implied.

**5. Respect the OPAC.** Records with `opac_hide = 1` are excluded for guests
and members; staff searches include them, because a hidden record is often the
one a librarian is looking for.

### Tuning

Everything above lives in one config block. The chat hands it to the same
service the console uses, so a value applies to WhatsApp/Telegram answers too.
Omit a key and the built-in value stays.

```php
'search' => [
    'sort' => 'relevance',          // default ordering
    'typo' => true,                 // shortened-token rescue stage
    'suggestions' => 5,             // how many "did you mean" titles
    'min_similarity' => 0.55,       // below this a suggestion is noise
    'max_tokens' => 6,              // tokens kept after cleaning
    'stopwords' => ['skripsi'],     // extra filler words
    'fulltext' => true,             // use a FULLTEXT index if it exists
    'weights' => [
        'title_exact' => 120,
        'title_prefix' => 70,
        'author' => 30,
        // … any subset
    ],
],
```

A partial `weights` array is merged, not replaced, so bumping `author` to 45
keeps every other weight. The console reads the same keys from the `search`
block of `config/cli_anything.php`; per-call options (the chat) win over the
file, and the file wins over defaults.

`fulltext` is **opt-in by schema, never by us**: the service asks
`SHOW INDEX FROM biblio WHERE Index_type = 'FULLTEXT'` once per request and
adds `MATCH … AGAINST (… IN BOOLEAN MODE)` only when the answer is non-empty.
Stock SLiMS 9 has no such index, so the LIKE stages do the work; on a large
catalogue an operator may add one and get the faster path without touching a
single line of code:

```sql
ALTER TABLE biblio ADD FULLTEXT INDEX ft_biblio_title (title);
```

Nothing in the plugin runs `ALTER TABLE`, and a missing or broken index simply
degrades to the LIKE search instead of failing the query.

The same code answers all three surfaces, so a typo behaves the same from the
console, the webhook, and the browser:

```bash
php index.php clianything:biblio:list --q="sejarh"
php index.php clianything:biblio:list --q="" --sort=popular --limit=5
cli-anything-slims biblio list --q "laskr pelangi"
```

## The write flow (two steps, always)

```
user:  pinjamkan B00001 ke anggota 123
bot:   Rencana saja, belum ada yang ditulis
       Anggota 123 · Rina Kartika
       - Sejarah Indonesia Modern — B00001 · jatuh tempo 04/12/2026 [ready]
       Konfirmasi berlaku 180 detik dan hanya bisa dipakai sekali.
       [Konfirmasi] [Batal]
```

* The first call runs the service with `commit = false`. SLiMS' own loan-rule
  engine runs, the temporary loan session is thrown away, nothing is written.
* The reply carries a token: HMAC-signed with a per-session secret, bound to
  the role and actor, valid for `confirm_ttl` seconds, single use.
* Only `{confirm: token}` writes. A replay answers `confirm_invalid`; an
  expired token asks you to plan again.
* If `allow_write` (or `allow_admin_write` / `allow_member_write`) is closed,
  the plan is still shown, together with the reason it cannot be committed.

## Configuration

`config/chatbot.php` (see `config/chatbot.sample.php` for the long version):

| Key | Default | Meaning |
| --- | --- | --- |
| `enabled` | `true` | master switch |
| `opac`, `admin` | `true` | which surfaces exist |
| `language` | `auto` | `auto`, `id` or `en` |
| `allow_admin_write` | `false` | staff checkout/check-in/renewal from the chat |
| `allow_member_write` | `false` | member self-renewal |
| `allow_pii_reveal` | `false` | kept for parity; the chat always redacts |
| `rate_limit_per_minute` | `20` | messages per session |
| `max_writes_per_hour` | `5` | confirmations per session |
| `confirm_ttl` | `180` | token lifetime (seconds) |
| `max_message_length` | `500` | longer messages are refused |
| `result_limit` | `5` | rows shown per answer |
| `channel` | off | WhatsApp/Telegram webhook, see [Messaging channels](#messaging-channels) |

Turning a gate on still requires `allow_write` in `config/cli_anything.php`;
the chat never opens what the CLI keeps closed.

## HTTP contract

`POST index.php?p=chatbot&action=ask` (OPAC) or
`POST admin/plugin_container.php?mod=system&id=<hash>&action=ask` (staff).

```json
{"message": "pinjaman saya", "csrf_token": "…"}
```

```json
{
  "ok": true,
  "schema": "1.0",
  "role": "member",
  "locale": "id",
  "reply": {
    "text": "1 pinjaman aktif:",
    "blocks": [
      {"type": "list", "items": [
        {"title": "Sejarah Indonesia Modern", "meta": "B00001 · dipinjam 01/11/2026",
         "badge": "terlambat 19 hari", "tone": "warn"}
      ]}
    ]
  },
  "suggestions": ["pinjaman saya", "denda saya", "…"],
  "pending": null,
  "meta": {"writes_enabled": false, "confirm_ttl": 180, "intent": "my.loans"},
  "surface": "opac"
}
```

Block types: `text`, `notice` (`info|success|warn|error`), `facts`, `list`,
`link`. The browser renders every string with `textContent`, so a title
containing markup stays text.

Errors use the same envelope version as the CLI:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `CSRF_FAILED` | 403 | page reloaded or session expired |
| `EMPTY_MESSAGE` | 422 | neither a message nor a confirmation was sent |
| `CHATBOT_DISABLED` | 422 | surface switched off in `config/chatbot.php` |
| `confirm_invalid` / `confirm_expired` | 200 (in reply) | token replayed, tampered with, or too old |
| `BIBLIO_NOT_FOUND`, `MEMBER_NOT_FOUND`, `ITEM_NOT_FOUND`, `LOAN_NOT_FOUND` | 422 | passed through from the service |
| `WRITE_DISABLED`, `BULK_DISABLED`, `LOAN_REJECTED` | 422 | passed through from CLI-Anything |

## Messaging channels

<a id="messaging-channels"></a>

The same engine, reached from a webhook instead of a browser, so a library can
answer on WhatsApp or Telegram without a second implementation. Only three
things differ: **identity**, **confirmation** and **formatting**.

### Endpoint

```
POST index.php?p=api/chatbot/v1/message
GET  index.php?p=api/chatbot/v1/message      # WhatsApp Cloud handshake
```

Authentication is a single shared token, sent as `X-Chatbot-Token: <token>` or
`Authorization: Bearer <token>`. The route only exists when
`channel.enabled` is on *and* `channel.token` is not empty; otherwise
`404 CHATBOT_DISABLED`.

The `GET` form answers the Meta verification: with `hub.mode=subscribe` and a
matching `hub.verify_token` it echoes `hub.challenge`; anything else is `403`.

### Payloads

The body may be a native payload or the normalized short form; the channel is
detected, so one URL serves every gateway.

| Source | Shape understood |
| --- | --- |
| Telegram | `{"message": {"chat": {"id": 987654321}, "text": "pinjaman terlambat"}}` |
| WhatsApp Cloud | `{"entry": [{"changes": [{"value": {"messages": [{"from": "628123456789", "text": {"body": "…"}}]}}]}]}` |
| Local gateway | `{"phone": "628123456789", "message": "…"}` |
| Normalized | `{"channel": "telegram", "from": "987654321", "text": "…"}` |

### Identity

`channel.members` and `channel.staff` are the entire mapping, keyed
`<channel>:<from>` (lower-cased). A key that is not in either list is a
**guest** — never a member guessed from the message text, never an account
looked up by phone number. Staff entries map to a `uid`; member entries map to
a member id and are scoped by `Policy::scope()` exactly like a logged-in OPAC
session.

```php
'members' => ['whatsapp:628123456789' => 'M001'],
'staff'   => ['telegram:987654321'    => 1],
```

A guest asking *"pinjaman saya"* gets the login link, not an error.

### Confirmation without a cookie

There is no session to bind a token to, so `Engine(…, stateless: true)` returns
its `pending.action` as data, and `ChannelStore` keeps it per sender in one
guarded file (`<?php exit; ?>` + JSON, `flock`, TTL pruned on read). The reply
ends with *"Balas YA untuk menulis, atau BATAL untuk membatalkan."* — `YA`
(localized via `channel.confirm_words`) executes the plan once, `BATAL` drops
it, and an expired plan answers `channel_expired`. The plan is removed *before*
it runs, so a double-sent "YA" cannot check out two copies.

### Reply format

Plain text, capped at 1400 characters, cut with `…`. Facts render as
`Anggota: 123 · Rina`, records as `• Title` + indented meta + `[badge]`, and
notices with `[i]`, `[!]`, `[ok]`, `[x]`:

```
Rencana saja, belum ada yang ditulis
Anggota: 123 · Rina Kartika
• Sejarah Indonesia Modern
  B00001 · Jatuh tempo 04/12/2026 [ready]
[!] Periksa rinciannya, lalu konfirmasi. Konfirmasi berlaku 180 detik dan hanya bisa dipakai sekali.

Balas YA untuk menulis, atau BATAL untuk membatalkan.
```

### Errors

| Code | HTTP | Meaning |
| --- | --- | --- |
| `CHATBOT_DISABLED` | 404 | `channel.enabled` off or token empty |
| `UNAUTHORIZED` | 401 | missing or wrong token |
| `EMPTY_MESSAGE` | 422 | no text in the payload |
| `RATE_LIMITED` | 429 | over `rate_limit_per_minute` for this sender |
| `UNEXPECTED_ERROR` | 500 | see `system_log` |

### Storage

Pending actions and counters live in `channel.storage_dir`, defaulting to
`SB files/chatbot/`. The directory is created `0700` with a deny-all
`.htaccess` and an empty `index.html`; file names are `sha1(channel|from)`, so
a phone number never appears on disk. Two buckets are counted per sender:
`messages` (per minute) and `writes` (per hour, from `max_writes_per_hour`).

### Console equivalents

`php index.php chatbot:ask "pinjaman saya" --role=member --as=123` answers the
same question with the same engine, one message at a time and without the
store — useful to reproduce what a gateway reported.

## Console equivalents

```bash
php index.php chatbot:selftest                 # 97 offline checks, no DB
php index.php chatbot:ask "pinjaman terlambat" # staff view
php index.php chatbot:ask "pinjaman saya" --role=member --as=123
php index.php chatbot:ask "pinjamkan B00001 ke anggota 123" --commit
php index.php chatbot:ask "buku populer"                    # sort=popular
php index.php chatbot:ask "cari sejarh"                     # typo-tolerant
php index.php chatbot:ask "ringkasan hari ini" --json
```

`--commit` is the console form of the Confirm button: same gates, same audit,
no token needed because the flag itself is the operator's confirmation.

## Security notes

* **Role from the session.** `Actor::fromOpac()` reads `$_SESSION['mid']`
  (+ `utility::isMemberLogin()`); the staff page cannot even render without
  `admin/default/session_check.inc.php`. A crafted POST cannot promote itself.
* **Member scope is server-side.** `Policy::scope()` overwrites the member id
  for `my.*` intents; a member can never filter another account.
* **CSRF.** A per-session token is embedded in the page and required on every
  POST (`X-CSRF-Token` or `csrf_token` in the body).
* **Rate limits** per session for messages and for writes.
* **PII.** `memberGet()` is always called with redaction on, so email, phone
  and address arrive as `***redacted***`. There is no `reveal` path.
* **The webhook has its own token** and is off unless both `channel.enabled`
  and `channel.token` are set. It is a separate switch from the browser
  surfaces; the shared token never reaches the page.
* **Channel identity is an allow-list.** `channel.members` / `channel.staff`
  map a phone number or chat id to one account; an unknown sender is a guest.
  A message can never claim a role, and the member id is overwritten by
  `Policy::scope()` for `my.*` exactly as in the browser.
* **Pending actions are files, not sessions.** One `sha1(channel|from).php`
  per sender, guarded, locked and pruned; the action is deleted *before* it is
  executed, so a replayed "YA" finds nothing.
* **No destructive command** exists in CLI-Anything, so the chat cannot delete
  a record even if a message asks for it.
* **CSP-friendly.** No inline JavaScript; the small admin wrapper style moved
  into `assets/chatbot.css`.

## Files

```
plugins/chatbot/
├── chatbot.plugin.php          menu registration (opac + system)
├── bootstrap.php               `Chatbot\` autoloader
├── Support/
│   ├── Engine.php              intent -> service -> reply (the orchestrator)
│   ├── Intent.php              deterministic rule table (pure)
│   ├── Policy.php              role matrix, scoping, write gates (pure)
│   ├── Actor.php               role detection from the two SLiMS sessions
│   ├── Confirm.php             single-use HMAC confirmation tokens
│   ├── Channel.php             webhook -> identity -> engine -> plain text
│   ├── ChannelStore.php        per-sender pending action + counters (flock)
│   ├── Webhook.php             the `api/chatbot/v1/message` route
│   ├── RateLimit.php           per-session counters
│   ├── Http.php                POST / CSRF / JSON envelope
│   ├── View.php                server-rendered chat shell (escaped)
│   ├── Lang.php                English + Indonesian copy
│   ├── Str.php                 mbstring fallback
│   ├── Config.php              defaults + config/chatbot.php
│   ├── TestService.php         fake CLI-Anything for offline tests
│   ├── vendor/
│   │   ├── CliAnything.php     copy of the service, stock-install fallback
│   │   └── README.md           why it exists and how to re-sync it
│   └── SelfTest.php            97 assertions
├── pages/
│   ├── opac.php                ?p=chatbot (guest + member)
│   └── admin.php               System -> Chatbot (staff)
├── assets/
│   ├── chatbot.css
│   └── chatbot.js
└── tests/run.php               `php plugins/chatbot/tests/run.php`
```

## Testing

```bash
php plugins/chatbot/tests/run.php   # standalone, no SLiMS boot, no DB
php index.php chatbot:selftest      # inside SLiMS, same suite + table output
```

The suite covers the intent table (Indonesian + English), role policy,
confirmation replay/expiry/binding, rate limits, the engine against a fake
service, the HTTP pipeline (CSRF, empty message, disabled plugin), the messaging
channel (payload normalization, allow-list identity, guest/member/staff
replies, YA/BATAL flow, replay, throttle, stale action, guarded store), the
stock-install path (service resolution without the Commands plugin, envelope
version, bundled copy in sync, greeting without a database), the search
pipeline (tokenizer, similarity, ranking fragments, typo fallback, did-you-mean
rendering, sort hints, hidden records, configurable weights, the optional
FULLTEXT branch) and the escaping of the rendered shell.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| No *Chatbot* menu in OPAC | plugin not active | `php index.php plugin:list --type=all`, then `plugin:activate <id>` |
| `UNEXPECTED_ERROR` on every question, page still renders | the bundled service file is missing from `Support/vendor/` | re-copy `plugins/chatbot/`; `php plugins/chatbot/tests/run.php` checks for it |
| *"Writes are disabled"* on a stock install | `config/cli_anything.php` absent or `allow_write` off | create the file, turn `allow_write` on |
| "Asisten perpustakaan sedang dimatikan" | `enabled`/`opac`/`admin` is false | edit `config/chatbot.php` |
| Every send says the session expired | CSRF token missing (page cached, or cookies blocked) | reload the page; check the session driver |
| Confirm button answers "expired" | slower than `confirm_ttl` | ask again; the plan is rebuilt |
| Plan shown but never writable | `allow_write` or `allow_admin_write` is off | enable deliberately, then re-check `doctor` |
| Answer says `UNEXPECTED_ERROR` | the service threw (DB, missing `loan_history`) | run `php index.php clianything:doctor` |
