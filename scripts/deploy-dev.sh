#!/bin/bash
set -euo pipefail

# Dev-instance deploy: ships the CURRENT branch (any branch) to an isolated
# dev copy (DEV_DIR / DEV_HEALTH_URL in scripts/deploy.env). Strict mode uses
# its own Unix identity, PHP-FPM service, database identity, database, .env and
# login password. Explicit shared mode retains the lower-friction legacy host
# model and prints its risk on every run. For the MySQL root password the
# CLONE_DB step uses MYSQL_ROOT_PASSWORD when set; otherwise it falls back to
# CloudPanel's clpctl, one supported host option.
#
# Keep the dev port closed at your firewall by default, so the URL above times
# out until you open it. That is deliberate: dev serves a CLONE of production's
# events and people. The clone step strips sessions, API tokens, push devices,
# outbound feeds, Google links, active jobs and plugin integration settings,
# and dev must have its own
# BETTERCAL_SESSION_SECRET, so no production login works on it. Receive-only
# ICS subscriptions are re-encrypted for dev and retained unless explicitly
# stripped. The data itself is still real, which is reason enough not to leave
# a second public door open between branches.
#
# Open the dev port in your firewall only while you are actually testing (for
# example with your cloud provider's CLI), ideally scoped to your own address
# rather than to everyone, and close it again after.
#
# Everything else survives closure: the code, the database and the vhost stay
# in place, nothing is scheduled against dev (the worker cron points only at
# prod), so a closed dev costs nothing and revives instantly.
#
# Isolation properties worth knowing:
#   - Mail, Google, AI, mapping and Web Push configuration is OFF by default.
#     DEV_EXTERNAL_SERVICES=enabled permits deliberate dev-only configuration and
#     warns that dev may make external calls or cause side effects. Receive-only
#     ICS feeds and the fixed geocoder endpoints remain available separately.
#   - The worker cron only points at the prod path. Run the dev worker by
#     hand when testing background jobs:
#       ssh "$REMOTE" sudo -u "$DEV_APP_USER" php "$DEV_DIR/server/bin/worker.php"
#   - Production authentication state is not cloned. CLONE_DB replaces every
#     copied password verifier with DEV_LOGIN_PASSWORD and removes remembered
#     devices, so sign in to dev with its own password.
#   - Copied plugin-generated calendars/events remain as snapshots, but plugins
#     are disabled and their settings/KV/jobs are cleared. Reconfigure and
#     enable a plugin in dev when testing it.
#
# CLONE_DB=1 re-clones production data into DEV_DB (drops prior dev data only
# after a private staging clone has been sanitized and verified).
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
for v in REMOTE APP_USER APP_DIR HEALTH_URL DB_USER PHP_FPM_SERVICE; do
  if [ -z "${!v:-}" ]; then
    echo "${v} is not set. Copy scripts/deploy.env.example to scripts/deploy.env and fill it in." >&2
    exit 1
  fi
done

DEV_ISOLATION_MODE="${DEV_ISOLATION_MODE:-strict}"
DEV_EXTERNAL_SERVICES="${DEV_EXTERNAL_SERVICES:-disabled}"
DEV_CLONE_SUBSCRIPTIONS="${DEV_CLONE_SUBSCRIPTIONS:-preserve}"
CLONE_DB="${CLONE_DB:-0}"
case "${DEV_ISOLATION_MODE}" in strict|shared) ;; *) echo 'DEV_ISOLATION_MODE must be strict or shared.' >&2; exit 1 ;; esac
case "${DEV_EXTERNAL_SERVICES}" in disabled|enabled) ;; *) echo 'DEV_EXTERNAL_SERVICES must be disabled or enabled.' >&2; exit 1 ;; esac
case "${DEV_CLONE_SUBSCRIPTIONS}" in preserve|strip) ;; *) echo 'DEV_CLONE_SUBSCRIPTIONS must be preserve or strip.' >&2; exit 1 ;; esac
case "${CLONE_DB}" in 0|1) ;; *) echo 'CLONE_DB must be 0 or 1.' >&2; exit 1 ;; esac

# Shared mode is an explicit compatibility choice. Missing identity variables
# inherit production's values so an existing deployment needs only the mode
# switch; individually distinct resources still receive their stronger checks.
if [ "${DEV_ISOLATION_MODE}" = shared ]; then
  DEV_APP_USER="${DEV_APP_USER:-${APP_USER}}"
  DEV_DB_USER="${DEV_DB_USER:-${DB_USER}}"
  DEV_PHP_FPM_SERVICE="${DEV_PHP_FPM_SERVICE:-${PHP_FPM_SERVICE}}"
