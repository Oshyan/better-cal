#!/bin/bash
set -euo pipefail

# This script is sent from the reviewed deploy snapshot to the server and run
# through sudo before rsync changes the application. Root owns only the backup
# destination. Every read from the app-controlled tree, including .env and the
# database, runs as APP_USER so replacing an app path cannot turn a deploy into
# a privileged file read.

PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
BACKUP_APP_TEMP=''
BACKUP_DB_TEMP=''

backup_fail() {
  echo "backup refused: $*" >&2
  return 1
}

backup_uid() {
  stat -c '%u' -- "$1" 2>/dev/null || stat -f '%u' "$1"
}

backup_gid() {
  stat -c '%g' -- "$1" 2>/dev/null || stat -f '%g' "$1"
}

backup_mode() {
  stat -c '%a' -- "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

backup_assert_secure_dir() {
  local path="$1" expected_uid="$2" mode
  case "${path}" in
    /) ;;
    */)
      backup_fail "${path} must not have a trailing slash"
      return 1
      ;;
  esac
  if [ -L "${path}" ]; then
    backup_fail "${path} must not be a symbolic link"
    return 1
  fi
  if [ ! -d "${path}" ]; then
    backup_fail "${path} must be a directory"
    return 1
  fi
  if [ "$(backup_uid "${path}")" != "${expected_uid}" ]; then
    backup_fail "${path} must be owned by uid ${expected_uid}"
    return 1
  fi
  mode="$(backup_mode "${path}")"
  if (( (8#${mode} & 0022) != 0 )); then
    backup_fail "${path} must not be writable by its group or other users"
    return 1
  fi
}

backup_assert_private_dir() {
  local path="$1" expected_uid="$2" expected_gid="$3"
  backup_assert_secure_dir "${path}" "${expected_uid}" || return 1
  if [ "$(backup_gid "${path}")" != "${expected_gid}" ]; then
    backup_fail "${path} must be owned by gid ${expected_gid}"
    return 1
  fi
  if [ "$(backup_mode "${path}")" != 700 ]; then
    backup_fail "${path} must have mode 0700"
    return 1
  fi
}

backup_assert_private_file() {
  local path="$1" expected_uid="$2" expected_gid="$3"
  if [ -L "${path}" ] || [ ! -f "${path}" ]; then
    backup_fail "${path} must be a regular file, not a symbolic link"
    return 1
  fi
  if [ "$(backup_uid "${path}")" != "${expected_uid}" ] \
    || [ "$(backup_gid "${path}")" != "${expected_gid}" ]; then
    backup_fail "${path} must have the expected private owner"
    return 1
  fi
  if [ "$(backup_mode "${path}")" != 600 ]; then
    backup_fail "${path} must have mode 0600"
    return 1
  fi
}

backup_publish() {
  local temporary="$1" final="$2" expected_uid="${3:-$(id -u)}" expected_gid="${4:-$(id -g)}"
  if [ -e "${final}" ] || [ -L "${final}" ]; then
    backup_fail "refusing to replace existing output ${final}"
    return 1
  fi
  chmod 600 "${temporary}"
  mv "${temporary}" "${final}"
  backup_assert_private_file "${final}" "${expected_uid}" "${expected_gid}"
}

backup_cleanup_temporaries() {
  [ -z "${BACKUP_APP_TEMP}" ] || rm -f -- "${BACKUP_APP_TEMP}"
  [ -z "${BACKUP_DB_TEMP}" ] || rm -f -- "${BACKUP_DB_TEMP}"
}

backup_db_kind() {
  sudo -n -u "${APP_USER}" -- bash -s -- "${APP_DIR}/.env" <<'APP_DB_KIND'
set -euo pipefail
env_file="$1"
dsn="$(awk -F= '$1 == "BETTERCAL_DB_DSN" { value=substr($0, index($0,"=")+1); if (length(value)>=2 && ((substr(value,1,1)=="\"" || substr(value,1,1)=="\047") && substr(value,length(value),1)==substr(value,1,1))) value=substr(value,2,length(value)-2); print value; exit }' "${env_file}")"
case "${dsn}" in
  mysql:*) printf 'mysql\n' ;;
  sqlite:*) echo 'deploy backups require MySQL or MariaDB; back up SQLite separately' >&2; exit 1 ;;
  *) echo 'BETTERCAL_DB_DSN is not a MySQL/MariaDB DSN' >&2; exit 1 ;;
