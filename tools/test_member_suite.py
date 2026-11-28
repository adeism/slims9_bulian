#!/usr/bin/env python3
"""Opt-in disposable MySQL validation: regression -> fresh reads -> HTTP -> writes.

Requires Rust/Linux libcrypt/libsqlite3, Python with PyMySQL, and an EXISTING
EMPTY local slims_test* database. Never creates/drops databases, never uses real
member data. Set SLIMS_TEST_SUITE_ALLOW=1 and SLIMS_TEST_MYSQL_URL. Test writes
change the fixture permanently; use another empty database for each run.
"""
import argparse
from contextlib import contextmanager
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlsplit
from urllib.request import ProxyHandler, build_opener

from member_validation_report import SCHEMA_VERSION, revision, timestamp

ROOT = Path(__file__).resolve().parent.parent


def validate_target(raw, consent):
    if consent != '1':
        raise ValueError('Set SLIMS_TEST_SUITE_ALLOW=1 for disposable data only')
    try:
        url = urlsplit(raw)
        valid = (url.scheme == 'mysql' and url.hostname == '127.0.0.1'
                 and url.username and not url.query and not url.fragment
                 and re.fullmatch(r'/slims_test[A-Za-z0-9_]*', url.path)
                 and 1 <= (url.port if url.port is not None else 3306) <= 65535
                 and not any(c in raw for c in '%\\?#')
                 and not any(ord(c) < 33 or ord(c) > 126 for c in raw))
        if not valid:
            raise ValueError()
    except ValueError:
        # Do not echo the URL: it contains credentials.
        raise ValueError('Requires mysql://user:password@127.0.0.1:port/slims_test... (no escapes/query)') from None
    return url


def clean_environment(source):
    env = {k: v for k, v in source.items() if not k.startswith('SLIMS_')}
    env['CARGO_TARGET_DIR'] = str(ROOT / 'rust' / 'target')
    # HTTP acceptance must never send fixture credentials through an ambient proxy.
    for key in list(env):
        if key.lower() in ('http_proxy', 'https_proxy', 'all_proxy'):
            del env[key]
    env['NO_PROXY'] = '127.0.0.1,localhost'
    env['no_proxy'] = env['NO_PROXY']
    return env


