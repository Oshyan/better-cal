# Shared by scripts/deploy.sh and scripts/deploy-dev.sh; sourced after
# deploy.env is loaded (needs REMOTE and HEALTH_URL).
#
# Why this exists: when many unauthenticated connections are open (a
# brute-force run against sshd, say), sshd's MaxStartups setting can randomly
# drop new ones, ours included. A deploy that opens a fresh ssh connection per
# step can then lose one after rsync has already rewritten the code, so the
# remote chown never runs and nginx's disable_symlinks owner check 404s the
# whole site until a re-run. Now:
#   - one ssh connection, opened with retries before anything changes, carries
#     every later step (rsync included), so a drop can only happen up front;
#   - rsync writes as the app user (deploy_rsync), so files are right the
#     moment they land, whatever the local machine's rsync is (openrsync has
#     no --chown);
#   - any failure after the server was touched says so, loudly.

DEPLOY_STAGE="pre-flight"
DEPLOY_TOUCHED=0

SSH_CTL_DIR="$(mktemp -d /tmp/bc-ssh.XXXXXX)"
# ControlMaster=no uses the shared connection when it is up and falls back to
# a fresh one when it is not.
SSH_OPTS=(-o "ControlPath=${SSH_CTL_DIR}/ctl" -o ControlMaster=no
  -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=4)

rssh() { ssh "${SSH_OPTS[@]}" "${REMOTE}" "$@"; }

stage() { DEPLOY_STAGE="$1"; echo "== $1 =="; }

# Opens the shared connection. Nothing on the server has changed yet, so a
# retry here is always safe.
ssh_open() {
  local attempt
  for attempt in 1 2 3 4 5; do
    if ssh -o "ControlPath=${SSH_CTL_DIR}/ctl" -o ControlMaster=yes -o ControlPersist=15m \
      -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 \
      -fN "${REMOTE}"; then
      return 0
    fi
    [ "${attempt}" -eq 5 ] && break
    echo "ssh to ${REMOTE} failed (attempt ${attempt} of 5); retrying in $((attempt * 3))s" >&2
    sleep $((attempt * 3))
  done
  echo "Could not connect to ${REMOTE} after 5 attempts." >&2
  return 1
}

# rsync of the working tree into $1 on the server. The receiving rsync runs as
# APP_USER, so every file it writes is owned by the app from the start and no
# later chown is needed. -a's owner/group are dropped (they would be the local
# machine's). No --delete, by policy; stale-file removal, when ever needed, is
# a deliberate manual action on the server.
#
# deploy_rsync DEST [SNAPSHOT] [RUN_USER]. With SNAPSHOT (a `git archive` of
# DEPLOY_COMMIT, made by deploy.sh), exactly that commit's files ship, and
# nothing uncommitted can: a second session's half-finished edits in the
# working tree once came close to production. Without it (deploy-dev.sh), the
# working tree ships as before.
deploy_rsync() {
  DEPLOY_TOUCHED=1
  local src="${ROOT_DIR}"
  local run_user="${3:-${APP_USER}}"
  local extra=()
  case "${run_user}" in
    ''|[0-9]*|*[!A-Za-z0-9_-]*)
      echo 'Deployment application user must be a plain Unix account name.' >&2
      return 1
      ;;
  esac
  if [ -n "${2:-}" ]; then
    src="$2"
    # git archive stamps every file with the commit time, so size and mtime
    # can't tell an edited file from an untouched one: compare contents.
    extra=(--checksum)
  fi
  # Ask Git for the source list instead of asking rsync to interpret every
  # .gitignore. Rsync's filter syntax does not implement Git's nested negate
  # rules correctly (tools/tz-harness/*.json hid its tracked package files).
  # From a snapshot: the commit's files. From the working tree: tracked files,
  # plus untracked ones Git says are not ignored.
  # The explicit excludes remain a final backstop for secrets and dependencies.
  {
    if [ -n "${2:-}" ]; then
      git -C "${ROOT_DIR}" ls-tree -r -z --name-only "${DEPLOY_COMMIT}"
    else
      git -C "${ROOT_DIR}" ls-files --cached --others --exclude-standard -z
    fi
  } | \
  rsync -azr --no-owner --no-group "${extra[@]}" \
    --from0 --files-from=- \
    --exclude '.git' \
    --exclude '.credentials' \
    --exclude '.credentials/***' \
    --exclude '.env' \
    --include '.env.example' \
    --exclude '.env.*' \
    --exclude '.mcp.json' \
    --exclude 'scripts/deploy.env' \
    --exclude 'workfolder' \
    --exclude 'workfolder/***' \
    --exclude '.ygrep' \
    --exclude '.ygrep/***' \
    --exclude '.aws' \
    --exclude '.aws/***' \
    --exclude '.agents' \
    --exclude '.agents/***' \
    --exclude '.codex' \
    --exclude '.codex/***' \
    --exclude '.DS_Store' \
    --exclude 'server/vendor' \
    --exclude 'server/vendor/***' \
    --exclude 'node_modules' \
    --exclude '**/node_modules/***' \
    -e "ssh ${SSH_OPTS[*]}" \
    --rsync-path="sudo -n -u ${run_user} rsync" \
    "${src}/" "${REMOTE}:$1/"
}

