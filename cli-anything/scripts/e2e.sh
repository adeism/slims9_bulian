#!/usr/bin/env bash
#
# End-to-end check of the CLI-Anything integration against the docker-compose
# SLiMS in this repository.
#
#   cp .env.example .env        # once
#   CLI_ANYTHING_E2E=1 ./cli-anything/scripts/e2e.sh
#
# What it does:
#   1. boots docker compose (SLiMS + MariaDB, sample data)
#   2. writes config/cli_anything.php with a random token, writes enabled
#   3. activates the bridge plugin
#   4. seeds a throw-away patron CLI0001
#   5. checks the local (tarsius) and remote (HTTP) backends
#   6. runs checkout as dry run, then commit, then checks the item back in
#
# It refuses to run unless CLI_ANYTHING_E2E=1 is set, because it *writes* a
# loan into the sample database.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$ROOT"

if [[ "${CLI_ANYTHING_E2E:-0}" != "1" ]]; then
    echo "Refusing to run: this script boots docker compose and writes a loan." >&2
    echo "Re-run with CLI_ANYTHING_E2E=1 to acknowledge." >&2
    exit 1
fi

if [[ ! -f .env ]]; then
    echo ".env not found. Run: cp .env.example .env" >&2
    exit 1
fi

set -a
# shellcheck disable=SC1091
. ./.env
set +a

APP=/var/www/html
PORT="${HTTP_PORT:-8881}"
DB_NAME="${DB_NAME:-senayan}"
DB_USER="${DB_USER:-root}"
BASE="http://localhost:${PORT}"
TOKEN=$(openssl rand -hex 24)
ITEM_CODE="${ITEM_CODE:-B00001}"
MEMBER_ID="${MEMBER_ID:-CLI0001}"

# MariaDB client flags (password is optional in the compose file)
DB_FLAGS=(-u"${DB_USER}")
if [[ -n "${DB_PASS:-}" ]]; then
    DB_FLAGS+=(-p"${DB_PASS}")
fi

db() {
    docker compose exec -T db mariadb "${DB_FLAGS[@]}" "${DB_NAME}" -e "$1"
}

echo "==> booting docker compose (this can take a few minutes)"
docker compose up -d --build

echo "==> waiting for SLiMS on ${BASE}"
for _ in $(seq 1 90); do
    if curl -sf -o /dev/null "${BASE}/index.php"; then
        break
    fi
    sleep 2
done

echo "==> writing config/cli_anything.php with writes enabled"
docker compose exec -T slims sh -c "cat > ${APP}/config/cli_anything.php" <<PHP
<?php
return [
    'enabled' => true,
    'token' => '${TOKEN}',
    'user_id' => 1,
    'user_name' => 'CLI-Anything E2E',
    'allow_write' => true,
    'allow_bulk' => true,
    'redact_pii' => true,
    'allow_pii_reveal' => false,
    'backup_dir' => '',
    'default_limit' => 20,
];
PHP

echo "==> activating the CLI-Anything Bridge plugin"
db "INSERT INTO plugins (id, path, options, created_at, deleted_at, uid)
    VALUES (MD5('${APP}/plugins/cli_anything/cli_anything.plugin.php'),
            '${APP}/plugins/cli_anything/cli_anything.plugin.php', NULL, NOW(), NULL, 1)
    ON DUPLICATE KEY UPDATE deleted_at = NULL, path = VALUES(path);"

echo "==> seeding patron ${MEMBER_ID}"
db "INSERT INTO member (member_id, member_name, gender, member_type_id, member_email,
                      member_phone, register_date, expire_date, input_date, last_update)
    VALUES ('${MEMBER_ID}', 'CLI-Anything Test Patron', 1, 1, 'cli@example.test', '000000',
            CURDATE(), DATE_ADD(CURDATE(), INTERVAL 365 DAY), CURDATE(), CURDATE())
    ON DUPLICATE KEY UPDATE member_name = VALUES(member_name);"

echo "==> local backend: console doctor (php index.php ...)"
docker compose exec -T slims php index.php clianything:doctor --json > /tmp/cli-anything-doctor.json
python3 - <<'PY'
import json

data = json.load(open('/tmp/cli-anything-doctor.json'))
assert data['ok'] is True, data
print('    gates :', data['data']['gates'])
print('    counts:', data['data']['counts'])
PY

echo "==> HTTP backend: doctor"
pip install -q ./cli-anything 2>/dev/null || pip install -q --break-system-packages ./cli-anything
cli-anything-slims --base-url "${BASE}" --token "${TOKEN}" --json doctor > /tmp/cli-anything-http.json
python3 - <<'PY'
import json

data = json.load(open('/tmp/cli-anything-http.json'))
assert data['ok'] is True, data
assert data['meta']['write_enabled'] is True, data['meta']
print('    http bridge reachable, writes enabled')
PY

echo "==> read: item ${ITEM_CODE}"
cli-anything-slims --base-url "${BASE}" --token "${TOKEN}" --json item get "${ITEM_CODE}" \
    > /tmp/cli-anything-item.json

echo "==> dry run checkout (must not write)"
cli-anything-slims --base-url "${BASE}" --token "${TOKEN}" --json \
    loan checkout --member "${MEMBER_ID}" --item "${ITEM_CODE}" > /tmp/cli-anything-plan.json
python3 - <<'PY'
import json

plan = json.load(open('/tmp/cli-anything-plan.json'))
assert plan['ok'] is True, plan
assert plan['data']['dry_run'] is True, plan['data']
assert plan['data']['ready'] is True, plan['data']
print('    planned due date:', plan['data']['items'][0]['due_date'])
PY

echo "==> committed checkout"
cli-anything-slims --base-url "${BASE}" --token "${TOKEN}" --json \
    loan checkout --member "${MEMBER_ID}" --item "${ITEM_CODE}" --commit > /tmp/cli-anything-commit.json
python3 - <<'PY'
import json

result = json.load(open('/tmp/cli-anything-commit.json'))
assert result['ok'] is True, result
assert result['data']['committed'] is True, result['data']
print('    committed loan(s):', len(result['data']['receipt']))
PY

echo "==> committed checkin (leave the database clean)"
cli-anything-slims --base-url "${BASE}" --token "${TOKEN}" --json \
    loan checkin --item-code "${ITEM_CODE}" --commit > /tmp/cli-anything-checkin.json
python3 - <<'PY'
import json

result = json.load(open('/tmp/cli-anything-checkin.json'))
assert result['ok'] is True, result
assert result['data']['committed'] is True, result['data']
print('    returned, overdue days:', result['data']['plan']['overdue_days'])
PY

echo
echo "E2E OK — artefacts in /tmp/cli-anything-*.json"
echo "Audit trail: SELECT * FROM system_log WHERE log_location = 'CLI-Anything' ORDER BY log_id DESC LIMIT 5;"
