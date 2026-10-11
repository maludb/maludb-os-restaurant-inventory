#!/usr/bin/env bash
# Phase 0 proof, repeatable: the schema (db/proof/phase0_proof.sql, the schema's checks — skipped with a line when the file is
# not there yet) and the kit without a kernel (testing-without-a-kernel.md §4) — the directory fixture with TWO SITES applied,
# the roles from access[] per site, the five roles and what each gives, the settings and a site's settings seeded, a hand-off
# minted the kernel's way carrying scopes[] and the chosen scope, its replay / expiry / another audience / an unknown member /
# a tampered signature / no grant / no site refused and logged, a site not held → 404, the switcher changing the site and
# refusing one not held, ri_sees_cost per role and the site's kitchen_sees_cost flag, health, CSRF, the activity funnel with the
# site and the audit keys, a person's key and the rate rule, the sign-out notice ending sessions, a grant the kernel revoked and a
# site the kernel closed shutting the door within one sync, a site the kernel added materialised, the worker's empty pass, the
# ingest bridge against a fake MaluDB. Scratch database only (ri_dev0); the installed application (if any) is never touched.
# Ports 9301 (the app), 9302 (the fake kernel), 9303 (the fake MaluDB + MaluMail REST); 9304–9305 are the MCP servers' (Phase 4).
# Prints ok/FAIL per check and a count; exits non-zero on any failure. Needs `sudo -n -u postgres` (scratch ri_* databases and the
# three restaurant_inventory_* roles only), php 8.3, curl, openssl, and for the ingest check a python with asyncpg + httpx
# (/var/www/mcp/venv/bin/python, else $RI_PY).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export RI_DEV_DB=${RI_DEV_DB:-ri_dev0}
export RI_DEV_ENV=${RI_DEV_ENV:-/tmp/ri-dev0.env}
PY=${RI_PY:-/var/www/mcp/venv/bin/python}
PORT=9301; P_KERNEL=9302; P_FAKE=9303
TMP=$(mktemp -d /tmp/ri-kit.XXXXXX)
pass=0; fail=0; PIDS=()
ok()   { echo "ok   $1"; pass=$((pass+1)); }
bad()  { echo "FAIL $1"; fail=$((fail+1)); }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got: $1, wanted: $2)"; fi; }
has()  { if grep -q -- "$2" <<<"$1"; then ok "$3"; else bad "$3 (missing: $2)"; fi; }
hasnt(){ if grep -q -- "$2" <<<"$1"; then bad "$3 (found: $2)"; else ok "$3"; fi; }
sql()  { sudo -n -u postgres psql -d "$RI_DEV_DB" -Atc "$1"; }
as()   { sql "select set_config('app.member_id', '$1', false); select $2" | tail -1; }
cleanup() { local rc=$?; for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done; rm -rf "$TMP"; exit $rc; }
trap cleanup EXIT INT TERM
for port in $PORT $P_KERNEL $P_FAKE; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done

bash "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "scratch database failed"; exit 1; }
set -a; . "$RI_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$PORT" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$TMP/kernel.json" FAKE_MALUDB_LOG="$TMP/maludb.log" FAKE_MALUMAIL_LOG="$TMP/malumail.log"
cd "$ROOT"
APP="http://127.0.0.1:$PORT"

echo "== the schema (db/proof/phase0_proof.sql)"
if [ -n "${RI_MIGRATIONS_UPTO:-}${RI_DEV_UPTO:-}" ]; then
  echo "skip db/proof/phase0_proof.sql — the migrations stop at ${RI_MIGRATIONS_UPTO:-$RI_DEV_UPTO} (the kit alone)"
elif [ -f "$ROOT/db/proof/phase0_proof.sql" ]; then
  out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$RI_DEV_DB" -f "$ROOT/db/proof/phase0_proof.sql" 2>&1)
  echo "$out" | grep -E '^ ?FAIL' || true
  summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
  failed=$(echo "$summary" | sed -E 's/.* ([0-9]+) failed.*/\1/')
  check "$failed" "0" "schema proof: $summary"
else
  echo "skip db/proof/phase0_proof.sql is not written yet (the schema builder's) — the kit's checks below run on whatever db/*.sql applies"
fi

