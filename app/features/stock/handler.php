<?php
declare(strict_types=1);

/**
 * The ledger's handler prelude (ledger.md "Handlers"): the gates, the request readers for every document's header and lines (a form's fields
 * or an agent's `lines` JSON go through the SAME reader, so a rule cannot differ by door), the 404s and stock_log(). Every handler:
 * ri_handler_begin() → the right at the site → the record through its view → ri_guard() around one transaction → the schema's posting verb
 * → log_activity with scope_id (+ ingredient_id on a line, purchase_order_id on a receipt against a PO) → ri_done().
 * A quantity enters only through qty_to_base() (the database converts; its refusal is the unit's field error) — catalog's qty_with_unit()
 * for a form field, stock_qty_in() for a JSON line. PHP computes no balance and no cost.
 */
require_once dirname(__DIR__) . '/catalog/handler.php';
require_once dirname(__DIR__) . '/recipes/queries.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';

function stock_begin(string $right): void
{
    ri_handler_begin();
    stock_refuse_eval();
    require_right($right);
}

/**
 * An evaluation run writes nothing (the action manifest: "is_eval refuses every write"). An agent's run token is asked of the kernel once per
 * request (run_facts() caches); the kit asks only at an agent's first admission, so the ledger asks here for each of its writes. Unreachable
 * kernel → refused, as every caller of run_facts() fails closed.
 */
function stock_refuse_eval(): void
{
    if (!is_action_authed()) { return; }
    $tok = (string) ($_SERVER['HTTP_X_ACTION_TOKEN'] ?? '');
    if ($tok === '' || action_token_run_id($tok) === null) { return; }
    $f = run_facts($tok);
    if ($f === null || empty($f['valid'])) { refuse(401, 'The kernel does not vouch for this run — nothing was written.'); }
    if (!empty($f['is_eval'])) { refuse(403, 'An evaluation run writes nothing — this action was not taken.'); }
}

function stock_log(PDO $pdo, string $action, string $entityType, int $entityId, array $opts = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, $opts + ['scope_id' => current_site_id()]);
}

/** The site's own today (its zone). */
function site_today(?int $scope = null): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(site_timezone($scope))))->format('Y-m-d');
}

/** A number from a raw value: null when empty; an error when not a number within bounds. $positive: > 0. */
function stock_number(mixed $raw, string $field, string $label, array &$errors, bool $positive = false, ?float $min = null, ?float $max = null, bool $signedNonZero = false): ?string
{
    $v = is_array($raw) ? '' : str_replace([',', ' '], '', trim((string) $raw));
    if ($v === '') { return null; }
    if (!is_numeric($v)) { $errors[$field] = $label . ' is a number.'; return null; }
    $f = (float) $v;
    if ($positive && $f <= 0) { $errors[$field] = $label . ' is more than zero.'; return null; }
    if ($signedNonZero && $f == 0.0) { $errors[$field] = $label . ' is never zero — a plus or a minus.'; return null; }
    if ($min !== null && $f < $min) { $errors[$field] = $label . ' is ' . fmt_num($min) . ' or more.'; return null; }
    if ($max !== null && $f > $max) { $errors[$field] = $label . ' is up to ' . fmt_num($max) . '.'; return null; }
    return number_format($f, 4, '.', '');
}

/** A quantity in a unit for an ingredient (a JSON line or a form field): [qty, unit, qty_base] or null with the error on $field / {$field}_unit. */
function stock_qty_in(PDO $pdo, int $ingredientId, mixed $qty, mixed $unit, string $field, string $label, array &$errors, bool $required = true, bool $signed = false): ?array
{
    $q = stock_number($qty, $field, $label, $errors, !$signed, null, null, $signed);
    if ($q === null) {
        if ($required && !isset($errors[$field])) { $errors[$field] = $label . ' is required.'; }
        return null;
    }
    $u = trim((string) ($unit ?? ''));
    if ($u === '') { $errors[$field . '_unit'] = 'Say the unit of ' . lcfirst($label) . '.'; return null; }
    try {
        $base = qty_to_base($pdo, $ingredientId, number_format(abs((float) $q), 4, '.', ''), $u);
    } catch (PDOException $e) {
        $errors[$field . '_unit'] = db_message($e, 'That unit cannot be converted.');
        return null;
    }
    return ['qty' => $q, 'unit' => $u, 'qty_base' => $signed && (float) $q < 0 ? '-' . $base : $base];
}

