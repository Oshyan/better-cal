#!/usr/bin/env bash
# Explicit online preparation; ordinary checking/signing never invokes a resolver.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
base=${BETTERCAL_SIGNER_DIR:-$ROOT/.release-signer}
pin=$(tr -d '\n' < "$ROOT/scripts/release-signer/python-version")
lock="$ROOT/scripts/release-signer/requirements.txt"
fail() { echo "release-signer: $*" >&2; exit 1; }
case "${1:-}" in
  prepare)
    [[ ! -e "$base/venv" ]] || fail 'environment already exists; use a new BETTERCAL_SIGNER_DIR to prepare its replacement'
    mkdir -p "$base"
    base=$(cd "$base" && pwd)
    export UV_PYTHON_INSTALL_DIR="$base/python"
    export UV_CACHE_DIR="$base/cache"
    uv --no-config python install "$pin" --no-bin
    uv --no-config venv --managed-python --no-python-downloads --python "$pin" "$base/venv"
    uv --no-config pip sync --python "$base/venv/bin/python" --require-hashes --only-binary :all: "$lock"
    "$base/venv/bin/python" -I -S "$ROOT/scripts/release-signer/check.py" prepare "$base" "$ROOT/scripts/release-signer"
    echo 'Signing environment prepared. No key was accessed.'
    ;;
  check|sign|enroll)
    [[ -x "$base/venv/bin/python" ]] || fail 'run bash scripts/release-signer.sh prepare first (use a current uv with the pinned Python available)'
    "$base/venv/bin/python" -I -S "$ROOT/scripts/release-signer/check.py" check "$base" "$ROOT/scripts/release-signer"
    [[ "$1" != check ]] || exit 0
    exec "$base/venv/bin/python" -I -B "$ROOT/scripts/release-sign-fido.py" "$@"
    ;;
  *) fail 'usage: bash scripts/release-signer.sh prepare|check|sign|enroll [signer options]' ;;
esac
