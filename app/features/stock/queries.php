<?php
declare(strict_types=1);

/**
 * The ledger's reads (ledger.md "Query functions"; signatures fixed — Phase 4's tools `stock_on_hand`, `stock_by_area`, `stock_movements`,
 * `stock_value`, `receipts_open`, `transfers_open`, `production_batches`, `waste_logs` call these). Every row comes through an mcp_* view, so
 * the site and the cost walls are the database's: a cost a caller may not see arrives null. A quantity is rendered by
 * app/features/units/queries.php alone (format_qty / ri_qty_sentence / display_qty) — never multiplied here.
 */

const STOCK_PAGE = 100;
const TXN_CHIPS = ['receipt' => 'success', 'depletion' => 'primary', 'waste' => 'danger', 'transfer_out' => 'info', 'transfer_in' => 'info', 'production_in' => 'warning',
    'production_out' => 'warning', 'count_correction' => 'secondary', 'adjustment' => 'secondary', 'return' => 'success', 'reversal' => 'dark'];
const TXN_WORDS = ['receipt' => 'receipt', 'depletion' => 'depletion', 'waste' => 'waste', 'transfer_out' => 'transfer out', 'transfer_in' => 'transfer in', 'production_in' => 'made',
    'production_out' => 'used in prep', 'count_correction' => 'count', 'adjustment' => 'adjustment', 'return' => 'return', 'reversal' => 'reversal'];
const DOC_STATUS_CHIPS = ['draft' => 'secondary', 'in_transit' => 'info', 'posted' => 'success', 'received' => 'success', 'reviewed' => 'success', 'cancelled' => 'dark', 'voided' => 'dark'];
const DISCREPANCY_KINDS = ['none' => 'None', 'short' => 'Short', 'over' => 'Over', 'damaged' => 'Damaged', 'wrong_item' => 'Wrong item', 'substitute' => 'Substitute'];
const WASTE_STAGES = ['ingredient' => 'Ingredient', 'prep' => 'Prep', 'plate' => 'Plate'];
const WASTE_DISPOSITIONS = ['trash' => 'Trash', 'compost' => 'Compost', 'donation' => 'Donation', 'staff_meal' => 'Staff meal', 'comp' => 'Comp', 'other' => 'Other'];

/** An int-keyed row: the named keys cast to int (PDO returns bigint as a string). */
function stock_ints(array $r, array $keys): array
{
    foreach ($keys as $k) { if (array_key_exists($k, $r) && $r[$k] !== null) { $r[$k] = (int) $r[$k]; } }
    return $r;
}

/** On hand by ingredient and area at a site: [rows, total, total_value, withheld]. $f: area, category, q, below_par. Value null when walled. */
function stock_levels(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['b.scope_id = :s', 'b.qty_base <> 0'];
    $args = ['s' => $scope];
    if (($f['area'] ?? '') !== '' && ctype_digit((string) $f['area'])) { $where[] = 'b.storage_area_id = :a'; $args['a'] = (int) $f['area']; }
    if (($f['category'] ?? '') !== '') { $where[] = '(c.key = :c OR c.category_id::text = :c2)'; $args['c'] = (string) $f['category']; $args['c2'] = (string) $f['category']; }
    if (($f['q'] ?? '') !== '') { $where[] = '(i.name ILIKE :q OR i.code ILIKE :q2)'; $args['q'] = '%' . $f['q'] . '%'; $args['q2'] = '%' . $f['q'] . '%'; }
    $sql = 'SELECT b.ingredient_id, b.storage_area_id, b.qty_base, a.name AS area, a.walk_order, i.name, i.code, i.base_unit_code, i.measure_class, c.name AS category, c.key AS category_key,
                   sc.avg_cost_per_base, sc.on_hand_base AS site_on_hand, ss.par_base,
                   (SELECT u.unit_code FROM mcp_ingredient_units u WHERE u.ingredient_id = b.ingredient_id AND u.is_default_count AND u.active) AS count_unit
              FROM mcp_inventory_balances b
              JOIN mcp_storage_areas a ON a.storage_area_id = b.storage_area_id
              JOIN mcp_ingredients i ON i.ingredient_id = b.ingredient_id
              JOIN mcp_ingredient_categories c ON c.category_id = i.category_id
              LEFT JOIN mcp_ingredient_site_costs sc ON sc.ingredient_id = b.ingredient_id AND sc.scope_id = b.scope_id
              LEFT JOIN mcp_ingredient_site_settings ss ON ss.ingredient_id = b.ingredient_id AND ss.scope_id = b.scope_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY i.name, a.walk_order, a.name';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $all = $st->fetchAll();
    $doh = [];
    $st = $pdo->prepare('SELECT ingredient_id, days_on_hand FROM ri_days_on_hand(:s)');
    $st->execute(['s' => $scope]);
    foreach ($st->fetchAll() as $d) { $doh[(int) $d['ingredient_id']] = $d['days_on_hand']; }
    $onOrder = [];
    $rows = [];
    $total = 0.0;
    $withheld = !sees_cost($scope);
    foreach ($all as $r) {
        $r = stock_ints($r, ['ingredient_id', 'storage_area_id']);
        $iid = $r['ingredient_id'];
        $onOrder[$iid] ??= (string) one_value($pdo, 'SELECT ri_on_order(:i, :s)', ['i' => $iid, 's' => $scope]);
        $r['on_order_base'] = $onOrder[$iid];
        $r['below_par'] = $r['par_base'] !== null && ((float) $r['site_on_hand'] + (float) $onOrder[$iid]) < (float) $r['par_base'];
        $r['days_on_hand'] = $doh[$iid] ?? null;
        $r['value'] = $withheld || $r['avg_cost_per_base'] === null ? null : number_format((float) $r['qty_base'] * (float) $r['avg_cost_per_base'], 2, '.', '');
        if ($r['value'] !== null) { $total += (float) $r['value']; }
        if (!empty($f['below_par']) && !$r['below_par']) { continue; }
        $rows[] = $r;
    }
    $count = count($rows);
    return ['rows' => array_slice($rows, ($page - 1) * STOCK_PAGE, STOCK_PAGE), 'total' => $count, 'total_value' => $withheld ? null : number_format($total, 2, '.', ''), 'withheld' => $withheld];
}

