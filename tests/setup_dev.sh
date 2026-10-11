#!/usr/bin/env bash
# Builds the SCRATCH database the proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres` — for scratch ri_* databases and the three restaurant_inventory_* roles only;
#                                  recreates $RI_DEV_DB, default ri_dev0)
# 1. drops and creates the scratch database; 2. applies db/[0-9]*.sql in order as postgres (the same files the installer applies) —
#    RI_MIGRATIONS_UPTO=006 (or RI_DEV_UPTO) stops after the kit's db/001–006 while the schema's later files are being written;
#    3. leaves the cluster roles' passwords ALONE when the app is installed (reads them from config/.env — or the install's, from a
#    worktree: RI_LIVE_ENV names its config/.env; /srv/apps/restaurant_inventory is the install path — DocCloud's guard); else gives
#    them a scratch password (the kernel's installer sets fresh ones at `apply`); 4. makes a scratch attachment store ($RI_DEV_STORAGE,
#    default /tmp/<db>-storage); 5. writes the proofs' environment to $RI_DEV_ENV (default /tmp/ri-dev0.env, mode 600) —
#    app/bootstrap.php reads $RI_DEV_ENV instead of config/.env when it is set (never in production). Nothing here is committed or
#    installed. Ports 9301 (the app), 9302 (the fake kernel), 9303 (the fake MaluDB + MaluMail REST), 9304–9305 (the MCP servers,
#    Phase 4), 9306–9307 spare.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${RI_DEV_DB:-ri_dev0}
case "$DB" in ri_*) ;; *) echo "refused: the scratch database must be named ri_* (got $DB)"; exit 2 ;; esac
ENVF=${RI_DEV_ENV:-/tmp/ri-dev0.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
UPTO=${RI_MIGRATIONS_UPTO:-${RI_DEV_UPTO:-999}}
for f in "$ROOT"/db/[0-9]*.sql; do n=$(basename "$f" | cut -c1-3); [ "$((10#$n))" -le "$((10#$UPTO))" ] 2>/dev/null || continue; $PSQL -d "$DB" -f "$f" >/dev/null || { echo "FAILED: $f"; exit 1; }; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
# A worktree (or a second clone) of an INSTALLED application has no config/.env of its own but shares the cluster's three roles with
# the install: resetting their passwords here would take the installed application down. Reuse the install's passwords instead.
if ! { [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ]; }; then
  for cand in "${RI_LIVE_ENV:-}" /srv/apps/restaurant_inventory/config/.env; do
    if [ -n "$cand" ] && [ -r "$cand" ] && grep -q '^DB_PASSWORD=.' "$cand"; then LIVEENV=$cand; break; fi
  done
fi
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in restaurant_inventory_rw restaurant_inventory_records_ro restaurant_inventory_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
STORE=${RI_DEV_STORAGE:-/tmp/${DB}-storage}          # the attachment store: a scratch one, emptied
rm -rf "$STORE"; mkdir -p "$STORE"/{files,tmp}
umask 077
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=1
APP_NAME="Restaurant Inventory"
APP_KEY=restaurant_inventory
APP_URL=http://127.0.0.1:9301
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=restaurant_inventory_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=restaurant_inventory_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=restaurant_inventory_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=9301
MCP_RECORDS_PORT=9304
MCP_ACTIVITY_PORT=9305
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:9302
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:9303
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:9303
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
MAIL_FROM=kitchen@example.invalid
MAIL_FROM_NAME="Restaurant Inventory"
RI_SECRETS_KEY=$(openssl rand -hex 32)
RI_STORAGE_DIR=$STORE
RI_PUBLIC_BASE_URL=http://127.0.0.1:9301
RI_MANAGER_EMAIL=
ENV
echo "scratch database $DB ready; environment in $ENVF"