# Quarantine only plugin ids that this Git repository has managed and the
# selected release no longer contains. Operator drop-in plugins, which have
# never appeared in Git history, are deliberately outside this cleanup.
# The root-owned manifest/quarantine live under /var/lib by default, outside
# the application's writable tree. No blanket rsync --delete is used.
reconcile_release_plugins() {
  local dest="$1"
  local snapshot="${2:-}"
  local run_user="${3:-${APP_USER}}"
  local current known history_ref baseline previous_state stale id state_key state_root state_dir

  if [ -n "${snapshot}" ]; then
    current="$(git -C "${ROOT_DIR}" ls-tree -r --name-only "${DEPLOY_COMMIT}" -- server/plugins \
      | awk -F/ 'NF == 4 && $1 == "server" && $2 == "plugins" && $4 == "plugin.json" {print $3}' \
      | LC_ALL=C sort -u)"
  else
    current="$(git -C "${ROOT_DIR}" ls-files -- server/plugins \
      | awk -F/ 'NF == 4 && $1 == "server" && $2 == "plugins" && $4 == "plugin.json" {print $3}' \
      | LC_ALL=C sort -u)"
  fi
  history_ref="${DEPLOY_COMMIT:-HEAD}"
  known="$(git -C "${ROOT_DIR}" log "${history_ref}" --format= --name-only -- 'server/plugins/*/plugin.json' \
    | awk -F/ 'NF == 4 && $1 == "server" && $2 == "plugins" && $4 == "plugin.json" {print $3}' \
    | LC_ALL=C sort -u)"
  state_key="$(printf '%s' "${dest}" | git hash-object --stdin)"
  state_root="${DEPLOY_STATE_ROOT:-/var/lib/better-cal-deploy}"
  state_dir="${state_root}/${state_key}"
  printf -v state_key_q '%q' "${state_key}"
  printf -v state_root_q '%q' "${state_root}"
  printf -v state_dir_q '%q' "${state_dir}"
  previous_state="$(rssh "sudo -n env DEPLOY_STATE_ROOT=${state_root_q} DEPLOY_STATE_DIR=${state_dir_q} bash -s" <<'EOF'
set -euo pipefail
for dir in "${DEPLOY_STATE_ROOT}" "${DEPLOY_STATE_DIR}"; do
  if [ ! -e "${dir}" ]; then
    printf '__MISSING__\n'
    exit 0
  fi
  if [ -L "${dir}" ] || [ ! -d "${dir}" ] || [ "$(stat -c %u "${dir}")" != 0 ] || [ "$(stat -c %a "${dir}")" != 700 ]; then
    echo 'Deploy plugin state directory is not a root-owned mode-0700 directory.' >&2
    exit 1
  fi
done
manifest="${DEPLOY_STATE_DIR}/managed-plugins"
if [ ! -e "${manifest}" ]; then
  printf '__MISSING__\n'
  exit 0
fi
if [ -L "${manifest}" ] || [ ! -f "${manifest}" ] || [ "$(stat -c %u "${manifest}")" != 0 ] \
  || [ "$(stat -c %a "${manifest}")" != 600 ] || [ "$(stat -c %s "${manifest}")" -gt 65536 ]; then
  echo 'Deploy plugin manifest is not a small root-owned mode-0600 regular file.' >&2
  exit 1
fi
printf '__PRESENT__\n'
cat "${manifest}"
EOF
)"
  case "${previous_state}" in
    __MISSING__) baseline="${known}" ;;
    __PRESENT__) baseline='' ;;
    __PRESENT__$'\n'*) baseline="${previous_state#*$'\n'}" ;;
    *) echo 'Could not read the managed-plugin deployment baseline.' >&2; return 1 ;;
  esac
  stale=''
  while IFS= read -r id; do
    [ -z "${id}" ] && continue
    if ! printf '%s' "${id}" | LC_ALL=C grep -Eq '^[a-z][a-z0-9-]{1,62}[a-z0-9]$'; then
      echo 'The managed-plugin deployment baseline contains an invalid id.' >&2
      return 1
    fi
    if ! printf '%s\n' "${current}" | awk -v wanted="${id}" '$0 == wanted {found=1} END {exit found ? 0 : 1}'; then
      stale="${stale}${stale:+,}${id}"
    fi
  done <<EOF