/** yes/no from a raw value; null when absent or empty. */
function stock_yes(mixed $raw): ?bool
{
    if ($raw === null || is_array($raw)) { return null; }
    $v = strtolower(trim((string) $raw));
    if ($v === '') { return null; }
    return in_array($v, ['1', 'true', 'on', 'yes', 'ok'], true) ? true : (in_array($v, ['0', 'false', 'off', 'no', 'not_ok'], true) ? false : null);
}

/** An active storage area of a site named by id or name; null with an error otherwise. */
function stock_area_ref(PDO $pdo, mixed $raw, int $scope, string $field, string $label, array &$errors, bool $required = true): ?int
{
    $v = trim((string) ($raw ?? ''));
    if ($v === '') { if ($required) { $errors[$field] = $label . ' is required.'; } return null; }
    $st = $pdo->prepare('SELECT storage_area_id FROM mcp_storage_areas WHERE scope_id = :s AND active AND (storage_area_id::text = :v OR lower(name) = lower(:v2))');
    $st->execute(['s' => $scope, 'v' => $v, 'v2' => $v]);
    $id = $st->fetchColumn();
    if ($id === false) { $errors[$field] = 'That ' . lcfirst($label) . ' is not an active storage area of the site.'; return null; }
    return (int) $id;
}

/** A recipe by id, code or exact name. */
function stock_recipe_by_ref(PDO $pdo, string $v): ?array
{
    $v = trim($v);
    if ($v === '') { return null; }
    if (!ctype_digit($v)) {
        $st = $pdo->prepare('SELECT recipe_id FROM mcp_recipes WHERE lower(code) = lower(:v) OR lower(name) = lower(:v2) ORDER BY active DESC LIMIT 1');
        $st->execute(['v' => $v, 'v2' => $v]);
        $id = $st->fetchColumn();
        if ($id === false) { return null; }
        $v = (string) $id;
    }
    return find_recipe($pdo, (int) $v);
}

/** The JSON `lines` of a document, or [] (an error when it is not a list of objects). */
function stock_json_lines(array &$errors): array
{
    $raw = req_val('lines');
    if ($raw === null || $raw === '') { return []; }
    $v = json_decode($raw, true);
    if (!is_array($v) || !array_is_list($v)) { $errors['lines'] = 'Lines are a JSON list of objects.'; return []; }
    foreach ($v as $l) { if (!is_array($l)) { $errors['lines'] = 'Lines are a JSON list of objects.'; return []; } }
    return $v;
}

/** A refusal unless the record is at the current site (a document of another site does not exist here). */
function stock_here_or_404(?array $r, string $what): array
{
    if ($r === null || (int) $r['scope_id'] !== (int) current_site_id()) { respond_not_found($what . ' is not here.'); }
    return $r;
}

// ------------------------------------------------------------------------------------------------ receipts
function receipt_or_404(PDO $pdo, ?int $id): array
{
    return stock_here_or_404($id === null ? null : receipt_for($pdo, $id), 'That receipt');
}

