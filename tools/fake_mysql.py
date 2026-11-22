#!/usr/bin/env python3
"""A MySQL-compatible server used to exercise the Rust client's SQL and protocol.

    pip install mysql-mimic
    python3 tools/fake_mysql.py --port 3307

It is a protocol double, not a database: mysql-mimic accepts the wire handshake
and COM_QUERY packets, and this module answers the queries the MySQL store
issues. Two modes:

`--dataset` (default when install/*.sql is found)
    Loads `install/senayan.sql` + `install/sampledata.sql` into an in-memory
    SQLite database (the DDL is converted, the INSERTs run as they are), adds
    the same deterministic demo circulation the memory store uses, then
    translates each incoming MySQL query to SQLite with sqlglot and executes it.
    The same behavior recording can then pass against both backends, and a
    mistake in the store's SQL shows up as a wrong row instead of being hidden
    by a canned answer.

`--canned`
    Answers a fixed list of query prefixes with fixed rows. Protocol smoke test
    only: `tools/mysql_roundtrip.sh` uses it.

`tools/mysql_roundtrip.sh` starts this server, points `slims9` at it, and
checks the handshake, mysql_native_password, COM_QUERY, column definitions,
text-protocol rows and hydration end to end.

This exists because the sandbox that produced the port had no MySQL server.
"""

from __future__ import annotations

import argparse
import asyncio
import datetime as dt
import pathlib
import re
import sqlite3

from mysql_mimic import (
    IdentityProvider,
    MysqlServer,
    NativePasswordAuthPlugin,
    Session,
    User,
)

try:
    import sqlglot
except ImportError:  # pragma: no cover - mysql-mimic depends on sqlglot
    sqlglot = None

PASSWORD = "secret"

# --- install/*.sql -> SQLite -------------------------------------------------

MYSQL_PROPS = [
    (r"\bENGINE\s*=\s*\w+", ""),
    (r"\bDEFAULT\s+CHARSET\s*=\s*\w+", ""),
    (r"\bCHARSET\s*=\s*\w+", ""),
    (r"\bCHARACTER SET \w+", ""),
    (r"\bCOLLATE\s*=?\s*[\w`]+", ""),
    (r"\bCOLLATE\s+'[^']*'", ""),
    (r"\bROW_FORMAT\s*=\s*\w+", ""),
    (r"\bAUTO_INCREMENT\s*=\s*\d+", ""),
    (r"\bCOMMENT\s+'([^']|'')*'", ""),
    (r"\bON UPDATE (CURRENT_TIMESTAMP|timestamp\(\))", ""),
    (r"\bUNSIGNED\b", ""),
    (r"\bZEROFILL\b", ""),
]
INT_TYPES = {"int", "integer", "tinyint", "smallint", "mediumint", "bigint", "bit", "bool", "boolean"}
REAL_TYPES = {"decimal", "float", "double", "real", "numeric"}


def _clean(text: str) -> str:
    for pattern, replacement in MYSQL_PROPS:
        text = re.sub(pattern, replacement, text, flags=re.I)
    return re.sub(r"\s+", " ", text).strip()


def _split_top_level(body: str) -> list[str]:
    parts, depth, buf = [], 0, []
    for ch in body:
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        if ch == "," and depth == 0:
            parts.append("".join(buf))
            buf = []
        else:
            buf.append(ch)
    if "".join(buf).strip():
        parts.append("".join(buf))
    return parts


def _column(part: str) -> str | None:
    match = re.match(r"`([^`]+)`\s+(.*)$", part.strip())
    if not match:
        return None
    name, rest = match.group(1), match.group(2)
    auto = bool(re.search(r"\bauto_increment\b", rest, re.I))
    rest = re.sub(r"\bauto_increment\b", " ", rest, flags=re.I)
    type_match = re.match(r"\s*(\w+)\s*(\([^)]*\))?", rest)
    base = type_match.group(1).lower() if type_match else "text"
    rest = " " + rest[type_match.end():].strip() if type_match else ""
    if base in INT_TYPES:
        sql_type = "INTEGER"
    elif base in REAL_TYPES:
        sql_type = "REAL"
    else:
        sql_type = "TEXT"
    if auto:
        return f'"{name}" INTEGER PRIMARY KEY AUTOINCREMENT'
    if "not null" in rest.lower() and "default" not in rest.lower():
        # MySQL fills an implicit default for NOT NULL columns the dump omits
        # (ENUM gets '', ints get 0); SQLite refuses the row instead.
        rest = re.sub(r"\bNOT NULL\b", "", rest, flags=re.I)
    return f'"{name}" {sql_type}{rest}'.rstrip()


