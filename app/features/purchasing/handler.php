<?php
declare(strict_types=1);

/**
 * Purchasing's handler prelude (purchasing.md "Handlers"): the gates, the request readers for a purchase order's header and lines and for an
 * invoice's (a form's fields or an agent's `lines` JSON go through the SAME reader, so a rule cannot differ by door), the 404s and po_log().
 * Every handler: ri_handler_begin() → the right at the site → the record through its view → ri_guard() around one transaction → the schema's
 * verb (ri_po_send, ri_po_supplier_event, ri_po_close, ri_invoice_match, ri_invoice_settle …) → log_activity with scope_id and purchase_order_id →
 * ri_done(). A quantity enters only through stock_qty_in() (the database converts), a price per another unit through cost_per_unit(). PHP
 * computes no total, no balance and no cost.
 */
require_once dirname(__DIR__) . '/stock/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/mail.php';

function po_begin(string $right): void
{
    stock_begin($right);
}

/** log_activity() on a purchase order: scope_id (the current site) and purchase_order_id. */
function po_log(PDO $pdo, string $action, int $poId, array $opts = []): void
{
    stock_log($pdo, $action, 'purchase_order', $poId, ['purchase_order_id' => $poId] + $opts);
}

/** Is the caller an agent (a Purchasing agent's draft has origin `agent`)? */
function po_is_agent(): bool
{
    return (current_member()['member_kind'] ?? '') === 'agent';
}

function po_or_404(PDO $pdo, ?int $id): array
{
    return stock_here_or_404($id === null ? null : po_for($pdo, $id), 'That purchase order');
}

/** The request's purchase order — an id or the document's number — at the current site, or 404 / a field error when none is named. */
function po_from_request(PDO $pdo, string $name = 'purchase_order'): array
{
    $v = trim((string) (req_val($name) ?? ''));
    if ($v === '') { ri_refuse_fields([$name => 'Name the purchase order.']); }
    $id = ctype_digit($v) ? (int) $v : (int) (one_value($pdo, 'SELECT purchase_order_id FROM mcp_purchase_orders WHERE lower(number) = lower(:n)', ['n' => $v]) ?: 0);
    return po_or_404($pdo, $id > 0 ? $id : null);
}

/** A PO line (from `line`, an id) and its PO at the current site, or 404. */
function po_line_from_request(PDO $pdo, string $name = 'line'): array
{
    $id = (int) (req_val($name) ?? 0);
    $l = $id > 0 ? q_row($pdo, 'SELECT l.*, i.name AS ingredient FROM mcp_purchase_order_lines l JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id WHERE l.purchase_order_line_id = :id', ['id' => $id]) : null;
    if ($l === null) { respond_not_found('That line is not here.'); }
    return [$l, po_or_404($pdo, (int) $l['purchase_order_id'])];
}

function po_require_draft(array $po): void
{
    if ($po['status'] !== 'draft') {
        refuse(422, 'Purchase order ' . $po['number'] . ' is ' . (PO_STATUS_WORDS[$po['status']] ?? $po['status']) . ' — only a draft changes.');
    }
}

function po_line_count(PDO $pdo, int $poId): int
{
    return (int) one_value($pdo, 'SELECT count(*) FROM mcp_purchase_order_lines WHERE purchase_order_id = :p', ['p' => $poId]);
}

/** A date (YYYY-MM-DD) from raw text: null when empty; an error when not a date. */
function po_date(mixed $raw, string $field, string $label, array &$errors): ?string
{
    $v = is_array($raw) ? '' : trim((string) $raw);
    if ($v === '') { return null; }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if ($dt === false || $dt->format('Y-m-d') !== $v) { $errors[$field] = $label . ' is a date (YYYY-MM-DD).'; return null; }
    return $v;
}

// ------------------------------------------------------------------------------------------------ the header
/**
 * A PO's header from the request (create: $cur null). A field left out stays as it was. The supplier is fixed once a line exists; the expected
 * date is the supplier's next delivery when a new PO names none and is never before today (a date already on the draft may stay).
 */