echo "== the contract tables (db/001–006)"
check "$(sql "select string_agg(role_key, ',' order by sort_order) from mcp_app_roles")" "viewer,user,chef,manager,admin" "the five roles, in order (os.app-roles/1; design §3, D2)"
check "$(sql "select string_agg(role_key, ',') from ri_roles where is_admin")" "admin" "exactly one admin role"
check "$(sql "select string_agg(role_key || '=' || capability, ',' order by sort_order) from ri_roles")" "viewer=read,user=write,chef=write,manager=write,admin=admin" "each role's capability (the base role keyed user: Kitchen)"
check "$(sql "select string_agg(role_key || ':' || cardinality(rights), ',' order by sort_order) from mcp_app_roles")" "viewer:2,user:8,chef:14,manager:21,admin:27" "Viewer 2 rights, Kitchen 8, Chef 14, Manager 21, admin all 27 — each the lower roles' plus its own"
check "$(sql "select string_agg(right_key, ',' order by right_key) from ri_role_rights where role_key = 'user'")" "notes.write,ri.read,stock.count,stock.produce,stock.receive,stock.transfer,stock.waste,tokens.own" "the Kitchen counts, wastes, receives, transfers, produces, notes"
check "$(sql "select string_agg(right_key, ',' order by right_key) from ri_role_rights where role_key = 'manager' and right_key not in (select right_key from ri_role_rights where role_key = 'chef')")" "exports.all,forecast.adjust,invoices.write,periods.close,prices.write,purchasing.send,suppliers.write" "the Manager's own seven: the money, the close, the forecast, the exports"
check "$(sql "select count(*) from ri_rights where right_key not in (select right_key from ri_role_rights where role_key = 'admin')")" "0" "the admin holds every right"
check "$(sudo -n -u postgres psql -d "$RI_DEV_DB" -v ON_ERROR_STOP=1 -qAt -c "begin; insert into activity_log (source, action, after) values ('system', 'worker.pass', '{\"count\":3}'); select source from activity_log; rollback;" 2>&1 | head -1)" "system" "activity_log admits source system (the worker's own passes)"
check "$(sudo -n -u postgres psql -d "$RI_DEV_DB" -v ON_ERROR_STOP=1 -qAt -c "insert into activity_log (source, action) values ('portal2', 'x.y')" 2>&1 | grep -c 'violates check constraint')" "1" "…and refuses a source outside the list"
check "$(sql "select string_agg(column_name || ':' || data_type, ',' order by ordinal_position) from information_schema.columns where table_name = 'activity_log' and column_name in ('token_id','scope_id','ingredient_id','recipe_id','purchase_order_id','count_id')")" "token_id:bigint,scope_id:bigint,ingredient_id:bigint,recipe_id:bigint,purchase_order_id:bigint,count_id:bigint" "the audit keys appended to activity_log (token_id; scope_id; ingredient, recipe, purchase order, count — design §6.9)"
check "$(sql "select cost_basis || ':' || calendar_kind || ':' || blind_counts || ':' || price_alert_pct || ':' || covers_factor_threshold_pct || ':' || forecast_history_weeks_min || '/' || dynamic_par_after_weeks || ':' || late_receipt_flag || ':' || key_rate_per_minute || '/' || key_rate_per_day from ri_settings")" "avg:week:true:10.00:15.00:4/4:true:60/5000" "the settings seeded as D6, D8–D11, D13 say (weighted average, weekly periods, blind counts, 10 % alerts, 15 % covers factor, four weeks, late receipts flagged; the key rates)"
check "$(sudo -n -u postgres psql -d "$RI_DEV_DB" -qAt -c "update ri_settings set cost_basis = 'fifo'" 2>&1 | grep -c 'violates check constraint')" "1" "the cost basis is avg or last (D6)"
check "$(sudo -n -u postgres psql -d "$RI_DEV_DB" -qAt -c "update ri_settings set calendar_kind = 'fortnight'" 2>&1 | grep -c 'violates check constraint')" "1" "the calendar is week, month, a 4-4-5 variant or 13x4 (D13)"
check "$(sql "select string_agg(kind || '=' || prefix, ',' order by kind) from document_sequences")" "adjustment=ADJ-,count=CNT-,goods_receipt=GR-,invoice=INV-,production=PRD-,purchase_order=PO-,transfer=TR-,waste=WST-" "eight numbered document kinds (db/006)"
check "$(sql "select next_document_number('count') || ',' || next_document_number('count')")" "CNT-00001,CNT-00002" "next_document_number counts up"
check "$(sql "select count(*) from information_schema.role_table_grants where grantee in ('restaurant_inventory_records_ro','restaurant_inventory_activity_ro') and table_name in ('activity_log','mcp_access_tokens','members','key_usage','ri_settings','sites','member_site_roles','site_settings')")" "0" "the read roles hold no grant on the contract tables (the mcp_* views are their window)"

