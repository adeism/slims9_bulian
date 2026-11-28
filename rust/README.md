# slims9-rs

A Rust rewrite of the public surface of SLiMS 9 Bulian: the OPAC and the REST
API v1, running against the existing database schema with no migration.

```bash
cargo run --offline                 # demo, http://0.0.0.0:8080
SLIMS_DB='mysql://user:pass@127.0.0.1:3306/senayan' cargo run --release --offline
cargo test --offline                # unit tests + 24-case behavior gate
```

No external crates. `src/` contains the HTTP server, JSON, MySQL text protocol,
SQL seed loader and templates. See `../docs/rust-port.md` for the architecture,
the behavior gate and the roadmap; `DECISIONS.md`, `MAP.md` and `HANDOFF.md`
for the working notes.

```
src/
  main.rs  app.rs  opac.rs  templates.rs  api.rs  models.rs
  store/{mod,seed,memory,mysql}.rs
  http.rs  json.rs  date.rs  config.rs
fixtures/behavior/cases.json   the recording the gate replays
tests/behavior.rs              in-process replay
```

### Member area status

Phase 2 is **experimental and opt-in**: bcrypt login, CSRF-protected POST
logout, paginated current loans/history, account and bookmark views, a
session-scoped title basket, and optional local SQLite sessions. Database writes
are disabled by default. Test-only switches add password change, legacy MD5
upgrade, bookmark writes and a limited reservation prototype on disposable
local databases. The default build still serves the Phase 1 placeholder.

This is **not ready for production or real member data**. In particular there
is no multi-host session store, distributed limiter, MySQL TLS, LDAP/captcha,
production audit/last-login handling, or safe PHP/Rust concurrent reservation protocol.
The test write paths are not a production reservation implementation. We tested
a disposable MySQL 5.7.29 instance and the SQL-speaking double, but did not
record parity against a running PHP application or test all MySQL/MariaDB versions.

For a disposable demo (known credentials; never use with real members):

```bash
export SLIMS_MEMBER_EXPERIMENTAL=1 SLIMS_DEMO_RESERVATIONS=1
export SLIMS_DEMO_MEMBER_HASH='$2y$04$qGzOSwDpk3DwkERJbP.zsuFsyyjmAMwbQByLMBLb/Y77gxpIIJWGO'
cargo run --offline --features member-crypt
# /member — ID M001, password: siti rahma; try Demo reservations, title ID 2
```

The feature needs Linux libcrypt with bcrypt and `crypt_ra`. Cookies are Secure
by default and therefore require HTTPS (a TLS reverse proxy is suitable).
For **local HTTP testing only**, set `SLIMS_MEMBER_INSECURE_COOKIE=1`. The demo
hash is an explicit memory-only override; no default member password is set.
Run `cargo test --offline --features member-crypt`. See ADR-0013/0014 and
`HANDOFF.md` for security limitations and the wire-double integration test.


By default Title Bookmark lists existing `biblio_mark` rows read-only for the
signed-in member, 20 per page, newest first.
Like the PHP list it joins existing bibliographic records, including hidden
ones already bookmarked; orphaned title IDs are omitted. The date is shown as
stored, not localized. Pages are capped at 5,001 (100,020 rows), and offset
pagination can shift if bookmarks are modified while browsing. Memory mode
reads fixture rows at startup. MySQL bookmark owner matching is byte-exact,
so historical rows with differently cased IDs can be missed even if a PHP
collation would match them. PHP parity is unverified.

Current loans and history also use 20-row offset pages, capped at 5,001 pages.
Current loans mark overdue dates with elapsed calendar days (UTC). A due date
of today is not overdue; malformed/unknown dates are escaped but not classified.
This is display-only, not a fine/holiday calculation, renewal permission or
PHP timezone parity. Returned history does not show an overdue badge.
Offset pages may shift under concurrent updates; unlike PHP, the views are not
an unpaginated list. The legacy PHP download scripts produce HTML attachments;
the combined report now has a bounded Rust replacement (see ADR-0046).
Member requests reject duplicate `sec`, `page` and `format` selectors and
multiple POST Content-Type headers. Cookies are inspected across all Cookie
headers; a repeated member-session cookie is not accepted even if identical.
Split Cookie headers containing only one member-session cookie remain supported.
Member responses use no-store, nosniff and no-referrer headers; these controls
are defense in depth, not a completed security review.

Each loan/history page offers `format=csv` (up to 20 rows, not all loans), using
the same member/session and page checks. CSV is UTF-8 with BOM, quoted fields
and CRLF records. Formula-like or leading-control cell values receive an
apostrophe prefix for spreadsheet safety, so this is not a lossless backup.
Null values become empty fields; filenames contain no member information.
Offset exports may shift under concurrent updates just like HTML pagination.