fi
for v in DEV_DIR DEV_HEALTH_URL PROD_DB DEV_DB DEV_APP_USER DEV_DB_USER DEV_PHP_FPM_SERVICE; do
  if [ -z "${!v:-}" ]; then
    echo "${v} is not set (the dev instance; see scripts/deploy.env.example)." >&2
    exit 1
  fi
done
for v in APP_USER DEV_APP_USER; do
  if ! printf '%s' "${!v}" | grep -Eq '^[A-Za-z_][A-Za-z0-9_-]{0,31}$'; then
    echo "${v} must be a plain Unix account name." >&2
    exit 1
  fi
done
if [ "${DEV_DIR}" = "${APP_DIR}" ] || [ "${DEV_DB}" = "${PROD_DB}" ]; then
  echo "Development and production paths and databases must be distinct." >&2
  exit 1
fi
if [ "${DEV_ISOLATION_MODE}" = strict ]; then
  if [ "${DEV_APP_USER}" = "${APP_USER}" ] || [ "${DEV_DB_USER}" = "${DB_USER}" ]; then
    echo 'Strict dev isolation requires distinct Unix and database users.' >&2
    exit 1
  fi
  if [ "${DEV_PHP_FPM_SERVICE}" = "${PHP_FPM_SERVICE}" ]; then
    echo 'Strict dev isolation requires a distinct PHP-FPM service/pool.' >&2
    exit 1
  fi
fi

if [ "${CLONE_DB}" = 1 ]; then
  for v in DB_USER DEV_DB_USER PROD_DB DEV_DB; do
    if ! printf '%s' "${!v:-}" | grep -Eq '^[A-Za-z0-9_-]{1,64}$'; then
      echo "${v} must be letters, digits, hyphens and underscores (set it in scripts/deploy.env)." >&2
      exit 1
    fi
  done
  if [ -z "${DEV_LOGIN_PASSWORD:-}" ] || [ "${#DEV_LOGIN_PASSWORD}" -lt 8 ] || [ "${#DEV_LOGIN_PASSWORD}" -gt 1024 ]; then
    echo 'DEV_LOGIN_PASSWORD must be a dev-only password of 8 to 1024 characters in the gitignored deploy.env.' >&2
    exit 1
  fi
  if [[ "${DEV_LOGIN_PASSWORD}" == *$'\n'* || "${DEV_LOGIN_PASSWORD}" == *$'\r'* ]]; then
    echo 'DEV_LOGIN_PASSWORD cannot contain a line break.' >&2
    exit 1
  fi
fi

if [ "${DEV_ISOLATION_MODE}" = shared ]; then
  {
    echo '!!! WARNING: DEV_ISOLATION_MODE=shared explicitly permits dev and production to share host identities.'
    echo '!!! Branch code may be able to read production files or databases; shared PHP-FPM is not stopped or restarted.'
    echo '!!! If PHP opcode timestamp checks are disabled, changed dev code may remain stale until an operator-chosen reload.'
  } >&2
fi
if [ "${DEV_EXTERNAL_SERVICES}" = enabled ]; then
  {
    echo '!!! WARNING: DEV_EXTERNAL_SERVICES=enabled permits configured dev integrations to make external calls.'
    echo '!!! Use test accounts where practical; mail, push, AI, maps, and connected calendars may have real side effects or cost.'
  } >&2
fi
if [ "${DEV_CLONE_SUBSCRIPTIONS}" = strip ]; then
  echo 'NOTICE: DEV_CLONE_SUBSCRIPTIONS=strip will turn cloned ICS subscriptions into inert local snapshots.' >&2
fi
HEALTH_URL="${DEV_HEALTH_URL}"

BRANCH="$(git -C "${ROOT_DIR}" branch --show-current)"
echo "== deploying branch '${BRANCH}' to dev (${DEV_DIR}) =="

# One shared ssh connection, loud failures (see the file for why).
source "${ROOT_DIR}/scripts/deploy-lib.sh"

