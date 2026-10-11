<?php
declare(strict_types=1);

/**
 * Purchasing's reads (purchasing.md "Query functions", signatures fixed — Phase 4's tools `find_purchase_orders`, `get_purchase_order`,
 * `purchase_order_events`, `purchase_orders_open`, `deliveries_due`, `find_invoices`, `get_invoice`, `invoice_mismatches`, `price_exceptions` call
 * these). Every row comes through an mcp_* view, so the site and the cost walls are the database's: a cost a caller may not see arrives null.
 * `po_for()` and `po_open_lines()` are the ledger's (app/features/stock/queries.php — the receipt reads them too); this file adds the rest.
 * A quantity is rendered by app/features/units/queries.php alone; a price per another unit goes through cost_per_unit() (the database's
 * ri_to_base). PHP sums nothing but a page count.
 */
require_once dirname(__DIR__) . '/stock/queries.php';
require_once dirname(__DIR__) . '/catalog/queries.php';

const PO_PAGE = 100;
const PO_STATUS_CHIPS = ['draft' => 'secondary', 'sent' => 'info', 'acknowledged' => 'primary', 'partial' => 'warning', 'received' => 'success', 'closed' => 'success', 'closed_short' => 'warning', 'cancelled' => 'dark'];
const PO_STATUS_WORDS = ['draft' => 'draft', 'sent' => 'sent', 'acknowledged' => 'acknowledged', 'partial' => 'partly received', 'received' => 'received', 'closed' => 'closed', 'closed_short' => 'closed short', 'cancelled' => 'cancelled'];
const PO_LINE_CHIPS = ['open' => 'secondary', 'acknowledged' => 'primary', 'declined' => 'danger', 'partial' => 'warning', 'received' => 'success', 'closed_short' => 'warning', 'cancelled' => 'dark'];
const PO_LINE_WORDS = ['open' => 'open', 'acknowledged' => 'acknowledged', 'declined' => 'declined', 'partial' => 'partly received', 'received' => 'received', 'closed_short' => 'closed short', 'cancelled' => 'cancelled'];
const PO_ORIGINS = ['manual' => 'Manual', 'suggested' => 'Suggested', 'agent' => 'Agent', 'requisition' => 'Requisition'];
const PO_ORIGIN_CHIPS = ['manual' => 'secondary', 'suggested' => 'info', 'agent' => 'primary', 'requisition' => 'warning'];
const PO_EVENT_WORDS = ['sent' => 'Sent by email', 'placed' => 'Placed', 'acknowledge' => 'Acknowledged', 'decline' => 'Declined a line', 'date' => 'Set the delivery date', 'received' => 'Received', 'note' => 'Note', 'cancelled' => 'Cancelled'];
const PO_EVENT_SOURCES = ['door' => 'primary', 'manual' => 'secondary', 'email' => 'info', 'phone' => 'info', 'agent' => 'warning'];
const SENT_VIA_WORDS = ['email' => 'email', 'portal' => 'the supplier\'s portal', 'api' => 'API', 'edi' => 'EDI', 'phone' => 'phone'];
const INVOICE_STATUS_CHIPS = ['draft' => 'secondary', 'matched' => 'success', 'disputed' => 'danger', 'settled' => 'success', 'void' => 'dark'];
const INVOICE_KINDS = ['invoice' => 'Invoice', 'credit' => 'Credit note'];
const MATCH_STATE_WORDS = ['unmatched' => 'not matched yet', 'ok' => 'ok', 'qty_diff' => 'quantity differs', 'price_diff' => 'price differs', 'both_diff' => 'both differ', 'unordered' => 'unordered', 'settled' => 'settled'];
const MATCH_STATE_CHIPS = ['unmatched' => 'secondary', 'ok' => 'success', 'qty_diff' => 'warning', 'price_diff' => 'warning', 'both_diff' => 'danger', 'unordered' => 'danger', 'settled' => 'success'];
/** A PO in these states is still open with the supplier (the door takes the supplier's three answers; a receipt is received against it). */
const PO_OPEN_STATES = ['sent', 'acknowledged', 'partial'];

