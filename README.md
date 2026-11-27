SLiMS 9 Bulian
===============
SENAYAN Library Management System (SLiMS) version 9 Codename Bulian

SLiMS is free open source software for library resources management
(such as books, journals, digital document and other library materials)
and administration such as collection circulation, collection management,
membership, stock taking and many other else.

SLiMS is licensed under GNU GPL version 3. Please read "GPL-3.0 License.txt"
to learn more about GPL.

Looking for scripted/agent access (catalogue search, circulation, reports) instead
of the web UI? Jump to [CLI-Anything: agent-native access to SLiMS](#cli-anything-agent-native-access-to-slims).

### System Requirements
- PHP version >= 8.1;
- MySQL version 5.7 and or MariaDB version 10.3;
- PHP GD enabled
- PHP gettext enabled
- PHP mbstring enabled

### Running Docker Development

Using docker for running requires Docker Desktop service, as it will use Docker to create the database.
Before run docker compose copy .env.example to .env for setting http port and maria db (db) port 
can be change on .env

You can run the test using this command :

```shell
docker compose up -d --build
```

Setting docker default can be change on docker-compose.yml

CLI-Anything: agent-native access to SLiMS
------------------------------------------

SLiMS is driven by the web UI, which is fine for librarians and terrible for
agents, scripts and cron jobs. This repository therefore ships a
[CLI-Anything](https://github.com/HKUDS/CLI-Anything) harness: a thin, gated,
JSON-first command surface over the *real* SLiMS backend.

Nothing is re-implemented. Reads run against the SLiMS schema, writes go through
SLiMS' own `member` / `circulation` classes, so loan rules, holiday handling,
fines, reservations, `loan_history` and `system_log` keep behaving exactly like
the admin GUI.

```
agent ──► cli-anything-slims (Python CLI)
             ├─► HTTP bridge plugin      plugins/cli_anything/     (remote, token)
             └─► tarsius commands        plugins/Commands/          (local, PHP)
                         │
librarian ─► chatbot plugin             plugins/chatbot/           (OPAC + admin, session)
                         │
                   SLiMS core ──► circulation / member ──► MySQL
```

All three entry points call one service, so a rule learned in the console is a
rule enforced in the chat.

### What is included

| Phase | Piece | Path | Purpose |
|---|---|---|---|
| Fase 0 | HTTP bridge | `plugins/cli_anything/` | token protected read endpoints under `index.php?p=api/cli-anything/v1/...` |
| Fase 1 | Console commands | `plugins/Commands/CliAnything*.php` | `clianything:*` commands inside SLiMS' own Symfony-based `tarsius` console |
| Fase 1 | Shared service | `plugins/Commands/Support/` | queries, error codes, session isolation, action rendering |
| Fase 2 | Write gates | `plugins/Commands/Support/CliAnything.php` | dry run by default, `--commit`, `allow_write` / `allow_bulk`, pre-bulk DB dump, `system_log` audit |
| Fase 3 | Chatbot | `plugins/chatbot/` | percakapan untuk tamu / anggota / staf di OPAC dan admin, di atas service yang sama; tulis selalu dua langkah (rencana → konfirmasi) |
| — | Chatbot config | `config/chatbot.sample.php` | permukaan yang aktif, gerbang tulis chat, batas laju, bahasa |
| — | Agent harness | `cli-anything/` | installable Python CLI `cli-anything-slims` + `SKILL.md` + 29 tests |
| — | Configuration | `config/cli_anything.sample.php` | token, gates, PII policy, backup dir |
| — | Docs drafts | `docs/slims-documentation/` | halaman siap-kirim untuk `slims/SLiMS-9-Documentation` (dev guide + panduan pustakawan) |

### Quick start

1. **Local (no HTTP, nothing exposed):**

   ```bash
   cp config/cli_anything.sample.php config/cli_anything.php   # optional, for gates
   php index.php clianything:doctor
   php index.php clianything:biblio:list --q="sejarah" --limit=5 --json

   # optional shortcut used throughout the SLiMS docs
   php index.php tarsius && php tarsius clianything:doctor
   ```

   `tarsius` is SLiMS' own console runner (`lib/Cli`); the `clianything:*`
   commands are discovered from `plugins/Commands/` automatically, and
   `--json` gives you exactly the same envelope as the HTTP bridge.

2. **Remote (agent on a laptop, SLiMS untouched):**

   ```bash
   cp config/cli_anything.sample.php config/cli_anything.php
   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"     # paste as 'token'

   # activate the bridge (or use System → Plugins in the admin UI)
   php tarsius plugin:list                                # note the id of "CLI-Anything Bridge"
   php tarsius plugin:activate <id>
   ```

   ```bash
   pip install ./cli-anything
   export SLIMS_BASE_URL="https://slims.sekolah.id"
   export SLIMS_CLI_ANYTHING_TOKEN="<token>"
   cli-anything-slims doctor
   cli-anything-slims biblio list --q "metodologi" --limit 5
   ```

   No Python, no extra package and no filesystem access is needed on the server
   itself for this mode.

### Command surface

| Command | Purpose | Writes |
|---|---|---|
| `doctor` | gates, table counts, deployment checks | no |
| `biblio list --q --limit --offset` | catalogue search (title, ISBN/ISSN, call number, author) | no |
| `biblio get <id>` | metadata, authors, subjects, copies with loan status | no |
| `item get <item_code>` | one copy and its active loan | no |
| `member get <id> [--reveal]` | patron card + loan summary, PII masked by default | no |
| `loan list --status active\|overdue\|returned\|all` | circulation listing | no |
| `report summary` | collection, membership, circulation, fines | no |
| `loan checkout --member M --items B1,B2 [--commit]` | check out | gated |
| `loan checkin --loan-id 12 \| --item-code B1 [--commit]` | return | gated |
| `loan extend --loan-id 12 [--commit]` | renew | gated |

Both entry points share one envelope:

```json
{"ok": true, "schema": "1.0", "command": "loan.list", "data": {...},
 "meta": {"generated_at": "...", "write_enabled": false, "bulk_enabled": false}}
{"ok": false, "schema": "1.0",
 "error": {"code": "WRITE_DISABLED", "message": "...", "context": {}}}
```

HTTP status codes follow the error code (`401`, `403`, `404`, `405`, `409`,
`500`, `503`), and the CLI exits `0` ok / `1` backend error / `2` usage /
`3` gate refused. The full code table lives in
`cli-anything/skills/cli-anything-slims/SKILL.md`.

### Write path: four gates, dry run first

| Gate | Default | Where |
|---|---|---|
| `allow_write` — any write at all | **off** | `config/cli_anything.php` / `SLIMS_CLI_ANYTHING_ALLOW_WRITE` |
| `--commit` / `commit=1` — this call | **off** | CLI flag / request body |
| `allow_bulk` — more than one record | **off** | `config/cli_anything.php` / `SLIMS_CLI_ANYTHING_ALLOW_BULK` |
| automatic database dump before bulk writes | always on | `files/backup/cli-anything-*.sql` |

A write command without `--commit` is a **real dry run**: it calls SLiMS' own
rule engine (`addLoanSession`, `countOverdueValue`, loan-rule period maths,
reservation checks) and reports planned due dates, fines and rejection reasons
while writing nothing. All-or-nothing: if one item in a multi-item checkout is
rejected, the call fails with `LOAN_REJECTED` and no loan is written.

Every committed write is recorded in `system_log` under the `CLI-Anything`
location with the configured staff account, so agent activity is separable from
human activity in **System → System Log**.

### Privacy and security model

* A shared token (`X-CLI-Anything-Token` or `Authorization: Bearer`) guards the
  bridge; an empty token disables it entirely (no routes are registered).
  Tokens are compared with `hash_equals` and never echoed back.
* The bridge plugin only loads when activated in **System → Plugins**, i.e. it is
  opt-in like any other plugin.
* `redact_pii` masks e-mail, phone, fax and address in every payload; HTTP
  clients cannot bypass it unless the operator sets `allow_pii_reveal`.
* No delete command exists for catalogue or patron records, by design.
* Prefer HTTPS; a token is only as private as its transport.
* Run the harness on the operator's machine or in CI, not on the library server,
  when you want to avoid installing Python in production.

### Chatbot: the same engine, with a face

`plugins/chatbot/` turns the command surface into a conversation, without
duplicating a single query. It is the only way a librarian reaches the engine
without a terminal, and the only place where the role is decided by a session
rather than by a token.

| | Guest (OPAC) | Member (logged in) | Staff (admin) |
|---|---|---|---|
| Catalogue search, title detail, copy status | ✅ | ✅ | ✅ |
| Own loans and fines | ❌ → login link | ✅ own account only | — |
| Any member's circulation | ❌ | ❌ (id forced server-side) | ✅ |
| Daily figures, diagnostics | ❌ | ❌ | ✅ |
| Checkout / check-in / renewal | ❌ | own renewal, gated | ✅, after confirmation |
| Delete | ❌ | ❌ | ❌ (no such command) |

A transaction is always two steps: the first answer is a dry run plus a
single-use, HMAC-signed token (180 s); only `{confirm: token}` writes. That
token is bound to the session role and actor, so a replayed request, a double
click, or "yes" for a different plan cannot commit anything.

```bash
cp config/chatbot.sample.php config/chatbot.php
cp plugins/chatbot/Support/faq.sample.php config/chatbot.faq.php   # optional
php index.php plugin:list --type=all && php index.php plugin:activate <id>

php index.php chatbot:selftest                      # 178 offline checks
php index.php chatbot:ask "pinjaman terlambat"      # staff view
php index.php chatbot:ask "pinjaman saya" --role=member --as=123
php index.php chatbot:ask "pinjamkan B00001 ke anggota 123" --commit

php index.php chatbot:log --days=7 --unanswered  # what was never answered
php index.php chatbot:eval                       # accuracy, typo tolerance, parity
php index.php chatbot:eval --sweep               # FAQ threshold table
CHATBOT_E2E=1 php index.php chatbot:e2e          # read-only live-DB dialogues
```

Surfaces: `index.php?p=chatbot` (OPAC menu) and **System → Chatbot** (staff).
The same engine also answers a webhook — `POST index.php?p=api/chatbot/v1/message`
with `X-Chatbot-Token` — so WhatsApp or Telegram can talk to it; senders are
mapped to a member id or a staff uid by an explicit allow-list, and everyone
else is a guest. Full details — what the install adds, benefits, risks with their mitigations,
role matrix, payload shapes, JSON contract and rollback — are in
`plugins/chatbot/README.md`.

Catalogue search does not assume clean input. The query is tokenised and
stripped of filler words, matched in three stages (`exact` → shortened token →
"did you mean"), and ranked by field (title > ISBN > call number > author >
subject > publisher) with past loans as the tie-breaker; `--sort=popular` or a
message like "buku populer" puts the most borrowed titles first. Weights, the
typo stage and the suggestion count are configurable (`search` block in
`config/chatbot.php` / `config/cli_anything.php`), and a FULLTEXT index on
`biblio.title` is used automatically when the schema has one — without the
plugin ever altering a table. Records hidden from the OPAC stay hidden for
guests and members.

Routine questions that have nothing to do with the catalogue — opening hours,
phone number, address, membership, fines — are answered from one content file
(`config/chatbot.faq.php`, seeded from `plugins/chatbot/Support/faq.sample.php`
with 15 common entries). The library fills that one page with its real policy;
`php index.php chatbot:faq` validates it. The FAQ is only consulted when a
message is *not* a command, so "pinjaman saya" stays a loan question.

Everything the chat does is measurable and correctable without a deploy. It
keeps an append-only conversation log (`files/chatbot/`, no table: salted
actor hash, no name or IP, truncated question, 90-day retention) and the staff
area gains three pages beside the chat: **history** (filters, one conversation,
CSV, the week's numbers), **review** (unanswered and thumbs-down queue; add a
keyword, create a draft entry, hide one, block a question, preview before
saving, restore the previous version) and **rules** (blocked questions and
forbidden answers, with an over-blocking count). Corrections land in an overlay
file, never in `config/chatbot.faq.php`. `chatbot:eval` runs a 122-sentence
corpus in both languages and gates on accuracy, typo tolerance, threshold
stability, language parity and the render contract; `chatbot:e2e` replays
read-only dialogues against the live database.

Nothing in the core is patched: the chatbot uses the stock plugin API
(`registerMenu`, and the `custom_api_route` hook that `api/v1/routes.php`
already executes), the stock circulation classes for writes, and the stock
`system_log` for audit. A chatbot-only copy on a pristine SLiMS 9 Bulian still
works — it carries the service as `Support/vendor/CliAnything.php` and loads it
only when `plugins/Commands/Support/CliAnything.php` is absent.

### Recipes

```bash
# nightly overdue report for a WhatsApp/Telegram bot
0 7 * * * cd /var/www/slims && php tarsius clianything:loan:list --status=overdue --limit=100 --json \
          > /var/www/slims/files/overdue.json

# bulk check-in of a returning class set (dry run, then commit)
cli-anything-slims --json loan checkin --item-code B00041
cli-anything-slims --json loan checkin --item-code B00041 --commit

# CI smoke test against a docker-compose SLiMS
docker compose up -d --build
cli-anything-slims --backend tarsius --slims-root "$PWD" --json doctor
```

### What this deliberately does not do

* **No direct SQL writes.** Bypassing `circulation_action`/`bibliography` would
  skip loan rules, fines, `loan_history` and plugin hooks.
* **No catalogue authoring or deletion.** Cataloguing, MARC import and weeding
  stay in the admin UI until a gated command exists with a verified rollback.
* **No scraping of the admin pages.** Session + CSRF + IP checks make that the
  fragile RPA approach CLI-Anything exists to replace.
* **No `preview` / `trajectory`.** Those CLI-Anything features target creative
  desktop software; SLiMS produces no visual artefact. What is reused is the
  harness pattern: real backend, JSON envelopes, `SKILL.md`, dry-run-first
  writes.

### Documentation

Two drop-in pages for the official docs repository
([`slims/SLiMS-9-Documentation`](https://github.com/slims/SLiMS-9-Documentation),
Docusaurus, autogenerated sidebar) are drafted in `docs/slims-documentation/`:

* `development-guide/06-Console/05-CLI-Anything.md` — technical page that sits next
  to the existing Console/Tarsius pages.
* `user-guide/Modules/7-CLI-Anything/00_intro.md` — librarian-facing page: what it
  can answer, what stays forbidden, the enable-writes checklist.

`docs/slims-documentation/README.md` records the destination paths, the remaining
screenshots, and the fork → branch → PR steps. No `sidebars.js` change is needed
because the sidebar is autogenerated.

### Testing

```bash
cd cli-anything && pip install -e ".[dev]" && pytest
```

With Docker available, the full stack can be exercised end to end:

```bash
cp .env.example .env
CLI_ANYTHING_E2E=1 ./cli-anything/scripts/e2e.sh
```

It boots the compose stack, activates the bridge with a random token, seeds a
throw-away patron, then runs doctor → item lookup → dry-run checkout → commit →
check-in through both backends, asserting the envelopes along the way.

29 tests cover the envelope contract, dry-run/commit forwarding, gate exit
codes, HTTP request shaping (token header, path escaping, POST bodies, error
mapping) and `tarsius` argv/JSON parsing. They need no database.

The chatbot plugin has its own offline suite (no database, no SLiMS boot):

```bash
php plugins/chatbot/tests/run.php     # standalone runner
php index.php chatbot:selftest        # inside SLiMS, same suite
```

178 assertions cover the Indonesian/English intent table, role policy (guest,
member, staff), confirmation replay/expiry/session binding, rate limits, the
engine against a fake service, the HTTP pipeline (CSRF, empty message, disabled
plugin), the WhatsApp/Telegram channel (payload shapes, allow-list identity,
guest/member/staff answers, YA/BATAL confirmation, replay, throttle), the search
pipeline (typo fallback, did-you-mean, popularity ranking, hidden records), the
FAQ (content validation, phrase/typo matching, the coverage guard, locale
selection), the conversation log and staff history, the correction overlay and
its audit trail, the guard (bypass variants, answer screening, over-blocking),
the evaluation targets, and the escaping of the rendered shell.

Before enabling writes on a real instance, run `php -l` over the new PHP files,
then `php tarsius clianything:doctor` and the write commands **without**
`--commit` to inspect the plan against your own data.

### Licensing

The harness is a separate work (GPL-3.0-or-later here, consistent with SLiMS;
the upstream CLI-Anything project is Apache-2.0 and only provides the pattern
and tooling). Keeping the Python package out of the SLiMS process means the two
licences never mix.