echo "== the mirror (the fixture through directory_sync --from-file: two sites)"
php bin/directory_sync.php --from-file bin/dev_directory.json >/dev/null 2>&1; check "$?" "0" "the directory fixture applies"
check "$(sql "select count(*) || ' of ' || count(*) filter (where capability is not null) from members")" "12 of 11" "twelve members mirrored, eleven admitted (the Watcher holds no grant)"
check "$(sql "select string_agg(scope_id || ':' || name || ':' || timezone, ',' order by scope_id) from sites where removed_at is null")" "101:SMOKE Downtown:America/New_York,102:SMOKE Airport:America/Chicago" "two sites materialised from scopes[] with their time zones"
check "$(sql "select count(*) from site_settings") $(sql "select count(*) from day_parts")" "2 4" "…each with its settings row and two day parts (Lunch, Dinner — Reservations' service names)"
check "$(sql "select string_agg(member_id || '@' || scope_id || '=' || coalesce(role_key, '-'), ',' order by member_id, scope_id) from member_site_roles")" "1@101=admin,1@102=admin,26@102=user,27@101=manager,27@102=user,28@101=-,29@101=viewer,30@101=chef,31@102=-,40@101=chef,40@102=chef,42@101=manager,43@101=admin" "every holding per site from access[].scopes[]; Nico (32) holds nothing (a grant naming no site)"
check "$(sql "select roles::text from members where id = 27")" "{manager,user}" "the union of roles lands in members.roles: Marco is Manager and Kitchen"
check "$(sql "select is_agent::int || ',' || roles::text || ',' || display_name from members where id = 42")" "1,{manager},SMOKE Buyer" "the Purchasing agent is an agent (is_agent generated) holding Manager at Downtown"
check "$(sql "select ri_member_roles_at(28, 101)::text || ' ' || ri_member_roles_at(31, 102)::text || ' ' || ri_member_roles_at(1, 102)::text || ' ' || ri_member_roles_at(27, 102)::text || ' ' || ri_member_roles_at(27, 103)::text || ' ' || ri_member_roles_at(41, 101)::text")" "{user} {viewer} {admin} {user} {} {}" "ri_member_roles_at: write with no roles yet is Kitchen, read is Viewer, the super-admin admin, Marco Kitchen at Airport, nothing at a site not held, an unadmitted agent nothing"

