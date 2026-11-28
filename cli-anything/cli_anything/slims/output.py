"""Human + JSON rendering for the SLiMS harness.

`--json` prints the backend envelope verbatim so agents can parse it;
the default mode prints something a librarian can read.
"""

from __future__ import annotations

import json
import sys
from typing import Any, Dict, Iterable, List, Mapping, Optional, Sequence, TextIO

#: Preferred column order for the tables agents ask for most often.
PREFERRED_COLUMNS: Dict[str, Sequence[str]] = {
    "items": (
        "item_code",
        "loan_id",
        "biblio_id",
        "title",
        "authors",
        "publish_year",
        "call_number",
        "member_id",
        "due_date",
        "overdue_days",
        "item_count",
        "available_count",
        "status",
    ),
    "authors": ("author_name", "level"),
    "topics": ("topic", "topic_type"),
    "loans": ("loan_id", "item_code", "title", "due_date", "overdue_days"),
    "checks": ("name", "ok", "detail"),
}

MAX_CELL_WIDTH = 42
MAX_COLUMNS = 8


def dump_json(payload: Mapping[str, Any], stream: Optional[TextIO] = None) -> None:
    stream = stream or sys.stdout
    json.dump(payload, stream, indent=2, ensure_ascii=False, sort_keys=False)
    stream.write("\n")


def render(payload: Mapping[str, Any], stream: Optional[TextIO] = None) -> None:
    """Render an envelope for humans."""

    stream = stream or sys.stdout

    def write(line: str = "") -> None:
        stream.write(line + "\n")

    data = payload.get("data") or {}
    command = payload.get("command") or ""

    write(f"SLiMS · {command}")

    meta = payload.get("meta") or {}
    if meta.get("transport"):
        write(f"  transport : {meta['transport']}")

    if isinstance(data, Mapping):
        if data.get("dry_run"):
            write("  DRY RUN   : nothing written. Re-run with --commit to execute.")
        if data.get("committed"):
            write("  COMMITTED : written to the database and recorded in system_log.")
        if data.get("backup"):
            write(f"  backup    : {data['backup']}")

    write()
    _render_node(data, write, indent=0)
    write()


def _render_node(node: Any, write, indent: int = 0) -> None:
    if not isinstance(node, Mapping) or not node:
        return

    pad = "  " * (indent + 1)

    for key, value in node.items():
        if key in ("dry_run", "committed", "backup"):
            continue

        label = key.replace("_", " ")

        if isinstance(value, list):
            if not value:
                continue
            if isinstance(value[0], Mapping):
                write(f"{pad}{label} ({len(value)})")
                write()
                for line in format_table(value, preferred=PREFERRED_COLUMNS.get(key), indent=pad + "  "):
                    write(line)
                write()
            else:
                write(f"{pad}{label}: " + ", ".join(_stringify(item) for item in value))
        elif isinstance(value, Mapping):
            write(f"{pad}{label}")
            _render_node(value, write, indent + 1)
        else:
            write(f"{pad}{label:<22}: {_stringify(value)}")


def format_table(
    rows: Iterable[Mapping[str, Any]],
    preferred: Optional[Sequence[str]] = None,
    indent: str = "",
) -> List[str]:
    rows = [dict(row) for row in rows]
    if not rows:
        return [indent + "(empty)"]

    keys: List[str] = []
    available = {key for row in rows for key in row}

    for key in preferred or ():
        if key in available and key not in keys:
            keys.append(key)

    for key in rows[0]:
        if len(keys) >= MAX_COLUMNS:
            break
        if key not in keys and _is_scalar_column(rows, key):
            keys.append(key)

    cells = [[_truncate(_stringify(row.get(key, ""))) for key in keys] for row in rows]
    header = [key.replace("_", " ").upper() for key in keys]

    widths = [max(len(header[index]), max((len(row[index]) for row in cells), default=0)) for index in range(len(keys))]

    def line(values: Sequence[str]) -> str:
        return indent + "  ".join(value.ljust(widths[index]) for index, value in enumerate(values)).rstrip()

    output = [line(header), indent + "  ".join("-" * width for width in widths)]
    output.extend(line(row) for row in cells)

    return output


def _is_scalar_column(rows: Sequence[Mapping[str, Any]], key: str) -> bool:
    for row in rows:
        value = row.get(key)
        if isinstance(value, (dict, list)):
            return False
    return True


def _truncate(value: str) -> str:
    if len(value) <= MAX_CELL_WIDTH:
        return value
    return value[: MAX_CELL_WIDTH - 1] + "…"


def _stringify(value: Any) -> str:
    if value is None:
        return "-"
    if isinstance(value, bool):
        return "yes" if value else "no"
    if isinstance(value, float) and value.is_integer():
        return str(int(value))
    if isinstance(value, (dict, list)):
        return json.dumps(value, ensure_ascii=False)
    return str(value)
