#!/usr/bin/env bash
# tests/phase3/slice6/servers.sh start|stop (slice 6, counts: app :9329, fake kernel :9330, fake MaluDB + MaluMail :9331, the Apache internal vhost :9332) — the proofs' servers on loopback, all from the scratch environment ($RI_DEV_ENV):
#   the application on :${P_APP}, a fake kernel on :${P_KERNEL}, a fake MaluDB + MaluMail on :${P_FAKE} (slice 6's block 9329–9332, design §14).
#   The application is `php -S` with tests/dev_router.php by default; with RI_APP=apache it is a REAL Apache (as your user, mod_php) serving
#   deploy/apache-restaurant_inventory.conf rendered the way the kernel's installer renders it (the internal vhost on :9332) — the proofs
#   then exercise the vhost's own rewrites. State: $RI_DEV_STATE (default /tmp/ri-dev-s6-state) holds the pid files, the fake kernel's state
#   file and the MaluDB log.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
P_APP=${P_APP:-9329}; P_KERNEL=${P_KERNEL:-9330}; P_FAKE=${P_FAKE:-9331}; P_INTERNAL=${P_INTERNAL:-9332}
ENVF=${RI_DEV_ENV:-/tmp/ri-dev-s6.env}
STATE=${RI_DEV_STATE:-/tmp/ri-dev-s6-state}
mkdir -p "$STATE"
stop() {
  for f in "$STATE"/*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  pkill -f "php -S 127.0.0.1:${P_APP}" 2>/dev/null || true; pkill -f "php -S 127.0.0.1:${P_KERNEL}" 2>/dev/null || true; pkill -f "php -S 127.0.0.1:${P_FAKE}" 2>/dev/null || true
  [ -f "$STATE/apache/main.conf" ] && apache2 -f "$STATE/apache/main.conf" -k stop 2>/dev/null || true
  sleep 0.3
}
case "${1:-}" in
  stop) stop ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    export APP_URL="http://127.0.0.1:${P_APP}" RI_PUBLIC_BASE_URL="http://127.0.0.1:${P_APP}" OS_INTERNAL_URL="http://127.0.0.1:${P_KERNEL}" MALUDB_API_URL="http://127.0.0.1:${P_FAKE}" MALUMAIL_API_URL="http://127.0.0.1:${P_FAKE}"
    export FAKE_KERNEL_STATE="$STATE/kernel.json" FAKE_MALUDB_LOG="$STATE/maludb.log" FAKE_MALUMAIL_STATE="$STATE/malumail.json" FAKE_MALUMAIL_LOG="$STATE/malumail.log"
    export RI_PICKER_EXTRA="$ROOT/tests/phase2/picker.php"
    : > "$FAKE_MALUDB_LOG"; : > "$FAKE_MALUMAIL_LOG"; echo '{"mailboxes":{}}' > "$FAKE_MALUMAIL_STATE"
    php -r '$f=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2], json_encode(["feed"=>$f["feed"], "scopes"=>$f["feed"]["scopes"]]));' "$ROOT/bin/dev_directory.json" "$FAKE_KERNEL_STATE"
    php -S 127.0.0.1:${P_KERNEL} "$ROOT/tests/fake_kernel.php" >"$STATE/kernel.log" 2>&1 & echo $! > "$STATE/kernel.pid"
    php -S 127.0.0.1:${P_FAKE} "$ROOT/tests/fake_maludb.php" >"$STATE/maludb.log.srv" 2>&1 & echo $! > "$STATE/maludb.pid"
    if [ "${RI_APP:-php}" = "apache" ]; then
      mkdir -p "$STATE/apache"
      python3 - "$ROOT" "$STATE/apache" "$P_APP" "$P_INTERNAL" <<'PY'
import sys
root, out = sys.argv[1], sys.argv[2]
v = open(root + '/deploy/apache-restaurant_inventory.conf').read()
for k, val in {'APP_FQDN': 'kitchen.subello.com', 'DOMAIN': 'subello.com', 'APP_KEY': 'restaurant_inventory', 'APP_DIR': root, 'APP_INTERNAL_PORT': sys.argv[4], 'MCP_RECORDS_PORT': '9398', 'MCP_ACTIVITY_PORT': '9399'}.items():
    v = v.replace('{{%s}}' % k, val)
assert '{{' not in v, 'unfilled placeholder in the vhost template'
v = v.replace('<VirtualHost *:80>', '<VirtualHost *:' + sys.argv[3] + '>')
open(out + '/site.conf', 'w').write(v)
open(out + '/main.conf', 'w').write('ServerRoot "/etc/apache2"\nDefaultRuntimeDir %s\nPidFile %s/apache.pid\nErrorLog %s/apache.err\nServerName localhost\nLogLevel warn\n'
    'Include /etc/apache2/mods-enabled/*.load\nInclude /etc/apache2/mods-enabled/*.conf\nDefine APACHE_LOG_DIR %s\nListen %s\nInclude %s/site.conf\n' % (out, out, out, out, sys.argv[3], out))
PY
      apache2 -f "$STATE/apache/main.conf" -k start
    else
      ( cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:${P_APP} -t html tests/dev_router.php >"$STATE/app.log" 2>&1 & echo $! > "$STATE/app.pid" )
    fi
    for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:${P_APP}/api/v1/health && break; sleep 0.2; done
    echo "servers up (app :${P_APP} ${RI_APP:-php}, fake kernel :${P_KERNEL}, fake MaluDB :${P_FAKE})" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
