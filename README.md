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
                   SLiMS core ──► circulation / member ──► MySQL
```

### What is included

| Phase | Piece | Path | Purpose |
|---|---|---|---|
| Fase 0 | HTTP bridge | `plugins/cli_anything/` | token protected read endpoints under `index.php?p=api/cli-anything/v1/...` |
| Fase 1 | Console commands | `plugins/Commands/CliAnything*.php` | `clianything:*` commands inside SLiMS' own Symfony-based `tarsius` console |
| Fase 1 | Shared service | `plugins/Commands/Support/` | queries, error codes, session isolation, action rendering |
| Fase 2 | Write gates | `plugins/Commands/Support/CliAnything.php` | dry run by default, `--commit`, `allow_write` / `allow_bulk`, pre-bulk DB dump, `system_log` audit |
| — | Agent harness | `cli-anything/` | installable Python CLI `cli-anything-slims` + `SKILL.md` + 25 tests |
| — | Configuration | `config/cli_anything.sample.php` | token, gates, PII policy, backup dir |

### Quick start

1. **Local (no HTTP, nothing exposed):**

   ```bash
   cp config/cli_anything.sample.php config/cli_anything.php   # optional, for gates
   php tarsius tarsius                                         # create the console entry point once
   php tarsius clianything:doctor
   php tarsius clianything:biblio:list --q="sejarah" --limit=5 --json
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

25 tests cover the envelope contract, dry-run/commit forwarding, gate exit
codes, HTTP request shaping (token header, path escaping, POST bodies, error
mapping) and `tarsius` argv/JSON parsing. They need no database.

Before enabling writes on a real instance, run `php -l` over the new PHP files,
then `php tarsius clianything:doctor` and the write commands **without**
`--commit` to inspect the plan against your own data.

### Licensing

The harness is a separate work (GPL-3.0-or-later here, consistent with SLiMS;
the upstream CLI-Anything project is Apache-2.0 and only provides the pattern
and tooling). Keeping the Python package out of the SLiMS process means the two
licences never mix.