echo "== roles, rights and the cost wall (ri_has_right, ri_scopes_with_right, ri_is_admin, ri_sees_cost, ri_right_refusal)"
check "$(as 1 "ri_is_admin(101) and ri_is_admin(102) and ri_is_admin() and ri_has_right('settings.manage', 101) and ri_has_right('purchasing.send', 102) and ri_sees_cost(101) and ri_right_refusal('settings.manage', 101) is null")" "t" "the super-admin runs the application at every site"
check "$(as 27 "ri_has_right('purchasing.send', 101) and ri_has_right('periods.close', 101) and ri_has_right('counts.approve', 101) and not ri_has_right('settings.manage', 101) and not ri_is_admin(101) and ri_sees_cost(101)")" "t" "Marco, Manager at Downtown: the money, the close, counts — not the settings; sees cost"
check "$(as 27 "not ri_has_right('purchasing.send', 102) and not ri_has_right('counts.approve', 102) and ri_has_right('stock.count', 102) and ri_has_right('stock.waste', 102) and not ri_sees_cost(102)")" "t" "…and Kitchen at Airport: counts and waste there, no money, no cost (rights are per site)"
check "$(as 27 "ri_right_refusal('settings.manage', 101)")" "Your role at SMOKE Downtown (Manager) does not let you do that." "…told why in a sentence, never a bare 403"
check "$(as 27 "ri_right_refusal('stock.count', 103)")" "You do not hold that restaurant." "…a site not held, in a sentence"
check "$(as 27 "array_to_string(array(select ri_scopes_with_right('purchasing.send') order by 1), ',') || '|' || array_to_string(array(select ri_scopes_with_right('stock.count') order by 1), ',')")" "101|101,102" "ri_scopes_with_right: the money at Downtown only, counting at both"
check "$(as 27 "ri_has_right_anywhere('recipes.write') and not ri_has_right_anywhere('settings.manage')")" "t" "a right at any held site opens a record shared across sites (recipes); the settings nowhere"
check "$(as 30 "ri_has_right('recipes.write', 101) and ri_has_right('counts.approve', 101) and ri_has_right('purchasing.draft', 101) and not ri_has_right('purchasing.send', 101) and not ri_has_right('periods.close', 101) and ri_sees_cost(101)")" "t" "Dana, Chef at Downtown: recipes, counts' approval, drafts — never sends; sees cost"
check "$(as 26 "ri_has_right('stock.count', 102) and ri_has_right('stock.receive', 102) and not ri_has_right('recipes.write', 102) and not ri_sees_cost(102) and not ri_holds_scope(101)")" "t" "Priya, Kitchen at Airport: counts and receives, no recipes, NO COST, and Downtown does not exist to her"
sql "update site_settings set kitchen_sees_cost = true where scope_id = 102" >/dev/null
check "$(as 26 "ri_sees_cost(102)")" "t" "…until the site's kitchen_sees_cost is on (design §3, D2)"
sql "update site_settings set kitchen_sees_cost = false where scope_id = 102" >/dev/null
check "$(as 29 "ri_has_right('ri.read', 101) and not ri_has_right('stock.count', 101) and not ri_sees_cost(101) and ri_is_member_here()")" "t" "Ann, an external Viewer: reads, never counts, never a cost"
check "$(as 28 "ri_has_right('stock.count', 101) and not ri_has_right('recipes.write', 101)")" "t" "a member whose roles have not arrived is read from the capability (write = Kitchen)"
check "$(as 31 "ri_has_right('ri.read', 102) and not ri_has_right('stock.count', 102)")" "t" "…and read = Viewer"
check "$(as 32 "app_is_active_member() and not ri_is_member_here() and not ri_has_right('ri.read', 101)")" "t" "Nico, admitted with a grant naming no site, holds nothing here (fail closed)"
check "$(as 32 "ri_right_refusal('ri.read', 101)")" "Your access to Restaurant Inventory names no restaurant yet. A super-admin grants a role at a site." "…told why"
check "$(as 40 "ri_has_right('recipes.write', 101) and ri_has_right('recipes.write', 102) and app_member_kind() = 'agent' and not ri_has_right('purchasing.send', 101)")" "t" "the expert (an agent Chef at both sites) drafts and writes recipes, never sends an order"
check "$(as 42 "ri_has_right('purchasing.send', 101) and ri_has_right('purchasing.draft', 101) and not ri_holds_scope(102)")" "t" "the Purchasing agent holds Manager at Downtown only (sending pauses in the kernel — design §5)"
check "$(as 40 "ri_right_refusal('purchasing.send', 101)")" "The agent's role at SMOKE Downtown (Chef) does not allow that." "an agent's refusal names the agent's role"
check "$(as 41 "not app_is_active_member() and not ri_has_right('ri.read', 101) and not ri_is_member_here()")" "t" "an agent not yet admitted holds nothing (fail closed)"
check "$(as '' "ri_has_right('ri.read', 101) or ri_is_member_here() or ri_is_admin()")" "f" "nobody (no app.member_id) holds nothing"
check "$(sql "select ri_member_has_right(30, 'recipes.write', 101)::text || ',' || ri_member_has_right(30, 'recipes.write', 102)::text || ',' || ri_member_has_right(1, 'settings.manage', 102)::text")" "true,false,true" "ri_member_has_right answers for a given member at a site"
check "$(sql "select ri_site_timezone(102) || ',' || ri_site_timezone(999)")" "America/Chicago,UTC" "ri_site_timezone: the site's zone, UTC for none"

