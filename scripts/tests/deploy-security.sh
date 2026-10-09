#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="$(command -v php)"
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
deploy_lib_source="$(cat "${ROOT_DIR}/scripts/deploy-lib.sh")"
migration_check_source="$(cat "${ROOT_DIR}/scripts/migration-applied.php")"
seed_source="$(cat "${ROOT_DIR}/server/bin/seed.php")"
plugin_cleanup_source="$(cat "${ROOT_DIR}/server/bin/disable-missing-plugins.php")"
clone_sanitizer_source="$(cat "${ROOT_DIR}/scripts/sanitize-dev-clone.php")"
db_boundary_source="$(cat "${ROOT_DIR}/scripts/check-db-boundary.php")"
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
  && "${deploy_source}" == *'045_feed_credentials.php'* \
  && "${deploy_source}" == *'MOVE_INTEGRITY_QUIESCED'* \
  && "${deploy_source}" == *'FEED_CREDENTIALS_QUIESCED'* \
  && "${deploy_source}" == *'.deploy-move-integrity-paused'* \
  && "${deploy_source}" == *'systemctl stop'* \
  && "${deploy_source}" == *'systemctl start'* \
  && "${deploy_source}" == *'LoadState'* \
  && "${deploy_source}" == *'ActiveState'* \
  && "${deploy_source}" == *'bootstrap_present'* \
  && "${deploy_source}" == *'sudo -n test ! -e'* \
  && "${deploy_source}" == *'crontab -l'* \
  && "${deploy_source}" == *'crontab - <"${cron_after}"'* \
  && "${deploy_source}" == *'process_status'* \
  && "${deploy_source}" == *'pgrep -u'* ]]; then
  ok
else
  not_ok 'production deploy does not require quiesced first rollouts for migrations 042 and 045'
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
if [[ "${dev_source}" == *'DEV_ISOLATION_MODE="${DEV_ISOLATION_MODE:-strict}"'* \
  && "${dev_source}" == *'DEV_ISOLATION_MODE=shared explicitly permits'* \
  && "${dev_source}" == *'DEV_APP_USER="${DEV_APP_USER:-${APP_USER}}"'* \
  && "${dev_source}" == *'DEV_DB_USER="${DEV_DB_USER:-${DB_USER}}"'* \
  && "${dev_source}" == *'DEV_PHP_FPM_SERVICE="${DEV_PHP_FPM_SERVICE:-${PHP_FPM_SERVICE}}"'* ]]; then
  ok
else
  not_ok 'development deploy does not default to strict isolation with an explicit warned shared-identity mode'
fi
if [[ "${dev_source}" == *'systemctl show --property=Id'* \
  && "${dev_source}" == *'if [ "${fpm_relation}" = distinct ]'*'systemctl stop ${dev_fpm_q}'* \
  && "${dev_source}" == *'if [ "${fpm_relation}" = distinct ]'*'systemctl restart ${dev_fpm_q}'* \
  && "${dev_source}" == *'deploy_failure_recovery'* \
  && "${dev_source}" == *'DEV_FPM_PAUSED=1'* \
  && "${deploy_lib_source}" == *'deploy_failure_recovery "${rc}"'* \
  && "${db_boundary_source}" == *'REPORT_DB_PRINCIPAL'*'SELECT CURRENT_USER()'* \
  && "${dev_source}" == *'prod_db_principal'*'dev_db_principal'*'db_identities_shared'* \
  && "${dev_source}" == *'stage "verify pre-deploy database isolation"'* \
  && "${dev_source}" != *'ALTER USER '* \
  && "${dev_source}" != *'REVOKE ALL PRIVILEGES'* \
  && "${dev_source}" != *'GRANT ALL PRIVILEGES'* ]]; then
  ok
else
  not_ok 'development deploy can mutate a shared service/account or fails to verify effective database identities'
fi
if [[ "${dev_source}" == *'DEV_EXTERNAL_SERVICES="${DEV_EXTERNAL_SERVICES:-disabled}"'* \
  && "${dev_source}" == *'DEV_EXTERNAL_SERVICES=enabled permits configured dev integrations'* \
  && "${dev_source}" == *'while DEV_EXTERNAL_SERVICES=disabled'* ]]; then
  ok
else
  not_ok 'development deploy does not provide a disabled-by-default warned external-service switch'
fi
if [[ "${dev_source}" == *'DEV_CLONE_SUBSCRIPTIONS="${DEV_CLONE_SUBSCRIPTIONS:-preserve}"'* \
  && "${dev_source}" == *'DEV_CLONE_SUBSCRIPTIONS must be preserve or strip'* \
  && "${dev_source}" == *'STRIP_DEV_SUBSCRIPTIONS'* \
  && "${clone_sanitizer_source}" == *"kind = 'subscribed' AND COALESCE(provider, 'ics') = 'ics'"* \
  && "${clone_sanitizer_source}" == *'bcDevCloneSealSource'*'bcDevCloneOpenSource'* ]]; then
  ok
