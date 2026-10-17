#!/bin/bash
# Small behavior suite: real entry points, inert remote-shell parsing and cron.
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "${ROOT_DIR}/scripts/deploy-config.sh"
TEST_DIR="$(mktemp -d "${TMPDIR:-/tmp}/bc-deploy-config.XXXXXX")"
trap 'rm -rf -- "${TEST_DIR}"' EXIT
mkdir -p "${TEST_DIR}/scripts"
cp "${ROOT_DIR}/scripts/"{deploy.sh,deploy-dev.sh,push-settings.sh,deploy-config.sh} "${TEST_DIR}/scripts/"
config="${TEST_DIR}/scripts/deploy.env"
passed=0
check() { if "$@"; then passed=$((passed + 1)); else echo "FAIL: $*" >&2; exit 1; fi; }
fixture() {
  printf '%s\n' REMOTE=sample-host APP_DIR=/srv/sample.app APP_USER=sample DOCROOT=/srv/public \
    HEALTH_URL=https://calendar.example.test/api/v1/health DB_USER=sample PHP_FPM_SERVICE=php-fpm \
    DEV_DIR=/srv/sample-dev DEV_HEALTH_URL=https://calendar.example.test:8443/api/v1/health \
    PROD_DB=sample DEV_DB=sample_dev DEV_APP_USER=sample-dev DEV_DB_USER=sample_dev \
    DEV_PHP_FPM_SERVICE=php-fpm-dev >"${config}"
  chmod 600 "${config}"
}
refused() { ! (deploy_load_config "${config}") >/dev/null 2>&1; }

fixture
check deploy_load_config "${config}"
# Reject file keys that could affect tools, internal state or one-off flags.
for key in BASH_ENV ENV PATH NODE_OPTIONS PHPRC GIT_SSH_COMMAND ROOT_DIR SSH_OPTS SKIP_BACKUP; do
  fixture; printf '%s=%s\n' "${key}" '$(printf SHOULD_NOT_EXECUTE >&2)' >>"${config}"
  check refused
done
for line in 'REMOTE=another-host' 'export REMOTE=sample-host' 'BAD[$(id)]=value' 'printf SHOULD_NOT_EXECUTE'; do
  fixture; printf '%s\n' "${line}" >>"${config}"
  check refused
done
for value in "/srv/a'b" '/srv/$(id)' '/srv/a;b' '/srv/a b' '/srv/a%b' '/srv/../a' '/srv/./a' '/srv//a' '/srv/a/' /; do
  check test "$(deploy_value_valid APP_DIR "${value}" && echo accepted || echo refused)" = refused
done
check test "$(deploy_value_valid REMOTE '-oProxyCommand=id' && echo accepted || echo refused)" = refused
check test "$(deploy_value_valid APP_USER '-root' && echo accepted || echo refused)" = refused
fixture; chmod 644 "${config}"; check refused
fixture; mv "${config}" "${config}.real"; ln -s "${config}.real" "${config}"; check refused
rm "${config}" "${config}.real"
fixture; printf '\0' >>"${config}"; check refused

# Raw secrets round-trip without shell evaluation, including CRLF and an
# unterminated final line. Config variables are never exported to child tools.
fixture
secret='literal $HOME $(printf SHOULD_NOT_EXECUTE) `id` # = "quote" '\'' space'
printf '\r\n # comment\r\nDEV_LOGIN_PASSWORD=%s\r\nMYSQL_ROOT_PASSWORD=last=value' "${secret}" >>"${config}"
(
  export REMOTE=wrong-host APP_DIR=/srv/wrong APP_USER=wrong HEALTH_URL=https://wrong.example.test/ DEV_EXTERNAL_SERVICES=enabled
  export SKIP_TESTS=1 FORCE_BRANCH=1 SSH_OPTS=sentinel
  deploy_load_config "${config}"
  test "${REMOTE}" = sample-host && test "${APP_DIR}" = /srv/sample.app
  test "${APP_USER}" = sample && test "${HEALTH_URL}" = https://calendar.example.test/api/v1/health
  test -z "${DEV_EXTERNAL_SERVICES:-}" && test "${DEV_LOGIN_PASSWORD}" = "${secret}"
  test "${MYSQL_ROOT_PASSWORD}" = last=value
  test "${SKIP_TESTS}:${FORCE_BRANCH}:${SSH_OPTS}" = 1:1:sentinel
  ! env | grep -q '^DEV_LOGIN_PASSWORD='
  ! env | grep -q '^REMOTE='
) || { echo 'FAIL: literal config, precedence or export boundary' >&2; exit 1; }
passed=$((passed + 1))

