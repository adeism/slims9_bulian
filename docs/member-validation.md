# Reproducible member validation (not production acceptance)

`tools/test_member_suite.py` combines the regression, fresh-fixture, live HTTP
and destructive write checks in their required order. It never creates or drops
a database and never enables production writes. Use **disposable demo data**.

## Prerequisites and target

- Linux, Rust/Cargo on PATH, libcrypt and libsqlite3 runtime libraries.
- Python 3.9+ with PyMySQL installed in an external virtual environment.
- Running local MySQL with an **existing empty** database named `slims_test...`
  and a fixture-only account with schema/data/LOCK TABLES permissions there.
- The host must be literal `127.0.0.1`. The database name may contain only ASCII
  letters, digits and underscores after the `slims_test` prefix. The test runner
  rejects escaped URL values, URL queries/fragments and control characters to
  avoid disagreement between Python and the Rust fixture URL parser.
- No other test/job/operator may use the fixture concurrently. Localhost and
  the prefix are safety checks, not proof that data is disposable.

Set `SLIMS_TEST_MYSQL_URL` in your environment to the fixture connection URL;
do not commit it or paste real credentials into test reports. The runner reads
it from the environment and never includes the URL/password in its JSON report.

```bash
# Run with the Python interpreter from the PyMySQL virtual environment.
SLIMS_TEST_SUITE_ALLOW=1 python3 tools/test_member_suite.py \
  --report /tmp/slims-member-validation.json
```

Optional isolated PHP ownership checks can be included in the same run:

```bash
SLIMS_TEST_SUITE_ALLOW=1 python3 tools/test_member_suite.py \
  --php-command 'node /tmp/slims-php-tests/node_modules/@php-wasm/cli/php-wasm.js' \
  --php-wasm-modules /tmp/slims-php-tests/node_modules \
  --report /tmp/slims-member-validation.json
```

Native PHP can be used instead for `--php-command 'php'`. See
[member-cutover.md](member-cutover.md) for installation of optional PHP WASM
tooling. The HTTP-response option uses PHP WASM and needs Node. Those tests
are explicitly **isolated**: no FPM/reverse proxy or PHP/MySQL admin flow runs.
Omitted PHP options are reflected as uncovered, not implied passes.

## Order and safety behavior

1. Explicit consent and URL restrictions; read-only connection/empty-DB preflight.
2. Runner safety tests and any requested isolated PHP tests.
3. Default, member-crypt and member-sqlite Cargo regressions, offline.
4. Build member-sqlite binary, then bootstrap fixture (rechecks empty database).
5. Explicitly run ignored MySQL read integrations while fixture is still fresh.
6. Start a test HTTP server on `0.0.0.0` with an OS-assigned port, private local
   SQLite sessions and **all SQL-write switches OFF**. Read the actual bound port
   from its startup log rather than guess/release a port. The runner's requests
   use `127.0.0.1`; do not expose this insecure-cookie test server externally.
7. Run member HTTP smoke and live MySQL OPAC gate, then stop the HTTP server.
8. Explicitly run the destructive write integration with private SQLite sessions.
9. Write optional stage results and return nonzero on failure.

Inherited `SLIMS_*` values are cleared before constructing child configuration;
write flags/session paths from a developer shell cannot silently enable writes
on the live smoke server. Proxy variables are cleared for local HTTP clients.
Child processes use separate process groups, per-stage timeouts and cleanup on
success, failure, interruption and SIGTERM. SIGKILL/machine failure cannot run
cleanup. No database rollback/restore/automatic drop is attempted. If a run
fails after bootstrap, **use a new empty fixture**; do not blindly replay it.
The optional report is marked failed before preflight so a stale passed report
cannot survive target refusal. `production_ready` is always false.

Database-free safety checks:

```bash
python3 -m unittest discover -s tools -p test_member_suite_safety.py -v
```

## Evidence and limits

Validated locally on MySQL 5.7.29 with a fresh fixture: default 73 library + 1
behavior; crypt 87 + 1 + 23 flow; SQLite 97 + 1 + 26 flow; explicit MySQL reads
3/3; member live HTTP smoke; OPAC live gate 23/23 applicable (one memory-only
case skipped); destructive SQL/private-SQLite integration. Included isolated PHP
checks passed 8 CLI/lint tests and 16 HTTP-response cases. Runner safety coverage
also verifies refusal, configuration isolation, timeout/failure reporting,
subprocess shutdown, SIGTERM cleanup and stale-report replacement. An actual
non-empty local fixture was refused before mutations or regression execution.

These checks **do not** validate MySQL TLS, MySQL 8/MariaDB compatibility,
LDAP/CAPTCHA policy, durable audit, executing PHP admin/plugin concurrency,
load/security review, FPM/proxy routing, backups or rollback approval. See
[member-cutover.md](member-cutover.md) and
[member-writer-inventory.md](member-writer-inventory.md). Passing this runner is
a reproducible development milestone, not permission to remove test-only guards.

### Combined report coverage (ADR-0046)

The live member smoke now checks the legacy combined-report alias, printable
HTML, combined CSV and HEAD body suppression. Real write integration checks a
report spanning 25 loans (beyond one UI page), missing catalog items, owner-case
aliases, and refusal beyond 1000 loans without partial attachments. Latest full
regressions: default 73+1; crypt 88+1+24 flow; SQLite 98+1+27 flow. Remaining
MySQL/live/PHP/safety stages above also pass with this feature.

### Scoped download and PR workflow coverage

The HTTP smoke also exercises current/history HTML and CSV aliases plus HEAD
suppression. Memory tests prove filtering precedes the overflow sentinel. The
MySQL destructive suite now asserts small history remains downloadable while
a current/combined report overflows. Run it with a fresh fixture; adding the
assertion is not evidence it passed on a particular MySQL version.

The inactive template `docs/ci/rust-validation.yml` is prepared to automate the offline feature
matrix, safety/native-PHP guard tests and a fresh MySQL 5.7.44 full suite. The
service uses fixture-only credentials; only credential-free stage JSON is
uploaded. Read the actual check results on the PR. Rust is pinned to 1.85.1
for CI (this does not assert the declared minimum Rust version was tested).

The GitHub App cannot create/update workflows (push explicitly rejected). The
template is stored under docs only; install it at
`.github/workflows/rust-validation.yml` using an identity with workflows
permission. No new CI/MySQL scope execution has occurred; do not interpret
this template as an active or passing check.