function po_header_from_request(PDO $pdo, ?array $cur, int $scope, array &$errors, bool $hasLines): array
{
    $f = ['supplier_id' => $cur['supplier_id'] ?? null, 'supplier' => $cur['supplier'] ?? null, 'storage_area_id' => $cur['storage_area_id'] ?? null, 'expected_on' => $cur['expected_on'] ?? null,
          'notes' => $cur['notes'] ?? null, 'internal_notes' => $cur['internal_notes'] ?? null, 'cutoff_at' => $cur['cutoff_at'] ?? null];
    if ($cur === null || req_has('supplier')) {
        $v = (string) (req_val('supplier') ?? '');
        $s = $v === '' ? null : supplier_by_ref($pdo, $v);
        if ($v === '') { if ($cur === null) { $errors['supplier'] = 'Choose the supplier.'; } }
        elseif ($s === null) { $errors['supplier'] = 'That supplier is not here.'; }
        elseif (!$s['active']) { $errors['supplier'] = $s['name'] . ' is archived — restore it to order from it.'; }
        elseif ($cur !== null && $s['supplier_id'] !== (int) $cur['supplier_id'] && $hasLines) { $errors['supplier'] = 'Remove the lines first — a purchase order is to one supplier.'; }
        else { $f['supplier_id'] = $s['supplier_id']; $f['supplier'] = $s['name']; }
    }
    if (req_has('area') || ($cur === null && req_has('storage_area'))) {
        $a = trim((string) (req_val('area') ?? req_val('storage_area') ?? ''));
        $f['storage_area_id'] = $a === '' ? null : stock_area_ref($pdo, $a, $scope, 'area', 'The area', $errors, false);
    }
    $today = site_today($scope);
    if (req_has('expected_on')) {
        $d = po_date(req_val('expected_on'), 'expected_on', 'The delivery date', $errors);
        if ($d !== null && $d < $today && $d !== ($cur['expected_on'] ?? null)) { $errors['expected_on'] = 'The delivery date is not before today.'; }
        elseif (!isset($errors['expected_on'])) { $f['expected_on'] = $d; }
    }
    if ($f['supplier_id'] !== null && ($cur === null || $f['supplier_id'] !== (int) $cur['supplier_id'])) {       // a new PO, or a draft moved to another supplier: its next delivery, the cut-off it was drafted for
        $w = supplier_window($pdo, (int) $f['supplier_id'], $scope);
        if ($f['expected_on'] === null) { $f['expected_on'] = $w['delivery_on']; }
        $f['cutoff_at'] = $w['has_schedule'] ? $w['cutoff_at'] : null;
    }
    $f['notes'] = ri_text('notes', $f['notes'], 2000, 'The note to the supplier', $errors);
    $in = ri_text('internal_notes', $f['internal_notes'], 2000, 'The internal note', $errors);
    if ($in !== $f['internal_notes'] && !sees_cost($scope)) { $errors['internal_notes'] = 'The internal note is cost-side, which your role does not see here.'; } else { $f['internal_notes'] = $in; }
    return $f;
}

// ------------------------------------------------------------------------------------------------ the lines
/** The price per purchase unit a line lands at when none is given: the supplier item's contract (in force) or last price, converted by the database when the unit is another; else the ingredient's running cost. null when walled or unknown. */
function po_default_unit_cost(PDO $pdo, ?array $si, int $iid, string $unit, int $scope): ?string
{
    if (!sees_cost($scope)) { return null; }
    if ($si !== null) {
        $price = $si['contract_price'] !== null && ($si['contract_until'] === null || $si['contract_until'] >= site_today($scope)) ? $si['contract_price'] : $si['last_price'];
        if ($price !== null && $unit === $si['purchase_unit_code']) { return number_format((float) $price, 4, '.', ''); }
        $perBase = one_value($pdo, 'SELECT ri_supplier_item_cost_per_base(:id)', ['id' => $si['supplier_item_id']]);
        if ($perBase !== null) { return cost_per_unit($pdo, $iid, (string) $perBase, $unit); }
    }
    $c = ingredient_cost($pdo, $iid, $scope);
    return $c === null ? null : cost_per_unit($pdo, $iid, $c['per_base'], $unit);
}

/**
 * One PO line from raw values (a form's fields or one object of `lines`): supplier_item (id, or SKU among THIS supplier's) | ingredient, qty + unit,
 * unit_cost per purchase unit, expected_on. $cur is the line being changed (what is left out stays). $po: scope_id, supplier_id, supplier. The unit
 * the person gave is the unit the line keeps (the supplier reads that one); the database converts it to base. $p prefixes the error keys.
 */
