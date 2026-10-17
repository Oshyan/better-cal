# Shared deployment data boundary. Sourcing this file has no side effects.
# Bash 3.2 compatible: the local deploy tools also run on macOS.

deploy_config_error() { printf 'Deployment configuration: %s\n' "$*" >&2; return 1; }

deploy_path_valid() {
  case "$1" in
    /*) ;;
    *) return 1 ;;
  esac
  case "$1" in
    /|*/|*//*|*/./*|*/../*|*/.|*/..|*[!A-Za-z0-9_./-]*) return 1 ;;
  esac
}

deploy_value_valid() {
  local key="$1" value="$2"
  # Optional empty values are resolved by the owning script's defaults/requirements.
  [ -n "${value}" ] || return 0
  case "${value}" in *$'\r'*|*$'\n'*) return 1 ;; esac
  case "${key}" in
    REMOTE) [[ "${value}" =~ ^([A-Za-z_][A-Za-z0-9_-]*@)?[A-Za-z0-9][A-Za-z0-9._-]*$ ]] ;;
    APP_USER|DEV_APP_USER) [[ "${value}" =~ ^[A-Za-z_][A-Za-z0-9_-]{0,31}$ ]] ;;
    APP_DIR|DEV_DIR|DOCROOT|DEPLOY_STATE_ROOT) deploy_path_valid "${value}" ;;
    BACKUP_DIR)
      deploy_path_valid "${value}" && [[ "${value}" =~ ^/(home|var/backups)/[A-Za-z0-9._-]+$ ]] ;;
    PHP_FPM_SERVICE|DEV_PHP_FPM_SERVICE) [[ "${value}" =~ ^[A-Za-z0-9_][A-Za-z0-9_.@:-]*$ ]] ;;
    DB_USER|DEV_DB_USER|PROD_DB|DEV_DB) [[ "${value}" =~ ^[A-Za-z0-9_-]{1,64}$ ]] ;;
    HEALTH_URL|DEV_HEALTH_URL)
      [[ "${value}" =~ ^https?://([A-Za-z0-9][A-Za-z0-9.-]*|\[[A-Fa-f0-9:]+\])(:[0-9]+)?/[A-Za-z0-9_./-]*$ ]] ;;
    DEV_ISOLATION_MODE) [[ "${value}" = strict || "${value}" = shared ]] ;;
    DEV_EXTERNAL_SERVICES) [[ "${value}" = disabled || "${value}" = enabled ]] ;;
    DEV_CLONE_SUBSCRIPTIONS) [[ "${value}" = preserve || "${value}" = strip ]] ;;
    DEV_LOGIN_PASSWORD|MYSQL_ROOT_PASSWORD) return 0 ;;
    *) return 1 ;;
  esac
}

deploy_load_config() {
  local file="$1" key value line mode owner line_no=0 seen=' '
  local keys='REMOTE APP_USER APP_DIR DOCROOT HEALTH_URL PHP_FPM_SERVICE BACKUP_DIR DB_USER DEV_DIR DEV_HEALTH_URL PROD_DB DEV_DB DEV_APP_USER DEV_DB_USER DEV_PHP_FPM_SERVICE DEV_ISOLATION_MODE DEV_EXTERNAL_SERVICES DEV_CLONE_SUBSCRIPTIONS DEV_LOGIN_PASSWORD MYSQL_ROOT_PASSWORD DEPLOY_STATE_ROOT'
  local names=() values=() i
  if [ -L "${file}" ] || [ ! -f "${file}" ] || [ ! -r "${file}" ]; then
    deploy_config_error 'deploy.env must be a readable regular file, not a symlink.'; return 1
  fi
  owner="$(stat -c %u "${file}" 2>/dev/null || stat -f %u "${file}")" || return 1
  mode="$(stat -c %a "${file}" 2>/dev/null || stat -f %Lp "${file}")" || return 1
  if [ "${owner}" != "${EUID}" ] || { [ "${mode}" != 600 ] && [ "${mode}" != 400 ]; }; then
    deploy_config_error 'deploy.env must belong to the invoking user and have mode 0600 or 0400 (chmod 600 scripts/deploy.env).'; return 1
  fi
  if IFS= read -r -d '' line < "${file}"; then
    deploy_config_error 'deploy.env contains a NUL byte.'; return 1
  fi
  while IFS= read -r line || [ -n "${line}" ]; do
    line_no=$((line_no + 1))
    line="${line%$'\r'}"
    [[ "${line}" =~ ^[[:space:]]*(#|$) ]] && continue
    if [[ ! "${line}" =~ ^[A-Z][A-Z0-9_]*= ]]; then
      deploy_config_error "line ${line_no}: expected literal KEY=VALUE."; return 1
    fi
    key="${line%%=*}"; value="${line#*=}"
    case " ${keys} " in
      *" ${key} "*) ;;
      *) deploy_config_error "line ${line_no}: unsupported key ${key}; one-off flags belong in the command environment."; return 1 ;;
    esac
    case "${seen}" in
      *" ${key} "*) deploy_config_error "line ${line_no}: duplicate key ${key}."; return 1 ;;
    esac
    if ! deploy_value_valid "${key}" "${value}"; then
      deploy_config_error "line ${line_no}: invalid ${key}; see deploy.env.example."; return 1
    fi
    seen="${seen}${key} "
    names+=("${key}"); values+=("${value}")
  done < "${file}"
  # Validate the complete file first. Clear *all* inherited configuration,
  # including optional keys absent from the file and their export attributes.
  for key in ${keys}; do unset "${key}"; done
  for ((i=0; i<${#names[@]}; i++)); do
    printf -v "${names[i]}" '%s' "${values[i]}"
  done
}

deploy_require() {
  local key
  for key in "$@"; do
    if [ -z "${!key:-}" ]; then
      deploy_config_error "${key} is required in scripts/deploy.env."; return 1
    fi
  done
}

deploy_check_flags() {
  local key
  for key in SKIP_TESTS SKIP_BACKUP FORCE_BRANCH CLONE_DB MOVE_INTEGRITY_QUIESCED FEED_CREDENTIALS_QUIESCED; do
    case "${!key:-0}" in
      0|1) ;;
      *) deploy_config_error "environment flag ${key} must be 0 or 1."; return 1 ;;
    esac
  done
}

deploy_target() {
  printf 'Target: %s %s:%s (from scripts/deploy.env)\n' "$1" "${REMOTE}" "$2"
}

deploy_confirm_skip_backup() {
  [ "${SKIP_BACKUP:-0}" = 1 ] || return 0
  printf '!!! WARNING: SKIP_BACKUP=1. This production deploy will have NO NEW BACKUP.\n' >&2
  if [ ! -t 0 ]; then
    printf 'Skipping the backup requires interactive confirmation. Unset SKIP_BACKUP for unattended deployment.\n' >&2
    return 1
  fi
  local answer
  printf 'Type "skip backup" to continue: ' >&2
  IFS= read -r answer && [ "${answer}" = 'skip backup' ]
}

# Serialize argv for the remote login shell, including /bin/sh. Never eval
# this locally. Scripts themselves travel on stdin; values remain arguments.
deploy_command() {
  local arg
  for arg in "$@"; do
    printf "'"
    while [[ "${arg}" = *"'"* ]]; do
      printf '%s%s' "${arg%%\'*}" "'\\''"
      arg="${arg#*\'}"
    done
    printf "%s' " "${arg}"
  done
}
