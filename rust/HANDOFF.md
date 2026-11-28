# Handoff

How to pick the port up, continue it, and not break what already passes.

## Environment used

Initially the sandbox had no Rust toolchain, PHP runtime or MySQL server. A
disposable MySQL 5.7.29 was later provisioned for integration tests. The Rust
toolchain came from
the npm package `@rust-sandbox/rust-1-95-x86_64-linux` (official rustc, cargo
and rust-std components), installed to `~/.local/rust`:

```bash
export PATH="$HOME/.local/rust/bin:$PATH"
cargo build --offline --manifest-path rust/Cargo.toml
```

On a normal machine use rustup. `cargo build --offline` works because the crate
has no dependencies.

## Daily commands

```bash
cd rust
cargo test --offline                       # unit + behavior gate
cargo run --offline                        # demo, http://0.0.0.0:8080
SLIMS_DB='mysql://user:pass@127.0.0.1:3306/senayan' cargo run --release --offline

python3 tools/gate.py --url http://127.0.0.1:8080
tools/mysql_roundtrip.sh                   # wire protocol + the recording on MySQL
python3 tools/fake_mysql.py --port 3307    # stand-alone double (--verbose logs SQL)
SLIMS_DB='mysql://slims:secret@127.0.0.1:3307/senayan' \
  SLIMS_ADDR=127.0.0.1:8081 ./target/debug/slims9   # against the double
python3 tools/gate.py --url http://127.0.0.1:8081   # backend detected from /healthz
```

The debug binary is `rust/target/debug/slims9`; release is
`rust/target/release/slims9`. Both are ignored by Git.

## Layout

```
rust/src/
  main.rs        CLI: config, state, bind
  app.rs         routing, static files, /healthz, thumbnail endpoint
  opac.rs        OPAC pages + legacy index.php URLs
  templates.rs   HTML components and escaping
  api.rs         REST API v1 + cache + image_path
  models.rs      Biblio, ItemCopy, Loan, SearchQuery, ...
  store/
    mod.rs       Store trait, SQL escaping
    seed.rs      install/*.sql parser
    memory.rs    demo/test backend + demo circulation
    mysql.rs     MySQL wire protocol + queries
  http.rs        HTTP/1.1 server and raw client
  json.rs        JSON value, parser, PHP escaping
  date.rs        civil dates
  config.rs      env config, mysql:// parser, root discovery
```

## Test backends

| Build | Command | Gate |
| --- | --- | --- |
| memory | `cargo run --offline` | 23/23 applicable (the MySQL health case is skipped) |
| mysql | `SLIMS_DB=mysql://… cargo run --offline` | 23/23 applicable (the memory health case is skipped) |
| mysql, no server | `tools/mysql_roundtrip.sh` | 23/23 through the wire protocol |

`tools/gate.py` asks `/healthz` which backend it is talking to. Cases may carry
`"backends": ["memory"]` where an expectation is backend-specific; the rest of
the recording is shared. **A case that passes on one backend only is a parity
bug, not a fixture problem** — fix the store, then update the recording.

## Adding an API route

1. Add the SQL/logic to both `MemoryStore` and `MySqlStore` in
   `src/store/{memory,mysql}.rs`. The `Store` trait is the contract; the
   compiler will tell you what is missing.
2. Add the handler in `src/api.rs` and the match arm in `handle`. Match the PHP
   JSON types exactly: mysqli returns strings, so counts are
   `Json::str(total.to_string())`.
3. Add a case to `fixtures/behavior/cases.json`:
   - stable output → `{"status":200,"json":{...}}`
   - date-dependent → `{"status":200,"shape":{...}}`
   - HTML → `{"status":200,"contains":["..."]}`
4. Run `cargo test --offline`. The behavior test replays the new case.

## Adding an OPAC page

1. Add a handler in `src/opac.rs` and a route in `src/app.rs` (or a `p=` branch
   in `opac::legacy` for `index.php` compatibility).
2. Render through `templates::layout`; escape every dynamic string with
   `templates::escape`.
3. Add a `contains` case to the fixture.

## Pitfalls found while porting

