# Handoff

How to pick the port up, continue it, and not break what already passes.

## Environment used

The sandbox had no Rust toolchain, no PHP and no MySQL. The toolchain came from
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
  --ignored`). Use only a disposable demo dataset. Real MySQL remains untested.
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
are not compatible. Unknown sections return 404. Reservations, basket,
bookmarks, password changes, legacy MD5 migration, audit and last-login writes
are absent from the database-backed implementation. A Python MySQL wire double, not real MySQL, was used for the
credential + loan flow. The implementation lacks distributed rate limiting and MySQL TLS;
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
without checking the fingerprint. No actual SLiMS database writes are added.

`member_flow.rs` tests changed and removed credentials, failure/recovery and
cross-worker SQLite revocation. `session_sqlite.rs` tests concurrent migration,
legacy revocation, anonymous preservation and failed-migration rollback. The
MySQL wire-double login/history test passed; no real MySQL/PHP test was run.


### Read-only My Account

`/member?sec=my_account` shows member name, ID, email, type, registration,
expiry and institution after authentication, plus pending/expired notices.
Both stores implement `member_profile`; MySQL uses a hex-encoded ID and a
read-only SELECT. Member data is HTML-escaped and responses are no-store. The
profile page does not change passwords or enforce membership status. Dates use
UTC rather than a configurable PHP timezone. It was tested with the SQL wire
double, not an actual MySQL server or PHP behavior recording.
