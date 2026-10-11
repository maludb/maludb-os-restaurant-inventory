<?php
declare(strict_types=1);
/**
 * Actions `invoice_create` (no `invoice`) / `invoice_update` (with — a draft or disputed invoice's header): invoices.write. supplier, invoice_no (unique per supplier and kind — said in words),
 * invoice_date, kind (invoice or credit), receipt (a posted receipt of this supplier), purchase_order, tax, received_on, file (multipart: saved as an attachment on the invoice), notes, lines (JSON).
 * With a receipt and NO lines, the receipt's lines become the invoice's lines (a catch-weight line at its declared weight and unit) for the person to correct against the paper (`prefill=no` starts empty).
 * The totals are the trigger's. Log invoice.create / invoice.update (purchase_order_id when it bills one). Location /invoices/{id}/edit.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('invoices.write');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$cur = req_has('invoice') && trim((string) req_val('invoice')) !== '' ? invoice_from_request($pdo) : null;
if ($cur !== null) { invoice_require_editable($cur); }
$errors = [];
$hasLines = $cur !== null && (int) one_value($pdo, 'SELECT count(*) FROM mcp_supplier_invoice_lines WHERE invoice_id = :i', ['i' => $cur['invoice_id']]) > 0;
$f = invoice_header_from_request($pdo, $cur, $site, $errors, $hasLines);
$lineIn = stock_json_lines($errors);
if ($cur === null && $lineIn === [] && $f['goods_receipt_id'] !== null && strtolower((string) (req_val('prefill') ?? 'yes')) !== 'no' && !isset($errors['receipt'])) {
    foreach (receipt_lines_for_invoice($pdo, (int) $f['goods_receipt_id']) as $rl) {
        $lineIn[] = ['supplier_item' => $rl['supplier_item_id'] === null ? '' : (string) $rl['supplier_item_id'], 'ingredient' => (string) $rl['ingredient_id'], 'description' => $rl['ingredient'], 'qty' => $rl['qty'], 'unit' => $rl['unit'],
                     'unit_price' => $rl['price'] ?? '0', 'receipt_line' => (string) $rl['goods_receipt_line_id']];
    }
}
$file = $_FILES['file'] ?? null;
if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { $file = null; }
if ($errors !== []) { ri_refuse_fields($errors); }
$forLines = ['scope_id' => $site, 'supplier_id' => $f['supplier_id'], 'supplier' => $f['supplier'], 'goods_receipt_id' => $f['goods_receipt_id']];
$lineErrors = [];
$parsed = [];
foreach ($lineIn as $k => $l) {
    $x = invoice_line_fields($pdo, $l, $forLines, null, $lineErrors, 'lines.' . $k . '.');
    if ($x !== null) { $parsed[] = $x; }
}
if ($lineErrors !== []) { ri_refuse_fields($lineErrors); }
$id = ri_guard($pdo, static function () use ($pdo, $cur, $f, $site, $me, $parsed, $file): int {
    $pdo->beginTransaction();
    if ($cur === null) {
        $st = $pdo->prepare('INSERT INTO supplier_invoices (scope_id, supplier_id, invoice_no, kind, invoice_date, received_on, purchase_order_id, goods_receipt_id, tax, notes, created_by)
                             VALUES (:s, :su, :no, :k, :d::date, :r::date, :po, :g, :t, :n, :by) RETURNING id');
        $st->execute(['s' => $site, 'su' => $f['supplier_id'], 'no' => $f['invoice_no'], 'k' => $f['kind'], 'd' => $f['invoice_date'], 'r' => $f['received_on'], 'po' => $f['purchase_order_id'], 'g' => $f['goods_receipt_id'],
                      't' => $f['tax'], 'n' => $f['notes'], 'by' => $me]);
        $id = (int) $st->fetchColumn();
    } else {
        $id = (int) $cur['invoice_id'];
        $pdo->prepare("UPDATE supplier_invoices SET supplier_id = :su, invoice_no = :no, kind = :k, invoice_date = :d::date, received_on = :r::date, purchase_order_id = :po, goods_receipt_id = :g, tax = :t, notes = :n WHERE id = :id AND status IN ('draft', 'disputed')")
            ->execute(['su' => $f['supplier_id'], 'no' => $f['invoice_no'], 'k' => $f['kind'], 'd' => $f['invoice_date'], 'r' => $f['received_on'], 'po' => $f['purchase_order_id'], 'g' => $f['goods_receipt_id'], 't' => $f['tax'], 'n' => $f['notes'], 'id' => $id]);
    }
    foreach ($parsed as $l) { save_invoice_line($pdo, $id, null, $l); }
    $pdo->prepare('SELECT ri_invoice_totals(:i)')->execute(['i' => $id]);            // the tax may have changed with no line changing
    if ($file !== null) {
        $att = attachment_store($pdo, 'supplier_invoice', $id, $file, $me);
        $pdo->prepare('UPDATE supplier_invoices SET attachment_id = :a WHERE id = :id')->execute(['a' => $att, 'id' => $id]);
    }
    $g = invoice_for($pdo, $id);
    $keys = ['supplier_id', 'invoice_no', 'kind', 'invoice_date', 'received_on', 'purchase_order_id', 'goods_receipt_id', 'tax', 'notes'];
    if ($cur === null) {
        invoice_log($pdo, 'invoice.create', $g, ['after' => ['number' => $g['number'], 'invoice_no' => $f['invoice_no'], 'supplier_id' => $f['supplier_id'], 'kind' => $f['kind'], 'receipt_id' => $f['goods_receipt_id'], 'total' => $g['total'],
            'lines' => invoice_loggable_lines(invoice_lines($pdo, $id)), 'file' => $file !== null]]);
    } else {
        $d = ri_diff(array_intersect_key($cur, array_flip($keys)), ['supplier_id' => $f['supplier_id'], 'invoice_no' => $f['invoice_no'], 'kind' => $f['kind'], 'invoice_date' => $f['invoice_date'], 'received_on' => $f['received_on'],
            'purchase_order_id' => $f['purchase_order_id'], 'goods_receipt_id' => $f['goods_receipt_id'], 'tax' => $f['tax'], 'notes' => $f['notes']]);
        if ($d['after'] !== [] || $parsed !== [] || $file !== null) { invoice_log($pdo, 'invoice.update', $g, ['before' => $d['before'], 'after' => $d['after'] + ($parsed === [] ? [] : ['lines_added' => count($parsed)]) + ($file !== null ? ['file' => true] : [])]); }
    }
    $pdo->commit();
    return $id;
});
$g = invoice_for($pdo, $id);
ri_done(($cur === null ? 'Entered ' : 'Saved ') . $g['number'] . ' (' . $g['supplier'] . ' ' . $g['invoice_no'] . ')' . ($parsed !== [] ? ' with ' . count($parsed) . ' line' . (count($parsed) === 1 ? '' : 's') : ''), $id,
    ri_land('/invoices/' . $id . '/edit', $cur === null ? 'created' : 'saved'), 'invoiceChanged', ['invoice_id' => $id, 'number' => $g['number'], 'status' => $g['status'], 'total' => $g['total']]);
