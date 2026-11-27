# CLI-Anything Bridge plugin

HTTP bridge that exposes the CLI-Anything service layer to agent harnesses
(`cli-anything-slims`) and to plain `curl`.

The plugin is **inert by default**: it registers no routes unless
`config/cli_anything.php` (or the environment) provides `enabled = true` **and**
a non-empty `token`.

## Setup

```bash
cp config/cli_anything.sample.php config/cli_anything.php
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"      # paste as 'token'
```

Then enable the plugin — the plugin only loads once it is active, like every
SLiMS plugin:

```bash
php tarsius plugin:list                    # note the id of "CLI-Anything Bridge"
php tarsius plugin:activate <id>
# or: System → Plugins in the admin UI
```

## Endpoints

Base path: `<slims>/index.php?p=api/cli-anything/v1/...`

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/status` | token | doctor: gates, counts, checks |
| GET | `/biblio?q=&limit=&offset=` | token | catalogue search |
| GET | `/biblio/{id}` | token | metadata + authors + subjects + items |
| GET | `/item/{item_code}` | token | copy + active loan |
| GET | `/member/{id}?reveal=1` | token | PII masked unless `allow_pii_reveal` |
| GET | `/loan?status=&member=&limit=` | token | active / overdue / returned / all |
| GET | `/report/summary` | token | aggregate numbers |
| POST | `/loan/checkout` | token + `allow_write` (+ `allow_bulk`) | body: `{"member":"M1","items":"B1,B2","commit":true}` |
| POST | `/loan/checkin` | token + `allow_write` | body: `{"loanid":12,"commit":true}` |
| POST | `/loan/extend` | token + `allow_write` | body: `{"loanid":12,"commit":true}` |

Authentication header:

```
X-CLI-Anything-Token: <token>
```

`Authorization: Bearer <token>` is accepted as well. The token is compared with
`hash_equals`, is never echoed back, and `doctor` only reports whether one is
configured.

## Try it

```bash
TOKEN=...; BASE="https://slims.sekolah.id/index.php?p=api/cli-anything/v1"

curl -s -H "X-CLI-Anything-Token: $TOKEN" "$BASE/status" | jq '.data.gates'
curl -s -H "X-CLI-Anything-Token: $TOKEN" "$BASE/biblio?q=sejarah&limit=3" | jq '.data.items[].title'

# dry run first: nothing is written even though the method is POST
curl -s -X POST -H "X-CLI-Anything-Token: $TOKEN" -H 'Content-Type: application/json' \
     -d '{"member":"M0001","items":"B00001","commit":false}' \
     "$BASE/loan/checkout" | jq '.data.items'
```

## Response contract

```json
{"ok": true, "schema": "1.0", "command": "loan.list", "data": {...},
 "meta": {"generated_at": "...", "write_enabled": false, "bulk_enabled": false, "transport": "http"}}
```

HTTP status follows the error code: `401` unauthorized, `403` gate refused,
`404` unknown endpoint / unknown identifier, `405` write endpoint called with
GET, `409` business-rule conflict (expired member, reservation, renewal limit),
`500` transaction or backup failure, `503` bridge not configured.

## Security notes

* Keep `allow_write` off until staff are trained; reads do not need it.
* Keep `redact_pii` on for anything that reaches an LLM; `allow_pii_reveal`
  should stay off unless a librarian explicitly needs unmasked contact data.
* Run SLiMS behind HTTPS. A shared token in a request header is only as private
  as the transport.
* Point `user_id` at a dedicated staff account so `system_log` isolates agent
  activity.
* Every committed write is logged, and bulk writes dump the database first to
  `files/backup/cli-anything-*.sql`.

See the "CLI-Anything" section of the repository README for the full design,
including the `tarsius` (local) backend and the Python harness.
