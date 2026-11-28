# Decisions

ADRs record decisions at the time made. Later ADRs supersede earlier scope and
validation statements; see ADR-0021 for current test-only writes.

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

## ADR-0019 — Session-scoped title basket (experimental)

**Decision.** Add a basket of at most 20 distinct visible catalog IDs to each
member session. Add/remove/clear use authenticated CSRF-protected POSTs. The
in-memory store serializes changes with logout; local SQLite uses a transaction
and migrates existing credential-bound sessions with an empty basket. SQLite
workers sharing a session file see each other's changes.

**Consequences.** The basket is not shared between different logins of one
member, is removed on logout/expiry, and is not a persistent bookmark or a
real reservation. It is not interoperable with PHP sessions or PHP's basket
reservation flow. The limit is fixed, not read from PHP configuration. Catalog
visibility can change after insertion; missing/hidden titles render without
revealing the title and can still be removed. Existing credential revalidation
has the same cross-database race as other member actions. SQLite stores basket
IDs unencrypted; stop old workers before upgrading schema. Memory and SQLite
flow/concurrency tests and the SQL-speaking test double do not establish PHP or
real-MySQL parity, nor production readiness.

## ADR-0020 — Read-only member bookmark list

**Decision.** Show existing `biblio_mark` rows for the authenticated, revalidated
member only. SQL encodes the member ID as a hex literal, joins `biblio` as PHP
does, and fetches 21 rows for a 20-row page and next-page detection. Sort by
creation date then bookmark ID descending; escape titles and dates. Memory demo
loads bookmarks only from the initial seed. Pagination caps at 5,001 pages.

**Consequences.** Listing is read-only: no add/remove or interaction with PHP's
bookmark session cache. Duplicate member/title rows remain visible and orphaned
bibliographic IDs are omitted. Hidden bibliographic records already bookmarked
are displayed, matching the PHP query; dates are raw, not localized like PHP.
Offset pages can shift under concurrent writes; ordering for equal timestamps
may differ between SQLite/memory and MySQL collations. Queries and member
isolation were exercised on memory and the SQLite-backed protocol double, not
real MySQL/MariaDB or a running PHP instance. The member feature remains
experimental and must not be used with real member data.

## ADR-0021 — Disposable-database member write prototypes

**Decision.** Default member behavior stays read-only. An explicit test flag
allows password changes, MD5 login upgrades and bookmark add/delete only in
memory or against `127.0.0.1` in a `slims_test*` database; a separate flag
allows test reservations. The guards are not proof of data disposability and
are not a production security boundary. New bcrypt passwords use fresh OS
randomness and cost 10; new credentials are limited to 72 bytes, and legacy
MD5 logins above that limit are refused rather than truncated. Password writes
use byte-exact compare-and-set, then the existing credential fingerprint revokes
other sessions on their next request. Bookmarks use PHP's deterministic
`MD5(decimal title ID + member ID)` key and delete all member/title matches.
Bookmark reads/deletes compare the owner byte-exactly to avoid collation errors;
this can omit historical case-variant rows that PHP's collation would match.

Each SQL write uses a fresh connection once, reads the OK/affected-row packet,
and is never replayed. Lost acknowledgements or warnings yield an explicitly
unknown outcome. The limited reservation prototype holds MySQL table locks
while checking member policy, duplicates, limit and eligible items, then
selects a copy round-robin and inserts. This serializes cooperating Rust test
writers only. PHP can perform its check before Rust's lock and insert after it,
so mixed-writer duplicate/limit guarantees cannot be made on MyISAM. This is
not production reservation support and not the PHP basket/email/plugin flow.
Test writes hold a session lock across DB I/O, potentially blocking other
sessions, and session DB and library DB do not have a shared transaction.

**Validation and limitations.** A disposable MySQL 5.7.29 fixture from the
installer SQL tested reads, authentication, bookmark isolation/duplicates,
password change and MD5 upgrade, concurrent CAS, concurrent reservation limits,
and a committed write whose acknowledgement was dropped by a TCP proxy. Memory,
SQLite and wire-double tests also pass. This is not PHP parity, modern-MySQL or
MariaDB coverage, a security review, nor proof of reliable recovery under all
failures. LDAP, CAPTCHA, configurable password policy, last-login/audit,
distributed limiting/sessions, TLS, PDF downloads and production-safe real
reservation handling are still missing. Do not use with real member data.

## ADR-0022 — Fail-closed membership checks for prototype reservations

Both the memory demo and disposable MySQL reservation prototype reject pending
members and absent/invalid/expired expiry dates. Expiry is inclusive on the UTC
calendar date; calendar round-trip validation rejects impossible dates. MySQL
reads status under the existing member READ table lock, preventing a status
update between that check and its insert. This does not resolve the previously
documented stale-check races with unmodified PHP reservation writers.

