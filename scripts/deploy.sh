#!/bin/bash
set -euo pipefail

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
if [ -z "${DOCROOT:-}" ]; then
  echo "DOCROOT is not set (the web server's document root; see scripts/deploy.env.example)." >&2
  exit 1
fi

# Production deploys ship main. Feature branches go to the dev instance via
# scripts/deploy-dev.sh; FORCE_BRANCH=1 overrides for the rare deliberate
# exception.
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
  # The frontend works in the viewer's zone, so the suite runs in several: west
  # and east of UTC, UTC itself, a half-hour offset, and a southern-hemisphere
  # DST zone past +12. It used to pass only in Pacific time, which hid all-day
  # events moving a day for anyone east of their event's zone.
  for zone in America/Los_Angeles UTC Europe/Berlin Asia/Kolkata Pacific/Auckland; do
    printf '%-20s ' "${zone}"
    TZ="${zone}" node "${ROOT_DIR}/web/tests/smoke.mjs" 2>/dev/null | tail -1
  done
  php "${ROOT_DIR}/server/tests/run.php" | tail -1
  # Vendored frontend libraries must be exactly the pinned releases.
  node "${ROOT_DIR}/scripts/vendor.mjs" --verify | tail -1
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
ssh "${REMOTE}" "APP_DIR='${APP_DIR}' DOCROOT='${DOCROOT}' APP_USER='${APP_USER}' bash -s" <<'EOF'
set -euo pipefail
chown -R "${APP_USER}:${APP_USER}" "${APP_DIR}"
# The app reads its .env but must not be able to rewrite it: root owns it,
# the app's group reads it (scan 2026-09-23, F1).
if [ "$(id -u)" -eq 0 ] && [ -f "${APP_DIR}/.env" ]; then chown "root:${APP_USER}" "${APP_DIR}/.env"; chmod 640 "${APP_DIR}/.env"; fi
sudo -u "${APP_USER}" bash -c "cd ${APP_DIR}/server && composer install --no-dev --quiet --no-interaction"
# Known advisories against the locked PHP dependencies: reported, not blocking.
# A finding means "look at it", not "roll back the deploy in progress".
echo "-- composer audit --"
sudo -u "${APP_USER}" bash -c "cd ${APP_DIR}/server && composer audit --no-dev --locked --no-interaction 2>&1 | tail -20" || true
sudo -u "${APP_USER}" php "${APP_DIR}/server/bin/migrate.php"
# Docroot -> app/server/public (replace real dir with symlink once)
if [ ! -L "${DOCROOT}" ]; then
  rm -rf "${DOCROOT}"
  ln -s "${APP_DIR}/server/public" "${DOCROOT}"
  chown -h "${APP_USER}:${APP_USER}" "${DOCROOT}"
fi
# Cron for worker (idempotent); the log sits beside the app directory.
WORKER_LOG="$(dirname "${APP_DIR}")/worker.log"
sudo -u "${APP_USER}" bash -c "crontab -l 2>/dev/null | grep -q worker.php || (crontab -l 2>/dev/null; echo \"* * * * * php ${APP_DIR}/server/bin/worker.php >> ${WORKER_LOG} 2>&1\") | crontab -"
EOF

# --- CalDAV nginx block (one-time manual change; NOT applied by this script) --
# The /dav endpoint is served by server/public/dav.php (sabre/dav). Add this
# location to the site's nginx server block, above/alongside the existing PHP
# location, then reload nginx (docs/install.md has the full block):
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
# /dav.php/ (dav.php adjusts its base URI automatically).

echo "== smoke =="
sleep 1
# /health proves PHP and the database answer. The server-side smoke proves the
# events window, the single-event record and the calendars listing actually
# serialise against the real data: a serializer refactor once 500ed the window
# while this script printed "Deploy OK" under a green /health.
curl -fsS --max-time 10 "${HEALTH_URL}" && echo
ssh "${REMOTE}" "sudo -u ${APP_USER} php ${APP_DIR}/server/bin/smoke.php" && echo "Deploy OK"