/** A receipt's header from the request (create: $cur null). Returns the fields; errors by field. */
function receipt_header_from_request(PDO $pdo, ?array $cur, int $scope, array &$errors): array
{
    $f = ['storage_area_id' => $cur['storage_area_id'] ?? null, 'supplier_id' => $cur['supplier_id'] ?? null, 'purchase_order_id' => $cur['purchase_order_id'] ?? null,
          'received_on' => $cur['received_on'] ?? site_today($scope), 'delivery_note_ref' => $cur['delivery_note_ref'] ?? null, 'invoice_ref' => $cur['invoice_ref'] ?? null, 'notes' => $cur['notes'] ?? null];
    if (req_has('area') || $cur === null) { $f['storage_area_id'] = stock_area_ref($pdo, req_val('area'), $scope, 'area', 'The area', $errors); }
    if (req_has('supplier')) {
        $v = (string) req_val('supplier');
        if ($v === '') { $f['supplier_id'] = null; }
        else { $s = supplier_by_ref($pdo, $v); if ($s === null) { $errors['supplier'] = 'That supplier is not here.'; } else { $f['supplier_id'] = (int) $s['supplier_id']; } }
    }
    if (req_has('purchase_order')) {
        $v = (string) req_val('purchase_order');
        if ($v === '') { $f['purchase_order_id'] = null; }
        else {
            $poId = ctype_digit($v) ? (int) $v : (int) (one_value($pdo, 'SELECT purchase_order_id FROM mcp_purchase_orders WHERE number = :n', ['n' => $v]) ?: 0);
            $po = $poId > 0 ? po_for($pdo, $poId) : null;
            if ($po === null) { $errors['purchase_order'] = 'That purchase order is not here.'; }
            elseif ($po['scope_id'] !== $scope) { $errors['purchase_order'] = 'That purchase order is for another site.'; }
            elseif (!in_array($po['status'], ['sent', 'acknowledged', 'partial'], true)) { $errors['purchase_order'] = 'Purchase order ' . $po['number'] . ' is ' . $po['status'] . ' — only an open one is received against.'; }
            else {
                if ($f['supplier_id'] === null) { $f['supplier_id'] = $po['supplier_id']; }
                if ($f['supplier_id'] !== $po['supplier_id']) { $errors['purchase_order'] = 'Purchase order ' . $po['number'] . ' is from ' . $po['supplier'] . ', not this receipt\'s supplier.'; }
                $f['purchase_order_id'] = $po['purchase_order_id'];
            }
        }
    }
    if (req_has('received_on')) {
        $d = (string) req_val('received_on');
        if ($d === '') { $d = site_today($scope); }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if ($dt === false || $dt->format('Y-m-d') !== $d) { $errors['received_on'] = 'Received on is a date (YYYY-MM-DD).'; }
        elseif ($d > site_today($scope)) { $errors['received_on'] = 'A delivery is not received in the future.'; }
        else { $f['received_on'] = $d; }
    }
    $f['delivery_note_ref'] = ri_text('delivery_note_ref', $f['delivery_note_ref'], 100, 'The delivery note', $errors);
    $f['invoice_ref'] = ri_text('invoice_ref', $f['invoice_ref'], 100, 'The invoice number', $errors);
    $f['notes'] = ri_text('notes', $f['notes'], 2000, 'Notes', $errors);
    return $f;
}

/**
 * One receipt line from raw values (a form's fields or one object of `lines`): ingredient | supplier_item, qty + unit, weight_qty + weight_unit,
 * unit_cost, allow_zero_cost, discrepancy_kind/_note, temperature_c/_ok, putaway_area, purchase_order_line. $p prefixes the error keys.
 */