Login and account/loan reads remain available, as do test password changes.
This is a deliberate restrictive prototype policy, not PHP timezone or policy
parity. Tests cover pending and expiry changes after login, no reservation rows
on denial, inclusive expiry day, and continued account access.

## ADR-0023 — Explicit single-title reservation from basket

Basket rows offer the existing CSRF-protected reservation POST only when demo
or disposable-MySQL reservations are enabled. They reuse the existing store
policy, limit, duplicate and status checks. No bulk write endpoint is added:
partial bulk success on MyISAM would require per-title outcome/recovery design.
The basket remains unchanged on success, denial and unknown outcomes. A button
is not a promise of eligibility; current state is checked by the store on POST.
This does not implement PHP bulk/email/plugin parity or production reservations.

Tests cover opt-in rendering, CSRF, creation, duplicate/unavailable outcomes and
basket retention, plus disposable MySQL test-button rendering and retention.

## ADR-0024 — Display calendar lateness for active member loans

Active-loan due dates now show overdue calendar days using one UTC date snapshot
per page. Due today is not overdue. Invalid, missing, non-date-shaped or
impossible dates are escaped and displayed without classification. Returned
history never shows an overdue badge. This is deliberately display-only: no
fines, holiday/calendar rules, renewal policy or PHP timezone parity is implied.
Tests cover inclusive boundaries, leap days, escaping, malformed dates and
member-flow rendering. The existing member-scoped read and no-store policy stay
unchanged; no database writes or new endpoints are introduced.

## ADR-0025 — Bounded, member-authorized loan CSV pages

Authenticated current-loan/history GETs may request `format=csv`. The endpoint
reuses credential refresh, session ownership, page limits and scoped store reads;
anonymous/revoked sessions receive the login flow, not a download. It exports
at most 20 rows, excludes the pagination sentinel and never claims to be the
PHP all-loan PDF. Attachments use fixed-shape non-identifying filenames, no-store
and nosniff headers. Duplicate/unsupported format and invalid page are rejected.

CSV uses UTF-8 BOM, CRLF records and doubled quotes in fully quoted fields.
Formula-prefix cells (including whitespace-prefixed) and cells beginning with
control characters receive an apostrophe prefix. This intentionally changes
some exported values; it is not a lossless backup and cannot guarantee every
spreadsheet/importer configuration. Nulls export as empty strings. Existing
offset-pagination consistency limits apply. Tests cover serialization, access,
logout, validation, empty pages and real MySQL multi-page owner isolation.

## ADR-0026 — Reject ambiguous member request selectors and credentials

Member routes reject duplicate section/page/format query selectors with 400,
including identical values. POST requires exactly one Content-Type header.
Cookie parsing inspects all case-insensitive Cookie fields to support split
proxy headers but refuses duplicate member-session cookie names, including
identical tokens. An ambiguous cookie is treated as missing authentication:
GET gets the login flow, POST fails CSRF/session authorization. Existing valid
sessions are not invalidated by such requests. Other application routes are
unchanged; this is not a general HTTP proxy/request-smuggling audit.

All member responses now include no-referrer and nosniff in addition to no-store.
Tests cover split/duplicate cookies, mixed-case headers, duplicate selectors and
Content-Type, no mutation on refusal, valid session survival and credential-
revoked CSV access. Existing concurrent-revocation limits still apply.

## ADR-0027 — Revoke all sessions in the member's Rust session store

My Account offers a CSRF-protected `logout_all` POST. The member ID is derived
only from the live authenticated session; memory validates/deletes under one
mutex and SQLite uses BEGIN IMMEDIATE and a parameterized byte-exact member-ID
delete in the same transaction. It removes all matching sessions including the
caller and baskets, leaves other members and anonymous sessions intact, and
clears the caller cookie only after success. Storage failure returns 503; no
library/MySQL writes or opt-in test-write flag is needed.

Scope is this process (memory) or workers sharing this SQLite DB, not PHP or
other stores. Existing authorized requests cannot be recalled; a login finishing
after the deletion can create a session. This is not a password change or a
durable revocation epoch. Tests cover memory and cross-worker SQLite, wrong
CSRF/anonymous/expired/replayed callers, owner isolation, CSV denial afterwards,
basket clearing, fresh login and SQLite deletion failure rollback.

## ADR-0028 — Catalog-to-member confirmation pages

Member-enabled public catalog details include a non-mutating link to a member
confirmation page for a positive title ID. The page requires authentication and
credential refresh, checks current title visibility and escapes its title.
It offers existing CSRF POST basket/bookmark/reservation handlers, respecting
all test/demo flags; no new database mutation implementation is introduced.
Public catalog pages contain only a generic link, not session state/tokens.
GET and HEAD do not mutate basket/bookmarks/reservations. Missing/hidden titles
are 404; invalid/duplicate title IDs are 400. Signed-out users see login and
must reopen the catalog action link after signing in; no arbitrary return URL
or persisted post-login target is accepted. Tests cover flags, visibility,
escaping, anonymous access, no GET/HEAD mutation and successful POST addition.