DEV_FPM_PAUSED=0
deploy_failure_recovery() {
  [ "${DEV_FPM_PAUSED}" = 1 ] || return 0
  if rssh "sudo -n systemctl start ${dev_fpm_q}" >/dev/null 2>&1; then
    DEV_FPM_PAUSED=0
    echo 'Recovered the dedicated development PHP-FPM service after the failed deploy.' >&2
  else
    echo 'WARNING: could not resume the dedicated development PHP-FPM service after the failed deploy.' >&2
  fi
}

if [ "${SKIP_TESTS:-0}" != "1" ]; then
  echo "== pre-flight tests =="
  node --experimental-vm-modules "${ROOT_DIR}/web/tests/static.mjs" 2>/dev/null | tail -1
  # Several zones, not just Pacific: see scripts/deploy.sh.
  for zone in America/Los_Angeles UTC Europe/Berlin Asia/Kolkata Pacific/Auckland; do
    printf '%-20s ' "${zone}"
    TZ="${zone}" node "${ROOT_DIR}/web/tests/smoke.mjs" 2>/dev/null | tail -1
  done
  php "${ROOT_DIR}/server/tests/run.php" | tail -1
  bash "${ROOT_DIR}/scripts/tests/deploy-security.sh" | tail -1
fi

stage "connect"
ssh_open

stage "verify dev isolation"
printf -v app_dir_q '%q' "${APP_DIR}"
printf -v dev_dir_q '%q' "${DEV_DIR}"
printf -v app_user_q '%q' "${APP_USER}"
printf -v dev_app_user_q '%q' "${DEV_APP_USER}"
printf -v backup_dir_q '%q' "${BACKUP_DIR:-/var/backups/better-cal}"
printf -v prod_fpm_q '%q' "${PHP_FPM_SERVICE}"
printf -v dev_fpm_q '%q' "${DEV_PHP_FPM_SERVICE}"
printf -v prod_db_q '%q' "${PROD_DB}"
printf -v dev_db_q '%q' "${DEV_DB}"
printf -v db_user_q '%q' "${DB_USER:-}"
printf -v dev_db_user_q '%q' "${DEV_DB_USER}"
printf -v isolation_mode_q '%q' "${DEV_ISOLATION_MODE}"
printf -v external_services_q '%q' "${DEV_EXTERNAL_SERVICES}"
fpm_relation_file="$(mktemp "${TMPDIR:-/tmp}/bc-dev-fpm.XXXXXX")"
if ! rssh "sudo -n env APP_DIR=${app_dir_q} DEV_DIR=${dev_dir_q} APP_USER=${app_user_q} DEV_APP_USER=${dev_app_user_q} BACKUP_DIR=${backup_dir_q} PHP_FPM_SERVICE=${prod_fpm_q} DEV_PHP_FPM_SERVICE=${dev_fpm_q} PROD_DB=${prod_db_q} DEV_DB=${dev_db_q} DB_USER=${db_user_q} DEV_DB_USER=${dev_db_user_q} DEV_ISOLATION_MODE=${isolation_mode_q} DEV_EXTERNAL_SERVICES=${external_services_q} bash -s" >"${fpm_relation_file}" <<'EOF'
set -euo pipefail
if [ -L "${APP_DIR}" ] || [ ! -d "${APP_DIR}" ] || [ -L "${DEV_DIR}" ] || [ ! -d "${DEV_DIR}" ]; then
  echo 'Production and development application roots must be real directories, not symbolic links.' >&2
  exit 1
fi
same_uid=0
[ "$(id -u "${APP_USER}")" = "$(id -u "${DEV_APP_USER}")" ] && same_uid=1
if [ "${DEV_ISOLATION_MODE}" = strict ] && [ "${same_uid}" = 1 ]; then
  echo 'Strict development isolation requires a distinct Unix identity.' >&2
  exit 1
fi
if ! sudo -n -u "${DEV_APP_USER}" -- test -d "${DEV_DIR}" \
  || ! sudo -n -u "${DEV_APP_USER}" -- test -w "${DEV_DIR}"; then
  echo 'DEV_DIR must be pre-provisioned and writable only by the dev application user.' >&2
  exit 1
fi
if [ "${same_uid}" = 0 ]; then
  if sudo -n -u "${DEV_APP_USER}" -- test -r "${APP_DIR}/.env"; then
    echo 'The dev application user can read the production environment file.' >&2
    exit 1
  fi
  if sudo -n -u "${APP_USER}" -- test -r "${DEV_DIR}/.env"; then
    echo 'The production application user can read the dev environment file.' >&2
    exit 1
  fi
  if [ -e "${BACKUP_DIR}" ] && sudo -n -u "${DEV_APP_USER}" -- test -r "${BACKUP_DIR}"; then
    echo 'The dev application user can read the production backup directory.' >&2
    exit 1
  fi