function receipt_line_fields(PDO $pdo, array $in, array $receipt, array &$errors, string $p = ''): ?array
{
    $scope = (int) $receipt['scope_id'];
    $si = null;
    if (($in['supplier_item'] ?? '') !== '' && $in['supplier_item'] !== null) {
        $v = (string) $in['supplier_item'];
        $sid = ctype_digit($v) ? (int) $v : (int) (one_value($pdo, 'SELECT supplier_item_id FROM mcp_supplier_items WHERE supplier_sku = :s' . ($receipt['supplier_id'] ? ' AND supplier_id = :su' : '') . ' LIMIT 1',
            $receipt['supplier_id'] ? ['s' => $v, 'su' => $receipt['supplier_id']] : ['s' => $v]) ?: 0);
        $si = $sid > 0 ? find_supplier_item($pdo, $sid) : null;
        if ($si === null) { $errors[$p . 'supplier_item'] = 'That supplier item is not here.'; return null; }
    }
    $ing = null;
    if (($in['ingredient'] ?? '') !== '' && $in['ingredient'] !== null) { $ing = ingredient_by_ref($pdo, (string) $in['ingredient']); if ($ing === null) { $errors[$p . 'ingredient'] = 'That ingredient is not here.'; return null; } }
    if ($ing === null && $si !== null) { $ing = find_ingredient($pdo, $si['ingredient_id']); }
    if ($ing === null) { $errors[$p . 'ingredient'] = 'Choose the ingredient (or the supplier item).'; return null; }
    $iid = (int) $ing['ingredient_id'];
    if ($si !== null && $si['ingredient_id'] !== $iid) { $errors[$p . 'supplier_item'] = 'That supplier item is ' . $si['ingredient'] . ', not ' . $ing['name'] . '.'; return null; }
    $unit = trim((string) ($in['unit'] ?? '')) ?: ($si['purchase_unit_code'] ?? '');
    $q = stock_qty_in($pdo, $iid, $in['qty'] ?? null, $unit, $p . 'qty', 'The quantity', $errors);
    $facts = ingredient_receiving_facts($pdo, $iid, $scope, $si['supplier_item_id'] ?? null);
    $out = ['ingredient_id' => $iid, 'ingredient' => $ing['name'], 'supplier_item_id' => $si['supplier_item_id'] ?? null, 'qty' => $q['qty'] ?? null, 'unit' => $q['unit'] ?? $unit,
            'weight_qty' => null, 'weight_unit' => null, 'unit_cost' => null, 'allow_zero_cost' => stock_yes($in['allow_zero_cost'] ?? null) ?? false,
            'discrepancy_kind' => 'none', 'discrepancy_note' => null, 'temperature_c' => null, 'temperature_ok' => null, 'putaway_area_id' => null, 'purchase_order_line_id' => null];
    $w = stock_number($in['weight_qty'] ?? null, $p . 'weight_qty', 'The declared weight', $errors, true);
    if ($w !== null) {
        $wu = trim((string) ($in['weight_unit'] ?? '')) ?: 'lb';
        try { qty_to_base($pdo, $iid, $w, $wu); $out['weight_qty'] = $w; $out['weight_unit'] = $wu; }
        catch (PDOException $e) { $errors[$p . 'weight_unit'] = db_message($e, 'That weight unit cannot be converted.'); }
    }
    $cost = $in['unit_cost'] ?? null;
    if (($cost === null || (!is_array($cost) && trim((string) $cost) === '')) && $si !== null && sees_cost($scope) && !array_key_exists('unit_cost_given', $in)) {
        $cost = $si['contract_price'] !== null && ($si['contract_until'] === null || $si['contract_until'] >= date('Y-m-d')) ? $si['contract_price'] : $si['last_price'];   // the supplier item fills the price (the view walls it)
    }
    if ($cost !== null && !is_array($cost) && trim((string) $cost) !== '') {
        if (!sees_cost($scope)) {
            $errors[$p . 'unit_cost'] = 'The cost is not shown to you here, so it is not entered by you: leave it blank — the line lands at the running cost and a Chef or the Manager prices it.';
        } else {
            $out['unit_cost'] = stock_number($cost, $p . 'unit_cost', 'The cost', $errors, false, 0);
        }
    }
    $dk = trim((string) ($in['discrepancy_kind'] ?? '')) ?: 'none';
    if (!isset(DISCREPANCY_KINDS[$dk])) { $errors[$p . 'discrepancy_kind'] = 'The discrepancy is one of: ' . implode(', ', array_keys(DISCREPANCY_KINDS)) . '.'; } else { $out['discrepancy_kind'] = $dk; }
    $dn = trim((string) ($in['discrepancy_note'] ?? ''));
    if (mb_strlen($dn) > 500) { $errors[$p . 'discrepancy_note'] = 'The note is up to 500 characters.'; } elseif ($dn !== '') { $out['discrepancy_note'] = $dn; }
    $out['temperature_c'] = stock_number($in['temperature_c'] ?? null, $p . 'temperature_c', 'The temperature', $errors, false, -60, 100);
    $out['temperature_ok'] = stock_yes($in['temperature_ok'] ?? null);
    if (($in['putaway_area'] ?? '') !== '' && $in['putaway_area'] !== null) { $out['putaway_area_id'] = stock_area_ref($pdo, $in['putaway_area'], $scope, $p . 'putaway_area', 'The put-away area', $errors); }
    if (($in['purchase_order_line'] ?? '') !== '' && $in['purchase_order_line'] !== null) {
        $pol = (int) $in['purchase_order_line'];
        $row = $receipt['purchase_order_id'] ? q_row($pdo, 'SELECT purchase_order_line_id, ingredient_id FROM mcp_purchase_order_lines WHERE purchase_order_line_id = :l AND purchase_order_id = :p', ['l' => $pol, 'p' => $receipt['purchase_order_id']]) : null;
        if ($row === null) { $errors[$p . 'purchase_order_line'] = 'That is not a line of the receipt\'s purchase order.'; }
        elseif ((int) $row['ingredient_id'] !== $iid) { $errors[$p . 'purchase_order_line'] = 'That purchase-order line is for another ingredient.'; }
        else { $out['purchase_order_line_id'] = $pol; }
    }
    $out['facts'] = $facts;
    return $out;
}

