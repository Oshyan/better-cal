#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

REPO="Oshyan/better-cal"
ASSET="bettercal-release.json"
# The release zip (source plus PHP libraries), built by .github/workflows/release-zip.yml.
zip_name() { echo "better-cal-$1.zip"; }
SIG="bettercal-release.sig"
# The Composer that builds the release's PHP libraries, here and in
# .github/workflows/release-zip.yml: the same version, so the same files.
COMPOSER_VERSION=2.10.3
COMPOSER_SHA256=7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6

# Rebuild the release here, from the tag and the lock file, and compare it
# file by file with the zip GitHub built: the signature vouches for the zip's
# bytes, so it must never be put on a zip that differs from the tagged source
# (a changed build, or a zip swapped on the draft by someone holding the
# GitHub account).
verify_zip() {
  local zip=$1 work phar
  work=$(mktemp -d)
  phar="$HOME/.cache/better-cal/composer-$COMPOSER_VERSION.phar"
  if [[ ! -f "$phar" ]]; then
    mkdir -p "$(dirname "$phar")"
    curl -fsSLo "$phar.part" "https://getcomposer.org/download/$COMPOSER_VERSION/composer.phar"
    mv "$phar.part" "$phar"
  fi
  [[ $(shasum -a 256 "$phar" | cut -d' ' -f1) == "$COMPOSER_SHA256" ]] || die "$phar isn't Composer $COMPOSER_VERSION (SHA-256 differs)"
  mkdir -p "$work/src" "$work/ours" "$work/theirs"
  # Outside git, as on GitHub, where the build sees no repository: Composer
  # records the app's version from git when it can.
  git archive "$tag" server | tar -x -C "$work/src"
  (cd "$work/src/server" && COMPOSER_HOME="$work/composer-home" php "$phar" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --quiet)
  scripts/release-assemble.sh "$tag" "$work/src/server/vendor" "$work/build" "$work/ours.zip"
  (cd "$work/ours" && unzip -q ../ours.zip)
  (cd "$work/theirs" && unzip -q "$zip")
  if ! diff -r "$work/ours" "$work/theirs" >"$work/diff.txt"; then
    head -20 "$work/diff.txt" >&2
    die "GitHub's $(basename "$zip") differs from the release rebuilt here from $tag; not signing it"
  fi
  rm -rf "$work"
  echo "$(basename "$zip") matches the release rebuilt here from $tag."
}

# Check (and with "sign", first make) the release signature for $tag: the
# statement names the version, the tag's commit and the SHA-256 of the zip and
# manifest as attached to the release. Signed with the hardware security key
# (scripts/release-sign-fido.py: its PIN and a touch), or with --recovery, the
# key file kept in the password manager (scripts/release-sign.php), for when
# the hardware key is lost (docs/releasing.md). No private key touches
# GitHub; installs verify with server/config/release-keys.php.
release_signature() {
  local mode=$1 dir commit
  dir=$(mktemp -d)
  gh release download "$tag" --repo "$REPO" --pattern "$(zip_name "$version")" --pattern "$ASSET" --dir "$dir"
  commit=$(git rev-parse "$tag^{commit}")
  if [[ "$mode" == sign ]]; then
    verify_zip "$dir/$(zip_name "$version")"
    php scripts/release-sign.php --statement --version="$version" --commit="$commit" \
      --zip="$dir/$(zip_name "$version")" --manifest="$dir/$ASSET" --out="$dir/statement.txt"
    if [[ "$recovery" == true ]]; then
      echo "Signing with the recovery key file."
      php scripts/release-sign.php --sign --statement-file="$dir/statement.txt" --out="$dir/$SIG"
    else
      echo "Signing with the security key: enter its PIN, then touch it."
      uv run --quiet --with fido2==2.2.1 scripts/release-sign-fido.py sign --statement-file "$dir/statement.txt" --out "$dir/$SIG"
    fi
  else
    gh release download "$tag" --repo "$REPO" --pattern "$SIG" --dir "$dir"
  fi
  php scripts/release-sign.php --verify="$dir/$SIG" --version="$version" --commit="$commit" \
    --zip="$dir/$(zip_name "$version")" --manifest="$dir/$ASSET" || die "the release signature does not verify"
  if [[ "$mode" == sign ]]; then
    gh release upload "$tag" --repo "$REPO" --clobber "$dir/$SIG"
  fi
}

