#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "${ROOT_DIR}/scripts/deploy-backup.sh"

passed=0
failed=0
ok() { passed=$((passed + 1)); }
not_ok() { echo "FAIL: $*" >&2; failed=$((failed + 1)); }

TEST_DIR="$(mktemp -d "${TMPDIR:-/tmp}/bc-deploy-security.XXXXXX")"
trap 'chmod -R u+rwX "${TEST_DIR}" 2>/dev/null || true; rm -rf "${TEST_DIR}"' EXIT
uid="$(id -u)"
gid="$(id -g)"

secure="${TEST_DIR}/secure"
mkdir "${secure}"
chmod 700 "${secure}"
if backup_assert_secure_dir "${secure}" "${uid}"; then ok; else not_ok 'private owned directory was rejected'; fi
if backup_assert_private_dir "${secure}" "${uid}" "${gid}"; then ok; else not_ok '0700 private directory was rejected'; fi

chmod 750 "${secure}"
if backup_assert_private_dir "${secure}" "${uid}" "${gid}" >/dev/null 2>&1; then
  not_ok 'non-0700 final backup directory was accepted'
else
  ok
fi
chmod 700 "${secure}"

unsafe="${TEST_DIR}/unsafe"
mkdir "${unsafe}"
chmod 777 "${unsafe}"
if backup_assert_secure_dir "${unsafe}" "${uid}" >/dev/null 2>&1; then
  not_ok 'group/other-writable backup directory was accepted'
else
  ok
fi

linked="${TEST_DIR}/linked"
ln -s "${secure}" "${linked}"
if backup_assert_secure_dir "${linked}" "${uid}" >/dev/null 2>&1; then
  not_ok 'symlinked backup directory was accepted'
else
  ok
fi
if backup_assert_private_dir "${linked}/" "${uid}" "${gid}" >/dev/null 2>&1; then
  not_ok 'trailing-slash backup-directory symlink was accepted'
else
  ok
fi

temporary="$(mktemp "${secure}/.candidate.XXXXXX")"
printf 'new backup\n' > "${temporary}"
final="${secure}/backup.tar.gz"
backup_publish "${temporary}" "${final}"
if [ "$(backup_mode "${final}")" = 600 ] && [ "$(cat "${final}")" = 'new backup' ]; then
  ok
else
  not_ok 'published backup was not private and intact'
fi

sentinel="${TEST_DIR}/sentinel"
printf 'sentinel\n' > "${sentinel}"
planted="${secure}/planted.tar.gz"
ln -s "${sentinel}" "${planted}"
temporary="$(mktemp "${secure}/.candidate.XXXXXX")"
printf 'attacker controlled\n' > "${temporary}"
if backup_publish "${temporary}" "${planted}" >/dev/null 2>&1; then
  not_ok 'pre-existing output symlink was replaced'
else
  ok
fi
if [ "$(cat "${sentinel}")" = 'sentinel' ]; then ok; else not_ok 'sentinel changed through output symlink'; fi
rm -f "${temporary}"

BACKUP_APP_TEMP="$(mktemp "${secure}/.app-cleanup.XXXXXX")"
BACKUP_DB_TEMP="$(mktemp "${secure}/.db-cleanup.XXXXXX")"
backup_cleanup_temporaries
if [ ! -e "${BACKUP_APP_TEMP}" ] && [ ! -e "${BACKUP_DB_TEMP}" ]; then
  ok
else
  not_ok 'failed backup temporaries were not cleaned'
fi
BACKUP_APP_TEMP=''
BACKUP_DB_TEMP=''

# Exercise the app-side configuration parser without sudo. Production invokes
# this exact function through sudo as APP_USER; this shim only removes the
# identity transition for a portable macOS/Linux test.
sudo() {
  shift # -n
  shift # -u
  shift # user
  shift # --
  command "$@"
}
APP_USER="$(id -un)"
APP_DIR="${TEST_DIR}/app"
mkdir "${APP_DIR}"
printf 'sqlite payload\n' > "${APP_DIR}/db.sqlite"
printf 'BETTERCAL_DB_DSN=sqlite:%s\n' "${APP_DIR}/db.sqlite" > "${APP_DIR}/.env"
if backup_db_kind >/dev/null 2>&1; then
  not_ok 'production backup accepted an app-selected SQLite source'
