#!/usr/bin/env bash
# Prove the MySQL backend without a MySQL server.
#
#   cargo build --manifest-path rust/Cargo.toml
#   tools/mysql_roundtrip.sh
#
# Installs mysql-mimic into a temporary virtualenv, starts tools/fake_mysql.py
# (which loads install/*.sql into SQLite and answers the store's queries), points
# the Rust binary at it and then:
#
#   1. checks the wire protocol end to end — handshake, mysql_native_password,
#      COM_QUERY, column definitions, text rows, hydration;
#   2. replays the whole behavior recording (rust/fixtures/behavior/cases.json)
#      against the MySQL-backed build, so the same 23 cases that pass on the
#      memory backend have to pass through the wire protocol too.
#
# Port 3307 is used for the fake server; set FAKE_MYSQL_PORT to avoid a clash.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BIN="${SLIMS_BIN:-$ROOT/rust/target/debug/slims9}"
FAKE_PORT="${FAKE_MYSQL_PORT:-3307}"
APP_PORT="${APP_PORT:-8099}"
VENV="${VENV:-/tmp/slims-mysql-venv}"

if [[ ! -x "$BIN" ]]; then
  echo "missing $BIN; run: cargo build --manifest-path rust/Cargo.toml" >&2
  exit 1
fi

if [[ ! -x "$VENV/bin/python" ]]; then
  python3 -m venv "$VENV"
  "$VENV/bin/pip" install --quiet mysql-mimic
fi

FAKE_PID=""
APP_PID=""
cleanup() {
  [[ -n "$APP_PID" ]] && kill "$APP_PID" 2>/dev/null || true
  [[ -n "$FAKE_PID" ]] && kill "$FAKE_PID" 2>/dev/null || true
}
trap cleanup EXIT

"$VENV/bin/python" "$ROOT/tools/fake_mysql.py" --port "$FAKE_PORT" &
FAKE_PID=$!
sleep 2

SLIMS_ADDR="127.0.0.1:$APP_PORT" \
SLIMS_DB="mysql://slims:secret@127.0.0.1:$FAKE_PORT/senayan" \
  "$BIN" &
APP_PID=$!
sleep 1

base="http://127.0.0.1:$APP_PORT"

check() {
  local path="$1"
  local needle="$2"
  local body
  if ! body="$(curl -fsS "$base$path")"; then
    echo "FAIL request $path" >&2
    exit 1
  fi
  if ! grep -qF -- "$needle" <<<"$body"; then
    echo "FAIL $path: response does not contain $needle" >&2
    printf '%s\n' "$body" | head -c 400 >&2
    echo >&2
    exit 1
  fi
  echo "ok   $path"
}

echo "== wire protocol =="
check "/healthz" '"backend":"mysql"'
check "/api/biblio/latest" 'PostgreSQL'
check "/api/biblio/popular" 'Linux In a Nutshell'
check "/api/biblio/total/all" '"15"'
check "/api/loan/summary" '"total":"12"'
check "/biblio/1" 'PHP 5 for dummies'

echo
echo "== behavior recording through the wire protocol =="
GATE_OUT="$(python3 "$ROOT/tools/gate.py" --url "$base" --backend mysql)"
printf '%s\n' "$GATE_OUT"
GATE_SUMMARY="$(printf '%s\n' "$GATE_OUT" | grep -o '[0-9]*/[0-9]* cases passed' | head -1)"

echo
echo "mysql round-trip: OK"
echo "  handshake + mysql_native_password: ok"
echo "  COM_QUERY + column definitions + text rows: ok"
echo "  hydration through the store: ok"
echo "  behavior recording: $GATE_SUMMARY"
