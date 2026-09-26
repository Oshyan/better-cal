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

# One shared ssh connection, loud failures (see the file for why).
source "${ROOT_DIR}/scripts/deploy-lib.sh"

# Pre-flight gate. Every live break so far (blank app from a stray import, a
# dead "+ New" button, a frozen agenda) was a broken reference that a test run
# would have caught — but deploy never ran the tests. It does now. Set
# SKIP_TESTS=1 only when deliberately shipping a known-red tree.
#
# The modulepreload block and the service worker's version are no longer
# generated here: the app fills them in as it serves index.html and sw.js
# (server/src/Http/AppShell.php), so the committed files are what ships.
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

stage "connect"
ssh_open

# Back up before anything changes: the app directory and the database, into
# backups/ beside the app, keeping the newest 20 of each. Database credentials
# come from the app's own .env on the server and are never printed. A failed
# backup stops the deploy; SKIP_BACKUP=1 skips it deliberately.
if [ "${SKIP_BACKUP:-0}" != "1" ]; then
  stage "backup"
  rssh "APP_DIR='${APP_DIR}' bash -s" <<'BACKUP'
set -euo pipefail
envval() { grep -E "^$1=" "${APP_DIR}/.env" | head -1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"; }
BK="$(dirname "${APP_DIR}")/backups"
mkdir -p "${BK}"
TS="$(date +%Y-%m-%dT%H%M%S%z)"
tar czf "${BK}/bettercal-app-${TS}.tar.gz" --exclude=.git -C "$(dirname "${APP_DIR}")" "$(basename "${APP_DIR}")"
DSN="$(envval BETTERCAL_DB_DSN)"
case "${DSN}" in
  mysql:*)
    DB="$(printf '%s' "${DSN}" | sed -n 's/.*dbname=\([^;]*\).*/\1/p')"
    HOST="$(printf '%s' "${DSN}" | sed -n 's/.*host=\([^;]*\).*/\1/p')"
    MYSQL_PWD="$(envval BETTERCAL_DB_PASS)" mysqldump --single-transaction --no-tablespaces \
      -h "${HOST:-localhost}" -u "$(envval BETTERCAL_DB_USER)" "${DB}" | gzip > "${BK}/bettercal-db-${TS}.sql.gz"
    zcat "${BK}/bettercal-db-${TS}.sql.gz" | tail -1 | grep -q 'Dump completed' \
      || { echo "database dump incomplete; not deploying" >&2; exit 1; } ;;
  sqlite:*)
    cp "${DSN#sqlite:}" "${BK}/bettercal-db-${TS}.sqlite" ;;
  *)
    echo "BETTERCAL_DB_DSN is neither mysql nor sqlite; database not backed up, not deploying" >&2; exit 1 ;;
esac
for kind in app db; do ls -1t "${BK}"/bettercal-${kind}-2* 2>/dev/null | tail -n +21 | xargs -r rm -f; done
ls -1 "${BK}"/*"${TS}"* | sed 's|.*/|  |'
BACKUP
fi

stage "rsync code"
deploy_rsync "${APP_DIR}"

stage "composer + migrate + link"
rssh "APP_DIR='${APP_DIR}' DOCROOT='${DOCROOT}' APP_USER='${APP_USER}' bash -s" <<'EOF'
set -euo pipefail
# rsync already wrote everything as APP_USER; this only repairs strays (a file
# a root shell left behind), and never touches .env.
find "${APP_DIR}" ! -user "${APP_USER}" ! -path "${APP_DIR}/.env" -exec chown -h "${APP_USER}:${APP_USER}" {} +
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

stage "smoke"
sleep 1
# /health proves PHP and the database answer. The server-side smoke proves the
# events window, the single-event record and the calendars listing actually
# serialise against the real data: a serializer refactor once 500ed the window
# while this script printed "Deploy OK" under a green /health.
# (curl on its own line: inside `curl && echo`, set -e ignored a failing /health.)
curl -fsS --max-time 10 "${HEALTH_URL}"
echo
rssh "sudo -u ${APP_USER} php ${APP_DIR}/server/bin/smoke.php"
echo "Deploy OK"
