<?php
/**
 * Helpers for the Phase 2 proofs (docs/build-specs/sso-shell.md). Run them through tests/phase2/run.sh, which builds the scratch database,
 * starts the application (:9301), a fake kernel (:9302) and a fake MaluDB (:9303) and puts the scratch environment in the process environment.
 * Every proof prints "  ok   …" / "  FAIL …" and ends "all N passed" or "N FAILED".
 * The fixture (bin/dev_directory.json): 1 the owner (super-admin, admin at 101 Downtown and 102 Airport), 26 Priya (Kitchen at Airport), 27 Marco
 * (Manager at Downtown, Kitchen at Airport), 28 Bea (write at Downtown, no roles — Kitchen), 29 Ann (external Viewer at Downtown), 30 Dana (Chef
 * at Downtown), 31 Lee (read at Airport, no roles — Viewer), 32 Nico (a write grant naming no site — refused), 40 the expert (an agent, Chef at
 * both), 41 Watcher (an agent not admitted), 42 the Purchasing agent (Manager at Downtown), 43 Ada (an agent admin at Downtown).
 */
chdir(dirname(__DIR__, 3));
$GLOBALS['fails'] = 0;
$GLOBALS['passes'] = 0;
define('BASE', 'http://127.0.0.1:' . (getenv('P_APP') ?: '9304'));
const APP = 'restaurant_inventory';
const DOWNTOWN = 101;
const AIRPORT = 102;
function need(string $k): string { $v = getenv($k); if ($v === false || $v === '') { fwrite(STDERR, "$k is not set — run tests/phase3/slice2/run.sh\n"); exit(2); } return $v; }
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

