<?php
date_default_timezone_set('America/New_York'); putenv('PGTZ=America/New_York');   // the proofs read the Downtown site's day, as the app's connection now does (app/db.php db_use_site_zone)
/**
 * Slice 3's proof helpers (docs/build-specs/ledger.md "Proof"), on the Phase 2 library's shape. Run them through tests/phase3/slice3/run.sh, which
 * builds the scratch database ri_dev3d, starts the application (:9316), a fake kernel (:9317) and a fake MaluDB (:9318) and puts the scratch
 * environment in the process environment.
 * Every proof prints "  ok   …" / "  FAIL …" and ends "all N passed" or "N FAILED".
 * The fixture (bin/dev_directory.json): 1 the owner (super-admin, admin at 101 Downtown and 102 Airport), 26 Priya (Kitchen at Airport), 27 Marco
 * (Manager at Downtown, Kitchen at Airport), 28 Bea (write at Downtown, no roles — Kitchen), 29 Ann (external Viewer at Downtown), 30 Dana (Chef
 * at Downtown), 31 Lee (read at Airport, no roles — Viewer), 32 Nico (a write grant naming no site — refused), 40 the expert (an agent, Chef at
 * both), 41 Watcher (an agent not admitted), 42 the Purchasing agent (Manager at Downtown), 43 Ada (an agent admin at Downtown).
 */