fi
if [ "$(systemctl show --property=LoadState --value "${PHP_FPM_SERVICE}")" != loaded ] \
  || [ "$(systemctl show --property=LoadState --value "${DEV_PHP_FPM_SERVICE}")" != loaded ]; then
  echo 'The configured production and development PHP-FPM services must be loaded.' >&2
  exit 1
fi
prod_fpm_id="$(systemctl show --property=Id --value "${PHP_FPM_SERVICE}")"
dev_fpm_id="$(systemctl show --property=Id --value "${DEV_PHP_FPM_SERVICE}")"
if [ -z "${prod_fpm_id}" ] || [ -z "${dev_fpm_id}" ]; then
  echo 'Could not resolve the configured PHP-FPM service identities.' >&2
  exit 1
fi
fpm_relation=distinct
if [ "${prod_fpm_id}" = "${dev_fpm_id}" ]; then
  fpm_relation=shared
  if [ "${DEV_ISOLATION_MODE}" = strict ]; then
    echo 'Strict development isolation requires a distinct PHP-FPM service.' >&2
    exit 1
  fi
fi

env_value() {
  local file="$1" key="$2" value
  value="$(awk -F= -v wanted="${key}" '{
    found=$1; gsub(/^[[:space:]]+|[[:space:]]+$/, "", found)
    if (found == wanted) {
      sub(/^[^=]*=/, ""); gsub(/^[[:space:]]+|[[:space:]]+$/, ""); print; exit
    }
  }' "${file}")"
  value="${value#\"}"; value="${value%\"}"
  value="${value#\'}"; value="${value%\'}"
  printf '%s' "${value}"
}
for file in "${APP_DIR}/.env" "${DEV_DIR}/.env"; do
  if [ -L "${file}" ] || [ ! -f "${file}" ] || [ ! -r "${file}" ]; then
    echo 'Each environment must have a readable regular .env file, not a symlink.' >&2
    exit 1
  fi
done
prod_user="$(env_value "${APP_DIR}/.env" BETTERCAL_DB_USER)"
dev_user="$(env_value "${DEV_DIR}/.env" BETTERCAL_DB_USER)"
prod_dsn="$(env_value "${APP_DIR}/.env" BETTERCAL_DB_DSN)"
dev_dsn="$(env_value "${DEV_DIR}/.env" BETTERCAL_DB_DSN)"
prod_pass="$(env_value "${APP_DIR}/.env" BETTERCAL_DB_PASS)"
dev_pass="$(env_value "${DEV_DIR}/.env" BETTERCAL_DB_PASS)"
prod_secret="$(env_value "${APP_DIR}/.env" BETTERCAL_SESSION_SECRET)"
dev_secret="$(env_value "${DEV_DIR}/.env" BETTERCAL_SESSION_SECRET)"
if [ "${prod_user}" != "${DB_USER}" ] || [ "${dev_user}" != "${DEV_DB_USER}" ] \
  || [ -z "${prod_user}" ] || [ -z "${dev_user}" ]; then
  echo 'The two .env files do not select their configured database users.' >&2
  exit 1
fi
if [ "${DEV_ISOLATION_MODE}" = strict ] && [ "${prod_user}" = "${dev_user}" ]; then
  echo 'Strict development isolation requires distinct database users.' >&2
  exit 1
fi
case "${prod_dsn}" in *"dbname=${PROD_DB}"*) ;; *) echo 'Production .env does not select PROD_DB.' >&2; exit 1 ;; esac
case "${dev_dsn}" in *"dbname=${DEV_DB}"*) ;; *) echo 'Dev .env does not select DEV_DB.' >&2; exit 1 ;; esac
if [ -z "${prod_pass}" ] || [ -z "${dev_pass}" ]; then
  echo 'Development and production database passwords must be non-empty.' >&2
  exit 1
fi
if [ "${prod_user}" != "${dev_user}" ] && [ "${prod_pass}" = "${dev_pass}" ]; then
  echo 'Distinct development and production database users must not share a password.' >&2
  exit 1
