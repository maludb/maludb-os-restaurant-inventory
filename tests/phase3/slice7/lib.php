<?php
/**
 * Helpers for slice 7's proofs (docs/build-specs/periods.md "Proof"), run through tests/phase3/slice7/run.sh: the scratch database ri_dev_s7, the application
 * on :9333, the fake kernel :9334, the fake MaluDB + MaluMail :9335 (the Apache internal vhost :9336). The request helpers are slice 3's; the count doors' are
 * slice 6's. period_world() makes, through the real doors where a door can be dated and by the schema's own verbs where it cannot, the world the proofs
 * read — a weekly calendar whose first week began five weeks before this Monday (so weeks 1–5 are past and week 6 is the one holding today), and in them:
 *   receipts (Sysco, back-dated through the receipts door), POS days depleted by ri_depletion_run() — except ONE day of week 3, left undepleted on purpose —,
 *   and in week 4 every bucket of the usage identity for eggs: purchases 300, transfers in 12 and out 30, adjustments −5, waste 31, a comped sandwich (1),
 *   a prep batch (chicken out, stock in), and an approved count on the last day of the week that finds 41 eggs more than the book.
 * The people: 1 the owner (admin), 27 Marco (Manager at Downtown, Kitchen at Airport), 28 Bea (Kitchen), 29 Ann (Viewer), 30 Dana (Chef), 40 the expert (an
 * agent Chef at both sites), 42 the Purchasing agent (Manager).
 */
require_once dirname(__DIR__) . '/slice3/lib.php';
require_once dirname(__DIR__) . '/slice6/lib.php';

/** Monday of this week in Downtown's zone. */
function mon(): DateTimeImmutable
{
    static $m = null;
    return $m ??= (new DateTimeImmutable('today', new DateTimeZone((string) one('SELECT timezone FROM sites WHERE scope_id = 101'))))->modify('monday this week');
}
/** The date of day $d (0 = the week's Monday) of proof week $w (1..6; week 6 holds today). */
function wk(int $w, int $d = 0): string { return mon()->modify(sprintf('%+d days', ($w - 6) * 7 + $d))->format('Y-m-d'); }
function fiscal_year(): int { return (int) mon()->modify('-35 days')->format('Y'); }

/** A receipt dated $date through the receipts door (draft, then post); returns its id. */
function receive_on(string $jar, int $area, array $lines, string $date, ?int $supplier = null): int
{
    post($jar, '/receipts/save.php', ['area' => $area, 'received_on' => $date, 'lines' => json_encode($lines)] + ($supplier ? ['supplier' => $supplier] : []));
    $g = last_id('goods_receipts');
    post($jar, '/receipts/post.php', ['receipt' => $g]);
    return $g;
}

/** A ledger row the doors cannot date: the schema's own ri_post_txn(), back-dated to midday of $date at the site. */
function post_on(string $type, int $ingredient, int $area, float $qty, string $date, string $refKind, ?float $cost = null, ?int $cpSite = null): void
{
    static $n = 0;
    $n++;
    ri_sql("SELECT ri_post_txn(:t, 101, :i, :a, :q, COALESCE(CAST(:c AS numeric), ri_cost_as_of(:i2, 101)), :rk, 0, :k, :cpk, :cpid, NULL, NULL, 1, NULL, ri_site_moment(101, :d::date, false) + interval '10 hours', :d2::date)",
        ['t' => $type, 'i' => $ingredient, 'a' => $area, 'q' => $qty, 'c' => $cost, 'i2' => $ingredient, 'rk' => $refKind, 'k' => 'p7:' . $n . ':' . $date . ':' . $type . ':' . $ingredient, 'cpk' => $cpSite ? 'site' : 'none', 'cpid' => $cpSite, 'd' => $date, 'd2' => $date]);
}

