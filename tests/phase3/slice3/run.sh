#!/usr/bin/env bash
# Slice 3's proofs — the ledger: receiving, transfers, adjustments, production, waste, stock levels, movements and value (docs/build-specs/ledger.md
# "Proof") — on a fresh SCRATCH database (ri_dev3d), the application on :9316 (php -S, or RI_APP=apache with the rendered vhost on :9316 and the
# internal vhost :9319), the fake kernel :9317, the fake MaluDB :9318 — this slice's own port block (design §14), so it runs beside any other proof.
# Then the registry, the approvals and the Phase 1 claim check. Needs `sudo -n -u postgres`, php, node with the kernel's web/node_modules (Playwright).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export RI_DEV_DB=${RI_DEV_DB:-ri_dev3d} RI_DEV_ENV=${RI_DEV_ENV:-/tmp/ri-dev3d.env} RI_DEV_STATE=${RI_DEV_STATE:-/tmp/ri-dev3d-state} SHOTS=${SHOTS:-/tmp/ri-shots-s3}
export P_APP=${P_APP:-9316} P_KERNEL=${P_KERNEL:-9317} P_FAKE=${P_FAKE:-9318}
mkdir -p "$SHOTS"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase3/slice3/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase3/slice3/servers.sh" stop' EXIT
set -a; . "$RI_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$RI_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$RI_DEV_STATE/maludb.log" RI_PICKER_EXTRA="$ROOT/tests/phase2/picker.php"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0; total=0
for p in receipts levels transfers production json; do
  echo "== $p"; out=$(php "tests/phase3/slice3/$p.php" 2>&1); rc=$?; echo "$out" | grep -E '^  FAIL|passed|FAILED|Fatal|Warning'; [ $rc -eq 0 ] || status=1
  total=$((total + $(echo "$out" | grep -cE '^  ok')))
done
echo "== browser"; out=$(node tests/phase3/slice3/browser.mjs 2>&1); rc=$?; echo "$out" | grep -E '^  FAIL|passed|FAILED|Error'; [ $rc -eq 0 ] || status=1; total=$((total + $(echo "$out" | grep -cE '^  ok')))
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && { echo "  ok   the registry matches the manifest"; total=$((total+1)); } || { echo "  FAIL the registry is stale"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && { echo "  ok   approvals[] matches"; total=$((total+1)); } || { echo "  FAIL approvals[] is stale"; status=1; }
echo "== specs"; bash tests/phase1/check_specs.sh >/dev/null 2>&1 && { echo "  ok   every manifest row claimed once"; total=$((total+1)); } || { echo "  FAIL the claim check"; status=1; }
echo "== $total ok"; [ $status -eq 0 ] && echo "ALL SLICE 3 PROOFS PASSED (${RI_APP:-php})" || echo "SOME SLICE 3 PROOFS FAILED"
exit $status
