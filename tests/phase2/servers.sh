#!/usr/bin/env bash
# tests/phase2/servers.sh start|stop — the proofs' servers on loopback, all from the scratch environment ($RI_DEV_ENV):
#   the application on :9301, a fake kernel on :9302, a fake MaluDB + MaluMail on :9303 (design §14's scratch block 9301–9307).
#   The application is `php -S` with tests/dev_router.php by default; with RI_APP=apache it is a REAL Apache (as your user, mod_php) serving
#   deploy/apache-restaurant_inventory.conf rendered the way the kernel's installer renders it (the internal vhost on :9307) — the proofs
#   then exercise the vhost's own rewrites. State: $RI_DEV_STATE (default /tmp/ri-dev2-state) holds the pid files, the fake kernel's state
#   file and the MaluDB log.
set -euo pipefail
export P_APP=${P_APP:-9301} P_KERNEL=${P_KERNEL:-9302} P_FAKE=${P_FAKE:-9303} P_INTERNAL=${P_INTERNAL:-9307}   # the port block (design §14)
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${RI_DEV_ENV:-/tmp/ri-dev2.env}
STATE=${RI_DEV_STATE:-/tmp/ri-dev2-state}
mkdir -p "$STATE"
stop() {
  for f in "$STATE"/*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  for port in "$P_APP" "$P_KERNEL" "$P_FAKE"; do pkill -f "php -S 127.0.0.1:${port} " 2>/dev/null || true; done   # only this block's ports
  [ -f "$STATE/apache/main.conf" ] && apache2 -f "$STATE/apache/main.conf" -k stop 2>/dev/null || true
  sleep 0.3
}
case "${1:-}" in
  stop) stop ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    export APP_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
    export FAKE_KERNEL_STATE="$STATE/kernel.json" FAKE_MALUDB_LOG="$STATE/maludb.log" FAKE_MALUMAIL_STATE="$STATE/malumail.json" FAKE_MALUMAIL_LOG="$STATE/malumail.log"
    export RI_PICKER_EXTRA="$ROOT/tests/phase2/picker.php"
    : > "$FAKE_MALUDB_LOG"; : > "$FAKE_MALUMAIL_LOG"; echo '{"mailboxes":{}}' > "$FAKE_MALUMAIL_STATE"
    php -r '$f=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2], json_encode(["feed"=>$f["feed"], "scopes"=>$f["feed"]["scopes"]]));' "$ROOT/bin/dev_directory.json" "$FAKE_KERNEL_STATE"
    php -S 127.0.0.1:$P_KERNEL "$ROOT/tests/fake_kernel.php" >"$STATE/kernel.log" 2>&1 & echo $! > "$STATE/kernel.pid"
    php -S 127.0.0.1:$P_FAKE "$ROOT/tests/fake_maludb.php" >"$STATE/maludb.log.srv" 2>&1 & echo $! > "$STATE/maludb.pid"
    if [ "${RI_APP:-php}" = "apache" ]; then
      mkdir -p "$STATE/apache"
      python3 - "$ROOT" "$STATE/apache" <<'PY'
import os, sys
root, out = sys.argv[1], sys.argv[2]
v = open(root + '/deploy/apache-restaurant_inventory.conf').read()
for k, val in {'APP_FQDN': 'kitchen.subello.com', 'DOMAIN': 'subello.com', 'APP_KEY': 'restaurant_inventory', 'APP_DIR': root, 'APP_INTERNAL_PORT': os.environ.get('P_INTERNAL', '9307'), 'MCP_RECORDS_PORT': '9304', 'MCP_ACTIVITY_PORT': '9305'}.items():
    v = v.replace('{{%s}}' % k, val)
assert '{{' not in v, 'unfilled placeholder in the vhost template'
v = v.replace('<VirtualHost *:80>', '<VirtualHost *:%s>' % os.environ.get('P_APP', '9301'))
open(out + '/site.conf', 'w').write(v)
open(out + '/main.conf', 'w').write('ServerRoot "/etc/apache2"\nDefaultRuntimeDir %s\nPidFile %s/apache.pid\nErrorLog %s/apache.err\nServerName localhost\nLogLevel warn\n'
    'Include /etc/apache2/mods-enabled/*.load\nInclude /etc/apache2/mods-enabled/*.conf\nDefine APACHE_LOG_DIR %s\nListen %s\nInclude %s/site.conf\n' % ((out,) * 4 + (os.environ.get('P_APP', '9301'), out)))
PY
      apache2 -f "$STATE/apache/main.conf" -k start
    else
      ( cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$P_APP -t html tests/dev_router.php >"$STATE/app.log" 2>&1 & echo $! > "$STATE/app.pid" )
    fi
    for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:$P_APP/api/v1/health && break; sleep 0.2; done
    echo "servers up (app :$P_APP ${RI_APP:-php}, fake kernel :$P_KERNEL, fake MaluDB :$P_FAKE)" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
