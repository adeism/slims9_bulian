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

Phase 2 has an **experimental, opt-in** bcrypt sign-in, CSRF-protected POST
logout, current-loan/history and read-only My Account views, and optional local
SQLite session storage. The default build
still serves the Phase 1 placeholder. This is **not ready for production**:
the login limiter is per-process only, and there is no multi-host session store,
TLS in the MySQL client, MD5 migration, LDAP/captcha, password changes, real
reservations or write audit. The login has not been tested against real MySQL
or recorded against a running PHP instance.

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


Demo reservations use only the in-memory backend and vanish on restart; they do
not reserve items in SLiMS. Duplicate and member-type limit checks are atomic
within one process. `SLIMS_DEMO_RESERVATIONS=1` is rejected on MySQL or without
member mode. The single-process limiter permits 8 login attempts per member and
200 globally per 10 minutes, with at most 4 concurrent verifications. It is
not distributed or IP-aware, can be abused to deny service, and resets on
restart; use an independently managed edge limiter before production use.


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
CSRF tokens and member IDs/names are not encrypted; protect backups and journals.
Expiry uses the wall clock (30 minutes), expired records are cleaned on session
creation. On each protected request, a fingerprint of the current stored
password hash is compared with the login-time fingerprint; password-hash change
or a missing member revokes the session. Backend failures return HTTP 503
without granting access. Expiry/pending status changes and restoration of the
same hash are not detected, and a credential change concurrent with a request
may race its check. Migrating an older SQLite session database logs out all
previously authenticated sessions; stop every old worker before migration.
Run `cargo test --offline --features member-sqlite`. See ADR-0016/0017; this
remains a prototype, not production-ready.