fi
if [ -z "${prod_secret}" ] || [ -z "${dev_secret}" ] || [ "${prod_secret}" = "${dev_secret}" ]; then
  echo 'Development and production session secrets must be non-empty and distinct.' >&2
  exit 1
fi
if [ "${DEV_EXTERNAL_SERVICES}" = disabled ]; then
  # Refuse values that can activate a transport. Harmless companion defaults
  # such as ports, model names or a VAPID subject do not force the opt-in.
  for key in BETTERCAL_GEMINI_API_KEY BETTERCAL_GOOGLE_CLIENT_ID BETTERCAL_GOOGLE_CLIENT_SECRET BETTERCAL_MAPTILER_KEY BETTERCAL_SMTP_HOST BETTERCAL_RSVP_SMTP_HOST BETTERCAL_IMAP_HOST BETTERCAL_VAPID_PUBLIC BETTERCAL_VAPID_PRIVATE; do
    if [ -n "$(env_value "${DEV_DIR}/.env" "${key}")" ]; then
      echo "Dev .env configures an external service while DEV_EXTERNAL_SERVICES=disabled (${key})." >&2
      exit 1
    fi
  done
fi
printf '%s\n' "${fpm_relation}"
EOF
then
  rm -f "${fpm_relation_file}"
  exit 1
fi
fpm_relation="$(tr -d '\r\n' < "${fpm_relation_file}")"
rm -f "${fpm_relation_file}"
case "${fpm_relation}" in
  shared|distinct) ;;
  *) echo 'Could not determine whether the development PHP-FPM service is shared.' >&2; exit 1 ;;
esac

# Configured database usernames do not necessarily identify the account that
# MySQL actually authenticated (proxy and anonymous-account rules can differ).
# Resolve CURRENT_USER() through each application environment before any
# account-control statement, returning only a digest to the deploy log.
resolve_db_identity() {
  local run_user_q="$1" app_root_q="$2" expected_db_q="$3" forbidden_db_q="$4" expected_user_q="$5"
  rssh "sudo -n -u ${run_user_q} env APP_ROOT=${app_root_q} EXPECTED_DB=${expected_db_q} FORBIDDEN_DB=${forbidden_db_q} EXPECTED_DB_USER=${expected_user_q} REQUIRE_DB_ISOLATION=0 REPORT_DB_PRINCIPAL=1 php" \
    < "${ROOT_DIR}/scripts/check-db-boundary.php"
}
if ! prod_db_identity="$(resolve_db_identity "${app_user_q}" "${app_dir_q}" "${prod_db_q}" "${dev_db_q}" "${db_user_q}")" \
  || ! dev_db_identity="$(resolve_db_identity "${dev_app_user_q}" "${dev_dir_q}" "${dev_db_q}" "${prod_db_q}" "${dev_db_user_q}")"; then
  exit 1
fi
if [[ ! "${prod_db_identity}" =~ ^[0-9a-f]{64}\ [01]$ ]]; then
  echo 'Could not validate the effective production database identity.' >&2
  exit 1
fi
read -r prod_db_principal prod_db_name_matches <<< "${prod_db_identity}"
if [[ ! "${dev_db_identity}" =~ ^[0-9a-f]{64}\ [01]$ ]]; then
  echo 'Could not validate the effective development database identity.' >&2
  exit 1
fi
read -r dev_db_principal dev_db_name_matches <<< "${dev_db_identity}"
db_identities_shared=0
if [ "${prod_db_principal}" = "${dev_db_principal}" ]; then
  db_identities_shared=1
  if [ "${DEV_ISOLATION_MODE}" = strict ]; then
    echo 'Strict development isolation requires distinct effective database identities.' >&2
    exit 1
  fi
elif [ "${prod_db_name_matches}" != 1 ] || [ "${dev_db_name_matches}" != 1 ]; then
  echo 'A distinct effective database identity does not match its configured account name.' >&2
  exit 1
fi

# Database accounts are pre-provisioned operator state, not something a deploy
# should rewrite. Prove a dedicated pair is isolated before any service or
# schema mutation; the same check runs again after publication/migration.
if [ "${db_identities_shared}" = 0 ]; then
  stage "verify pre-deploy database isolation"
  rssh "sudo -n -u ${dev_app_user_q} env APP_ROOT=${dev_dir_q} EXPECTED_DB=${dev_db_q} FORBIDDEN_DB=${prod_db_q} REQUIRE_DB_ISOLATION=1 php" \
    < "${ROOT_DIR}/scripts/check-db-boundary.php"
  rssh "sudo -n -u ${app_user_q} env APP_ROOT=${app_dir_q} EXPECTED_DB=${prod_db_q} FORBIDDEN_DB=${dev_db_q} REQUIRE_DB_ISOLATION=1 php" \
    < "${ROOT_DIR}/scripts/check-db-boundary.php"
