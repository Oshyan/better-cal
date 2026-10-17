#!/bin/bash
# Streamed to the remote Bash after configuration validation. No nested shell
# evaluates configuration while constructing the schedule.
set -euo pipefail
: "${APP_DIR:?}" "${APP_USER:?}"
umask 0077
log="${APP_DIR%/*}/worker.log"
# This is trusted helper code, not PHP from the deployed application's tree.
sudo -n -u "${APP_USER}" -- php -- "${log}" <<'PHP'
<?php
$path = $argv[1];
$at = '';
foreach (explode('/', trim($path, '/')) as $part) {
    $at .= '/' . $part;
    if (is_link($at)) { throw new RuntimeException('The worker log path contains a symbolic link.'); }
}
umask(0077);
if (file_exists($path) && (!is_file($path) || fileowner($path) !== posix_geteuid())) {
    throw new RuntimeException('The worker log must be a regular file owned by the application account.');
}
$fh = fopen($path, 'c+b');
if ($fh === false) { throw new RuntimeException('Could not create the private worker log.'); }
try {
    $st = fstat($fh);
    clearstatcache(true, $path);
    $named = lstat($path);
    if ($st['uid'] !== posix_geteuid() || $st['nlink'] !== 1 || ($st['mode'] & 0170000) !== 0100000
        || $st['dev'] !== $named['dev'] || $st['ino'] !== $named['ino'] || is_link($path)) {
        throw new RuntimeException('The worker log changed or is not a private regular file.');
    }
    if (!chmod($path, 0600)) { throw new RuntimeException('Could not make the worker log private.'); }
} finally { fclose($fh); }
PHP
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
# Upgrade only the exact line this helper previously installed.
old="* * * * * php '${worker}' >> '${log}' 2>&1"
new="* * * * * umask 0077; php '${worker}' >> '${log}' 2>&1"
cron_text="$(awk -v old="${old}" -v new="${new}" '{if ($0 == old) print new; else print}' "${cron_before}")"
printf '%s\n' "${cron_text}" >"${cron_before}"
if ! grep -Fq -- "${worker}" "${cron_before}"; then
  # Paths have already been restricted to canonical /[A-Za-z0-9_./-]+:
  # quotes, cron's percent syntax and line breaks cannot enter the command.
  printf '%s\n' "${new}" >>"${cron_before}"
fi
sudo -n -u "${APP_USER}" -- crontab - <"${cron_before}"