function po_line_fields(PDO $pdo, array $in, array $po, ?array $cur, array &$errors, string $p = ''): ?array
{
    $scope = (int) $po['scope_id'];
    $si = null;
    $siRaw = trim((string) ($in['supplier_item'] ?? ''));
    $ingRaw = trim((string) ($in['ingredient'] ?? ''));
    if ($siRaw !== '') {
        $sid = ctype_digit($siRaw) ? (int) $siRaw : (int) (one_value($pdo, 'SELECT supplier_item_id FROM mcp_supplier_items WHERE supplier_id = :su AND lower(supplier_sku) = lower(:v) ORDER BY active DESC LIMIT 1', ['su' => $po['supplier_id'], 'v' => $siRaw]) ?: 0);
        $si = $sid > 0 ? find_supplier_item($pdo, $sid) : null;
        if ($si === null) { $errors[$p . 'supplier_item'] = 'That supplier item is not here.'; return null; }
        if ($si['supplier_id'] !== (int) $po['supplier_id']) { $errors[$p . 'supplier_item'] = 'That item is ' . $si['supplier'] . '\'s, not ' . $po['supplier'] . '\'s — a purchase order is to one supplier.'; return null; }
        if (!$si['active']) { $errors[$p . 'supplier_item'] = 'That supplier item is retired.'; return null; }
    } elseif ($ingRaw === '' && $cur !== null && $cur['supplier_item_id'] !== null) {
        $si = find_supplier_item($pdo, (int) $cur['supplier_item_id']);
    }
    $ing = null;
    if ($ingRaw !== '') {
        $ing = ingredient_by_ref($pdo, $ingRaw);
        if ($ing === null) { $errors[$p . 'ingredient'] = 'That ingredient is not here.'; return null; }
    } elseif ($si !== null) {
        $ing = find_ingredient($pdo, $si['ingredient_id']);
    } elseif ($cur !== null) {
        $ing = find_ingredient($pdo, (int) $cur['ingredient_id']);
    }
    if ($ing === null) { $errors[$p . 'supplier_item'] = 'Choose the supplier item (or the ingredient).'; return null; }
    $iid = (int) $ing['ingredient_id'];
    if ($si !== null && $si['ingredient_id'] !== $iid) { $errors[$p . 'supplier_item'] = 'That item is ' . $si['ingredient'] . ', not ' . $ing['name'] . '.'; return null; }
    $unit = trim((string) ($in['unit'] ?? ''));
    if ($unit === '') {
        $sameItem = $cur !== null && (int) ($cur['supplier_item_id'] ?? 0) === (int) ($si['supplier_item_id'] ?? 0) && (int) $cur['ingredient_id'] === $iid;
        if ($sameItem) { $unit = (string) $cur['purchase_unit_code']; }
        elseif ($si !== null) { $unit = (string) $si['purchase_unit_code']; }
        else {
            foreach (unit_options_for($pdo, $iid, 'receive') as $o) { if ($o['kind'] === 'purchase') { $unit = (string) $o['code']; break; } }
            if ($unit === '') { $errors[$p . 'ingredient'] = $ing['name'] . ' has no purchase unit — add one on the ingredient.'; return null; }
        }
    }
    $qtyRaw = $in['qty'] ?? ($cur !== null && !array_key_exists('qty', $in) ? $cur['qty_ordered'] : null);
    $e = [];
    $q = stock_qty_in($pdo, $iid, $qtyRaw, $unit, $p . 'qty', 'The quantity', $e);
    foreach ($e as $k => $msg) { $errors[$k === $p . 'qty_unit' ? $p . 'unit' : $k] = $msg; }
    $out = ['ingredient_id' => $iid, 'ingredient' => $ing['name'], 'supplier_item_id' => $si['supplier_item_id'] ?? null, 'supplier_sku' => $si['supplier_sku'] ?? null, 'unit' => $unit,
            'qty' => $q['qty'] ?? null, 'qty_base' => $q['qty_base'] ?? null, 'unit_cost' => null, 'expected_on' => $cur['expected_on'] ?? null];
    $cost = $in['unit_cost'] ?? null;
    if ($cost !== null && !is_array($cost) && trim((string) $cost) !== '') {
        if (!sees_cost($scope)) { $errors[$p . 'unit_cost'] = 'The cost is not shown to you here, so it is not entered by you: leave it blank.'; }
        else { $out['unit_cost'] = stock_number($cost, $p . 'unit_cost', 'The cost', $errors, false, 0); }
    } elseif ($cur !== null && $cur['unit_cost'] !== null && (int) ($cur['supplier_item_id'] ?? 0) === (int) ($si['supplier_item_id'] ?? 0) && (string) $cur['purchase_unit_code'] === $unit) {
        $out['unit_cost'] = (string) $cur['unit_cost'];                                           // an edit that sends no cost keeps the one it had
    } else {
        $out['unit_cost'] = po_default_unit_cost($pdo, $si, $iid, $unit, $scope);
    }
    if (array_key_exists('expected_on', $in)) { $out['expected_on'] = po_date($in['expected_on'], $p . 'expected_on', 'The delivery date', $errors); }
    return $out;
}

