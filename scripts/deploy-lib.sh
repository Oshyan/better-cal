# Shared by scripts/deploy.sh and scripts/deploy-dev.sh; sourced after
# deploy.env is loaded (needs REMOTE and HEALTH_URL).
#
# Why this exists (2026-09-25): a brute-force storm against the host's sshd
# kept more than 10 unauthenticated connections open, so sshd's MaxStartups
# (10:30:100) randomly dropped new ones, ours included. A deploy opened a fresh
# ssh connection per step; one was dropped after rsync had already rewritten
# the code, the remote chown never ran, and nginx's disable_symlinks owner
# check 404ed the whole site until a re-run. Now:
#   - one ssh connection, opened with retries before anything changes, carries
#     every later step (rsync included), so a drop can only happen up front;
#   - rsync writes as the app user (deploy_rsync), so files are right the
#     moment they land, whatever rsync the Mac has (openrsync has no --chown);
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
# later chown is needed. -a's owner/group are dropped (they would be the Mac's).
# No --delete by explicit policy (the user has been burned by it); stale-file
# removal, when ever needed, is a deliberate manual action on the server.
deploy_rsync() {
  DEPLOY_TOUCHED=1
  rsync -az --no-owner --no-group \
    --exclude '.git' \
    --exclude '.credentials' \
    --exclude '.env' \
    --exclude '.DS_Store' \
    --exclude 'server/vendor' \
    --exclude 'node_modules' \
    -e "ssh ${SSH_OPTS[*]}" \
    --rsync-path="sudo -n -u ${APP_USER} rsync" \
    "${ROOT_DIR}/" "${REMOTE}:$1/"
}

deploy_on_exit() {
  local rc=$?
  ssh -o "ControlPath=${SSH_CTL_DIR}/ctl" -O exit "${REMOTE}" >/dev/null 2>&1 || true
  rm -f "${SSH_CTL_DIR}/ctl"
  rmdir "${SSH_CTL_DIR}" 2>/dev/null || true
  [ "${rc}" -eq 0 ] && return
  echo >&2
  if [ "${DEPLOY_TOUCHED}" = "1" ]; then
    local health="NOT answering"
    curl -fsS --max-time 10 -o /dev/null "${HEALTH_URL}" 2>/dev/null && health="answering"
    {
      echo "!!! DEPLOY FAILED at: ${DEPLOY_STAGE} (exit ${rc})"
      echo "!!! Code was already copied to the server, so the site may be down or half-updated."
      echo "!!! ${HEALTH_URL} is ${health}. Re-run this deploy to finish it."
    } >&2
  else
    echo "Deploy stopped at: ${DEPLOY_STAGE} (exit ${rc}). Nothing on the server was changed." >&2
  fi
  exit "${rc}"
}
trap deploy_on_exit EXIT