/** The ledger at a site: [rows, total]. $f: ingredient, area, type, from, to (business dates; the last 30 days by default). */
function stock_movements(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['t.scope_id = :s'];
    $args = ['s' => $scope];
    if (($f['ingredient'] ?? '') !== '' && ctype_digit((string) $f['ingredient'])) { $where[] = 't.ingredient_id = :i'; $args['i'] = (int) $f['ingredient']; }
    if (($f['area'] ?? '') !== '' && ctype_digit((string) $f['area'])) { $where[] = 't.storage_area_id = :a'; $args['a'] = (int) $f['area']; }
    if (($f['type'] ?? '') !== '' && isset(TXN_CHIPS[$f['type']])) { $where[] = 't.txn_type = :t'; $args['t'] = $f['type']; }
    if (($f['reference_kind'] ?? '') !== '') { $where[] = 't.reference_kind = :rk AND t.reference_id = :rid'; $args['rk'] = $f['reference_kind']; $args['rid'] = (int) ($f['reference_id'] ?? 0); }
    else {
        $from = ($f['from'] ?? '') !== '' ? $f['from'] : date('Y-m-d', strtotime('-30 days'));
        $where[] = 't.business_date >= :f'; $args['f'] = $from;
        if (($f['to'] ?? '') !== '') { $where[] = 't.business_date <= :to'; $args['to'] = $f['to']; }
    }
    $base = ' FROM mcp_inventory_transactions t JOIN mcp_ingredients i ON i.ingredient_id = t.ingredient_id JOIN mcp_storage_areas a ON a.storage_area_id = t.storage_area_id
              LEFT JOIN mcp_reason_codes rc ON rc.reason_code_id = t.reason_code_id LEFT JOIN mcp_members m ON m.member_id = t.actor_member_id WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT count(*)' . $base);
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT t.*, i.name AS ingredient, i.base_unit_code, a.name AS area, rc.name AS reason, m.display_name AS actor,
            CASE t.reference_kind
                WHEN \'goods_receipt\' THEN (SELECT number FROM mcp_goods_receipts WHERE goods_receipt_id = t.reference_id)
                WHEN \'transfer\' THEN (SELECT number FROM mcp_inventory_transfers WHERE transfer_id = t.reference_id)
                WHEN \'adjustment\' THEN (SELECT number FROM mcp_inventory_adjustments WHERE adjustment_id = t.reference_id)
                WHEN \'production\' THEN (SELECT number FROM mcp_production_batches WHERE production_batch_id = t.reference_id)
                WHEN \'waste\' THEN (SELECT number FROM mcp_waste_logs WHERE waste_log_id = t.reference_id)
                WHEN \'count\' THEN (SELECT number FROM mcp_inventory_counts WHERE count_id = t.reference_id)
                WHEN \'depletion_run\' THEN (SELECT \'Sales \' || business_date FROM mcp_depletion_runs WHERE depletion_run_id = t.reference_id)
                WHEN \'reversal\' THEN \'#\' || t.reference_id
                WHEN \'invoice\' THEN (SELECT number FROM mcp_supplier_invoices WHERE invoice_id = t.reference_id)
                ELSE NULL END AS document,
            CASE t.counterparty_kind
                WHEN \'storage_area\' THEN (SELECT name FROM mcp_storage_areas WHERE storage_area_id = t.counterparty_id)
                WHEN \'site\' THEN (SELECT name FROM sites WHERE scope_id = t.counterparty_id)
                WHEN \'supplier\' THEN (SELECT name FROM mcp_suppliers WHERE supplier_id = t.counterparty_id)
                WHEN \'customer\' THEN \'customer\' WHEN \'disposal\' THEN \'disposal\' ELSE NULL END AS counterparty,
            EXISTS (SELECT 1 FROM mcp_inventory_transactions r WHERE r.reverses_id = t.transaction_id) AS reversed' . $base
        . ' ORDER BY t.occurred_at DESC, t.transaction_id DESC LIMIT ' . STOCK_PAGE . ' OFFSET ' . (max(0, $page - 1) * STOCK_PAGE));
    $st->execute($args);
    $rows = array_map(static function (array $r): array {
        $r = stock_ints($r, ['transaction_id', 'ingredient_id', 'storage_area_id', 'reference_id', 'reverses_id', 'counterparty_id', 'actor_member_id']);
        $r['reversed'] = (bool) $r['reversed'];
        $r['late'] = str_contains((string) $r['note'], 'late:');
        $r['reversible'] = $r['txn_type'] !== 'reversal' && !$r['reversed'];
        return $r;
    }, $st->fetchAll());
    return ['rows' => $rows, 'total' => $total];
}

