#!/usr/bin/env bash
# Slice 1's proof (docs/build-specs/catalog.md "Proof") on a fresh SCRATCH database: tests/phase3/slice1/run.sh
#   RI_APP=apache tests/phase3/slice1/run.sh   serves the app from a real Apache with the rendered deploy vhost instead of php -S
#   The world (lib.php catalog_world()) is made through the handlers; the checks are curl + SQL + headless Chromium (browser.mjs).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export RI_DEV_DB=${RI_DEV_DB:-ri_dev3a} RI_DEV_ENV=${RI_DEV_ENV:-/tmp/ri-dev3a.env} RI_DEV_STATE=${RI_DEV_STATE:-/tmp/ri-dev3a-state} SHOTS=${SHOTS:-/tmp/ri-shots-slice1}
mkdir -p "$SHOTS"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$RI_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:9301" OS_INTERNAL_URL="http://127.0.0.1:9302" MALUDB_API_URL="http://127.0.0.1:9303" MALUMAIL_API_URL="http://127.0.0.1:9303"
export FAKE_KERNEL_STATE="$RI_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$RI_DEV_STATE/maludb.log" RI_PICKER_EXTRA="$ROOT/tests/phase2/picker.php"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
echo "== catalog"; php tests/phase3/slice1/catalog.php || status=1
echo "== browser"; node tests/phase3/slice1/browser.mjs || status=1
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   approvals[] matches the manifest" || { echo "  FAIL approvals[] is stale"; status=1; }
echo "== specs"; php tests/phase1/check_specs.php >/dev/null 2>&1 && echo "  ok   every manifest row is claimed once" || { echo "  FAIL check_specs"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 1 PROOFS PASSED" || echo "SOME SLICE 1 PROOFS FAILED"
exit $status