The Title Basket accepts visible catalog IDs, up to a fixed limit of 20 per
session. It supports add, remove and clear using CSRF-protected POST forms.
It is neither a bookmark nor a reservation; it is discarded on logout or
session expiry. With local SQLite sessions it survives a process restart until
expiry and is shared by workers using the same session database. It is not
compatible with PHP sessions or the PHP bulk reserve-from-basket workflow.
When demo or test reservations are explicitly enabled, each basket row offers
its corresponding single-title reservation POST. Success, denial and unknown
outcome all leave the basket intact; inspect the result before retrying. There
is no email/plugin integration; opt-in test/demo batch submission is described below. Stop old
workers before upgrading the SQLite session schema or they can race new code.

Demo reservations use only the in-memory backend and vanish on restart; they do
not reserve items in SLiMS. Duplicate and member-type limit checks are atomic
within one process. `SLIMS_DEMO_RESERVATIONS=1` is rejected on MySQL or without
member mode. The single-process limiter permits 8 login attempts per member and
200 globally per 10 minutes, with at most 4 concurrent verifications. It is
not distributed or IP-aware, can be abused to deny service, and resets on
restart; use an independently managed edge limiter before production use.


### Disposable database write tests — never production

`SLIMS_MEMBER_TEST_WRITES=1` enables test-only add/remove bookmark, password
change, legacy MD5 upgrade and credential-verified login timestamps. It requires member mode; a MySQL backend is
accepted **only** at `127.0.0.1` with a database name starting `slims_test`.
This is a guard, not proof that a database is disposable. Memory writes are
process-local and disappear on restart; with shared SQLite sessions, separate
memory-store workers do not share passwords/bookmarks. `crypt_gensalt_rn` and
`crypt_ra` from Linux libcrypt are needed. New passwords are 8–72 bytes with
ASCII uppercase, digit and non-alphanumeric; this is fixed, not PHP-configured.
No whitespace trimming is applied. Legacy MD5 passwords over 72 bytes are
rejected rather than silently truncated; a successful upgrade changes the stored
hash and invalidates other sessions on their next protected request.

`SLIMS_MEMBER_TEST_RESERVATIONS=1` additionally enables single-title
reservations to the disposable MySQL DB, using the default direct, all-eligible-
items policy and a table lock to serialize **cooperating Rust test writers**.
Pending and expired memberships are denied under the same table lock as the
insert; a missing or invalid expiry fails closed. Expiry day is inclusive using
UTC, not the configurable PHP timezone. Login, loan/account reads and password
changes remain available for these members. It shows at most 100 rows. This is not PHP basket/email/plugin parity and does
not support loan-only selection, configurable policies or cancellation. An
unmodified PHP writer can check before the lock and insert afterwards: duplicate
and limit guarantees do **not** hold when writers coexist. Do not use this path
on real databases. The SQLite session transaction is held over network I/O;
slow writes block other sessions. A lost write response produces HTTP 503 with
an explicitly unknown outcome, and the write is never automatically retried.

For repeatable testing create a new, empty disposable MySQL database with a
name beginning `slims_test`, grant a local test user access, and install PyMySQL
in a virtual environment. The setup script refuses nonempty/non-local databases.
The test itself **deletes and changes fixture members, bookmarks, loans and
reservations**; never point it at real data:

```bash
export SLIMS_TEST_MYSQL_URL='mysql://testuser:testpass@127.0.0.1:3306/slims_test_example'
SLIMS_TEST_CREATE_FIXTURE=1 python3 tools/setup_member_mysql_test.py
# Read suite requires the FRESH pagination fixture, before destructive writes.
cargo test --offline --manifest-path rust/Cargo.toml --features member-sqlite \
  --test member_mysql -- --ignored
SLIMS_TEST_ALLOW_RESET=1 cargo test --offline --manifest-path rust/Cargo.toml \
  --features member-sqlite --test member_mysql_writes -- --ignored
cargo test --offline --manifest-path rust/Cargo.toml --features member-sqlite \
  --test member_mysql member_loan_queries_use_the_wire_and_isolate_members -- --ignored
```

The installer now includes the same 22 owner bookmarks, one other-member row
and one orphan as the wire-double login fixture. The destructive test removes
these bookmarks while testing writes; to rerun the full read suite, prepare a
new empty test database rather than pointing the installer at a nonempty one.

The optional `SLIMS_TEST_SESSION_DB` may point to a private local session file.
The database remains in place after tests; remove it using your normal disposable
test-environment cleanup. See ADR-0021 for scope and failure semantics.