/** Insert or update one PO line (the trigger converts the base quantity and the totals follow); returns its id. */
function save_po_line(PDO $pdo, int $poId, ?int $lineId, array $l): int
{
    $cols = ['ingredient_id' => $l['ingredient_id'], 'supplier_item_id' => $l['supplier_item_id'], 'supplier_sku' => $l['supplier_sku'], 'purchase_unit_code' => $l['unit'], 'qty_ordered' => $l['qty'],
             'unit_cost' => $l['unit_cost'] ?? '0', 'expected_on' => $l['expected_on']];
    if ($lineId === null) {
        $no = (int) one_value($pdo, 'SELECT COALESCE(max(line_no), 0) + 1 FROM purchase_order_lines WHERE purchase_order_id = :p', ['p' => $poId]);
        $st = $pdo->prepare('INSERT INTO purchase_order_lines (purchase_order_id, line_no, qty_ordered_base, ' . implode(', ', array_keys($cols)) . ') VALUES (:p, :n, 0, :' . implode(', :', array_keys($cols)) . ') RETURNING id');
        $st->execute(['p' => $poId, 'n' => $no] + $cols);
        return (int) $st->fetchColumn();
    }
    $set = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($cols)));
    $pdo->prepare('UPDATE purchase_order_lines SET ' . $set . ' WHERE id = :id')->execute($cols + ['id' => $lineId]);
    return $lineId;
}

/**
 * The lines a header request brings: the `lines` JSON (an agent) and the ticked order-guide rows (`guide[]` supplier-item ids, `guide_qty[id]` in the item's purchase unit).
 * Returns raw line inputs for po_line_fields().
 */
function po_lines_in(PDO $pdo, array &$errors): array
{
    $in = stock_json_lines($errors);
    $guide = $_POST['guide'] ?? [];
    if (is_array($guide)) {
        foreach ($guide as $sid) {
            $qty = $_POST['guide_qty'][(int) $sid] ?? '';
            if (is_array($qty) || trim((string) $qty) === '') { continue; }                      // a ticked row with no quantity is not a line
            $in[] = ['supplier_item' => (string) (int) $sid, 'qty' => $qty];
        }
    }
    return $in;
}

/** A PO's loggable lines: ingredient, quantity with its unit, the cost only for one who sees it. */
function po_loggable_lines(array $lines, bool $sees): array
{
    return array_map(static function (array $l) use ($sees): array {
        $o = ['ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty_ordered'], 'unit' => $l['purchase_unit_code'], 'qty_base' => $l['qty_ordered_base']];
        if ($sees && $l['unit_cost'] !== null) { $o['unit_cost'] = $l['unit_cost']; }
        return $o;
    }, $lines);
}

// ------------------------------------------------------------------------------------------------ notifications and mail
/** Tell the PO's creator (and the one who sent it, when another) — the bell and the mail copy through ri_notify(). */
function po_notify(PDO $pdo, array $po, string $kind, string $title, ?string $body): int
{
    $n = 0;
    foreach (array_unique(array_filter([(int) ($po['created_by'] ?? 0), (int) ($po['sent_by'] ?? 0)])) as $m) {
        $pdo->prepare('SELECT ri_notify(:m, :k, \'purchase_order\', :id, :t, :b, NULL, false, :s)')->execute(['m' => $m, 'k' => $kind, 'id' => $po['purchase_order_id'], 't' => mb_substr($title, 0, 200), 'b' => $body, 's' => $po['scope_id']]);
        $n++;
    }
    return $n;
}

/** Keep a raw link for the person's next page (shown once on the PO); an agent's session is destroyed, so it only ever gets the last four. */
function po_link_once_set(int $poId, string $raw): void
{
    $_SESSION['po_link_once'] = [$poId => $raw];
}