def stop_child(child):
    if child.poll() is None:
        try:
            os.killpg(child.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            child.wait(timeout=5)
        except subprocess.TimeoutExpired:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            child.wait(timeout=5)


def run_stage(name, command, env, results, timeout=180):
    print(f'\n=== {name} ===', flush=True)
    record = {'stage': name, 'status': 'failed'}
    results.append(record)
    started = time.monotonic()
    child = subprocess.Popen(command, cwd=ROOT, env=env, start_new_session=True)
    try:
        code = child.wait(timeout=timeout)
        if code:
            raise RuntimeError(f'{name} failed (exit {code})')
        record['status'] = 'passed'
    finally:
        stop_child(child)
        record['seconds'] = round(time.monotonic() - started, 3)


@contextmanager
def http_server(env, directory, results):
    record = {'stage': 'http-server-readiness', 'status': 'failed'}
    results.append(record)
    log = directory / 'http.log'
    with log.open('wb') as output:
        child = subprocess.Popen([str(ROOT / 'rust/target/debug/slims9')], cwd=ROOT,
                                 env=env, stdout=output, stderr=output, start_new_session=True)
        try:
            deadline = time.monotonic() + 20
            opener = build_opener(ProxyHandler({}))
            while time.monotonic() < deadline:
                if child.poll() is not None:
                    raise RuntimeError('HTTP fixture server exited before readiness')
                match = re.search(r'slims9: bound http://0\.0\.0\.0:(\d+)', log.read_text(errors='replace'))
                if match:
                    base = 'http://127.0.0.1:' + match.group(1)
                    ready = False
                    try:
                        with opener.open(base + '/healthz', timeout=1) as response:
                            ready = response.status == 200
                    except OSError:
                        pass
                    if ready:
                        record['status'] = 'passed'
                        yield base
                        return
                time.sleep(0.05)
            raise RuntimeError('HTTP fixture server readiness timed out')
        finally:
            stop_child(child)


def execute(raw, url, report, php_command=None, php_wasm_modules=None):
    import pymysql  # Optional test dependency; no import needed for safety unit tests.
    env = clean_environment(os.environ)
    env['SLIMS_TEST_MYSQL_URL'] = raw
    summary = {'schema_version': SCHEMA_VERSION, 'source': revision(ROOT),
               'started_at': timestamp(), 'database': url.path[1:], 'production_ready': False,
               'coverage': {'php_cli_isolated': bool(php_command), 'php_http_isolated': bool(php_wasm_modules),
                            'full_php_mysql_integration': False, 'mysql_tls': False}, 'stages': []}
    results = summary['stages']
    # Read-only preflight; bootstrap independently rechecks emptiness later.
    connection = pymysql.connect(host='127.0.0.1', port=url.port or 3306,
                                 user=url.username, password=url.password or '',
                                 database=url.path[1:], connect_timeout=5, read_timeout=5)
    with connection:
        with connection.cursor() as cur:
            cur.execute('SHOW FULL TABLES')
            if cur.fetchone():
                raise RuntimeError('Refusing non-empty database; choose a NEW EMPTY disposable fixture')
            cur.execute('SELECT VERSION()')
            summary['mysql_version'] = cur.fetchone()[0]
    cargo = ['cargo', 'test', '--offline', '--locked', '--manifest-path', 'rust/Cargo.toml', '--quiet']
    try:
        with tempfile.TemporaryDirectory(prefix='slims-member-suite-') as temp:
            directory = Path(temp)  # tempfile creates a private 0700 parent.
            env['SLIMS_TEST_SESSION_DB'] = str(directory / 'sessions.sqlite')
            run_stage('runner-safety-tests', [sys.executable, '-m', 'unittest', 'discover', '-s',
                      'tools', '-p', 'test_member_suite_safety.py'], env, results)
            if php_command:
                run_stage('php-cli-ownership-isolated', [sys.executable, 'tools/test_member_owner.py'],
                          dict(env, PHP_COMMAND=php_command), results)
            if php_wasm_modules:
                run_stage('php-http-ownership-isolated', ['node', 'tools/test_member_owner_http.mjs'],
                          dict(env, PHP_WASM_NODE_MODULES=str(php_wasm_modules)), results)
            for feature in [None, 'member-crypt', 'member-sqlite']:
                args = [] if feature is None else ['--features', feature]
                run_stage('regression-' + (feature or 'default'), cargo + args, env, results)
            run_stage('build-member-sqlite', ['cargo', 'build', '--offline', '--locked', '--manifest-path',
                      'rust/Cargo.toml', '--features', 'member-sqlite'], env, results)
            fixture_env = dict(env, SLIMS_TEST_CREATE_FIXTURE='1')
            run_stage('bootstrap-empty-fixture', [sys.executable, 'tools/setup_member_mysql_test.py'], fixture_env, results)
            run_stage('mysql-fresh-read', cargo + ['--features', 'member-sqlite', '--test',
                      'member_mysql', '--test', 'content_mysql', '--', '--ignored'], env, results)
            server_env = dict(env, SLIMS_ADDR='0.0.0.0:0', SLIMS_ROOT=str(ROOT), SLIMS_DB=raw,
                              SLIMS_MEMBER_EXPERIMENTAL='1', SLIMS_MEMBER_INSECURE_COOKIE='1',
                              SLIMS_SESSION_DB=str(directory / 'http-sessions.sqlite'))
            # Deliberately NO SQL-write flags in the live HTTP server.
            with http_server(server_env, directory, results) as base:
                run_stage('member-http-writes-off', [sys.executable, 'tools/test_member_http.py', '--url', base],
                          dict(env, SLIMS_TEST_HTTP_ALLOW='1'), results)
                run_stage('opac-live-gate', [sys.executable, 'tools/gate.py', '--url', base, '--backend', 'mysql'], env, results)
            run_stage('mysql-destructive-writes-sqlite', cargo + ['--features', 'member-sqlite', '--test',
                      'member_mysql_writes', '--', '--ignored'], dict(env, SLIMS_TEST_ALLOW_RESET='1'), results)
        summary['status'] = 'passed'
        print('\nPASS: complete disposable member validation; NOT production acceptance', flush=True)
    finally:
        summary.setdefault('status', 'failed')
        summary['finished_at'] = timestamp()
        summary['source_at_finish'] = revision(ROOT)
        if report:
            report.write_text(json.dumps(summary, indent=2) + '\n')
    return summary


def interrupted(_signum, _frame):
    raise KeyboardInterrupt


def main():
    signal.signal(signal.SIGTERM, interrupted)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report', type=Path, help='Optional credential-free JSON results')
    parser.add_argument('--php-command', help='Optional native PHP or PHP-WASM CLI command for isolated gate/lint tests')
    parser.add_argument('--php-wasm-modules', type=Path, help='Optional absolute external node_modules for isolated PHP HTTP tests')
    args = parser.parse_args()
    try:
        if args.report:
            args.report.write_text(json.dumps({'status': 'failed', 'production_ready': False,
                                              'stages': [], 'phase': 'preflight'}) + '\n')
        raw = os.environ.get('SLIMS_TEST_MYSQL_URL', '')
        url = validate_target(raw, os.environ.get('SLIMS_TEST_SUITE_ALLOW'))
        if args.php_wasm_modules and (not args.php_wasm_modules.is_absolute() or not args.php_wasm_modules.is_dir()):
            raise ValueError('--php-wasm-modules must be an existing absolute external node_modules directory')
        execute(raw, url, args.report, args.php_command, args.php_wasm_modules)
    except (ValueError, RuntimeError, OSError, subprocess.TimeoutExpired, ImportError) as error:
        print(f'FAIL: {error}', file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print('FAIL: interrupted; fixture processes stopped', file=sys.stderr)
        return 130
    except Exception as error:
        # Optional DB driver errors must not echo connection credentials.
        print(f'FAIL: validation aborted ({type(error).__name__})', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