/** A document's own movements (receipt, transfer, adjustment, production, waste): every row it posted, reversals included. */
function document_movements(PDO $pdo, int $scope, string $kind, int $id): array
{
    $st = $pdo->prepare('SELECT t.*, i.name AS ingredient, i.base_unit_code, a.name AS area FROM mcp_inventory_transactions t JOIN mcp_ingredients i ON i.ingredient_id = t.ingredient_id
        JOIN mcp_storage_areas a ON a.storage_area_id = t.storage_area_id
        WHERE (t.reference_kind = :k AND t.reference_id = :id) OR t.reverses_id IN (SELECT transaction_id FROM mcp_inventory_transactions WHERE reference_kind = :k2 AND reference_id = :id2)
        ORDER BY t.transaction_id');
    $st->execute(['k' => $kind, 'id' => $id, 'k2' => $kind, 'id2' => $id]);
    return array_map(static fn (array $r): array => stock_ints($r, ['transaction_id', 'ingredient_id', 'storage_area_id', 'scope_id', 'reverses_id']), $st->fetchAll());
}

/** Stock value by category as of a day's close at a site: [rows, total, withheld]. ri_stock_value() is the referee; PHP walls the money. */
function stock_value_rows(PDO $pdo, int $scope, string $asOf): array
{
    $withheld = !sees_cost($scope);
    $st = $pdo->prepare('SELECT v.ingredient_id, v.category_id, v.on_hand_base, v.unit_cost, v.value, i.name, i.base_unit_code, c.name AS category, c.key AS category_key, c.sort_order
        FROM ri_stock_value(:s, ri_site_moment(:s2, :d::date, true)) v JOIN ingredients i ON i.id = v.ingredient_id JOIN ingredient_categories c ON c.id = v.category_id ORDER BY c.sort_order, i.name');
    $st->execute(['s' => $scope, 's2' => $scope, 'd' => $asOf]);
    $cats = [];
    $total = 0.0;
    foreach ($st->fetchAll() as $r) {
        $k = (string) $r['category_key'];
        $cats[$k] ??= ['category_key' => $k, 'category' => $r['category'], 'category_id' => (int) $r['category_id'], 'ingredients' => 0, 'value' => 0.0, 'rows' => []];
        $cats[$k]['ingredients']++;
        $cats[$k]['value'] += (float) $r['value'];
        $total += (float) $r['value'];
        $cats[$k]['rows'][] = ['ingredient_id' => (int) $r['ingredient_id'], 'name' => $r['name'], 'on_hand_base' => $r['on_hand_base'], 'base_unit_code' => $r['base_unit_code'],
            'unit_cost' => $withheld ? null : $r['unit_cost'], 'value' => $withheld ? null : $r['value']];
    }
    foreach ($cats as &$c) { $c['value'] = $withheld ? null : number_format($c['value'], 2, '.', ''); }
    unset($c);
    return ['rows' => array_values($cats), 'total' => $withheld ? null : number_format($total, 2, '.', ''), 'withheld' => $withheld, 'as_of' => $asOf];
}

/** One ingredient's balance in one area (base), 0 when none. */
function balance_for(PDO $pdo, int $ingredientId, int $areaId): string
{
    return (string) (one_value($pdo, 'SELECT qty_base FROM mcp_inventory_balances WHERE ingredient_id = :i AND storage_area_id = :a', ['i' => $ingredientId, 'a' => $areaId]) ?? '0');
}

/** A ledger row the caller may see (at the current site), for stock_reverse. */
function txn_for_reverse(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT t.*, i.name AS ingredient, EXISTS (SELECT 1 FROM mcp_inventory_transactions r WHERE r.reverses_id = t.transaction_id) AS reversed
        FROM mcp_inventory_transactions t JOIN mcp_ingredients i ON i.ingredient_id = t.ingredient_id WHERE t.transaction_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['transaction_id', 'scope_id', 'ingredient_id', 'storage_area_id', 'reverses_id']);
}

/** The units a form offers for an ingredient: [{code, label, factor, kind}] — receive: purchase units first, then count, then the class's
 *  global units; count/waste: count units first; recipe: recipe units first. Every one converts (ri_unit_options builds the set). */
function unit_options_for(PDO $pdo, int $ingredient, string $purpose): array
{
    $opts = ri_unit_options($pdo, $ingredient);
    $order = match ($purpose) { 'receive' => ['purchase' => 0, 'count' => 1, 'recipe' => 2, 'global' => 3], 'recipe' => ['recipe' => 0, 'purchase' => 2, 'count' => 1, 'global' => 3], default => ['count' => 0, 'purchase' => 1, 'recipe' => 2, 'global' => 3] };
    usort($opts, static fn (array $a, array $b): int => [$order[$a['kind']] ?? 9, $a['code']] <=> [$order[$b['kind']] ?? 9, $b['code']]);
    return $opts;
}

/** What a receiving form needs to know of an ingredient: catch weight (its own or the supplier item's), the temperature check (the category's AND the site's). */
function ingredient_receiving_facts(PDO $pdo, int $ingredient, int $scope, ?int $supplierItem = null): array
{
    $st = $pdo->prepare('SELECT i.ingredient_id, i.name, i.catch_weight, i.base_unit_code, c.temperature_check FROM mcp_ingredients i JOIN mcp_ingredient_categories c ON c.category_id = i.category_id WHERE i.ingredient_id = :i');
    $st->execute(['i' => $ingredient]);
    $i = $st->fetch();
    if ($i === false) { return ['catch_weight' => false, 'temperature' => false]; }
    $cw = (bool) $i['catch_weight'];
    if ($supplierItem !== null) { $cw = $cw || (bool) one_value($pdo, 'SELECT catch_weight FROM mcp_supplier_items WHERE supplier_item_id = :id AND ingredient_id = :i', ['id' => $supplierItem, 'i' => $ingredient]); }
    $siteCheck = (bool) (one_value($pdo, 'SELECT temperature_check FROM site_settings WHERE scope_id = :s', ['s' => $scope]) ?? true);
    return ['catch_weight' => $cw, 'temperature' => $siteCheck && (bool) $i['temperature_check'], 'base_unit_code' => $i['base_unit_code'], 'name' => $i['name']];
}

/** The site's active storage areas for a select: [{storage_area_id, name, kind, allow_negative}]. */
function area_options(PDO $pdo, int $scope): array
{
    return find_storage_areas($pdo, $scope);
}

/** A supplier's open POs at a site (sent, acknowledged, partial). */
function po_options_for_supplier(PDO $pdo, ?int $supplier, int $scope): array
{
    if ($supplier === null) { return []; }
    $st = $pdo->prepare("SELECT purchase_order_id, number, status, ordered_on, expected_on FROM mcp_purchase_orders WHERE supplier_id = :su AND scope_id = :s AND status IN ('sent', 'acknowledged', 'partial') ORDER BY ordered_on DESC, purchase_order_id DESC");
    $st->execute(['su' => $supplier, 's' => $scope]);
    return array_map(static fn (array $r): array => stock_ints($r, ['purchase_order_id']), $st->fetchAll());
}

/** A PO (any status) the caller may see, with the names slice 4's screens print (supplier and how it is ordered from, the area, who made and sent it). */
function po_for(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT p.*, s.name AS supplier, s.order_method, s.order_email, s.email AS supplier_email, a.name AS area, cm.display_name AS created_by_name, sm.display_name AS sent_by_name
        FROM mcp_purchase_orders p JOIN mcp_suppliers s ON s.supplier_id = p.supplier_id LEFT JOIN mcp_storage_areas a ON a.storage_area_id = p.storage_area_id
        LEFT JOIN mcp_members cm ON cm.member_id = p.created_by LEFT JOIN mcp_members sm ON sm.member_id = p.sent_by WHERE p.purchase_order_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['purchase_order_id', 'scope_id', 'supplier_id', 'storage_area_id', 'sent_by', 'created_by']);
}