function q_row(PDO $pdo, string $sql, array $args): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Insert or update one receipt line (the trigger converts); returns its id. */
function save_receipt_line(PDO $pdo, int $receiptId, ?int $lineId, array $l): int
{
    $cols = ['ingredient_id' => $l['ingredient_id'], 'supplier_item_id' => $l['supplier_item_id'], 'qty_received' => $l['qty'], 'purchase_unit_code' => $l['unit'], 'weight_qty' => $l['weight_qty'],
             'weight_unit_code' => $l['weight_unit'], 'unit_cost' => $l['unit_cost'], 'allow_zero_cost' => $l['allow_zero_cost'] ? 't' : 'f', 'discrepancy_kind' => $l['discrepancy_kind'],
             'discrepancy_note' => $l['discrepancy_note'], 'temperature_c' => $l['temperature_c'], 'temperature_ok' => $l['temperature_ok'] === null ? null : ($l['temperature_ok'] ? 't' : 'f'),
             'putaway_area_id' => $l['putaway_area_id'], 'purchase_order_line_id' => $l['purchase_order_line_id']];
    if ($lineId === null) {
        $no = (int) one_value($pdo, 'SELECT COALESCE(max(line_no), 0) + 1 FROM goods_receipt_lines WHERE goods_receipt_id = :g', ['g' => $receiptId]);
        $st = $pdo->prepare('INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, qty_base, ' . implode(', ', array_keys($cols)) . ') VALUES (:g, :n, 0, :' . implode(', :', array_keys($cols)) . ') RETURNING id');
        $st->execute(['g' => $receiptId, 'n' => $no] + $cols);
        return (int) $st->fetchColumn();
    }
    $set = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($cols)));
    $pdo->prepare('UPDATE goods_receipt_lines SET ' . $set . ' WHERE id = :id')->execute($cols + ['id' => $lineId]);
    return $lineId;
}