/** The supplier's next order window at a site as one row {cutoff_at, delivery_on, next_cutoff_at, next_delivery_on, has_schedule}: with no schedule, ri_next_order_windows answers "any day, lead time". */
function supplier_window(PDO $pdo, int $supplier, int $scope): array
{
    $w = next_window($pdo, $supplier, $scope) ?? ['cutoff_at' => null, 'delivery_on' => null, 'next_cutoff_at' => null, 'next_delivery_on' => null];
    $w['has_schedule'] = has_schedule($pdo, $supplier, $scope);
    return $w;
}

// ---------------------------------------------------------------------------------------------- purchase orders
/** The POs at a site, newest first, open ones before closed: [rows, total]. $f: status, supplier, from, to (ordered_on). */
function po_list(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['p.scope_id = :s'];
    $args = ['s' => $scope];
    if (($f['status'] ?? '') !== '' && isset(PO_STATUS_WORDS[$f['status']])) { $where[] = 'p.status = :st'; $args['st'] = $f['status']; }
    if (($f['supplier'] ?? '') !== '' && ctype_digit((string) $f['supplier'])) { $where[] = 'p.supplier_id = :su'; $args['su'] = (int) $f['supplier']; }
    if (($f['from'] ?? '') !== '') { $where[] = 'p.ordered_on >= :f::date'; $args['f'] = $f['from']; }
    if (($f['to'] ?? '') !== '') { $where[] = 'p.ordered_on <= :t::date'; $args['t'] = $f['to']; }
    $base = ' FROM mcp_purchase_orders p JOIN mcp_suppliers s ON s.supplier_id = p.supplier_id WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT count(*)' . $base);
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT p.*, s.name AS supplier, s.order_method,
            (SELECT count(*) FROM mcp_purchase_order_lines l WHERE l.purchase_order_id = p.purchase_order_id) AS line_count,
            (SELECT e.kind FROM mcp_purchase_order_events e WHERE e.purchase_order_id = p.purchase_order_id ORDER BY e.created_at DESC, e.event_id DESC LIMIT 1) AS last_event_kind,
            (SELECT e.source FROM mcp_purchase_order_events e WHERE e.purchase_order_id = p.purchase_order_id ORDER BY e.created_at DESC, e.event_id DESC LIMIT 1) AS last_event_source,
            (SELECT e.created_at FROM mcp_purchase_order_events e WHERE e.purchase_order_id = p.purchase_order_id ORDER BY e.created_at DESC, e.event_id DESC LIMIT 1) AS last_event_at' . $base
        . " ORDER BY (p.status IN ('draft', 'sent', 'acknowledged', 'partial')) DESC, p.ordered_on DESC, p.purchase_order_id DESC LIMIT " . PO_PAGE . ' OFFSET ' . (max(0, $page - 1) * PO_PAGE));
    $st->execute($args);
    return ['rows' => array_map(static fn (array $r): array => stock_ints($r, ['purchase_order_id', 'scope_id', 'supplier_id', 'storage_area_id', 'line_count', 'sent_by', 'created_by']), $st->fetchAll()), 'total' => $total];
}

