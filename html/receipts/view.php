<?php
declare(strict_types=1);
/** /receipts/{id} — THE RECEIVING SCREEN (screen `receipt-view`; param line = a line to change). JSON: present_receipt() + the movements it posted. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$g = receipt_or_404($pdo, request_integer('id') ?? request_integer('receipt'));
$rid = $g['goods_receipt_id'];
$lines = receipt_lines($pdo, $rid);
$sees = sees_cost($site);
log_activity($pdo, 'screen.view', 'goods_receipt', $rid, ['screen' => 'receipt-view', 'purchase_order_id' => $g['purchase_order_id']]);
if (wants_json()) {
    respond_screen(['receipt' => present_receipt($g, $lines, $sees), 'movements' => stock_doc_txns($pdo, 'goods_receipt', $rid), 'may' => ['receive' => has_right('stock.receive'), 'cost' => $sees]]);
}
$line = null;
$lineId = request_integer('line');
if ($lineId !== null) { foreach ($lines as $l) { if ($l['goods_receipt_line_id'] === $lineId) { $line = $l; } } }
$ingredient = $line['ingredient_id'] ?? null;
$unitOptions = $ingredient !== null ? unit_options_for($pdo, $ingredient, 'receive') : array_map(static fn (array $u): array => ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ' — ' . $u['name']], global_units($pdo));
$notices = ['created' => ['success', 'The receipt is started. Add each line as it comes off the truck.'], 'line_saved' => ['success', 'Line saved.'], 'line_removed' => ['success', 'Line removed.'],
    'posted' => ['success', 'Posted — the stock is on the shelf and the costs are recorded.'], 'saved' => ['success', 'Saved.']];
render_screen($g['number'], view('receipts/view.php', ['receipt' => $g, 'lines' => $lines, 'line' => $line, 'unitOptions' => $unitOptions, 'facts' => $ingredient === null ? [] : ingredient_receiving_facts($pdo, $ingredient, $site, $line['supplier_item_id'] ?? null),
    'areas' => area_options($pdo, $site), 'poLines' => $g['purchase_order_id'] ? po_open_lines($pdo, $g['purchase_order_id']) : [], 'seesCost' => $sees, 'mayEdit' => has_right('stock.receive'),
    'movements' => $g['status'] === 'posted' ? document_movements($pdo, $site, 'goods_receipt', $rid) : [], 'prices' => $g['status'] === 'posted' ? receipt_price_rows($pdo, $rid) : [],
    'notes' => record_notes($pdo, 'goods_receipt', $rid), 'attachments' => record_attachments($pdo, 'goods_receipt', $rid), 'mayNote' => has_right('notes.write'), 'me' => (int) current_member_id(), 'isAdmin' => is_ri_admin(),
    'trail' => find_record_activity($pdo, 'goods_receipt', $rid), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'receipt-list', 'screen' => 'receipt-view', 'entity' => 'goods_receipt', 'recordId' => (string) $rid]);