fi

# A distinct dev service can be paused safely. Shared mode must never stop or
# restart a service that also serves production.
if [ "${fpm_relation}" = distinct ]; then
  stage "pause dev request traffic"
  DEPLOY_TOUCHED=1
  DEV_FPM_PAUSED=1
  rssh "sudo -n systemctl stop ${dev_fpm_q}"
fi

if [ "${CLONE_DB}" = 1 ]; then
  stage "re-cloning ${PROD_DB} into ${DEV_DB}"
  # A shared FPM service cannot be stopped without interrupting production.
  # Never expose a raw production dump to the live dev schema: import it into
  # an unpredictable root-only staging schema, sanitize and verify it there,
  # and only then publish the sanitized snapshot to DEV_DB.
  clone_suffix="$(php -r 'echo bin2hex(random_bytes(12));')"
  clone_stage_db="bc_dev_stage_${clone_suffix}"
  clone_helper="$(rssh 'sudo -n mktemp /tmp/bc-dev-clone.XXXXXXXX.php')"
  if [[ ! "${clone_helper}" =~ ^/tmp/bc-dev-clone\.[A-Za-z0-9]{8}\.php$ ]]; then
    echo 'Could not create a safe remote clone helper.' >&2
    exit 1
  fi
  printf -v clone_helper_q '%q' "${clone_helper}"
  if ! rssh "sudo -n install -m 0700 -o root -g root /dev/stdin ${clone_helper_q}" \
    < "${ROOT_DIR}/scripts/sanitize-dev-clone.php"; then
    rssh "sudo -n rm -f -- ${clone_helper_q}" >/dev/null 2>&1 || true
    exit 1
  fi
  printf -v clone_stage_db_q '%q' "${clone_stage_db}"
  printf -v strip_subscriptions_q '%q' "$([ "${DEV_CLONE_SUBSCRIPTIONS}" = strip ] && printf 1 || printf 0)"
  DEPLOY_TOUCHED=1

  # The MySQL root and dev-login passwords never go on a command line. The
  # remote root shell reads them from stdin and exposes them only to its child
  # sanitizer/database clients for the lifetime of this clone operation.
  set +e
  { printf '%s\n' "${MYSQL_ROOT_PASSWORD:-}"; printf '%s\n' "${DEV_LOGIN_PASSWORD}"; cat <<'EOF'; } | rssh "sudo -n env PROD_DB=${prod_db_q} DEV_DB=${dev_db_q} PROD_APP_ROOT=${app_dir_q} DEV_APP_ROOT=${dev_dir_q} STAGING_DB=${clone_stage_db_q} CLONE_HELPER=${clone_helper_q} STRIP_DEV_SUBSCRIPTIONS=${strip_subscriptions_q} bash -c 'IFS= read -r MYSQL_ROOT_PASSWORD; IFS= read -r DEV_LOGIN_PASSWORD; export MYSQL_ROOT_PASSWORD DEV_LOGIN_PASSWORD; exec bash -s'"
set -euo pipefail
if [ -z "${MYSQL_ROOT_PASSWORD}" ]; then
  MYSQL_ROOT_PASSWORD="$(clpctl db:show:master-credentials | awk -F'|' '/Password/ {gsub(/ /,"",$3); print $3}')"
fi
if [ -z "${MYSQL_ROOT_PASSWORD}" ]; then
  echo 'Could not obtain the MySQL administrative password.' >&2
  exit 1
fi
export MYSQL_PWD="${MYSQL_ROOT_PASSWORD}"
stage_created=0
cleanup_clone() {
  if [ "${stage_created}" = 1 ]; then
    mysql -h 127.0.0.1 -u root -e "DROP DATABASE IF EXISTS \`${STAGING_DB}\`" >/dev/null 2>&1 || true
  fi
  rm -f -- "${CLONE_HELPER}"
}
trap cleanup_clone EXIT

