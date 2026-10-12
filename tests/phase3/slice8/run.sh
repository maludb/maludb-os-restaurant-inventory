#!/usr/bin/env bash
# Slice 8's proofs — projection and ordering: the forecast (SQL baseline, bin/forecast.py's Holt-Winters, runs, accuracy), overrides, the trading calendar and booked covers (K7 against the fake kernel), ingredient demand,
# projected stock, the suggested orders re-derived from the raw tables, the special check, the Purchasing agent's proposals and the morning note, watches, the worker's passes, JSON mode, who sees what
# (docs/build-specs/projection.md "Proof"; projection-and-ordering.md §7) — on a fresh SCRATCH database (ri_dev_s8), the application on :9337 (php -S, or RI_APP=apache with the rendered vhost on :9337 and the
# internal vhost :9340), the fake kernel :9338, the fake MaluDB + MaluMail :9339 — this slice's own port block (design §14), so it runs beside any other proof. Then the registry, the approvals and the Phase 1 claim
# check. Needs `sudo -n -u postgres`, php, python3, node with the kernel's web/node_modules (Playwright).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export RI_DEV_DB=${RI_DEV_DB:-ri_dev_s8} RI_DEV_ENV=${RI_DEV_ENV:-/tmp/ri-dev-s8.env} RI_DEV_STATE=${RI_DEV_STATE:-/tmp/ri-dev-s8-state} SHOTS=${SHOTS:-/tmp/ri-shots/slice8}
export P_APP=${P_APP:-9337} P_KERNEL=${P_KERNEL:-9338} P_FAKE=${P_FAKE:-9339} P_INTERNAL=${P_INTERNAL:-9340}
mkdir -p "$SHOTS"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase3/slice8/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase3/slice8/servers.sh" stop' EXIT
set -a; . "$RI_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$P_APP" RI_PUBLIC_BASE_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$RI_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$RI_DEV_STATE/maludb.log" FAKE_MALUMAIL_STATE="$RI_DEV_STATE/malumail.json" FAKE_MALUMAIL_LOG="$RI_DEV_STATE/malumail.log" RI_PICKER_EXTRA="$ROOT/tests/phase2/picker.php"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0; total=0
echo "== schema"; out=$(bash tests/phase0/schema.sh 2>&1); echo "$out" | grep -E 'FAIL|failed' | head -3
for p in forecast overrides calendar demand projected suggested special proposals watches worker visibility json; do
  echo "== $p"; out=$(php "tests/phase3/slice8/$p.php" 2>&1); rc=$?; echo "$out" | grep -E '^  FAIL|passed|FAILED|Fatal|Warning'; [ $rc -eq 0 ] || status=1
  total=$((total + $(echo "$out" | grep -cE '^  ok')))
done
echo "== browser"; out=$(node tests/phase3/slice8/browser.mjs 2>&1); rc=$?; echo "$out" | grep -E '^  FAIL|passed|FAILED|Error'; [ $rc -eq 0 ] || status=1; total=$((total + $(echo "$out" | grep -cE '^  ok')))
echo "== forecaster"; out=$(python3 -m py_compile bin/forecast.py 2>&1) && { echo "  ok   bin/forecast.py compiles"; total=$((total+1)); } || { echo "  FAIL bin/forecast.py"; status=1; }
echo "== server log"; if [ "${RI_APP:-php}" = "apache" ]; then log="$RI_DEV_STATE/apache/apache.err"; else log="$RI_DEV_STATE/app.log"; fi
bad=$(grep -E "PHP (Warning|Fatal|Notice|Deprecated)|Uncaught" "$log" 2>/dev/null | grep -v "ri_hx_redirect" | head -5)
[ -z "$bad" ] && { echo "  ok   the server log has no PHP warning, notice or fatal"; total=$((total+1)); } || { echo "  FAIL the server log:"; echo "$bad"; status=1; }
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && { echo "  ok   the registry matches the manifest"; total=$((total+1)); } || { echo "  FAIL the registry is stale"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && { echo "  ok   approvals[] matches"; total=$((total+1)); } || { echo "  FAIL approvals[] is stale"; status=1; }
echo "== specs"; bash tests/phase1/check_specs.sh >/dev/null 2>&1 && { echo "  ok   every manifest row claimed once"; total=$((total+1)); } || { echo "  FAIL the claim check"; status=1; }
echo "== $total ok"; [ $status -eq 0 ] && echo "ALL SLICE 8 PROOFS PASSED (${RI_APP:-php})" || echo "SOME SLICE 8 PROOFS FAILED"
exit $status