## ADR-0029 — Numeric post-login continuation to title confirmation

Supersedes ADR-0028's lack of login continuation. Anonymous title-action GETs
validate a positive ASCII-decimal i64 title ID and include its canonical value
as a hidden login field. Incorrect-password pages preserve it. POST validates
the optional field (duplicates/malformed/overflow/zero rejected) and constructs
only `/member?sec=title_actions&biblio_id=N` after successful session rotation.
No arbitrary return URL, GET mutation, stored redirect URL or automatic title
POST is added. Confirmation rechecks visibility/availability; an invalidated
title may return 404 after a successful login. The field is untrusted navigation
data, not proof of authorization. No session-schema migration is required.

Tests cover initial/existing anonymous sessions, failed-password retries, token
rotation/replay, normal no-target login, malicious URL/control/duplicate inputs,
404 target and absence of basket/bookmark mutation after login.

## ADR-0030 — Read stored reservations without write opt-in

Member navigation offers `reservation_list`, a private read-only view requiring
normal session/credential checks but no test-write flag. Store reads return the
latest 100 owner rows by reserve_id descending with an inner catalog join.
MySQL reuses the existing test-reservation read query, now exposed independently
from test mutations. Owner matching is byte-exact. Memory loads fixture reserve
rows into a separate immutable collection, not the demo mutation vector.
Missing catalog references are omitted; nullable item/date fields display empty.
No fulfillment/cancellation interpretation, new writes or production reservation
guarantees are introduced. The historical DemoReservation display type is shared
by these reads. Tests cover the 100-row bound, sorting, owner/case isolation,
escaping, anonymous access and separation from demo mutations. External MySQL
integration is not rerun in this environment refresh; prior query validation
is not a claim of full PHP parity.

## ADR-0031 — Rust member ownership target and opt-in legacy gate

The user selected Rust replacing the member area, retaining PHP administration.
A PHP environment gate (`SLIMS_MEMBER_OWNER=rust`) refuses legacy member and
member-download routes before bootstrap and at content includes, Native member
authentication and circulation reservation creation. Unknown nonempty owner
values fail closed on guarded paths. Default/empty/php preserves legacy behavior.
There is no automatic POST redirect/replay and no production Rust-write enablement.

The gate is defense in depth, not exhaustive writer isolation. PHP admin
fulfillment/deletion/member edits and plugins require further concurrency review;
in-flight requests must be drained. CLI gate tests passed on PHP WASM, with
syntax checks of changed files, not a live SLiMS/FPM/proxy validation. The runbook
records required inventory, staging, cutover and rollback/session-invalidation
steps. Production readiness and full member parity remain incomplete.

## ADR-0032 — Close legacy authentication bypasses during member cutover

Extend the PHP owner gate to Validator's member dispatch before invoking a
provider, Contract's magic member dispatch and member-session generation,
LDAP's member method, and legacy member_logon valid/native/LDAP entry points.
Case-insensitive member dispatch is covered. PHP utility's member-login check
returns false during Rust/invalid ownership without deleting old session data;
admin dispatch remains available. Rollback still requires explicit legacy
session invalidation, as changing ownership back restores session recognition.

Eight isolated gate/lint tests pass via PHP WASM CLI, including a custom public
member() override through Validator, direct Native/LDAP dispatch, direct member
session generation, legacy login, old-session refusal and admin dispatch success.
This is not LDAP service integration, arbitrary plugin isolation, FPM header
validation or live database parity. No Rust source or write-enable flags change.

## ADR-0033 — Test cutover HTTP responses and guard public member setup

Add isolated PHP WASM request/response tests for early legacy member/download
route refusal, including GET/POST/HEAD, response headers, no redirect and failed
request-field/header attempts to override worker ownership. Sixteen cases pass.
The eight CLI gate/lint tests still pass. Also guard direct public Native
setMemberData calls before their bookmark queries/last-login writes; test it
without database/bootstrap dependencies. No Rust source or production-write
configuration changes. These are isolated PHP tests, not live FPM/proxy/LDAP/
MySQL integration, general header-smuggling coverage or production acceptance.

## ADR-0034 — Bound HTTP reads and reject ambiguous request framing

The tiny HTTP server previously used unbounded read_line before checking the
header size, accepted incomplete headers, ignored Transfer-Encoding and used the
first Content-Length. Bound the actual read to the remaining 64-KiB budget plus
one byte; reject malformed/unterminated/non-CRLF request/header lines, invalid
header names/control bytes, unsupported versions/targets, duplicate Host and
missing HTTP/1.1 Host. Only origin-form HTTP/1.0/1.1 is supported.

