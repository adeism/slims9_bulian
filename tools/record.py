#!/usr/bin/env python3
"""Record REST API responses from a live SLiMS PHP instance.

    python3 tools/record.py --base-url http://127.0.0.1:8881 \
        --out rust/fixtures/behavior/recorded.json

This is the `record` half of the rust-anything workflow, applied to PHP (which
rust-anything today can record and replay but not bridge into unit ports). The
output has the same shape as `cases.json`, so it can be replayed with
`tools/gate.py` against the Rust build once the two run against the same
database.

The original is never modified. Run this against a test database: every
request is a normal GET, but SLiMS may write cache files under `files/cache`.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import urllib.error
import urllib.request
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_OUT = REPO_ROOT / "rust" / "fixtures" / "behavior" / "recorded.json"

# name, path (may contain {today})
ENDPOINTS = [
    ("api-home", "/api/"),
    ("api-biblio-popular", "/api/biblio/popular"),
    ("api-biblio-latest", "/api/biblio/latest"),
    ("api-biblio-gmd-text", "/api/biblio/gmd/Text"),
    ("api-biblio-coll-type-reference", "/api/biblio/coll_type/Reference"),
    ("api-subject-popular", "/api/subject/popular"),
    ("api-subject-latest", "/api/subject/latest"),
    ("api-member-top", "/api/member/top"),
    ("api-biblio-total-all", "/api/biblio/total/all"),
    ("api-item-total-all", "/api/item/total/all"),
    ("api-item-total-lent", "/api/item/total/lent"),
    ("api-item-total-available", "/api/item/total/available"),
    ("api-loan-summary", "/api/loan/summary"),
    ("api-loan-getdate", "/api/loan/getdate/{today}"),
    ("api-loan-summary-date", "/api/loan/summary/{today}"),
]


def fetch(url: str, timeout: float = 30.0):
    request = urllib.request.Request(url, method="GET")
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode("utf-8", "replace")
    except Exception as error:  # connection refused, timeout, ...
        return 0, f"<request failed: {error}>"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", required=True, help="e.g. http://127.0.0.1:8881")
    parser.add_argument("--out", default=str(DEFAULT_OUT))
    parser.add_argument(
        "--expect-json",
        action="store_true",
        help="store parsed JSON under expect.json as well as the raw body",
    )
    args = parser.parse_args()

    today = dt.date.today().isoformat()
    cases = []
    for name, path in ENDPOINTS:
        resolved = path.replace("{today}", today)
        status, body = fetch(args.base_url.rstrip("/") + resolved)
        case = {
            "name": name,
            "method": "GET",
            "path": path,
            "status": status,
            "body": body,
        }
        if args.expect_json:
            try:
                case["expect"] = {"status": status, "json": json.loads(body)}
            except json.JSONDecodeError:
                case["expect"] = {"status": status}
        cases.append(case)

    document = {
        "suite": "SLiMS 9 Bulian API recording",
        "recorded_at": dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"),
        "base_url": args.base_url,
        "notes": "Recorded with tools/record.py. Replay with tools/gate.py --cases <file>.",
        "cases": cases,
    }
    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(document, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"recorded {len(cases)} endpoints into {out}")
    for case in cases:
        print(f"  {case['status']:>3}  {case['name']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
