<?php
declare(strict_types=1);

/**
 * The suggested orders and the special check (projection.md "Query functions"; signatures fixed — Phase 4's tools `suggested_orders`, `get_suggested_order`,
 * `can_run_special` call these). THE DATABASE IS THE REFEREE: every line is a row of ri_suggested_order() (the periodic-review rule, step by step, with every step on the
 * row — db/014, walled for cost by db/028) and the PO a person or the agent drafts is ri_suggested_order_draft(). PHP shows the arithmetic, it never redoes it: a
 * quantity in the ingredient's count unit arrives from ri_from_base() in the same statement, the base figure beside it; a line's value is round(units × unit cost, 2)
 * in SQL. The application only ever DRAFTS (design D8/D9) — sending is slice 4's `purchase_order_send`.
 */
require_once dirname(__DIR__) . '/projection/queries.php';
require_once dirname(__DIR__) . '/recipes/queries.php';

const SUGGESTED_DUE_HOURS = [24, 36, 72, 168];
const PAR_MODE_CHIPS = ['fixed' => 'secondary', 'dynamic' => 'info'];

/**
 * A supplier's suggested order lines at a site (the rule's output, named): [{n, supplier_item_id, ingredient_id, name, code, sku, pack, purchase_unit, par_mode, thin_history,
 * demand_*, safety_*, need_*, have_*, on_hand_*, on_order_*, cap_*, raw_*, qty_units, unit_cost, line_value, card_total, reaches_min, rounding_note, count_unit, base_unit}] —
 * each quantity as `_base` and `_count`. `unit_cost`, `line_value` and `card_total` are null when the caller may not see cost.
 */