Reject any Transfer-Encoding/Expect, duplicate Content-Length and nondigit,
overflow or over-limit lengths before body allocation/handler dispatch. Errors
close the connection and never parse trailing pipeline bytes as a fresh request.
HTTP/1.0 closes; close tokens across Connection headers are respected. Remove
redundant OPTIONS length and stop appending default security headers when an
explicit response policy exists, preserving member no-referrer on the wire.

Tests cover pipeline/body boundaries, truncated bodies, malformed framing,
unterminated/cumulative allocation limits, no dispatch on a real TCP ambiguous
request and serialized policy uniqueness. Default/crypt/SQLite suites pass;
SQLite: 76 library + 1 behavior + 22 flow. Live memory HTTP gate: 23/23 applicable,
1 MySQL-specific skip. No live MySQL integration rerun; production still needs
thread/connection bounds, deadlines, proxy review and security/load testing.

## ADR-0035 — Bound HTTP worker admission and request read lifetime

Use an atomic per-listener admission counter with RAII permits before spawning
workers. At 128 active connections, close excess sockets without spawning or
blocking the accept loop on a response write. Builder spawn errors release the
closure-owned permit. EOF, malformed request, I/O failure and unwinding release
permits too. Cap each connection at 100 dispatched requests; close afterwards
without processing a remaining pipelined request.

A socket reader wrapper recomputes the remaining budget before every underlying
read. One 15-second absolute deadline covers each request, including keep-alive
idle, headers and body, and is checked again before dispatch. It does not cancel
handler/DB work or impose an absolute response-write deadline. Fixed limits are
prototype defaults, not global/per-IP admission, exhaustive memory limits or
proof of DoS resistance. Multiple listeners/processes have separate counters.

Tests cover bounded admission/recovery, permit release on unwind, a real excess
socket, keep-alive request cap, non-renewing expired reader budget and incomplete
request timeout without dispatch. Suites pass: SQLite 81 library + 1 behavior +
22 flow; live memory HTTP gate 23/23 applicable, one skip. MySQL not rerun here.

## ADR-0036 — Absolute response-write budget

Wrap response socket writes/flush with a shared absolute 15-second deadline,
reset once per response after handler completion and before serialization. Each
write uses only the remaining socket timeout; slow/partial progress cannot
renew the budget indefinitely. Failure closes the connection and releases its
worker admission permit; it never replays a handler or database write.

This does not cancel or bound handler/database work, interrupt serialization,
or prove end-to-end delivery. A committed member change may lose its HTTP
response, so clients must check state instead of blindly retrying. OS scheduling
and timer resolution can affect timeout precision. Tests cover already-expired
budgets after successful progress and a real nonreading TCP peer exhausting a
short test budget, then permit recovery. Default/crypt/SQLite suites pass;
SQLite 83 library + 1 behavior + 22 flow. Live memory HTTP gate 23/23 applicable,
1 skip. No live MySQL integration rerun in this step.

## ADR-0037 — Revalidate latest member flows on real MySQL and HTTP

Provisioned a fresh isolated MySQL 5.7.29 with installer schema/sample data.
Running the full read suite initially exposed a fixture mismatch: the real
fixture lacked the 22 pagination bookmarks required by the wire login test.
Align the installer with that explicit wire fixture (22 owner, one other owner,
one orphan), then run read tests before destructive write tests. The write
suite intentionally removes these rows; rerun reads on a fresh fixture.

All three ignored read tests pass on the new real fixture. Extended destructive
tests verify stored-reservation reading with write flags OFF, case/owner scope,
write refusal and logout-all isolation. They pass with private SQLite sessions
and with memory sessions plus NO_BACKSLASH_ESCAPES. Existing concurrency,
credential upgrade, CAS and dropped-write-ack tests still pass.

A reusable opt-in local HTTP smoke test checks actual Rust HTTP + real MySQL +
SQLite session flow and security headers. It passes, as does the live MySQL
OPAC gate (23/23 applicable, one memory-only skip). This is not running-PHP
parity, FPM/proxy/LDAP validation or modern MySQL/MariaDB coverage. Production
write restrictions and experimental status are unchanged.

## ADR-0038 — Credential-bound last-login timestamp in test-write mode

Expose stored last_login in the private account profile. Only opt-in test writes
record a UTC timestamp after verified bcrypt/MD5 upgrade, using byte-exact member
and expected-hash predicates. Wrong/stale credentials do not update it. MySQL
executes the UPDATE once; when affected rows is zero, a same-connection read
checks whether it was an unchanged matched row (same second), never replaying
the write. Failure after sending the write is unknown. Clear last_login_ip to
NULL because trusted client-IP plumbing is not implemented; do not associate
an old IP or untrusted forwarding header with a new login timestamp.