esac
APP_DB_KIND
}

backup_write_mysql() {
  sudo -n -u "${APP_USER}" -- bash -s -- "${APP_DIR}/.env" <<'APP_MYSQL'
set -euo pipefail
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
env_file="$1"
envval() {
  awk -F= -v wanted="$1" '$1 == wanted { value=substr($0, index($0,"=")+1); if (length(value)>=2 && ((substr(value,1,1)=="\"" || substr(value,1,1)=="\047") && substr(value,length(value),1)==substr(value,1,1))) value=substr(value,2,length(value)-2); print value; exit }' "${env_file}"
}
dsn="$(envval BETTERCAL_DB_DSN)"
db="$(printf '%s' "${dsn}" | sed -n 's/.*dbname=\([^;]*\).*/\1/p')"
host="$(printf '%s' "${dsn}" | sed -n 's/.*host=\([^;]*\).*/\1/p')"
db_user="$(envval BETTERCAL_DB_USER)"
db_pass="$(envval BETTERCAL_DB_PASS)"
[ -n "${db}" ] || { echo 'BETTERCAL_DB_DSN has no database name' >&2; exit 1; }
[ -n "${db_user}" ] || { echo 'BETTERCAL_DB_USER is empty' >&2; exit 1; }
export MYSQL_PWD="${db_pass}"
exec mysqldump --no-defaults --single-transaction --no-tablespaces \
  -h "${host:-localhost}" -u "${db_user}" -- "${db}"
APP_MYSQL
}

backup_prune() {
  local kind="$1" keep=0 entry file
  while IFS= read -r entry; do
    file="${entry#* }"
    keep=$((keep + 1))
    if [ "${keep}" -gt 5 ]; then
      rm -f -- "${file}"
    fi
  done < <(find "${BACKUP_DIR}" -maxdepth 1 -type f \
    -name "bettercal-${kind}-2*" -printf '%T@ %p\n' | sort -nr)
}

