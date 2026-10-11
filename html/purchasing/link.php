<?php
declare(strict_types=1);
/** Action `supplier_link_rotate`: purchasing.send — a new secure link for a sent order (ri_supplier_link_mint(): the old row is rotated out, its token dies at once). The new token is shown ONCE on the page (a person's session); the log and the JSON carry its last four only. Log purchase_order.link_rotate. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('purchasing.send');
$pdo = db();
$po = po_from_request($pdo);
if ($po['status'] === 'draft') { refuse(422, 'Purchase order ' . $po['number'] . ' is a draft — send it or mark it placed first; a draft has no link.'); }
if ($po['status'] === 'cancelled') { refuse(422, 'Purchase order ' . $po['number'] . ' is cancelled — its link takes no answer.'); }
$raw = ri_guard($pdo, static function () use ($pdo, $po): string {
    $pdo->beginTransaction();
    $raw = (string) one_value($pdo, 'SELECT ri_supplier_link_mint(:p)', ['p' => $po['purchase_order_id']]);
    po_log($pdo, 'purchase_order.link_rotate', (int) $po['purchase_order_id'], ['after' => ['link_last4' => token_last4($raw)]]);
    $pdo->commit();
    return $raw;
});
po_link_once_set((int) $po['purchase_order_id'], $raw);
ri_done('Made a new link for ' . $po['number'] . ' — the old one no longer works', (int) $po['purchase_order_id'], ri_land('/purchasing/' . $po['purchase_order_id'] . '?tab=link', 'rotated'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $po['purchase_order_id'], 'link_last4' => token_last4($raw)]);