/** The open lines of a PO with the quantity still to come, in base and in the purchase unit. */
function po_open_lines(PDO $pdo, int $po): array
{
    $st = $pdo->prepare("SELECT l.purchase_order_line_id, l.line_no, l.ingredient_id, l.supplier_item_id, l.purchase_unit_code, l.qty_ordered, l.qty_ordered_base, l.qty_received_base, l.unit_cost,
            greatest(l.qty_ordered_base - l.qty_received_base, 0) AS qty_open_base, i.name AS ingredient, i.base_unit_code
        FROM mcp_purchase_order_lines l JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id
        WHERE l.purchase_order_id = :p AND l.status IN ('open', 'acknowledged', 'partial') AND l.qty_ordered_base > l.qty_received_base ORDER BY l.line_no");
    $st->execute(['p' => $po]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r = stock_ints($r, ['purchase_order_line_id', 'ingredient_id', 'supplier_item_id', 'line_no']);
        $r['qty_open'] = qty_from_base($pdo, $r['ingredient_id'], (string) $r['qty_open_base'], (string) $r['purchase_unit_code']);
        $rows[] = $r;
    }
    return $rows;
}

// ---------------------------------------------------------------------------------------------- receipts
function receipts_list(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['g.scope_id = :s'];
    $args = ['s' => $scope];
    if (($f['status'] ?? '') !== '') { $where[] = 'g.status = :st'; $args['st'] = $f['status']; }
    if (($f['supplier'] ?? '') !== '' && ctype_digit((string) $f['supplier'])) { $where[] = 'g.supplier_id = :su'; $args['su'] = (int) $f['supplier']; }
    if (($f['from'] ?? '') !== '') { $where[] = 'g.received_on >= :f'; $args['f'] = $f['from']; }
    if (($f['to'] ?? '') !== '') { $where[] = 'g.received_on <= :t'; $args['t'] = $f['to']; }
    $base = ' FROM mcp_goods_receipts g LEFT JOIN mcp_suppliers s ON s.supplier_id = g.supplier_id JOIN mcp_storage_areas a ON a.storage_area_id = g.storage_area_id
              LEFT JOIN mcp_purchase_orders p ON p.purchase_order_id = g.purchase_order_id LEFT JOIN mcp_members m ON m.member_id = g.posted_by WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT count(*)' . $base); $st->execute($args); $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT g.*, s.name AS supplier, a.name AS area, p.number AS po_number, m.display_name AS posted_by_name,
            (SELECT count(*) FROM mcp_goods_receipt_lines l WHERE l.goods_receipt_id = g.goods_receipt_id) AS lines,
            (SELECT CASE WHEN count(l.temperature_ok) = 0 THEN NULL ELSE bool_and(l.temperature_ok) END FROM mcp_goods_receipt_lines l WHERE l.goods_receipt_id = g.goods_receipt_id) AS temps_ok' . $base
        . " ORDER BY (g.status = 'draft') DESC, g.received_on DESC, g.goods_receipt_id DESC LIMIT " . STOCK_PAGE . ' OFFSET ' . (max(0, $page - 1) * STOCK_PAGE));
    $st->execute($args);
    return ['rows' => array_map(static fn (array $r): array => stock_ints($r, ['goods_receipt_id', 'supplier_id', 'purchase_order_id', 'storage_area_id', 'lines']), $st->fetchAll()), 'total' => $total];
}

