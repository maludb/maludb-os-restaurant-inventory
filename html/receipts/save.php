<?php
declare(strict_types=1);
/** Actions `receipt_draft` (no `receipt`) / `receipt_update` (with — a draft's header): stock.receive. A draft's `lines` (JSON, an agent) or `prefill[]` (the PO's open lines, a form) become its first lines through the same reader as receipt_line_add. Log receipt.draft / receipt.update (purchase_order_id). Location /receipts/{id}; refresh receiptChanged. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.receive');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$cur = req_has('receipt') && (string) req_val('receipt') !== '' ? receipt_or_404($pdo, (int) req_val('receipt')) : null;
if ($cur !== null && $cur['status'] !== 'draft') { refuse(422, 'Receipt ' . $cur['number'] . ' is ' . $cur['status'] . ' — only a draft\'s header changes.'); }
$errors = [];
$f = receipt_header_from_request($pdo, $cur, $site, $errors);
$lineIn = [];
if ($cur === null) {
    foreach (stock_json_lines($errors) as $l) { $lineIn[] = $l; }
    $prefill = $_POST['prefill'] ?? [];
    if (is_array($prefill) && $prefill !== [] && $f['purchase_order_id'] !== null) {
        $open = [];
        foreach (po_open_lines($pdo, $f['purchase_order_id']) as $ol) { $open[$ol['purchase_order_line_id']] = $ol; }
        foreach ($prefill as $pid) {
            $ol = $open[(int) $pid] ?? null;
            if ($ol === null) { $errors['prefill'] = 'A checked line is no longer open on the purchase order.'; continue; }
            $lineIn[] = ['ingredient' => (string) $ol['ingredient_id'], 'supplier_item' => $ol['supplier_item_id'] === null ? '' : (string) $ol['supplier_item_id'], 'qty' => $_POST['prefill_qty'][(int) $pid] ?? $ol['qty_open'],
                         'unit' => $ol['purchase_unit_code'], 'unit_cost' => sees_cost($site) ? (string) $ol['unit_cost'] : '', 'purchase_order_line' => (string) $ol['purchase_order_line_id']];
        }
    }
}
if ($errors !== []) { ri_refuse_fields($errors); }
$header = ['scope_id' => $site, 'supplier_id' => $f['supplier_id'], 'purchase_order_id' => $f['purchase_order_id'], 'storage_area_id' => $f['storage_area_id']];
$lineErrors = [];
$parsed = [];
foreach ($lineIn as $k => $l) {
    $x = receipt_line_fields($pdo, $l, $header + ['goods_receipt_id' => 0], $lineErrors, 'lines.' . $k . '.');
    if ($x !== null) { $parsed[] = $x; }
}
if ($lineErrors !== []) { ri_refuse_fields($lineErrors); }
$today = site_today($site);
$id = ri_guard($pdo, static function () use ($pdo, $cur, $f, $site, $me, $parsed, $today): int {
    $pdo->beginTransaction();
    $args = ['su' => $f['supplier_id'], 'po' => $f['purchase_order_id'], 'a' => $f['storage_area_id'], 'd' => $f['received_on'], 'd2' => $f['received_on'], 'd3' => $f['received_on'], 'today' => $today, 's2' => $site,
             'dn' => $f['delivery_note_ref'], 'ir' => $f['invoice_ref'], 'n' => $f['notes']];
    $at = "CASE WHEN :d::date >= :today::date THEN now() ELSE ri_site_moment(:s2, :d2::date, false) + interval '1 hour' END";
    if ($cur === null) {
        $st = $pdo->prepare("INSERT INTO goods_receipts (scope_id, supplier_id, purchase_order_id, storage_area_id, received_on, received_at, delivery_note_ref, invoice_ref, notes, created_by)
                             VALUES (:s, :su, :po, :a, :d3::date, $at, :dn, :ir, :n, :by) RETURNING id");
        $st->execute($args + ['s' => $site, 'by' => $me]);
        $id = (int) $st->fetchColumn();
        foreach ($parsed as $l) { save_receipt_line($pdo, $id, null, $l); }
        $g = receipt_for($pdo, $id);
        stock_log($pdo, 'receipt.draft', 'goods_receipt', $id, ['purchase_order_id' => $f['purchase_order_id'], 'after' => ['number' => $g['number'], 'supplier_id' => $f['supplier_id'], 'purchase_order_id' => $f['purchase_order_id'],
            'storage_area_id' => $f['storage_area_id'], 'received_on' => $f['received_on'], 'lines' => receipt_loggable_lines(receipt_lines($pdo, $id), sees_cost($site))]]);
    } else {
        $id = (int) $cur['goods_receipt_id'];
        $st = $pdo->prepare("UPDATE goods_receipts SET supplier_id = :su, purchase_order_id = :po, storage_area_id = :a, received_on = :d3::date, received_at = $at, delivery_note_ref = :dn, invoice_ref = :ir, notes = :n WHERE id = :id AND status = 'draft'");
        $st->execute($args + ['id' => $id]);
        $before = ['supplier_id' => $cur['supplier_id'], 'purchase_order_id' => $cur['purchase_order_id'], 'storage_area_id' => $cur['storage_area_id'], 'received_on' => $cur['received_on'], 'delivery_note_ref' => $cur['delivery_note_ref'], 'invoice_ref' => $cur['invoice_ref'], 'notes' => $cur['notes']];
        $after = ['supplier_id' => $f['supplier_id'], 'purchase_order_id' => $f['purchase_order_id'], 'storage_area_id' => $f['storage_area_id'], 'received_on' => $f['received_on'], 'delivery_note_ref' => $f['delivery_note_ref'], 'invoice_ref' => $f['invoice_ref'], 'notes' => $f['notes']];
        $d = ri_diff($before, $after);
        if ($d['after'] !== []) { stock_log($pdo, 'receipt.update', 'goods_receipt', $id, ['purchase_order_id' => $f['purchase_order_id'], 'before' => $d['before'], 'after' => $d['after']]); }
    }
    $pdo->commit();
    return $id;
});
$g = receipt_for($pdo, $id);
ri_done(($cur === null ? 'Started ' : 'Saved ') . $g['number'] . ($g['supplier'] ? ' from ' . $g['supplier'] : '') . ($parsed !== [] ? ' with ' . count($parsed) . ' line' . (count($parsed) === 1 ? '' : 's') : ''), $id,
    ri_land('/receipts/' . $id, $cur === null ? 'created' : 'saved'), 'receiptChanged', ['receipt_id' => $id, 'number' => $g['number']]);
