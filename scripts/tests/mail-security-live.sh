#!/bin/bash
# Disposable services only. No install settings, real mailbox or outside SMTP.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
docker info >/dev/null 2>&1 || { echo 'Start Docker Desktop first.' >&2; exit 2; }
for name in bc-mail-app bc-mail-db; do
  if docker container inspect "$name" >/dev/null 2>&1; then
    echo "Refusing to replace existing container $name" >&2
    exit 2
  fi
done
if docker network inspect bc-mail-security >/dev/null 2>&1; then
  echo 'Refusing to replace existing network bc-mail-security' >&2
  exit 2
fi
cleanup() {
  docker rm -f -v bc-mail-app bc-mail-db >/dev/null 2>&1 || true
  docker network rm bc-mail-security >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker build -q -t bc-mail-security-fixture "$root/server/tests/fixtures/mail-services" >/dev/null
docker network create --internal bc-mail-security >/dev/null
docker run -d --name bc-mail-db --network bc-mail-security \
  -e MARIADB_ROOT_PASSWORD=synthetic-root -e MARIADB_DATABASE=mail_test \
  -e MARIADB_USER=sample -e MARIADB_PASSWORD=synthetic-only mariadb:10.11 >/dev/null
docker run -d --name bc-mail-app --network bc-mail-security \
  --mount "type=bind,source=$root/server,target=/code/server,readonly" \
  -e BC_MAIL_DISPOSABLE=1 bc-mail-security-fixture tail -f /dev/null >/dev/null
docker exec bc-mail-app mkdir -p /tmp/bc-mail
docker exec bc-mail-app openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout /tmp/bc-mail/key.pem -out /tmp/bc-mail/cert.pem -days 1 \
  -subj /CN=localhost -addext subjectAltName=DNS:localhost >/dev/null 2>&1
docker exec bc-mail-app chmod 600 /tmp/bc-mail/key.pem
docker exec bc-mail-app dovecot
# Follow the actual database-ready event, not a guessed startup delay. A
# closed log stream is checked below, so startup failure cannot look ready.
php -r '
  $process = proc_open(["docker", "logs", "-f", "bc-mail-db"], [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["redirect", 1]], $pipes);
  if (!$process) exit(1);
  $ready = false; $announced = false;
  while (true) {
    $read = [$pipes[1]]; $write = $except = null;
    if (stream_select($read, $write, $except, 60) !== 1) break;
    $line = fgets($pipes[1]);
    if ($line === false) break;
    if (str_contains($line, "ready for connections")) $announced = true;
    if ($announced && str_contains($line, "port: 3306")) { $ready = true; break; }
  }
  proc_terminate($process);
  foreach ($pipes as $pipe) fclose($pipe);
  proc_close($process);
  exit($ready ? 0 : 1);
'
docker exec bc-mail-db healthcheck.sh --connect --innodb_initialized
docker exec bc-mail-app php -d openssl.cafile=/tmp/bc-mail/cert.pem server/tests/mail-mysql.php
