#!/usr/bin/env python3
"""Populate a NEW EMPTY local slims_test* MySQL database for destructive tests.

Requires PyMySQL. Creates the installer tables and sample data, then inserts four
known demo members, loans and pagination bookmarks. It refuses non-local hosts, names without the slims_test
prefix, and any database containing tables. It does not create or drop databases.
Never point this at a database containing real library data.
"""
import datetime as dt
import os
from pathlib import Path
from urllib.parse import urlsplit

try:
    import pymysql
except ImportError as exc:
    raise SystemExit("Install PyMySQL in a virtual environment first") from exc


def statements(path):
    lines = []
    for line in path.read_text(errors="replace").splitlines():
        stripped = line.strip()
        if stripped.startswith(("--", "/*", "#")):
            continue
        lines.append(line)
        if stripped.endswith(";"):
            yield "\n".join(lines)
            lines = []
    if "".join(lines).strip():
        yield "\n".join(lines)


def main():
    if os.environ.get("SLIMS_TEST_CREATE_FIXTURE") != "1":
        raise SystemExit("Set SLIMS_TEST_CREATE_FIXTURE=1 to populate a NEW EMPTY test database")
    url = urlsplit(os.environ.get("SLIMS_TEST_MYSQL_URL", ""))
    database = url.path.removeprefix("/")
    if url.scheme != "mysql" or url.hostname != "127.0.0.1" or not database.startswith("slims_test") or "/" in database or not url.username:
        raise SystemExit("Use mysql://user:password@127.0.0.1:port/slims_test... only")
    root = Path(__file__).resolve().parent.parent
    connection = pymysql.connect(host=url.hostname, port=url.port or 3306, user=url.username,
                                 password=url.password or "", database=database,
                                 charset="utf8mb4", autocommit=True)
    with connection.cursor() as cur:
        cur.execute("SHOW FULL TABLES")
        if cur.fetchone() is not None:
            raise SystemExit("Refusing to populate a non-empty database")
        # Installer SQL expects permissive default handling for some legacy data.
        cur.execute("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'")
        count = 0
        for name in ("senayan.sql", "sampledata.sql"):
            for statement in statements(root / "install" / name):
                if statement.strip().lower().startswith(("create table", "insert")):
                    cur.execute(statement)
                    count += 1
        password_hash = "$2y$04$qGzOSwDpk3DwkERJbP.zsuFsyyjmAMwbQByLMBLb/Y77gxpIIJWGO"
        for member_id, name, email in [
            ("M001", "Siti Rahma", "siti.rahma@example.org"),
            ("M002", "Budi Santoso", "budi.santoso@example.org"),
            ("M003", "Dewi Lestari", "dewi.lestari@example.org"),
            ("M004", "Agus Wijaya", "agus.wijaya@example.org"),
        ]:
            cur.execute("INSERT INTO member (member_id,member_name,gender,member_type_id,member_email,expire_date,is_pending,mpasswd,input_date,last_update) VALUES (%s,%s,0,1,%s,'2030-01-01',0,%s,'2007-01-01','2007-01-01')", (member_id, name, email, password_hash))
        cur.execute("SELECT item_code FROM item ORDER BY biblio_id, item_id LIMIT 10")
        items = [row[0] for row in cur.fetchall()]
        if len(items) < 9:
            raise SystemExit("Sample fixture does not contain enough items")
        rows = [(0, "M001", -2, 12, False, 0), (0, "M002", -20, 27, True, 0),
                (0, "M003", -40, 47, True, 0), (1, "M002", -3, 10, False, 0),
                (1, "M004", -25, 32, True, 0), (2, "M003", -4, 11, False, 0),
                (3, "M004", -5, 12, False, 0), (4, "M001", -6, 13, True, 0),
                (5, "M002", -9, -2, False, 0), (6, "M003", -7, 14, False, 1),
                (7, "M004", -12, -1, False, 0), (8, "M001", -15, 22, True, 0)]
        today = dt.date.today()
        for index, (item, member, ago, due, returned, renewed) in enumerate(rows, 1):
            date = lambda offset: (today + dt.timedelta(days=offset)).isoformat()
            cur.execute("INSERT INTO loan (loan_id,item_code,member_id,loan_date,due_date,renewed,is_lent,is_return,return_date) VALUES (%s,%s,%s,%s,%s,%s,1,%s,%s)",
                        (index, items[item], member, date(ago), date(due), renewed, int(returned), date(ago+5) if returned else None))
        # Match --demo-member-login on the wire double, so the complete read
        # suite works on a fresh real fixture too (including bookmark paging).
        for n in range(22):
            cur.execute("INSERT INTO biblio_mark (id,member_id,biblio_id,created_at) VALUES (%s,'M001',2,'2024-01-01 00:00:00')", (f"test-mark-{n:02d}",))
        cur.execute("INSERT INTO biblio_mark (id,member_id,biblio_id,created_at) VALUES ('test-other','M002',4,'2024-02-01 00:00:00')")
        cur.execute("INSERT INTO biblio_mark (id,member_id,biblio_id,created_at) VALUES ('test-orphan','M001',999999,'2024-03-01 00:00:00')")
        print(f"Prepared local disposable database {database}: {count} installer statements, four test members, 12 demo loans, and 24 bookmark rows")
    connection.close()


if __name__ == "__main__":
    main()
