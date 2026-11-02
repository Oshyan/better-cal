#!/bin/sh
# Property harness for repeating-series edits: random series with
# exceptions, random edits through the real domain code, invariants
# checked against the rules. See README.md. Needs PHP with server/vendor
# installed and a MariaDB or MySQL server it may create databases on
# (BC_TEST_DB=host:port, BC_TEST_DB_USER, BC_TEST_DB_PASS). A few minutes.
set -e
cd "$(dirname "$0")"
exec php harness.php "$@"
