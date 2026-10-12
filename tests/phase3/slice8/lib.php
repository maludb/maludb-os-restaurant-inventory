<?php
/**
 * Helpers for slice 8's proofs (docs/build-specs/projection.md "Proof" and projection-and-ordering.md §7), run through tests/phase3/slice8/run.sh: the scratch database
 * ri_dev_s8, the application on :9337, the fake kernel :9338 (its K7 `read` answers covers_by_service from state.read), the fake MaluDB + MaluMail :9339 (the Apache internal
 * vhost :9340). The request helpers are slice 3's. projection_world() makes the world the proofs project (all dated from the DATABASE's current_date, which is what db/014's
 * functions count from):
 *   - the ledger world (slice 3) plus short rib, honey, a braise, an omelette, a lime soda and a daily special; Sysco's two schedules (orders Saturday 13:00 for Tuesday, Wednesday
 *     13:00 for Friday), Produce Co's, a splitter supplier with no schedule that sells honey by the split case;
 *   - 56 days of POS history through the real path (a sale a day, ri_depletion_run) and 56 more of sales_daily rows: the sandwich 20 a day and 40 on Fridays and Saturdays, the
 *     omelette 12 (18 on Sundays), the soda 25, the daily special 8 for the last three weeks only (thin history); every check seats 200 covers;
 *   - on hand at Downtown (all in the Walk-in): flour 161.025 kg (fixed par 250 kg), eggs 140 (dynamic), chicken 300 kg, limes 60 (shelf life 5 days, fixed par 5 000), oil 1 case
 *     (par 5.1 cases), gin none (par 1.2 cases, minimum 3), rib none (par 20 kg), honey 1 300 g at the splitter (par 5 kg);
 *   - the people: 1 the owner, 27 Marco (Manager at Downtown, Kitchen at Airport), 28 Bea (Kitchen), 29 Ann (Viewer), 30 Dana (Chef), 40 the expert (an agent Chef), 42 the Purchasing
 *     agent (Manager; its job title `buyer`, so the mirror finds it).
 */
require_once dirname(__DIR__) . '/slice3/lib.php';
require_once dirname(__DIR__) . '/slice6/lib.php';

