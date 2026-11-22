# Decisions

Architecture decisions for the Rust rewrite, in the order they were made. Each
one records the context, the choice and what it costs. Change a decision by
adding a new ADR, not by editing the old one.

## ADR-0001 — No external crates

**Context.** The rewrite was produced in a sandbox with network access to
GitHub, npm and PyPI but not crates.io. SLiMS is a self-contained PHP
application; its only runtime dependencies are mysqli, GD and gettext.

**Decision.** The crate has zero dependencies. JSON, HTTP/1.1, the MySQL text
protocol, SHA-1, SHA-256, URL encoding and HTML escaping live in `src/`.

**Consequences.** The build is reproducible with `cargo build --offline` and
there is no supply-chain surface. The cost is ~2,000 lines of runtime code that
a mature crate would provide, and the MySQL client supports only what Phase 1
needs (text protocol, no TLS, no prepared statements, no Local INFILE).

## ADR-0002 — One `Store` trait, two backends

**Context.** The OPAC and API must run in the demo without a database, and
against the existing SLiMS schema in production.

**Decision.** `src/store/mod.rs` defines `Store`. `MemoryStore` seeds itself
from `install/*.sql`; `MySqlStore` runs SQL against the existing tables. No
schema migration.

**Consequences.** Handlers are written once and tested twice. The memory store
mirrors the PHP queries method by method, which is what makes the behavior gate
meaningful without a database. New endpoints must be added to both backends;
the compiler enforces it.

## ADR-0003 — MySQL wire protocol from scratch

**Context.** No crates.io and no MySQL server in the sandbox.

**Decision.** `src/store/mysql.rs` implements the client protocol: handshake
v10, `mysql_native_password`, `caching_sha2_password` fast path, auth switch,
`COM_QUERY`, column definitions, text rows, EOF packets and error packets.

**Consequences.** The rewrite can run against a stock MariaDB/MySQL database
with no driver. `caching_sha2_password` full authentication (MySQL 8's default
when the cache is cold) needs TLS or RSA and is refused with a clear message;
use a `mysql_native_password` account or a TLS tunnel. `tools/mysql_roundtrip.sh`
proves the implemented path end to end.

## ADR-0004 — PHP-shaped JSON

**Context.** The REST API is consumed by the existing SLiMS JavaScript and by
third-party integrations. `mysqli::fetch_assoc()` returns every column as a
string, and `json_encode()` escapes `/` and non-ASCII by default.

**Decision.** API values keep their PHP types (`{"data":"15"}`, `{"total":"5"}`)
and `Json::to_string` uses PHP escaping. Objects preserve insertion order.

**Consequences.** API responses are byte-comparable with the PHP controllers.
The HTML templates use `to_string_utf8`, so the UI stays readable.

## ADR-0005 — LIKE instead of FULLTEXT

**Context.** SLiMS uses `MATCH … AGAINST (… IN BOOLEAN MODE)` for keyword,
title and notes searches, with MySQL FULLTEXT indexes.

**Decision.** The port uses `LIKE '%term%'` across title, series, notes, ISBN,
classification, call number, publisher, author and topic, with escaped
wildcards.

**Consequences.** The demo store and MySQL behave the same, and short words are
searchable. Relevance ranking is not reproduced; SLiMS orders by `last_update`
anyway. On a large catalog, add FULLTEXT or a search engine behind the same
`Store::search` method.

## ADR-0006 — Demo circulation in the memory store

**Context.** `install/sampledata.sql` ships 15 biblio records and 10 items but
no loans or members, so `biblio/popular`, `member/top` and `loan/*` would be
empty in the demo.

**Decision.** When the seeded `loan` table is empty, `MemoryStore` adds four
members and twelve deterministic loans dated relative to today.

**Consequences.** The demo and the behavior gate exercise every endpoint. The
MySQL store never invents data; it reads the real `loan` table.

## ADR-0007 — New OPAC UI, same URLs

