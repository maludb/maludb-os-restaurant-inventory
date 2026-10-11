#!/usr/bin/env bash
# Restaurant Inventory — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0
# (2026-10-11); later phases ADD steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database
# (tests/setup_dev.sh), php -S and fixtures, and never touch an installed application, Apache or systemd.
#   bash deploy/ROOT_STEPS.sh                          prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply [manager@email]   runs the steps with no decision in them (0, 0b, 0c); the Manager's email seeds RI_MANAGER_EMAIL (D14)
set -euo pipefail
APP=/srv/apps/restaurant_inventory
KERNEL=/var/www
MODE=${1:-plan}
MANAGER=${2:-}
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS before the installer's first apply (design §13.16 / §14; Help Desk's lesson of 2026-10-02). A config/.env
#    holding ONLY the three ports, the secrets key and the Manager's email: the installer reads them, fills every other required
#    key itself, and sets fresh role passwords because DB_PASSWORD is absent. RI_SECRETS_KEY (32 random bytes, hex) seals every
#    POS source credential — generated here if absent, NEVER replaced afterwards. RI_MANAGER_EMAIL is the Manager the morning
#    note goes to (D14) — the site settings screen changes it later. Never put anything else in this file by hand.
#    Ports: 8200 (the application), 8856 / 8857 (records and activity MCP) — the block after MaluMail's 8199/8852–8855.
echo "== 0. Pin the ports and the secrets key (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  KEY=$(openssl rand -hex 32)
  if [ -n "$MANAGER" ]; then MANAGER_LINE="RI_MANAGER_EMAIL=$MANAGER"; else MANAGER_LINE="# RI_MANAGER_EMAIL=manager@your-domain   # the Manager the morning note goes to (D14); set it here or on the site settings screen"; fi
  run bash -c "mkdir -p '$APP/config' && printf 'APP_INTERNAL_PORT=8200\nMCP_RECORDS_PORT=8856\nMCP_ACTIVITY_PORT=8857\nRI_SECRETS_KEY=%s\n%s\n' '$KEY' '$MANAGER_LINE' > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
else
  echo "   (config/.env exists — leaving it alone)"
  if ! grep -q '^RI_SECRETS_KEY=.\+' "$APP/config/.env"; then
    run bash -c "printf 'RI_SECRETS_KEY=%s\n' \"\$(openssl rand -hex 32)\" >> '$APP/config/.env'"
  fi
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost)'   -> ports done, no {{…}} unfilled

# ------------------------------------------------------------------------------------------------------------------
# 0b. mod_headers (ROOT) — the vhost's Header lines are guarded by <IfModule mod_headers.c>, so Apache starts either way;
#     enabling the module makes them take effect (Sales CRM's lesson, 2026-10-09). Idempotent.
echo "== 0b. mod_headers (ROOT)"
run bash -c "a2enmod -q headers >/dev/null 2>&1 || true"

# ------------------------------------------------------------------------------------------------------------------
# 0c. STORAGE (ROOT) — storage/ and its directories www-data-owned, mode 0770, before apply: attachments (invoice images,
#     waste photos, recipe photos), exports, the POS intake's spool (files received by email or SFTP before they are read).
#     Idempotent: an existing directory is left in place, its owner and mode set.
echo "== 0c. storage/ (ROOT) — www-data-owned, 0770"
run bash -c "mkdir -p '$APP/storage/attachments' '$APP/storage/exports' '$APP/storage/pos-intake' && chown -R www-data:www-data '$APP/storage' && chmod 0770 '$APP/storage' '$APP/storage/attachments' '$APP/storage/exports' '$APP/storage/pos-intake'"

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT) — PHASE 2 onward, not before the shell exists: database and roles, config/.env, the vhost,
#    the units, the registration (catalog kind ours — the row K47 seeded, kernel db/182 —, the application with /sso and
#    /sso/logout, scope_kind location), the application token into config/.env, skills imported, approval policies, the
#    installing super-admin's admin grant, the MaluMail key from ~/.malumail (the `mail` step). NO --grant-standing-departments
#    (design §13.3: granted person by person, per site). --hire-agents hires the expert and the Purchasing agent (both
#    hired_on_install; the Purchasing agent's duty from agents[].duty). Run it twice: the roles are read from app_roles only
#    once the records MCP answers (Phase 4).
echo "== 2. apply (ROOT) — from Phase 2"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 3. The owner's keys and decisions (any time after 2):
echo "== 3. The owner's"
echo "   - the SITES: on the application's Scopes tab in the kernel, add every restaurant (a kernel location of kind site); the sync creates each within a minute"
echo "   - MaluMail: MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME in $APP/config/.env (the installer's mail step writes them from ~/.malumail; the purchase order's email and the morning note need them); RI_INTAKE_MAILBOX for the POS's emailed exports, if used"
echo "   - the K6 text sender, if texts to members are wanted: php $KERNEL/bin/notify_endpoint_set.php (already set when another application uses it)"
echo "   - DNS and TLS for kitchen.subello.com (until then /etc/hosts maps it to this host — and a loopback line so the kernel's app_roles read does not meet the proxy's https redirect, 2026-10-11); RI_PUBLIC_BASE_URL for the supplier door's absolute links"
echo "   - the grants, person by person and per site: Chef (chef), the Manager (manager), the kitchen (user), a viewer (viewer) — super-admins hold admin"
echo "   - the connection a super-admin approves (bin/app_connection.php) for this application's read of reservations.covers_by_service, and for the ledger's reads of our shares"
echo "   - the first catalog: a CSV of the distributor's order guide (Phase 5), the first recipes, the first POS file on the Sources screen (sealed under RI_SECRETS_KEY when a key is involved)"
echo "   - RI_MANAGER_EMAIL per site on the site settings screen if it changed"