The update occurs before session rotation; no cross-store transaction exists.
It records a verified attempt, not guaranteed session creation or durable audit.
Rotation failure can leave a timestamp without a session. Normal read-only mode
is unchanged. Tests cover opt-in behavior, bad password, stale/case/injection
predicates, same-second repeats, owner isolation, stale-IP removal and committed
write with dropped acknowledgement on real MySQL 5.7.29 + private SQLite sessions.
Suites pass: SQLite 83 library + 1 behavior + 23 flow. Production writes remain
restricted; no audit/LDAP/proxy or full PHP parity claim is made.

## ADR-0039 — Session-owned basket reservation, test/demo only

The basket page can reserve its current titles only when demo reservations or
explicit member test reservations are enabled. No submitted title/member list
is accepted: CSRF and credential binding are checked, and the authoritative
basket is read while holding the memory session lock or SQLite transaction.
Results are per-title, not an atomic batch; the basket remains unchanged.
Business refusals allow subsequent titles. Any store error stops the batch,
returns an unconfirmed result (503), and marks remaining titles not attempted;
no write is retried. Operators/users must reconcile before resubmitting.

Before each title, recheck the credential binding and a 15-second between-title
budget plus session expiry. An in-flight DB call cannot be cancelled by this
budget, so this is NOT a total request deadline. SQLite/session locks span the
network operations and may delay unrelated sessions. PHP/admin writes remain
outside this lock; no cross-store or cross-process atomicity is implied.

Validation: SQLite 84 library + 1 behavior + 24 member flows; destructive real
MySQL 5.7.29 + private SQLite passes including partial success/limit, retained
basket and repeat behavior. Unit coverage verifies stopping on unknown outcome
and skipping remaining titles. These checks do not enable production writes.

## ADR-0040 — Recheck reservation credential binding inside MySQL locks

HTTP reservation authentication previously refreshed the credential before
acquiring the SQL reservation critical section. An admin password reset could
intervene. Single-title and basket test reservations now call a fail-closed
`test_reserve_bound` store API with the session's SHA-256 credential version.
MySQL compares it to the stored credential under its member READ lock, before
policy checks and insert. No raw password hash is added to session storage.
The unbound store helper is retained only for direct disposable policy tests.

The destructive MySQL 5.7.29/private-SQLite suite passes with an admin-reset
WRITE-lock scenario, stale/empty/case-mismatched bindings, fresh policy disable
and zero limit, and restored valid credentials. See the repository writer
inventory in `docs/member-writer-inventory.md`. This is SQL-level evidence,
not PHP administration concurrency acceptance or production enablement.

## ADR-0041 — Credential-bound bookmark mutations

HTTP bookmark additions and removals now use a fail-closed bound store API,
not the unbound fixture helpers. A missing/stale credential returns false and
the HTTP layer revokes that session. Unsupported backends return an error.

MySQL takes biblio_mark WRITE, member READ and biblio READ locks on a separate
connection, compares the session credential version to the stored hash, checks
visibility for additions, then executes the mutation once on that connection.
All return/error paths drop the connection and release locks. Additions to
hidden/deleted titles return NotFound (404), not silent success. Removal remains
idempotent and permits orphaned or hidden catalog references. The deterministic
PHP-compatible bookmark key is unchanged. A sent write with failed reply remains
WriteUnknown and is not retried. A reset after the lock is released does not
undo an already completed mutation.

Memory uses its password mutex across credential comparison and bookmark
mutation; its catalog is immutable. SQL locks still span network work while the
session lock is held: this is test-only, not a throughput/deployment design.
Session expiry is not atomic with the SQL write. This does not constrain plugins
or make all PHP administration multi-statement operations atomic.

Validation: default 60 library + 1 behavior; member-crypt 74 + 1 + 23 flows;
SQLite 84 + 1 + 26 flows. Real MySQL 5.7.29 destructive suite passes with private
SQLite sessions and with memory sessions plus NO_BACKSLASH_ESCAPES. Added cases
cover stale binding on add/remove after an admin-style reset, owner/case
isolation, visibility changes, deterministic keys, repeated mutation and orphan
removal. This is SQL-level simulation, not full running PHP integration.

## ADR-0042 — Absolute MySQL wire-operation budgets and result ceilings

Handshake and each query/one-shot statement now share a 20-second absolute
socket-I/O deadline within that operation. Every partial socket read/write
recomputes remaining time; trickled bytes no longer renew the budget. Socket
I/O failure shuts down the connection. A request or logical response packet is
limited to 8 MiB; aggregate response packet bytes (including headers) per
operation are limited to 32 MiB. Multi-packet messages exceed the deliberate
logical-packet cap and are rejected, not concatenated without a bound.