function po_link_once_take(int $poId): ?string
{
    $raw = $_SESSION['po_link_once'][$poId] ?? null;
    unset($_SESSION['po_link_once']);
    return is_string($raw) ? $raw : null;
}

/** The site as the supplier is told of it: name, address, zone (mcp_sites). */
function po_site_row(PDO $pdo, int $scope): array
{
    return q_row($pdo, 'SELECT scope_id, name, address, timezone FROM mcp_sites WHERE scope_id = :s', ['s' => $scope]) ?? ['scope_id' => $scope, 'name' => '', 'address' => null, 'timezone' => 'UTC'];
}

/** The lines of a PO for the supplier's email (no cost): ingredient, SKU, quantity in the purchase unit. */
function po_email_lines(PDO $pdo, int $poId): array
{
    $st = $pdo->prepare('SELECT l.line_no, l.supplier_sku, l.purchase_unit_code, l.qty_ordered, i.name AS ingredient FROM mcp_purchase_order_lines l JOIN mcp_ingredients i ON i.ingredient_id = l.ingredient_id
        WHERE l.purchase_order_id = :p AND l.status <> \'declined\' ORDER BY l.line_no');
    $st->execute(['p' => $poId]);
    return $st->fetchAll();
}

// ------------------------------------------------------------------------------------------------ invoices
function invoice_or_404(PDO $pdo, ?int $id): array
{
    return stock_here_or_404($id === null ? null : invoice_for($pdo, $id), 'That invoice');
}

/** The request's invoice — an id or our number — at the current site, or 404. */
function invoice_from_request(PDO $pdo, string $name = 'invoice'): array
{
    $v = trim((string) (req_val($name) ?? ''));
    if ($v === '') { ri_refuse_fields([$name => 'Name the invoice.']); }
    $id = ctype_digit($v) ? (int) $v : (int) (one_value($pdo, 'SELECT invoice_id FROM mcp_supplier_invoices WHERE lower(number) = lower(:n)', ['n' => $v]) ?: 0);
    return invoice_or_404($pdo, $id > 0 ? $id : null);
}

function invoice_log(PDO $pdo, string $action, array $inv, array $opts = []): void
{
    stock_log($pdo, $action, 'supplier_invoice', (int) $inv['invoice_id'], ['purchase_order_id' => $inv['purchase_order_id'] ?? null] + $opts);
}

/** An invoice that can still be edited: a draft or a disputed one. */
function invoice_require_editable(array $inv): void
{
    if (!in_array($inv['status'], ['draft', 'disputed'], true)) {
        refuse(422, 'Invoice ' . $inv['number'] . ' is ' . $inv['status'] . ' — only a draft or a disputed invoice changes.');
    }
}