# A late malformed line must not partially assign earlier settings.
fixture; printf 'BASH_ENV=/tmp/not-a-startup-file\n' >>"${config}"
check bash -c 'source "$1"; REMOTE=unchanged; deploy_load_config "$2" >/dev/null 2>&1 && exit 1; [ "$REMOTE" = unchanged ]' _ "${ROOT_DIR}/scripts/deploy-config.sh" "${config}"

# Exercise each unchanged entry point's integration: external work stops at
# git, and settings push stops at the deliberately missing payload file.
git() { return 79; }
ssh() { echo UNEXPECTED_SSH >&2; return 80; }
export -f git ssh
for script in deploy.sh deploy-dev.sh push-settings.sh; do
  fixture
  output="$(REMOTE=wrong-host APP_DIR=/srv/wrong APP_USER=wrong HEALTH_URL=https://wrong.example.test/ \
    bash "${TEST_DIR}/scripts/${script}" 2>&1 || true)"
  check test "$(printf '%s' "${output}" | grep -c 'Target: .* sample-host:/srv/sample.*(from scripts/deploy.env)')" = 1
  check test "$(printf '%s' "${output}" | grep -c 'wrong-host\|/srv/wrong\|UNEXPECTED_SSH' || true)" = 0
  printf 'BASH_ENV=%s\n' "${TEST_DIR}/startup" >>"${config}"
  printf 'echo SHOULD_NOT_EXECUTE\n' >"${TEST_DIR}/startup"
  output="$(bash "${TEST_DIR}/scripts/${script}" 2>&1 || true)"
  check test "$(printf '%s' "${output}" | grep -c 'unsupported key BASH_ENV')" = 1
  check test "$(printf '%s' "${output}" | grep -c 'SHOULD_NOT_EXECUTE\|UNEXPECTED_SSH' || true)" = 0
done
unset -f git ssh
fixture
output="$(SKIP_BACKUP=1 bash "${TEST_DIR}/scripts/deploy.sh" </dev/null 2>&1 || true)"
check test "$(printf '%s' "${output}" | grep -c 'NO NEW BACKUP')" = 1
check test "$(printf '%s' "${output}" | grep -c 'requires interactive confirmation')" = 1

# Run the exact serializer through POSIX sh and Bash, not just a source check.
for shell in /bin/sh /bin/bash; do
  for value in '' /srv/sample.app "a'b" '$(printf BAD)' 'a; printf BAD' 'a b%`id`'; do
    command_text="$(deploy_command printf '%s' "${value}")"
    check test "$("${shell}" -c "${command_text}")" = "${value}"
  done
done

# Execute the real remote cron helper with a fake crontab. Repeated runs keep
# unrelated entries and do not duplicate the worker; read errors fail closed.
export CRON_FIXTURE="${TEST_DIR}/crontab"
printf '0 3 * * * echo unrelated\n' >"${CRON_FIXTURE}"
sudo() { shift 4; "$@"; }
crontab() {
  if [ "$1" = -l ]; then
    [ "${CRON_FAIL:-0}" = 0 ] || return 2
    cat "${CRON_FIXTURE}"
  else
    cat >"${CRON_FIXTURE}"
  fi
}
export -f sudo crontab
for attempt in 1 2; do
  APP_DIR=/srv/sample.app APP_USER=sample bash "${ROOT_DIR}/scripts/deploy-worker-cron.sh"
done
check test "$(wc -l <"${CRON_FIXTURE}" | tr -d ' ')" = 2
check grep -Fxq '0 3 * * * echo unrelated' "${CRON_FIXTURE}"
check grep -Fxq "* * * * * php '/srv/sample.app/server/bin/worker.php' >> '/srv/worker.log' 2>&1" "${CRON_FIXTURE}"
check bash -c '! CRON_FAIL=1 APP_DIR=/srv/other APP_USER=sample bash "$1"' _ "${ROOT_DIR}/scripts/deploy-worker-cron.sh"
unset -f sudo crontab
echo "Deploy configuration tests: ${passed} passed, 0 failed"