/** A receipt's loggable lines: quantities in base with their units; value only for one who sees cost. */
function receipt_loggable_lines(array $lines, bool $sees): array
{
    return array_map(static function (array $l) use ($sees): array {
        $o = ['ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty_received'], 'unit' => $l['purchase_unit_code'], 'qty_base' => $l['qty_base'], 'base_unit' => $l['base_unit_code']];
        if ($l['weight_qty'] !== null) { $o['weight_qty'] = $l['weight_qty']; $o['weight_unit'] = $l['weight_unit_code']; }
        if ($sees && $l['cost_per_base'] !== null) { $o['value'] = number_format((float) $l['qty_base'] * (float) $l['cost_per_base'], 2, '.', ''); }
        return $o;
    }, $lines);
}

// ------------------------------------------------------------------------------------------------ transfers
/** A transfer the caller holds either end of; null otherwise. */
function transfer_or_404(PDO $pdo, ?int $id): array
{
    $t = $id === null ? null : transfer_for($pdo, $id);
    if ($t === null) { respond_not_found('That transfer is not here.'); }
    return $t;
}

/** One transfer line from raw values: ingredient, qty, unit. */
function transfer_line_fields(PDO $pdo, array $in, array &$errors, string $p = ''): ?array
{
    $ing = ($in['ingredient'] ?? '') === '' || $in['ingredient'] === null ? null : ingredient_by_ref($pdo, (string) $in['ingredient']);
    if ($ing === null) { $errors[$p . 'ingredient'] = ($in['ingredient'] ?? '') === '' ? 'Choose the ingredient.' : 'That ingredient is not here.'; return null; }
    $q = stock_qty_in($pdo, (int) $ing['ingredient_id'], $in['qty'] ?? null, $in['unit'] ?? (default_count_unit($pdo, (int) $ing['ingredient_id']) ?? $ing['base_unit_code']), $p . 'qty', 'The quantity', $errors);
    return $q === null ? null : ['ingredient_id' => (int) $ing['ingredient_id'], 'ingredient' => $ing['name'], 'qty' => $q['qty'], 'unit' => $q['unit'], 'qty_base' => $q['qty_base']];
}