**Context.** `template/default` is Bootstrap + Vue + a PHP thumbnail endpoint.
A pixel port would be a large, fragile job with no behavioral value.

**Decision.** Server-rendered HTML with one stylesheet (`rust/static/opac.css`),
same routes, same fields, same legacy `index.php` URLs. Static assets are served
from the repository.

**Consequences.** The catalog is usable and fast. Theme parity is not a goal of
Phase 1; the original template keeps working under PHP.

## ADR-0008 — Phase 1 scope

**Context.** SLiMS is ~255,900 lines of PHP. A full rewrite is a multi-quarter
project.

**Decision.** Phase 1 ports the public surface: OPAC search/detail and the 15
REST API routes. Admin, circulation, reporting, plugins and OAI-PMH stay in PHP
until their phase.

**Consequences.** The highest-traffic public path moves first, with a read-only
database requirement and a rollback that is one proxy rule.

## ADR-0009 — The behavior gate is a fixture, a test and a script

**Context.** rust-anything 3.1 records and replays PHP apps but does not bridge
PHP units into Rust.

**Decision.** `fixtures/behavior/cases.json` is the recording;
`tests/behavior.rs` replays it in-process; `tools/gate.py` replays it against
any build; `tools/record.py` records a live PHP instance into the same shape.

**Consequences.** The gate runs in CI with no services and can be pointed at the
original once a database is available. The hand-written fixture is a starting
point, not a substitute for a real recording.

## ADR-0010 — Phase 1 is read-only

**Context.** The Rust binary runs beside a live PHP application.

**Decision.** The MySQL store issues only `SELECT` statements. There is no write
path in Phase 1.

**Consequences.** The binary can run against a production replica. Member
sign-in and circulation, which need writes, are Phase 2 and 3 and must add
transactions and audit logs before they ship.

## ADR-0011 — Deterministic order where PHP leaves it undefined

**Context.** Three queries in the original end with `ORDER BY total DESC` and
nothing else: `BiblioController::getPopular`, `SubjectController::getPopular`
and `MemberController::getTopMember` (which also applies `LIMIT` before PHP's
`usort`). MySQL returns ties in whatever order the plan produces, so the same
request can answer differently between servers and releases.