backup_main() {
  : "${APP_DIR:?APP_DIR is required}"
  : "${APP_USER:?APP_USER is required}"
  : "${BACKUP_DIR:?BACKUP_DIR is required}"

  if [ "$(id -u)" -ne 0 ]; then
    backup_fail "this helper must run as root"
    return 1
  fi
  case "${APP_USER}" in
    ''|-*|*[!A-Za-z0-9_-]*)
      backup_fail "APP_USER is not a plain Unix user name"
      return 1
      ;;
  esac
  if ! id "${APP_USER}" >/dev/null 2>&1; then
    backup_fail "APP_USER does not exist"
    return 1
  fi
  if [ "$(id -u "${APP_USER}")" -eq 0 ]; then
    backup_fail "APP_USER must not be root"
    return 1
  fi

  # Keep the privileged namespace deliberately narrow. BACKUP_DIR may select
  # one plain child beneath /home or /var/backups, both root-controlled on the
  # supported Debian-style hosts, never an app-owned parent or nested path.
  local backup_parent backup_name
  backup_parent="$(dirname "${BACKUP_DIR}")"
  case "${backup_parent}" in
    /home|/var/backups) ;;
    *)
      backup_fail "BACKUP_DIR must be a direct child of /home or /var/backups"
      return 1
      ;;
  esac
  backup_name="$(basename "${BACKUP_DIR}")"
  if [ "${BACKUP_DIR}" != "${backup_parent}/${backup_name}" ]; then
    backup_fail "BACKUP_DIR must use one canonical path without a trailing or repeated slash"
    return 1
  fi
  case "${backup_name}" in
    ''|.|..|*/*|*[!A-Za-z0-9._-]*)
      backup_fail "BACKUP_DIR must end in one plain directory name"
      return 1
      ;;
  esac

  if [ "${backup_parent}" = /home ]; then
    backup_assert_secure_dir /home 0
  else
    backup_assert_secure_dir /var 0
    if [ -L /var/backups ]; then
      backup_fail "/var/backups must not be a symbolic link"
      return 1
    elif [ ! -e /var/backups ]; then
      install -d -o root -g root -m 755 /var/backups
    fi
    backup_assert_secure_dir /var/backups 0
  fi
  if [ -L "${BACKUP_DIR}" ]; then
    backup_fail "${BACKUP_DIR} must not be a symbolic link"
    return 1
  elif [ ! -e "${BACKUP_DIR}" ]; then
    install -d -o root -g root -m 700 "${BACKUP_DIR}"
  fi
  backup_assert_private_dir "${BACKUP_DIR}" 0 0

  # Serialize deployments before names are chosen. The directory is already a
  # validated root-only namespace, so APP_USER cannot replace the lock or race
  # the existence-check/rename publication sequence.
  umask 077
  local lock_file="${BACKUP_DIR}/.deploy.lock"
  if [ -L "${lock_file}" ] || { [ -e "${lock_file}" ] && [ ! -f "${lock_file}" ]; }; then
    backup_fail "${lock_file} must be a regular file, not a symbolic link"
    return 1
  fi
  : > "${lock_file}"
  chmod 600 "${lock_file}"
  exec 9<> "${lock_file}"
  if ! flock -n 9; then
    backup_fail "another Better-Cal backup is already running"
    return 1
  fi

  # A symlinked .env is never needed by the reference deploy. These checks are
  # intentionally made as APP_USER; even a rename race can therefore grant no
  # authority beyond what the application already has.
  if sudo -n -u "${APP_USER}" -- test -L "${APP_DIR}/.env"; then
    backup_fail "${APP_DIR}/.env must be a regular file, not a symbolic link"
    return 1
  fi
  if ! sudo -n -u "${APP_USER}" -- test -f "${APP_DIR}/.env"; then
    backup_fail "${APP_DIR}/.env must be a regular file"
    return 1
  fi
  if ! sudo -n -u "${APP_USER}" -- test -r "${APP_DIR}/.env"; then
    backup_fail "${APP_DIR}/.env must be readable by ${APP_USER}"
    return 1
  fi

  local timestamp app_final db_final db_kind
  timestamp="$(date +%Y-%m-%dT%H%M%S%z)"
  app_final="${BACKUP_DIR}/bettercal-app-${timestamp}.tar.gz"
  BACKUP_APP_TEMP="$(mktemp "${BACKUP_DIR}/.bettercal-app-${timestamp}.XXXXXX.tmp")"
  BACKUP_DB_TEMP=''
  trap backup_cleanup_temporaries EXIT

  sudo -n -u "${APP_USER}" -- tar czf - --exclude=.git \
    -C "$(dirname "${APP_DIR}")" "$(basename "${APP_DIR}")" > "${BACKUP_APP_TEMP}"
  db_kind="$(backup_db_kind)"
  case "${db_kind}" in
    mysql)
      db_final="${BACKUP_DIR}/bettercal-db-${timestamp}.sql.gz"
      BACKUP_DB_TEMP="$(mktemp "${BACKUP_DIR}/.bettercal-db-${timestamp}.XXXXXX.tmp")"
      backup_write_mysql | sudo -n -u "${APP_USER}" -- gzip -c > "${BACKUP_DB_TEMP}"
      if ! zcat "${BACKUP_DB_TEMP}" | tail -1 | grep -q 'Dump completed'; then
        backup_fail "database dump is incomplete"
        return 1
      fi
      ;;
    *)
      backup_fail "BETTERCAL_DB_DSN is not a MySQL/MariaDB DSN"
      return 1
      ;;
  esac

  backup_publish "${BACKUP_APP_TEMP}" "${app_final}" 0 0
  BACKUP_APP_TEMP=''
  backup_publish "${BACKUP_DB_TEMP}" "${db_final}" 0 0
  BACKUP_DB_TEMP=''
  backup_prune app
  backup_prune db
  printf '  %s\n  %s\n' "$(basename "${app_final}")" "$(basename "${db_final}")"
  trap - EXIT
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  backup_main "$@"
fi