/** A POS day at Downtown: $qty sandwiches (and $comp comped ones) on the source, at $14 — by SQL (a POS file is slice 5's); depleted unless $deplete is false. */
function pos_day(string $date, int $qty, int $comp, bool $deplete = true): void
{
    static $src = null, $item = null, $n = 0;
    if ($src === null) {
        ri_sql("INSERT INTO sources (scope_id, name, connector) VALUES (101, 'SMOKE7 POS', 'manual') ON CONFLICT DO NOTHING");
        $src = id_of("SELECT id FROM sources WHERE scope_id = 101 AND name = 'SMOKE7 POS'");
        $w = ledger_world();
        ri_sql("INSERT INTO menu_items (pos_source_id, plu, name, sale_price, recipe_id) VALUES (:s, '7001', 'SMOKE7 Chicken sandwich', 14.00, :r) ON CONFLICT DO NOTHING", ['s' => $src, 'r' => $w['r_sand']]);
        $item = id_of("SELECT id FROM menu_items WHERE pos_source_id = :s AND plu = '7001'", ['s' => $src]);
    }
    $n++;
    ri_sql("INSERT INTO pos_sales (scope_id, source_id, external_id, business_date, closed_at, subtotal, total) VALUES (101, :s, :e, :d::date, ri_site_moment(101, :d2::date, false) + interval '9 hours', :t, :t2)",
        ['s' => $src, 'e' => 'p7-' . $n . '-' . $date, 'd' => $date, 'd2' => $date, 't' => $qty * 14, 't2' => $qty * 14]);
    $sale = last_id('pos_sales');
    ri_sql("INSERT INTO pos_sale_lines (sale_id, plu, qty, unit_price, line_total) VALUES (:s, '7001', :q, 14.00, :t)", ['s' => $sale, 'q' => $qty, 't' => $qty * 14]);
    if ($comp > 0) { ri_sql("INSERT INTO pos_sale_lines (sale_id, plu, qty, unit_price, line_total, comp) VALUES (:s, '7001', :q, 14.00, 0, true)", ['s' => $sale, 'q' => $comp]); }
    if ($deplete) { ri_sql('SELECT ri_depletion_run(101, :d::date, 1)', ['d' => $date]); }
}

/** The world of the periods proofs. Idempotent per database. Returns ledger_world()'s ids plus the days that matter. */
function period_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $w = ledger_world();
    as_member(1);
    if (!one("SELECT id FROM costing_calendars WHERE name = 'Weeks'")) {
        ri_sql("INSERT INTO costing_calendars (name, kind, year_start_on, week_starts, is_default) VALUES ('Weeks', 'week', :d::date, 1, true)", ['d' => wk(1)]);
    }
    $cal = id_of("SELECT id FROM costing_calendars WHERE name = 'Weeks'");
    if (!one('SELECT 1 FROM periods WHERE scope_id = 101 LIMIT 1')) {
        ri_sql('SELECT ri_periods_ensure(101, :y, :c)', ['y' => fiscal_year(), 'c' => $cal]);
        ri_sql('UPDATE site_settings SET manager_member_id = 27 WHERE scope_id = 101');
        $dana = person(30);
        $wa = $w['walkin'];
        receive_on($dana, $wa, [['ingredient' => 'L-EGG', 'qty' => 900, 'unit' => 'each', 'unit_cost' => 0.30], ['ingredient' => 'L-FLOUR', 'qty' => 4, 'unit' => 'bag50', 'unit_cost' => 25],
            ['ingredient' => 'L-CHK', 'qty' => 1, 'unit' => 'case', 'weight_qty' => 100, 'weight_unit' => 'lb', 'unit_cost' => 3.10], ['ingredient' => 'L-LIME', 'qty' => 1, 'unit' => 'case', 'unit_cost' => 38],
            ['ingredient' => 'L-GIN', 'qty' => 1, 'unit' => 'case6', 'unit_cost' => 108]], wk(1, 1), $w['sysco']);
        receive_on($dana, $wa, [['ingredient' => 'L-EGG', 'qty' => 600, 'unit' => 'each', 'unit_cost' => 0.30], ['ingredient' => 'L-CHK', 'qty' => 1, 'unit' => 'case', 'weight_qty' => 80, 'weight_unit' => 'lb', 'unit_cost' => 3.10]], wk(2, 0), $w['sysco']);
        receive_on($dana, $wa, [['ingredient' => 'L-EGG', 'qty' => 600, 'unit' => 'each', 'unit_cost' => 0.30], ['ingredient' => 'L-FLOUR', 'qty' => 2, 'unit' => 'bag50', 'unit_cost' => 25]], wk(3, 0), $w['sysco']);
        receive_on($dana, $wa, [['ingredient' => 'L-EGG', 'qty' => 300, 'unit' => 'each', 'unit_cost' => 0.30], ['ingredient' => 'L-FLOUR', 'qty' => 2, 'unit' => 'bag50', 'unit_cost' => 26],
            ['ingredient' => 'L-CHK', 'qty' => 1, 'unit' => 'case', 'weight_qty' => 60, 'weight_unit' => 'lb', 'unit_cost' => 3.10]], wk(4, 1), $w['sysco']);
        receive_on($dana, $wa, [['ingredient' => 'L-LIME', 'qty' => 1, 'unit' => 'case', 'unit_cost' => 38]], wk(4, 2), $w['produce']);
        receive_on($dana, $wa, [['ingredient' => 'L-EGG', 'qty' => 600, 'unit' => 'each', 'unit_cost' => 0.30], ['ingredient' => 'L-CHK', 'qty' => 1, 'unit' => 'case', 'weight_qty' => 60, 'weight_unit' => 'lb', 'unit_cost' => 3.10]], wk(5, 0), $w['sysco']);
        $back = date('Y-m-d', strtotime(wk(1) . ' -30 days'));   // the recipes were in force before week 1 (an active version is immutable — the scratch database's owner moves the date)
        shell_exec('sudo -n -u postgres psql -q -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg("SET session_replication_role = replica; UPDATE recipe_versions SET effective_from = '$back' WHERE effective_from > '$back'") . ' 2>&1');
        // the POS days: week 3's second day is left undepleted — the close refuses on it until it is run
        pos_day(wk(1, 2), 40, 0); pos_day(wk(1, 3), 38, 1);
        pos_day(wk(2, 1), 30, 0); pos_day(wk(2, 2), 28, 0);
        pos_day(wk(3, 1), 25, 0); pos_day(wk(3, 3), 22, 0, false);
        pos_day(wk(4, 1), 25, 0); pos_day(wk(4, 2), 30, 1); pos_day(wk(4, 4), 20, 0);
        pos_day(wk(5, 1), 35, 0); pos_day(wk(5, 2), 30, 0);
        // week 4's other buckets, dated by the schema's own verb
        post_on('transfer_out', $w['egg'], $wa, -30, wk(4, 3), 'transfer', null, 102);
        post_on('transfer_in', $w['egg'], $wa, 12, wk(4, 5), 'transfer', 0.30, 102);
        post_on('adjustment', $w['egg'], $wa, -5, wk(4, 6), 'adjustment');
        post_on('waste', $w['egg'], $wa, -31, wk(4, 5), 'waste');
        post_on('waste', $w['chicken'], $wa, -1000, wk(4, 5), 'waste');
        post_on('production_out', $w['chicken'], $wa, -2000, wk(4, 3), 'production');
        post_on('production_in', $w['stock'], $wa, 1800, wk(4, 3), 'production', 0.0);
        // the approved count on week 4's last day: it finds 41 eggs more than the book
        $asOf = wk(5, 0) . 'T03:00';
        [$c] = start_count_as($dana, ['kind' => 'spot', 'as_of' => $asOf]);
        foreach (q('SELECT id, ingredient_id, system_qty_base FROM inventory_count_lines WHERE count_id = :c', ['c' => $c]) as $l) {
            if ((int) $l['ingredient_id'] === $w['egg']) { post($dana, '/counts/line.php', ['count_line' => $l['id'], 'entries' => json_encode([['unit' => 'each', 'qty' => (float) $l['system_qty_base'] + 41]])]); }
            else { post($dana, '/counts/line.php', ['count_line' => $l['id'], 'state' => 'skipped']); }
        }
        post($dana, '/counts/review.php', ['count' => $c]);
        post($dana, '/counts/approve.php', ['count' => $c, 'force' => 'yes']);
        ri_sql('UPDATE site_settings SET manager_member_id = 27 WHERE scope_id = 101');
    }
    $w['cal'] = $cal;
    $w['count'] = id_of("SELECT max(id) FROM inventory_counts WHERE scope_id = 101 AND status = 'approved'");
    return $w;
}