def convert_create(stmt: str) -> str:
    """MySQL CREATE TABLE -> SQLite CREATE TABLE (types, keys, collations)."""
    start = stmt.index("(")
    depth, end = 0, start
    for index in range(start, len(stmt)):
        if stmt[index] == "(":
            depth += 1
        elif stmt[index] == ")":
            depth -= 1
            if depth == 0:
                end = index
                break
    columns = []
    for part in _split_top_level(stmt[start + 1:end]):
        lowered = _clean(part).lower()
        if lowered.startswith(
            ("primary key", "unique", "key ", "index", "constraint", "fulltext", "foreign key")
        ):
            continue
        column = _column(_clean(part))
        if column:
            columns.append(column)
    return _clean(stmt[:start]) + "(\n  " + ",\n  ".join(columns) + "\n)"


def statements(path: pathlib.Path) -> list[str]:
    out, buf = [], []
    for line in path.read_text(errors="replace").splitlines():
        stripped = line.strip()
        if stripped.startswith("--") or stripped.startswith("/*") or stripped.startswith("#"):
            continue
        buf.append(line)
        if stripped.endswith(";"):
            out.append("\n".join(buf))
            buf = []
    if "".join(buf).strip():
        out.append("\n".join(buf))
    return out


def load_dataset(root: pathlib.Path) -> sqlite3.Connection:
    db = sqlite3.connect(":memory:", check_same_thread=False)
    for name in ("senayan.sql", "sampledata.sql"):
        path = root / "install" / name
        if not path.exists():
            raise SystemExit(f"missing {path}; run from the repository or pass --root")
        for stmt in statements(path):
            text = stmt.strip()
            if not text:
                continue
            lowered = text.lower()
            try:
                if lowered.startswith("create table"):
                    db.execute(convert_create(text))
                elif lowered.startswith("insert"):
                    insert = re.sub(r"^insert\s+ignore\s+", "INSERT OR IGNORE ", text, flags=re.I)
                    insert = unescape_mysql(insert)
                    insert = re.sub(r"\bNOW\(\)", "CURRENT_TIMESTAMP", insert, flags=re.I)
                    db.execute(insert)
            except sqlite3.Error:
                # A few installer tables use constructs SQLite cannot express
                # (fulltext indexes, set types). None of them are read by the port.
                continue
    return db


def seed_demo_circulation(db: sqlite3.Connection) -> None:
    """The same deterministic loans the memory store adds when `loan` is empty.

    Mirrors `MemoryStore::seed_demo_circulation` in rust/src/store/memory.rs so
    that one behavior recording can be replayed against either backend. Change
    both together, or the gate will show the difference.
    """
    if db.execute("SELECT COUNT(*) FROM loan").fetchone()[0]:
        return
    items = [
        row[0]
        for row in db.execute(
            "SELECT item_code FROM item ORDER BY biblio_id, item_id LIMIT 10"
        ).fetchall()
    ]
    if not items:
        return
    members = [
        ("M001", "Siti Rahma", "siti.rahma@example.org"),
        ("M002", "Budi Santoso", "budi.santoso@example.org"),
        ("M003", "Dewi Lestari", "dewi.lestari@example.org"),
        ("M004", "Agus Wijaya", "agus.wijaya@example.org"),
    ]
    for member_id, name, email in members:
        db.execute(
            "INSERT INTO member (member_id, member_name, gender, member_type_id, member_email, "
            "expire_date, is_pending, input_date, last_update) "
            "VALUES (?, ?, 0, 1, ?, '2030-01-01', 0, '2007-01-01', '2007-01-01')",
            (member_id, name, email),
        )

    today = dt.date.today()

    def day(offset: int) -> str:
        return (today + dt.timedelta(days=offset)).isoformat()

    loans = [
        (items[0], "M001", -2, 12, False, 0),
        (items[0], "M002", -20, 27, True, 0),
        (items[0], "M003", -40, 47, True, 0),
        (items[1], "M002", -3, 10, False, 0),
        (items[1], "M004", -25, 32, True, 0),
        (items[2], "M003", -4, 11, False, 0),
        (items[3], "M004", -5, 12, False, 0),
        (items[4], "M001", -6, 13, True, 0),
        (items[5], "M002", -9, -2, False, 0),  # overdue
        (items[6], "M003", -7, 14, False, 1),  # renewed
        (items[7], "M004", -12, -1, False, 0),  # overdue
        (items[8], "M001", -15, 22, True, 0),
    ]
    for index, (item_code, member_id, ago, due, returned, renewed) in enumerate(loans, start=1):
        db.execute(
            "INSERT INTO loan (loan_id, item_code, member_id, loan_date, due_date, renewed, "
            "is_lent, is_return, return_date) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)",
            (
                index,
                item_code,
                member_id,
                day(ago),
                day(due),
                renewed,
                1 if returned else 0,
                day(ago + 5) if returned else None,
            ),
        )