die() { echo "release: $*" >&2; exit 1; }
remote_tag_commit() {
  local peeled direct
  peeled=$(git ls-remote origin "refs/tags/$1^{}" | cut -f1)
  if [ -n "$peeled" ]; then echo "$peeled"; return; fi
  direct=$(git ls-remote origin "refs/tags/$1" | cut -f1)
  echo "$direct"
}
version=$(tr -d '[:space:]' < VERSION)
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "VERSION must contain X.Y.Z"
tag="v$version"

clean_main() {
  [[ $(git branch --show-current) == main ]] || die "run releases from main"
  [[ -z $(git status --porcelain) ]] || die "working tree is not clean"
  git fetch origin main --tags
  local head remote
  head=$(git rev-parse HEAD)
  remote=$(git rev-parse origin/main)
  [[ "$head" == "$remote" ]] || die "HEAD must exactly match origin/main"
  grep -Eq "^## .*${version//./\\.}([[:space:]]|$)" CHANGELOG.md || die "CHANGELOG.md has no $version section"
  local ci
  ci=$(gh run list --repo "$REPO" --workflow tests.yml --commit "$head" --limit 1 --json status,conclusion,headSha --jq '.[0] | select(.headSha == "'"$head"'") | [.status,.conclusion] | @tsv')
  [[ "$ci" == $'completed\tsuccess' ]] || die "the tests workflow for $head is not green"
}