function save_transfer_line(PDO $pdo, int $transferId, ?int $lineId, array $l): int
{
    if ($lineId === null) {
        $no = (int) one_value($pdo, 'SELECT COALESCE(max(line_no), 0) + 1 FROM inventory_transfer_lines WHERE transfer_id = :t', ['t' => $transferId]);
        $st = $pdo->prepare('INSERT INTO inventory_transfer_lines (transfer_id, line_no, ingredient_id, qty, unit_code, qty_base) VALUES (:t, :n, :i, :q, :u, 0) RETURNING id');
        $st->execute(['t' => $transferId, 'n' => $no, 'i' => $l['ingredient_id'], 'q' => $l['qty'], 'u' => $l['unit']]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE inventory_transfer_lines SET ingredient_id = :i, qty = :q, unit_code = :u WHERE id = :id')->execute(['i' => $l['ingredient_id'], 'q' => $l['qty'], 'u' => $l['unit'], 'id' => $lineId]);
    return $lineId;
}

// ------------------------------------------------------------------------------------------------ adjustments
function adjustment_or_404(PDO $pdo, ?int $id): array
{
    return stock_here_or_404($id === null ? null : adjustment_for($pdo, $id), 'That adjustment');
}

/** One adjustment line: ingredient, qty_delta (signed, never zero) + unit, unit_cost per that unit (cost seers only), note. */
function adjustment_line_fields(PDO $pdo, array $in, int $scope, array &$errors, string $p = ''): ?array
{
    $ing = ($in['ingredient'] ?? '') === '' || $in['ingredient'] === null ? null : ingredient_by_ref($pdo, (string) $in['ingredient']);
    if ($ing === null) { $errors[$p . 'ingredient'] = ($in['ingredient'] ?? '') === '' ? 'Choose the ingredient.' : 'That ingredient is not here.'; return null; }
    $iid = (int) $ing['ingredient_id'];
    $q = stock_qty_in($pdo, $iid, $in['qty_delta'] ?? null, $in['unit'] ?? (default_count_unit($pdo, $iid) ?? $ing['base_unit_code']), $p . 'qty_delta', 'The change', $errors, true, true);
    $out = ['ingredient_id' => $iid, 'ingredient' => $ing['name'], 'qty_delta' => $q['qty'] ?? null, 'unit' => $q['unit'] ?? null, 'qty_delta_base' => $q['qty_base'] ?? null, 'unit_cost_per_base' => null, 'note' => null];
    $cost = $in['unit_cost'] ?? null;
    if ($cost !== null && !is_array($cost) && trim((string) $cost) !== '') {
        if (!sees_cost($scope)) { $errors[$p . 'unit_cost'] = 'The cost is not shown to you here, so it is not entered by you.'; }
        else {
            $c = stock_number($cost, $p . 'unit_cost', 'The cost', $errors, false, 0);
            if ($c !== null && $q !== null) { $per = (float) qty_to_base($pdo, $iid, '1', $q['unit']); $out['unit_cost_per_base'] = number_format((float) $c / $per, 6, '.', ''); }
        }
    }
    $n = trim((string) ($in['note'] ?? ''));
    if (mb_strlen($n) > 500) { $errors[$p . 'note'] = 'The note is up to 500 characters.'; } elseif ($n !== '') { $out['note'] = $n; }
    return $q === null ? null : $out;
}

function save_adjustment_line(PDO $pdo, int $adjId, ?int $lineId, array $l): int
{
    if ($lineId === null) {
        $no = (int) one_value($pdo, 'SELECT COALESCE(max(line_no), 0) + 1 FROM inventory_adjustment_lines WHERE adjustment_id = :a', ['a' => $adjId]);
        $st = $pdo->prepare('INSERT INTO inventory_adjustment_lines (adjustment_id, line_no, ingredient_id, qty_delta, unit_code, qty_delta_base, unit_cost_per_base, note) VALUES (:a, :n, :i, :q, :u, 0, :c, :note) RETURNING id');
        $st->execute(['a' => $adjId, 'n' => $no, 'i' => $l['ingredient_id'], 'q' => $l['qty_delta'], 'u' => $l['unit'], 'c' => $l['unit_cost_per_base'], 'note' => $l['note']]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE inventory_adjustment_lines SET ingredient_id = :i, qty_delta = :q, unit_code = :u, unit_cost_per_base = :c, note = :note WHERE id = :id')
        ->execute(['i' => $l['ingredient_id'], 'q' => $l['qty_delta'], 'u' => $l['unit'], 'c' => $l['unit_cost_per_base'], 'note' => $l['note'], 'id' => $lineId]);
    return $lineId;
}

/** An adjustment reason by code or id; the schema's sentence when it is not one. */
function adjustment_reason(PDO $pdo, string $v, array &$errors): ?array
{
    $r = $v === '' ? null : reason_by_ref($pdo, $v);
    if ($r === null) { $errors['reason'] = $v === '' ? 'Say why — the reason is required.' : 'That reason is not here.'; return null; }
    if (!in_array('adjustment', pg_text_array((string) $r['applies_to']), true)) { $errors['reason'] = 'The reason ' . $r['name'] . ' is not an adjustment reason'; return null; }
    return $r;
}

// ------------------------------------------------------------------------------------------------ production and waste
function batch_or_404(PDO $pdo, ?int $id): array
{
    return stock_here_or_404($id === null ? null : batch_for($pdo, $id), 'That batch');
}

function waste_or_404(PDO $pdo, ?int $id): array
{
    return stock_here_or_404($id === null ? null : waste_for($pdo, $id), 'That waste log');
}

/** The movements a posted document wrote, for its JSON answer: [{transaction_id, ingredient_id, qty_base, …}] (cost walled). */
function stock_doc_txns(PDO $pdo, string $kind, int $id): array
{
    $sees = sees_cost();
    return array_map(static fn (array $t): array => present_txn($t, $sees), document_movements($pdo, (int) current_site_id(), $kind, $id));
}