MYSQL_ESCAPES = {
    "0": "\0",
    "b": "\b",
    "n": "\n",
    "r": "\r",
    "t": "\t",
    "Z": "\x1a",
    "\\": "\\",
    "'": "''",
    '"': '"',
}


def unescape_mysql(text: str) -> str:
    """Turn MySQL string literals into SQLite string literals.

    MySQL reads `\\r\\n` inside a quoted literal as CR LF and escapes a quote as
    `\\'`; SQLite has no backslash escapes and doubles quotes instead. The Rust
    seed parser does the same for the memory store, so both backends see the
    same characters.
    """
    out, index, length = [], 0, len(text)
    while index < length:
        char = text[index]
        if char != "'":
            out.append(char)
            index += 1
            continue
        out.append(char)
        index += 1
        while index < length:
            char = text[index]
            if char == "\\" and index + 1 < length:
                out.append(MYSQL_ESCAPES.get(text[index + 1], text[index + 1]))
                index += 2
            elif char == "'":
                if index + 1 < length and text[index + 1] == "'":
                    out.append("''")
                    index += 2
                    continue
                out.append(char)
                index += 1
                break
            else:
                out.append(char)
                index += 1
    return "".join(out)


def to_sqlite(sql: str) -> str:
    if sqlglot is None:
        raise RuntimeError("sqlglot is required (pip install mysql-mimic)")
    # SQLite has no YEAR(); MySQL's YEAR() on a DATE/DATETIME is the same as
    # strftime('%Y', ...). Done before transpiling so the CAST survives intact.
    sql = re.sub(r"\bYEAR\(([^()]*)\)", r"CAST(STRFTIME('%Y', \1) AS INTEGER)", sql, flags=re.I)
    statement = sqlglot.transpile(sql, read="mysql", write="sqlite")[0]
    statement = re.sub(r"\bNOW\(\)", "CURRENT_TIMESTAMP", statement, flags=re.I)
    # MySQL compares a number with a quoted number numerically ('2026' = 2026 is
    # true); SQLite compares by type and would return no rows.
    statement = re.sub(r"=\s*'(\d+)'", r"= \1", statement)
    return statement


class Identity(IdentityProvider):
    async def get_user(self, username):
        return User(
            name=username,
            auth_string=NativePasswordAuthPlugin.create_auth_string(PASSWORD),
            auth_plugin=NativePasswordAuthPlugin.name,
        )


class FakeSession(Session):
    def __init__(self, db: sqlite3.Connection | None = None, verbose: bool = False):
        super().__init__()
        self.db = db
        self.verbose = verbose

    async def handle_query(self, sql, attrs):
        # mysql-mimic's default pipeline executes statements with sqlglot's own
        # executor and needs a full schema for every column. This server answers
        # from SQLite instead, so skip the middlewares and go straight to query.
        return await self.query(None, sql, attrs)

    async def query(self, expression, sql, attrs):
        normalized = " ".join(sql.split())
        if self.verbose:
            print("QUERY", normalized[:200], flush=True)
        if self.db is None:
            return canned_rows(normalized)
        statement = to_sqlite(normalized)
        if self.verbose:
            print("  ->", statement[:200], flush=True)
        try:
            cursor = self.db.execute(statement)
        except sqlite3.Error as error:
            print(f"  !! {error} in {statement[:160]}", flush=True)
            raise
        if cursor.description is None:
            return [], []
        columns = [column[0] for column in cursor.description]
        rows = [[None if value is None else str(value) for value in row] for row in cursor.fetchall()]
        return rows, columns


BIBLIO_COLUMNS = [
    "biblio_id",
    "title",
    "image",
    "isbn_issn",
    "publish_year",
    "publisher",
    "publish_place",
    "call_number",
    "language_id",
    "language_name",
    "classification",
    "notes",
    "series_title",
    "edition",
    "collation",
    "sor",
    "spec_detail_info",
    "opac_hide",
    "promoted",
    "last_update",
    "gmd_id",
    "gmd_name",
]