/** Period $n of the proof's calendar at Downtown. */
function period_id(int $n, int $scope = DOWNTOWN): int { return (int) one('SELECT id FROM periods WHERE scope_id = :s AND no = :n ORDER BY calendar_id LIMIT 1', ['s' => $scope, 'n' => $n]); }
function period_row(int $id): array { return q('SELECT * FROM periods WHERE id = :i', ['i' => $id])[0] ?? []; }
/** ri_usage() for a window at Downtown, keyed by ingredient — the referee the screens are checked against. */
function usage_ref(string $from, string $to, string $basis = 'avg'): array
{
    $out = [];
    foreach (q('SELECT * FROM ri_usage(101, :f::date, :t::date, :b)', ['f' => $from, 't' => $to, 'b' => $basis]) as $r) { $out[(int) $r['ingredient_id']] = $r; }
    return $out;
}
function says_text(array $r): string { return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($r['body']), ENT_QUOTES | ENT_HTML5))); }
/** A JSON door under a signed action token: [code, json]. */
function jget(int $member, string $path, int $site = DOWNTOWN): array { $t = person_token($member); $r = req('GET', $path, ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $t, 'X-Action-Relay: ' . relay_of($t), 'X-Site: ' . $site]]); $r['json'] = json_decode($r['body'], true) ?: []; return $r; }
function jpost(int $member, string $path, array $form, int $site = DOWNTOWN): array { $t = person_token($member); $r = req('POST', $path, ['form' => $form, 'headers' => ['Accept: application/json', 'X-Action-Token: ' . $t, 'X-Action-Relay: ' . relay_of($t), 'X-Site: ' . $site]]); $r['json'] = json_decode($r['body'], true) ?: []; return $r; }
