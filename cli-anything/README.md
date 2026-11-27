# cli-anything-slims

An agent-native CLI for **SLiMS 9 Bulian**, built as a
[CLI-Anything](https://github.com/HKUDS/CLI-Anything) harness: the commands in
this package never talk to the database directly — they always go through a real
SLiMS backend, so loan rules, fines, reservations, `loan_history` and audit logs
behave exactly like the admin GUI.

```
agent / librarian
        │  cli-anything-slims  (Python, this package)
        ├─────────────► HTTP bridge plugin        (plugins/cli_anything)
        │                 index.php?p=api/cli-anything/v1/...
        └─────────────► SLiMS console commands    (plugins/Commands)
                          php index.php clianything:...
                                    │
                              SLiMS core ──► member / circulation classes ──► MySQL
```

## Install

```bash
pipx install ./cli-anything          # or: pip install ./cli-anything
cli-anything-slims --version
```

Point it at an instance

```bash
# A) remote: the bridge plugin must be active and have a token
export SLIMS_BASE_URL="https://slims.sekolah.id"
export SLIMS_CLI_ANYTHING_TOKEN="<token from config/cli_anything.php>"

# B) local: run the bundled console commands on the server
export SLIMS_ROOT="/var/www/slims"
# uses <root>/tarsius when it exists, otherwise <root>/index.php
```

Both transports return the same envelope, so a workflow can be moved from a
laptop to a cron job without changing a single command.

## Commands

```
cli-anything-slims doctor
cli-anything-slims biblio list --q "sejarah" --limit 5 --offset 0
cli-anything-slims biblio get 42
cli-anything-slims item get B00012
cli-anything-slims member get M0001 [--reveal]
cli-anything-slims loan list --status active|overdue|returned|all [--member M0001]
cli-anything-slims loan checkout --member M0001 --items B00001,B00002 [--commit]
cli-anything-slims loan checkin --loan-id 12 | --item-code B00001 [--commit]
cli-anything-slims loan extend --loan-id 12 [--commit]
cli-anything-slims report summary
```

Global options: `--json`, `--backend http|tarsius`, `--base-url`, `--token`,
`--slims-root`, `--tarsius`, `--php`, `--timeout`. `--json` also works after the
sub-command, which is what agents usually emit.

## Output

`--json` prints exactly one envelope:

```json
{"ok": true, "schema": "1.0", "command": "loan.list",
 "data": {"items": [{"loan_id": 12, "item_code": "B00001", "due_date": "2026-12-11", "overdue_days": 0}],
          "count": 1, "total": 1, "status": "active"},
 "meta": {"generated_at": "...", "write_enabled": false, "bulk_enabled": false}}
```

Without `--json` the same payload is rendered as readable tables (dry-run and
committed banners included).

Exit codes: `0` ok · `1` backend error · `2` usage error · `3` gate refused.

## Writes are gated

| Layer | Default | Where |
|---|---|---|
| `allow_write` (any write) | **off** | `config/cli_anything.php` or `SLIMS_CLI_ANYTHING_ALLOW_WRITE` |
| `--commit` (this call) | **off** (dry run) | CLI flag |
| `allow_bulk` (>1 record) | **off** | `SLIMS_CLI_ANYTHING_ALLOW_BULK` |
| automatic DB dump before bulk writes | always | `files/backup/cli-anything-*.sql` |
| audit trail in `system_log` | always | via `SLiMS\Log\Factory` |

A write command without `--commit` executes SLiMS' own rule engine and reports
the plan — planned due dates, fines, rejection reasons — while writing nothing.

## Skill file

`skills/cli-anything-slims/SKILL.md` is the canonical agent skill (command
reference, JSON contract, error codes, safety rules). A packaged copy lives at
`cli_anything/slims/skills/SKILL.md` so `pip install` users have it offline.

```bash
npx skills add HKUDS/CLI-Anything --skill cli-anything-slims -g -y   # after upstreaming
# or copy skills/cli-anything-slims/ into your agent's skills directory
```

## Development

```bash
pip install -e ".[dev]"
pytest
```

The test suite covers the envelope contract, dry-run/commit flag forwarding,
gate exit codes, HTTP request shaping (token header, path escaping, POST body)
and `tarsius` argv/JSON parsing. No SLiMS instance is required.

## Layout

```
cli-anything/
├── pyproject.toml
├── cli_anything/slims/
│   ├── cli.py        # Click commands
│   ├── backend.py    # HTTP bridge + tarsius transports, envelope errors
│   ├── output.py     # JSON + human rendering
│   └── skills/SKILL.md
├── skills/cli-anything-slims/SKILL.md
└── tests/
```

## Non-goals

* No direct SQL writes, by design.
* No delete commands for catalogue/patron records.
* No bulk MARC import (yet) — catalogue creation stays in the admin UI.
* The CLI-Anything `preview` / `trajectory` features do not apply here; SLiMS
  has no visual artefact to preview. What is reused is the harness pattern:
  real backend, structured JSON, `SKILL.md`, dry-run-first writes.