/** A PO's lines with the ingredient, the SKU, what has come in the purchase unit (the database converts) and the line total (the database multiplies; null when walled). */
function po_lines(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT l.*, i.name AS ingredient, i.code AS ingredient_code, i.base_unit_code, si.supplier_sku AS item_sku,
            CASE WHEN l.unit_cost IS NULL THEN NULL ELSE round(l.qty_ordered * l.unit_cost, 2) END AS line_total
        FROM mcp_purchase_order_lines l JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id LEFT JOIN mcp_supplier_items si ON si.supplier_item_id = l.supplier_item_id
        WHERE l.purchase_order_id = :id ORDER BY l.line_no');
    $st->execute(['id' => $id]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r = stock_ints($r, ['purchase_order_line_id', 'purchase_order_id', 'ingredient_id', 'supplier_item_id', 'line_no']);
        $r['qty_received'] = (float) $r['qty_received_base'] > 0 ? qty_from_base($pdo, $r['ingredient_id'], (string) $r['qty_received_base'], (string) $r['purchase_unit_code']) : '0';
        $r['has_math'] = $r['need_base'] !== null || $r['have_base'] !== null || $r['cap_base'] !== null || $r['raw_base'] !== null || $r['rounding_note'] !== null;
        $rows[] = $r;
    }
    return $rows;
}

/** A PO's events, newest first, with who and the line they name. */
function po_events(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT e.*, m.display_name AS member_name, l.line_no, i.name AS ingredient
        FROM mcp_purchase_order_events e LEFT JOIN mcp_members m ON m.member_id = e.member_id LEFT JOIN mcp_purchase_order_lines l ON l.purchase_order_line_id = e.purchase_order_line_id
        LEFT JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id WHERE e.purchase_order_id = :id ORDER BY e.created_at DESC, e.event_id DESC');
    $st->execute(['id' => $id]);
    return array_map(static fn (array $r): array => stock_ints($r, ['event_id', 'purchase_order_id', 'purchase_order_line_id', 'member_id', 'line_no']), $st->fetchAll());
}

/** The receipts posted (or drafted) against a PO. */
function po_receipts(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT g.goods_receipt_id, g.number, g.status, g.received_on, g.posted_at, a.name AS area, m.display_name AS posted_by_name,
            (SELECT count(*) FROM mcp_goods_receipt_lines l WHERE l.goods_receipt_id = g.goods_receipt_id) AS lines
        FROM mcp_goods_receipts g LEFT JOIN mcp_storage_areas a ON a.storage_area_id = g.storage_area_id LEFT JOIN mcp_members m ON m.member_id = g.posted_by
        WHERE g.purchase_order_id = :id ORDER BY g.received_on DESC, g.goods_receipt_id DESC');
    $st->execute(['id' => $id]);
    return array_map(static fn (array $r): array => stock_ints($r, ['goods_receipt_id', 'lines']), $st->fetchAll());
}

/** The invoices that bill a PO (empty for one who sees no cost: the view is empty). */
function po_invoices(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT invoice_id, number, invoice_no, kind, invoice_date, total, status FROM mcp_supplier_invoices WHERE purchase_order_id = :id ORDER BY invoice_date DESC, invoice_id DESC');
    $st->execute(['id' => $id]);
    return array_map(static fn (array $r): array => stock_ints($r, ['invoice_id']), $st->fetchAll());
}

/** The state of a PO's current link (never the token — only its hash is kept): {created_at, expires_at, last_used_at, view_count, rotations} or null. */
function po_link(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT created_at, expires_at, last_used_at, view_count, (SELECT count(*) FROM supplier_links_secure r WHERE r.purchase_order_id = l.purchase_order_id AND r.rotated_at IS NOT NULL) AS rotations
        FROM supplier_links_secure l WHERE l.purchase_order_id = :id AND l.rotated_at IS NULL ORDER BY l.id DESC LIMIT 1');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The supplier's order guide at the site as rows to tick: its active items not yet on the PO, with the last price (walled by the view) and the purchase unit. */
function guide_rows_for(PDO $pdo, int $supplier, int $scope, int $poId): array
{
    $guide = order_guide($pdo, $supplier, $scope);
    if ($guide === null) { return []; }
    $st = $pdo->prepare('SELECT supplier_item_id FROM mcp_purchase_order_lines WHERE purchase_order_id = :p AND supplier_item_id IS NOT NULL');
    $st->execute(['p' => $poId]);
    $on = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter($guide['lines'], static fn (array $l): bool => $l['active'] && !in_array($l['supplier_item_id'], $on, true)));
}