function jget(int $member, string $path, int $site = DOWNTOWN): array { $t = person_token($member); $r = req('GET', $path, ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $t, 'X-Action-Relay: ' . relay_of($t), 'X-Site: ' . $site]]); $r['json'] = json_decode($r['body'], true) ?: []; return $r; }
function jpost(int $member, string $path, array $form, int $site = DOWNTOWN): array { $t = person_token($member); $r = req('POST', $path, ['form' => $form, 'headers' => ['Accept: application/json', 'X-Action-Token: ' . $t, 'X-Action-Relay: ' . relay_of($t), 'X-Site: ' . $site]]); $r['json'] = json_decode($r['body'], true) ?: []; return $r; }
/** A POST under an agent's RUN token (the fake kernel vouches for the run). */
function apost(int $member, int $run, string $path, array $form, int $site = DOWNTOWN): array { agent_facts($member, $run, $site); $r = agent_req('POST', $path, $member, $run, $form, $site); return $r; }
function says_text(array $r): string { return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($r['body']), ENT_QUOTES | ENT_HTML5))); }
function today_db(): string { return (string) one('SELECT current_date'); }
function day(int $offset): string { return (string) one("SELECT (current_date + CAST(:n AS integer))::date", ['n' => $offset]); }
/** The worker, as cron runs it. $env: RI_WORKER_NOW etc. The decoded one-line report. */
function worker(string $passes, array $env = []): array
{
    $pre = '';
    foreach ($env as $k => $v) { $pre .= $k . '=' . escapeshellarg($v) . ' '; }
    $out = (string) shell_exec($pre . 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=' . escapeshellarg($passes) . ' 2>/dev/null');
    $lines = array_values(array_filter(explode("\n", trim($out))));
    return json_decode((string) end($lines), true) ?: ['raw' => $out];
}
/** Site-local ISO moment for the worker's fake clock: today in the DB's calendar at $hm in Downtown's zone. */
function site_clock(string $hm, int $dayOffset = 0): string
{
    $tz = new DateTimeZone((string) one('SELECT timezone FROM sites WHERE scope_id = 101'));
    return (new DateTimeImmutable(day($dayOffset) . ' ' . $hm, $tz))->format(DATE_ATOM);
}
function nid(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM notifications'); }
function notes(int $member, string $kind, int $since = 0): array { return q('SELECT * FROM notifications WHERE member_id = :m AND kind = :k AND id > :s ORDER BY id', ['m' => $member, 'k' => $kind, 's' => $since]); }

/** Set an ingredient's on hand at Downtown to a target base quantity with one adjustment row dated now. */
function set_on_hand(int $ingredient, int $area, float $target): void
{
    $delta = $target - (float) one('SELECT ri_on_hand(:i, 101)', ['i' => $ingredient]);
    if (abs($delta) < 0.00005) { return; }
    static $n = 0;
    $n++;
    ri_sql("SELECT ri_post_txn('adjustment', 101, :i, :a, :q, ri_cost_as_of(:i2, 101), 'adjustment', 0, :k, 'none', NULL, NULL, NULL, 1, NULL, now(), current_date)", ['i' => $ingredient, 'a' => $area, 'q' => $delta, 'i2' => $ingredient, 'k' => 'p8set:' . $n . ':' . $ingredient . ':' . microtime(true)]);
}

/** The world of the projection proofs. Idempotent per database. Returns ledger_world()'s ids plus menu items, supplier items and recipes. */
function projection_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $w = ledger_world();
    as_member(1);
    if (!one("SELECT id FROM ingredients WHERE code = 'L-RIB'")) {
        // the recipes were in force from 20–30 days ago; the history reaches back further (an active version is immutable — the scratch database's owner moves the date)
        shell_exec('sudo -n -u postgres psql -q -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg("SET session_replication_role = replica; UPDATE recipe_versions SET effective_from = current_date - 140") . ' 2>&1');
        $cat = static fn (string $k): int => id_of('SELECT id FROM ingredient_categories WHERE key = :k', ['k' => $k]);
        $ins = static function (string $code, string $name, string $catKey, string $class, string $base, string $recipe, array $extra = []) use ($cat): int {
            $cols = array_merge(['code', 'name', 'category_id', 'measure_class', 'base_unit_code', 'recipe_unit_code'], array_keys($extra));
            $st = pdo()->prepare('INSERT INTO ingredients (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ') RETURNING id');
            $st->execute(array_merge([$code, $name, $cat($catKey), $class, $base, $recipe], array_values($extra)));
            return (int) $st->fetchColumn();
        };
        $rib = $ins('L-RIB', 'SMOKE Short rib', 'protein', 'weight', 'g', 'g', ['shelf_life_days' => 5]);
        $honey = $ins('L-HONEY', 'SMOKE Honey', 'dry', 'weight', 'g', 'g', ['shelf_life_days' => 365]);
        ri_sql("UPDATE ingredients SET shelf_life_days = 5 WHERE code = 'L-LIME'");
        ri_sql("INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count) VALUES (:r, 'case', 'case of 10 lb', 4535.92, 'purchase', false), (:h, 'tub', '2 kg tub', 2000, 'purchase', true)", ['r' => $rib, 'h' => $honey]);
        ri_sql("INSERT INTO suppliers (name, kind, order_method, lead_time_days, allow_split_case, min_order_value) VALUES ('SMOKE Splitter', 'wholesaler', 'phone', 1, true, NULL)");
        $split = id_of("SELECT id FROM suppliers WHERE name = 'SMOKE Splitter'");
        ri_sql("UPDATE suppliers SET min_order_value = 500 WHERE id = :s", ['s' => $w['sysco']]);
        ri_sql("INSERT INTO supplier_items (supplier_id, ingredient_id, supplier_sku, purchase_unit_code, pack, last_price, moq) VALUES
            (:sy, :oil, 'SY-OIL', 'case4', '4 gal', 40.00, 1), (:sy2, :gin, 'SY-GIN', 'case6', '6 x 750 ml', 108.00, 3), (:sy3, :rib, 'SY-RIB', 'case', '10 lb', 60.00, 1), (:sp, :honey, 'SP-HONEY', 'tub', '2 kg', 18.00, 1)",
            ['sy' => $w['sysco'], 'oil' => $w['oil'], 'sy2' => $w['sysco'], 'gin' => $w['gin'], 'sy3' => $w['sysco'], 'rib' => $rib, 'sp' => $split, 'honey' => $honey]);
        $w += ['rib' => $rib, 'honey' => $honey, 'splitter' => $split];
        // schedules: Sysco orders Saturday 13:00 for Tuesday and Wednesday 13:00 for Friday; Produce Co orders Monday and Thursday 14:00 for the next day
        ri_sql("INSERT INTO supplier_schedules (supplier_id, scope_id, order_dow, cutoff_time, delivery_dow) VALUES (:s, 101, 6, '13:00', 2), (:s2, 101, 3, '13:00', 5), (:p, 101, 1, '14:00', 2), (:p2, 101, 4, '14:00', 5)",
            ['s' => $w['sysco'], 's2' => $w['sysco'], 'p' => $w['produce'], 'p2' => $w['produce']]);
        // the recipes: the omelette (2 eggs), the lime soda (1 lime), the braise (250 g rib and 20 g flour a portion)
        pdo()->exec("SELECT set_config('app.member_id', '30', false)");
        $recipe = static function (string $code, string $name, array $lines) use ($w): int {
            ri_sql("INSERT INTO recipes (code, name, kind, category_id, created_by) VALUES (:c, :n, 'menu_item', (SELECT id FROM menu_categories WHERE key = 'mains'), 30)", ['c' => $code, 'n' => $name]);
            $r = id_of('SELECT id FROM recipes WHERE code = :c', ['c' => $code]);
            ri_sql('INSERT INTO recipe_versions (recipe_id, batch_yield_qty, created_by) VALUES (:r, 1, 30)', ['r' => $r]);
            $v = id_of('SELECT max(id) FROM recipe_versions WHERE recipe_id = :r', ['r' => $r]);
            $seq = 0;
            foreach ($lines as [$ing, $qty, $unit]) { $seq++; ri_sql('INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, qty_base) VALUES (:v, :s, :i, :q, :u, 0)', ['v' => $v, 's' => $seq, 'i' => $ing, 'q' => $qty, 'u' => $unit]); }
            ri_sql('SELECT ri_recipe_version_activate(:v, 30, current_date - 140)', ['v' => $v]);
            return $r;
        };
        $r_omelette = $recipe('L-R-OMEL', 'SMOKE Omelette', [[$w['egg'], 2, 'each'], [$w['oil'], 10, 'ml']]);
        $r_soda = $recipe('L-R-SODA', 'SMOKE Lime soda', [[$w['lime'], 1, 'each']]);
        $r_braise = $recipe('L-R-BRAISE', 'SMOKE Short rib braise', [[$rib, 250, 'g'], [$w['flour'], 20, 'g']]);
        as_member(1);
        ri_sql("INSERT INTO sources (scope_id, name, connector) VALUES (101, 'SMOKE8 POS', 'manual')");
        $src = id_of("SELECT id FROM sources WHERE scope_id = 101 AND name = 'SMOKE8 POS'");
        foreach ([['8001', 'SMOKE8 Chicken sandwich', $w['r_sand']], ['8002', 'SMOKE8 Omelette', $r_omelette], ['8003', 'SMOKE8 Lime soda', $r_soda], ['8004', 'SMOKE8 Daily special', $r_soda]] as [$plu, $name, $rid]) {
            ri_sql('INSERT INTO menu_items (pos_source_id, plu, name, sale_price, recipe_id) VALUES (:s, :p, :n, 12.00, :r)', ['s' => $src, 'p' => $plu, 'n' => $name, 'r' => $rid]);
        }
        $mi = fn (string $plu): int => id_of('SELECT id FROM menu_items WHERE pos_source_id = :s AND plu = :p', ['s' => $src, 'p' => $plu]);
        $w += ['r_omelette' => $r_omelette, 'r_soda' => $r_soda, 'r_braise' => $r_braise, 'src' => $src, 'mi_sand' => $mi('8001'), 'mi_omel' => $mi('8002'), 'mi_soda' => $mi('8003'), 'mi_special' => $mi('8004')];
        // 56 more days of sales_daily alone (the older history), then 56 days through the POS path: a sale, its lines, the depletion run
        ri_sql("INSERT INTO sales_daily (scope_id, menu_item_id, business_date, day_part_id, qty, net)
                SELECT 101, m.id, current_date - k, NULL, CASE m.plu WHEN '8001' THEN CASE WHEN extract(isodow FROM current_date - k) IN (5, 6) THEN 40 ELSE 20 END
                                                                     WHEN '8002' THEN CASE WHEN extract(isodow FROM current_date - k) = 7 THEN 18 ELSE 12 END ELSE 25 END, 0
                  FROM menu_items m CROSS JOIN generate_series(57, 112) k WHERE m.pos_source_id = :s AND m.plu IN ('8001', '8002', '8003')", ['s' => $src]);
        for ($k = 56; $k >= 1; $k--) {
            $d = day(-$k);
            $iso = (int) one('SELECT extract(isodow FROM CAST(:d AS date))', ['d' => $d]);
            ri_sql("INSERT INTO pos_sales (scope_id, source_id, external_id, business_date, closed_at, covers, subtotal, total) VALUES (101, :s, :e, :d::date, ri_site_moment(101, :d2::date, false) + interval '9 hours', 200, 1000, 1000)",
                ['s' => $src, 'e' => 'p8-' . $d, 'd' => $d, 'd2' => $d]);
            $sale = last_id('pos_sales');
            $lines = [['8001', in_array($iso, [5, 6], true) ? 40 : 20], ['8002', $iso === 7 ? 18 : 12], ['8003', 25]];
            if ($k <= 21) { $lines[] = ['8004', 8]; }
            foreach ($lines as [$plu, $q]) { ri_sql('INSERT INTO pos_sale_lines (sale_id, plu, qty, unit_price, line_total) VALUES (:s, :p, :q, 12.00, :t)', ['s' => $sale, 'p' => $plu, 'q' => $q, 't' => $q * 12]); }
            ri_sql('SELECT ri_depletion_run(101, :d::date, 1)', ['d' => $d]);
        }
        // stock: received through the door, then set to the targets
        $dana = person(30);
        $wa = $w['walkin'];
        stock_in($dana, $wa, [['ingredient' => 'L-EGG', 'qty' => 5000, 'unit' => 'each', 'unit_cost' => 0.30], ['ingredient' => 'L-FLOUR', 'qty' => 161.025, 'unit' => 'kg', 'unit_cost' => 1.10],
            ['ingredient' => 'L-CHK', 'qty' => 1, 'unit' => 'case', 'weight_qty' => 700, 'weight_unit' => 'lb', 'unit_cost' => 3.10], ['ingredient' => 'L-LIME', 'qty' => 1, 'unit' => 'case', 'unit_cost' => 38],
            ['ingredient' => 'L-OIL', 'qty' => 1, 'unit' => 'case4', 'unit_cost' => 40]], $w['sysco']);
        stock_in($dana, $wa, [['ingredient' => 'L-HONEY', 'qty' => 1300, 'unit' => 'g', 'unit_cost' => 0.009]], $split);
        foreach (['egg' => 140.0, 'chicken' => 300000.0, 'lime' => 60.0, 'flour' => 161025.0, 'oil' => 15141.65, 'honey' => 1300.0, 'stock' => 20000.0] as $k => $target) { set_on_hand($w[$k], $wa, $target); }
        // settings: pars and modes, the Manager, the Purchasing agent's key
        foreach ([[$w['flour'], 250000, 'fixed'], [$w['egg'], null, 'dynamic'], [$w['chicken'], 150000, 'fixed'], [$w['lime'], 5000, 'fixed'], [$w['oil'], 77222.415, 'fixed'], [$w['gin'], 5400, 'fixed'], [$rib, 20000, 'fixed'], [$honey, 5000, 'fixed']] as [$i, $par, $mode]) {
            ri_sql("INSERT INTO ingredient_site_settings (ingredient_id, scope_id, par_base, par_mode) VALUES (:i, 101, :p, :m) ON CONFLICT (ingredient_id, scope_id) DO UPDATE SET par_base = EXCLUDED.par_base, par_mode = EXCLUDED.par_mode", ['i' => $i, 'p' => $par, 'm' => $mode]);
        }
        ri_sql('UPDATE site_settings SET manager_member_id = 27 WHERE scope_id = 101');
        ri_sql("UPDATE members SET job_title = 'buyer' WHERE id = 42");
        ri_sql("INSERT INTO order_guides (supplier_id, scope_id, name) VALUES (:s, 101, 'Order guide')", ['s' => $w['sysco']]);
        $guide = id_of('SELECT max(id) FROM order_guides');
        foreach ([$w['si_flour'], $w['si_egg'], $w['si_chicken'], id_of("SELECT id FROM supplier_items WHERE supplier_sku = 'SY-OIL'"), id_of("SELECT id FROM supplier_items WHERE supplier_sku = 'SY-GIN'"), id_of("SELECT id FROM supplier_items WHERE supplier_sku = 'SY-RIB'")] as $n => $si) {
            ri_sql('INSERT INTO order_guide_lines (guide_id, supplier_item_id, seq) VALUES (:g, :s, :n)', ['g' => $guide, 's' => $si, 'n' => $n + 1]);
        }
    }
    foreach (['rib' => 'L-RIB', 'honey' => 'L-HONEY'] as $k => $code) { $w[$k] ??= id_of('SELECT id FROM ingredients WHERE code = :c', ['c' => $code]); }
    $w['splitter'] ??= id_of("SELECT id FROM suppliers WHERE name = 'SMOKE Splitter'");
    foreach (['r_omelette' => 'L-R-OMEL', 'r_soda' => 'L-R-SODA', 'r_braise' => 'L-R-BRAISE'] as $k => $code) { $w[$k] ??= id_of('SELECT id FROM recipes WHERE code = :c', ['c' => $code]); }
    $src = $w['src'] ??= id_of("SELECT id FROM sources WHERE scope_id = 101 AND name = 'SMOKE8 POS'");
    foreach (['mi_sand' => '8001', 'mi_omel' => '8002', 'mi_soda' => '8003', 'mi_special' => '8004'] as $k => $plu) { $w[$k] ??= id_of('SELECT id FROM menu_items WHERE pos_source_id = :s AND plu = :p', ['s' => $src, 'p' => $plu]); }
    foreach (['si_oil' => 'SY-OIL', 'si_gin' => 'SY-GIN', 'si_rib' => 'SY-RIB', 'si_honey' => 'SP-HONEY'] as $k => $sku) { $w[$k] = id_of('SELECT id FROM supplier_items WHERE supplier_sku = :s', ['s' => $sku]); }
    return $w;
}

/** ri_suggested_order() rows of a supplier at Downtown, keyed by ingredient (as the writer, no member — nothing walled). */
function suggestion(int $supplier): array
{
    pdo()->exec("SELECT set_config('app.member_id', '', false)");
    $out = [];
    foreach (q('SELECT * FROM ri_suggested_order(101, :s, now())', ['s' => $supplier]) as $r) { $out[(int) $r['ingredient_id']] = $r; }
    return $out;
}

/** Reset the projection's state between proofs: the runs, overrides, closures, covers, proposals, watches, drafted POs of origin suggested/agent. */
function reset_projection(): void
{
    ri_sql('DELETE FROM item_forecasts');
    ri_sql('DELETE FROM forecast_runs');
    ri_sql('DELETE FROM forecast_overrides');
    ri_sql('DELETE FROM calendar_days');
    ri_sql('DELETE FROM covers_expected');
    ri_sql('DELETE FROM forecast_accuracy');
    ri_sql('DELETE FROM buyer_proposals');
    ri_sql("DELETE FROM activity_log WHERE action = 'buyer.note'");
    ri_sql('DELETE FROM watch_events');
    ri_sql('DELETE FROM agent_dispatches');
    ri_sql('DELETE FROM watches');
    ri_sql("DELETE FROM notification_outbox WHERE dedupe_key LIKE 'stockout:%' OR dedupe_key LIKE 'agent_drafted:%' OR dedupe_key LIKE 'watch:%' OR dedupe_key LIKE 'override:%'");
    ri_sql("DELETE FROM purchase_order_lines WHERE purchase_order_id IN (SELECT id FROM purchase_orders WHERE origin IN ('suggested', 'agent') AND status = 'draft')");
    ri_sql("DELETE FROM purchase_orders WHERE origin IN ('suggested', 'agent') AND status = 'draft'");
    kernel_state(function ($s) { unset($s['read']); return $s; });
}
function row_count(string $table): int { return (int) one("SELECT count(*) FROM $table"); }
/** Draft and place a PO as a person through the doors (the supplier's reference 'P8-<n>'); returns its id. $lines: [{supplier_item, qty, unit}]; $expected: a date. */
function draft_and_place(string $jar, int $supplier, array $lines, ?string $expected = null): int
{
    static $n = 0;
    $n++;
    post($jar, '/purchasing/save.php', ['supplier' => $supplier, 'lines' => json_encode($lines)] + ($expected ? ['expected_on' => $expected] : []));
    $po = last_id('purchase_orders');
    post($jar, '/purchasing/place.php', ['purchase_order' => $po, 'via' => 'phone', 'supplier_order_ref' => 'P8-' . $n]);
    return $po;
}
/** A waste row dated $daysAgo days back, by the schema's own verb. */
function post_waste(int $ingredient, int $area, float $qty, int $daysAgo): void
{
    static $n = 0;
    $n++;
    ri_sql("SELECT ri_post_txn('waste', 101, :i, :a, :q, ri_cost_as_of(:i2, 101), 'waste', 0, :k, 'none', NULL, NULL, NULL, 1, NULL, now() - CAST(:d AS integer) * interval '1 day', current_date - CAST(:d2 AS integer))", ['i' => $ingredient, 'a' => $area, 'q' => -$qty, 'i2' => $ingredient, 'k' => 'p8waste:' . $n . ':' . microtime(true), 'd' => $daysAgo, 'd2' => $daysAgo]);
}
function po_row(int $id): array { return q('SELECT * FROM purchase_orders WHERE id = :i', ['i' => $id])[0] ?? []; }
function po_lines_of(int $id): array { return q('SELECT * FROM purchase_order_lines WHERE purchase_order_id = :i ORDER BY line_no', ['i' => $id]); }
function reset_projection_proposals_only(): void { ri_sql('DELETE FROM buyer_proposals'); ri_sql("DELETE FROM activity_log WHERE action = 'buyer.note'"); }
/** acceptance_rate() as the application computes it (30 days), without loading the application's code into the proof. */
function acceptance_probe(): array
{
    $r = q("SELECT count(*) FILTER (WHERE status IN ('accepted', 'dismissed')) AS decided, count(*) FILTER (WHERE status = 'accepted') AS accepted FROM buyer_proposals WHERE scope_id = 101 AND note_date >= current_date - 30")[0];
    return ['decided' => (int) $r['decided'], 'accepted' => (int) $r['accepted'], 'rate' => (int) $r['decided'] === 0 ? null : round(100 * $r['accepted'] / $r['decided'], 1)];
}
function site_day_str(): string { return (string) one("SELECT (now() AT TIME ZONE timezone)::date FROM sites WHERE scope_id = 101"); }
