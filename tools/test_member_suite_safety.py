#!/usr/bin/env python3
"""Database-free tests for the destructive validation runner's safety boundary."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest

import test_member_suite as suite


class SuiteSafety(unittest.TestCase):
    def test_requires_explicit_consent_and_literal_loopback_disposable_database(self):
        good = 'mysql://fixture:secret@127.0.0.1:3337/slims_test_suite'
        self.assertEqual(suite.validate_target(good, '1').port, 3337)
        for consent in [None, '', '0', 'true']:
            with self.assertRaises(ValueError):
                suite.validate_target(good, consent)
        for raw in [good.replace('127.0.0.1', host) for host in ['localhost', 'example.org', '127.1', '0.0.0.0']]:
            with self.assertRaises(ValueError):
                suite.validate_target(raw, '1')
        for raw in [good.replace('/slims_test_suite', '/senayan'), good + '?x=1', good + '#x',
                    good.replace('3337', '0'), good.replace('3337', '65536'),
                    good.replace('secret', '%73ecret'), good + '/extra', '\n' + good]:
            with self.assertRaises(ValueError) as raised:
                suite.validate_target(raw, '1')
            self.assertNotIn('secret@', str(raised.exception))

    def test_inherited_write_and_proxy_configuration_is_not_used(self):
        env = suite.clean_environment({'PATH': '/fixture', 'SLIMS_MEMBER_TEST_WRITES': '1',
                                      'SLIMS_MEMBER_TEST_RESERVATIONS': '1', 'SLIMS_SESSION_DB': '/wrong',
                                      'SLIMS_DB': 'mysql://wrong', 'SLIMS_TEST_ALLOW_RESET': '1',
                                      'https_proxy': 'http://external', 'HTTP_PROXY': 'http://external'})
        self.assertFalse(any(k.startswith('SLIMS_') for k in env))
        self.assertNotIn('HTTP_PROXY', env)
        self.assertNotIn('https_proxy', env)
        self.assertEqual(env['NO_PROXY'], '127.0.0.1,localhost')
        self.assertEqual(env['PATH'], '/fixture')

    def test_failure_and_timeout_never_record_success(self):
        for command, timeout in [([sys.executable, '-c', 'raise SystemExit(7)'], 5),
                                 ([sys.executable, '-c', 'import time; time.sleep(60)'], 0.05)]:
            records = []
            with self.assertRaises((RuntimeError, subprocess.TimeoutExpired)):
                suite.run_stage('expected-failure', command, os.environ.copy(), records, timeout=timeout)
            self.assertEqual(records[0]['status'], 'failed')

    def test_stops_server_process_and_records_successful_stage(self):
        child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(60)'], start_new_session=True)
        suite.stop_child(child)
        self.assertIsNotNone(child.poll())
        records = []
        suite.run_stage('expected-pass', [sys.executable, '-c', 'pass'], os.environ.copy(), records)
        self.assertEqual(records[0]['status'], 'passed')

    def test_sigterm_cleans_up_running_stage_child(self):
        with tempfile.TemporaryDirectory() as temp:
            pidfile = Path(temp) / 'child.pid'
            worker = "import os,time; open(" + repr(str(pidfile)) + ", 'w').write(str(os.getpid())); time.sleep(60)"
            code = ("import sys; sys.path.insert(0, 'tools'); import test_member_suite as s; "
                    "s.execute=lambda *a: s.run_stage('interrupt-test', [sys.executable, '-c', " + repr(worker) +
                    "], s.clean_environment(__import__('os').environ), []); sys.exit(s.main())")
            env = dict(os.environ, SLIMS_TEST_SUITE_ALLOW='1',
                       SLIMS_TEST_MYSQL_URL='mysql://fixture:secret@127.0.0.1/slims_test_interrupt')
            runner = subprocess.Popen([sys.executable, '-c', code], cwd=suite.ROOT,
                                      env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                      start_new_session=True)
            try:
                deadline = time.monotonic() + 5
                while not pidfile.exists() and time.monotonic() < deadline:
                    time.sleep(0.01)
                self.assertTrue(pidfile.exists())
                child_pid = int(pidfile.read_text())
                runner.terminate()
                self.assertEqual(runner.wait(timeout=5), 130)
                with self.assertRaises(ProcessLookupError):
                    os.kill(child_pid, 0)
            finally:
                suite.stop_child(runner)

    def test_refused_preflight_replaces_stale_passed_report(self):
        with tempfile.TemporaryDirectory() as temp:
            report = Path(temp) / 'report.json'
            report.write_text('{"status":"passed"}')
            env = {k: v for k, v in os.environ.items() if k != 'SLIMS_TEST_SUITE_ALLOW'}
            result = subprocess.run([sys.executable, str(Path(suite.__file__)), '--report', str(report)],
                                    env=env, capture_output=True, text=True, timeout=5)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('"status": "failed"', report.read_text())
            self.assertNotIn('"status":"passed"', report.read_text())


if __name__ == '__main__':
    unittest.main()