if [ -L "${CLONE_HELPER}" ] || [ ! -f "${CLONE_HELPER}" ] \
  || [ "$(stat -c %u "${CLONE_HELPER}")" != 0 ] || [ "$(stat -c %a "${CLONE_HELPER}")" != 700 ]; then
  echo 'The development clone sanitizer is not a private root-owned file.' >&2
  exit 1
fi
mysql -h 127.0.0.1 -u root -e "CREATE DATABASE \`${STAGING_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
stage_created=1
BETTERCAL_VERIFY_DEV_CLONE_STAGE=1 php "${CLONE_HELPER}"
mysqldump -h 127.0.0.1 -u root --single-transaction "${PROD_DB}" | mysql -h 127.0.0.1 -u root "${STAGING_DB}"
BETTERCAL_RUN_DEV_CLONE_SANITIZER=1 php "${CLONE_HELPER}"

mysql -h 127.0.0.1 -u root -e "DROP DATABASE IF EXISTS \`${DEV_DB}\`; CREATE DATABASE \`${DEV_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysqldump -h 127.0.0.1 -u root --single-transaction "${STAGING_DB}" | mysql -h 127.0.0.1 -u root "${DEV_DB}"
echo 'Published sanitized development clone.'
EOF
  clone_status=$?
  set -e
  if [ "${clone_status}" -ne 0 ]; then
    # The remote EXIT trap normally removes both. This second attempt handles
    # a shell that exited before installing its trap or lost its input stream.
    # Its only database target is the validated random staging name.
    set +e
    { printf '%s\n' "${MYSQL_ROOT_PASSWORD:-}"; cat <<'EOF'; } | rssh "sudo -n env STAGING_DB=${clone_stage_db_q} CLONE_HELPER=${clone_helper_q} bash -c 'IFS= read -r MYSQL_ROOT_PASSWORD; export MYSQL_ROOT_PASSWORD; exec bash -s'"
set -euo pipefail
if [[ ! "${STAGING_DB}" =~ ^bc_dev_stage_[0-9a-f]{24}$ ]]; then exit 1; fi
if [[ ! "${CLONE_HELPER}" =~ ^/tmp/bc-dev-clone\.[A-Za-z0-9]{8}\.php$ ]]; then exit 1; fi
if [ -z "${MYSQL_ROOT_PASSWORD}" ]; then
  MYSQL_ROOT_PASSWORD="$(clpctl db:show:master-credentials | awk -F'|' '/Password/ {gsub(/ /,"",$3); print $3}')"
fi
cleanup_status=0
if [ -n "${MYSQL_ROOT_PASSWORD}" ]; then
  export MYSQL_PWD="${MYSQL_ROOT_PASSWORD}"
  mysql -h 127.0.0.1 -u root -e "DROP DATABASE IF EXISTS \`${STAGING_DB}\`" >/dev/null 2>&1 || cleanup_status=1
else
  cleanup_status=1
fi
rm -f -- "${CLONE_HELPER}" || cleanup_status=1
exit "${cleanup_status}"
EOF
    cleanup_status=$?
    set -e
    if [ "${cleanup_status}" -ne 0 ]; then
      echo 'WARNING: automatic cleanup of the private clone staging resources failed; inspect them before retrying.' >&2
    fi
    exit "${clone_status}"
  fi
fi

# Always prove the selected schema before branch code executes. A dedicated
# database identity must also be unable to see the other schema; explicit
# shared mode records the intended schema without pretending it is isolated.
require_db_isolation=1
[ "${db_identities_shared}" = 1 ] && require_db_isolation=0
printf -v require_db_isolation_q '%q' "${require_db_isolation}"
stage "verify effective database boundary"
rssh "sudo -n -u ${dev_app_user_q} env APP_ROOT=${dev_dir_q} EXPECTED_DB=${dev_db_q} FORBIDDEN_DB=${prod_db_q} REQUIRE_DB_ISOLATION=${require_db_isolation_q} php" \
  < "${ROOT_DIR}/scripts/check-db-boundary.php"
rssh "sudo -n -u ${app_user_q} env APP_ROOT=${app_dir_q} EXPECTED_DB=${prod_db_q} FORBIDDEN_DB=${dev_db_q} REQUIRE_DB_ISOLATION=${require_db_isolation_q} php" \
  < "${ROOT_DIR}/scripts/check-db-boundary.php"

stage "rsync code"
# Same tracked-file manifest as production, received as the dev user.
deploy_rsync "${DEV_DIR}" '' "${DEV_APP_USER}"
reconcile_release_plugins "${DEV_DIR}" '' "${DEV_APP_USER}"

