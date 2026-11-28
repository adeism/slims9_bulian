# Backend foundation: implemented boundary and remaining framework migration

## Scope and status

This increment strengthens process configuration, transport policy and operational
logging without changing SLiMS schema, application routes, member ownership or
production-write guards. **It does not install Axum/Tokio, SQLx, Askama/MiniJinja
or tracing.** Network checks in this environment failed for both
`https://index.crates.io/config.json` and the static crate archive endpoint;
GitHub was reachable. No unresolvable dependency was added to Cargo.toml, and
all builds/tests remain offline-capable.

| Foundation | Current implementation | Remaining work |
| --- | --- | --- |
| Typed configuration | `runtime::RuntimeConfig`, `ConfigError`, startup validation | A configuration crate is optional, not yet adopted |
| HTTP transport policy | `http::ServerSettings`, configurable resource budgets | Axum/Tokio adapter; not implemented |
| Operational logs | JSON events, server-owned request IDs, redacted fields | tracing subscriber/spans/exporters; not implemented |
| Database boundary | Existing `Store` unchanged, password-redacted URL Debug | SQLx pool, async boundary and verified TLS; not implemented |
| HTML rendering | Existing escaping and CSP preserved | Askama/MiniJinja port; not implemented |

## Executable startup behavior

The executable now uses `RuntimeConfig::from_env`, not the permissive compatibility
`Config::from_env` loader. Invalid input exits **2 before listening** and emits a
JSON `configuration_refused` event containing only the variable name and a static
reason. A bad database URL no longer silently selects memory even in OPAC mode.
Invalid member feature/ownership combinations still fail through `AppState` and
emit `application_startup_refused`. Listener errors exit 1. No configuration dump
or database endpoint/password is logged. `MySqlUrl` Debug redacts its password.

Inputs are actual process environment variables. There is no implicit .env
loader; an operator/service manager must supply them. Non-Unicode recognized
settings fail, unknown settings are ignored. Root must be an absolute repository
path containing `install/senayan.sql` (default is discovered from working dir).
Changing an explicitly empty/invalid setting from silently ignored to rejected
is an intentional compatibility change.

| Variable | Default | Accepted values |
| --- | --- | --- |
| `SLIMS_ADDR` | `0.0.0.0:8080` | IP:port, including bracketed IPv6; port 0 allowed for ephemeral tests |
| `SLIMS_DB` | memory when absent | Exactly `memory`, or existing MySQL URL syntax, nonempty user/schema, nonzero port, valid IP/DNS host; URL options/escapes unsupported |
| `SLIMS_LIBRARY_NAME`, `SLIMS_LIBRARY_SUBNAME` | existing names | 1–512 bytes, nonblank, no control characters |
| `SLIMS_POPULAR_LIMIT`, `SLIMS_LATEST_LIMIT` | 6 | 1–50 (reject, no clamp) |
| `SLIMS_CACHE_TTL` | 18000 | 0–604800 seconds |
| `SLIMS_HTTP_CACHE_LIFETIME` | 0 | 0–86400 seconds; member responses remain no-store |
| `SLIMS_HTTP_MAX_CONNECTIONS` | 128 | 1–1024 |
| `SLIMS_HTTP_MAX_REQUESTS` | 100 | 1–1000 per connection |
| `SLIMS_HTTP_READ_SECONDS` | 15 | 1–60, absolute request-read budget |
| `SLIMS_HTTP_WRITE_SECONDS` | 15 | 1–60, absolute response-write budget |
| `SLIMS_HTTP_ACCESS_LOG` | 1 in executable | Exactly 0 or 1 |
| Existing member/demo/write flags | 0 | Exactly 0 or 1, with existing feature/test-only guards |
| `SLIMS_SESSION_DB` | absent | Absolute private local path; SQLite store validates permissions/schema |
| `SLIMS_DEMO_MEMBER_HASH` | absent | Nonempty, <=128 bytes, no controls; experimental memory member mode only |

These transport settings do not make thread-per-connection into an async server,
create a fair queue, cancel MySQL work, bound an entire handler, or introduce a
graceful-shutdown/draining protocol. Hard header/body parser caps remain unchanged.
Library callers can still use existing default transport APIs; advanced callers
can supply validated `ServerSettings` via `serve_with_settings` or
`serve_listener_with_settings`. The `Handler`/`Store` boundaries remain unchanged.

## Logs and correlation

Every parsed/dispatched HTTP request receives a server-generated `X-Request-ID`,
including OPTIONS responses. Incoming IDs are not trusted or reused; an existing
response ID is replaced, not duplicated. IDs are operational correlation labels,
not credentials or cryptographic tokens. Malformed/overload connections closed
before dispatch do not receive an ID or request event.

When enabled, one JSON `response_prepared` record includes:

- timestamp, server request ID;
- allowlisted HTTP method and coarse route class (`member`, `api`, `health`,
  `static`, `opac`);
- response status and monotonic handler duration in microseconds.

It deliberately excludes raw paths/query strings, bodies, headers/cookies,
authorization tokens, member IDs, database URLs and passwords. The legacy
`slims9: bound http://...` startup marker remains for existing readiness tooling;
other new lifecycle messages are JSON. Logging errors do not panic or repeat
application actions. Logs are synchronous stderr writes; a blocked sink can add
latency. Use an operationally managed sink; there is no async exporter/buffer.

**response_prepared is not proof of delivery or a durable audit record.** It
occurs before socket delivery. A disconnected client may still have caused a
committed write, and logged status does not resolve an unknown database outcome.
Member response security headers and no-store policies are unchanged.

## Verified in this increment

- Typed/default configuration, invalid inputs and credential-safe errors.
- Operational event redaction and unique server-owned response IDs.
- Real executable startup exits before binding on invalid settings.
- Real sockets respect configured request/connection caps; incoming request IDs
  and secrets do not enter request events.
- Complete disposable MySQL 5.7.29 runner: default 77 library + 1 behavior + 2
  binary tests; crypt 92 + 1 + 24 member flows + 2 binary tests; SQLite 102 + 1 +
  27 member flows + 2 binary tests; fresh reads 3/3, live member HTTP/report/CSV,
  OPAC 23/23 applicable and destructive private-SQLite writes pass. Six runner
  safety tests also pass. PHP code was not changed and optional isolated PHP
  tests were not rerun in this increment.

## Framework adoption plan once dependencies are available

1. Choose/pin versions and supported Rust version, obtain Cargo.lock and review
   license/advisory/dependency tree; do not label unavailable crates installed.
2. Introduce an Axum/Tokio transport adapter behind the existing request/response
   boundary. Preserve duplicate selectors, cookies, security headers, body limits,
   HEAD and error semantics in wire tests. Bound any `spawn_blocking` bridge;
   never call current blocking MySQL/bcrypt/session code directly on the reactor.
3. Add tracing behind the event boundary with the same redaction allowlist;
   prohibit raw request-target/header spans and distinguish prepared/delivered.
4. Add SQLx behind the store boundary, with required certificate-verifying TLS
   and separate read/write pool policy. The existing trait is synchronous: plan
   its async conversion, not a nested runtime per query. Keep one-shot write and
   ambiguous-outcome rules. MyISAM tables do not gain transaction semantics from
   changing drivers. Do not remove disposable test-only writer restrictions.
5. Port templates individually with automatic HTML escaping and unchanged CSP;
   run behavior/member/live gates before replacing the legacy implementation.

Production migration remains blocked by the deployment/security/ownership
acceptance criteria in `member-cutover.md`; these foundations do not clear them.