### Stored reservations (read-only)

`/member?sec=reservation_list` shows the signed-in member's latest 100 stored
`reserve` rows, ordered by reservation ID descending, without enabling test
writes. Ownership is byte-exact; historical case-variant member IDs can be
omitted compared with a case-insensitive PHP collation. Missing catalog
references are omitted, and NULL dates/item codes display as empty values.
This view does not infer fulfillment/cancellation status or offer cancellation.
Memory reads installer/fixture reserve rows separately from temporary demo
reservations. MySQL reads the real table; no new SQL write path is added.

### Actions from catalog details

With member mode enabled, catalog details link to an authenticated, no-store
confirmation page (`/member?sec=title_actions&biblio_id=ID`). It offers basket
addition and, only when their test/demo flags are enabled, bookmark/reservation
POSTs. GET/HEAD only display forms. Hidden/missing titles are refused and all
POST handlers recheck their normal rules. Public catalog pages remain session-
independent and contain no CSRF tokens. If signed out, the login form carries
only a validated numeric title ID and returns to its confirmation page after
sign-in (also after an incorrect-password retry). No arbitrary return URL is
accepted, and login never submits a title action. Missing or newly hidden titles
are rechecked at the confirmation page.

### Sign out of all Rust sessions

My Account provides a CSRF-protected POST to revoke all sessions for the signed-
in member in the current Rust session store, including the caller and its
baskets. No library database write flag is required. In-memory sessions cover
one process only; SQLite covers workers sharing the same file. PHP sessions,
other stores and requests already authorized/in flight are not canceled. A
concurrent sign-in completing afterwards can create a new session. This does
not replace changing a compromised password. Authorization and deletion share
a memory lock or SQLite transaction; storage failure returns 503, not success.

### Optional local persistent sessions (experimental)

By default sessions remain in process memory and disappear on restart. For
persistence on one host, enable the `member-sqlite` feature (which also enables
`member-crypt`) and specify a database in an **existing private directory**:

```bash
mkdir -m 700 /absolute/path/to/private-session-dir
export SLIMS_SESSION_DB=/absolute/path/to/private-session-dir/sessions.sqlite
cargo run --offline --features member-sqlite
```

Set `SLIMS_MEMBER_EXPERIMENTAL=1` as above. The process user must own the
folder and database; the folder and file must have no group/other permissions.
The file is created mode 0600 if absent. Linux `libsqlite3.so.0` is required.
SQLite uses local file locks and must not be used across hosts or on a network
filesystem. Configure all processes sharing the file with the same application,
database and member settings; only sessions are shared, **not** the rate limit
or demo reservations. Raw cookie IDs are stored only as SHA-256 digests, but
CSRF tokens, basket IDs and member IDs/names are not encrypted; protect backups and journals.
Expiry uses the wall clock (30 minutes), expired records are cleaned on session
creation. On each protected request, a fingerprint of the current stored
password hash is compared with the login-time fingerprint; password-hash change
or a missing member revokes the session. Backend failures return HTTP 503
without granting access. Expiry/pending status changes and restoration of the
same hash are not detected, and a credential change concurrent with a request
may race its check. Migrating a legacy SQLite schema without credential
fingerprints logs out authenticated sessions. The later basket migration keeps
credential-bound sessions and starts them with empty baskets. Stop every old
worker before migration.
Run `cargo test --offline --features member-sqlite`. See ADR-0016/0017; this
remains a prototype, not production-ready.

## Selected member deployment target

Rust is intended to replace the PHP member area, while PHP administration
remains. An opt-in PHP `SLIMS_MEMBER_OWNER=rust` gate and a staged cutover/
rollback checklist are described in [`docs/member-cutover.md`](../docs/member-cutover.md).
This does not enable production Rust writes or prove that all PHP/plugin
writers are coordinated. The Phase 1 cutover script is not a member installer.

## HTTP transport constraints

The HTTP/1.x parser now caps the request-line/header read itself at 64 KiB,
not just the size after an unbounded read. Bodies remain capped at 1 MiB.
It requires CRLF lines, valid header names and complete headers, origin-form
request targets and HTTP/1.0 or HTTP/1.1. HTTP/1.1 requires one nonempty Host.
Duplicate Content-Length (even identical), malformed lengths, Transfer-Encoding
and Expect are refused by closing the connection before handler dispatch.
Chunked requests/100-continue are unsupported; configure the reverse proxy to
send unambiguous Content-Length-framed bodies. HTTP/1.0 connections close after
a response. Connection close tokens are honored across header lists.

