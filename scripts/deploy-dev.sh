#!/bin/bash
set -euo pipefail

# Dev-instance deploy: ships the CURRENT branch (any branch) to an isolated
# dev copy (DEV_DIR / DEV_HEALTH_URL in scripts/deploy.env). The dev instance
# is a full second install — own directory, own database, own .env — sharing
# only the host, the TLS certificate, and the PHP-FPM pool. The CLONE_DB step
# assumes a CloudPanel host (clpctl for the MySQL root password); elsewhere,
# set MYSQL_ROOT_PASSWORD.
#
# PORT 9443 IS CLOSED AT THE FIREWALL by default, so the URL above will time out
# until you reopen it. That is deliberate: dev serves a CLONE of production —
# real events, real people, real tokens — behind the SAME session secret as
# prod, so a prod cookie authenticates on it. An internet-facing second door to
# all of that is not worth leaving open between branches.
#
# Open it only while you are actually testing, and close it again after:
#   hcloud firewall add-rule <firewall-name> --direction in --protocol tcp \
#     --port 9443 --source-ips 0.0.0.0/0 --source-ips ::/0
#   hcloud firewall delete-rule <firewall-name> --direction in --protocol tcp \
#     --port 9443 --source-ips 0.0.0.0/0 --source-ips ::/0
#
# Better still, scope it to your own address the way port 8443 already is,
# rather than to 0.0.0.0/0.
#
# Everything else survives closure: the code, the database and the vhost stay
# in place (~42 MB total), nothing is scheduled against dev (the worker cron
# points only at prod), so a closed dev costs nothing and revives instantly.
#
# Isolation properties worth knowing:
#   - Mail ingest, reminder email, RSVP SMTP, and Web Push are OFF in dev by
#     construction: its .env simply omits the SMTP/RSVP/VAPID variables, and
#     IMAP piggybacks on SMTP config, so the worker's outbound side effects
#     all no-op. Feed polls and geocoding still work (read-only fetches).
#   - The worker cron only points at the prod path. Run the dev worker by
#     hand when testing background jobs:
#       ssh "$REMOTE" sudo -u "$APP_USER" php "$DEV_DIR/server/bin/worker.php"
#   - Same SESSION_SECRET as prod plus a cloned sessions table means the
#     browser session you already have works on :9443 without logging in.
#
# CLONE_DB=1 re-clones production data into bettercal_dev (drops dev data).
# SKIP_TESTS=1 skips the local pre-flight suites.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Target host and paths come from scripts/deploy.env (copy deploy.env.example;
# ignored by git). Real environment variables win over the file.
if [ -f "${ROOT_DIR}/scripts/deploy.env" ]; then
  while IFS='=' read -r key value; do
    case "${key}" in ''|\#*) continue ;; esac
    if [ -z "${!key:-}" ]; then export "${key}=${value}"; fi
  done < "${ROOT_DIR}/scripts/deploy.env"
fi
for v in REMOTE APP_USER APP_DIR HEALTH_URL; do
  if [ -z "${!v:-}" ]; then
    echo "${v} is not set. Copy scripts/deploy.env.example to scripts/deploy.env and fill it in." >&2
    exit 1
  fi
done
for v in DEV_DIR DEV_HEALTH_URL PROD_DB DEV_DB; do
  if [ -z "${!v:-}" ]; then
    echo "${v} is not set (the dev instance; see scripts/deploy.env.example)." >&2
    exit 1
  fi
done
HEALTH_URL="${DEV_HEALTH_URL}"

BRANCH="$(git -C "${ROOT_DIR}" branch --show-current)"
echo "== deploying branch '${BRANCH}' to dev (${DEV_DIR}) =="

if [ "${SKIP_TESTS:-0}" != "1" ]; then
  echo "== pre-flight tests =="
  node "${ROOT_DIR}/scripts/gen-preload.mjs" --check
  node --experimental-vm-modules "${ROOT_DIR}/web/tests/static.mjs" 2>/dev/null | tail -1
  # Several zones, not just Pacific: see scripts/deploy.sh.
  for zone in America/Los_Angeles UTC Europe/Berlin Asia/Kolkata Pacific/Auckland; do
    printf '%-20s ' "${zone}"
    TZ="${zone}" node "${ROOT_DIR}/web/tests/smoke.mjs" 2>/dev/null | tail -1
  done
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
  echo "== re-cloning ${PROD_DB} into ${DEV_DB} =="
  ssh "${REMOTE}" "APP_DIR='${APP_DIR}' APP_USER='${APP_USER}' PROD_DB='${PROD_DB}' DEV_DB='${DEV_DB}' MYSQL_ROOT_PASSWORD='${MYSQL_ROOT_PASSWORD:-}' bash -s" <<'EOF'
set -euo pipefail
MPASS="${MYSQL_ROOT_PASSWORD:-$(clpctl db:show:master-credentials | awk -F'|' '/Password/ {gsub(/ /,"",$3); print $3}')}"
mysql -h 127.0.0.1 -u root -p"${MPASS}" -e "DROP DATABASE IF EXISTS ${DEV_DB}; CREATE DATABASE ${DEV_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysqldump -h 127.0.0.1 -u root -p"${MPASS}" --single-transaction "${PROD_DB}" | mysql -h 127.0.0.1 -u root -p"${MPASS}" "${DEV_DB}"
DBUSER="$(sudo -u "${APP_USER}" grep '^BETTERCAL_DB_USER=' "${APP_DIR}/.env" | cut -d= -f2)"
mysql -h 127.0.0.1 -u root -p"${MPASS}" -e "GRANT ALL PRIVILEGES ON ${DEV_DB}.* TO '${DBUSER}'@'localhost'; GRANT ALL PRIVILEGES ON ${DEV_DB}.* TO '${DBUSER}'@'127.0.0.1'; FLUSH PRIVILEGES;" || true
echo "cloned"
EOF
fi

echo "== composer + migrate (dev) =="
ssh "${REMOTE}" "DEV_DIR='${DEV_DIR}' APP_USER='${APP_USER}' bash -s" <<'EOF'
set -euo pipefail
chown -R "${APP_USER}:${APP_USER}" "${DEV_DIR}"
sudo -u "${APP_USER}" bash -c "cd ${DEV_DIR}/server && composer install --no-dev --quiet --no-interaction"
sudo -u "${APP_USER}" php "${DEV_DIR}/server/bin/migrate.php"
EOF

echo "== smoke =="
sleep 1
curl -fsS --max-time 10 "${HEALTH_URL}" && echo && echo "Dev deploy OK -> ${HEALTH_URL%/api/v1/health}"
