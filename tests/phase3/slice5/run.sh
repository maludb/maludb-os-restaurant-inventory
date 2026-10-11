#!/usr/bin/env bash
# Slice 5's proofs — POS sources, sales and depletion, the second exemplar (docs/build-specs/pos.md "Proof") — on a fresh SCRATCH database
# (ri_dev3e): the application on :9320 (php -S, or RI_APP=apache with the rendered vhost on :9320 and the internal vhost :9324), the fake
# kernel :9321, the fake MaluDB + MaluMail with the mailbox MCP :9322, the POS fixture server (a fake Square Orders API) :9323 — slice 5's
# port block (design §14), apart from every other slice's so the proofs can run at once. Then the registry, the approvals and the Phase 1
# claim check. Needs `sudo -n -u postgres`, php, node with the kernel's web/node_modules (Playwright) for the browser proof.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export RI_DEV_DB=${RI_DEV_DB:-ri_dev3e} RI_DEV_ENV=${RI_DEV_ENV:-/tmp/ri-dev3e.env} RI_DEV_STATE=${RI_DEV_STATE:-/tmp/ri-dev3e-state} SHOTS=${SHOTS:-/tmp/ri-shots-s5}
export P_APP=${P_APP:-9320} P_KERNEL=${P_KERNEL:-9321} P_FAKE=${P_FAKE:-9322} P_FIX=${P_FIX:-9323}
mkdir -p "$SHOTS"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase3/slice5/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase3/slice5/servers.sh" stop' EXIT
set -a; . "$RI_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$RI_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$RI_DEV_STATE/maludb.log" FAKE_MALUMAIL_STATE="$RI_DEV_STATE/malumail.json" FAKE_MALUMAIL_LOG="$RI_DEV_STATE/malumail.log"
export RI_PICKER_EXTRA="$ROOT/tests/phase2/picker.php" MALUMAIL_MCP_URL="http://127.0.0.1:$P_FAKE/mcp" RI_INTAKE_MAILBOX="pos-intake@smoke.invalid" FIXTURE_STATE="$RI_DEV_STATE/fixture" RI_SQUARE_BASE_URL="http://127.0.0.1:$P_FIX"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0; total=0
for p in sources credentials mappings import depletion worker health; do
  echo "== $p"; out=$(php "tests/phase3/slice5/$p.php"); rc=$?; echo "$out" | grep -E '^  FAIL|passed|FAILED'; [ $rc -eq 0 ] || status=1
  total=$((total + $(echo "$out" | grep -cE '^  ok')))
done
echo "== browser"; out=$(node tests/phase3/slice5/browser.mjs); rc=$?; echo "$out" | grep -E '^  FAIL|passed|FAILED'; [ $rc -eq 0 ] || status=1; total=$((total + $(echo "$out" | grep -cE '^  ok')))
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && { echo "  ok   the registry matches the manifest"; total=$((total+1)); } || { echo "  FAIL the registry is stale"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && { echo "  ok   approvals[] matches"; total=$((total+1)); } || { echo "  FAIL approvals[] is stale"; status=1; }
echo "== specs"; bash tests/phase1/check_specs.sh >/dev/null 2>&1 && { echo "  ok   every manifest row claimed once"; total=$((total+1)); } || { echo "  FAIL the claim check"; status=1; }
echo "== $total ok"; [ $status -eq 0 ] && echo "ALL SLICE 5 PROOFS PASSED (${RI_APP:-php})" || echo "SOME SLICE 5 PROOFS FAILED"
exit $status
