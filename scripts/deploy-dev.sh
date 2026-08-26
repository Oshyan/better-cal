#!/bin/bash
set -euo pipefail

# Dev-instance deploy: ships the CURRENT branch (any branch) to the isolated
# dev copy at https://cal.oshyan.com:9443. The dev instance is a full second
# install — own directory (/home/bettercal/dev), own database (bettercal_dev),
# own .env — sharing only the host, the TLS certificate, and the PHP-FPM pool.
#
# Isolation properties worth knowing:
#   - Mail ingest, reminder email, RSVP SMTP, and Web Push are OFF in dev by
#     construction: its .env simply omits the SMTP/RSVP/VAPID variables, and
#     IMAP piggybacks on SMTP config, so the worker's outbound side effects
#     all no-op. Feed polls and geocoding still work (read-only fetches).
#   - The worker cron only points at the prod path. Run the dev worker by
#     hand when testing background jobs:
#       ssh hetzner sudo -u bettercal php /home/bettercal/dev/server/bin/worker.php
#   - Same SESSION_SECRET as prod plus a cloned sessions table means the
#     browser session you already have works on :9443 without logging in.
#
# CLONE_DB=1 re-clones production data into bettercal_dev (drops dev data).
# SKIP_TESTS=1 skips the local pre-flight suites.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE="${REMOTE:-hetzner}"
DEV_DIR="/home/bettercal/dev"
HEALTH_URL="https://cal.oshyan.com:9443/api/v1/health"

BRANCH="$(git -C "${ROOT_DIR}" branch --show-current)"
echo "== deploying branch '${BRANCH}' to dev (${DEV_DIR}) =="

if [ "${SKIP_TESTS:-0}" != "1" ]; then
  echo "== pre-flight tests =="
  node "${ROOT_DIR}/scripts/gen-preload.mjs" --check
  node --experimental-vm-modules "${ROOT_DIR}/web/tests/static.mjs" 2>/dev/null | tail -1
  node "${ROOT_DIR}/web/tests/smoke.mjs" 2>/dev/null | tail -1
  php "${ROOT_DIR}/server/tests/run.php" | tail -1
fi

echo "== rsync code =="
# Same no --delete policy as production.
rsync -az \
  --exclude '.git' \
  --exclude '.credentials' \
  --exclude '.env' \
  --exclude '.DS_Store' \
  --exclude 'server/vendor' \
  --exclude 'node_modules' \
  --rsync-path="rsync" \
  "${ROOT_DIR}/" "${REMOTE}:${DEV_DIR}/"

if [ "${CLONE_DB:-0}" = "1" ]; then
  echo "== re-cloning production DB into bettercal_dev =="
  ssh "${REMOTE}" bash -s <<'EOF'
set -euo pipefail
MPASS="$(clpctl db:show:master-credentials | awk -F'|' '/Password/ {gsub(/ /,"",$3); print $3}')"
mysql -h 127.0.0.1 -u root -p"${MPASS}" -e "DROP DATABASE IF EXISTS bettercal_dev; CREATE DATABASE bettercal_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysqldump -h 127.0.0.1 -u root -p"${MPASS}" --single-transaction bettercal | mysql -h 127.0.0.1 -u root -p"${MPASS}" bettercal_dev
DBUSER="$(sudo -u bettercal grep '^BETTERCAL_DB_USER=' /home/bettercal/app/.env | cut -d= -f2)"
mysql -h 127.0.0.1 -u root -p"${MPASS}" -e "GRANT ALL PRIVILEGES ON bettercal_dev.* TO '${DBUSER}'@'localhost'; GRANT ALL PRIVILEGES ON bettercal_dev.* TO '${DBUSER}'@'127.0.0.1'; FLUSH PRIVILEGES;" || true
echo "cloned"
EOF
fi

echo "== composer + migrate (dev) =="
ssh "${REMOTE}" bash -s <<'EOF'
set -euo pipefail
DEV_DIR="/home/bettercal/dev"
chown -R bettercal:bettercal "${DEV_DIR}"
sudo -u bettercal bash -c "cd ${DEV_DIR}/server && composer install --no-dev --quiet --no-interaction"
sudo -u bettercal php "${DEV_DIR}/server/bin/migrate.php"
EOF

echo "== smoke =="
sleep 1
curl -fsS --max-time 10 "${HEALTH_URL}" && echo && echo "Dev deploy OK -> https://cal.oshyan.com:9443"
