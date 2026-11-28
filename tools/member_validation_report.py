#!/usr/bin/env python3
"""Verify disposable-suite evidence, never production readiness. No network/DB access."""
import argparse
from datetime import datetime, timezone
import json
import math
from pathlib import Path
import re
import subprocess
import sys

SCHEMA_VERSION = 1
REQUIRED_STAGES = (
    'runner-safety-tests', 'regression-default', 'regression-member-crypt',
    'regression-member-sqlite', 'build-member-sqlite', 'bootstrap-empty-fixture',
    'mysql-fresh-read', 'http-server-readiness', 'member-http-writes-off',
    'opac-live-gate', 'mysql-destructive-writes-sqlite',
)
OPTIONAL_STAGES = {'php_cli_isolated': 'php-cli-ownership-isolated',
                   'php_http_isolated': 'php-http-ownership-isolated'}


def revision(root):
    """No remote access; include untracked files in the dirty flag."""
    try:
        sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root,
                                      stderr=subprocess.DEVNULL, text=True).strip()
        dirty = bool(subprocess.check_output(['git', 'status', '--porcelain'], cwd=root,
                                            stderr=subprocess.DEVNULL, text=True).strip())
        return {'commit': sha, 'dirty': dirty}
    except (OSError, subprocess.CalledProcessError):
        return {'commit': None, 'dirty': True}


def timestamp():
    return datetime.now(timezone.utc).isoformat()


def verify(report, expected_commit, max_age_hours=24, now=None):
    """Raise a static, credential-free error on missing, stale or partial evidence."""
    def require(condition, message):
        if not condition:
            raise ValueError(message)
    require(isinstance(report, dict), 'Report must be an object')
    require(report.get('schema_version') == SCHEMA_VERSION, 'Unsupported evidence schema')
    require(report.get('status') == 'passed' and report.get('production_ready') is False,
            'Report must pass without claiming production readiness')
    require(isinstance(expected_commit, str) and re.fullmatch(r'[0-9a-f]{40}', expected_commit),
            'Expected commit must be a full SHA-1')
    source = report.get('source', {})
    require(isinstance(source, dict) and source.get('commit') == expected_commit
            and source.get('dirty') is False, 'Evidence revision differs or working tree was dirty')
    require(report.get('source_at_finish') == source, 'Source changed during validation')
    try:
        start = datetime.fromisoformat(report['started_at'])
        end = datetime.fromisoformat(report['finished_at'])
        current = now or datetime.now(timezone.utc)
        age = (current - end).total_seconds()
        valid = (start.tzinfo is not None and end.tzinfo is not None and start <= end
                 and 0 <= age <= max_age_hours * 3600)
    except (KeyError, TypeError, ValueError, OverflowError):
        valid = False
    require(valid, 'Evidence timestamps missing, invalid, future or stale')
    coverage = report.get('coverage')
    require(isinstance(coverage, dict), 'Coverage missing')
    require(coverage.get('full_php_mysql_integration') is False and coverage.get('mysql_tls') is False,
            'Unsupported coverage claim')
    required = set(REQUIRED_STAGES)
    for field, stage in OPTIONAL_STAGES.items():
        require(type(coverage.get(field)) is bool, 'Optional coverage must be explicit')
        if coverage[field]:
            required.add(stage)
    stages = report.get('stages')
    require(isinstance(stages, list), 'Stages missing')
    seen = []
    for stage in stages:
        require(isinstance(stage, dict), 'Invalid stage record')
        name = stage.get('stage')
        require(isinstance(name, str) and name in required, 'Unknown or unrequested stage')
        require(name not in seen, 'Duplicate stage')
        require(stage.get('status') == 'passed', 'Incomplete or failed stage')
        seen.append(name)
    require(set(seen) == required, 'Required stages missing')
    # Optional checks can precede the regression matrix; all core stages must be ordered.
    require([name for name in seen if name in REQUIRED_STAGES] == list(REQUIRED_STAGES),
            'Core stages out of order')
    return True


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('report', type=Path)
    parser.add_argument('--commit', required=True, help='Expected full tested commit SHA')
    parser.add_argument('--max-age-hours', type=float, default=24)
    args = parser.parse_args()
    try:
        if not math.isfinite(args.max_age_hours) or not 0 < args.max_age_hours <= 168:
            raise ValueError('Age limit must be positive and at most 168 hours')
        if args.report.stat().st_size > 1024 * 1024:
            raise ValueError('Evidence file exceeds 1 MiB')
        report = json.loads(args.report.read_text())
        verify(report, args.commit, args.max_age_hours)
    except (OSError, ValueError, TypeError):
        # Do not echo untrusted report fields, paths or JSON parser fragments.
        print('FAIL: evidence invalid, incomplete, stale or revision mismatch', file=sys.stderr)
        return 1
    print('PASS: complete disposable-suite evidence for expected clean revision; NOT production acceptance')
    return 0


if __name__ == '__main__':
    sys.exit(main())