recovery=false
case "${1:-}" in
  prepare)
    priority=${2:-}
    floor=${3:-}
    [[ "${4:-}" == "" || "${4:-}" == --recovery ]] || die "unknown option ${4:-}"
    [[ "${4:-}" == --recovery ]] && recovery=true
    [[ "$priority" == routine || "$priority" == recommended || "$priority" == security ]] || die "priority must be routine, recommended, or security"
    [[ "$floor" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "minimum secure version must be X.Y.Z"
    clean_main
    manifest_dir=$(mktemp -d)
    trap 'rm -rf "$manifest_dir"' EXIT
    node scripts/release-manifest.mjs --write "$manifest_dir/$ASSET" --version "$version" --priority "$priority" --minimum-secure-version "$floor"
    node scripts/release-manifest.mjs --verify "$manifest_dir/$ASSET" --version "$version"
    # The release page is this version's changelog entry plus how to update
    # (scripts/release-notes.mjs); the app's update notice links to it.
    previous=$(git describe --tags --abbrev=0 --match 'v[0-9]*' HEAD^ 2>/dev/null || true)
    node scripts/release-notes.mjs --version "$version" ${previous:+--previous "$previous"} --write "$manifest_dir/notes.md"
    if git rev-parse -q --verify "refs/tags/$tag" >/dev/null; then
      [[ $(git rev-list -n 1 "$tag") == $(git rev-parse HEAD) ]] || die "existing local $tag points somewhere else"
    else
      git tag -a "$tag" HEAD -m "Better-Cal $tag"
    fi
    remote_tag=$(remote_tag_commit "$tag")
    if [ -n "$remote_tag" ]; then
      [[ "$remote_tag" == $(git rev-parse "$tag^{commit}") ]] || die "existing remote $tag points somewhere else"
    else
      git push origin "refs/tags/$tag"
    fi
    if gh release view "$tag" --repo "$REPO" >/dev/null 2>&1; then
      [[ $(gh release view "$tag" --repo "$REPO" --json isDraft --jq .isDraft) == true ]] || die "existing $tag release is already published"
      gh release upload "$tag" --repo "$REPO" --clobber "$manifest_dir/$ASSET#$ASSET"
      gh release edit "$tag" --repo "$REPO" --notes-file "$manifest_dir/notes.md"
    else
      gh release create "$tag" --repo "$REPO" --draft --verify-tag --title "Better-Cal $tag" --notes-file "$manifest_dir/notes.md" "$manifest_dir/$ASSET#$ASSET"
    fi
    # The release zip is built on GitHub from the tag, in the same PHP image
    # the tests use, and attached to this draft before it is published.
    echo "Building $(zip_name "$version") on GitHub..."
    started=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    gh workflow run release-zip.yml --repo "$REPO" --ref main -f tag="$tag"
    run_id=""
    for _ in $(seq 1 30); do
      run_id=$(gh run list --repo "$REPO" --workflow release-zip.yml --event workflow_dispatch --limit 5 --json databaseId,createdAt \
        --jq "[.[] | select(.createdAt >= \"$started\")][0].databaseId // empty")
      [ -n "$run_id" ] && break
      sleep 2
    done
    [ -n "$run_id" ] || die "the release zip build did not start"
    gh run watch "$run_id" --repo "$REPO" --exit-status >/dev/null || die "the release zip build failed: gh run view $run_id --repo $REPO --log-failed"
    [[ $(gh release view "$tag" --repo "$REPO" --json assets --jq '[.assets[] | select(.name == "'"$(zip_name "$version")"'")] | length') == 1 ]] \
      || die "the draft has no $(zip_name "$version")"
    release_signature sign
    echo "Draft $tag is ready, with $(zip_name "$version") and its signature. Deploy this exact commit and complete smoke checks before publishing."
    ;;
  publish)
    [[ "${2:-}" == --deployment-verified ]] || die "publish requires --deployment-verified after deploying and smoke-testing this exact commit"
    clean_main
    [[ $(git rev-list -n 1 "$tag") == $(git rev-parse HEAD) ]] || die "$tag does not point to HEAD"
    [[ $(remote_tag_commit "$tag") == $(git rev-parse "$tag^{commit}") ]] || die "remote tag does not point to HEAD"
    draft=$(gh release view "$tag" --repo "$REPO" --json isDraft --jq .isDraft)
    [[ "$draft" == true ]] || die "$tag is not a draft release"
    manifest_dir=$(mktemp -d)
    trap 'rm -rf "$manifest_dir"' EXIT
    gh release download "$tag" --repo "$REPO" --pattern "$ASSET" --dir "$manifest_dir"
    [[ $(gh release view "$tag" --repo "$REPO" --json assets --jq '[.assets[] | select(.name == "'"$ASSET"'")] | length') == 1 ]] || die "draft must contain exactly one $ASSET"
    [[ $(gh release view "$tag" --repo "$REPO" --json assets --jq '[.assets[] | select(.name == "'"$(zip_name "$version")"'")] | length') == 1 ]] || die "draft must contain exactly one $(zip_name "$version"); run prepare again"
    node scripts/release-manifest.mjs --verify "$manifest_dir/$ASSET" --version "$version"
    release_signature check
    immutable_enabled=$(gh api -H 'X-GitHub-Api-Version: 2026-03-10' "repos/$REPO/immutable-releases" --jq '.enabled // false')
    [[ "$immutable_enabled" == true ]] || die "enable immutable releases for $REPO before publishing"
    gh release edit "$tag" --repo "$REPO" --draft=false --latest
    immutable=$(gh api -H 'X-GitHub-Api-Version: 2026-03-10' "repos/$REPO/releases/tags/$tag" --jq '.immutable // false')
    [[ "$immutable" == true ]] || die "$tag published, but GitHub did not report it immutable"
    echo "Published and verified immutable: $tag"
    ;;
  *)
    echo "usage: scripts/release.sh prepare routine|recommended|security MINIMUM_SECURE_VERSION [--recovery]" >&2
    echo "   or: scripts/release.sh publish --deployment-verified" >&2
    exit 2
    ;;
esac
