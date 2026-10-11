#!/usr/bin/env bash
# Runs every Phase 2 proof on a fresh SCRATCH database (never the installed one): tests/phase2/run.sh
#   RI_APP=apache tests/phase2/run.sh   serves the app from a real Apache with the rendered deploy vhost instead of php -S
#   needs: `sudo -n -u postgres`, php, /var/www/mcp/venv (asyncpg + httpx, for the ingest proof), node with the kernel's web/node_modules
#   (Playwright + its Chromium, for the browser proof). Screenshots go to $SHOTS (default /tmp/ri-shots).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export RI_DEV_DB=${RI_DEV_DB:-ri_dev2} RI_DEV_ENV=${RI_DEV_ENV:-/tmp/ri-dev2.env} RI_DEV_STATE=${RI_DEV_STATE:-/tmp/ri-dev2-state} SHOTS=${SHOTS:-/tmp/ri-shots}
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
for p in sso gates sites sync ingest kernel_compat vhost; do
  echo "== $p"; php "tests/phase2/$p.php" || status=1
done
echo "== browser"; node tests/phase2/browser.mjs || status=1
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   maludb-os.json approvals[] matches the manifest" || { echo "  FAIL approvals[] is stale — run bin/sync_approvals.php"; status=1; }
[ $status -eq 0 ] && echo "ALL PHASE 2 PROOFS PASSED" || echo "SOME PHASE 2 PROOFS FAILED"
exit $status