/** An invoice header from the request (create: $cur null); a field left out stays as it was. */
function invoice_header_from_request(PDO $pdo, ?array $cur, int $scope, array &$errors, bool $hasLines): array
{
    $f = ['supplier_id' => $cur['supplier_id'] ?? null, 'supplier' => $cur['supplier'] ?? null, 'invoice_no' => $cur['invoice_no'] ?? null, 'kind' => $cur['kind'] ?? 'invoice', 'invoice_date' => $cur['invoice_date'] ?? null,
          'received_on' => $cur['received_on'] ?? site_today($scope), 'goods_receipt_id' => $cur['goods_receipt_id'] ?? null, 'purchase_order_id' => $cur['purchase_order_id'] ?? null, 'tax' => $cur['tax'] ?? '0', 'notes' => $cur['notes'] ?? null];
    if ($cur === null || req_has('supplier')) {
        $v = (string) (req_val('supplier') ?? '');
        $s = $v === '' ? null : supplier_by_ref($pdo, $v);
        if ($v === '') { if ($cur === null) { $errors['supplier'] = 'Choose the supplier.'; } }
        elseif ($s === null) { $errors['supplier'] = 'That supplier is not here.'; }
        elseif ($cur !== null && $s['supplier_id'] !== (int) $cur['supplier_id'] && $hasLines) { $errors['supplier'] = 'Remove the lines first — an invoice is from one supplier.'; }
        else { $f['supplier_id'] = $s['supplier_id']; $f['supplier'] = $s['name']; }
    }
    $f['invoice_no'] = ri_text('invoice_no', $f['invoice_no'], 60, 'The invoice number', $errors, $cur === null);
    if ($cur === null && ($f['invoice_no'] === null || $f['invoice_no'] === '') && !isset($errors['invoice_no'])) { $errors['invoice_no'] = 'The invoice number is required.'; }
    $f['kind'] = ri_choice('kind', $f['kind'], array_keys(INVOICE_KINDS), 'The kind', $errors, false) ?? 'invoice';
    if (req_has('invoice_date')) {
        $d = po_date(req_val('invoice_date'), 'invoice_date', 'The invoice date', $errors);
        if ($d === null && !isset($errors['invoice_date'])) { $errors['invoice_date'] = 'The invoice date is required.'; } else { $f['invoice_date'] = $d ?? $f['invoice_date']; }
    } elseif ($cur === null) { $errors['invoice_date'] = 'The invoice date is required.'; }
    if (req_has('received_on')) {
        $d = po_date(req_val('received_on'), 'received_on', 'Received on', $errors);
        $f['received_on'] = $d ?? site_today($scope);
    }
    if (req_has('receipt')) {
        $v = trim((string) req_val('receipt'));
        if ($v === '') { $f['goods_receipt_id'] = null; }
        else {
            $g = ctype_digit($v) ? receipt_for($pdo, (int) $v) : null;
            if ($g === null && !ctype_digit($v)) {
                $gid = (int) (one_value($pdo, 'SELECT goods_receipt_id FROM mcp_goods_receipts WHERE lower(number) = lower(:n)', ['n' => $v]) ?: 0);
                $g = $gid > 0 ? receipt_for($pdo, $gid) : null;
            }
            if ($g === null || $g['scope_id'] !== $scope) { $errors['receipt'] = 'That receipt is not here.'; }
            elseif ($g['status'] !== 'posted') { $errors['receipt'] = 'Receipt ' . $g['number'] . ' is ' . $g['status'] . ' — an invoice bills a posted receipt.'; }
            elseif ($f['supplier_id'] !== null && $g['supplier_id'] !== null && $g['supplier_id'] !== (int) $f['supplier_id']) { $errors['receipt'] = 'Receipt ' . $g['number'] . ' is from ' . $g['supplier'] . ', not this invoice\'s supplier.'; }
            else {
                $f['goods_receipt_id'] = $g['goods_receipt_id'];
                if ($f['supplier_id'] === null && $g['supplier_id'] !== null) { $f['supplier_id'] = $g['supplier_id']; $f['supplier'] = $g['supplier']; }
                if (!req_has('purchase_order') && $g['purchase_order_id'] !== null) { $f['purchase_order_id'] = $g['purchase_order_id']; }
            }
        }
    }
    if (req_has('purchase_order')) {
        $v = trim((string) req_val('purchase_order'));
        if ($v === '') { $f['purchase_order_id'] = null; }
        else {
            $poId = ctype_digit($v) ? (int) $v : (int) (one_value($pdo, 'SELECT purchase_order_id FROM mcp_purchase_orders WHERE lower(number) = lower(:n)', ['n' => $v]) ?: 0);
            $po = $poId > 0 ? po_for($pdo, $poId) : null;
            if ($po === null || $po['scope_id'] !== $scope) { $errors['purchase_order'] = 'That purchase order is not here.'; }
            elseif ($po['status'] === 'draft' || $po['status'] === 'cancelled') { $errors['purchase_order'] = 'Purchase order ' . $po['number'] . ' is ' . $po['status'] . ' — an invoice bills one that was sent.'; }
            elseif ($f['supplier_id'] !== null && $po['supplier_id'] !== (int) $f['supplier_id']) { $errors['purchase_order'] = 'Purchase order ' . $po['number'] . ' is to ' . $po['supplier'] . ', not this invoice\'s supplier.'; }
            else { $f['purchase_order_id'] = $po['purchase_order_id']; }
        }
    }
    if (req_has('tax')) {
        $t = stock_number(req_val('tax'), 'tax', 'The tax', $errors, false, 0);
        $f['tax'] = $t ?? '0';
    }
    $f['notes'] = ri_text('notes', $f['notes'], 2000, 'Notes', $errors);
    // the UNIQUE's sentence, said before the database says it: a supplier's number is its own per kind
    if ($f['supplier_id'] !== null && $f['invoice_no'] !== null && $f['invoice_no'] !== '' && !isset($errors['invoice_no'])) {
        $dup = one_value($pdo, 'SELECT invoice_id FROM mcp_supplier_invoices WHERE supplier_id = :s AND invoice_no = :n AND kind = :k' . ($cur === null ? '' : ' AND invoice_id <> :id'),
            ['s' => $f['supplier_id'], 'n' => $f['invoice_no'], 'k' => $f['kind']] + ($cur === null ? [] : ['id' => $cur['invoice_id']]));
        if ($dup !== null) { $errors['invoice_no'] = ($f['supplier'] ?? 'That supplier') . ' already has ' . ($f['kind'] === 'credit' ? 'a credit note ' : 'an invoice ') . $f['invoice_no'] . '.'; }
    }
    return $f;
}