echo "== sign-on"
php -r '$f = json_decode(file_get_contents("bin/dev_directory.json"), true); file_put_contents(getenv("FAKE_KERNEL_STATE"), json_encode(["feed" => $f["feed"], "scopes" => $f["feed"]["scopes"]]));'
php -S 127.0.0.1:$P_KERNEL tests/fake_kernel.php >"$TMP/kernel.log" 2>&1 & PIDS+=($!)
php -S 127.0.0.1:$P_FAKE tests/fake_maludb.php >"$TMP/fake.log" 2>&1 & PIDS+=($!)
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$PORT -t html tests/dev_router.php >"$TMP/app.log" 2>&1 & PIDS+=($!)
sleep 1.2
check "$(curl -s -o /dev/null -w '%{http_code}' $APP/api/v1/health)" "200" "health answers 200"
check "$(curl -s $APP/api/v1/health | php -r '$h = json_decode(stream_get_contents(STDIN), true); echo ($h["application"] ?? "?") . ":" . ($h["storage"] ?? "?") . ":" . ($h["database"] ?? "?");')" "restaurant_inventory:ok:ok" "…naming the application, its attachment store writable, the database up"
url27=$(php bin/dev_handoff.php 27 101)
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$TMP/jar27" "$url27")" "302 $APP/" "a signed hand-off token with the chosen scope opens a session (302 to /)"
home27=$(curl -s -b "$TMP/jar27" "$APP/")
has "$home27" 'id="header-user-name">SMOKE Marco<' "the shell names the signed-in member (the user menu)"
has "$home27" 'id="header-site-name" title="Restaurant Inventory">SMOKE Downtown<' "…at the site the launcher chose (the header's site name)"
has "$home27" 'id="header-role-badge">Manager<' "…with the roles the kernel sent THERE, by their names (the badge)"
has "$home27" 'data-sees-cost="1"' "…and that a Manager sees cost (body data-sees-cost)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$url27")" "403" "the same token a second time is refused (replay)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_TTL=-120 php bin/dev_handoff.php 26)")" "403" "an expired token is refused"
bad_url=$(APP_KEY=other php bin/dev_handoff.php 26 | sed "s#$APP##")
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP$bad_url")" "403" "a token minted for another application is refused (audience)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(php bin/dev_handoff.php 999)")" "403" "an unknown member is refused"
check "$(sql "select count(*) from members where id = 999")" "0" "…and is never created in the mirror"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_NO_GRANT=1 php bin/dev_handoff.php 30)")" "403" "a hand-off whose claims carry no grant is refused (capability)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(php bin/dev_handoff.php 32)")" "403" "a hand-off whose claims name no site is refused (no_site) — a grant must name a restaurant"
pyld=$(echo "$url27" | sed -E 's/.*token=([^.]+\.[^.]+\.[^.]+\.[^.]+)\.[a-f0-9]+.*/\1/')
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP/sso?token=$pyld.$(printf '0%.0s' $(seq 1 64))&claims=x.y")" "403" "a tampered signature is refused"
check "$(sql "select string_agg(after->>'reason', ',' order by id) from activity_log where action = 'member.sign_on.refused'")" "replay,token,token,capability,capability,no_site,token" "every refusal is logged with its reason; none is told to the visitor"
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP/")" "302" "an anonymous visit to / is sent to the launcher (never a login form)"
check "$(sql "select scope_id from member_sessions where member_id = 27 and ended_at is null")" "101" "the session is listed for the kernel's sign-out notice, with its site"
check "$(sql "select scope_id || ':' || (after->>'scope_id') || ':' || (after->'roles')::text from activity_log where action = 'member.sign_on' and entity_id = 27")" '101:101:["manager", "user"]' "member.sign_on logged at the site with the roles"
code26=$(curl -s -o /dev/null -w '%{http_code}' -c "$TMP/jar26" "$(php bin/dev_handoff.php 26 101)"); check "$code26" "302" "Priya signs on asking for Downtown, which she does not hold —"
has "$(curl -s -b "$TMP/jar26" "$APP/")" 'id="header-site-name" title="Restaurant Inventory">SMOKE Airport<' "…and lands at the site she holds (the claims' scope is honoured only when held)"
check "$(curl -s -H 'Accept: application/json' -b "$TMP/jar26" "$APP/" | php -r '$d = json_decode(stream_get_contents(STDIN), true)["data"]; echo $d["site_id"] . ":" . implode(",", $d["roles"]) . ":" . var_export($d["sees_cost"], true);')" "102:user:false" "the home's JSON answers the site, the roles there and the cost wall"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$TMP/jarn" "$(php bin/dev_handoff.php 30)&next=/ingredients")" "302 $APP/ingredients" "the hand-off lands on the launcher's next path when it is local"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$TMP/jarx" "$(php bin/dev_handoff.php 30)&next=https://evil.example/")" "302 $APP/" "…and on / when it is not"
has "$(curl -s -b "$TMP/jarn" "$APP/")" 'id="header-role-badge">Chef<' "Dana signs on with no scope chosen and lands at her one site as Chef"

