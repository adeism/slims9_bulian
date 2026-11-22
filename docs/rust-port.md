# SLiMS 9 Bulian — Rust rewrite

Status: **Phase 1 implementation in place; validation incomplete** (2026-11-22).
The OPAC and REST API v1 subset runs from a Rust binary with a memory backend or
a MySQL-protocol client targeting the SLiMS schema. The original PHP application
is untouched. Real MySQL and live PHP comparison have not yet been verified;
do not interpret the gate as a production cutover certification.

| | |
| --- | --- |
| Original | 3,994 PHP files, ~255,900 lines, PHP ≥ 8.1 + MySQL/MariaDB |
| Ported in Phase 1 | OPAC (home, search, detail, legacy `index.php` URLs) and all 15 REST API v1 routes |
| Rust code | `rust/` — one crate, no external dependencies |
| Validation | 24 source-derived fixture cases (23 per backend) and MySQL wire-protocol round-trip against a SQLite-backed double; no live PHP/MySQL parity test yet |
| Not ported yet | admin modules, circulation workflows, reporting, plugins, OAI-PMH, installer |

The work follows the [rust-anything](https://github.com/erkamyaman/rust-anything)
discipline — **record → port → check → lift** — with one adaptation: rust-anything
3.1 supports PHP for recording, replay and the front door, but not yet for
per-unit bridges, so the recording is a hand-maintained JSON fixture and the
replay is a test rather than a generated bridge.

---

## What runs today

### OPAC

| Route | Original | Rust |
| --- | --- | --- |
| `/` | `index.php` welcome page | `rust/src/opac.rs::home` |
| `/search?q=…` | `index.php?search=search&keywords=…` | `rust/src/opac.rs::search` |
| `/search?title=&author=&subject=&isbn=` | advanced search | same handler, field filters |
| `/biblio/{id}` | `index.php?p=show_detail&id={id}` | `rust/src/opac.rs::detail` |
| `/index.php?p=show_detail&id={id}` | legacy bookmarks | `rust/src/opac.rs::legacy` |
| `/index.php?search=search&keywords={…}` | legacy search links | same |
| `/member` | member area | informational page (sign-in is Phase 2) |
| `/healthz` | — | backend and library name |

The UI is a clean rewrite (one stylesheet, `rust/static/opac.css`), not a
pixel-for-pixel copy of the Bootstrap/Vue template. It keeps the same
information architecture: search, facets, result rows with availability, and a
detail page with copies and subjects. Cover images and static assets are served
from the repository's existing `images/`, `css/`, `js/` and `template/`
directories.

### REST API v1

Every route in `api/v1/routes.php`, with the same JSON shapes (including the
string-typed numbers that mysqli produces):

| Route | PHP | Rust |
| --- | --- | --- |
| `GET /api/` | `HomeController@index` | `api::home` |
| `GET /api/biblio/popular` | `BiblioController@getPopular` | `api::biblio_popular` |
| `GET /api/biblio/latest` | `BiblioController@getLatest` | `api::biblio_latest` |
| `GET /api/biblio/gmd/{gmd}` | `BiblioController@getByGmd` | `api::biblio_by_gmd` |
| `GET /api/biblio/coll_type/{type}` | `BiblioController@getByCollType` | `api::biblio_by_coll_type` |
| `GET /api/biblio/total/all` | `BiblioController@getTotalAll` | `api::biblio_total` |
| `GET /api/subject/popular` | `SubjectController@getPopular` | `api::subject_popular` |
| `GET /api/subject/latest` | `SubjectController@getLatest` | `api::subject_latest` |
| `GET /api/member/top` | `MemberController@getTopMember` | `api::member_top` |
| `GET /api/item/total/all` | `ItemController@getTotalAll` | `api::item_total` |
| `GET /api/item/total/lent` | `ItemController@getTotalLent` | `api::item_total` |
| `GET /api/item/total/available` | `ItemController@getTotalAvailable` | `api::item_total` |
| `GET /api/loan/summary` | `LoanController@getSummary` | `api::loan_summary` |
| `GET /api/loan/getdate/{date}` | `LoanController@getDate` | `api::loan_dates` |
| `GET /api/loan/summary/{date}` | `LoanController@getSummaryDate` | `api::loan_summary_date` |

The `image` field is byte-compatible: it still points at
`./lib/minigalnano/createthumb.php?filename=…&width=120`, and the Rust server
implements that endpoint (serving the original file; the stylesheet scales it).
The popular and subject-popular responses keep the five-hour file-cache
semantics of `api/v1/helpers/Cache.php`, in memory.

---

## Architecture

```
browser ──HTTP──> http::serve (thread per connection)
                     │
                     ├── /api/…        api.rs ──┐
                     ├── /images/…      app.rs │
                     ├── /static/…             ├── Store (trait)
                     └── /…             opac.rs│   ├── MemoryStore  (install/*.sql)
                                                │   └── MySqlStore  (wire protocol)
                                                └── models.rs / json.rs / date.rs
```

- **`json.rs`** — dependency-free JSON with insertion-ordered objects and
  PHP-compatible escaping (`/` → `\/`, non-ASCII → `\uXXXX`).
- **`http.rs`** — HTTP/1.1 parsing, keep-alive, HEAD, static files, a raw client
  used by tests and the gate.
- **`store`** — the storage boundary. `MemoryStore` parses the installer's SQL
  dumps (`install/senayan.sql`, `install/sampledata.sql`) and adds a small
  deterministic circulation set so the API endpoints have data without a
  database. `MySqlStore` speaks the MySQL text protocol (handshake,
  `mysql_native_password`, `caching_sha2_password` fast path, `COM_QUERY`,
  column definitions, result rows) against the existing SLiMS schema.
- **`api.rs`** — the fifteen routes and their PHP-shaped JSON.
- **`opac.rs` / `templates.rs`** — server-rendered HTML.
- **`app.rs`** — routing, static files, the thumbnail endpoint, `/healthz`.

There are no external crates. The sandbox that produced this port could reach
GitHub and npm but not crates.io, and SLiMS itself is a self-contained PHP app;
writing the small runtime (JSON, HTTP, MySQL text protocol, SHA-1/SHA-256)
keeps the dependency surface honest and the build reproducible.

---

## Behavior gate (the rust-anything part)

`rust/fixtures/behavior/cases.json` is the recording. It holds 24 cases:
15 exact-JSON responses, 3 shape checks for date-dependent or backend-dependent
values, and 6 HTML pages. One of the JSON cases is the memory backend's
`/healthz` and another is the MySQL backend's, so 23 cases apply to any given
build. The fixture is replayed in the following places:

1. `cargo test --test behavior` — in-process server, memory backend.
2. `python3 tools/gate.py --url http://127.0.0.1:8080` — any running build.
3. `tools/mysql_roundtrip.sh` — the same recording through the MySQL wire
   protocol against `tools/fake_mysql.py`.
A separate `tools/record.py` tool can capture API GET responses from a live
PHP instance; it was not used to produce the current fixture. Use `--expect-json`
to include parsed JSON expectations for the gate.

A case may carry `"backends": ["memory"]` or `["mysql"]`. `gate.py` asks
`/healthz` which backend it is talking to and skips the cases that do not
apply, so one recording replays against either build.

`tools/gate.py` compares JSON semantically (key order does not matter), checks
`contains` for HTML and `shape` for date-dependent endpoints. It exits non-zero
on any mismatch.

The fixture was produced from the PHP controllers' SQL and the sample data.
Once a PHP instance with the same database is available, `tools/record.py`
turns it into a true before/after recording; `cases.json` should then be
replaced by that recording, and the gate becomes a real parity check.

### MySQL proof without a MySQL server

`tools/mysql_roundtrip.sh` installs `mysql-mimic` into a temporary virtualenv,
starts `tools/fake_mysql.py`, points the binary at it and checks two things:
the wire protocol (handshake, `mysql_native_password`, `COM_QUERY`, column
definitions, text rows, hydration) and then the whole behavior recording
through that protocol.

The fake is not a list of canned answers. It loads `install/senayan.sql` and
`install/sampledata.sql` into an in-memory SQLite database (converting the DDL,
unescaping MySQL string literals the way the server does), adds the same
deterministic demo circulation the memory store uses, and then translates each
query the store issues from MySQL to SQLite with sqlglot and executes it. A
mistake in the store's SQL therefore shows up as a wrong row or a failed case
rather than being hidden by a matching prefix. Three small shims cover what
SQLite does not have: `YEAR()`, `NOW()` and numeric comparison with a quoted
number.

```
$ tools/mysql_roundtrip.sh
ok   /healthz
ok   /api/biblio/latest
...
PASS  api-loan-summary-date
23/23 cases passed against http://127.0.0.1:8099 (1 skipped)

mysql round-trip: OK
  handshake + mysql_native_password: ok
  COM_QUERY + column definitions + text rows: ok
  hydration through the store: ok
  behavior recording: 23/23 cases passed
```

That run is what caught three real defects in the MySQL store: an `IN ()`
list built from an empty id set (a syntax error on MySQL), an unstable
`ORDER BY total DESC` that made the popular list depend on the server's
whim, and a `getLatest` subjects query whose order differed from the demo
backend's.

---

## Run it

```bash
# demo: no database, seeded from install/*.sql
cd rust && cargo run --offline        # http://0.0.0.0:8080

# against an existing SLiMS database
SLIMS_DB='mysql://user:password@127.0.0.1:3306/senayan' \
  cargo run --release --offline

# tests
cargo test --offline                 # unit tests + behavior gate

# gate a running build
python3 tools/gate.py --url http://127.0.0.1:8080

# record a live PHP instance
python3 tools/record.py --base-url http://127.0.0.1:8881
```

### Configuration

| Variable | Default | Meaning |
| --- | --- | --- |
| `SLIMS_ADDR` | `0.0.0.0:8080` | bind address |
| `SLIMS_DB` | `memory` | `memory` or `mysql://user:pass@host:port/db` |
| `SLIMS_ROOT` | repository root | where `install/*.sql` and `images/` live |
| `SLIMS_LIBRARY_NAME` | `SLiMS 9 Bulian` | header and titles |
| `SLIMS_LIBRARY_SUBNAME` | `Senayan Library Management System` | subtitle |
| `SLIMS_POPULAR_LIMIT` | `6` | `classic_popular_collection_item` |
| `SLIMS_LATEST_LIMIT` | `6` | home page "new in the catalog" |
| `SLIMS_CACHE_TTL` | `18000` | API cache seconds (PHP uses 18,000) |
| `SLIMS_HTTP_CACHE_LIFETIME` | `0` | `max-age` when the `SLiMS-Http-Cache` header is sent |

The MySQL store is read-only: Phase 1 never writes to the database, so it can
run against a production replica. Set `SLIMS_DB` to a read-only account.

---

## Deliberate deviations

These are recorded rather than hidden; each one is a decision in
`rust/DECISIONS.md`.

- **LIKE instead of `MATCH … AGAINST`.** SLiMS uses MySQL FULLTEXT in boolean
  mode for `keywords`, `title` and `notes`. The Rust port uses `LIKE` across
  title, series, notes, ISBN, classification, call number, publisher, author and
  topic. Substring search behaves the same on the demo store and on MySQL, and
  avoids FULLTEXT's minimum word length and stop-word list. Relevance ordering
  is not reproduced (SLiMS orders by `last_update`, and so does the port).
- **Empty loan table.** PHP's `getPopular` builds `NOT IN ()` when there are no
  loans, which is a MySQL syntax error. The Rust port falls back to the latest
  titles instead of returning a 500.
- **Thumbnails.** `createthumb.php` resizes images with PHP GD. The Rust
  endpoint serves the original file and lets CSS size it; the URL and JSON
  field are unchanged.
- **UI.** The OPAC is a new server-rendered design, not a port of the
  Bootstrap/Vue template. URLs, fields and JSON are preserved.
- **Member sign-in** is not ported in Phase 1 (SLiMS uses bcrypt via
  `password_verify`; the demo data has no member passwords).
- **Caching** is in-process with the same five-hour TTL, not file-based. A
  multi-process deployment would need a shared cache.

---

## Roadmap

| Phase | Scope | Gate |
| --- | --- | --- |
| **1 (done)** | OPAC search/detail + REST API v1, memory and MySQL backends | 23/23 applicable behavior cases on both backends, MySQL round-trip |
| 2 | Member sign-in, member area, loan history, reserves | PHP session parity; bcrypt; CSRF |
| 3 | Circulation (checkout/return/renew), fines, loan rules | transaction tests against MySQL; audit log |
| 4 | Admin bibliography: cataloging, items, authorities, imports (MARC/Z39.50) | record/replay admin forms |
| 5 | Reporting, stock take, plugins, OAI-PMH, cutover | PHP and Rust answer the same recorded traffic; `rust/cutover.sh` |

Each phase adds recording cases before it adds code. The PHP app stays the
source of truth until the gate passes for a phase; then the proxy moves that
path to Rust, with a one-line rollback.

---

## Files

| Path | Purpose |
| --- | --- |
| `rust/Cargo.toml` | one crate, no dependencies |
| `rust/src/` | the rewrite |
| `rust/fixtures/behavior/cases.json` | the behavior recording (24 cases) |
| `rust/tests/behavior.rs` | in-process replay |
| `rust/DECISIONS.md` | ADRs for the decisions above |
| `rust/MAP.md` | PHP area → Rust module/state |
| `rust/HANDOFF.md` | how to continue the port |
| `rust/cutover.sh` | interactive proxy cutover and rollback |
| `tools/gate.py` | replay the recording against any build |
| `tools/record.py` | record a live PHP instance |
| `tools/fake_mysql.py` | MySQL protocol test server (dataset-backed, SQL-speaking) |
| `tools/mysql_roundtrip.sh` | run the protocol test |


## Phase 2: experimental member area

An opt-in prototype now provides bcrypt login, sessions with CSRF (memory by
default, optional local SQLite),
POST logout, the current-loan and loan-history views, and read-only member details. It is disabled in the
default build and is **not suitable for production or real member data**: its rate limiting is process-local, and there
is no multi-host session store, MySQL TLS, PHP parity recording, legacy
MD5 upgrade or write path. Real reservations, password changes and bookmarks remain
unimplemented. An opt-in memory-only reservation demo loses all changes on restart. See `rust/README.md` for the disposable demo and
`rust/DECISIONS.md` ADR-0014 through ADR-0016 for the boundaries and security limitations.


The experimental member session is bound to the current password-hash
fingerprint and is revalidated on protected requests. Changed or missing
credentials revoke it; database errors fail closed. SQLite session databases
from the preceding version are migrated atomically and existing authenticated
sessions are logged out. This is not full account-status or PHP session parity;
see `rust/DECISIONS.md` ADR-0017.