/** The POs expected on or before a day at the site, with whether a receipt is posted against each: ri_deliveries_due() + the supplier and the status. */
function deliveries_due(PDO $pdo, int $scope, string $on): array
{
    $st = $pdo->prepare('SELECT d.purchase_order_id, d.number, d.supplier_id, d.expected_on, d.received, s.name AS supplier, p.status
        FROM ri_deliveries_due(:s, :on::date) d JOIN mcp_suppliers s ON s.supplier_id = d.supplier_id JOIN mcp_purchase_orders p ON p.purchase_order_id = d.purchase_order_id ORDER BY d.expected_on, d.number');
    $st->execute(['s' => $scope, 'on' => $on]);
    $today = site_today($scope);
    return array_map(static function (array $r) use ($today): array {
        $r = stock_ints($r, ['purchase_order_id', 'supplier_id']);
        $r['received'] = (bool) $r['received'];
        $r['overdue'] = !$r['received'] && $r['expected_on'] < $today;
        return $r;
    }, $st->fetchAll());
}

/** A supplier's POs a person may bill (sent onward, the last 60 days), plus the one already named. */
function pos_for_supplier(PDO $pdo, int $scope, int $supplier, ?int $include = null): array
{
    $st = $pdo->prepare("SELECT purchase_order_id, number, status, ordered_on FROM mcp_purchase_orders WHERE scope_id = :s AND supplier_id = :su
        AND ((status IN ('sent', 'acknowledged', 'partial', 'received', 'closed', 'closed_short') AND ordered_on >= current_date - 60) OR purchase_order_id = :i) ORDER BY ordered_on DESC, purchase_order_id DESC");
    $st->execute(['s' => $scope, 'su' => $supplier, 'i' => $include ?? 0]);
    return array_map(static fn (array $r): array => stock_ints($r, ['purchase_order_id']), $st->fetchAll());
}

/** A supplier's posted receipts at the site in the last 60 days, plus the one already named. */
function receipts_for_supplier(PDO $pdo, int $scope, int $supplier, ?int $include = null): array
{
    $st = $pdo->prepare("SELECT goods_receipt_id, number, received_on, purchase_order_id FROM mcp_goods_receipts WHERE scope_id = :s AND supplier_id = :su
        AND ((status = 'posted' AND received_on >= current_date - 60) OR goods_receipt_id = :i) ORDER BY received_on DESC, goods_receipt_id DESC");
    $st->execute(['s' => $scope, 'su' => $supplier, 'i' => $include ?? 0]);
    return array_map(static fn (array $r): array => stock_ints($r, ['goods_receipt_id', 'purchase_order_id']), $st->fetchAll());
}

// ---------------------------------------------------------------------------------------------- invoices
/** The invoices at a site, newest first: [rows, total]. $f: status, supplier, from, to (invoice_date), kind. The view is empty for one who sees no cost. */
function invoice_list(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['i.scope_id = :s'];
    $args = ['s' => $scope];
    if (($f['status'] ?? '') !== '' && isset(INVOICE_STATUS_CHIPS[$f['status']])) { $where[] = 'i.status = :st'; $args['st'] = $f['status']; }
    if (($f['kind'] ?? '') !== '' && isset(INVOICE_KINDS[$f['kind']])) { $where[] = 'i.kind = :k'; $args['k'] = $f['kind']; }
    if (($f['supplier'] ?? '') !== '' && ctype_digit((string) $f['supplier'])) { $where[] = 'i.supplier_id = :su'; $args['su'] = (int) $f['supplier']; }
    if (($f['from'] ?? '') !== '') { $where[] = 'i.invoice_date >= :f::date'; $args['f'] = $f['from']; }
    if (($f['to'] ?? '') !== '') { $where[] = 'i.invoice_date <= :t::date'; $args['t'] = $f['to']; }
    $base = ' FROM mcp_supplier_invoices i JOIN mcp_suppliers s ON s.supplier_id = i.supplier_id WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT count(*)' . $base);
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT i.*, s.name AS supplier,
            (SELECT count(*) FROM mcp_supplier_invoice_lines l WHERE l.invoice_id = i.invoice_id AND l.match_state IN (\'qty_diff\', \'price_diff\', \'both_diff\', \'unordered\')) AS differences' . $base
        . " ORDER BY (i.status IN ('draft', 'disputed', 'matched')) DESC, i.invoice_date DESC, i.invoice_id DESC LIMIT " . PO_PAGE . ' OFFSET ' . (max(0, $page - 1) * PO_PAGE));
    $st->execute($args);
    return ['rows' => array_map(static fn (array $r): array => stock_ints($r, ['invoice_id', 'scope_id', 'supplier_id', 'purchase_order_id', 'goods_receipt_id', 'differences']), $st->fetchAll()), 'total' => $total];
}

/** One invoice the caller may see (with its supplier, the receipt and PO it bills, who made it), or null. */
function invoice_for(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT i.*, s.name AS supplier, s.match_tolerance_pct, s.match_tolerance_value, g.number AS receipt_number, p.number AS po_number, cm.display_name AS created_by_name, sm.display_name AS settled_by_name
        FROM mcp_supplier_invoices i JOIN mcp_suppliers s ON s.supplier_id = i.supplier_id LEFT JOIN mcp_goods_receipts g ON g.goods_receipt_id = i.goods_receipt_id
        LEFT JOIN mcp_purchase_orders p ON p.purchase_order_id = i.purchase_order_id LEFT JOIN mcp_members cm ON cm.member_id = i.created_by LEFT JOIN mcp_members sm ON sm.member_id = i.settled_by
        WHERE i.invoice_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['invoice_id', 'scope_id', 'supplier_id', 'purchase_order_id', 'goods_receipt_id', 'attachment_id', 'created_by', 'settled_by']);
}

/**
 * An invoice's lines with the receipt line each is matched to: the quantity that came in the INVOICE line's unit and the price it was received at
 * per that unit (both by the database's conversion), and the match's differences in that unit. A walled caller gets no lines (the view is empty).
 */
function invoice_lines(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT l.*, i.name AS ingredient, i.base_unit_code, si.supplier_sku, rl.qty_base AS rl_qty_base, rl.cost_per_base AS rl_cost_per_base, rl.goods_receipt_id AS rl_receipt_id, rl.line_no AS rl_line_no,
            rg.number AS rl_receipt_number,
            CASE WHEN rl.unit_cost IS NOT NULL THEN round(rl.unit_cost * ri_to_base(rl.ingredient_id, 1, l.unit_code) / ri_to_base(rl.ingredient_id, 1, CASE WHEN rl.weight_qty IS NOT NULL THEN rl.weight_unit_code ELSE rl.purchase_unit_code END), 4) END AS received_cost_exact
        FROM mcp_supplier_invoice_lines l LEFT JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id LEFT JOIN mcp_supplier_items si ON si.supplier_item_id = l.supplier_item_id
        LEFT JOIN mcp_goods_receipt_lines rl ON rl.goods_receipt_line_id = l.receipt_line_id LEFT JOIN mcp_goods_receipts rg ON rg.goods_receipt_id = rl.goods_receipt_id
        WHERE l.invoice_id = :id ORDER BY l.line_no');
    $st->execute(['id' => $id]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r = stock_ints($r, ['invoice_line_id', 'invoice_id', 'line_no', 'supplier_item_id', 'ingredient_id', 'receipt_line_id', 'rl_receipt_id']);
        $iid = $r['ingredient_id'];
        $r['received_qty'] = null;
        $r['received_cost'] = null;
        $r['diff_qty_unit'] = null;
        if ($iid !== null && $r['receipt_line_id'] !== null) {
            $r['received_qty'] = qty_from_base($pdo, $iid, (string) $r['rl_qty_base'], (string) $r['unit_code']);
            $r['received_cost'] = $r['received_cost_exact'] ?? cost_per_unit($pdo, $iid, $r['rl_cost_per_base'], (string) $r['unit_code']);       // the price it was received at, in the invoice's unit (the receipt's own, not the six-decimal per-gram figure)
            if ($r['diff_qty'] !== null) { $r['diff_qty_unit'] = qty_from_base($pdo, $iid, (string) $r['diff_qty'], (string) $r['unit_code']); }
        }
        $rows[] = $r;
    }
    return $rows;
}

/** A receipt's lines as an invoice's prefill: the item, the quantity and unit (a catch-weight line at its declared weight and weight unit) and the price it was received at. */
function receipt_lines_for_invoice(PDO $pdo, int $receipt): array
{
    $st = $pdo->prepare('SELECT l.goods_receipt_line_id, l.line_no, l.ingredient_id, l.supplier_item_id, l.qty_received, l.purchase_unit_code, l.weight_qty, l.weight_unit_code, l.qty_base, l.unit_cost, l.cost_per_base,
            i.name AS ingredient, si.supplier_sku
        FROM mcp_goods_receipt_lines l JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id LEFT JOIN mcp_supplier_items si ON si.supplier_item_id = l.supplier_item_id
        WHERE l.goods_receipt_id = :g ORDER BY l.line_no');
    $st->execute(['g' => $receipt]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r = stock_ints($r, ['goods_receipt_line_id', 'line_no', 'ingredient_id', 'supplier_item_id']);
        $weighed = $r['weight_qty'] !== null;
        $r['qty'] = $weighed ? $r['weight_qty'] : $r['qty_received'];
        $r['unit'] = $weighed ? $r['weight_unit_code'] : $r['purchase_unit_code'];
        $r['price'] = $r['unit_cost'] ?? cost_per_unit($pdo, $r['ingredient_id'], $r['cost_per_base'], (string) $r['unit']);
        $rows[] = $r;
    }
    return $rows;
}

/** The credit note that names an invoice's number in its notes, or the invoice a credit note names: the pair, as a list of {invoice_id, number, invoice_no, kind, status, total}. */
function invoice_pair(PDO $pdo, array $inv): array
{
    if ($inv['kind'] === 'invoice') {
        $st = $pdo->prepare("SELECT invoice_id, number, invoice_no, kind, status, total FROM mcp_supplier_invoices WHERE supplier_id = :s AND kind = 'credit' AND position(lower(:no) in lower(COALESCE(notes, ''))) > 0 AND invoice_id <> :id ORDER BY invoice_id");
        $st->execute(['s' => $inv['supplier_id'], 'no' => $inv['invoice_no'], 'id' => $inv['invoice_id']]);
    } else {
        $st = $pdo->prepare("SELECT invoice_id, number, invoice_no, kind, status, total FROM mcp_supplier_invoices WHERE supplier_id = :s AND kind = 'invoice' AND position(lower(invoice_no) in lower(:notes)) > 0 AND invoice_id <> :id ORDER BY invoice_id");
        $st->execute(['s' => $inv['supplier_id'], 'notes' => (string) ($inv['notes'] ?? ''), 'id' => $inv['invoice_id']]);
    }
    return array_map(static fn (array $r): array => stock_ints($r, ['invoice_id']), $st->fetchAll());
}

// ---------------------------------------------------------------------------------------------- price alerts
/**
 * The price alerts of a site (and the ones with no site), unseen first: [rows, total, open]. $f: seen ('0' open, '1' seen, '' all), from (raised on or after).
 * Each row carries the price before and after per the unit it was given in and per the recipe unit (the database converts), the document that raised
 * it and who saw it. The view is empty for one who sees no cost.
 */
function alerts_list(PDO $pdo, int $scope, array $f, int $page): array
{
    $where = ['(a.scope_id = :s OR a.scope_id IS NULL)'];
    $args = ['s' => $scope];
    if (($f['seen'] ?? '') === '0') { $where[] = 'a.seen_at IS NULL'; } elseif (($f['seen'] ?? '') === '1') { $where[] = 'a.seen_at IS NOT NULL'; }
    if (($f['from'] ?? '') !== '') { $where[] = 'a.raised_at >= :f::date'; $args['f'] = $f['from']; }
    $base = ' FROM mcp_price_alerts a JOIN mcp_ingredients i ON i.ingredient_id = a.ingredient_id LEFT JOIN mcp_price_history h ON h.price_history_id = a.price_history_id
              LEFT JOIN mcp_members m ON m.member_id = a.seen_by WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT count(*)' . $base);
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT a.*, i.name AS ingredient, i.code AS ingredient_code, i.base_unit_code, i.recipe_unit_code, h.per_unit_code, h.reference_kind, h.reference_id, h.source_kind, m.display_name AS seen_by_name,
            CASE h.reference_kind WHEN \'goods_receipt\' THEN (SELECT number FROM mcp_goods_receipts WHERE goods_receipt_id = h.reference_id) WHEN \'invoice\' THEN (SELECT number FROM mcp_supplier_invoices WHERE invoice_id = h.reference_id) END AS document,
            CASE h.reference_kind WHEN \'goods_receipt\' THEN (SELECT s.name FROM mcp_goods_receipts g JOIN mcp_suppliers s ON s.supplier_id = g.supplier_id WHERE g.goods_receipt_id = h.reference_id)
                                  WHEN \'invoice\' THEN (SELECT s.name FROM mcp_supplier_invoices v JOIN mcp_suppliers s ON s.supplier_id = v.supplier_id WHERE v.invoice_id = h.reference_id) END AS supplier' . $base
        . ' ORDER BY (a.seen_at IS NULL) DESC, a.raised_at DESC, a.price_alert_id DESC LIMIT ' . PO_PAGE . ' OFFSET ' . (max(0, $page - 1) * PO_PAGE));
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r = stock_ints($r, ['price_alert_id', 'scope_id', 'ingredient_id', 'supplier_item_id', 'price_history_id', 'seen_by', 'reference_id']);
        $iid = $r['ingredient_id'];
        $unit = (string) ($r['per_unit_code'] ?? '');
        $r['unit'] = $unit !== '' ? $unit : $r['base_unit_code'];
        $r['old_per_unit'] = $unit !== '' ? cost_per_unit($pdo, $iid, $r['old_per_base'], $unit) : $r['old_per_base'];
        $r['new_per_unit'] = $unit !== '' ? cost_per_unit($pdo, $iid, $r['new_per_base'], $unit) : $r['new_per_base'];
        $r['old_per_recipe'] = cost_per_unit($pdo, $iid, $r['old_per_base'], (string) $r['recipe_unit_code']);
        $r['new_per_recipe'] = cost_per_unit($pdo, $iid, $r['new_per_base'], (string) $r['recipe_unit_code']);
        $rows[] = $r;
    }
    $open = (int) one_value($pdo, 'SELECT count(*) FROM mcp_price_alerts WHERE seen_at IS NULL AND (scope_id = :s OR scope_id IS NULL)', ['s' => $scope]);
    return ['rows' => $rows, 'total' => $total, 'open' => $open];
}

/** The thresholds: the default % (the setting) and each category's own (null = the default), for the alerts screen's form. */
function thresholds(PDO $pdo): array
{
    $default = (string) (one_value($pdo, 'SELECT price_alert_pct FROM mcp_settings') ?? '10');
    return ['default' => $default, 'categories' => array_map(static fn (array $c): array => ['category_id' => (int) $c['category_id'], 'key' => $c['key'], 'name' => $c['name'], 'price_alert_pct' => $c['price_alert_pct']], categories($pdo))];
}
