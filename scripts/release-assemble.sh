#!/usr/bin/env bash
# The release's file tree, assembled from a tag and its installed PHP
# libraries. One definition, used twice: by .github/workflows/release-zip.yml
# to build the zip, and by scripts/release.sh to rebuild the same tree on the
# maintainer's machine and compare it with GitHub's zip before signing it, so
# a changed build or a swapped zip can't be signed by mistake.
#
#   scripts/release-assemble.sh TAG VENDOR_DIR OUT_DIR ZIP
#
# Writes OUT_DIR/better-cal: the tagged source (less CI and test suites), its
# .release-files list, and VENDOR_DIR copied to server/vendor without the
# libraries' tools, examples, tests and docs. Then zips it to ZIP, with the
# files at the top level. zip follows the one symbolic link in the source
# (server/public/assets, to web/), so the zip holds a real copy there.
set -euo pipefail

tag=$1
vendor=$2
out=$3
zip=$4
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "release-assemble: bad tag $tag" >&2; exit 1; }
[[ -f "$vendor/autoload.php" ]] || { echo "release-assemble: no autoload.php in $vendor" >&2; exit 1; }
[[ ! -e "$out/better-cal" ]] || { echo "release-assemble: $out/better-cal already exists" >&2; exit 1; }
mkdir -p "$out"
# Everything but CI and the test suites, which aren't for running
# Better-Cal. (Excluded here rather than marked export-ignore, since the
# deploy script runs the suites from its own git archive.)
git archive --format=tar --prefix=better-cal/ "$tag" -- . \
  ':(exclude).github' ':(exclude)server/tests' ':(exclude)web/tests' \
  ':(exclude)scripts/tests' ':(exclude)tools/tz-harness' | tar -x -C "$out"
# The files this release ships, so bin/update.php can tell the ones someone
# added from ones a later release simply dropped.
(cd "$out/better-cal" && find . -type f | sed 's|^\./||' | LC_ALL=C sort > .release-files)
cp -R "$vendor" "$out/better-cal/server/vendor"
# The libraries' own command-line tools, examples, tests and docs: Better-Cal
# never runs them, and on a host that serves the app folder as a web root, a
# PHP script among them would run outside Better-Cal's checks. Only the
# libraries' code goes in the zip.
rm -rf "$out/better-cal/server/vendor/bin"
find "$out/better-cal/server/vendor" -mindepth 3 -maxdepth 3 -type d \
  \( -name bin -o -name examples -o -name example -o -name tests -o -name test -o -name Tests -o -name docs \) \
  -prune -exec rm -rf {} +
[[ -f "$out/better-cal/server/vendor/autoload.php" ]]
# Files at the zip's top level, no better-cal/ folder around them (since
# 0.9.84): hosting file managers extract into a folder you pick or name,
# often one the panel already made for a subdomain. update.php reads both
# layouts since 0.9.83.
(cd "$out/better-cal" && zip -q -r -X "$zip" .)
# (Read whole first: grep -q stopping early would fail the pipe under pipefail.)
listing=$(unzip -Z1 "$zip")
grep -qx 'VERSION' <<<"$listing"
! grep -q '^better-cal/' <<<"$listing"
