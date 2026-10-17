#!/bin/bash
# Run only in a disposable Linux container, with server/ and scripts/ at /code.
set -euo pipefail
[[ "${BC_FILES_DISPOSABLE_CONTAINER:-}" = 1 && "$(id -u)" = 0 ]] || exit 2
groupadd sample-shared
useradd -m sample-prod
useradd -m -G sample-shared sample-dev
fixture="$(mktemp -d /tmp/bc-files-linux.XXXXXX)"
trap 'rm -rf -- "${fixture}"' EXIT
chmod 755 "${fixture}"
prod="${fixture}/production"
mkdir "${prod}"
touch "${prod}/code.php"
chown -R sample-prod:sample-shared "${prod}"
chmod 755 "${prod}"
chmod 644 "${prod}/code.php"
check_dev() {
  runuser -u sample-dev -- env BETTERCAL_CHECK_PROD_WRITE=1 APP_ROOT="${prod}" php /code/scripts/sanitize-dev-clone.php
}
refused() {
  if "$@" >"${fixture}/result" 2>&1; then echo 'FAIL: unsafe fixture accepted' >&2; exit 1; fi
}
check_dev
echo 'PASS: separate read-only production tree passes strict dev check'
chmod 664 "${prod}/code.php"
refused check_dev
echo 'PASS: shared-group write access is refused'
chmod 644 "${prod}/code.php"
chmod 775 "${prod}"
refused check_dev
echo 'PASS: parent replacement access is refused'
chmod 755 "${prod}"
chown sample-dev "${prod}/code.php"
chmod 444 "${prod}/code.php"
refused check_dev
echo 'PASS: dev ownership is refused even with read-only mode bits'
chown sample-prod "${prod}/code.php"
check_dev
# Root may not convert a lower-privilege application's planted backup tree.
mkdir "${prod}/backups"
chown sample-prod:sample-shared "${prod}/backups"
php -r 'file_put_contents($argv[1], gzencode("invented backup"));' "${prod}/backups/db-old.sql.gz"
chown sample-prod "${prod}/backups/db-old.sql.gz"
refused php -r 'require "/code/server/src/bootstrap.php"; BetterCal\Infra\DbBackup::encryptFile($argv[1], BetterCal\Infra\BackupCipher::keyFrom("synthetic"));' "${prod}/backups/db-old.sql.gz"
test -f "${prod}/backups/db-old.sql.gz"
test ! -e "${prod}/backups/db-old.bcbackup"
echo 'PASS: privileged conversion refuses an app-controlled tree without changing it'
runuser -u sample-prod -- php -r 'require "/code/server/src/bootstrap.php"; BetterCal\Infra\DbBackup::encryptFile($argv[1], BetterCal\Infra\BackupCipher::keyFrom("synthetic"));' "${prod}/backups/db-old.sql.gz"
test -f "${prod}/backups/db-old.bcbackup"
echo 'PASS: ordinary storage-owner conversion still succeeds'
# Hosting account groups may write their own home and application folders.
layout="${fixture}/home"
mkdir -p "${layout}/site"
chown -R sample-prod:sample-prod "${layout}"
storage_probe='require "/code/server/src/bootstrap.php"; $w = new BetterCal\Infra\ExportWorkspace($argv[1]); $w->file("ics"); $w->close(); (new BetterCal\Infra\Updater($argv[1]))->ensureBackupDir();'
for modes in '770 755' '755 775' '770 775'; do
  read -r home_mode app_mode <<< "${modes}"
  chmod "${home_mode}" "${layout}"
  chmod "${app_mode}" "${layout}/site"
  runuser -u sample-prod -- php -r "${storage_probe}" "${layout}/site"
  runuser -u sample-prod -- php -d disable_functions=posix_geteuid,posix_getegid -r "${storage_probe}" "${layout}/site"
done
echo 'PASS: account-group home and app folders support exports and backups with or without POSIX'
chgrp sample-shared "${layout}"
refused runuser -u sample-prod -- php -r "${storage_probe}" "${layout}/site"
echo 'PASS: a different writable parent group is refused with POSIX'
chgrp sample-prod "${layout}"
chmod 777 "${layout}"
refused runuser -u sample-prod -- php -r "${storage_probe}" "${layout}/site"
refused runuser -u sample-prod -- php -d disable_functions=posix_geteuid,posix_getegid -r "${storage_probe}" "${layout}/site"
echo 'PASS: world-writable non-sticky parent is refused even without POSIX'
chmod 1777 "${layout}"
runuser -u sample-prod -- php -r "${storage_probe}" "${layout}/site"
refused php -r "${storage_probe}" "${layout}/site"
echo 'PASS: sticky parent permits owner access but does not let root enter a foreign-owned tree'
