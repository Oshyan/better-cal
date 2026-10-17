#!/bin/bash
# Streamed to the remote Bash after configuration validation. No nested shell
# evaluates configuration while constructing the schedule.
set -euo pipefail
: "${APP_DIR:?}" "${APP_USER:?}"
cron_before="$(mktemp)"
trap 'rm -f -- "${cron_before}"' EXIT
if sudo -n -u "${APP_USER}" -- crontab -l >"${cron_before}" 2>/dev/null; then
  :
else
  status=$?
  [ "${status}" = 1 ] || exit "${status}"
  : >"${cron_before}"
fi
worker="${APP_DIR}/server/bin/worker.php"
if ! grep -Fq -- "${worker}" "${cron_before}"; then
  # Paths have already been restricted to canonical /[A-Za-z0-9_./-]+:
  # quotes, cron's percent syntax and line breaks cannot enter the command.
  printf "* * * * * php '%s' >> '%s' 2>&1\n" "${worker}" "${APP_DIR%/*}/worker.log" >>"${cron_before}"
  sudo -n -u "${APP_USER}" -- crontab - <"${cron_before}"
fi