**Decision.** Both backends break ties explicitly: `biblio_id` for popular
titles, `topic` for popular subjects, `member_id` for top members (which
reproduces MySQL 5.7's implicit `GROUP BY` order, the version SLiMS targets).
The demo backend sorts the same way.

**Consequences.** The same recording passes on both backends and on any MySQL
version. Where the original was arbitrary, the port is now stable — a client
that was relying on the old tie order would see a change, but there was no
order to rely on.

## ADR-0012 — The MySQL double executes SQL, it does not match prefixes

**Context.** No MySQL server was available, and a prefix-matching fake would
happily return the right rows for the wrong query — it tests the protocol and
nothing else.

**Decision.** `tools/fake_mysql.py` loads `install/*.sql` into SQLite, adds the
demo circulation, and executes each query the store sends after translating it
with sqlglot. `--canned` keeps a prefix table for a pure protocol smoke test.

**Consequences.** The store's SQL is exercised on every run. This found the
`IN ()` syntax error, the unstable popular ordering and the divergent subject
ordering. The double is still not MySQL: collation, FULLTEXT and
`ONLY_FULL_GROUP_BY` behave differently, so a real database remains the
authority for anything the shims touch (`YEAR()`, `NOW()`, numeric string
comparison).

## ADR-0013 — Member-area groundwork (superseded in part by ADR-0014)

**Context.** Native PHP member authentication supports bcrypt and legacy MD5
(with an immediate bcrypt upgrade). The member area also writes last-login,
bookmarks, passwords and reservations. The MySQL text client is currently
read-only, unencrypted and has no prepared statements; the shipped `reserve`
table is MyISAM, so transactions alone cannot guarantee reservation limits.

**Decision.** Add a read-only, member-scoped loan query on both stores and an
opt-in bcrypt verification primitive using Linux libxcrypt `crypt_ra` rather
than writing a password algorithm from scratch. Use hex-encoded MySQL literals
for the member identifier so quote and backslash SQL modes cannot turn it into
SQL. Limit the displayed loans to 20 like PHP's datagrid; break date ties by
loan ID. Do not expose either primitive as a login or loan endpoint yet. The
existing member placeholder and the read-only Phase 1 policy remain unchanged.

**Consequences.** `--features member-crypt` requires a Linux system libcrypt
with bcrypt and `crypt_ra`; it is off by default. Verification accepts PHP
`$2y$` and `$2a$`/`$2b$` hashes, rejects malformed hashes, NULs and costs above
16, and follows bcrypt's 72-byte input limit. This cost cap is intentionally
stricter than PHP and can reject legitimate high-cost hashes; review before
shipping. MD5 login/migration, authentication configuration, bcrypt creation,
rate limiting, session lifecycle, CSRF, write safety, reservation consistency,
TLS, and real-MySQL/PHP parity tests are still unresolved. Do not deploy this
as member authentication. The SQLite-backed wire double checks this query, but
is not proof of behavior under real MySQL character sets/collations/SQL modes.

## ADR-0014 — Isolated experimental member sessions (updated by ADR-0015)

**Context.** The user selected the member area as Phase 2. Until a write-safe,
rate-limited production implementation exists, a working flow is useful for
migration tests, but silently enabling incomplete authentication is unsafe.

**Decision.** Gate the flow behind both the Linux `member-crypt` build feature
and `SLIMS_MEMBER_EXPERIMENTAL=1`. Default behavior is unchanged. A process-local,
bounded session map holds opaque 256-bit OS-random session IDs and independent
CSRF tokens, expires entries after 30 minutes, rotates IDs and CSRF on login,
and invalidates on CSRF-protected POST logout. Cookie defaults are Secure,
HttpOnly and SameSite=Lax; member responses are no-store. A local-only switch
can omit Secure. Select only the member credential and matching current/history
loans; the existing database remains read-only. Demo credentials are never
implicit and are overrideable for M001 only on the memory backend.

**Consequences.** The feature is for disposable test data only, not production:
there is no rate limiting, no durable/shared or revocable-across-processes
session store, no MySQL TLS, no PHP session or CSRF compatibility, no PHP
recording or real-MySQL validation, no MD5-to-bcrypt upgrade, LDAP/captcha,
password changes, reservations, bookmarks, audit or last-login update. It
supports only bcrypt. No GET logout or destination redirect is accepted. The
MySQL test double checks SQL and protocol but not MySQL collation or security.
Once writes are added, the retry-on-query-failure code must not blindly repeat
mutations; reserve tables in the supplied schema use MyISAM.

## ADR-0015 — Bounded local login attempts and disposable reservations

**Context.** The experimental member flow had no throttle and the PHP reserve
schema uses MyISAM, so a transaction in the Rust process cannot make a
check-then-insert safe against concurrent PHP writers. A full MySQL write path
must not be claimed until all writers share an enforceable locking strategy.

**Decision.** Add an in-process limiter: at most 8 attempts per normalized member
ID and 200 globally per 10 minutes, at most 4 simultaneous DB/password checks.
Count successful as well as unsuccessful attempts. Return HTTP 429 with a
Retry-After header. Add a separately opted-in memory-only reservation demo;
reject the option on MySQL or without experimental member mode. Its member-type
permission, limit, duplicate check, item selection and insertion are atomic
under one mutex; require an authenticated session and CSRF for POST, and
serialize the mutation with session logout. The stored data is ephemeral.
Refuse member mode if SLIMS_DB is invalid rather than silently using the demo.

**Consequences.** The limiter is per process and resets on restart; it is not
IP-aware, does not cover Unicode collation equivalences, has no cluster-wide
coordination and can be exploited to lock out other users. The server itself
still spawns unbounded connection threads. Reservation demo behavior is not PHP
parity: it operates on one ID at a time, uses UTC date only, and does not
implement basket, email, hooks, cancellations or a persistent write path. The
MySQL backend remains read-only. Neither the limiter nor demo reservation
makes the experimental member area production-ready.

## ADR-0016 — Optional local SQLite session storage (updated by ADR-0017)

**Context.** In-memory sessions disappear on restart and cannot be shared
between local workers. Session data must not be mixed into the legacy MyISAM
SLiMS schema; adding MySQL writes without safe concurrency is still out of scope.

**Decision.** Add opt-in `member-sqlite` and `SLIMS_SESSION_DB`. SQLite is
accessed through Linux's system libsqlite3 C API; the other builds remain free
of that system dependency. Require an absolute file path inside an existing
private directory owned by the process user, with the file owner-only and not a
symlink or hardlink. Reject other journal modes, use DELETE journaling and
synchronous FULL, and fail closed on storage errors. Store a SHA-256 digest of
the random bearer token, not the cookie itself. Use `BEGIN IMMEDIATE` and atomic
transactions for issue, rotation and logout. Session TTL is absolute 30 minutes
and expired rows are removed during issue. For demo reservation, hold the
session transaction through the process-local mutation to serialize against
logout; do not use the closure for network or SLiMS database writes.

**Consequences.** This is a local single-host option only, not a distributed
session service: never use SQLite on a network filesystem or across hosts. The
limiter and demo reservations remain process-local. CSRF tokens and member
identifiers/names are stored in plaintext, including possible SQLite journals
and backups; storage permissions alone are not encryption or secure erasure.
Absolute expiry uses wall time and can be extended by backward clock changes;
external password or account changes do not revoke sessions. File/database
replacement by the same OS user is outside the protection boundary. The login
still lacks MySQL TLS, production throttling, external auth, PHP parity and
security review. The feature is experimental and not production-ready.


## ADR-0017 — Bind member sessions to the current credential

**Context.** Persistent sessions previously remained usable after the stored
password was changed or the member row was removed. That is unsafe for account
recovery, even in the experimental member area.

**Decision.** Store a SHA-256 fingerprint of the stored password-hash string
with each authenticated session, not the raw password hash. Before rendering a
protected member page or executing a protected action, fetch the current member
record and compare its ID and fingerprint. On mismatch or missing record,
revoke that session and expire its cookie; on backend failure return 503 with
no access and retain the session for possible recovery. Refresh the display name
from the current record. Allow CSRF-protected logout even while the member
backend is unavailable. SQLite schema migration runs under one transaction,
adds the version field and deletes all legacy authenticated sessions because
they cannot be validated. Anonymous sessions remain valid.

**Consequences.** Each protected request adds a member lookup; the credential
fingerprint and member metadata at rest still need protection. It detects hash
changes and missing records, not changes to pending/expiry fields or replacement
with a record carrying the same ID and hash. A credential change concurrent
with a request may race the lookup; there is no cross-database transaction.
SQLite storage errors fail closed. Deployments must stop all older workers
before migration: old binaries do not enforce this fingerprint. The MySQL
backend remains read-only, unencrypted, and experimental; no security review or
real MySQL/PHP parity test has been completed.

## ADR-0018 — Read-only member details

**Decision.** Add an authenticated, no-store My Account view with the fields
shown by PHP's `showMemberDetail`: name, ID, email, type, registration date,
expiry date, institution and pending/expired notices. Both stores read only the
requested member; SQL encodes the ID as hex, and the template escapes all
database values. Authentication and credential revalidation occur before the
profile query. Do not expose a password-change form until a safe write path is
implemented.

**Consequences.** Pending/expiry notices are informational, not authorization
rules. Date comparison uses UTC, which can differ from the PHP server timezone.
A member can change concurrently between credential and profile reads, because
there is no cross-query transaction. The SQLite-backed MySQL protocol double
and memory tests cover the query and authorization; real MySQL and PHP parity
remain unverified.
