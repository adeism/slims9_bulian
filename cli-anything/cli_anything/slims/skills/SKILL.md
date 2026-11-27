---
name: cli-anything-slims
description: Operate a SLiMS 9 Bulian library system from the terminal — search the catalogue, inspect items and patrons, watch circulation, and (only when explicitly enabled) check items in and out with dry-run-first safeguards. Use this skill whenever a task involves SLiMS data, library circulation or Indonesian school/campus library administration.
---

# cli-anything-slims

`cli-anything-slims` is a CLI-Anything harness for **SLiMS 9 Bulian**. It never
re-implements library rules: every call reaches a real SLiMS backend, either the
CLI-Anything HTTP bridge plugin or the bundled `tarsius` console commands.

## Before you start

```bash
export SLIMS_BASE_URL="https://slims.sekolah.id"       # HTTP bridge (recommended)
export SLIMS_CLI_ANYTHING_TOKEN="<shared token>"       # required by the bridge
# or, on the server itself:
export SLIMS_ROOT="/var/www/slims"                     # tarsius backend
```

Run the health check first — it tells you whether writes are allowed:

```bash
cli-anything-slims --json doctor
```

`doctor.data.gates.write_enabled` is `false` on a default install. In that state
every write command still works **as a dry run** and refuses to persist.

## Command groups

| Command | What it does | Writes? |
|---|---|---|
| `doctor` | Gates, limits, table counts, deployment checks | no |
| `biblio list --q --limit --offset` | Catalogue search (title, ISBN/ISSN, call number, author) | no |
| `biblio get <biblio_id>` | Metadata + authors + subjects + copies | no |
| `item get <item_code>` | One copy (barcode) and its active loan | no |
| `member get <member_id> [--reveal]` | Patron card + loan summary (PII masked by default) | no |
| `loan list --status active\|overdue\|returned\|all [--member]` | Circulation listing | no |
| `report summary` | Collection, membership, circulation, fines | no |
| `loan checkout --member M --items B1,B2 [--commit]` | Check out | gated |
| `loan checkin --loan-id 12` or `--item-code B1 [--commit]` | Return | gated |
| `loan extend --loan-id 12 [--commit]` | Renew | gated |

## Output contract

Always prefer `--json`. Every call (success or failure) prints exactly one JSON
envelope:

```json
{
  "ok": true,
  "schema": "1.0",
  "command": "biblio.list",
  "data": { "items": [ ... ], "count": 1, "total": 1, "limit": 20, "offset": 0 },
  "meta": { "generated_at": "2026-11-27T09:12:44+07:00", "write_enabled": false }
}
```

Failures keep the same shape with `ok: false` and an `error` object:

```json
{"ok": false, "schema": "1.0",
 "error": {"code": "WRITE_DISABLED", "message": "Write operations are disabled (loan.checkout).", "context": {}}}
```

Error codes you must branch on:

| Code | Meaning | What to do |
|---|---|---|
| `WRITE_DISABLED` | `allow_write` is off | report to the operator; do not retry |
| `BULK_DISABLED` | multi-record call without `allow_bulk` | split into single-item calls or ask the operator |
| `MEMBER_NOT_FOUND` / `ITEM_NOT_FOUND` / `BIBLIO_NOT_FOUND` / `LOAN_NOT_FOUND` | bad identifier | re-check the barcode / member id |
| `MEMBER_EXPIRED`, `MEMBER_PENDING` | membership blocks lending | hand over to a librarian |
| `LOAN_REJECTED` | at least one item failed loan rules; **nothing was written** | read `error.context.items[].status` and explain |
| `ITEM_RESERVED` | reserved by another patron | do not force; escalate |
| `RENEW_LIMIT_REACHED` | reborrow limit reached | propose a return + new loan only with human approval |
| `UNAUTHORIZED` | missing/invalid token | ask the operator for the token; never guess it |
| `PII_REVEAL_DISABLED` | `--reveal` not permitted | use the masked payload |

Exit codes: `0` success, `1` backend/other error, `2` usage error, `3` gate
refused (`WRITE_DISABLED`, `BULK_DISABLED`, `PII_REVEAL_DISABLED`).

## Safety rules for agents

1. **Read first.** Cash-in questions ("is this book available?", "what is
   overdue?") are answered by read commands only.
2. **Dry run first.** Call a write command without `--commit`, show the plan
   (due dates, fines, rejections), and only then re-run with `--commit` — and
   only with human confirmation when the plan changes more than one record.
3. **One item per commit** unless the operator explicitly enabled bulk writes;
   bulk writes force a database dump before touching anything.
4. **Never invent identifiers.** Item codes are barcodes, member ids are card
   numbers; resolve them with `item get` / `member get` first.
5. **Do not exfiltrate PII.** `member get` masks e-mail, phone and address.
   Do not ask for `--reveal` unless a named librarian asked for it.
6. **No deletes.** This harness has no delete command by design. Removing
   catalogue or patron records stays a human decision in the admin UI.

## Typical workflows

```bash
# "Where is this copy?"
cli-anything-slims --json item get B00012

# "What is overdue this week?"
cli-anything-slims --json loan list --status overdue --limit 50

# "Check out two books for Siti" (plan, then commit)
cli-anything-slims --json loan checkout --member M0001 --items B00001,B00002
cli-anything-slims --json loan checkout --member M0001 --items B00001,B00002 --commit

# "Return everything Siti brought back"
cli-anything-slims --json loan checkin --item-code B00001 --commit

# Daily numbers for a report or a chat bot reply
cli-anything-slims --json report summary
```

## Troubleshooting

* `NO_BACKEND` — neither `--base-url` nor `--slims-root` is configured.
* `CONNECTION_FAILED` — wrong URL, offline, or SLiMS behind a proxy that strips
  the `X-CLI-Anything-Token` header.
* `BRIDGE_NOT_CONFIGURED` (HTTP 503) — the token is empty in
  `config/cli_anything.php`, so the bridge plugin registers no routes.
* `BAD_RESPONSE` with HTML — the URL points at the OPAC instead of the bridge;
  the path must be `<base>/index.php?p=api/cli-anything/v1/...`.
* `BAD_RESPONSE` with `tarsius exited ...` — run `php tarsius tarsius` once to
  create the console entry point, and check that PHP CLI has `mysqli`,
  `mbstring` and `gettext`.