function receipt_for(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT g.*, s.name AS supplier, a.name AS area, p.number AS po_number, pm.display_name AS posted_by_name, cm.display_name AS created_by_name
        FROM mcp_goods_receipts g LEFT JOIN mcp_suppliers s ON s.supplier_id = g.supplier_id JOIN mcp_storage_areas a ON a.storage_area_id = g.storage_area_id
        LEFT JOIN mcp_purchase_orders p ON p.purchase_order_id = g.purchase_order_id LEFT JOIN mcp_members pm ON pm.member_id = g.posted_by LEFT JOIN mcp_members cm ON cm.member_id = g.created_by
        WHERE g.goods_receipt_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['goods_receipt_id', 'scope_id', 'supplier_id', 'purchase_order_id', 'storage_area_id', 'posted_by', 'created_by']);
}

function receipt_lines(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT l.*, i.name AS ingredient, i.code, i.base_unit_code, i.catch_weight AS ingredient_catch_weight, c.temperature_check, si.supplier_sku, si.catch_weight AS item_catch_weight, pa.name AS putaway_area
        FROM mcp_goods_receipt_lines l JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id JOIN mcp_ingredient_categories c ON c.category_id = i.category_id
        LEFT JOIN mcp_supplier_items si ON si.supplier_item_id = l.supplier_item_id LEFT JOIN mcp_storage_areas pa ON pa.storage_area_id = l.putaway_area_id
        WHERE l.goods_receipt_id = :id ORDER BY l.line_no');
    $st->execute(['id' => $id]);
    return array_map(static fn (array $r): array => stock_ints($r, ['goods_receipt_line_id', 'goods_receipt_id', 'line_no', 'ingredient_id', 'supplier_item_id', 'purchase_order_line_id', 'putaway_area_id']), $st->fetchAll());
}

