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

## What it understands

Deterministic rules, both Indonesian and English. No model, no network call,
no token cost — and every answer maps to a command a human can run.

| You type | Intent | Command behind it |
| --- | --- | --- |
| `cari buku sejarah` / `find book history` | `biblio.list` | `clianything:biblio:list --q=sejarah` |
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

## Console equivalents

```bash
php index.php chatbot:selftest                 # 56 offline checks, no DB
php index.php chatbot:ask "pinjaman terlambat" # staff view
php index.php chatbot:ask "pinjaman saya" --role=member --as=123
php index.php chatbot:ask "pinjamkan B00001 ke anggota 123" --commit
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
│   ├── RateLimit.php           per-session counters
│   ├── Http.php                POST / CSRF / JSON envelope
│   ├── View.php                server-rendered chat shell (escaped)
│   ├── Lang.php                English + Indonesian copy
│   ├── Str.php                 mbstring fallback
│   ├── Config.php              defaults + config/chatbot.php
│   ├── TestService.php         fake CLI-Anything for offline tests
│   └── SelfTest.php            56 assertions
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
service, the HTTP pipeline (CSRF, empty message, disabled plugin) and the
escaping of the rendered shell.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| No *Chatbot* menu in OPAC | plugin not active | `php index.php plugin:list --type=all`, then `plugin:activate <id>` |
| "Asisten perpustakaan sedang dimatikan" | `enabled`/`opac`/`admin` is false | edit `config/chatbot.php` |
| Every send says the session expired | CSRF token missing (page cached, or cookies blocked) | reload the page; check the session driver |
| Confirm button answers "expired" | slower than `confirm_ttl` | ask again; the plan is rebuilt |
| Plan shown but never writable | `allow_write` or `allow_admin_write` is off | enable deliberately, then re-check `doctor` |
| Answer says `UNEXPECTED_ERROR` | the service threw (DB, missing `loan_history`) | run `php index.php clianything:doctor` |
