<?php
/**
 * Helpers for slice 5's proofs (docs/build-specs/pos.md "Proof"), run through tests/phase3/slice5/run.sh: the scratch database ri_dev3e, the
 * application on :9320, the fake kernel :9321, the fake MaluDB + MaluMail (with the mailbox MCP) :9322, the POS fixture server (a fake Square
 * Orders API) :9323. The request helpers are slice 2's (tests/phase3/slice2/lib.php — BASE follows P_APP). pos_world() makes, by SQL, the
 * world the proofs read: five ingredients with their own units, two storage areas at Downtown (the Walk-in the depletion area, the Bar the
 * gin's), a receipt dated 2026-10-01 that gives every cost, the recipes — a chicken sandwich (170 g chicken at a 90 % line yield, 30 g
 * tomato, a bun), a gin and tonic (1.5 floz gin, an eighth of a lime), the modifiers "extra chicken" (+85 g) and "no tomato" (−30 g), a
 * "special" (100 g chicken) — all in force from 2026-09-01; map_source() maps a source's PLUs 1001 and 3001 and its modifiers XCHK and NOTOM.
 * The fixture days (tests/fixtures/pos/README.md): 2026-10-05 and -06 by the templates, -07 over SFTP, -08 from Square, -09 by email.
 */
require_once dirname(__DIR__) . '/slice2/lib.php';

const FIX = '/srv/apps/restaurant_inventory/tests/fixtures/pos';
const D1 = '2026-10-05';
const D2 = '2026-10-06';
const GT_GIN_ML = 44.36029434375;              // 1.5 floz
const SANDWICH_CHICKEN_G = 188.88888889;        // 170 g at a 90 % line yield

function state_dir(): string { return (string) (getenv('RI_DEV_STATE') ?: '/tmp/ri-dev3e-state'); }

/** A multipart POST as a signed-in person with the page's CSRF (a file field and form fields). */
function post_file(string $jar, string $path, array $form, string $field, string $file, ?string $name = null, array $headers = []): array
{
    static $csrf = [];
    $csrf[$jar] ??= page_csrf($jar);
    $ch = curl_init(BASE . $path);
    $body = ['csrf_token' => $csrf[$jar]] + $form + [$field => new CURLFile($file, 'text/csv', $name ?? basename($file))];
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers]);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $out = ['code' => $code, 'headers' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
    $out['json'] = json_decode($out['body'], true) ?: [];
    return $out;
}
function post_file_json(string $jar, string $path, array $form, string $field, string $file, ?string $name = null): array { return post_file($jar, $path, $form, $field, $file, $name, ['Accept: application/json']); }

/** A member signed on at a site (claims.scope), the jar answered. */
function at(int $member, int $scope = DOWNTOWN): string { [$j] = sign_on($member, ['scope' => $scope]); return $j; }

function as_member(int $m): void { pdo()->exec("SELECT set_config('app.member_id', '$m', false)"); }

