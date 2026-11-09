#!/bin/sh
# Runs the whole checklist against one TYPO3 installation.
#
#   sh Tests/Manual/run.sh /path/to/typo3-install [php-binary]
#
# The installation must have this extension installed (a path repository or a
# symlink in vendor/ is fine), a reachable database, and GD with the formats you
# care about. Nothing needs to be served over HTTP: the harness boots TYPO3 in
# CLI mode and renders Fluid directly.
#
# It creates fixtures under <install>/public/fileadmin/jw_watermark_test/ and
# leaves them in place for the next run. Remove that folder to start over.
set -eu

ROOT=${1:-}
PHP=${2:-php}

if [ -z "$ROOT" ]; then
    echo "usage: sh Tests/Manual/run.sh <typo3-install-root> [php-binary]" >&2
    exit 2
fi
if [ ! -f "$ROOT/vendor/autoload.php" ]; then
    echo "no vendor/autoload.php under $ROOT — is that a Composer-based TYPO3 installation?" >&2
    exit 2
fi

DIR=$(dirname "$0")

echo "== fixtures"
"$PHP" "$DIR/make-images.php" "$ROOT"

echo "== cold cache"
"$PHP" "$DIR/reset-processed.php" "$ROOT"

# Both passes must run even if the first reports failures, so `set -e` must not
# abort here — hence the explicit `|| STATUS=$?` on each.
STATUS=0

echo "== pass 1 (generates the processed files)"
"$PHP" "$DIR/run-test.php" "$ROOT" 1 || STATUS=$?

echo "== pass 2 (must serve everything from cache)"
"$PHP" "$DIR/run-test.php" "$ROOT" 2 || STATUS=$?

exit "$STATUS"