/** The price-history rows a posted receipt wrote (walled by the view). */
function receipt_price_rows(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT h.*, i.name AS ingredient, i.base_unit_code FROM mcp_price_history h JOIN mcp_ingredients i ON i.ingredient_id = h.ingredient_id WHERE h.reference_kind = 'goods_receipt' AND h.reference_id = :id ORDER BY h.price_history_id");
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------------------------------- transfers
function transfers_list(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['(t.scope_id = :s OR t.to_scope_id = :s2)'];
    $args = ['s' => $scope, 's2' => $scope];
    if (($f['status'] ?? '') !== '') { $where[] = 't.status = :st'; $args['st'] = $f['status']; }
    $base = ' FROM mcp_inventory_transfers t JOIN sites fs ON fs.scope_id = t.scope_id JOIN sites ts ON ts.scope_id = t.to_scope_id
              JOIN storage_areas fa ON fa.id = t.from_area_id JOIN storage_areas ta ON ta.id = t.to_area_id LEFT JOIN mcp_members sm ON sm.member_id = t.shipped_by LEFT JOIN mcp_members rm ON rm.member_id = t.received_by WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT count(*)' . $base); $st->execute($args); $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT t.*, fs.name AS from_site, ts.name AS to_site, fa.name AS from_area, ta.name AS to_area, sm.display_name AS shipped_by_name, rm.display_name AS received_by_name,
            jsonb_array_length(COALESCE(t.lines, \'[]\'::jsonb)) AS line_count' . $base . " ORDER BY (t.status IN ('draft', 'in_transit')) DESC, t.created_at DESC LIMIT " . STOCK_PAGE . ' OFFSET ' . (max(0, $page - 1) * STOCK_PAGE));
    $st->execute($args);
    return ['rows' => array_map(static fn (array $r): array => stock_ints($r, ['transfer_id', 'scope_id', 'to_scope_id', 'from_area_id', 'to_area_id', 'line_count']), $st->fetchAll()), 'total' => $total];
}

function transfer_for(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT t.*, fs.name AS from_site, ts.name AS to_site, fa.name AS from_area, ta.name AS to_area, sm.display_name AS shipped_by_name, rm.display_name AS received_by_name
        FROM mcp_inventory_transfers t JOIN sites fs ON fs.scope_id = t.scope_id JOIN sites ts ON ts.scope_id = t.to_scope_id JOIN storage_areas fa ON fa.id = t.from_area_id JOIN storage_areas ta ON ta.id = t.to_area_id
        LEFT JOIN mcp_members sm ON sm.member_id = t.shipped_by LEFT JOIN mcp_members rm ON rm.member_id = t.received_by WHERE t.transfer_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['transfer_id', 'scope_id', 'to_scope_id', 'from_area_id', 'to_area_id']);
}

function transfer_lines(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT l.id AS line_id, l.line_no, l.ingredient_id, l.qty, l.unit_code, l.qty_base, l.qty_received_base, i.name AS ingredient, i.base_unit_code
        FROM inventory_transfer_lines l JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id
        WHERE l.transfer_id = :id AND EXISTS (SELECT 1 FROM mcp_inventory_transfers t WHERE t.transfer_id = l.transfer_id) ORDER BY l.line_no');
    $st->execute(['id' => $id]);
    return array_map(static fn (array $r): array => stock_ints($r, ['line_id', 'line_no', 'ingredient_id']), $st->fetchAll());
}

// ---------------------------------------------------------------------------------------------- adjustments
function adjustments_list(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['j.scope_id = :s'];
    $args = ['s' => $scope];
    if (($f['status'] ?? '') !== '') { $where[] = 'j.status = :st'; $args['st'] = $f['status']; }
    if (($f['reason'] ?? '') !== '') { $where[] = '(r.code = :r OR r.reason_code_id::text = :r2)'; $args['r'] = $f['reason']; $args['r2'] = $f['reason']; }
    $base = ' FROM mcp_inventory_adjustments j JOIN mcp_storage_areas a ON a.storage_area_id = j.storage_area_id JOIN mcp_reason_codes r ON r.reason_code_id = j.reason_code_id LEFT JOIN mcp_members m ON m.member_id = j.posted_by WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT count(*)' . $base); $st->execute($args); $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT j.*, a.name AS area, r.name AS reason, r.code AS reason_code, m.display_name AS posted_by_name, jsonb_array_length(COALESCE(j.lines, \'[]\'::jsonb)) AS line_count' . $base
        . " ORDER BY (j.status = 'draft') DESC, j.created_at DESC LIMIT " . STOCK_PAGE . ' OFFSET ' . (max(0, $page - 1) * STOCK_PAGE));
    $st->execute($args);
    return ['rows' => array_map(static fn (array $r): array => stock_ints($r, ['adjustment_id', 'storage_area_id', 'reason_code_id', 'line_count']), $st->fetchAll()), 'total' => $total];
}

function adjustment_for(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT j.*, a.name AS area, r.name AS reason, r.code AS reason_code, m.display_name AS posted_by_name FROM mcp_inventory_adjustments j JOIN mcp_storage_areas a ON a.storage_area_id = j.storage_area_id
        JOIN mcp_reason_codes r ON r.reason_code_id = j.reason_code_id LEFT JOIN mcp_members m ON m.member_id = j.posted_by WHERE j.adjustment_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['adjustment_id', 'scope_id', 'storage_area_id', 'reason_code_id']);
}

/** An adjustment's lines; the cost per base only for one who sees cost at the site. */
function adjustment_lines(PDO $pdo, int $id): array
{
    $sees = sees_cost();
    $st = $pdo->prepare('SELECT l.id AS line_id, l.line_no, l.ingredient_id, l.qty_delta, l.unit_code, l.qty_delta_base, l.unit_cost_per_base, l.note, i.name AS ingredient, i.base_unit_code
        FROM inventory_adjustment_lines l JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id
        WHERE l.adjustment_id = :id AND EXISTS (SELECT 1 FROM mcp_inventory_adjustments j WHERE j.adjustment_id = l.adjustment_id) ORDER BY l.line_no');
    $st->execute(['id' => $id]);
    return array_map(static function (array $r) use ($sees): array { $r = stock_ints($r, ['line_id', 'line_no', 'ingredient_id']); if (!$sees) { $r['unit_cost_per_base'] = null; } return $r; }, $st->fetchAll());
}

/** The reasons whose applies_to holds $kind, active, in order. */
function reasons_for(PDO $pdo, string $kind, ?string $stage = null): array
{
    $st = $pdo->prepare('SELECT reason_code_id, code, name, stage FROM mcp_reason_codes WHERE active AND :k = ANY (applies_to) ORDER BY (stage IS NOT DISTINCT FROM :st) DESC, sort_order, name');
    $st->execute(['k' => $kind, 'st' => $stage]);
    return array_map(static fn (array $r): array => stock_ints($r, ['reason_code_id']), $st->fetchAll());
}

function reason_by_ref(PDO $pdo, string $v): ?array
{
    $st = $pdo->prepare('SELECT reason_code_id, code, name, applies_to, stage FROM mcp_reason_codes WHERE code = :c OR reason_code_id::text = :c2');
    $st->execute(['c' => $v, 'c2' => $v]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['reason_code_id']);
}

// ---------------------------------------------------------------------------------------------- production
function batches_list(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['b.scope_id = :s'];
    $args = ['s' => $scope];
    if (($f['status'] ?? '') !== '') { $where[] = 'b.status = :st'; $args['st'] = $f['status']; }
    if (($f['recipe'] ?? '') !== '' && ctype_digit((string) $f['recipe'])) { $where[] = 'b.recipe_id = :r'; $args['r'] = (int) $f['recipe']; }
    if (($f['from'] ?? '') !== '') { $where[] = 'b.made_at >= :f::date'; $args['f'] = $f['from']; }
    if (($f['to'] ?? '') !== '') { $where[] = 'b.made_at < (:t::date + 1)'; $args['t'] = $f['to']; }
    $base = ' FROM mcp_production_batches b JOIN mcp_recipes r ON r.recipe_id = b.recipe_id LEFT JOIN mcp_members m ON m.member_id = b.made_by WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT count(*)' . $base); $st->execute($args); $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT b.*, r.name AS recipe, r.output_ingredient_id, m.display_name AS made_by_name' . $base . " ORDER BY (b.status = 'draft') DESC, b.made_at DESC LIMIT " . STOCK_PAGE . ' OFFSET ' . (max(0, $page - 1) * STOCK_PAGE));
    $st->execute($args);
    return ['rows' => array_map(static fn (array $r): array => stock_ints($r, ['production_batch_id', 'recipe_id', 'output_ingredient_id', 'storage_area_id']), $st->fetchAll()), 'total' => $total];
}