Results allow at most 256 columns, 10,000 rows and 262,144 cells, checked before
corresponding result allocation. Resource refusal shuts down the stream so
unread packets cannot be consumed as the next response. Oversized query strings
are rejected before constructing a duplicate COM_QUERY buffer. Length-encoded
values use checked conversion/bounds, reject reserved integer markers, and a
truncated error SQLSTATE no longer permits out-of-bounds slicing.

These are compatibility ceilings: large legitimate responses now fail rather
than truncate. They are not a process memory limit (decoded structures and
copies add overhead). DNS resolution/TCP connect, mutex/session waits and CPU
processing are outside the operation deadline. Multiple SQL statements, the
read-only reconnect/retry, and catalog hydration each get new operation budgets;
there is still no total handler deadline or server-side cancellation guarantee.
One-shot writes remain never retried; a failure after send may still mean a
committed write. TLS is not added by these protections.

Tests cover trickled responses, blocked socket writes, connection shutdown after
budget failure, oversized/aggregate packets, excessive columns/rows/cells,
normal results and hostile lengths. Full suites pass: default 65+1,
member-crypt 79+1+23 flows, SQLite 89+1+26 flows. Fresh real MySQL 5.7.29 fixture
read integrations pass 3/3; the destructive private-SQLite write suite also
passes. No production enablement or whole-handler resource guarantee is made.

## ADR-0043 — Reject unsynchronized MySQL responses

Incoming packets must match the current sequence number, including wrapping
at 255; each new command resets the sequence to zero. Do not advertise
CLIENT_MULTI_RESULTS: the application does not consume multiple result sets.
Result metadata/final EOF must be a complete five-byte protocol-41 EOF and
must not signal MORE_RESULTS. Command OK packets are parsed instead of accepting
an arbitrary leading zero; query OK warnings are allowed while one-shot write
warnings remain conservatively unconfirmed. Rows must have exactly the declared
values without trailing bytes (NULL remains supported).

Any query or one-shot-command error shuts down the stream, including parser
errors that could leave response packets unread. The read-only store reconnect
path may still retry a read once, but it cannot reuse the unsynchronized socket.
Writes are not retried. These checks do not authenticate the database peer or
substitute for TLS, and they do not implement stored-procedure/multi-result or
EOF-deprecation protocol extensions.

Tests include sequence mismatch/wrap, malformed OK/EOF, unexpected extra results,
trailing row bytes, valid NULL, warnings, and consecutive command sequence reset.
Full suites pass: default 68+1; crypt 82+1+23 flow; SQLite 92+1+26 flow.
Fresh real MySQL 5.7.29 fixture read tests pass 3/3; destructive SQL writes with
private SQLite sessions pass, including committed writes with dropped replies.

## ADR-0044 — Correct and constrain MySQL plugin authentication

AuthSwitchResponse now sends the raw native/caching-SHA2 scramble, not a
length-encoded field (that encoding belongs to the initial handshake response).
Both supported plugins require a 20-byte nonce, optionally followed by one NUL
terminator. Preserve zero-valued nonce bytes rather than stripping all trailing
zeros, which could randomly break otherwise valid authentication.

Unknown initial plugins are refused before emitting a credential-derived
response instead of silently using a native scramble. Unsupported switched
plugins remain refused. Authentication OK must parse as a complete protocol-41
OK. Only the exact caching-SHA2 fast-success continuation is accepted; a final
OK is still required. Full authentication/cleartext/RSA is not implemented and
remains refused. Protocol NUL-terminated strings must actually terminate.

Socket-level tests cover raw switched native/SHA2 responses, a nonce ending in
zero, malformed/nonterminated messages, unsupported initial plugins emitting
no response, full-auth refusal and fast-success requiring final OK. SHA2 tests
use a scripted peer, not a MySQL 8 deployment; real validation is MySQL 5.7.29
native auth. Fresh fixture reads pass 3/3 and destructive private-SQLite writes
pass. Full suites: default 71+1; crypt 85+1+23 flow; SQLite 95+1+26 flow.
No TLS or production authentication readiness is claimed.

## ADR-0045 — Bound TCP attempts/read queue and consolidate validation

MySQL TCP connection candidates share a five-second budget after OS hostname
resolution, with at most eight candidates. A hostname's OS DNS resolution is
still outside the budget. Bracketed IPv6 hosts are normalized before tuple-based
resolution. Pooled read-connection mutex acquisition now uses a three-second
budget with a short bounded backoff; queue saturation returns an error rather
than waiting indefinitely. Existing operation budgets/write no-retry remain.
These are best-effort local waits, not a total handler deadline, fair queue,
query cancellation or throughput acceptance. SQLite/session locks are separate.

Add `tools/test_member_suite.py` and safety tests. Explicit consent plus an empty
literal-loopback slims_test fixture is required; never create/drop databases.
Run safety/optional isolated PHP checks, all three Cargo configurations, build,
fresh MySQL reads, dynamic-port live HTTP writes-off/OPAC gate, then destructive
SQL writes. Clear inherited SLIMS/proxy settings; private session storage,
per-stage subprocess timeouts and process-group cleanup include SIGTERM.
JSON reports omit credentials and always retain production_ready=false; refusal
replaces stale passed reports. A failed fixture is not automatically replayed.
See `docs/member-validation.md` for the reproducible operator command and gaps.