${baseline}
EOF
  STALE_MANAGED_PLUGINS="${stale}"
  CURRENT_MANAGED_PLUGINS="$(printf '%s' "${current}" | tr '\n' ',')"
  MANAGED_PLUGIN_STATE_ROOT="${state_root}"
  MANAGED_PLUGIN_STATE_DIR="${state_dir}"
  export STALE_MANAGED_PLUGINS
  export CURRENT_MANAGED_PLUGINS MANAGED_PLUGIN_STATE_ROOT MANAGED_PLUGIN_STATE_DIR

  printf -v dest_q '%q' "${dest}"
  printf -v run_user_q '%q' "${run_user}"
  printf -v current_q '%q' "$(printf '%s' "${current}" | tr '\n' ',')"
  printf -v stale_q '%q' "${stale}"
  rssh "sudo -n env DEPLOY_DEST=${dest_q} DEPLOY_RUN_USER=${run_user_q} DEPLOY_STATE_KEY=${state_key_q} DEPLOY_STATE_ROOT=${state_root_q} DEPLOY_STATE_DIR=${state_dir_q} CURRENT_PLUGINS=${current_q} STALE_PLUGINS=${stale_q} bash -s" <<'EOF'
set -euo pipefail
case "${DEPLOY_DEST}" in /*) ;; *) echo 'Plugin reconciliation requires an absolute deployment path.' >&2; exit 1 ;; esac
case "${DEPLOY_STATE_ROOT}" in /*) ;; *) echo 'Plugin reconciliation requires an absolute state root.' >&2; exit 1 ;; esac
if [ "${DEPLOY_DEST}" = / ] || [ "${DEPLOY_STATE_ROOT}" = / ] \
  || ! id "${DEPLOY_RUN_USER}" >/dev/null 2>&1 \
  || ! printf '%s' "${DEPLOY_STATE_KEY}" | grep -Eq '^[0-9a-f]{40}$' \
  || [ "${DEPLOY_STATE_DIR}" != "${DEPLOY_STATE_ROOT}/${DEPLOY_STATE_KEY}" ]; then
  echo 'Plugin reconciliation received an unsafe path or unknown application user.' >&2
  exit 1
fi
if { [ -e "${DEPLOY_STATE_ROOT}" ] || [ -L "${DEPLOY_STATE_ROOT}" ]; } \
  && { [ -L "${DEPLOY_STATE_ROOT}" ] || [ ! -d "${DEPLOY_STATE_ROOT}" ]; }; then
  echo 'Deploy plugin state root is not a real directory.' >&2
  exit 1
fi
install -d -m 0700 -o root -g root "${DEPLOY_STATE_ROOT}"
if [ "$(stat -c %u "${DEPLOY_STATE_ROOT}")" != 0 ] || [ "$(stat -c %a "${DEPLOY_STATE_ROOT}")" != 700 ]; then
  echo 'Deploy plugin state root is not root-owned mode 0700.' >&2
  exit 1
fi
if { [ -e "${DEPLOY_STATE_DIR}" ] || [ -L "${DEPLOY_STATE_DIR}" ]; } \
  && { [ -L "${DEPLOY_STATE_DIR}" ] || [ ! -d "${DEPLOY_STATE_DIR}" ]; }; then
  echo 'Deploy plugin state path is not a real directory.' >&2
  exit 1
fi
install -d -m 0700 -o root -g root "${DEPLOY_STATE_DIR}" "${DEPLOY_STATE_DIR}/plugin-quarantine"
if [ "$(stat -c %u "${DEPLOY_STATE_DIR}")" != 0 ] || [ "$(stat -c %a "${DEPLOY_STATE_DIR}")" != 700 ]; then
  echo 'Deploy plugin state directory is not root-owned mode 0700.' >&2
  exit 1
fi
if [ -n "${STALE_PLUGINS}" ]; then
  # Plugin code executes only in the worker. Remove this app's schedule and let
  # an in-flight run finish before moving code; the deploy restores the normal
  # worker line after migration. A failed deploy therefore stays fail-closed.
  if ! command -v crontab >/dev/null 2>&1 || ! command -v pgrep >/dev/null 2>&1; then
    echo 'crontab and pgrep are required to retire a managed plugin safely.' >&2
    exit 1
  fi
  cron_before="$(mktemp)"
  cron_after="$(mktemp)"
  cleanup_plugin_state() {
    local file
    for file in "${cron_before:-}" "${cron_after:-}" "${manifest_tmp:-}"; do
      [ -z "${file}" ] || rm -f -- "${file}"
    done
  }
  trap cleanup_plugin_state EXIT
  if sudo -n -u "${DEPLOY_RUN_USER}" -- crontab -l >"${cron_before}" 2>/dev/null; then
    :
  else
    cron_status=$?
    if [ "${cron_status}" -ne 1 ]; then
      echo 'Could not inspect the application worker schedule.' >&2
      exit 1
    fi
    : >"${cron_before}"
  fi
  awk -v worker="${DEPLOY_DEST}/server/bin/worker.php" 'index($0, worker) == 0' "${cron_before}" >"${cron_after}"
  sudo -n -u "${DEPLOY_RUN_USER}" -- crontab - <"${cron_after}"
  deadline=$((SECONDS + 75))
  while pgrep -u "${DEPLOY_RUN_USER}" -f "${DEPLOY_DEST}/server/bin/[w]orker.php" >/dev/null; do
    if [ "${SECONDS}" -ge "${deadline}" ]; then
      echo 'An application worker did not finish before plugin retirement.' >&2
      exit 1
    fi
    sleep 2
  done
  stamp="$(date -u +%Y%m%dT%H%M%SZ)"
  quarantine="${DEPLOY_STATE_DIR}/plugin-quarantine/${stamp}"
  install -d -m 0700 -o root -g root "${quarantine}"
  old_ifs="${IFS}"
  IFS=,
  for id in ${STALE_PLUGINS}; do
    IFS="${old_ifs}"
    if ! printf '%s' "${id}" | LC_ALL=C grep -Eq '^[a-z][a-z0-9-]{1,62}[a-z0-9]$'; then
      echo 'Refusing an invalid managed plugin id.' >&2
      exit 1
    fi
    target="${DEPLOY_DEST}/server/plugins/${id}"
    if [ -e "${target}" ] || [ -L "${target}" ]; then
      mv -- "${target}" "${quarantine}/${id}"
      echo "Quarantined removed release plugin: ${id}"
    fi
    IFS=,
  done
  IFS="${old_ifs}"
fi
manifest_tmp="$(mktemp "${DEPLOY_STATE_DIR}/.managed-plugins.XXXXXX")"
printf '%s' "${CURRENT_PLUGINS}" | tr ',' '\n' | sed '/^$/d' >"${manifest_tmp}"
chmod 0600 "${manifest_tmp}"
if [ -n "${STALE_PLUGINS}" ]; then
  # Keep the old baseline authoritative until database cleanup succeeds. A
  # failed deploy then rediscovers the same removed ids and safely retries.
  mv -f -- "${manifest_tmp}" "${DEPLOY_STATE_DIR}/managed-plugins.pending"
else
  mv -f -- "${manifest_tmp}" "${DEPLOY_STATE_DIR}/managed-plugins"
  rm -f -- "${DEPLOY_STATE_DIR}/managed-plugins.pending"
fi
trap - EXIT
EOF
}

finalize_managed_plugin_manifest() {
  local state_root_q state_dir_q
  [ -z "${STALE_MANAGED_PLUGINS:-}" ] && return 0
  printf -v state_root_q '%q' "${MANAGED_PLUGIN_STATE_ROOT}"
  printf -v state_dir_q '%q' "${MANAGED_PLUGIN_STATE_DIR}"
  rssh "sudo -n env DEPLOY_STATE_ROOT=${state_root_q} DEPLOY_STATE_DIR=${state_dir_q} bash -s" <<'EOF'
set -euo pipefail
case "${DEPLOY_STATE_DIR}" in
  "${DEPLOY_STATE_ROOT}"/*) ;;
  *) echo 'Managed-plugin state path is outside its configured root.' >&2; exit 1 ;;
esac
for dir in "${DEPLOY_STATE_ROOT}" "${DEPLOY_STATE_DIR}"; do
  if [ -L "${dir}" ] || [ ! -d "${dir}" ] || [ "$(stat -c %u "${dir}")" != 0 ] \
    || [ "$(stat -c %a "${dir}")" != 700 ]; then
    echo 'Deploy plugin state directory is not a root-owned mode-0700 directory.' >&2
    exit 1
  fi
done
pending="${DEPLOY_STATE_DIR}/managed-plugins.pending"
if [ -L "${pending}" ] || [ ! -f "${pending}" ] || [ "$(stat -c %u "${pending}")" != 0 ] \
  || [ "$(stat -c %a "${pending}")" != 600 ] || [ "$(stat -c %s "${pending}")" -gt 65536 ]; then
  echo 'Pending managed-plugin manifest is not a small root-owned mode-0600 regular file.' >&2
  exit 1
fi
mv -f -- "${pending}" "${DEPLOY_STATE_DIR}/managed-plugins"
EOF
}

# Quarantined code cannot execute. Also make the durable state explicit so a
# queued worker does not repeatedly try it and a later rollback does not
# silently re-enable code the release removed.
disable_missing_plugins() {
  local dest="$1"
  local run_user="${2:-${APP_USER}}"
  [ -z "${STALE_MANAGED_PLUGINS:-}" ] && return 0
  printf -v dest_q '%q' "${dest}"
  printf -v run_user_q '%q' "${run_user}"
  printf -v stale_q '%q' "${STALE_MANAGED_PLUGINS}"
  rssh "sudo -n -u ${run_user_q} env STALE_MANAGED_PLUGINS=${stale_q} php ${dest_q}/server/bin/disable-missing-plugins.php"
  # Commit the new release baseline only after the durable database cleanup.
  # If either step fails, a retry still rediscovers and processes every id.
  finalize_managed_plugin_manifest
}

deploy_on_exit() {
  local rc=$?
  # A caller may register narrowly scoped recovery that still needs the shared
  # SSH connection (for example, resuming a dedicated dev PHP service). The
  # hook must never mask the original deployment failure.
  if [ "${rc}" -ne 0 ] && declare -F deploy_failure_recovery >/dev/null 2>&1; then
    deploy_failure_recovery "${rc}" || true
  fi
  ssh -o "ControlPath=${SSH_CTL_DIR}/ctl" -O exit "${REMOTE}" >/dev/null 2>&1 || true
  rm -f "${SSH_CTL_DIR}/ctl"
  rmdir "${SSH_CTL_DIR}" 2>/dev/null || true
  case "${DEPLOY_SNAPSHOT:-}" in /tmp/bc-deploy.*) rm -rf "${DEPLOY_SNAPSHOT}" ;; esac
  [ "${rc}" -eq 0 ] && return
  echo >&2
  if [ "${DEPLOY_TOUCHED}" = "1" ]; then
    local health="NOT answering"
    curl -fsS --max-time 10 -o /dev/null "${HEALTH_URL}" 2>/dev/null && health="answering"
    {
      echo "!!! DEPLOY FAILED at: ${DEPLOY_STAGE} (exit ${rc})"
      echo "!!! Remote deployment state already changed, so the site may be stopped or half-updated."
      echo "!!! ${HEALTH_URL} is ${health}. Re-run this deploy to finish it."
    } >&2
  else
    echo "Deploy stopped at: ${DEPLOY_STAGE} (exit ${rc}). Nothing on the server was changed." >&2
  fi
  exit "${rc}"
}
trap deploy_on_exit EXIT
