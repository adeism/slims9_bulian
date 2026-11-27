# Member-area cutover: Rust owns the member service

## Decision and current readiness

The selected target is **Rust replacing the member area**, with PHP retained for
administration. This is not permission to enable prototype SQL writes on real
data. Rust member mode remains experimental; test writes remain restricted to
local disposable `slims_test*` databases. No production cutover has been executed.

The old Phase 1 `rust/cutover.sh` is not a member cutover installer. Do not run
its `--apply` expecting member traffic or write ownership to be configured.

## Opt-in PHP gate

Set `SLIMS_MEMBER_OWNER=rust` in **every PHP worker's environment** to reject:

- `index.php?p=member` before database/session bootstrap, for all HTTP methods;
- the three legacy member loan-download routes and their content includes;
- direct member content includes, Validator member dispatch (including public
  custom-provider overrides), Contract member dispatch and member-session generation;
- direct Native/LDAP authentication and legacy member_logon entry points;
- new reservations submitted through PHP's circulation `reserveItemID` action.

Responses are 503, no-store and contain no redirect. Never replay PHP POSTs to
Rust: form fields, sessions and CSRF tokens are incompatible. A reverse proxy
must route new member requests to Rust; the gate is a backstop for missed routes.
Setting an unknown nonempty owner fails closed on guarded paths. Unset, empty,
or `php` preserves the previous PHP behavior. Configure this as a service/FPM
worker environment value, **not an HTTP header or request parameter**. Verify
FPM environment filtering and all instances, not just a CLI shell.

The flag does not turn on Rust, change database permissions, delete PHP sessions,
or stop requests already running. Existing PHP bearer cookies become usable
again if the gate is removed; plan invalidation of legacy sessions before rollback.
`utility::isMemberLogin()` also refuses to recognize old PHP member sessions
while Rust owns the area, without deleting them. PHP admin login is not gated.
Custom providers invoked outside Validator/Contract, constructors, hooks, plugins,
scripts, direct SQL writers and alternate entry points must still be inventoried;
these guards cannot constrain arbitrary custom PHP code.

PHP circulation reservation deletion/loan fulfillment and member administration
remain possible. These affect Rust reads and reservation counts. They must be
covered by integration/concurrency tests before production. Do not call this
absolute exclusive database ownership or rely solely on the environment flag.

## Required order before a real cutover

1. Back up database, configuration and session storage; test restoring them.
2. Inventory PHP/plugin/external writes to member credentials, bookmarks,
   reservations, member status/types, items and loans. Decide which remain in
   administration and how their effects coordinate with Rust.
3. Validate the chosen native/LDAP/CAPTCHA and password policy requirements.
   Rust does not yet implement all PHP providers/policies.
4. Complete production write recovery, audit/last-login, TLS, deployment session
   and rate-limit design, all-loan report requirements and security review.
   Do not remove test-only DB guards as a substitute for this work.
5. Run PHP + Rust + MySQL/MariaDB integration with the same disposable schema
   and policy configuration, including concurrent admin changes and failed writes.
6. Enter a maintenance window; stop/drain old member writers and in-flight jobs.
   Set the PHP ownership gate everywhere and verify missed routes fail closed.
7. Configure and verify reverse-proxy routing for `/member` and legacy member
   URLs, including POST. Legacy downloads are HTML attachments, not PDFs. The combined report has a
   bounded Rust HTML/CSV replacement (ADR-0046); separately route/retire the two
   older single-section download URLs. No automatic PHP session conversion or
   POST replay is supported.
8. Verify login, credential changes, bookmark isolation, reservation policy and
   recovery, logout/revocation, CSV and monitoring on the intended deployment.
9. Obtain operational approval. Only then implement/enable a reviewed production
   configuration. No step in this document performs that enablement.

## Rollback

Stop/drain Rust member writes first. Review unknown write outcomes and reconcile
reservations/credentials before restoring PHP ownership. Invalidate legacy PHP
sessions as planned, restore proxy routing, set `SLIMS_MEMBER_OWNER=php` (or unset)
on every PHP worker and restart/verify them. Keep Rust sessions private and
invalidate them if appropriate. Never run both uncoordinated member writers to
avoid downtime. Database restoration is a separate, explicitly approved action.

## Tests

`python3 tools/test_member_owner.py` uses native PHP CLI by default. It covers
allow/deny owner values, early member/download route refusal, content includes
provider/session-generation and legacy-login refusal, old-session recognition,
continued administrator dispatch and lint for changed PHP files. `PHP_COMMAND` may specify a PHP CLI-compatible
command. The isolated tests passed with PHP WASM CLI; this is **not** a running
SLiMS/FPM/proxy/database integration test or production acceptance.

### Isolated HTTP-response tests (PHP WASM)

`tools/test_member_owner_http.mjs` executes PHP request/response contexts rather
than CLI output alone. It checks all four legacy routes for GET/POST/HEAD,
503 status, plain-text content type, no-store/nosniff/no-referrer, absence of
Location, invalid-owner refusal and inability of request fields/headers to
replace the configured worker owner. It does not open a network listener or
exercise an FPM/reverse-proxy deployment.

Install optional test tooling outside this repository (tested package version
3.1.66; use the Node version supported by the packages):

```bash
npm install --prefix /tmp/slims-php-tests --ignore-scripts --no-audit --no-fund \
  @php-wasm/cli@3.1.66 @php-wasm/node@3.1.66 @php-wasm/universal@3.1.66
PHP_COMMAND='node /tmp/slims-php-tests/node_modules/@php-wasm/cli/php-wasm.js' \
  python3 tools/test_member_owner.py
PHP_WASM_NODE_MODULES=/tmp/slims-php-tests/node_modules \
  node tools/test_member_owner_http.mjs
```

Results for this step: 8 gate/lint tests and 16 HTTP-response cases passed.
`Native::setMemberData()` is gated too: this public helper writes last-login
data and must not remain an alternate PHP member-write entry point. The HTTP
cases validate headers produced by PHP WASM, not headers as delivered by FPM
or a deployed proxy. No running SLiMS/database parity is asserted.

## Writer review baseline

See [member-writer-inventory.md](member-writer-inventory.md) for the reviewed
repository entry points and remaining deployment inventory. ADR-0040 adds a
credential-version recheck inside the SQL reservation critical section, tested
against an admin-style password reset and changed reservation policies. This
does not replace full PHP admin/plugin concurrency acceptance.

## Combined loan report routing (ADR-0046)

Rust now handles `/member?sec=loan_report` plus its CSV format and the GET/HEAD
alias `/index.php?p=download_member_loan_report`. Proxy this query route to Rust
rather than allow the old PHP include through the ownership gate. Reject/retire
legacy POSTs; never redirect/replay them as authenticated actions. Rust sessions
are required. The PHP source emits HTML attachments (earlier PDF references
were inaccurate); Rust serves printable inline HTML plus CSV with documented
size ceilings and different markup/header behavior. Current/history-only PHP
download routes still need separate routing/retirement decisions. Passing the
report checks does not enable production writes or full PHP parity.