/** The worker, as cron runs it (the scratch environment is in this process's environment). */
function worker(string $passes, array $env = []): array
{
    $pre = '';
    foreach ($env as $k => $v) { $pre .= $k . '=' . escapeshellarg($v) . ' '; }
    $out = (string) shell_exec($pre . 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=' . escapeshellarg($passes) . ' 2>/dev/null');
    $lines = array_values(array_filter(explode("\n", trim($out))));
    return json_decode((string) end($lines), true) ?: ['raw' => $out];
}

function pos_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    as_member(1);
    $id = static fn (string $code): int => (int) one('SELECT id FROM ingredients WHERE code = :c', ['c' => $code]);
    if (!one("SELECT id FROM ingredients WHERE code = 'SMK5-CHK'")) {
        pdo()->beginTransaction();
        $cat = static fn (string $k): int => (int) one('SELECT id FROM ingredient_categories WHERE key = :k', ['k' => $k]);
        ri_sql("INSERT INTO storage_areas (scope_id, name, kind, walk_order) VALUES (101, 'SMOKE5 Walk-in', 'walk_in', 1), (101, 'SMOKE5 Bar', 'bar', 2)");
        $walk = (int) one("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE5 Walk-in'");
        $bar = (int) one("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE5 Bar'");
        ri_sql('UPDATE site_settings SET depletion_area_id = :a WHERE scope_id = 101', ['a' => $walk]);
        foreach ([['SMK5-CHK', 'SMOKE5 Chicken breast', 'protein', 'weight', 'g', 'g', null, null], ['SMK5-TOM', 'SMOKE5 Tomato', 'produce', 'weight', 'g', 'g', null, null],
                  ['SMK5-BUN', 'SMOKE5 Bun', 'bakery', 'each', 'each', 'each', null, null], ['SMK5-GIN', 'SMOKE5 Gin', 'liquor', 'volume', 'ml', 'floz', null, null],
                  ['SMK5-LIME', 'SMOKE5 Limes', 'produce', 'each', 'each', 'each', null, null]] as $i) {
            ri_sql('INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code) VALUES (:c, :n, :cat, :m, :b, :r)', ['c' => $i[0], 'n' => $i[1], 'cat' => $cat($i[2]), 'm' => $i[3], 'b' => $i[4], 'r' => $i[5]]);
        }
        ri_sql("INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count) VALUES
                (:c, 'case10', 'case of 10 kg', 10000, 'purchase', false), (:t, 'case5', 'case of 5 kg', 5000, 'purchase', false), (:b, 'bag12', 'bag of 12', 12, 'purchase', false),
                (:g, 'bottle', '750 ml bottle', 750, 'count', true), (:l, 'case', 'case of 100', 100, 'purchase', false)",
               ['c' => $id('SMK5-CHK'), 't' => $id('SMK5-TOM'), 'b' => $id('SMK5-BUN'), 'g' => $id('SMK5-GIN'), 'l' => $id('SMK5-LIME')]);
        ri_sql('INSERT INTO ingredient_site_settings (ingredient_id, scope_id, default_area_id) VALUES (:g, 101, :a)', ['g' => $id('SMK5-GIN'), 'a' => $bar]);
        ri_sql("INSERT INTO goods_receipts (scope_id, storage_area_id, received_on, received_at, created_by) VALUES (101, :a, '2026-10-01', '2026-10-01 10:00:00-04', 1)", ['a' => $walk]);
        $gr = (int) one('SELECT max(id) FROM goods_receipts');
        ri_sql("INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, qty_received, purchase_unit_code, unit_cost, putaway_area_id) VALUES
                (:g, 1, :c, 2, 'case10', 70.00, NULL), (:g2, 2, :t, 1, 'case5', 15.00, NULL), (:g3, 3, :b, 4, 'bag12', 6.00, NULL), (:g4, 4, :gin, 4, 'bottle', 22.50, :bar), (:g5, 5, :l, 1, 'case', 25.00, NULL)",
               ['g' => $gr, 'g2' => $gr, 'g3' => $gr, 'g4' => $gr, 'g5' => $gr, 'c' => $id('SMK5-CHK'), 't' => $id('SMK5-TOM'), 'b' => $id('SMK5-BUN'), 'gin' => $id('SMK5-GIN'), 'l' => $id('SMK5-LIME'), 'bar' => $bar]);
        ri_sql('SELECT ri_post_receipt(:g, 1)', ['g' => $gr]);
        $recipe = static function (string $code, string $name, string $kind, array $lines): int {
            ri_sql("INSERT INTO recipes (code, name, kind, created_by) VALUES (:c, :n, :k, 1)", ['c' => $code, 'n' => $name, 'k' => $kind]);
            $r = (int) one('SELECT id FROM recipes WHERE code = :c', ['c' => $code]);
            ri_sql('INSERT INTO recipe_versions (recipe_id, batch_yield_qty, created_by) VALUES (:r, 1, 1)', ['r' => $r]);
            $v = (int) one('SELECT max(id) FROM recipe_versions WHERE recipe_id = :r', ['r' => $r]);
            foreach ($lines as $n => [$ing, $qty, $unit, $yield]) {
                ri_sql('INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, qty_base, line_yield_pct) VALUES (:v, :s, :i, :q, :u, 0, :y)', ['v' => $v, 's' => $n + 1, 'i' => $ing, 'q' => $qty, 'u' => $unit, 'y' => $yield]);
            }
            ri_sql("SELECT ri_recipe_version_activate(:v, 1, '2026-09-01')", ['v' => $v]);
            return $r;
        };
        $recipe('R-SMK5-SANDWICH', 'SMOKE5 Chicken sandwich', 'menu_item', [[$id('SMK5-CHK'), 170, 'g', 90], [$id('SMK5-TOM'), 30, 'g', 100], [$id('SMK5-BUN'), 1, 'each', 100]]);
        $recipe('R-SMK5-GT', 'SMOKE5 Gin and tonic', 'menu_item', [[$id('SMK5-GIN'), 1.5, 'floz', 100], [$id('SMK5-LIME'), 0.125, 'each', 100]]);
        $recipe('M-SMK5-XCHK', 'SMOKE5 Extra chicken', 'modifier', [[$id('SMK5-CHK'), 85, 'g', 100]]);
        $recipe('M-SMK5-NOTOM', 'SMOKE5 No tomato', 'modifier', [[$id('SMK5-TOM'), 30, 'g', 100]]);
        $recipe('R-SMK5-SPECIAL', 'SMOKE5 Special', 'menu_item', [[$id('SMK5-CHK'), 100, 'g', 100]]);
        pdo()->commit();
    }
    $w = ['chicken' => $id('SMK5-CHK'), 'tomato' => $id('SMK5-TOM'), 'bun' => $id('SMK5-BUN'), 'gin' => $id('SMK5-GIN'), 'lime' => $id('SMK5-LIME'),
          'walkin' => (int) one("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE5 Walk-in'"), 'bar' => (int) one("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE5 Bar'")];
    foreach (['sandwich' => 'R-SMK5-SANDWICH', 'gt' => 'R-SMK5-GT', 'xchk' => 'M-SMK5-XCHK', 'notom' => 'M-SMK5-NOTOM', 'special' => 'R-SMK5-SPECIAL'] as $k => $code) { $w['r_' . $k] = (int) one('SELECT id FROM recipes WHERE code = :c', ['c' => $code]); }
    return $w;
}