BIBLIO_ROW = [
    "1",
    "PHP 5 for dummies",
    "php5_dummies.jpg",
    "0764541668",
    "2004",
    "Wiley",
    "Jakarta",
    "005.13/3",
    "en",
    "English",
    "005.13",
    "A note",
    "For dummies",
    "1st",
    "xiv, 392 p.",
    "Valade",
    "",
    "0",
    "0",
    "2007-11-29 16:26:59",
    "1",
    "Text",
]


def canned_rows(normalized: str):
    """Fixed answers for the protocol smoke test (`--canned`)."""
    if normalized.startswith("SELECT b.biblio_id, b.title, b.image"):
        return [BIBLIO_ROW], BIBLIO_COLUMNS
    if normalized.startswith("SELECT ba.biblio_id"):
        return [["1", "Valade, Janet", "1"]], ["biblio_id", "author_name", "level"]
    if normalized.startswith("SELECT bt.biblio_id"):
        return [["1", "PHP"]], ["biblio_id", "topic"]
    if normalized.startswith("SELECT i.biblio_id"):
        return (
            [["1", "B00001", "005.13", "SL", "Reference", "Available", "Main Library", "0"]],
            [
                "biblio_id",
                "item_code",
                "call_number",
                "site",
                "coll_type_name",
                "item_status_name",
                "location_name",
                "on_loan",
            ],
        )
    if normalized.startswith("SELECT b.biblio_id, COUNT(*) AS total"):
        return [["1", "5"]], ["biblio_id", "total"]
    if normalized.startswith("SELECT COUNT(biblio_id)"):
        return [["15"]], ["COUNT(biblio_id)"]
    if normalized.startswith("SELECT COUNT(*)"):
        return [["15"]], ["COUNT(*)"]
    if normalized.startswith("SELECT COUNT(item_id)"):
        return [["10"]], ["COUNT(item_id)"]
    if normalized.startswith("SELECT COUNT(loan_id)"):
        return [["7"]], ["COUNT(loan_id)"]
    if normalized.startswith("SELECT COUNT(loan_date)"):
        return [["1"]], ["countloan"]
    if normalized.startswith("SELECT DATE_FORMAT(loan_date"):
        return (
            [["15/11", "2026-11-15"], ["16/11", "2026-11-16"]],
            ["loandate", "loan_date"],
        )
    if normalized.startswith("SELECT g.gmd_name AS name"):
        return [["Text", "15"]], ["name", "total"]
    if normalized.startswith("SELECT t.topic AS name"):
        return [["PHP", "15"]], ["name", "total"]
    if normalized.startswith("SELECT biblio_id FROM biblio WHERE opac_hide < 1"):
        return [["1"]], ["biblio_id"]
    return [], []


async def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=3307)
    parser.add_argument("--root", default=None, help="repository root (default: auto-detect)")
    parser.add_argument("--canned", action="store_true", help="fixed rows, protocol test only")
    parser.add_argument("--no-demo-circulation", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    parser.add_argument("--demo-member-login", action="store_true", help="enable known M001 test credential in the disposable SQLite dataset")
    args = parser.parse_args()

    root = pathlib.Path(args.root) if args.root else pathlib.Path(__file__).resolve().parent.parent
    db = None
    if not args.canned:
        try:
            db = load_dataset(root)
            if not args.no_demo_circulation:
                seed_demo_circulation(db)
            if args.demo_member_login:
                db.execute("UPDATE member SET mpasswd=? WHERE member_id=?", (
                    "$2y$04$qGzOSwDpk3DwkERJbP.zsuFsyyjmAMwbQByLMBLb/Y77gxpIIJWGO", "M001"))
            print(
                f"dataset {root}/install: "
                f"{db.execute('SELECT COUNT(*) FROM biblio').fetchone()[0]} biblio, "
                f"{db.execute('SELECT COUNT(*) FROM item').fetchone()[0]} items, "
                f"{db.execute('SELECT COUNT(*) FROM loan').fetchone()[0]} loans",
                flush=True,
            )
        except (SystemExit, sqlite3.Error) as error:
            print(f"dataset unavailable ({error}); falling back to canned rows", flush=True)
            db = None

    server = MysqlServer(
        lambda: FakeSession(db=db, verbose=args.verbose),
        identity_provider=Identity(),
    )
    await server.start_server(host=args.host, port=args.port)
    mode = "canned rows" if db is None else "sqlite dataset"
    print(
        f"fake mysql listening on {args.host}:{args.port} "
        f"(user/password: slims/{PASSWORD}, {mode})",
        flush=True,
    )
    await server.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