stage "composer + migrate (dev)"
rssh "DEV_DIR='${DEV_DIR}' DEV_APP_USER='${DEV_APP_USER}' bash -s" <<'EOF'
set -euo pipefail
if sudo -n -u "${DEV_APP_USER}" -- test -L "${DEV_DIR}/.env" \
  || ! sudo -n -u "${DEV_APP_USER}" -- test -f "${DEV_DIR}/.env" \
  || ! sudo -n -u "${DEV_APP_USER}" -- test -r "${DEV_DIR}/.env"; then
  echo "${DEV_DIR}/.env must be a readable regular file, not a symlink; provision it before deploying." >&2
  exit 1
fi
sudo -u "${DEV_APP_USER}" bash -c "cd ${DEV_DIR}/server && composer install --no-dev --quiet --no-interaction"
sudo -u "${DEV_APP_USER}" php "${DEV_DIR}/server/bin/migrate.php"
EOF

disable_missing_plugins "${DEV_DIR}" "${DEV_APP_USER}"
rssh "sudo -n -u ${dev_app_user_q} env APP_ROOT=${dev_dir_q} EXPECTED_DB=${dev_db_q} FORBIDDEN_DB=${prod_db_q} REQUIRE_DB_ISOLATION=${require_db_isolation_q} php" \
  < "${ROOT_DIR}/scripts/check-db-boundary.php"
rssh "sudo -n -u ${app_user_q} env APP_ROOT=${app_dir_q} EXPECTED_DB=${prod_db_q} FORBIDDEN_DB=${dev_db_q} REQUIRE_DB_ISOLATION=${require_db_isolation_q} php" \
  < "${ROOT_DIR}/scripts/check-db-boundary.php"
# Force the next app-shell response to create a fresh cache file, then verify
# its owner. This checks the effective PHP worker identity, not merely the
# configured service name. A dedicated dev service resumes only after every
# sanitation, migration and database-boundary check passes.
rssh "sudo -n env DEV_DIR=${dev_dir_q} DEV_APP_USER=${dev_app_user_q} bash -s" <<'EOF'
set -euo pipefail
cache_dir="${DEV_DIR}/.runtime-cache"
cache_file="${cache_dir}/app-shell.json"
expected_uid="$(id -u "${DEV_APP_USER}")"
if [ -L "${cache_dir}" ] || { [ -e "${cache_dir}" ] && { [ ! -d "${cache_dir}" ] \
  || [ "$(stat -c %u "${cache_dir}")" != "${expected_uid}" ] || [ "$(stat -c %a "${cache_dir}")" != 700 ]; }; }; then
  echo 'The dev app-shell cache directory is not a private directory owned by the dev application identity.' >&2
  exit 1
fi
sudo -n -u "${DEV_APP_USER}" -- rm -f "${cache_file}"
EOF
if [ "${fpm_relation}" = distinct ]; then
  rssh "sudo -n systemctl restart ${dev_fpm_q}"
  DEV_FPM_PAUSED=0
fi

stage "smoke"
sleep 1
curl -fsS --max-time 10 "${HEALTH_URL}"
echo
curl -fsS --max-time 10 "${HEALTH_URL%/api/v1/health}/" >/dev/null
rssh "sudo -n env DEV_DIR=${dev_dir_q} DEV_APP_USER=${dev_app_user_q} bash -s" <<'EOF'
set -euo pipefail
cache_dir="${DEV_DIR}/.runtime-cache"
cache_file="${cache_dir}/app-shell.json"
expected_uid="$(id -u "${DEV_APP_USER}")"
if [ -L "${cache_dir}" ] || [ ! -d "${cache_dir}" ] || [ "$(stat -c %u "${cache_dir}")" != "${expected_uid}" ] \
  || [ "$(stat -c %a "${cache_dir}")" != 700 ] || [ -L "${cache_file}" ] || [ ! -f "${cache_file}" ] \
  || [ "$(stat -c %u "${cache_file}")" != "${expected_uid}" ] || [ "$(stat -c %a "${cache_file}")" != 600 ]; then
  echo 'The dev HTTP worker did not create the app-shell cache as the dev application identity.' >&2
  exit 1
fi
EOF
echo "Dev deploy OK -> ${HEALTH_URL%/api/v1/health}"