chdir(dirname(__DIR__, 3));
$GLOBALS['fails'] = 0;
$GLOBALS['passes'] = 0;
define('BASE', 'http://127.0.0.1:' . (getenv('P_APP') ?: '9316'));
const APP = 'restaurant_inventory';
const DOWNTOWN = 101;
const AIRPORT = 102;
function need(string $k): string { $v = getenv($k); if ($v === false || $v === '') { fwrite(STDERR, "$k is not set — run tests/phase3/slice3/run.sh\n"); exit(2); } return $v; }
function ok(bool $c, string $l): void { $GLOBALS[$c ? 'passes' : 'fails']++; echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n"; }
function finish(): never { echo $GLOBALS['fails'] ? "{$GLOBALS['fails']} FAILED ({$GLOBALS['passes']} passed)\n" : "all {$GLOBALS['passes']} passed\n"; exit($GLOBALS['fails'] ? 1 : 0); }
function pdo(): PDO {
    static $p = null;
    return $p ??= new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function q(string $sql, array $args = []): array { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
function one(string $sql, array $args = []) { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchColumn(); }
function fixture(): array { return json_decode(file_get_contents(dirname(__DIR__, 3) . '/bin/dev_directory.json'), true); }
/** ri_has_right() at a site, as the database answers it for a member. */
function right_of(int $member, string $right, int $scope = DOWNTOWN): bool { pdo()->exec("SELECT set_config('app.member_id', '$member', false)"); return (bool) one('SELECT ri_has_right(:r, :s)', ['r' => $right, 's' => $scope]); }

function req(string $method, string $path, array $o = []): array
{
    $ch = curl_init((str_starts_with($path, 'http') ? '' : BASE) . $path);
    $h = $o['headers'] ?? [];
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $o['jar'] ?? '/dev/null', CURLOPT_COOKIEFILE => $o['jar'] ?? '/dev/null'];
    if (isset($o['form'])) { $opt[CURLOPT_POSTFIELDS] = http_build_query($o['form']); }
    if (isset($o['json'])) { $opt[CURLOPT_POSTFIELDS] = json_encode($o['json']); $h[] = 'Content-Type: application/json'; }
    if (isset($o['raw'])) { $opt[CURLOPT_POSTFIELDS] = $o['raw']; }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($r, 0, $hs);
    return ['code' => $code, 'headers' => $headers, 'body' => substr($r, $hs), 'location' => preg_match('/^Location: (.+)$/mi', $headers, $m) ? trim($m[1]) : ''];
}
function jar(): string { return tempnam(sys_get_temp_dir(), 'rijar'); }
function csrf_of(string $html): string { preg_match('/name="csrf-token" id="csrf-token-meta" content="([^"]+)"/', $html, $m); return $m[1] ?? ''; }

/** A single-use hand-off URL path (/sso?token=&claims=), signed as the kernel signs one. $o: key, ttl, app, claims, scope. */
function handoff(int $member, array $o = []): string
{
    $key = $o['key'] ?? need('ACTION_TOKEN_KEY');
    $payload = $member . '.' . (time() + ($o['ttl'] ?? 60)) . '.' . ($o['app'] ?? APP) . '.' . bin2hex(random_bytes(16));
    $token = $payload . '.' . hash_hmac('sha256', 'sso:' . $payload, $key);
    $claims = ($o['claims'] ?? (fixture()['claims'][(string) $member] ?? [])) + ['member_id' => $member];
    if (array_key_exists('scope', $o)) { $claims['scope'] = $o['scope']; }
    $text = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    return '/sso?' . http_build_query(['token' => $token, 'claims' => $text . '.' . hash_hmac('sha256', $text, $key)]);
}
function sign_on(int $member, array $o = []): array { $j = jar(); return [$j, req('GET', handoff($member, $o), ['jar' => $j])]; }
function page(string $jar, string $path, array $o = []): array { return req('GET', $path, ['jar' => $jar] + $o); }
function page_csrf(string $jar): string { return csrf_of(req('GET', '/', ['jar' => $jar])['body']); }
function badge_of(string $jar): string { preg_match('/id="header-role-badge">([^<]+)</', req('GET', '/', ['jar' => $jar])['body'], $m); return trim($m[1] ?? ''); }
function site_of(string $jar): string { preg_match('/id="header-site-name"[^>]*>([^<]+)</', req('GET', '/', ['jar' => $jar])['body'], $m); return trim($m[1] ?? ''); }
function person_token(int $m, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl); return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY')); }
function run_token(int $m, int $runId, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl) . '.' . $runId; return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY')); }
function relay_of(string $tok): string { return hash_hmac('sha256', $tok, need('ACTIONS_RELAY_KEY')); }
function as_agent(string $tok): array { return ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . relay_of($tok)]; }
function kernel_state(?callable $change = null): array
{
    $f = need('FAKE_KERNEL_STATE');
    $s = json_decode((string) file_get_contents($f), true) ?: [];
    if ($change) { $s = $change($s) ?? $s; file_put_contents($f, json_encode($s)); }
    return $s;
}
function incr(array $lists, string $next): array
{
    return array_merge(['schema' => 'os.directory-changes/1', 'since' => 'x', 'next' => $next, 'full' => false, 'members' => [], 'departments' => [], 'memberships' => [],
        'deleted_departments' => [], 'scopes' => [], 'access' => []], $lists);
}
function sync(string $args = ''): string { return trim((string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/directory_sync.php') . ' ' . $args . ' 2>&1')); }
function activity(string $action, ?int $since = null): array { return q('SELECT * FROM activity_log WHERE action = :a AND id > :s ORDER BY id', ['a' => $action, 's' => $since ?? 0]); }
function last_activity_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM activity_log'); }
/** An access row of the feed for a member, with their holdings per site: [[scope_id, role, roles[], capability], …]. */
function access_row(int $member, array $sites, ?string $capability = 'write'): array
{
    $scopes = [];
    foreach ($sites as [$scope, $role, $roles, $cap]) { $scopes[] = ['scope_id' => $scope, 'role' => $role, 'roles' => $roles, 'capability' => $cap]; }
    $top = $sites[0] ?? [null, null, [], null];
    return ['member_id' => $member, 'role' => $top[1], 'roles' => array_values(array_unique(array_merge(...array_map(fn ($s) => $s[2], $sites ?: [[0, null, [], null]])))), 'rights' => [], 'capability' => $capability, 'scopes' => $scopes];
}

// ---- slice 3's world (ledger.md "Proof"): areas at both sites, the spec's ingredients with their units, Sysco and the Produce Co with items,
// the stock prep, the sandwich and the modifier — made by SQL as the writer; NO stock (the receipts below bring it). Idempotent per database.
function ri_sql(string $sql, array $args = []): void { $st = pdo()->prepare($sql); $st->execute($args); }
function id_of(string $sql, array $args = []): int { return (int) one($sql, $args); }
function ledger_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    pdo()->exec("SELECT set_config('app.member_id', '1', false)");
    if (!one("SELECT id FROM ingredients WHERE code = 'L-FLOUR'")) {
        pdo()->beginTransaction();
        $cat = static fn (string $k): int => id_of('SELECT id FROM ingredient_categories WHERE key = :k', ['k' => $k]);
        ri_sql("INSERT INTO storage_areas (scope_id, name, kind, walk_order, allow_negative) VALUES (101, 'SMOKE Walk-in', 'walk_in', 1, true), (101, 'SMOKE Dry', 'dry', 2, true), (101, 'SMOKE Bar', 'bar', 3, true), (101, 'SMOKE Cold room', 'walk_in', 4, false),
                (102, 'SMOKE Airport walk-in', 'walk_in', 1, true), (102, 'SMOKE Airport bar', 'bar', 2, true)");
        ri_sql("UPDATE site_settings SET depletion_area_id = (SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE Walk-in') WHERE scope_id = 101");
        $ins = static function (string $code, string $name, string $catKey, string $class, string $base, string $recipe, array $extra = []) use ($cat): int {
            $cols = array_merge(['code', 'name', 'category_id', 'measure_class', 'base_unit_code', 'recipe_unit_code'], array_keys($extra));
            $vals = array_merge([$code, $name, $cat($catKey), $class, $base, $recipe], array_values($extra));
            $st = pdo()->prepare('INSERT INTO ingredients (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ') RETURNING id');
            $st->execute($vals);
            return (int) $st->fetchColumn();
        };
        $flour = $ins('L-FLOUR', 'SMOKE Flour', 'dry', 'weight', 'g', 'g', ['shelf_life_days' => 180]);
        $chicken = $ins('L-CHK', 'SMOKE Chicken breast', 'protein', 'weight', 'g', 'g', ['yield_pct' => 85, 'catch_weight' => 't', 'shelf_life_days' => 4, 'variance_tolerance_pct' => 3, 'variance_tolerance_value' => 5]);
        $egg = $ins('L-EGG', 'SMOKE Eggs', 'dairy', 'each', 'each', 'each', ['each_weight_g' => 50, 'shelf_life_days' => 21, 'allergens' => '{egg}']);
        $lime = $ins('L-LIME', 'SMOKE Limes', 'produce', 'each', 'each', 'each', ['shelf_life_days' => 10]);
        $gin = $ins('L-GIN', 'SMOKE Gin', 'liquor', 'volume', 'ml', 'floz');
        $oil = $ins('L-OIL', 'SMOKE Olive oil', 'dry', 'volume', 'ml', 'floz', ['density_g_per_ml' => 0.916]);
        $stock = $ins('L-STOCK', 'SMOKE Chicken stock', 'dry', 'volume', 'ml', 'ml', ['is_prep' => 't']);
        ri_sql("INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count) VALUES
            (:f, 'bag50', '50 lb bag', 22679.6185, 'purchase', true), (:c, 'case', 'case ~40 lb', 18143.69, 'purchase', false), (:e, 'case', 'case of 15 dozen', 180, 'purchase', false), (:e2, 'flat', 'flat of 30', 30, 'count', true),
            (:l, 'case', 'case of 200', 200, 'purchase', true), (:g, 'bottle', '750 ml bottle', 750, 'count', true), (:g2, 'case6', 'case of 6 × 750 ml', 4500, 'purchase', false), (:o, 'case4', 'case of 4 × 1 gal', 15141.65, 'purchase', true)",
            ['f' => $flour, 'c' => $chicken, 'e' => $egg, 'e2' => $egg, 'l' => $lime, 'g' => $gin, 'g2' => $gin, 'o' => $oil]);
        ri_sql("INSERT INTO suppliers (name, kind, order_method, lead_time_days) VALUES ('SMOKE Sysco', 'distributor', 'email', 2), ('SMOKE Produce Co', 'wholesaler', 'phone', 1)");
        $sysco = id_of("SELECT id FROM suppliers WHERE name = 'SMOKE Sysco'"); $prod = id_of("SELECT id FROM suppliers WHERE name = 'SMOKE Produce Co'");
        ri_sql("INSERT INTO supplier_items (supplier_id, ingredient_id, supplier_sku, purchase_unit_code, pack, last_price, catch_weight) VALUES
            (:s, :f, 'SY-FL50', 'bag50', '50 lb', 25.00, false), (:s2, :e, 'SY-EGG15', 'case', '15 dz', 54.00, false), (:s3, :c, 'SY-CHK', 'lb', 'case ~40 lb, priced per lb', 3.10, true), (:p, :l, 'PC-LIME', 'case', '200 ct', 38.00, false)",
            ['s' => $sysco, 'f' => $flour, 's2' => $sysco, 'e' => $egg, 's3' => $sysco, 'c' => $chicken, 'p' => $prod, 'l' => $lime]);
        pdo()->exec("SELECT set_config('app.member_id', '30', false)");
        ri_sql("INSERT INTO recipes (code, name, kind, category_id, output_ingredient_id, created_by) VALUES ('L-R-STOCK', 'SMOKE Chicken stock prep', 'prep', (SELECT id FROM menu_categories WHERE key = 'prep'), :o, 30)", ['o' => $stock]);
        $rs = id_of("SELECT id FROM recipes WHERE code = 'L-R-STOCK'");
        ri_sql("INSERT INTO recipe_versions (recipe_id, batch_yield_qty, batch_yield_unit_code, expected_loss_pct, created_by) VALUES (:r, 4, 'l', 10, 30)", ['r' => $rs]);
        $vs = id_of('SELECT max(id) FROM recipe_versions WHERE recipe_id = :r', ['r' => $rs]);
        ri_sql("INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, qty_base) VALUES (:v, 1, :c, 2, 'kg', 0)", ['v' => $vs, 'c' => $chicken]);
        ri_sql('SELECT ri_recipe_version_activate(:v, 30, current_date - 30)', ['v' => $vs]);
        ri_sql("INSERT INTO recipes (code, name, kind, category_id, created_by) VALUES ('L-R-SAND', 'SMOKE Chicken sandwich', 'menu_item', (SELECT id FROM menu_categories WHERE key = 'mains'), 30)");
        $rw = id_of("SELECT id FROM recipes WHERE code = 'L-R-SAND'");
        ri_sql("INSERT INTO recipe_versions (recipe_id, batch_yield_qty, created_by) VALUES (:r, 1, 30)", ['r' => $rw]);
        $vw = id_of('SELECT max(id) FROM recipe_versions WHERE recipe_id = :r', ['r' => $rw]);
        ri_sql("INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, qty_base, line_yield_pct) VALUES (:v, 1, :c, 170, 'g', 0, 90)", ['v' => $vw, 'c' => $chicken]);
        ri_sql("INSERT INTO recipe_lines (version_id, seq, sub_recipe_id, qty, unit_code, qty_base) VALUES (:v, 2, :s, 50, 'ml', 0)", ['v' => $vw, 's' => $rs]);
        ri_sql("INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, qty_base) VALUES (:v, 3, :e, 1, 'each', 0)", ['v' => $vw, 'e' => $egg]);
        ri_sql('SELECT ri_recipe_version_activate(:v, 30, current_date - 20)', ['v' => $vw]);
        ri_sql("INSERT INTO recipes (code, name, kind, category_id, created_by) VALUES ('L-M-XCHK', 'SMOKE Extra chicken', 'modifier', (SELECT id FROM menu_categories WHERE key = 'modifiers'), 30)");
        $rm = id_of("SELECT id FROM recipes WHERE code = 'L-M-XCHK'");
        ri_sql("INSERT INTO recipe_versions (recipe_id, batch_yield_qty, created_by) VALUES (:r, 1, 30)", ['r' => $rm]);
        $vm = id_of('SELECT max(id) FROM recipe_versions WHERE recipe_id = :r', ['r' => $rm]);
        ri_sql("INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, qty_base) VALUES (:v, 1, :c, 85, 'g', 0)", ['v' => $vm, 'c' => $chicken]);
        ri_sql('SELECT ri_recipe_version_activate(:v, 30, current_date - 20)', ['v' => $vm]);
        pdo()->commit();
    }
    $w = [];
    foreach (['flour' => 'L-FLOUR', 'chicken' => 'L-CHK', 'egg' => 'L-EGG', 'lime' => 'L-LIME', 'gin' => 'L-GIN', 'oil' => 'L-OIL', 'stock' => 'L-STOCK'] as $k => $code) { $w[$k] = id_of('SELECT id FROM ingredients WHERE code = :c', ['c' => $code]); }
    foreach (['walkin' => [101, 'SMOKE Walk-in'], 'dry' => [101, 'SMOKE Dry'], 'bar' => [101, 'SMOKE Bar'], 'cold' => [101, 'SMOKE Cold room'], 'awalkin' => [102, 'SMOKE Airport walk-in'], 'abar' => [102, 'SMOKE Airport bar']] as $k => [$s, $n]) {
        $w[$k] = id_of('SELECT id FROM storage_areas WHERE scope_id = :s AND name = :n', ['s' => $s, 'n' => $n]);
    }
    $w['sysco'] = id_of("SELECT id FROM suppliers WHERE name = 'SMOKE Sysco'"); $w['produce'] = id_of("SELECT id FROM suppliers WHERE name = 'SMOKE Produce Co'");
    foreach (['si_flour' => 'SY-FL50', 'si_egg' => 'SY-EGG15', 'si_chicken' => 'SY-CHK', 'si_lime' => 'PC-LIME'] as $k => $sku) { $w[$k] = id_of('SELECT id FROM supplier_items WHERE supplier_sku = :s', ['s' => $sku]); }
    foreach (['r_stock' => 'L-R-STOCK', 'r_sand' => 'L-R-SAND', 'r_mod' => 'L-M-XCHK'] as $k => $code) { $w[$k] = id_of('SELECT id FROM recipes WHERE code = :c', ['c' => $code]); }
    return $w;
}
/** POST as a signed-in person with the page's CSRF. */
function post(string $jar, string $path, array $form, array $headers = []): array
{
    static $csrf = [];
    $csrf[$jar] ??= page_csrf($jar);
    return req('POST', $path, ['jar' => $jar, 'form' => ['csrf_token' => $csrf[$jar]] + $form, 'headers' => $headers]);
}
function post_json(string $jar, string $path, array $form): array { $r = post($jar, $path, $form, ['Accept: application/json']); $r['json'] = json_decode($r['body'], true) ?: []; return $r; }
function get_json(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => ['Accept: application/json']]); $r['json'] = json_decode($r['body'], true) ?: []; return $r; }
function has(array $r, string $needle): bool { return str_contains($r['body'], $needle); }
function near(float|string|null $a, float $b, float $tol = 0.01): bool { return $a !== null && $a !== '' && abs((float) $a - $b) <= $tol; }
function on_hand(int $ingredient, int $scope = DOWNTOWN, ?int $area = null): float { return (float) one('SELECT ri_on_hand(:i, :s, :a)', ['i' => $ingredient, 's' => $scope, 'a' => $area]); }
function avg_cost(int $ingredient, int $scope = DOWNTOWN): float { return (float) one('SELECT avg_cost_per_base FROM ingredient_site_costs WHERE ingredient_id = :i AND scope_id = :s', ['i' => $ingredient, 's' => $scope]); }
function last_id(string $table): int { return (int) one("SELECT COALESCE(max(id), 0) FROM $table"); }
function as_member(int $m): void { pdo()->exec("SELECT set_config('app.member_id', '$m', false)"); }
/** A signed-on person at a site (Marco and the owner land at Airport by name — the scope names Downtown when asked). */
function person(int $member, ?int $scope = DOWNTOWN): string { [$j] = sign_on($member, $scope === null ? [] : ['scope' => $scope]); return $j; }
function agent_facts(int $member, int $run, int $scope = DOWNTOWN): void
{
    kernel_state(function ($s) use ($member, $run, $scope) { $s['facts'][(string) $run] = ['valid' => true, 'is_agent' => true, 'member_id' => $member, 'run_id' => $run, 'request_id' => 'req-run-' . $run, 'trigger' => 'chat', 'scope' => $scope, 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
}
function agent_req(string $method, string $path, int $member, int $run, array $form = [], int $scope = DOWNTOWN): array
{
    $h = as_agent(run_token($member, $run)); $h[] = 'X-Site: ' . $scope;
    $r = req($method, $path, ['headers' => $h] + ($method === 'POST' ? ['form' => $form] : []));
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** Stock in through the real door: a receipt with JSON lines, posted; returns the receipt's id. */
function stock_in(string $jar, int $area, array $lines, ?int $supplier = null): int
{
    $f = ['area' => $area, 'lines' => json_encode($lines)];
    if ($supplier !== null) { $f['supplier'] = $supplier; }
    post($jar, '/receipts/save.php', $f);
    $g = last_id('goods_receipts');
    post($jar, '/receipts/post.php', ['receipt' => $g]);
    return $g;
}
/** POST multipart (a file) as a signed-in person with the page's CSRF. */
function post_file(string $jar, string $path, array $form, string $field, string $file, string $mime): array
{
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POSTFIELDS => ['csrf_token' => page_csrf($jar)] + $form + [$field => new CURLFile($file, $mime, basename($file))]]);
    $r = (string) curl_exec($ch);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => substr($r, $hs), 'location' => preg_match('/^Location: (.+)$/mi', substr($r, 0, $hs), $m) ? trim($m[1]) : ''];
}