// ---- slice 2's world (recipes.md "Proof"): ingredients with their units, a supplier, the receipts that give costs at Downtown, a POS source; members from the fixture ----
function ri_sql(string $sql, array $args = []): void { $st = pdo()->prepare($sql); $st->execute($args); }
function id_of(string $sql, array $args = []): int { return (int) one($sql, $args); }
function recipes_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    pdo()->exec("SELECT set_config('app.member_id', '27', false)");
    if (one("SELECT id FROM ingredients WHERE code = 'SMK-CHK'")) {              // made by an earlier proof file on the same scratch database
        $w = [];
        foreach (['chicken' => 'SMK-CHK', 'egg' => 'SMK-EGG', 'stock' => 'SMK-STOCK', 'gin' => 'SMK-GIN', 'lime' => 'SMK-LIME', 'flour' => 'SMK-FLOUR', 'lettuce' => 'SMK-LET'] as $k => $code) { $w[$k] = id_of('SELECT id FROM ingredients WHERE code = :c', ['c' => $code]); }
        $w['walkin'] = id_of("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE Walk-in'"); $w['dry'] = id_of("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE Dry'");
        $w['sysco'] = id_of("SELECT id FROM suppliers WHERE name = 'SMOKE Sysco'"); $w['source'] = id_of("SELECT id FROM sources WHERE name = 'SMOKE Toast export'"); $w['chicken_cost'] = 3.10 / 453.59237;
        return $w;
    }
    $cat = static fn (string $k): int => id_of('SELECT id FROM ingredient_categories WHERE key = :k', ['k' => $k]);
    ri_sql("INSERT INTO storage_areas (scope_id, name, kind, walk_order) VALUES (101, 'SMOKE Walk-in', 'walk_in', 1), (101, 'SMOKE Dry', 'dry', 2) ON CONFLICT DO NOTHING");
    $walkin = id_of("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE Walk-in'"); $dry = id_of("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE Dry'");
    ri_sql('UPDATE site_settings SET depletion_area_id = :a WHERE scope_id = 101', ['a' => $walkin]);
    $ins = static function (string $code, string $name, string $catKey, string $class, string $base, string $recipe, array $extra = []) use ($cat): int {
        $cols = array_merge(['code', 'name', 'category_id', 'measure_class', 'base_unit_code', 'recipe_unit_code'], array_keys($extra));
        $vals = array_merge([$code, $name, $cat($catKey), $class, $base, $recipe], array_values($extra));
        $st = pdo()->prepare('INSERT INTO ingredients (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ') RETURNING id');
        $st->execute($vals);
        return (int) $st->fetchColumn();
    };
    $w = ['walkin' => $walkin, 'dry' => $dry];
    $w['chicken'] = $ins('SMK-CHK', 'SMOKE Chicken breast', 'protein', 'weight', 'g', 'g', ['yield_pct' => 85, 'catch_weight' => 't', 'shelf_life_days' => 4]);
    $w['egg'] = $ins('SMK-EGG', 'SMOKE Eggs', 'dairy', 'each', 'each', 'each', ['each_weight_g' => 50, 'allergens' => '{egg}']);
    $w['stock'] = $ins('SMK-STOCK', 'SMOKE Chicken stock', 'dry', 'volume', 'ml', 'ml', ['is_prep' => 't']);
    $w['gin'] = $ins('SMK-GIN', 'SMOKE Gin', 'liquor', 'volume', 'ml', 'floz');
    $w['lime'] = $ins('SMK-LIME', 'SMOKE Limes', 'produce', 'each', 'each', 'each', ['shelf_life_days' => 10]);
    $w['flour'] = $ins('SMK-FLOUR', 'SMOKE Flour', 'dry', 'weight', 'g', 'g');
    $w['lettuce'] = $ins('SMK-LET', 'SMOKE Lettuce', 'produce', 'weight', 'g', 'g');
    ri_sql("INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count) VALUES (:c, 'case', 'case ~40 lb', 18143.69, 'purchase', true), (:e, 'case', 'case of 15 dozen', 180, 'purchase', false), (:e2, 'flat', 'flat of 30', 30, 'count', true), (:g, 'bottle', '750 ml bottle', 750, 'count', true), (:g2, 'case6', 'case of 6', 4500, 'purchase', false), (:l, 'case', 'case of 200', 200, 'purchase', true), (:f, 'bag50', '50 lb bag', 22679.6185, 'purchase', true)",
           ['c' => $w['chicken'], 'e' => $w['egg'], 'e2' => $w['egg'], 'g' => $w['gin'], 'g2' => $w['gin'], 'l' => $w['lime'], 'f' => $w['flour']]);
    ri_sql("INSERT INTO suppliers (name, kind, order_method, lead_time_days) VALUES ('SMOKE Sysco', 'distributor', 'email', 2)");
    $w['sysco'] = id_of("SELECT id FROM suppliers WHERE name = 'SMOKE Sysco'");
    ri_sql("INSERT INTO supplier_items (supplier_id, ingredient_id, supplier_sku, purchase_unit_code, last_price, catch_weight) VALUES (:s, :c, 'SY-CHK', 'lb', 3.10, true), (:s2, :e, 'SY-EGG', 'case', 54.00, false), (:s3, :f, 'SY-FL50', 'bag50', 25.00, false)", ['s' => $w['sysco'], 'c' => $w['chicken'], 's2' => $w['sysco'], 'e' => $w['egg'], 's3' => $w['sysco'], 'f' => $w['flour']]);
    ri_sql("INSERT INTO goods_receipts (scope_id, supplier_id, storage_area_id, received_on, received_at, created_by) VALUES (101, :s, :a, current_date - 7, ri_site_moment(101, current_date - 7, false) + interval '2 hours', 27)", ['s' => $w['sysco'], 'a' => $walkin]);
    $gr = id_of('SELECT max(id) FROM goods_receipts');
    ri_sql("INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, qty_received, purchase_unit_code, weight_qty, weight_unit_code, unit_cost) VALUES (:g, 1, :c, 1, 'case', 38.5, 'lb', 3.10)", ['g' => $gr, 'c' => $w['chicken']]);
    ri_sql("INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, qty_received, purchase_unit_code, unit_cost) VALUES (:g, 2, :e, 2, 'case', 54.00), (:g2, 3, :f, 2, 'bag50', 25.00), (:g3, 4, :gin, 2, 'case6', 108.00), (:g4, 5, :l, 1, 'case', 38.00)", ['g' => $gr, 'e' => $w['egg'], 'g2' => $gr, 'f' => $w['flour'], 'g3' => $gr, 'gin' => $w['gin'], 'g4' => $gr, 'l' => $w['lime']]);
    ri_sql('SELECT ri_post_receipt(:g, 27)', ['g' => $gr]);
    ri_sql("INSERT INTO sources (scope_id, name, connector, settings, created_by) VALUES (101, 'SMOKE Toast export', 'file', '{\"mapping_key\": \"generic\"}', 1)");
    $w['source'] = id_of("SELECT id FROM sources WHERE name = 'SMOKE Toast export'");
    $w['chicken_cost'] = 3.10 / 453.59237;
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
function has(array $r, string $needle): bool { return str_contains($r['body'], $needle); }
function near(float $a, float $b, float $tol = 0.01): bool { return abs($a - $b) <= $tol; }

