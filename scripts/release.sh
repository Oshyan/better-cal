#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

REPO="Oshyan/better-cal"
ASSET="bettercal-release.json"

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

case "${1:-}" in
  prepare)
    priority=${2:-}
    floor=${3:-}
    [[ "$priority" == routine || "$priority" == recommended || "$priority" == security ]] || die "priority must be routine, recommended, or security"
    [[ "$floor" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "minimum secure version must be X.Y.Z"
    clean_main
    manifest_dir=$(mktemp -d)
    trap 'rm -rf "$manifest_dir"' EXIT
    node scripts/release-manifest.mjs --write "$manifest_dir/$ASSET" --version "$version" --priority "$priority" --minimum-secure-version "$floor"
    node scripts/release-manifest.mjs --verify "$manifest_dir/$ASSET" --version "$version"
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
    else
      gh release create "$tag" --repo "$REPO" --draft --verify-tag --title "Better-Cal $tag" --generate-notes "$manifest_dir/$ASSET#$ASSET"
    fi
    echo "Draft $tag is ready. Deploy this exact commit and complete smoke checks before publishing."
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
    node scripts/release-manifest.mjs --verify "$manifest_dir/$ASSET" --version "$version"
    immutable_enabled=$(gh api -H 'X-GitHub-Api-Version: 2026-03-10' "repos/$REPO/immutable-releases" --jq '.enabled // false')
    [[ "$immutable_enabled" == true ]] || die "enable immutable releases for $REPO before publishing"
    gh release edit "$tag" --repo "$REPO" --draft=false --latest
    immutable=$(gh api -H 'X-GitHub-Api-Version: 2026-03-10' "repos/$REPO/releases/tags/$tag" --jq '.immutable // false')
    [[ "$immutable" == true ]] || die "$tag published, but GitHub did not report it immutable"
    echo "Published and verified immutable: $tag"
    ;;
  *)
    echo "usage: scripts/release.sh prepare routine|recommended|security MINIMUM_SECURE_VERSION" >&2
    echo "   or: scripts/release.sh publish --deployment-verified" >&2
    exit 2
    ;;
esac