function batch_for(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT b.*, r.name AS recipe, r.code AS recipe_code, r.output_ingredient_id, oi.name AS output_ingredient, oi.base_unit_code AS output_base_unit, a.name AS area, ca.name AS consume_area, m.display_name AS made_by_name
        FROM mcp_production_batches b JOIN mcp_recipes r ON r.recipe_id = b.recipe_id LEFT JOIN mcp_ingredients oi ON oi.ingredient_id = r.output_ingredient_id
        JOIN mcp_storage_areas a ON a.storage_area_id = b.storage_area_id LEFT JOIN mcp_storage_areas ca ON ca.storage_area_id = b.consume_area_id LEFT JOIN mcp_members m ON m.member_id = b.made_by
        WHERE b.production_batch_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['production_batch_id', 'scope_id', 'recipe_id', 'recipe_version_id', 'output_ingredient_id', 'storage_area_id', 'consume_area_id']);
}

/** What a batch of a prep recipe will consume: ri_explode(the version in force today) × batches per ingredient, against on hand at the site; short lines marked. */
function batch_preview(PDO $pdo, int $recipe, float $batches, int $scope): array
{
    $vid = one_value($pdo, 'SELECT ri_recipe_version_on(:r, current_date)', ['r' => $recipe]);
    if ($vid === null || $vid === false) { return ['version_id' => null, 'rows' => [], 'output' => null]; }
    $st = $pdo->prepare('SELECT e.ingredient_id, e.qty_base * :b AS need_base, i.name, i.base_unit_code, ri_on_hand(e.ingredient_id, :s) AS on_hand_base FROM ri_explode(:v, current_date, false) e JOIN ingredients i ON i.id = e.ingredient_id ORDER BY i.name');
    $st->execute(['b' => $batches, 's' => $scope, 'v' => (int) $vid]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r = stock_ints($r, ['ingredient_id']);
        $r['short'] = (float) $r['on_hand_base'] < (float) $r['need_base'];
        $rows[] = $r;
    }
    $v = recipe_version($pdo, (int) $vid);
    $out = null;
    $r = find_recipe($pdo, $recipe);
    if ($v !== null && $r !== null && !empty($r['output_ingredient_id'])) {
        $per = qty_to_base($pdo, (int) $r['output_ingredient_id'], '1', (string) $v['batch_yield_unit_code']);
        $out = number_format((float) $v['batch_yield_qty'] * (float) $per * $batches * (1 - (float) $v['expected_loss_pct'] / 100), 4, '.', '');
    }
    return ['version_id' => (int) $vid, 'rows' => $rows, 'output_base' => $out, 'output_ingredient_id' => $r['output_ingredient_id'] ?? null];
}

// ---------------------------------------------------------------------------------------------- waste
function waste_list(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['w.scope_id = :s'];
    $args = ['s' => $scope];
    $from = ($f['from'] ?? '') !== '' ? $f['from'] : date('Y-m-d', strtotime('-6 days'));
    $where[] = 'w.business_date >= :f'; $args['f'] = $from;
    if (($f['to'] ?? '') !== '') { $where[] = 'w.business_date <= :t'; $args['t'] = $f['to']; }
    if (($f['stage'] ?? '') !== '') { $where[] = 'w.stage = :sg'; $args['sg'] = $f['stage']; }
    if (($f['reason'] ?? '') !== '') { $where[] = '(r.code = :r OR r.reason_code_id::text = :r2)'; $args['r'] = $f['reason']; $args['r2'] = $f['reason']; }
    if (($f['station'] ?? '') !== '') { $where[] = 'w.station ILIKE :sn'; $args['sn'] = $f['station']; }
    $base = ' FROM mcp_waste_logs w JOIN mcp_reason_codes r ON r.reason_code_id = w.reason_code_id LEFT JOIN mcp_ingredients i ON i.ingredient_id = w.ingredient_id LEFT JOIN mcp_recipes rc ON rc.recipe_id = w.recipe_id
              LEFT JOIN mcp_day_parts d ON d.day_part_id = w.day_part_id LEFT JOIN mcp_members m ON m.member_id = w.logged_by WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT count(*)' . $base); $st->execute($args); $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT w.*, r.name AS reason, r.code AS reason_code, i.name AS ingredient, rc.name AS recipe, d.name AS day_part, m.display_name AS logged_by_name' . $base
        . ' ORDER BY w.logged_at DESC, w.waste_log_id DESC LIMIT ' . STOCK_PAGE . ' OFFSET ' . (max(0, $page - 1) * STOCK_PAGE));
    $st->execute($args);
    return ['rows' => array_map(static fn (array $r): array => stock_ints($r, ['waste_log_id', 'ingredient_id', 'recipe_id', 'reason_code_id', 'day_part_id', 'logged_by', 'storage_area_id']), $st->fetchAll()), 'total' => $total, 'from' => $from];
}

