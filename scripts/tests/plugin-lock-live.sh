#!/bin/bash
# The post-response plugin run's worker lock (server/tests/plugin-lock-mysql.php)
# against a disposable MariaDB container. Named locks are MySQL's, so the
# main suite can only check that it fails closed without them.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
docker info >/dev/null 2>&1 || { echo 'Start Docker Desktop first.' >&2; exit 2; }
if docker container inspect bc-lock-db >/dev/null 2>&1; then
  echo 'Refusing to replace existing container bc-lock-db' >&2
  exit 2
fi
trap 'docker rm -f -v bc-lock-db >/dev/null 2>&1 || true' EXIT
docker run -d --name bc-lock-db -p 127.0.0.1:33308:3306 \
  -e MARIADB_ROOT_PASSWORD=synthetic-root -e MARIADB_DATABASE=lock_test \
  -e MARIADB_USER=sample -e MARIADB_PASSWORD=synthetic-only mariadb:10.11 >/dev/null
for _ in $(seq 1 60); do
  docker exec bc-lock-db mariadb -usample -psynthetic-only -e 'select 1' lock_test >/dev/null 2>&1 && break
  sleep 2
done
BC_LOCK_TEST_DSN='mysql:host=127.0.0.1;port=33308;dbname=lock_test;charset=utf8mb4' \
  BC_LOCK_TEST_USER=sample BC_LOCK_TEST_PASS=synthetic-only \
  php "$root/server/tests/plugin-lock-mysql.php"
