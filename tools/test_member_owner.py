#!/usr/bin/env python3
"""Database-free PHP cutover gate tests. PHP_COMMAND defaults to native php.

Example: PHP_COMMAND='php' python3 tools/test_member_owner.py
A PHP CLI-compatible WASM command can be supplied for isolated gate tests.
This does not test a running SLiMS installation or FPM/proxy configuration.
"""
import json
import os
from pathlib import Path
import shlex
import subprocess
import unittest

ROOT = Path(__file__).resolve().parent.parent
PHP = shlex.split(os.environ.get('PHP_COMMAND', 'php'))


def run(code):
    result = subprocess.run(PHP + ['-r', code], cwd=ROOT, text=True,
                            capture_output=True, timeout=30)
    if result.returncode:
        raise AssertionError(result.stderr + result.stdout)
    return result.stdout


class MemberOwnerGate(unittest.TestCase):
    def test_default_and_explicit_php_allow_legacy(self):
        for owner in [None, '', 'php']:
            setting = "putenv('SLIMS_MEMBER_OWNER');" if owner is None else f"putenv('SLIMS_MEMBER_OWNER={owner}');"
            out = run(setting + "require 'lib/member_owner_guard.inc.php'; slims_require_php_member_owner(); echo 'allowed';")
            self.assertEqual(out, 'allowed')

    def test_rust_and_invalid_owner_fail_closed_before_following_code(self):
        for owner in ['rust', 'typo', 'RUST']:
            out = run(f"putenv('SLIMS_MEMBER_OWNER={owner}');" +
                      "register_shutdown_function(function(){echo '\nSTATUS='.http_response_code();});" +
                      "require 'lib/member_owner_guard.inc.php'; slims_require_php_member_owner(); echo 'UNSAFE';")
            self.assertIn('STATUS=503', out)
            self.assertNotIn('UNSAFE', out)

    def test_index_member_routes_stop_before_database_bootstrap(self):
        for route in ['member', 'download_current_loan', 'download_loan_history', 'download_member_loan_report']:
            for method in ['GET', 'POST', 'HEAD']:
                code = "putenv('SLIMS_MEMBER_OWNER=rust'); $_GET['p']=" + json.dumps(route) + ";"
                code += "$_SERVER['REQUEST_METHOD']=" + json.dumps(method) + "; require 'index.php'; echo 'UNSAFE';"
                out = run(code)
                self.assertIn('PHP member operations are disabled', out)
                self.assertNotIn('UNSAFE', out)

    def test_direct_legacy_content_includes_stop(self):
        for name in ['member', 'download_current_loan', 'download_loan_history', 'download_member_loan_report']:
            out = run("putenv('SLIMS_MEMBER_OWNER=rust'); define('INDEX_AUTH', 1); require " +
                      json.dumps(f'lib/contents/{name}.inc.php') + "; echo 'UNSAFE';")
            self.assertIn('PHP member operations are disabled', out)
            self.assertNotIn('UNSAFE', out)

    def test_validator_guards_custom_public_member_provider_but_not_admin(self):
        setup = r"""
            require 'lib/Auth/Methods/Contract.php';
            require 'lib/Auth/Validator.php';
            class TestMemberProvider extends \SLiMS\Auth\Methods\Contract {
                public function member() { echo 'PROVIDER_CALLED'; return $this; }
                protected function memberAuthenticate() { return $this; }
                protected function adminAuthenticate() { $this->setData(['uid'=>7]); return $this; }
            }
        """
        for mode in ['rust', 'typo']:
            out = run(f"putenv('SLIMS_MEMBER_OWNER={mode}');" + setup +
                      r"\SLiMS\Auth\Validator::use(TestMemberProvider::class)->process('MeMbEr'); echo 'UNSAFE';")
            self.assertIn('PHP member operations are disabled', out)
            self.assertNotIn('PROVIDER_CALLED', out)
            self.assertNotIn('UNSAFE', out)
        out = run("putenv('SLIMS_MEMBER_OWNER=rust');" + setup +
                  r"if (!\SLiMS\Auth\Validator::use(TestMemberProvider::class)->process('admin')) exit(1); echo $_SESSION['uid'];")
        self.assertEqual(out, '7')
        out = run("putenv('SLIMS_MEMBER_OWNER=php');" + setup +
                  r"\SLiMS\Auth\Validator::use(TestMemberProvider::class)->process('member');")
        self.assertEqual(out, 'PROVIDER_CALLED')

    def test_direct_auth_and_session_generation_cannot_bypass_gate(self):
        setup = r"""
            require 'lib/Auth/Methods/Contract.php';
            require 'lib/Auth/Methods/Native.php';
            require 'lib/Auth/Methods/Ldap.php';
        """
        calls = [
            r"(new \SLiMS\Auth\Methods\Native)->member();",
            r"(new \SLiMS\Auth\Methods\Native)->setMemberData();",
            r"(new \SLiMS\Auth\Methods\Ldap)->MEMBER();",
            r"$p = new \SLiMS\Auth\Methods\Native; $p->setType(2); $p->generateSession();",
            r"$p = new \SLiMS\Auth\Methods\Native; $p->setData(['mid'=>'M001']); $p->generateSession();",
        ]
        for call in calls:
            out = run("putenv('SLIMS_MEMBER_OWNER=rust');" + setup + call + "echo 'UNSAFE';")
            self.assertIn('PHP member operations are disabled', out)
            self.assertNotIn('UNSAFE', out)
        for call in ["$p->nativeLogin();", "$p->valid(null);"]:
            out = run("putenv('SLIMS_MEMBER_OWNER=rust'); define('INDEX_AUTH',1); require 'lib/member_logon.inc.php';" +
                      "$p = new member_logon('member','password');" + call + "echo 'UNSAFE';")
            self.assertIn('PHP member operations are disabled', out)
            self.assertNotIn('UNSAFE', out)

    def test_old_php_sessions_are_not_recognized_during_rust_ownership(self):
        setup = "define('INDEX_AUTH',1); require 'lib/utility.inc.php'; $_SESSION=['mid'=>'M001','m_name'=>'Member'];"
        for mode, expected in [('php', 'yes'), ('rust', 'no'), ('typo', 'no')]:
            out = run(f"putenv('SLIMS_MEMBER_OWNER={mode}');" + setup +
                      "echo utility::isMemberLogin() ? 'yes' : 'no'; if ($_SESSION['mid'] !== 'M001') exit(1);")
            self.assertEqual(out, expected)

    def test_changed_php_files_parse(self):
        for path in ['index.php', 'lib/member_owner_guard.inc.php', 'lib/Auth/Methods/Native.php',
                     'admin/modules/circulation/circulation_action.php', 'lib/Auth/Validator.php',
                     'lib/Auth/Methods/Contract.php', 'lib/Auth/Methods/Ldap.php',
                     'lib/member_logon.inc.php', 'lib/utility.inc.php'] + [
                         f'lib/contents/{name}.inc.php' for name in
                         ['member', 'download_current_loan', 'download_loan_history', 'download_member_loan_report']]:
            result = subprocess.run(PHP + ['-l', path], cwd=ROOT, text=True,
                                    capture_output=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
