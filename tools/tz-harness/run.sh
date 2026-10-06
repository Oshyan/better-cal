#!/bin/sh
# Time zone cross-check: Better-Cal's repeat expansion and export against two
# independent engines. See README.md. Needs PHP with server/vendor installed,
# uv (Python 3.12) and Node 20+. About a minute.
set -e
cd "$(dirname "$0")"
[ -d node_modules ] || npm install --silent
php gen.php                                    # cases.json: the matrix, Better-Cal's expansion and export
uv run python goodtz.py >/dev/null             # marks series whose start is not a day of their own rule
uv run python oracle_py.py cases.json py.json  # recurring-ical-events (dateutil + zoneinfo)
node oracle_icaljs.mjs cases.json icaljs.json  # ical.js (Thunderbird, Nextcloud), reading our VTIMEZONEs
node compare.mjs py.json icaljs.json
php roundtrip.php                              # export, re-import, expand: same occurrences?