Real complete run passed MySQL 5.7.29, 3/3 reads, HTTP member smoke, OPAC 23/23
applicable, destructive writes/private SQLite, PHP WASM 8 CLI + 16 HTTP cases.
Rust regressions: default 73+1; crypt 87+1+23 flow; SQLite 97+1+26 flow. Safety
checks pass including actual nonempty-fixture refusal. No production cutover.

## ADR-0046 — Port the combined PHP member loan report to Rust

Implement the feature behind `download_member_loan_report.inc.php` as
`/member?sec=loan_report`, with a GET/HEAD-only compatibility alias
`/index.php?p=download_member_loan_report`. It requires current Rust member
authentication; no member ID can be selected from the request. Duplicate,
conflicting legacy, page, forged owner and unsupported format selectors are
refused. Anonymous/revoked callers get the member sign-in flow, not an attachment.
No legacy POST is replayed and PHP sessions are not converted.

The report includes profile name/ID/email/type/register/expiry and current plus
returned loans (including due and return dates). Loan rows are fetched in ONE
bounded store read with current first then descending date/ID, not assembled
from shifting offset pages. Profile and credential checks are separate reads;
there is no cross-store snapshot/atomic revocation guarantee. Refresh the
credential again after report construction before serving it. Exact predicates
on both owner columns prevent MySQL case-insensitive collation aliases. Testing
found MySQL 5.7.29 returned no rows for a binary-to-binary join plus binary hex
filter; a normal join with two independent byte-exact owner filters works and
is now covered by real case-alias tests.

Return at most 1001 rows from the backend; over 1000 loans or 512 KiB of raw
report fields returns 413 without a partial report/attachment. This is a safety
ceiling, not permission to silently drop old history. Paginated views remain
available. HTML is escaped, standalone and printable using a same-origin CSS
file (no CSP-violating inline styles/scripts). CSV uses the existing BOM/CRLF,
quoting and spreadsheet-formula neutralization; it omits profile metadata and
uses a fixed non-identifying filename. All responses retain private/no-store,
no-referrer, nosniff and existing CSP policies.

Correction to earlier ADR-0023/other notes mentioning legacy PDF reports: these
three PHP member download scripts actually emit HTML attachments. The combined
Rust report is an inline printable HTML replacement plus CSV, not byte-for-byte
PHP markup/download-header parity and not server-side PDF generation. The two
older single-section download aliases remain unported/guarded in PHP. Browser
Print/Save as PDF is user-operated and not a certified report.

Validation: complete runner on a fresh MySQL 5.7.29 fixture passes default
73+1, crypt 88+1+24 flow, SQLite 98+1+27 flow, real read integrations 3/3, live
member report/CSV/HEAD and OPAC 23/23 applicable, destructive writes/private
SQLite including 25-loan combined report and >1000 rejection, plus isolated PHP
8 CLI/16 HTTP cases and six runner safety tests. No production cutover.

## ADR-0047 — Typed runtime boundary and redacted operational telemetry

The executable now loads strict `runtime::RuntimeConfig` and fails before bind
on invalid settings instead of silently choosing/clamping defaults. ConfigError
contains only a static variable name/reason. Existing AppState feature/write
ownership checks remain. MySqlUrl Debug redacts password. Preserve legacy Config
loader for library compatibility; the executable no longer uses it.

Introduce validated `http::ServerSettings` and compatibility-preserving server
entry points. Runtime connection/keep-alive and absolute socket read/write limits
are actually applied; parser byte caps and Store are unchanged. Dispatch adds a
server-owned X-Request-ID and optionally emits JSON response_prepared events with
only method/route-class/status/duration. No raw URL/header/body/member ID or SQL
credential logging. Correlation is not authorization; prepared is not delivered,
committed or durable audit. Synchronous stderr logging can add latency.

Axum/Tokio, SQLx, Askama/MiniJinja and tracing are NOT adopted: both crates.io
index and static archive access failed in this environment. Do not add an
unresolvable optional dependency (Cargo still resolves it), break offline builds,
or claim homegrown telemetry is tracing. See docs/backend-foundation.md for
current settings, limits, evidence and staged adapter/async/TLS migration plan.

Validation: full offline/disposable MySQL 5.7.29 runner passes: default 77+1+2
binary tests; crypt 92+1+24 flows+2 binary; SQLite 102+1+27 flows+2 binary;
MySQL reads 3/3, live member/OPAC 23/23 applicable, destructive private-SQLite
writes and six runner safety tests. No schema change, production enablement,
framework/TLS adoption or claim of completing the entire foundation proposal.