/** The three recipes of the spec's world (the stock, the sandwich, the modifier), made by SQL and activated 30 / 20 days back; idempotent. Returns their ids and version ids. */
function recipes_fixture(): array
{
    $w = recipes_world();
    pdo()->exec("SELECT set_config('app.member_id', '30', false)");
    if (!one("SELECT id FROM recipes WHERE code = 'R-SMOKE_CHICKEN_STOCK'")) {
        pdo()->beginTransaction();
        // the fixture's own output ingredient (recipes.php may already have a prep on SMK-STOCK — one active recipe per output)
        ri_sql("INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code, is_prep) VALUES ('SMK-STOCK-F', 'SMOKE Chicken stock (fixture)', (SELECT id FROM ingredient_categories WHERE key = 'dry'), 'volume', 'ml', 'ml', true) ON CONFLICT DO NOTHING");
        $w['stock_f'] = id_of("SELECT id FROM ingredients WHERE code = 'SMK-STOCK-F'");
        ri_sql("INSERT INTO recipes (code, name, kind, category_id, output_ingredient_id, created_by) VALUES ('R-SMOKE_CHICKEN_STOCK', 'SMOKE Chicken stock', 'prep', (SELECT id FROM menu_categories WHERE key = 'prep'), :o, 30)", ['o' => $w['stock_f']]);
        $rs = id_of("SELECT id FROM recipes WHERE code = 'R-SMOKE_CHICKEN_STOCK'");
        ri_sql("INSERT INTO recipe_versions (recipe_id, batch_yield_qty, batch_yield_unit_code, expected_loss_pct, created_by) VALUES (:r, 4, 'l', 10, 30)", ['r' => $rs]);
        $vs = id_of('SELECT max(id) FROM recipe_versions WHERE recipe_id = :r', ['r' => $rs]);
        ri_sql("INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, qty_base) VALUES (:v, 1, :c, 2, 'kg', 0)", ['v' => $vs, 'c' => $w['chicken']]);
        ri_sql('SELECT ri_recipe_version_activate(:v, 30, current_date - 30)', ['v' => $vs]);
        ri_sql("INSERT INTO recipes (code, name, kind, category_id, created_by) VALUES ('R-SMOKE_CHICKEN_SANDWICH', 'SMOKE Chicken sandwich', 'menu_item', (SELECT id FROM menu_categories WHERE key = 'mains'), 30)");
        $rw = id_of("SELECT id FROM recipes WHERE code = 'R-SMOKE_CHICKEN_SANDWICH'");
        ri_sql("INSERT INTO recipe_versions (recipe_id, batch_yield_qty, created_by) VALUES (:r, 1, 30)", ['r' => $rw]);
        $vw = id_of('SELECT max(id) FROM recipe_versions WHERE recipe_id = :r', ['r' => $rw]);
        ri_sql("INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, qty_base, line_yield_pct) VALUES (:v, 1, :c, 170, 'g', 0, 90)", ['v' => $vw, 'c' => $w['chicken']]);
        ri_sql("INSERT INTO recipe_lines (version_id, seq, sub_recipe_id, qty, unit_code, qty_base) VALUES (:v, 2, :s, 50, 'ml', 0)", ['v' => $vw, 's' => $rs]);
        ri_sql("INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, qty_base) VALUES (:v, 3, :e, 1, 'each', 0)", ['v' => $vw, 'e' => $w['egg']]);
        ri_sql('SELECT ri_recipe_version_activate(:v, 30, current_date - 20)', ['v' => $vw]);
        ri_sql("INSERT INTO recipes (code, name, kind, category_id, created_by) VALUES ('M-SMOKE_EXTRA_CHICKEN_F', 'SMOKE Extra chicken (fixture)', 'modifier', (SELECT id FROM menu_categories WHERE key = 'modifiers'), 30)");
        $rm = id_of("SELECT id FROM recipes WHERE code = 'M-SMOKE_EXTRA_CHICKEN_F'");
        ri_sql("INSERT INTO recipe_versions (recipe_id, batch_yield_qty, created_by) VALUES (:r, 1, 30)", ['r' => $rm]);
        $vm = id_of('SELECT max(id) FROM recipe_versions WHERE recipe_id = :r', ['r' => $rm]);
        ri_sql("INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, qty_base) VALUES (:v, 1, :c, 85, 'g', 0)", ['v' => $vm, 'c' => $w['chicken']]);
        ri_sql('SELECT ri_recipe_version_activate(:v, 30, current_date - 20)', ['v' => $vm]);
        ri_sql("INSERT INTO menu_items (pos_source_id, plu, name, category_id, sale_price, recipe_id, mapped_by) VALUES (:s, '1001', 'SMOKE Chicken Sandwich', (SELECT id FROM menu_categories WHERE key = 'mains'), 14.00, :r, 30)", ['s' => $w['source'], 'r' => $rw]);
        ri_sql("INSERT INTO modifiers (pos_source_id, code, name, action, recipe_id) VALUES (:s, 'XCHK', 'SMOKE Extra chicken', 'add', :r)", ['s' => $w['source'], 'r' => $rm]);
        pdo()->commit();
    }
    $f = ['stock' => id_of("SELECT id FROM recipes WHERE code = 'R-SMOKE_CHICKEN_STOCK'"), 'sandwich' => id_of("SELECT id FROM recipes WHERE code = 'R-SMOKE_CHICKEN_SANDWICH'"), 'modifier' => id_of("SELECT id FROM recipes WHERE code = 'M-SMOKE_EXTRA_CHICKEN_F'")];
    foreach (['stock', 'sandwich', 'modifier'] as $k) { $f[$k . '_v'] = id_of('SELECT ri_recipe_version_on(:r, current_date)', ['r' => $f[$k]]); }
    $f['plu1001'] = id_of("SELECT id FROM menu_items WHERE plu = '1001'"); $f['xchk'] = id_of("SELECT id FROM modifiers WHERE code = 'XCHK'");
    return $f + $w;
}

if (!function_exists('format_date')) { function format_date(?string $d): string { return $d === null || $d === '' ? '' : (new DateTimeImmutable($d))->format('M j, Y'); } }