echo "== the site switcher"
tok27=$(echo "$home27" | sed -n 's/.*name="csrf_token" value="\([a-f0-9]*\)".*/\1/p' | head -1)
has "$home27" 'id="site-switch-102"' "a person holding two sites is offered the switcher"
hasnt "$(curl -s -b "$TMP/jar26" "$APP/")" 'id="site-switcher"' "…a person holding one is not"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$TMP/jar27" -X POST -d "csrf_token=$tok27&site=102" "$APP/switch-site.php")" "302 $APP/" "switching to a held site answers 302"
home27b=$(curl -s -b "$TMP/jar27" "$APP/")
has "$home27b" 'id="header-site-name" title="Restaurant Inventory">SMOKE Airport<' "…and the session's site changed"
has "$home27b" 'id="header-role-badge">Kitchen<' "…with the roles held THERE (Marco is Kitchen at Airport)"
has "$home27b" 'data-sees-cost="0"' "…and no cost for a Kitchen"
check "$(sql "select scope_id from member_sessions where member_id = 27 and ended_at is null")" "102" "…recorded on the session"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" -X POST -d "csrf_token=$tok27&site=999" "$APP/switch-site.php")" "403" "a site not held is refused"
check "$(sql "select count(*) from activity_log where action = 'member.site_switch.refused'")" "1" "…and logged (member.site_switch.refused — the manifest's name since Phase 2)"
check "$(php tests/phase0/probe.php site 26 101 2>&1 | tr '\n' ' ')" "not-held no-count:no-cost " "require_site / has_right / sees_cost as Priya for Downtown: not held, nothing there"
check "$(php tests/phase0/probe.php site 26 102 2>&1 | tr '\n' ' ')" "held count:no-cost " "…and for Airport: held, counts, no cost"

echo "== CSRF"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" -X POST "$APP/logout.php")" "403" "a POST without a CSRF token is refused"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" -X POST -d 'csrf_token=deadbeef' "$APP/logout.php")" "403" "a POST with a bad CSRF token is refused"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST -d 'csrf_token=x' "$APP/")" "302 https://app.example.invalid/launcher?app=restaurant_inventory" "an anonymous POST to a screen goes to the launcher (there is no login form here)"
check "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -X POST "$APP/")" "401" "…and answers a JSON caller 401, never a bare 403"

echo "== the activity funnel"
out=$(php tests/phase0/probe.php log 2>/dev/null)
check "$(sql "select scope_id || ':' || ingredient_id || ':' || recipe_id || ':' || entity_type || ':' || source from activity_log where action = 'probe.event'")" "101:5:77:recipe:cron" "log_activity writes the site and the audit keys beside the entity, the recipe's id from the entity (source cron under the CLI)"
check "$(sql "select scope_id || ':' || ingredient_id from activity_log where action = 'probe.ingredient'")" "102:9" "…and takes the ingredient's id from the entity when the entity IS an ingredient"
check "$(sql "select coalesce(scope_id::text, 'none') || ':' || purchase_order_id from activity_log where action = 'probe.noscope'")" "none:4" "…no session, no site; a purchase order's id from the entity"
check "$(sql "select count(*) || ':' || count(scope_id) || ':' || count(ingredient_id) || ':' || count(entity_id) from activity_log where action = 'probe.bad_keys'")" "1:0:0:0" "…and drops a key that is not a positive number without losing the row"
check "$(echo "$out" | tr '\n' ' ')" "logged survived " "log_activity never throws on a bad actor, an unencodable payload or a bad key"

echo "== keys: a person's own, rate-limited"
RAW="mcp_$(openssl rand -hex 24)"; HASH=$(printf '%s' "$RAW" | openssl dgst -sha256 | sed 's/.* //')
sql "insert into mcp_access_tokens (member_id, label, token_hash, scope) values (30, 'SMOKE key', '$HASH', 'api')" >/dev/null
TOK=$(sql "select id from mcp_access_tokens where token_hash = '$HASH'")
check "$(sql "select rate_per_minute || '/' || rate_per_day from mcp_access_tokens where id = $TOK")" "60/5000" "a key's limits default from the settings (the trigger: 60/min, 5,000/day)"
check "$(sql "select member_id || ':' || label || ':' || rate_per_minute from ri_resolve_key('$HASH')")" "30:SMOKE key:60" "a key resolves to its person, label and limits (ri_resolve_key)"
check "$(sudo -n -u postgres psql -d "$RI_DEV_DB" -qAt -c "insert into mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute) values (30, 'SMOKE bad', 'x$(openssl rand -hex 8)', 'mcp', 5)" 2>&1 | grep -c 'violates check constraint')" "1" "a person's mcp token can never carry a key's limits"
r1=$(php tests/phase0/probe.php apikey "$RAW" 2>&1 | tr '\n' ' ')
has "$r1" "authenticated:30:key=yes:label=SMOKE key" "the token API authenticates a key as its person"
check "$(sql "select token_id || ':' || source || ':' || scope_id || ':' || (after->>'label') from activity_log where action = 'probe.api'")" "$TOK:api:101:SMOKE key" "…every row under a key carries token_id, source api, the site and the key's label, never the key"
MCP="mcp_$(openssl rand -hex 24)"; MH=$(printf '%s' "$MCP" | openssl dgst -sha256 | sed 's/.* //')
sql "insert into mcp_access_tokens (member_id, label, token_hash, scope) values (30, 'SMOKE mcp', '$MH', 'mcp')" >/dev/null
has "$(php tests/phase0/probe.php apikey "$MCP" 2>&1 | tr '\n' ' ')" '"code":"unauthorized"' "a person's mcp token is not a door to the token API"
rl=$(sql "select string_agg(ok::text, ',' order by n) from (select n, (ri_rate_ok($TOK)).ok from generate_series(1, 61) n) s" | tr ',' '\n' | sort | uniq -c | tr -s ' ' | tr '\n' ';')
case "$rl" in " 2 false; 59 true;"|" 1 false; 60 true;") ok "the rate rule counts 60 calls a minute and refuses the 61st (ri_rate_ok) — $rl" ;; *) bad "the rate rule (got: $rl, wanted 60 a minute with the earlier call counted or not)" ;; esac
NEW=$(sql "select ri_key_rotate($TOK, '$(openssl rand -hex 32)')"); check "$(sql "select rotated_from = $TOK and rate_per_minute = 60 from mcp_access_tokens where id = $NEW") $(sql "select expires_at <= now() + interval '25 hours' from mcp_access_tokens where id = $TOK")" "t t" "rotation mints a key of the same person and limits and gives the old one 24 hours"