else
  not_ok 'development clone does not preserve/re-encrypt ICS subscriptions by default with explicit stripping'
fi
if [[ "${dev_source}" == *'bc_dev_stage_'* \
  && "${dev_source}" == *'CREATE DATABASE \`${STAGING_DB}\`'*'BETTERCAL_VERIFY_DEV_CLONE_STAGE=1 php "${CLONE_HELPER}"'*'mysqldump -h 127.0.0.1 -u root --single-transaction "${PROD_DB}"'* \
  && "${dev_source}" == *'mysqldump -h 127.0.0.1 -u root --single-transaction "${PROD_DB}" | mysql -h 127.0.0.1 -u root "${STAGING_DB}"'* \
  && "${dev_source}" == *'BETTERCAL_RUN_DEV_CLONE_SANITIZER=1 php "${CLONE_HELPER}"'* \
  && "${dev_source}" == *'mysqldump -h 127.0.0.1 -u root --single-transaction "${STAGING_DB}" | mysql -h 127.0.0.1 -u root "${DEV_DB}"'* \
  && "${dev_source}" == *'^bc_dev_stage_[0-9a-f]{24}$'* \
  && "${dev_source}" == *'DROP DATABASE IF EXISTS \`${STAGING_DB}\`'* \
  && "${dev_source}" == *'automatic cleanup of the private clone staging resources failed'* \
  && "${clone_sanitizer_source}" == *"['sessions', 'trusted_devices', 'push_subscriptions', 'out_feeds', 'google_accounts', 'api_tokens']"* \
  && "${clone_sanitizer_source}" == *'UPDATE plugins SET enabled = 0'*'DELETE FROM plugin_kv'*'DELETE FROM http_cache'* \
  && "${clone_sanitizer_source}" == *"WHERE status IN ('pending', 'running')"* \
  && "${clone_sanitizer_source}" == *'UPDATE mutations SET before_json = NULL, after_json = NULL'* ]]; then
  ok
else
  not_ok 'development clone can publish production credentials before staging sanitation succeeds'
fi
if [[ "${db_boundary_source}" == *'REQUIRE_DB_ISOLATION'* \
  && "${db_boundary_source}" == *"if (\$requireIsolation === '0')"* \
  && "${dev_source}" == *'stage "verify effective database boundary"'*'check-db-boundary.php'*'stage "rsync code"'* ]]; then
  ok
else
  not_ok 'development deploy does not verify the intended database before branch code while supporting explicit shared DB authority'
fi
if [[ "${deploy_lib_source}" == *'history_ref'*'server/plugins/*/plugin.json'* \
  && "${deploy_lib_source}" == *'/var/lib/better-cal-deploy'* \
  && "${deploy_lib_source}" == *'__PRESENT__'* \
  && "${deploy_lib_source}" == *'plugin-quarantine'* \
  && "${deploy_lib_source}" == *'managed-plugins'* \
  && "${deploy_lib_source}" == *'crontab -l'*'pgrep -u'* \
  && "${deploy_source}${dev_source}" == *'disable_missing_plugins'* ]]; then
  ok
else
  not_ok 'deploy does not reconcile removed Git-managed plugins without deleting operator drop-ins'
fi
if [[ "${deploy_lib_source}" == *'managed-plugins.pending'*'finalize_managed_plugin_manifest'* \
  && "${deploy_lib_source}" == *'disable-missing-plugins.php'*'finalize_managed_plugin_manifest'* ]]; then
  ok
else
  not_ok 'removed-plugin manifest can advance before durable database cleanup succeeds'
fi
if [[ "${deploy_source}" == *'stage "dependency audit"'*'audit_composer_snapshot "${DEPLOY_SNAPSHOT}"'*'stage "rsync code"'* \
  && "${deploy_lib_source}" == *'audit_composer_snapshot()'*'composer audit --working-dir='* \
  && "${deploy_lib_source}" == *'cleanup_composer_audit_dir'* ]]; then
  ok
else
  not_ok 'production dependency audit does not gate the exact snapshot before live deployment mutations'
fi
case "${plugin_cleanup_source}" in
  *"payload['plugin']"*"status = 'failed'"*"last_error"*) ok ;;
  *) not_ok 'removed-plugin cleanup does not cancel the actual queued plugin payload shape' ;;
esac
if [[ "${seed_source}" != *"'password:'"* \
  && "${seed_source}" == *"str_starts_with(\$arg, '--password=')"* \
  && "${seed_source}" == *"'password-stdin'"* ]]; then
  ok
else
  not_ok 'seed still accepts a password in process arguments or lacks the stdin replacement'
fi
set +e
seed_probe="$("${PHP_BIN}" "${ROOT_DIR}/server/bin/seed.php" --email=alex@example.test --password 2>&1)"
seed_status=$?
set -e
if [ "${seed_status}" -eq 2 ] && [[ "${seed_probe}" == *'--password is not supported'* ]] \
  && [[ "${seed_probe}" != *'alex@example.test'* ]]; then
  ok
else
  not_ok 'seed did not reject an argv password before database access without echoing it'
fi
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