- **mysqli types.** `fetch_assoc()` returns strings. `{"data":"15"}` is not the
  same as `{"data":15}`. The gate compares parsed JSON, so both would look
  equal unless the expectation is written with strings — write it with strings.
- **`image_path` double encoding.** PHP `urlencode()`s the file name, then
  `urlencode()`s the whole path. `php5_dummies.jpg` becomes
  `images%2Fdocs%2Fphp5_dummies.jpg`. `src/api.rs::image_path` mirrors it.
- **`NOT IN ()`.** `BiblioController::getPopular` produces invalid SQL when the
  loan table is empty. The Rust store falls back to latest titles.
- **`opac_hide`.** Lists use `opac_hide < 1`; detail returns 404 for hidden
  records. Do not use `= 0` if the column can be NULL.
- **Cache TTL.** PHP caches `biblio_popular` and `subject_popular` for 18,000
  seconds in `files/cache`. The port uses an in-process cache with the same TTL;
  tests use `Cache::new(0)` to disable it.
- **Loan dates.** `DATE_FORMAT(loan_date,'%d/%m')` and `BETWEEN start-8 AND
  start` inclusive. The memory store uses `Date` arithmetic, not strings.
- **PHP `json_encode` escaping.** `/` and non-ASCII are escaped by default.
  `Json::to_string` does the same; templates use `to_string_utf8`.
- **`IN ()`.** Never build an id list without checking it is non-empty; MySQL
  rejects `IN ()` with a 1064 syntax error. `attach_relations` returns early
  on an empty page for exactly this reason.
- **Tie order.** PHP's `ORDER BY total DESC` leaves ties to the server. Both
  backends break ties (`biblio_id`, `topic`, `member_id`), so a recording that
  passes once keeps passing. Do not "simplify" the ORDER BY back.
- **Cache after a backend change.** `biblio_popular` and `subject_popular` are
  cached in-process for 18,000 s. Restart the binary after changing the data
  source, or the gate will replay the old answer.