function suggested_order(PDO $pdo, int $scopeId, int $supplierId): array
{
    $conv = static fn (string $c): string => "ri_from_base(g.ingredient_id, g.{$c}_base, cu.unit) AS {$c}_count";
    $st = $pdo->prepare("SELECT g.n, g.supplier_item_id, g.ingredient_id, i.name, i.code, i.base_unit_code AS base_unit, cu.unit AS count_unit, si.supplier_sku AS sku, si.pack, g.purchase_unit_code AS purchase_unit, g.per_unit_base,
              g.par_mode, g.thin_history, g.demand_base, g.safety_base, g.need_base, g.have_base, g.on_hand_base, g.on_order_base, g.cap_base, g.raw_base, g.qty_units, g.unit_cost, g.rounding_note,
              g.cutoff_at, g.delivery_on, g.next_delivery_on, " . implode(', ', array_map($conv, ['demand', 'safety', 'need', 'have', 'on_hand', 'on_order', 'cap', 'raw'])) . ",
              round(g.qty_units * g.unit_cost, 2) AS line_value,
              sum(round(g.qty_units * g.unit_cost, 2)) OVER () AS card_total,
              CASE WHEN ms.min_order_value IS NULL THEN true WHEN g.unit_cost IS NULL THEN NULL ELSE sum(round(g.qty_units * g.unit_cost, 2)) OVER () >= ms.min_order_value END AS reaches_min
         FROM (SELECT x.*, row_number() OVER () AS n FROM ri_suggested_order(:s, :sup, now()) x) g
         JOIN mcp_ingredients i ON i.ingredient_id = g.ingredient_id
         JOIN mcp_supplier_items si ON si.supplier_item_id = g.supplier_item_id
         JOIN mcp_suppliers ms ON ms.supplier_id = :sup2
         CROSS JOIN LATERAL (SELECT COALESCE((SELECT iu.unit_code FROM mcp_ingredient_units iu WHERE iu.ingredient_id = g.ingredient_id AND iu.is_default_count AND iu.active LIMIT 1), i.base_unit_code) AS unit) cu
        ORDER BY g.n");
    $st->execute(['s' => $scopeId, 'sup' => $supplierId, 'sup2' => $supplierId]);
    return array_map(static function (array $r): array {
        $r = stock_ints($r, ['n', 'supplier_item_id', 'ingredient_id']);
        $r['thin_history'] = (bool) $r['thin_history'];
        $r['reaches_min'] = $r['reaches_min'] === null ? null : (bool) $r['reaches_min'];
        return $r;
    }, $st->fetchAll());
}

/** A supplier with a PO of origin suggested/agent made today (site time) that is not cancelled: [purchase_order_id, number, created_at, origin, status], or null. */
function suggested_po_today(PDO $pdo, int $scopeId, int $supplierId): ?array
{
    $r = proj_row($pdo, "SELECT p.id AS purchase_order_id, p.number, p.created_at, p.origin, p.status FROM purchase_orders p JOIN sites s ON s.scope_id = p.scope_id
                       WHERE p.scope_id = :s AND p.supplier_id = :sup AND p.origin IN ('suggested', 'agent') AND p.status <> 'cancelled'
                         AND (p.created_at AT TIME ZONE s.timezone)::date = (now() AT TIME ZONE s.timezone)::date ORDER BY p.id DESC LIMIT 1", ['s' => $scopeId, 'sup' => $supplierId]);
    return $r === null ? null : stock_ints($r, ['purchase_order_id']);
}

/**
 * The cards of the suggested-order screen: one per supplier due within $dueHours (or the one named — due or not), each ['supplier_id', 'name', 'min_order_value', 'cutoff_at',
 * 'delivery_on', 'next_cutoff_at', 'next_delivery_on', 'protection_days', 'scheduled', 'lines', 'to_order' (lines with units), 'total', 'reaches_min', 'drafted_today'].
 */
function suggested_orders(PDO $pdo, int $scopeId, ?int $supplierId = null, int $dueHours = 36): array
{
    $dueHours = in_array($dueHours, SUGGESTED_DUE_HOURS, true) ? $dueHours : 36;
    if ($supplierId !== null) {
        $st = $pdo->prepare('SELECT s.supplier_id, s.name, s.min_order_value, w.cutoff_at, w.delivery_on, w.next_cutoff_at, w.next_delivery_on, w.next_delivery_on - w.delivery_on AS protection_days,
                   EXISTS (SELECT 1 FROM mcp_supplier_schedules x WHERE x.supplier_id = s.supplier_id AND x.scope_id = :s AND x.active) AS scheduled
              FROM mcp_suppliers s CROSS JOIN LATERAL ri_next_order_windows(s.supplier_id, :s2, now()) w WHERE s.supplier_id = :sup');
        $st->execute(['s' => $scopeId, 's2' => $scopeId, 'sup' => $supplierId]);
    } else {
        $st = $pdo->prepare('SELECT s.supplier_id, s.name, s.min_order_value, w.cutoff_at, w.delivery_on, w.next_cutoff_at, w.next_delivery_on, w.next_delivery_on - w.delivery_on AS protection_days, true AS scheduled
              FROM ri_suppliers_due(:s, CAST(:h AS integer)) d JOIN mcp_suppliers s ON s.supplier_id = d.supplier_id CROSS JOIN LATERAL ri_next_order_windows(s.supplier_id, :s2, now()) w ORDER BY w.cutoff_at, s.name');
        $st->execute(['s' => $scopeId, 'h' => $dueHours, 's2' => $scopeId]);
    }
    $cards = [];
    foreach ($st->fetchAll() as $c) {
        $c = stock_ints($c, ['supplier_id', 'protection_days']);
        $c['scheduled'] = (bool) $c['scheduled'];
        $c['lines'] = suggested_order($pdo, $scopeId, $c['supplier_id']);
        $c['to_order'] = count(array_filter($c['lines'], static fn (array $l): bool => (float) $l['qty_units'] > 0));
        $c['total'] = $c['lines'] === [] ? null : $c['lines'][0]['card_total'];
        $c['reaches_min'] = $c['lines'] === [] ? null : $c['lines'][0]['reaches_min'];
        $c['drafted_today'] = suggested_po_today($pdo, $scopeId, $c['supplier_id']);
        $cards[] = $c;
    }
    return $cards;
}

/** The suppliers that have a schedule here (for the supplier filter of the suggested-order screen). */
function suppliers_with_schedule(PDO $pdo, int $scopeId): array
{
    $st = $pdo->prepare('SELECT DISTINCT s.supplier_id, s.name FROM mcp_suppliers s JOIN mcp_supplier_schedules x ON x.supplier_id = s.supplier_id AND x.scope_id = :s AND x.active WHERE s.active ORDER BY s.name');
    $st->execute(['s' => $scopeId]);
    return array_map(static fn (array $r): array => stock_ints($r, ['supplier_id']), $st->fetchAll());
}

// ---------------------------------------------------------------------------------------------------------------------- the special check

/**
 * "Can we run 60 portions of the braise on Friday?": the recipe exploded for the portions (the version in force that day, preps as leaves), each ingredient against the
 * projected on hand that day (ri_projected_stock), the shortfall, and the supplier that can bring it in time (ri_next_order_windows: the first delivery on or before the date).
 * Returns ['recipe', 'date', 'portions', 'needs' => [...], 'short' => [...], 'drafts' => [supplier_id => {supplier, lines[]}], 'verdict', 'problem'].
 * `portions` are the recipe's yield units: a menu item's portions, a prep's batch unit.
 */
function special_check(PDO $pdo, int $scopeId, int $recipeId, string $date, float $portions): array
{
    $recipe = find_recipe($pdo, $recipeId);
    $out = ['recipe' => $recipe, 'date' => $date, 'portions' => $portions, 'needs' => [], 'short' => [], 'drafts' => [], 'verdict' => '', 'problem' => null];
    if ($recipe === null) { $out['problem'] = 'That recipe is not here.'; return $out; }
    $version = (int) (one_value($pdo, 'SELECT ri_recipe_version_on(:r, CAST(:d AS date))', ['r' => $recipeId, 'd' => $date]) ?? 0);
    if ($version === 0) { $out['problem'] = $recipe['name'] . ' has no version in force on ' . format_date($date) . ' — activate one first.'; return $out; }
    $days = (int) (new DateTimeImmutable(proj_today($pdo)))->diff(new DateTimeImmutable($date))->format('%r%a') + 1;
    $st = $pdo->prepare("SELECT n.ingredient_id, i.name, i.code, i.base_unit_code AS base_unit, cu.unit AS count_unit, n.needed_base,
              ri_from_base(n.ingredient_id, n.needed_base, cu.unit) AS needed_count,
              ps.projected_base, ri_from_base(n.ingredient_id, ps.projected_base, cu.unit) AS projected_count,
              greatest(n.needed_base - COALESCE(ps.projected_base, 0), 0) AS short_base, ri_from_base(n.ingredient_id, greatest(n.needed_base - COALESCE(ps.projected_base, 0), 0), cu.unit) AS short_count
         FROM (SELECT e.ingredient_id, round(e.qty_base * CAST(:p AS numeric) / rv.batch_yield_qty, 4) AS needed_base
                 FROM recipe_versions rv CROSS JOIN LATERAL ri_explode(rv.id, CAST(:d AS date), false) e WHERE rv.id = :v) n
         JOIN mcp_ingredients i ON i.ingredient_id = n.ingredient_id
         CROSS JOIN LATERAL (SELECT COALESCE((SELECT iu.unit_code FROM mcp_ingredient_units iu WHERE iu.ingredient_id = n.ingredient_id AND iu.is_default_count AND iu.active LIMIT 1), i.base_unit_code) AS unit) cu
         LEFT JOIN LATERAL (SELECT p.projected_base FROM ri_projected_stock(:s, n.ingredient_id, CAST(:days AS integer)) p WHERE p.on_date = CAST(:d2 AS date)) ps ON true
        ORDER BY i.name");
    $st->execute(['p' => (string) $portions, 'd' => $date, 'd2' => $date, 'v' => $version, 's' => $scopeId, 'days' => max(1, $days)]);
    $site = site_timezone($scopeId);
    foreach ($st->fetchAll() as $n) {
        $n['ingredient_id'] = (int) $n['ingredient_id'];
        $n['short'] = (float) $n['short_base'] > 0.00005;
        $n['supplier'] = null;
        if ($n['short']) {
            $sup = proj_row($pdo, "SELECT si.supplier_item_id, s.supplier_id, s.name AS supplier, w.cutoff_at, w.delivery_on, si.purchase_unit_code AS unit,
                       greatest(ceil(CAST(:sh AS numeric) / ri_to_base(si.ingredient_id, 1, si.purchase_unit_code)), si.moq) AS units
                  FROM mcp_supplier_items si JOIN mcp_suppliers s ON s.supplier_id = si.supplier_id AND s.active
                  CROSS JOIN LATERAL ri_next_order_windows(si.supplier_id, :s, now()) w
                  LEFT JOIN mcp_ingredient_site_settings ss ON ss.ingredient_id = si.ingredient_id AND ss.scope_id = :s2
                 WHERE si.ingredient_id = :i AND si.active AND w.delivery_on <= CAST(:d AS date)
                 ORDER BY (si.supplier_item_id = ss.preferred_supplier_item_id) DESC NULLS LAST, w.delivery_on, si.supplier_item_id LIMIT 1",
                ['sh' => $n['short_base'], 's' => $scopeId, 's2' => $scopeId, 'i' => $n['ingredient_id'], 'd' => $date]);
            if ($sup !== null) {
                $n['supplier'] = stock_ints($sup, ['supplier_item_id', 'supplier_id']);
                $out['drafts'][$n['supplier']['supplier_id']]['supplier'] = $sup['supplier'];
                $out['drafts'][$n['supplier']['supplier_id']]['cutoff_at'] = $sup['cutoff_at'];
                $out['drafts'][$n['supplier']['supplier_id']]['delivery_on'] = $sup['delivery_on'];
                $out['drafts'][$n['supplier']['supplier_id']]['lines'][] = ['supplier_item' => $n['supplier']['supplier_item_id'], 'qty' => $sup['units'], 'unit' => $sup['unit'], 'name' => $n['name']];
            }
            $out['short'][] = $n;
        }
        $out['needs'][] = $n;
    }
    if ($out['needs'] === []) { $out['problem'] = $recipe['name'] . ' has no ingredients on ' . format_date($date) . '.'; return $out; }
    if ($out['short'] === []) { $out['verdict'] = 'Yes — everything covered'; return $out; }
    $shorts = array_map(static fn (array $n): string => format_qty($pdo, $n['ingredient_id'], $n['short_base']) . ' of ' . $n['name'], $out['short']);
    $parts = [];
    foreach ($out['drafts'] as $d) {
        $parts[] = $d['supplier'] . ' by ' . date('l', (int) strtotime((string) $d['delivery_on'])) . ' if ordered by ' . format_ts($d['cutoff_at'], $site, 'l H:i');
    }
    $none = array_map(static fn (array $n): string => $n['name'], array_filter($out['short'], static fn (array $n): bool => $n['supplier'] === null));
    $out['verdict'] = 'Short ' . implode(', ', $shorts) . ($parts !== [] ? '; ' . implode('; ', $parts) : '') . ($none !== [] ? '; no supplier delivers ' . implode(', ', $none) . ' by ' . date('l', (int) strtotime($date)) : '');
    return $out;
}

/** The suggested-order line that covers an ingredient at a site (its preferred supplier item's supplier, else the first active supplier item): ['supplier_id', 'supplier', 'cutoff_at', 'delivery_on', 'line'] or null. */
function suggested_line_for_ingredient(PDO $pdo, int $scopeId, int $ingredientId): ?array
{
    $sup = proj_row($pdo, 'SELECT s.supplier_id, s.name FROM mcp_supplier_items si JOIN mcp_suppliers s ON s.supplier_id = si.supplier_id AND s.active
                           LEFT JOIN mcp_ingredient_site_settings ss ON ss.ingredient_id = si.ingredient_id AND ss.scope_id = :s
                          WHERE si.ingredient_id = :i AND si.active ORDER BY (si.supplier_item_id = ss.preferred_supplier_item_id) DESC NULLS LAST, si.supplier_item_id LIMIT 1', ['s' => $scopeId, 'i' => $ingredientId]);
    if ($sup === null) { return null; }
    foreach (suggested_order($pdo, $scopeId, (int) $sup['supplier_id']) as $l) {
        if ($l['ingredient_id'] === $ingredientId) { return ['supplier_id' => (int) $sup['supplier_id'], 'supplier' => $sup['name'], 'cutoff_at' => $l['cutoff_at'], 'delivery_on' => $l['delivery_on'], 'line' => $l]; }
    }
    return null;
}