function waste_for(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT w.*, r.name AS reason, r.code AS reason_code, i.name AS ingredient, i.base_unit_code, rc.name AS recipe, d.name AS day_part, a.name AS area, m.display_name AS logged_by_name, rv.display_name AS reviewed_by_name
        FROM mcp_waste_logs w JOIN mcp_reason_codes r ON r.reason_code_id = w.reason_code_id LEFT JOIN mcp_ingredients i ON i.ingredient_id = w.ingredient_id LEFT JOIN mcp_recipes rc ON rc.recipe_id = w.recipe_id
        LEFT JOIN mcp_day_parts d ON d.day_part_id = w.day_part_id LEFT JOIN mcp_storage_areas a ON a.storage_area_id = w.storage_area_id LEFT JOIN mcp_members m ON m.member_id = w.logged_by LEFT JOIN mcp_members rv ON rv.member_id = w.reviewed_by
        WHERE w.waste_log_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['waste_log_id', 'scope_id', 'ingredient_id', 'recipe_id', 'reason_code_id', 'day_part_id', 'storage_area_id', 'logged_by', 'photo_attachment_id']);
}

/** The week's waste at a site: tiles by stage and rows by reason (ri_share_waste_summary()); cost walled. */
function waste_week(PDO $pdo, int $scope, string $from, string $to): array
{
    $withheld = !sees_cost($scope);
    $st = $pdo->prepare('SELECT stage, reason, disposition, entries, cost FROM ri_share_waste_summary(:s, :f::date, :t::date)');
    $st->execute(['s' => $scope, 'f' => $from, 't' => $to]);
    $tiles = ['ingredient' => ['entries' => 0, 'cost' => 0.0], 'prep' => ['entries' => 0, 'cost' => 0.0], 'plate' => ['entries' => 0, 'cost' => 0.0]];
    $reasons = [];
    foreach ($st->fetchAll() as $r) {
        $tiles[$r['stage']]['entries'] += (int) $r['entries'];
        $tiles[$r['stage']]['cost'] += (float) $r['cost'];
        $reasons[$r['reason']] ??= ['reason' => $r['reason'], 'entries' => 0, 'cost' => 0.0];
        $reasons[$r['reason']]['entries'] += (int) $r['entries'];
        $reasons[$r['reason']]['cost'] += (float) $r['cost'];
    }
    foreach ($tiles as &$t) { $t['cost'] = $withheld ? null : number_format($t['cost'], 2, '.', ''); }
    unset($t);
    foreach ($reasons as &$t) { $t['cost'] = $withheld ? null : number_format($t['cost'], 2, '.', ''); }
    unset($t);
    usort($reasons, static fn (array $a, array $b): int => $b['entries'] <=> $a['entries']);
    return ['tiles' => $tiles, 'reasons' => array_values($reasons), 'withheld' => $withheld, 'from' => $from, 'to' => $to];
}

/** The last 30 days' distinct stations at a site (the waste form's datalist). */
function recent_stations(PDO $pdo, int $scope): array
{
    $st = $pdo->prepare("SELECT DISTINCT station FROM mcp_waste_logs WHERE scope_id = :s AND station IS NOT NULL AND station <> '' AND logged_at >= now() - interval '30 days' ORDER BY station");
    $st->execute(['s' => $scope]);
    return array_column($st->fetchAll(), 'station');
}

/** The site's day parts and the one the moment falls in (the waste form's default). */
function day_parts_for(PDO $pdo, int $scope): array
{
    $st = $pdo->prepare('SELECT day_part_id, key, name, starts_at, ends_at FROM mcp_day_parts WHERE scope_id = :s ORDER BY starts_at');
    $st->execute(['s' => $scope]);
    return array_map(static fn (array $r): array => stock_ints($r, ['day_part_id']), $st->fetchAll());
}
function current_day_part(PDO $pdo, int $scope): ?int
{
    $tz = site_timezone($scope);
    $now = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('H:i:s');
    foreach (day_parts_for($pdo, $scope) as $d) {
        $s = (string) $d['starts_at']; $e = (string) $d['ends_at'];
        if (($s <= $e && $now >= $s && $now < $e) || ($s > $e && ($now >= $s || $now < $e))) { return $d['day_part_id']; }
    }
    return null;
}

/** The sites the member holds (for a transfer's destination), this site first. */
function held_sites_this_first(int $current): array
{
    $sites = held_sites();
    usort($sites, static fn (array $a, array $b): int => [(int) ($a['scope_id'] !== $current), $a['name']] <=> [(int) ($b['scope_id'] !== $current), $b['name']]);
    return $sites;
}