/**
 * One invoice line from raw values (a form's fields or one object of `lines`): supplier_item | ingredient (both optional — a line may be only a description),
 * description, qty + unit, unit_price per that unit, receipt_line (a line of the receipt the invoice bills, or of any posted receipt of this supplier at the site).
 */
function invoice_line_fields(PDO $pdo, array $in, array $inv, ?array $cur, array &$errors, string $p = ''): ?array
{
    $scope = (int) $inv['scope_id'];
    $si = null;
    $siRaw = trim((string) ($in['supplier_item'] ?? ''));
    $ingRaw = trim((string) ($in['ingredient'] ?? ''));
    if ($siRaw !== '') {
        $sid = ctype_digit($siRaw) ? (int) $siRaw : (int) (one_value($pdo, 'SELECT supplier_item_id FROM mcp_supplier_items WHERE supplier_id = :su AND lower(supplier_sku) = lower(:v) ORDER BY active DESC LIMIT 1', ['su' => $inv['supplier_id'], 'v' => $siRaw]) ?: 0);
        $si = $sid > 0 ? find_supplier_item($pdo, $sid) : null;
        if ($si === null) { $errors[$p . 'supplier_item'] = 'That supplier item is not here.'; return null; }
        if ($si['supplier_id'] !== (int) $inv['supplier_id']) { $errors[$p . 'supplier_item'] = 'That item is ' . $si['supplier'] . '\'s, not ' . $inv['supplier'] . '\'s.'; return null; }
    } elseif ($ingRaw === '' && $cur !== null && $cur['supplier_item_id'] !== null && !array_key_exists('supplier_item', $in)) {
        $si = find_supplier_item($pdo, (int) $cur['supplier_item_id']);
    }
    $ing = null;
    if ($ingRaw !== '') {
        $ing = ingredient_by_ref($pdo, $ingRaw);
        if ($ing === null) { $errors[$p . 'ingredient'] = 'That ingredient is not here.'; return null; }
    } elseif ($si !== null) {
        $ing = find_ingredient($pdo, $si['ingredient_id']);
    } elseif ($cur !== null && $cur['ingredient_id'] !== null && !array_key_exists('ingredient', $in) && !array_key_exists('supplier_item', $in)) {
        $ing = find_ingredient($pdo, (int) $cur['ingredient_id']);
    }
    $iid = $ing === null ? null : (int) $ing['ingredient_id'];
    if ($si !== null && $iid !== null && $si['ingredient_id'] !== $iid) { $errors[$p . 'supplier_item'] = 'That item is ' . $si['ingredient'] . ', not ' . $ing['name'] . '.'; return null; }
    $unit = trim((string) ($in['unit'] ?? ''));
    if ($unit === '') { $unit = $cur !== null && $iid === ($cur['ingredient_id'] ?? null) ? (string) $cur['unit_code'] : (string) ($si['purchase_unit_code'] ?? ''); }
    if ($unit === '') { $errors[$p . 'unit'] = 'Say the unit.'; }
    $qtyRaw = $in['qty'] ?? ($cur !== null && !array_key_exists('qty', $in) ? $cur['qty'] : null);
    $qty = null;
    if ($iid !== null && $unit !== '') {
        $e = [];
        $q = stock_qty_in($pdo, $iid, $qtyRaw, $unit, $p . 'qty', 'The quantity', $e);
        foreach ($e as $k => $msg) { $errors[$k === $p . 'qty_unit' ? $p . 'unit' : $k] = $msg; }
        $qty = $q['qty'] ?? null;
    } else {
        $qty = stock_number($qtyRaw, $p . 'qty', 'The quantity', $errors, true);
        if ($qty === null && !isset($errors[$p . 'qty'])) { $errors[$p . 'qty'] = 'The quantity is required.'; }
    }
    $priceRaw = $in['unit_price'] ?? ($cur !== null && !array_key_exists('unit_price', $in) ? $cur['unit_price'] : null);
    $price = stock_number($priceRaw, $p . 'unit_price', 'The unit price', $errors, false, 0);
    if ($price === null && !isset($errors[$p . 'unit_price'])) { $errors[$p . 'unit_price'] = 'The unit price is required.'; }
    $desc = array_key_exists('description', $in) ? trim((string) $in['description']) : (string) ($cur['description'] ?? '');
    if (mb_strlen($desc) > 300) { $errors[$p . 'description'] = 'The description is up to 300 characters.'; }
    if ($desc === '' && $ing !== null) { $desc = $ing['name']; }
    if ($desc === '' && $iid === null) { $errors[$p . 'description'] = 'Describe the line, or choose the supplier item.'; }
    $rl = null;
    $rlRaw = array_key_exists('receipt_line', $in) ? trim((string) $in['receipt_line']) : ($cur !== null ? (string) ($cur['receipt_line_id'] ?? '') : '');
    if ($rlRaw !== '') {
        $row = ctype_digit($rlRaw) ? q_row($pdo, 'SELECT l.goods_receipt_line_id, l.ingredient_id, g.supplier_id, g.scope_id, g.status, g.goods_receipt_id FROM mcp_goods_receipt_lines l JOIN mcp_goods_receipts g ON g.goods_receipt_id = l.goods_receipt_id WHERE l.goods_receipt_line_id = :id', ['id' => (int) $rlRaw]) : null;
        if ($row === null || (int) $row['scope_id'] !== $scope || (int) $row['supplier_id'] !== (int) $inv['supplier_id'] || $row['status'] !== 'posted') { $errors[$p . 'receipt_line'] = 'That is not a line of a posted receipt from this supplier.'; }
        elseif ($inv['goods_receipt_id'] !== null && (int) $row['goods_receipt_id'] !== (int) $inv['goods_receipt_id']) { $errors[$p . 'receipt_line'] = 'That line is not on the receipt this invoice bills.'; }
        elseif ($iid !== null && (int) $row['ingredient_id'] !== $iid) { $errors[$p . 'receipt_line'] = 'That receipt line is for another ingredient.'; }
        else { $rl = (int) $rlRaw; }
    }
    return ['supplier_item_id' => $si['supplier_item_id'] ?? null, 'ingredient_id' => $iid, 'description' => $desc === '' ? null : $desc, 'qty' => $qty, 'unit' => $unit, 'unit_price' => $price, 'receipt_line_id' => $rl];
}

