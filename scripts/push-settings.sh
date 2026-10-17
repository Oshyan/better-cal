#!/usr/bin/env bash
# Push settings from a local, gitignored file into the server's settings file
# (.env.php, or .env until it's converted).
#
#   scripts/push-settings.sh          scripts/server.env      -> APP_DIR
#   scripts/push-settings.sh dev      scripts/server-dev.env  -> DEV_DIR
#
# Only the keys in the local file are added or replaced; every other line of
# the server's file stays as it is, the guard line of .env.php included. A
# copy goes to a root-only backup directory first. Values travel over SSH
# stdin and are never printed.
#
# Deliberately separate from deploy.sh: a deploy never writes the settings
# (see its "composer + migrate + link" stage), so they change only when this
# is run. The write is done by scripts/merge-env.py, which refuses a symlinked
# file and keeps its owner and mode. PHP reads the settings on each request
# and the worker on each run, so nothing needs restarting.
#
# Target host and paths come from scripts/deploy.env, as for deploy.sh.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "${ROOT_DIR}/scripts/deploy-config.sh"
deploy_load_config "${ROOT_DIR}/scripts/deploy.env"

target="${1:-prod}"
case "${target}" in
  prod) settings="${ROOT_DIR}/scripts/server.env"; dir="${APP_DIR:-}"; label=prod ;;
  dev)  settings="${ROOT_DIR}/scripts/server-dev.env"; dir="${DEV_DIR:-}"; label=dev ;;
  *) echo "usage: scripts/push-settings.sh [prod|dev]" >&2; exit 2 ;;
esac
if [ -z "${REMOTE:-}" ] || [ -z "${dir}" ]; then
  echo "REMOTE and the ${target} directory must be set in scripts/deploy.env." >&2
  exit 1
fi
deploy_target "${target} settings" "${dir}"
if [ ! -f "${settings}" ]; then
  echo "No settings file: ${settings#"${ROOT_DIR}/"}" >&2
  exit 1
fi
if git -C "${ROOT_DIR}" ls-files --error-unmatch "${settings}" >/dev/null 2>&1; then
  echo "${settings#"${ROOT_DIR}/"} is tracked by git; it holds secrets and must stay untracked." >&2
  exit 1
fi

# KEY=VALUE lines only; comments and blank lines are skipped. Values are kept
# exactly as written, so the server's .env gets the same line you wrote here.
payload="$(grep -v -E '^[[:space:]]*(#|$)' "${settings}" || true)"
if [ -z "${payload}" ]; then
  echo "${settings#"${ROOT_DIR}/"} has no settings." >&2
  exit 1
fi
if printf '%s\n' "${payload}" | grep -v -q -E '^[A-Z][A-Z0-9_]*='; then
  echo "Every setting line must be KEY=VALUE with an uppercase key." >&2
  exit 1
fi
dupes="$(printf '%s\n' "${payload}" | cut -d= -f1 | sort | uniq -d)"
if [ -n "${dupes}" ]; then
  echo "A key appears twice in ${settings#"${ROOT_DIR}/"}: ${dupes//$'\n'/, }" >&2
  exit 1
fi

echo "== pushing $(printf '%s\n' "${payload}" | wc -l | tr -d ' ') setting(s) to ${target} (${dir}) =="
merge_b64="$(base64 < "${ROOT_DIR}/scripts/merge-env.py" | tr -d '\n')"
# The folder: merge-env.py picks .env.php, or .env until it's converted.
env_q="$(deploy_command "${dir}" "${label}")"
printf '%s\n' "${payload}" | ssh "${REMOTE}" \
  "python3 -c \"\$(printf '%s' '${merge_b64}' | base64 -d)\" ${env_q}"
