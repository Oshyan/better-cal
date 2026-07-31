#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE="${REMOTE:-hetzner}"
APP_DIR="/home/bettercal/app"
DOCROOT="/home/bettercal/htdocs/cal.oshyan.com"
HEALTH_URL="https://cal.oshyan.com/api/v1/health"

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
curl -fsS --max-time 10 "${HEALTH_URL}" && echo && echo "Deploy OK"