## ADR-0048 — Port single-section downloads and add PR validation

Add scoped Store reads (All/Current/History) for member reports. Scope filtering
must occur before applying the 1001 sentinel; do not filter a capped combined
report in the renderer. Unsupported stores fail closed for scoped requests.
Renderer rejects a backend scope mismatch, shares CSV formula protections,
uses fixed download filenames and refuses oversize without a partial attachment.

GET/HEAD legacy current/history routes return HTML attachments and support
explicit CSV. Canonical report scope defaults All; format=html downloads HTML.
Conflicting/duplicate selectors and owner overrides fail, POST is never replayed,
and revoked/anonymous sessions get sign-in without a download header. All member
privacy/security policies remain. Telemetry classifies all three aliases as
member requests. These are functional HTML/CSV replacements, not byte parity.

Prepare a GitHub Actions workflow template: offline feature matrix, runner safety, native PHP
ownership guard and a disposable MySQL 5.7.44 service/full runner with redacted
JSON artifact. Pin external actions, read-only repository permission, no live
credentials, bounded job times. This is test-only and never deploys. Workflow
presence/syntax checks do not themselves prove CI passed.

Local validation used a temporary restored Rust 1.92 nightly toolchain outside
the repository (npm archive integrity verified); current sandbox lost the prior
toolchain/MySQL server. Default 78+1+2 binary, crypt 94+1+25 flow+2 binary,
SQLite 104+1+28 flow+2 binary, six runner safety tests, live memory/private-SQLite
member HTTP (HTML/CSV/HEAD aliases) and OPAC gate 23/23 applicable pass. New
MySQL scope/overflow assertions are added but not claimed run locally. YAML
structure and pinned action references checked; actionlint download was blocked.
CI uses Rust 1.85.1; results will be recorded separately after execution.

CI installation blocker: GitHub rejected the push because this App lacks
`workflows` permission. Preserve the template as docs/ci/rust-validation.yml,
NOT an active .github workflow. Push feature changes/docs normally. New MySQL
scope assertions remain unexecuted; an authorized maintainer must install the
template before expecting check runs. No permissions or branch protection
settings were changed, and no passing CI result is claimed.

## ADR-0049: revision-bound disposable validation evidence

Record schema, start/finish timestamps and Git revision/dirty state at both ends
of the disposable suite. Add an offline fail-closed report verifier: require
complete ordered core stages, requested optional stages, clean expected commit,
no unsupported production/TLS/full-PHP claims, and bounded evidence age. Lock
Cargo resolution in the runner. Keep JSON outside the checkout. Template CI
checks this evidence after the suite, but is not installed or active.

This checks consistency, not authenticity or production acceptance; reports can
be forged and source changes reverted mid-run escape detection. Old reports
must be regenerated. Database-free safety tests (11) passed; real MySQL scope
acceptance is still pending. No schema, runtime or production-write change.

## ADR-0050: read-only public news and content

Port public `content` reads for `/news`, `/pages/{path}` and legacy query routes.
Filter drafts/admin/headerinfo/invalid paths before pagination, bound offsets,
use stable ordering with an 11-row sentinel. Literal keyword search instead of
BOOLEAN FULLTEXT; no total count. SQL inputs use hex, independent of backslash
mode. Default Store implementation fails closed. Unknown legacy page selectors
now produce a content lookup/404 rather than falling back to catalog home.

Do not execute stored rich HTML: emit only exact attribute-free formatting tags
and escape everything else under restrictive CSP; title/body bounds fail with
413. Language fallback, links/images/CKEditor/plugin output and CMS writes are
not ported. See docs/opac-content.md for exact compatibility restrictions.
Fix test HTTP client HEAD handling discovered by actual TCP regression tests.
Add source-derived gate cases, six content tests and opt-in fresh MySQL fixture
coverage. No schema change or production writer enablement. Real SQL tests are
compiled only in this increment; full PHP and MySQL acceptance remain pending.

## ADR-0051: public librarian directory without credential projection

Port `/librarians` and legacy `?p=librarian` from the PHP directory. Match its
public type selection (1/2) and 20-profile cap with a deterministic ID tie-breaker.
Filter before cap in both stores; use a dedicated model with only public fields
and a role enum that cannot represent private staff types. MySQL must never use
SELECT * on user for this feature. No authentication/admin/profile writer added.

Escape metadata and restrict portraits to local raster basenames. Do not parse
serialized social media or execute raw fields. Reject unsupported selectors and
non-read methods; failures/bounds are explicit rather than partial/empty success.
See docs/opac-librarians.md for privacy, compatibility and validation details.
Six directory tests + three unit tests and four gate cases added. All three
feature suites, Python safety and live memory gate31/31 passed. New disposable
SQL integration is compiled only; CI and production blockers unchanged.
