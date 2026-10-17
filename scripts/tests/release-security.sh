#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
php scripts/tests/release-security.php
python3 scripts/tests/signer-security.py
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
sed '/^version=/,$d' scripts/release.sh > "$work/helpers.sh"
# Load only helper declarations. Never run prepare/publish or a real signer.
for recovery in true false; do
  bash -s -- "$PWD" "$work" "$recovery" <<'TEST'
set -euo pipefail
repo=$1 work=$2 recovery=$3
source "$work/helpers.sh"
cd "$repo"
version=1.2.3 tag=v1.2.3
printf 'intended\n' > "$work/expected"
download_asset() { printf 'substituted\n' > "$2"; }
git() { printf '%040d\n' 1; }
verify_zip() { touch "$work/unexpected-zip-check"; }
if (release_signature sign "$work/expected"); then exit 1; fi
[[ ! -e "$work/unexpected-zip-check" ]]
# Matching intent reaches the chosen signer, but both are intercepted.
download_asset() { printf 'intended\n' > "$2"; }
verify_zip() { :; }
php() {
  if [[ " $* " == *' --sign '* ]]; then touch "$work/recovery-signer"; return 42; fi
  if [[ " $* " == *' --statement '* ]]; then command php "$@"; return; fi
  return 43
}
bash() { touch "$work/fido-signer"; return 42; }
if (release_signature sign "$work/expected"); then exit 1; fi
if [[ "$recovery" == true ]]; then [[ -f "$work/recovery-signer" ]]; else [[ -f "$work/fido-signer" ]]; fi
TEST
done
printf '12345' | php scripts/release-download.php "$work/within" 5
if printf '123456' | php scripts/release-download.php "$work/over" 5; then exit 1; fi
[[ ! -e "$work/over" ]]
if BETTERCAL_SIGNER_DIR="$work/not-prepared" bash scripts/release-signer.sh check; then exit 1; fi
echo 'Release gates: both signer paths reject substituted manifests; bounded downloads passed.'