Response serialization preserves explicit member no-referrer/nosniff policies
instead of appending weaker duplicate defaults. This is partial hardening, not
full HTTP compliance or a request-smuggling audit.

Each listener admits at most 128 active worker connections. Excess connections
are closed immediately without a queued worker or blocking error write. A
connection serves at most 100 requests, with Connection: close on the last.
Each complete request (including keep-alive idle, headers and body) has a shared
15-second absolute socket-read deadline; sending occasional bytes does not
renew it. These fixed prototype limits also apply to OPAC/API routes. Permits
are released on EOF, parser/I/O error, spawn failure or worker unwinding.

This is not a distributed/IP-aware limiter or a complete resource budget.
Handler/database work is not canceled by the read deadline. Response writes now
have a separate absolute 15-second budget beginning after handler completion,
before serialization; partial writes do not renew it. Write timeout closes the
connection without retrying the action. A database change can already have
committed when delivery fails: never assume a missing response means failure.
OS accept backlogs, memory per active request, multiple processes/listeners, trusted proxy
configuration and load/security testing remain deployment concerns.

### Local HTTP smoke test with a real fixture

`SLIMS_TEST_HTTP_ALLOW=1 python3 tools/test_member_http.py --url http://127.0.0.1:8080`
requires a running member server on the disposable fixture, with test SQL writes
and test reservations OFF. For local HTTP only it needs insecure test cookies.
It checks post-login title continuation, basket, CSV, stored reservations,
write refusal, logout-all and exact security headers on the wire. It creates a
basket and revokes M001 sessions in that store; never run it with real data.
The literal local-host check is not proof that the backend DB is disposable.

### Last-login timestamp (test-write mode)

My Account displays the stored `last_login` value, escaped and only to its
owner. Normal read-only login leaves it unchanged. Test-write mode records
`UTC_TIMESTAMP()` only after password verification (and any MD5 upgrade), with
byte-exact member/hash predicates. Wrong passwords and stale credentials do not
record a login. MySQL's unchanged-row case within the same second is verified
without replaying the UPDATE. The old `last_login_ip` is cleared to NULL: trusted
peer/proxy IP handling is not implemented, and an old address must not be paired
with the new timestamp. No password or forwarded IP value is stored by this path.

This is a verified-credential attempt timestamp, not a durable audit event or
proof a session was issued: database update precedes session rotation and the
two stores have no shared transaction. Lost SQL acknowledgement returns an
unknown outcome and no new session; a timestamp can have been written even when
login cannot finish. Memory timestamps are process-local. Existing legacy values
are displayed as stored and may use a different timezone.

Basket reservations (ADR-0039) are available only in demo reservation mode or
explicit test-reservation mode. The server reads the live session basket, not
submitted title/member IDs. Results may be partial and the basket is retained.
On an unconfirmed error, reconcile reservations before retrying; no automatic
retry occurs. Batch work holds the session lock/SQLite transaction, and its
15-second between-title budget cannot cancel an in-flight DB operation.

HTTP bookmark writes (ADR-0041) recheck the session credential version inside
the mutation lock. MySQL also rechecks catalog visibility for additions there;
hidden/deleted additions return 404, while removal can clean orphan bookmarks.
Unsupported backends fail closed. The write is never automatically replayed if
its outcome is unknown. These remain opt-in disposable-test writes.

MySQL wire limits (ADR-0042): handshake and each SQL command have a 20-second
absolute I/O budget. Requests/logical packets are capped at 8 MiB, aggregate
response packet bytes at 32 MiB, and results at 256 columns / 10,000 rows /
262,144 cells. Oversized results fail, not truncate; multipart packets are not
supported under this cap. Partial socket progress does not renew the deadline.
These are not whole-request deadlines: DNS/connect, lock waits, decoding and
multiple queries/reconnects can add time. Writes remain one-shot; a failed
acknowledgement must be reconciled before retry. TLS is still not implemented.

MySQL response integrity (ADR-0043): packet sequences and complete EOF/OK
responses are checked, row payloads must match their declared columns, and
unexpected additional result sets are refused. Multi-result capability is not
advertised. Command/parser errors close the socket rather than reuse an
unsynchronized stream; the read-only reconnect path can still retry once on a
new connection. This is not peer authentication or TLS protection.

MySQL authentication (ADR-0044) accepts only native-password and caching-SHA2
fast authentication; full SHA2 auth, RSA and cleartext plugins remain refused.
Plugin-switch responses use raw scrambles, nonce zero bytes are preserved, and
unsupported initial plugins fail before sending a credential-derived response.
Real validation covers MySQL 5.7.29 native auth; SHA2 is scripted-peer tested,
not MySQL 8 deployment acceptance. The connection still has no built-in TLS.