function save_invoice_line(PDO $pdo, int $invoiceId, ?int $lineId, array $l): int
{
    $cols = ['supplier_item_id' => $l['supplier_item_id'], 'ingredient_id' => $l['ingredient_id'], 'description' => $l['description'], 'qty' => $l['qty'], 'unit_code' => $l['unit'], 'unit_price' => $l['unit_price'], 'receipt_line_id' => $l['receipt_line_id']];
    if ($lineId === null) {
        $no = (int) one_value($pdo, 'SELECT COALESCE(max(line_no), 0) + 1 FROM supplier_invoice_lines WHERE invoice_id = :i', ['i' => $invoiceId]);
        $st = $pdo->prepare('INSERT INTO supplier_invoice_lines (invoice_id, line_no, ' . implode(', ', array_keys($cols)) . ') VALUES (:i, :n, :' . implode(', :', array_keys($cols)) . ') RETURNING id');
        $st->execute(['i' => $invoiceId, 'n' => $no] + $cols);
        return (int) $st->fetchColumn();
    }
    $set = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($cols)));
    // a changed line is no longer matched: its state goes back to "not matched yet" until the next Match
    $pdo->prepare('UPDATE supplier_invoice_lines SET ' . $set . ", match_state = 'unmatched', diff_qty = NULL, diff_value = NULL WHERE id = :id")->execute($cols + ['id' => $lineId]);
    return $lineId;
}

/** An invoice's loggable lines: ingredient, quantity with its unit, price — whole-screen cost, so these are only ever written by a cost-seer. */
function invoice_loggable_lines(array $lines): array
{
    return array_map(static fn (array $l): array => ['ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty'], 'unit' => $l['unit_code'], 'unit_price' => $l['unit_price'], 'receipt_line_id' => $l['receipt_line_id']], $lines);
}