echo "== the kernel's word shuts the doors"
notice="27.$(date +%s).restaurant_inventory"; sig=$(printf 'sso-logout:%s' "$notice" | openssl dgst -sha256 -hmac "$ACTION_TOKEN_KEY" | sed 's/.* //')
check "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=$notice.$sig" "$APP/sso/logout")" "204" "the kernel's sign-out notice answers 204"
check "$(sql "select ended_by from member_sessions where member_id = 27 order by created_at desc limit 1")" "kernel" "…and ends the member's session"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" "$APP/")" "302" "…so the next request goes to the launcher"
check "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=27.1.restaurant_inventory.deadbeef" "$APP/sso/logout")" "204" "a bad notice also answers 204 and changes nothing"
curl -s -o /dev/null -c "$TMP/jar29" "$(php bin/dev_handoff.php 29)"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar29" "$APP/")" "200" "Ann's session stands while her grant does"
php -r '$s = json_decode(file_get_contents(getenv("FAKE_KERNEL_STATE")), true); $s["incremental"] = ["schema" => "os.directory-changes/1", "since" => "2026-01-01T00:00:00.000000Z", "next" => "2026-01-02T00:00:00.000000Z", "full" => false, "members" => [], "departments" => [], "memberships" => [], "deleted_departments" => [], "scopes" => [["scope_id" => 102, "kind" => "location", "location_id" => 11, "department_id" => null, "name" => "SMOKE Airport", "address" => "Terminal B", "timezone" => "America/Chicago", "removed_at" => "2026-01-02T00:00:00Z", "updated_at" => "2026-01-02T00:00:00Z"], ["scope_id" => 103, "kind" => "location", "location_id" => 12, "department_id" => null, "name" => "SMOKE Harbor", "address" => "Pier 9", "timezone" => "America/Los_Angeles", "removed_at" => null, "updated_at" => "2026-01-02T00:00:00Z"]], "access" => [["member_id" => 29, "capability" => null, "roles" => [], "scopes" => []], ["member_id" => 30, "role" => "chef", "roles" => ["chef"], "capability" => "write", "scopes" => [["scope_id" => 101, "role" => "chef", "roles" => ["chef"], "capability" => "write"], ["scope_id" => 103, "role" => "manager", "roles" => ["manager"], "capability" => "write"]]]]]; file_put_contents(getenv("FAKE_KERNEL_STATE"), json_encode($s));'
php bin/directory_sync.php >/dev/null 2>&1; check "$?" "0" "the change feed applies through the kernel's internal API (the fake kernel; the first run read scopes.php)"
check "$(sql "select scopes_at is not null from directory_sync_state")" "t" "…the first run read scopes.php (scopes_at set)"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar29" "$APP/")" "302" "a grant the kernel revoked shuts the door within one sync"
check "$(sql "select ended_by from member_sessions where member_id = 29 order by created_at desc limit 1") $(sql "select capability is null from members where id = 29") $(sql "select count(*) from member_site_roles where member_id = 29")" "directory t 0" "…the session ended by the directory, the mirror holds no grant and no site"
check "$(sql "select removed_at is not null from sites where scope_id = 102") $(sql "select count(*) from (select set_config('app.member_id', '26', false)) c, lateral ri_held_scope_ids() h")" "t 0" "a site the kernel removed is closed: nobody reaches it (ri_held_scope_ids), its row and its holdings stay (db/019)"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar26" "$APP/")" "302" "…so Priya, who held only Airport, is out on her next request"
check "$(sql "select name || ':' || timezone from sites where scope_id = 103") $(sql "select count(*) from site_settings where scope_id = 103") $(sql "select count(*) from day_parts where scope_id = 103")" "SMOKE Harbor:America/Los_Angeles 1 2" "a site the kernel added is materialised with its settings and day parts"
check "$(sql "select string_agg(scope_id || '=' || role_key, ',' order by scope_id) from member_site_roles where member_id = 30")" "101=chef,103=manager" "…and Dana's holding now names it (Manager at Harbor, Chef at Downtown)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_NO_GRANT=1 php bin/dev_handoff.php 29)")" "403" "a hand-off whose claims now carry no grant is refused (the kernel's claims are the authority)"
tok30=$(curl -s -c "$TMP/jar30" -b "$TMP/jar30" -o /dev/null "$(php bin/dev_handoff.php 30)"; curl -s -b "$TMP/jar30" "$APP/" | sed -n 's/.*name="csrf_token" value="\([a-f0-9]*\)".*/\1/p' | head -1)
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$TMP/jar30" -X POST -d "csrf_token=$tok30" "$APP/logout.php")" "302 https://app.example.invalid/launcher" "the application's own sign-out (POST + CSRF) goes back to the launcher"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar30" "$APP/")" "302" "…and the session is over"

echo "== the worker, the ingest bridge, mail"
php bin/worker.php >"$TMP/worker.out" 2>&1; check "$?:$(sql "select count(*) from activity_log where action = 'worker.pass' and source = 'cron'")" "0:1" "the worker runs a pass of stubs, logs worker.pass (source cron) and exits 0"
check "$(php -r '$r = json_decode(file_get_contents($argv[1]), true); $ran = $r["ran"] ?? []; $bad = []; foreach ($ran as $k => $v) { if (isset($v["stub"]) && !preg_match("/^slice [0-9]+$/", (string) $v["stub"])) { $bad[] = $k; } } echo count($ran) . ":" . (isset($ran["key_usage_prune"]["pruned"]) ? "pruned" : "?") . ":" . (isset($ran["key_usage_prune"]["stub"]) ? "stub" : "real") . ":" . ($bad === [] ? "ok" : implode(",", $bad));' "$TMP/worker.out")" "12:pruned:real:ok" "…twelve passes: the key-usage prune real; every other pass real or a stub naming its slice (followed as slices land)"
if [ -x "$PY" ] && "$PY" -c 'import asyncpg, httpx' 2>/dev/null; then
  "$PY" mcp/activity_ingest.py >"$TMP/ingest.out" 2>&1; check "$?" "0" "the ingest bridge ships the log to MaluDB (fake) and advances its checkpoint"
  check "$(php -r '$first = json_decode(file(getenv("FAKE_MALUDB_LOG"))[0], true); echo array_key_first($first["payload"]) . "=" . $first["payload"]["application"] . ":" . $first["kind"];')" "application=restaurant_inventory:activity" "the payload names the application first (memory.md)"
  check "$(php -r 'foreach (file(getenv("FAKE_MALUDB_LOG")) as $l) { $b = json_decode($l, true); if (($b["payload"]["action"] ?? "") === "probe.event") echo $b["payload"]["scope_id"] . ":" . $b["payload"]["ingredient_id"] . ":" . $b["payload"]["recipe_id"]; }')" "101:5:77" "…and carries the site and the audit keys"
  check "$(sql "select last_id = (select max(id) from activity_log) from activity_ingest_state")" "t" "…the checkpoint stands at the last row"
else
  bad "the ingest bridge needs a python with asyncpg and httpx (RI_PY)"; bad "the payload names the application first"; bad "payload carries the audit keys"; bad "the checkpoint"
fi
check "$(php tests/phase0/probe.php mail 2>&1)" "sent:200" "notification mail goes through MaluMail's (fake) API when a key is set"
check "$(MALUMAIL_API_KEY= php tests/phase0/probe.php mail 2>&1 | cut -d: -f1)" "mail-threw" "with no key the send raises a catchable error (mail is optional)"

echo "== $pass ok, $fail failed"
[ "$fail" -eq 0 ]