### One-command disposable validation

See [`../docs/member-validation.md`](../docs/member-validation.md) for
`tools/test_member_suite.py`: all three Rust regressions, fresh MySQL reads,
live member/OPAC HTTP checks and destructive writes in the correct order,
with optional isolated PHP checks and credential-free JSON results. Requires
explicit consent and an existing empty local `slims_test*` database. No database
creation/drop or production enablement is performed.

MySQL TCP candidates share a 5-second budget after DNS (at most eight addresses);
the pooled read-connection queue allows 3 seconds. These complement the per-SQL
I/O budget, not a total HTTP deadline or server-side query cancellation.

### Combined member loan report (PHP feature port)

`/member?sec=loan_report` shows a private, printable report with the member's
profile and both current/returned loans; append `&format=csv` for a combined
CSV. `/index.php?p=download_member_loan_report` is a GET/HEAD-only alias when
requests are routed to Rust and experimental member mode is on. This replaces
the feature of PHP's combined HTML download; it is not identical markup or a
server-generated PDF. The single-section legacy downloads are now supported as described below (ADR-0048). PHP sessions are never accepted by Rust.

Reports use one bounded loan read (profile/credential checks are separate),
current loans first then newest dates/IDs. Over 1000 loans or 512 KiB raw report
fields returns 413 without a partial file; use paginated views for larger
accounts. CSV omits profile metadata and neutralizes spreadsheet formulas.
The report is read-only, requires refreshed authentication and retains no-store
headers. Print styling is same-origin external CSS compatible with member CSP.

### Backend foundation: strict startup and request telemetry

The executable now validates settings before listening using
`runtime::RuntimeConfig`; invalid database URLs no longer silently select memory.
Transport limits are configurable via `SLIMS_HTTP_MAX_CONNECTIONS`,
`SLIMS_HTTP_MAX_REQUESTS`, `SLIMS_HTTP_READ_SECONDS`, `SLIMS_HTTP_WRITE_SECONDS`.
See `.env.example` (documentation, not auto-loaded) and
[`../docs/backend-foundation.md`](../docs/backend-foundation.md) for ranges.
Parsed requests receive a server-owned `X-Request-ID`; JSON access events are
redacted by allowlist and can be disabled with `SLIMS_HTTP_ACCESS_LOG=0`.
They indicate a prepared response, not delivery or durable audit.

Framework migration is still pending: this build does not use Axum/Tokio, SQLx,
Askama/MiniJinja or tracing. Crates.io index/downloads were inaccessible during
this implementation; no unbuildable dependency was added. Typed startup, limits
and telemetry are implemented/tested independently, preserving offline builds.

### Current/history download scripts (ADR-0048)

All three PHP member download features now have bounded Rust replacements.
`/index.php?p=download_current_loan` and `?p=download_loan_history` return private
HTML attachments (`current_loan.html` / `loan_history.html`) for the signed-in
Rust member. Append `&format=csv` for scoped CSV. POST, forged owner selectors,
conflicting scope/section/page and duplicate selectors are refused.

Canonical views: `/member?sec=loan_report&scope=current` or `scope=history`;
add `&format=html` to download HTML, or `&format=csv`. Scope defaults to `all`.
Filtering happens in the Store read **before** the 1001-row sentinel. Each scope
has its own 1000-loan/size ceiling, so a large active list does not suppress a
small history download. No silent truncation or PHP session conversion.
Markup, profile and CSV formats differ from PHP; this is functional replacement,
not full source-output parity or production approval.

`docs/ci/rust-validation.yml` prepares PR regression jobs and a disposable
MySQL 5.7.44/live HTTP suite. It is a TEMPLATE, not an active workflow: the current
GitHub App was denied `workflows` permission. An authorized maintainer must install
it as `.github/workflows/rust-validation.yml`. No new CI result is available. It uses read-only repository permissions and a
fixture-only service, never a deployment database. CI results must be checked;
workflow presence alone is not a passing test result.

### Read-only library news and pages

`/news` (also `/index.php?p=news`) and `/pages/{path}` (also legacy `?p={path}`)
read published records from the existing `content` table. News supports literal
`keywords` search and bounded pagination; drafts/admin paths are excluded. Stored
formatting is deliberately restricted, with all attributes, links, scripts and
unknown tags escaped. This is not PHP rich-HTML/language/plugin parity or CMS
editing. Unknown legacy page selectors now return a content page/404 instead of
silently showing the catalog home. See [OPAC content port](../docs/opac-content.md)
for routes, limits, status codes, tests and unvalidated MySQL acceptance.
