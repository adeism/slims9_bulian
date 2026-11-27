#!/usr/bin/env python3
"""Replay the behavior gate against a running slims9 build.

    python3 tools/gate.py --url http://127.0.0.1:8080

The cases live in rust/fixtures/behavior/cases.json. Each case is a request
plus what the endpoint must answer. JSON expectations are compared
semantically (object key order does not matter), `contains` is a substring
check for HTML pages, and `shape` checks the JSON types for endpoints whose
values depend on the date.

Exit code is 0 only when every case passes. This is the same check
`cargo test --test behavior` runs in-process, made usable against any build
(debug, release, or a future pure-Rust cutover candidate).
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import sys
import urllib.error
import urllib.request
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_CASES = REPO_ROOT / "rust" / "fixtures" / "behavior" / "cases.json"
DEFAULT_URL = "http://127.0.0.1:8080"


def apply_template(path: str) -> str:
    today = dt.date.today()
    return path.replace("{today}", today.isoformat()).replace("{year}", str(today.year))


def type_name(value):
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "boolean"
    if isinstance(value, (int, float)):
        return "number"
    if isinstance(value, str):
        return "string"
    if isinstance(value, list):
        return "array"
    if isinstance(value, dict):
        return "object"
    return type(value).__name__


def check_shape(value, shape, path="$"):
    if isinstance(shape, str):
        expected = shape
        actual = type_name(value)
        if expected == "any" or expected == actual:
            return []
        return [f"{path}: expected {expected}, got {actual}"]
    if isinstance(shape, list) and len(shape) == 1:
        if not isinstance(value, list):
            return [f"{path}: expected array, got {type_name(value)}"]
        problems = []
        for index, item in enumerate(value):
            problems.extend(check_shape(item, shape[0], f"{path}[{index}]"))
        return problems
    if isinstance(shape, dict):
        if not isinstance(value, dict):
            return [f"{path}: expected object, got {type_name(value)}"]
        problems = []
        for key, child in shape.items():
            if key not in value:
                problems.append(f"{path}: missing key {key!r}")
            else:
                problems.extend(check_shape(value[key], child, f"{path}.{key}"))
        return problems
    return []


def fetch(url: str, method: str = "GET", timeout: float = 20.0):
    request = urllib.request.Request(url, method=method)
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode("utf-8", "replace")


def detect_backend(base_url: str) -> str | None:
    """Ask /healthz which backend this build is using (memory or mysql)."""
    try:
        _, body = fetch(base_url.rstrip("/") + "/healthz", timeout=5)
        payload = json.loads(body)
    except Exception:
        return None
    name = str(payload.get("backend", ""))
    if name.startswith("memory"):
        return "memory"
    return name or None


def run_case(case, base_url):
    name = case.get("name", "<unnamed>")
    method = case.get("method", "GET")
    raw_path = case.get("path") or case.get("path_template") or "/"
    path = apply_template(raw_path)
    url = base_url.rstrip("/") + path
    status, body = fetch(url, method)
    expect = case.get("expect", {})
    problems = []

    expected_status = int(expect.get("status", 200))
    if status != expected_status:
        problems.append(f"status: expected {expected_status}, got {status}")

    if "json" in expect:
        try:
            actual = json.loads(body)
        except json.JSONDecodeError as error:
            problems.append(f"response is not JSON: {error}")
        else:
            if actual != expect["json"]:
                problems.append(
                    "json mismatch\n"
                    f"  expected: {json.dumps(expect['json'], ensure_ascii=False)}\n"
                    f"  actual:   {json.dumps(actual, ensure_ascii=False)}"
                )

    if "shape" in expect:
        try:
            actual = json.loads(body)
        except json.JSONDecodeError as error:
            problems.append(f"response is not JSON: {error}")
        else:
            problems.extend(check_shape(actual, expect["shape"]))

    for needle in expect.get("contains", []):
        if needle not in body:
            problems.append(f"body does not contain {needle!r}")

    return name, method, path, problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", default=DEFAULT_URL, help="base URL of the build under test")
    parser.add_argument("--cases", default=str(DEFAULT_CASES), help="path to cases.json")
    parser.add_argument("--verbose", action="store_true", help="print the body of every failure")
    parser.add_argument("--backend", help="force memory/mysql instead of asking /healthz")
    args = parser.parse_args()

    document = json.loads(Path(args.cases).read_text(encoding="utf-8"))
    cases = document.get("cases", [])
    if not cases:
        print("no cases to replay", file=sys.stderr)
        return 2

    backend = args.backend or detect_backend(args.url)
    if backend:
        print(f"backend: {backend}")
    print()

    passed = 0
    skipped = 0
    failures = []
    for case in cases:
        allowed = case.get("backends")
        if allowed and backend not in allowed:
            skipped += 1
            print(f"SKIP  {case.get('name', '<unnamed>')} ({backend} backend)")
            continue
        name, method, path, problems = run_case(case, args.url)
        if problems:
            failures.append((name, method, path, problems))
            print(f"FAIL  {name}")
            for problem in problems:
                print(f"      {problem}")
        else:
            passed += 1
            print(f"PASS  {name}")

    print()
    print(f"{passed}/{len(cases) - skipped} cases passed against {args.url}"
          + (f" ({skipped} skipped)" if skipped else ""))
    if failures:
        print(f"{len(failures)} failed: " + ", ".join(name for name, _, _, _ in failures))
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