- **MySQL `LIKE` escaping.** `like_escape` escapes `%`, `_` and `\`; the query
  uses `ESCAPE '\\'`. Without it, a user searching for `%` matches everything.
- **MySQL auth.** `caching_sha2_password` full auth needs TLS/RSA and is
  refused. MariaDB and MySQL 5.7 default to `mysql_native_password`, which is
  implemented.
- **Threading.** The HTTP server is thread-per-connection and the MySQL store
  serializes queries behind a `Mutex<Option<Connection>>`. Fine for an OPAC;
  replace with a pool before heavy traffic.

## Next units, in order

1. **Member sign-in and member area** (`lib/contents/login.inc.php`,
   `member.inc.php`). Needs bcrypt (`password_verify`), sessions, CSRF and
   write access. Add a `SessionStore` and a `MemberStore`; gate with a recorded
   login/logout flow.
2. **Circulation** (`admin/modules/circulation`). Checkout, return, renew,
   fines, loan rules. Transactions and an audit log are mandatory; record the
   PHP behavior against a test database first.
3. **Admin bibliography** (`admin/modules/bibliography`). Cataloging, items,
   authorities, MARC import/export. This is the largest remaining area.
4. **Reporting and stock take**, then **plugins and OAI-PMH**.
5. **Cutover**: run both apps against the same replica, replay `record.py`
   output, move `/api/` and the OPAC to Rust with `cutover.sh`, keep `/admin/`
   on PHP until Phase 4 passes.

## Known limitations

- No TLS or Unix-socket support in the MySQL client; a local TLS tunnel or a
  trusted, isolated connection is required.
- No prepared statements; every query is built with `sql_escape`/`like_escape`.
- `tools/fake_mysql.py` is SQLite behind a MySQL face: no collations, no
  FULLTEXT, no `ONLY_FULL_GROUP_BY`. Treat a green run as "the SQL is
  well-formed and returns the expected rows", not as MySQL certification.
- No file upload, no PDF generation, no image resizing.
- No i18n; the UI is English.
- The memory store is for demo/tests, not production.

## Phase 2 groundwork (in progress)

- `src/crypto.rs` contains the existing MySQL SHA helpers plus an experimental
  `member-crypt` feature for libxcrypt bcrypt verification. `cargo test
  --offline --features member-crypt` exercises PHP-compatible hash vectors,
  malformed inputs, long passwords and concurrent calls. It is not used by any
  route in the default build. The experimental member flow uses it when enabled;
  it needs Linux libcrypt with `crypt_ra`; see ADR-0013/0014.
- `Store::member_loans(member_id, returned)` is a read-only data primitive,
  implemented in both stores. **It does not authorize callers.** Memory tests
  check isolation and filtering; `tests/member_mysql.rs` additionally runs
  against the SQL-speaking wire double (start `tools/fake_mysql.py`, then set
  `SLIMS_TEST_MYSQL_URL` and run `cargo test --offline --test member_mysql --
  --ignored`). Use only a disposable demo dataset. A later test also passed on
  MySQL 5.7.29 using an installer-derived disposable fixture.
- Native PHP supports MD5 migration as well as bcrypt. Its member login updates
  last-login and can use other configured auth methods/captcha. Reserves use a
  MyISAM table in the shipped schema, so a transaction by itself cannot solve
  concurrent reserve-limit checks. Design write consistency, authentication,
  stronger session storage, throttling, audit, TLS and PHP recording before
  production use. Some session and CSRF primitives exist in the experimental flow.

### Experimental member flow (ADR-0014)

`SLIMS_MEMBER_EXPERIMENTAL=1` with `--features member-crypt` enables bcrypt
login, rotating sessions (in memory by default), CSRF-protected POST logout and scoped current
loan/history pages. Secure/HttpOnly/SameSite cookies and no-store are set by
default. Sessions expire after 30 minutes; default in-memory sessions are discarded at restart. The
memory demo has **no default login**: supply `SLIMS_DEMO_MEMBER_HASH` explicitly.
For local HTTP only, `SLIMS_MEMBER_INSECURE_COOKIE=1` drops Secure. The known
fixture hash and credentials are in `README.md`. `/index.php?p=member` is an
alias, but GET logout, PHP CSRF tokens and destination redirects deliberately
are not compatible. Unknown sections return 404. A session-scoped title basket
now supports add/remove/clear; it is not a PHP-compatible basket or reservation.
Existing SLiMS bookmarks are read-only by default. Test-only switches enable
bookmark/password writes, MD5 upgrade and a limited MySQL reservation prototype
on disposable local databases; these are not production-ready. Audit and
last-login writes remain absent. The credential and loan flow was tested on a
Python wire double and a disposable MySQL 5.7.29 instance. The implementation lacks distributed rate limiting and MySQL TLS;
it must not be used with real member accounts. Do not expose this opt-in mode
on an untrusted network. Tests: `cargo test --offline --features member-crypt`
and start `tools/fake_mysql.py --demo-member-login`, then run
`SLIMS_TEST_MYSQL_URL=mysql://slims:secret@127.0.0.1:3307/senayan cargo test
--offline --features member-crypt --test member_mysql -- --ignored`.


### Demo-only reservations and login throttling (ADR-0015)

`SLIMS_DEMO_RESERVATIONS=1` enables temporary reservation forms only alongside
experimental member mode on the memory backend. The selected title must be
visible and have an eligible item; the seeded member type's reserve permission
and limit are checked under the same lock as duplicate detection and insertion.
Items are chosen round-robin. The operation requires session authentication and
CSRF and is serialized against logout. It never writes MySQL, is lost on restart,
and differs from the PHP basket/email/plugin workflow. `AppState::new` rejects
this flag with MySQL, and rejects invalid database configuration in member mode.

`member_limit.rs` adds per-process, bounded 10-minute attempt windows (8 per
member ID, 200 globally, four in-flight) including successful logins. The global
limit covers name variants but allows attackers to deny service; it neither
tracks IPs nor coordinates across replicas, and resets on restart. It does not
make this production-ready. Tests cover duplicate and cross-title concurrency,
limit, unauthorized/CSRF requests, MySQL refusal and throttle bypass attempts.


### Optional SQLite session store (ADR-0016)

Enable `member-sqlite` and set `SLIMS_SESSION_DB` to an absolute path in an
existing owner-only local directory. Session IDs are hashed at rest; rotation and
logout are SQLite transactions and errors fail closed. Same-host processes can
share the file; no network filesystem or multi-host operation is supported.
SQLite uses DELETE journaling and synchronous FULL. The store requires Linux
libsqlite3.so.0 and libcrypt; there is no Rust dependency. The 30-minute expiry
uses wall time; rollback of system time can extend sessions. Member ID/name and
CSRF tokens are unencrypted; deletions and backups are not secure erasure.
Sessions are revalidated on protected requests against the current member
credential; see ADR-0017 for races and account-status limitations.
Rate limits and the memory demo's reservations are still per-process. Real
MySQL/PHP parity, write paths, audits, TLS and distributed throttling are not
implemented. Tests: `cargo test --offline --features member-sqlite`; the wire
double test additionally accepts `SLIMS_TEST_SESSION_DB` in a private dir.
The two-process HTTP smoke test manually verified session survival across restart
and cross-process logout; it is not a multi-host or real-MySQL certification.


### Credential-bound session revalidation (ADR-0017)

Authenticated sessions store a SHA-256 fingerprint of the bcrypt-hash string,
not the hash itself. Each protected request reloads the member credential; a
missing member or changed hash revokes the session and clears its cookie, while
a backend failure returns 503 without access or destroying the session. The
member name is refreshed at the same time. Logout remains available without a
credential DB lookup. Current pending/expiry fields are not checked, the same
hash restored after a change is indistinguishable, and a concurrent change can
race an in-flight request. SQLite auto-migrates its schema atomically and
revokes old authenticated sessions because they lack a fingerprint. Shut down
all old workers before migration: an old binary can still read new sessions
without checking the fingerprint. Test-only writes were added later; see ADR-0021.

`member_flow.rs` tests changed and removed credentials, failure/recovery and
cross-worker SQLite revocation. `session_sqlite.rs` tests concurrent migration,
legacy revocation, anonymous preservation and failed-migration rollback. The
MySQL wire-double login/history and limited real MySQL 5.7.29 tests passed; no running-PHP parity test was run.


### Read-only My Account

`/member?sec=my_account` shows member name, ID, email, type, registration,
expiry and institution after authentication, plus pending/expired notices.
Both stores implement `member_profile`; MySQL uses a hex-encoded ID and a
read-only SELECT. Member data is HTML-escaped and responses are no-store. The
profile page supports test-only password changes when explicitly enabled but does
not enforce membership status. Dates use UTC rather than a configurable PHP
timezone. It was tested with the SQL wire double and limited MySQL 5.7.29 tests,
not a PHP behavior recording.

### Test-only SQL writes and reservation prototype (ADR-0021)

`SLIMS_MEMBER_TEST_WRITES=1` is off by default, needs experimental member mode,
and permits MySQL only at `127.0.0.1` in a `slims_test*` database. It implements
bookmark add/delete, password change and legacy MD5-to-bcrypt upgrade. A second
switch, `SLIMS_MEMBER_TEST_RESERVATIONS=1`, permits an exclusive-writer test
reservation flow; it is not safe when PHP or another uncoordinated writer uses
`reserve`. These names/host checks cannot prove that data is disposable: use
only a newly created test DB. Database writes are not automatically retried.
Lost acknowledgements are reported as an unknown outcome. A table-locking
reservation keeps the SQLite session transaction locked during network I/O;
this and the other security gaps rule out production use.

`tools/setup_member_mysql_test.py` prepares an empty test schema. The ignored
`member_mysql_writes` test resets selected fixture rows and exercises real
MySQL reads/writes, concurrent CAS and reservations, and a committed write with
its acknowledgement dropped by a TCP proxy. It passed on MySQL 5.7.29 with
both memory and SQLite sessions. Full PHP parity, modern server versions,
security review, distributed sessions/rate limiting and operational recovery
are still required. See README for exact setup and limitations.

Reservation status checks now fail closed for pending/expired/missing-invalid
expiry (ADR-0022). MySQL checks under its existing member READ lock, not from the
session snapshot; demo uses the same UTC/inclusive-expiry policy. Account and
loan reads remain available. Default/crypt/SQLite suites and the disposable
MySQL test should be rerun when this policy changes.

Basket rows now offer explicit single-title reservation forms in opt-in demo or
test mode (ADR-0023), reusing existing POST/store checks without clearing the
basket. No bulk semantics are introduced. Latest SQLite suite: 66 library +
1 behavior + 14 flow passed; destructive disposable MySQL 5.7.29 + SQLite
session test passed separately. This does not remove mixed PHP-writer hazards.

Active member loans display overdue UTC calendar days (ADR-0024); due today,
invalid dates and returned history are not flagged. No fine/holiday/timezone
parity is implied. A page uses one UTC date snapshot. Tests cover calendar
boundaries, escaping, history and authenticated rendering.

Loan pages support bounded `format=csv` downloads (ADR-0025), not PHP all-loan
PDFs. Same credential refresh and owner/page checks; max 20 rows, no sentinel.
UTF-8 BOM/CRLF, quoted fields, apostrophe-prefixed formula/control-leading cells.
This is not lossless backup. Tests: SQLite 68 library + 1 behavior + 16 flow;
separate disposable MySQL + SQLite test passed with multi-page CSV isolation.

Member request hardening (ADR-0026): all Cookie fields are inspected, duplicate
session names refused; duplicate sec/page/format and POST Content-Type refused.
All member responses add no-referrer and nosniff. SQLite suite: 68 library +
1 behavior + 17 flow passed, including ambiguous-header refusal and revoked CSV.
No general proxy/HTTP security audit or stronger concurrent-revocation claim.

My Account offers session-store-scoped logout-all (ADR-0027), memory-mutex or
SQLite-transaction atomic with live caller/CSRF validation. Not PHP logout or
a durable epoch; in-flight requests and sign-ins completing later remain a
limitation. No library DB write required. Tests: 70 SQLite library + 1 behavior
+ 19 flow passed, including cross-worker deletion and failure rollback.

Catalog details now link to authenticated title-action confirmation pages
(ADR-0028), without public CSRF/session data. GET/HEAD display only; existing
POST handlers perform all actions. Anonymous users sign in then reopen the
catalog link (no return-target persistence). Latest SQLite suite: 70 library +
1 behavior + 20 flow passed; flags/visibility/escaping/non-mutation covered.

Numeric title continuation now supersedes ADR-0028's manual reopening limitation
(ADR-0029): optional hidden validated title ID survives login errors and becomes
a constructed local confirmation URL only after login/session rotation. No free
return URLs or auto-mutations. SQLite latest: 70 library + 1 behavior + 21 flow.

Read-only stored reservation list is independent of test-write flags (ADR-0030),
latest 100 by reserve ID and byte-exact owner. Memory fixture rows are separate
from demo mutations. Rust 1.95 restored after sandbox refresh; previous /tmp
MySQL tooling/data no longer available. Current SQLite suite: 70 library +
1 behavior + 22 flow passed. External integration was not rerun this turn.

User selected Rust member ownership, PHP admin retained (ADR-0031). Opt-in PHP
member-owner gate and `docs/member-cutover.md` added. Gate tests/lint passed via
PHP WASM CLI using `tools/test_member_owner.py`; not live PHP/FPM/database
integration. Rust production write flags remain unchanged/restricted. Review
admin/plugin/external/in-flight writers and legacy session rollback before
cutover. Sandbox refresh removed prior toolchains and /tmp services again;
no Rust source changed in this ownership-gate step.

PHP ownership gate now covers Validator/Contract dispatch/session generation,
LDAP and legacy login (ADR-0032); utility ignores existing member sessions in
Rust mode, but does not erase them. Admin dispatch tested as still available.
Eight PHP WASM gate/lint tests passed; no Rust changes or live LDAP/FPM/MySQL test.
Custom providers outside guarded entry points still require explicit inventory.

Cutover gate now has 16 PHP WASM HTTP-response cases in addition to 8 CLI/lint
tests, both passed (ADR-0033). No network listener/deployed FPM/proxy test.
Public Native::setMemberData is also guarded before DB/last-login work.
See member-cutover runbook for optional external npm test-tool setup.

HTTP parser/serializer hardened in ADR-0034: bounded header-line reads, strict
framing/complete CRLF headers, no TE/Expect/duplicate CL, preserve explicit
security policies. Malformed requests close before dispatch. Proxy must send
Content-Length bodies. Tests: 76 SQLite library + 1 behavior + 22 flow; live
memory HTTP gate 23/23 applicable, 1 skip. No live MySQL rerun. Connection/thread
limits and absolute deadlines remain unresolved production risks.

HTTP admission/read limits (ADR-0035): 128 workers per listener, 100 requests per
connection, 15s absolute request-read budget. Excess sockets close immediately;
RAII permits recover on exit/unwind/spawn failure. Not global/IP-aware and not
handler cancellation or absolute write deadline. Latest SQLite: 81 library +
1 behavior + 22 flow; memory live gate 23/23 applicable, 1 skip. No MySQL rerun.

Response writes now use one absolute 15s budget (ADR-0036), beginning after the
handler and before serialization. Partial writes/flush use remaining time only;
no handler replay on delivery failure. Handler/DB/serialization cancellation is
not implemented, and committed changes may lose HTTP acknowledgement. Tests:
83 SQLite library + 1 behavior + 22 flow; live memory gate 23/23 applicable,
1 skip. Nonreading real TCP peer and permit recovery tested. No MySQL rerun.

Latest member flows revalidated on fresh real MySQL 5.7.29 (ADR-0037). Fixed real
fixture bookmark mismatch; all 3 read integrations now pass before destructive
writes. Write tests extended for stored read/write-off and logout-all, passed
with SQLite sessions and memory + NO_BACKSLASH_ESCAPES. New opt-in local HTTP
smoke tool passed on Rust/MySQL/SQLite; MySQL OPAC gate 23/23 applicable, 1 skip.
All default/crypt/SQLite suites pass (83 SQLite library + 1 behavior + 22 flow).
Destructive tests remove bookmark pagination fixture: use a new empty DB for
full read reruns. This is not PHP parity or production-write approval.

Test-write last-login support (ADR-0038): profile reads stored timestamp; verified
logins update UTC last_login with byte-exact expected hash, clearing stale IP.
No trusted peer IP/audit implemented. Read-only login does not update. Update
precedes session rotation, so not atomic proof of session creation. Never retry
on unknown acknowledgement. Same-second zero-change updates verified by read.
SQLite suite 83 library + 1 behavior + 23 flow; real MySQL + SQLite destructive
test passed, including dropped timestamp acknowledgement and stale-IP removal.

### Basket reservation increment (ADR-0039)

Test/demo basket reservation is implemented and tested, including real MySQL
5.7.29 with private SQLite sessions. It uses the live session basket, preserves
it, reports partial results and stops on any uncertain error without retry.
A between-title time/credential check is not cancellation of DB work. Session
locks remain held during this batch: measure contention before deployment.
Current SQLite suite: 84 library + 1 behavior + 24 member-flow tests pass.
Production ownership/security blockers above remain unresolved; do not interpret
this increment as authorization for a production cutover.

### Admin-reset reservation race (ADR-0040)

Both HTTP SQL reservation paths now recheck the session credential version
inside MySQL's reservation/member locks, closing the pre-lock password-reset
window. Real MySQL + private SQLite destructive validation passes. Unsupported
stores refuse the bound API. The unbound helper is for direct test fixtures,
not HTTP handlers. See `docs/member-writer-inventory.md` for retained PHP admin
writers, evidence and unresolved coordination requirements.

Final ADR-0040 regression: default 60 library + 1 behavior; member-crypt 74
library + 1 behavior + 22 flow; SQLite 84 library + 1 behavior + 25 flow pass.
The opt-in real MySQL destructive suite was run explicitly and passes; the
fixture-read tests remain ignored in these ordinary regression commands.

### Bound bookmark writes (ADR-0041)

HTTP bookmarks now check credential version and addition visibility inside the
mutation critical section (MySQL locks; memory password mutex). Stale binding
revokes the HTTP session; vanished/hidden addition returns 404. Removal may
clean up orphaned titles and remains idempotent. Unsupported backends fail
closed, never falling back to unbound fixture helpers. MySQL performs the final
mutation once, without retry on unknown outcome.

Final suites: default 60+1; member-crypt 74+1+23 flow; SQLite 84+1+26 flow.
Expanded destructive real MySQL suite passed on private SQLite sessions and
memory sessions with NO_BACKSLASH_ESCAPES. Production remains blocked as above.

### MySQL resource limits (ADR-0042)

Wire handshake/each command now has a 20s absolute I/O budget; partial progress
does not reset it. Limits: 8 MiB packet/query, 32 MiB response wire bytes,
256 columns, 10,000 rows, 262,144 cells. Over-limit responses fail closed and
shut down the stream, never silently truncate. Legitimate large responses may
need query pagination/redesign. Checked lengths avoid overflow and truncated
error-state slicing. Single-write retry behavior is unchanged (no retry).

NOT covered: DNS/connect, lock queues, total handler/multi-statement duration,
server cancellation, process memory cap or TLS. Read reconnect can use a second
budget. A batch/session lock can still span multiple command budgets.

Latest validation: default 65+1; crypt 79+1+23 flow; SQLite 89+1+26 flow pass.
Fresh `slims_test_budget` fixture read integrations 3/3 and destructive real
MySQL 5.7.29 + private SQLite pass. Destructive tests modified that fixture;
create a fresh empty database before rerunning fixture pagination read checks.

### MySQL response synchronization (ADR-0043)

Packet sequences are verified (including wrap); multi-result support is no
longer advertised. Strict metadata/final EOF, command OK parsing and exact row
consumption reject malformed/extra responses. All command errors close the
socket; only the read-only store's new-connection retry remains. No write retry,
TLS, stored-procedure/multi-result or deprecated-EOF support was added.

Latest regressions: default 68+1; crypt 82+1+23 flow; SQLite 92+1+26 flow pass.
Fresh real MySQL `slims_test_protocol` fixture reads 3/3 and destructive private
SQLite writes pass. This disposable fixture is now modified by write tests;
bootstrap a fresh empty DB before repeating fixture read-pagination assertions.

### MySQL authentication framing (ADR-0044)

Fixed switched-plugin responses to use raw scramble bytes, preserving the
20-byte nonce even when its last byte is zero. Unknown initial plugins fail
before credential-derived output; malformed continuations/OK and unterminated
protocol strings fail closed. Caching-SHA2 full auth remains unsupported; fast
auth requires exact success then final OK. Scripted-peer coverage is not real
MySQL 8/SHA2 acceptance, and TLS remains absent.

Latest regression: default 71+1; crypt 85+1+23 flow; SQLite 95+1+26 flow pass.
Fresh real MySQL 5.7.29 `slims_test_auth` reads pass 3/3, followed by passing
private-SQLite destructive writes (fixture now modified; use fresh DB for reads).

### Consolidated validation milestone (ADR-0045)

Use `tools/test_member_suite.py` as documented in `docs/member-validation.md`.
It requires an existing NEW EMPTY local disposable database, runs fixture reads
before mutations, launches/cleans a dynamic-port HTTP smoke server, and produces
optional credential-free stage JSON. Optional isolated PHP CLI/WASM HTTP checks
are included via explicit flags; they do not execute PHP admin against MySQL.
Nonempty targets and ambient write flags cannot silently bypass these guards.
Failure/SIGTERM cleanup and stale-report refusal are tested.

MySQL TCP candidates now share a 5s/8-address budget after DNS; the pooled read
queue has a 3s acquisition budget. DNS, other locks and total request duration
remain unresolved. Final regression counts: default 73+1, crypt 87+1+23 flow,
SQLite 97+1+26 flow. Complete real MySQL suite plus PHP WASM 8 CLI/16 HTTP cases
passed. Keep production-write restrictions; full PHP/admin integration, TLS,
authentication/audit policy and operational acceptance still block cutover.

### PHP combined loan report migrated (ADR-0046)

`lib/contents/download_member_loan_report.inc.php` now has a Rust feature
replacement: `/member?sec=loan_report` (printable HTML) and `&format=csv`.
The old combined-report query route works as a GET/HEAD-only alias when routed
to Rust with experimental member mode enabled. Authenticated owner only, no
PHP session conversion, no writes. Profile fields plus active/returned loan
rows are shown; the latter use a single bounded store read, not paginated fetches.
Limit 1000 loans/512 KiB raw fields: overflow yields 413, never partial success.
HTML uses CSP-compatible external print CSS; CSV reuses formula-neutralization.

Earlier notes calling PHP member reports PDFs were inaccurate: the source emits
HTML attachments. Rust provides inline printable HTML and CSV, not identical
PHP markup/header behavior. Single-section legacy download aliases remain
unported; keep PHP guards and proxy routing decisions explicit.

Latest complete validation: default 73+1; crypt 88+1+24 flow; SQLite 98+1+27 flow;
fresh MySQL 3/3 reads, live member report/HEAD/CSV, OPAC 23/23 applicable,
destructive report/overflow checks and other writes, PHP WASM 8 CLI/16 HTTP,
runner safety 6/6 pass. MySQL binary-to-binary owner join caused empty results;
normal join plus independent binary owner predicates fixes it without allowing
case aliases. Full PHP parity/production deployment remain unvalidated.

### Backend foundation stage 1 (ADR-0047; framework adoption blocked)

Binary uses `runtime::RuntimeConfig` fail-closed startup with static/redacted
errors (exit 2); compatibility Config::from_env remains library-only. Real
ServerSettings apply runtime connection/request/read/write limits. Dispatch
returns server-generated X-Request-ID and logs redacted JSON response_prepared
(coarse route/method/status/duration only). No path/query/cookie/body/member
identity or password logging. These are operational events, not audit/delivery
proof; synchronous log sink can block. Password is redacted from MySqlUrl Debug.

Crates.io index/static archives inaccessible; Axum/Tokio, SQLx, template crates
and tracing NOT installed/implemented. Offline build remains dependency-free.
See docs/backend-foundation.md and rust/.env.example for actual scope, strict
settings compatibility changes, remaining limitations and staged adapter plan.
Store/schema/test-only writers remain untouched; no production cutover.

Latest full disposable MySQL suite: default 77+1+2 binary; crypt 92+1+24 flow+2
binary; SQLite 102+1+27 flow+2 binary. Reads 3/3, live member/report/CSV/OPAC
23/23 applicable, destructive private SQLite writes and six safety tests pass.
Optional PHP suites not rerun in this increment (PHP unchanged).

### Single-section downloads + PR CI (ADR-0048)

Current/history PHP download aliases now map to scoped Rust HTML/CSV reports,
GET/HEAD only, owner-bound and no-store. Canonical scope=all/current/history,
format=html attachment or csv. Scope precedes SQL/memory sentinel, never filter
a capped combined report. Renderer refuses mismatches. Existing report caps,
credential refresh and no-replay rules remain. These are functional replacements
with different markup, not deployment parity/approval.

Prepared workflow template for feature matrix + isolated PHP guard + disposable MySQL full
suite. No deployment/secrets; read-only permissions and pinned actions. Check
remote runs before treating CI as passed. Local MySQL server from prior sessions
is gone; new SQL integration assertions were not run locally. Live memory HTTP
and OPAC gate pass, as do default 78+1+2 binary, crypt 94+1+25 flow+2 binary,
SQLite 104+1+28 flow+2 binary and six runner safety tests. Temporary compiler
restored from integrity-verified npm archives is outside Git; local 1.92 nightly,
CI targets 1.85.1. No production change, framework dependency adoption or merge.

Push was refused for .github/workflows by GitHub App permission. CI template
is now in docs/ci/rust-validation.yml only (inactive), to be installed by an
authorized maintainer. Feature code/docs can be pushed; no new remote CI/MySQL
result exists. Do not mark scope SQL acceptance complete.

### Revision-bound test evidence (ADR-0049)

`tools/member_validation_report.py` rejects incomplete, failed, stale, wrong-
revision or dirty-tree reports. Runner stamps source before/after and times,
uses locked offline Cargo; inactive CI template invokes verifier. Save reports
outside the tree and run from clean commits. Consistency only, not signed
attestation or production approval. Eleven runner/evidence safety tests passed;
no new Rust/MySQL/PHP/HTTP execution in this restored sandbox. CI permissions,
real SQL scope execution and all production blockers remain unresolved.