/** Map a source's PLUs 1001 (the sandwich) and 3001 (the gin and tonic) and its modifiers XCHK (add) and NOTOM (remove) — slice 2's map, by SQL. */
function map_source(int $sourceId): void
{
    $w = pos_world();
    as_member(30);
    foreach (['1001' => ['SMOKE Chicken Sandwich', 14.00, $w['r_sandwich']], '3001' => ['SMOKE Gin and Tonic', 11.00, $w['r_gt']]] as $plu => [$name, $price, $recipe]) {
        ri_sql("INSERT INTO menu_items (pos_source_id, plu, name, sale_price, recipe_id, mapped_by) VALUES (:s, :p, :n, :pr, :r, 30)
                ON CONFLICT (COALESCE(pos_source_id, 0), plu) DO UPDATE SET recipe_id = EXCLUDED.recipe_id", ['s' => $sourceId, 'p' => $plu, 'n' => $name, 'pr' => $price, 'r' => $recipe]);
    }
    foreach (['XCHK' => ['Extra chicken', 'add', $w['r_xchk']], 'NOTOM' => ['No tomato', 'remove', $w['r_notom']]] as $code => [$name, $action, $recipe]) {
        ri_sql("INSERT INTO modifiers (pos_source_id, code, name, action, recipe_id) VALUES (:s, :c, :n, :a, :r)
                ON CONFLICT (COALESCE(pos_source_id, 0), code) DO UPDATE SET action = EXCLUDED.action, recipe_id = EXCLUDED.recipe_id", ['s' => $sourceId, 'c' => $code, 'n' => $name, 'a' => $action, 'r' => $recipe]);
    }
}

function source_id(string $name): int { return (int) one('SELECT id FROM sources WHERE name = :n', ['n' => $name]); }
/** The live run of a day at a site. */
function live_run(string $date, int $scope = DOWNTOWN): ?array { return q("SELECT * FROM depletion_runs WHERE scope_id = :s AND business_date = :d AND status <> 'void' ORDER BY id DESC LIMIT 1", ['s' => $scope, 'd' => $date])[0] ?? null; }
/** What a run posted per ingredient (signed). */
function run_qty(int $run, int $ingredient): float { return (float) one("SELECT COALESCE(sum(qty_base), 0) FROM inventory_transactions WHERE reference_kind = 'depletion_run' AND reference_id = :r AND ingredient_id = :i", ['r' => $run, 'i' => $ingredient]); }
function on_hand(int $ingredient): float { return (float) one('SELECT ri_on_hand(:i, 101)', ['i' => $ingredient]); }
function is_due(string $date, int $scope = DOWNTOWN): bool { return (bool) one('SELECT EXISTS (SELECT 1 FROM ri_depletion_due() WHERE scope_id = :s AND business_date = :d::date)', ['s' => $scope, 'd' => $date]); }