else
  ok
fi

deploy_source="$(cat "${ROOT_DIR}/scripts/deploy.sh")"
dev_source="$(cat "${ROOT_DIR}/scripts/deploy-dev.sh")"
helper_source="$(cat "${ROOT_DIR}/scripts/deploy-backup.sh")"
migration_check_source="$(cat "${ROOT_DIR}/scripts/migration-applied.php")"
case "${deploy_source}" in
  *'chown "root:${APP_USER}" "${APP_DIR}/.env"'*|*'chmod 640 "${APP_DIR}/.env"'*)
    not_ok 'production deploy still mutates .env as root' ;;
  *) ok ;;
esac
case "${dev_source}" in
  *'chown "root:${APP_USER}" "${DEV_DIR}/.env"'*|*'chmod 640 "${DEV_DIR}/.env"'*)
    not_ok 'development deploy still mutates .env as root' ;;
  *) ok ;;
esac
case "${deploy_source}${dev_source}" in
  *'-exec chown'*)
    not_ok 'deploy still performs recursive privileged ownership repair in an app-controlled tree' ;;
  *) ok ;;
esac
case "${deploy_source}" in
  *'sudo -n -u "${APP_USER}" -- rm -rf "${DOCROOT}"'*'sudo -n -u "${APP_USER}" -- ln -s'*) ok ;;
  *) not_ok 'document-root replacement does not run with app-user authority' ;;
esac
case "${deploy_source}" in
  *'sudo -n env APP_DIR='*'scripts/deploy-backup.sh'*) ok ;;
  *) not_ok 'production deploy does not run the reviewed backup helper through sudo' ;;
esac
if [[ "${deploy_source}" == *'migration-applied.php'* \
  && "${deploy_source}" == *'042_google_move_integrity.php'* \
  && "${deploy_source}" == *'MOVE_INTEGRITY_QUIESCED'* \
  && "${deploy_source}" == *'.deploy-move-integrity-paused'* \
  && "${deploy_source}" == *'systemctl stop'* \
  && "${deploy_source}" == *'systemctl start'* \
  && "${deploy_source}" == *'LoadState'* \
  && "${deploy_source}" == *'ActiveState'* \
  && "${deploy_source}" == *'bootstrap_present'* \
  && "${deploy_source}" == *'sudo -n test ! -e'* \
  && "${deploy_source}" == *'crontab -l'* \
  && "${deploy_source}" == *'process_status'* \
  && "${deploy_source}" == *'pgrep -u'* ]]; then
  ok
else
  not_ok 'production deploy does not require a quiesced first rollout for migration 042'
fi
case "${migration_check_source}" in
  *'MIGRATION_FILE'*'schema_migrations'*) ok ;;
  *) not_ok 'migration rollout gate does not read the authoritative migration ledger' ;;
esac
case "${helper_source}" in
  *'env MYSQL_PWD='*) not_ok 'database password is still placed in a privileged argv' ;;
  *'export MYSQL_PWD='*'mysqldump --no-defaults'*) ok ;;
  *) not_ok 'app-side MySQL reader does not export the password internally and disable option files' ;;
esac
case "${helper_source}" in
  *'flock -n 9'*) ok ;;
  *) not_ok 'backup publication is not serialized with an exclusive lock' ;;
esac

# Production streams the helper to `bash -s`, where BASH_SOURCE[0] is unset.
# It must enter backup_main and report the first missing input, not die while
# deciding whether it was sourced.
stdin_output="$(env -u APP_DIR -u APP_USER -u BACKUP_DIR bash -s \
  < "${ROOT_DIR}/scripts/deploy-backup.sh" 2>&1 || true)"
case "${stdin_output}" in
  *'APP_DIR is required'*) ok ;;
  *) not_ok 'backup helper does not start correctly when streamed to bash -s' ;;
esac

echo "Deploy security tests: ${passed} passed, ${failed} failed"
[ "${failed}" -eq 0 ]
