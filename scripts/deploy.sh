#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE="${REMOTE:-hetzner}"
APP_DIR="/home/bettercal/app"
DOCROOT="/home/bettercal/htdocs/cal.oshyan.com"
HEALTH_URL="https://cal.oshyan.com/api/v1/health"

# Production deploys ship main. Feature branches go to the dev instance via
# scripts/deploy-dev.sh (cal.oshyan.com:9443); FORCE_BRANCH=1 overrides for
# the rare deliberate exception.
BRANCH="$(git -C "${ROOT_DIR}" branch --show-current)"
if [ "${BRANCH}" != "main" ] && [ "${FORCE_BRANCH:-0}" != "1" ]; then
  echo "Refusing to deploy branch '${BRANCH}' to production. Use scripts/deploy-dev.sh, or FORCE_BRANCH=1." >&2
  exit 1
fi

# Pre-flight gate. Every live break so far (blank app from a stray import, a
# dead "+ New" button, a frozen agenda) was a broken reference that a test run
# would have caught — but deploy never ran the tests. It does now. Set
# SKIP_TESTS=1 only when deliberately shipping a known-red tree.
# Always, even when tests are skipped: the modulepreload block is generated
# from the import graph (a stale one quietly sends the browser back to
# discovering modules level by level), and the service worker's VERSION and
# shell list are derived from a content hash of every shell file. Running it
# here, in write mode, means what ships is self-consistent whether or not
# anyone remembered to regenerate; the dev deploy keeps --check as its gate.
echo "== generated assets =="
node "${ROOT_DIR}/scripts/gen-preload.mjs"

if [ "${SKIP_TESTS:-0}" != "1" ]; then
  echo "== pre-flight tests =="
  node --experimental-vm-modules "${ROOT_DIR}/web/tests/static.mjs" 2>/dev/null | tail -1
  # Fixtures are written in Pacific time (-07:00 offsets, "today" maths); the
  # suite is only meaningful in that zone, wherever the laptop happens to be.
  TZ=America/Los_Angeles node "${ROOT_DIR}/web/tests/smoke.mjs" 2>/dev/null | tail -1
  php "${ROOT_DIR}/server/tests/run.php" | tail -1
fi

echo "== rsync code =="
# No --delete by explicit policy (user has been burned by it). Stale-file removal,
# when ever needed, is a deliberate manual action on the server.
rsync -az \
  --exclude '.git' \
  --exclude '.credentials' \
  --exclude '.env' \
  --exclude '.DS_Store' \
  --exclude 'server/vendor' \
  --exclude 'node_modules' \
  --rsync-path="rsync" \
  "${ROOT_DIR}/" "${REMOTE}:${APP_DIR}/"

echo "== composer + migrate + link =="
ssh "${REMOTE}" bash -s <<'EOF'
set -euo pipefail
APP_DIR="/home/bettercal/app"
DOCROOT="/home/bettercal/htdocs/cal.oshyan.com"
chown -R bettercal:bettercal "${APP_DIR}"
sudo -u bettercal bash -c "cd ${APP_DIR}/server && composer install --no-dev --quiet --no-interaction"
sudo -u bettercal php "${APP_DIR}/server/bin/migrate.php"
# Docroot -> app/server/public (replace real dir with symlink once)
if [ ! -L "${DOCROOT}" ]; then
  rm -rf "${DOCROOT}"
  ln -s "${APP_DIR}/server/public" "${DOCROOT}"
  chown -h bettercal:bettercal "${DOCROOT}"
fi
# Cron for worker (idempotent)
sudo -u bettercal bash -c 'crontab -l 2>/dev/null | grep -q worker.php || (crontab -l 2>/dev/null; echo "* * * * * php /home/bettercal/app/server/bin/worker.php >> /home/bettercal/worker.log 2>&1") | crontab -'
EOF

# --- CalDAV nginx block (one-time manual change; NOT applied by this script) --
# The /dav endpoint is served by server/public/dav.php (sabre/dav). Add this
# location to the cal.oshyan.com nginx server block, above/alongside the
# existing PHP location, then reload nginx:
#
#   location ^~ /dav {
#     include fastcgi_params;                          # or the site's fastcgi include
#     fastcgi_param SCRIPT_FILENAME $document_root/dav.php;
#     fastcgi_param PATH_INFO $uri;
#     fastcgi_pass unix:/run/php/php8.4-fpm.sock;      # match the existing fastcgi_pass
#   }
#
#   # Optional, lets clients auto-discover the CalDAV root:
#   location = /.well-known/caldav { return 301 /dav/; }
#
# Until the block is applied, the endpoint also answers directly at
# https://cal.oshyan.com/dav.php/ (dav.php adjusts its base URI automatically).

echo "== smoke =="
sleep 1
# /health proves PHP and the database answer. The server-side smoke proves the
# events window, the single-event record and the calendars listing actually
# serialise against the real data: a serializer refactor once 500ed the window
# while this script printed "Deploy OK" under a green /health.
curl -fsS --max-time 10 "${HEALTH_URL}" && echo
ssh "${REMOTE}" "sudo -u bettercal php ${APP_DIR}/server/bin/smoke.php" && echo "Deploy OK"
