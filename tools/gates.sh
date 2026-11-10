#!/usr/bin/env bash
# The gates, at three levels (CONTRIBUTING.md, "Gates: Three Levels"):
#
#   tools/gates.sh quick [db-test ...]   while working: the unit suite, the frontend
#                                        suites, and only the database test files named
#                                        (google-series, undo, ...). A minute or two.
#   tools/gates.sh merge [--since REF]   after a merge: every suite, side by side. With
#                                        --since, also the mutations aimed at files changed
#                                        since REF and, when the repeating-event engine or
#                                        the Google write path changed, one seed per mode.
#   tools/gates.sh full                  before a push to main: every suite, the oracle
#                                        probes, one recorded seed and one fresh seed in
#                                        each harness mode as shipped, and the whole
#                                        mutation list. Everything that can runs at once.
#
# BC_TEST_DB (host:port, default 127.0.0.1:3306), BC_TEST_DB_USER and BC_TEST_DB_PASS as
# the suites read them. GATES_LOG is where each gate's output goes (default: a new temp
# folder, printed first). GATES_SEED fixes the fresh seed (default: drawn, and printed).
# Exit status 0 only when every gate passed; a gate that did not finish has not passed.
set -u
cd "$(dirname "$0")/.." || exit 1
LEVEL="${1:-}"
case "$LEVEL" in
  quick | merge | full) shift ;;
  *) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
export BC_TEST_DB="${BC_TEST_DB:-127.0.0.1:3306}"
LOG="${GATES_LOG:-$(mktemp -d "${TMPDIR:-/tmp}/bc-gates.XXXXXX")}"
mkdir -p "$LOG"
RECORDED=7
FRESH="${GATES_SEED:-$(( (RANDOM * 32768 + RANDOM) % 90000 + 10000 ))}"
FAILED=0
PIDS=()
NAMES=()

# start <name> <command...>: run a gate in the background, its output in $LOG/<name>.txt.
start() {
  local name="$1"; shift
  ( "$@" >> "$LOG/$name.txt" 2>&1 ) &
  PIDS+=("$!")
  NAMES+=("$name")
}

# settle: wait for every started gate and print one line each.
settle() {
  local i status last
  for i in "${!PIDS[@]}"; do
    wait "${PIDS[$i]}"
    status=$?
    last="$(grep -E 'passed|failed|failing check|ok \(|missed' "$LOG/${NAMES[$i]}.txt" 2>/dev/null | tail -1 | cut -c1-140)"
    if [ "$status" -eq 0 ]; then
      echo "ok    ${NAMES[$i]}: $last"
    else
      echo "FAIL  ${NAMES[$i]} (exit $status): $last  [$LOG/${NAMES[$i]}.txt]"
      FAILED=1
    fi
  done
  PIDS=()
  NAMES=()
}

suites_light() { # no database server needed
  start unit php server/tests/run.php
  start smoke env TZ=America/Los_Angeles node web/tests/smoke.mjs
  start static node --experimental-vm-modules web/tests/static.mjs
  start mcp node tools/mcp/test.mjs
  start google-probe php tools/google-probe/test.php
}

suites_all() {
  suites_light
  start db php server/tests/db/run.php
  start deploy-security bash scripts/tests/deploy-security.sh
  start release-security bash scripts/tests/release-security.sh
}

harness() { # harness <name> <args...>: one harness run, its findings beside its log
  local name="$1"; shift
  start "$name" ./tools/edit-harness/run.sh "$@" --quiet --findings "$LOG/$name.json"
}

# One seed in each mode, as shipped (whole-series undo on Google off), at the counts CI runs.
seeds() { # seeds <seed> [extra interleave args...]
  local s="$1"; shift
  harness "local-$s" --seed "$s" --cases 1000 --shards 4
  harness "google-$s" --google --seed "$s" --cases 1000 --shards 4 --series-undo off
  harness "interleave-$s" --interleave --seed "$s" --cases 300 --fixed --no-shrink --series-undo off "$@"
}

# The ids of the mutations aimed at a file changed since REF, comma separated.
mutations_since() {
  git diff --name-only "$1"...HEAD > "$LOG/changed.txt" 2>/dev/null
  php -r '$changed = array_flip(file($argv[2], FILE_IGNORE_NEW_LINES)); $ids = []; foreach (require $argv[1] as $m) { if (isset($changed[$m["file"]])) { $ids[] = $m["id"]; } } echo implode(",", $ids);' \
    tools/edit-harness/mutations/list.php "$LOG/changed.txt"
}

# Whether the repeating-event engine, the Google write path or the harness itself changed since REF.
engine_changed() {
  git diff --name-only "$1"...HEAD | grep -qE '^(server/src/Domain/(Series/|GoogleSeries|GoogleSync|GoogleWriter|GoogleUndo|Undo|Feeds|SeriesPlans|Events|Ics)|server/src/Dav/|tools/edit-harness/|tools/google-probe/lib/FakeGoogle)'
}

echo "gates: $LEVEL on $(git log -1 --format=%h), logs in $LOG"
case "$LEVEL" in
  quick)
    suites_light
    for t in "$@"; do
      start "db-$t" env BC_TEST_ONLY="$t" BC_TEST_JOBS=1 php server/tests/db/run.php
    done
    settle
    ;;
  merge)
    SINCE=""
    [ "${1:-}" = "--since" ] && SINCE="${2:-}"
    suites_all
    settle
    if [ -n "$SINCE" ]; then
      IDS="$(mutations_since "$SINCE")"
      [ -n "$IDS" ] && start mutations php tools/edit-harness/mutations/run.php --only "$IDS"
      engine_changed "$SINCE" && seeds "$RECORDED"
      settle
    fi
    ;;
  full)
    suites_all
    settle
    start oracle-probes ./tools/edit-harness/run.sh --interleave --oracle-probes
    start mutations php tools/edit-harness/mutations/run.php
    seeds "$RECORDED"
    settle
    echo "fresh seed: $FRESH"
    seeds "$FRESH" --flips 0.35 --day-shapes 0.35
    settle
    ;;
esac
[ "$FAILED" -eq 0 ] && echo "gates: all passed" || echo "gates: FAILED"
exit "$FAILED"
