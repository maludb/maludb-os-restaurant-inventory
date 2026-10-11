<?php
declare(strict_types=1);
/** Action `receipt_cancel`: stock.receive; a draft only (nothing was posted). Log receipt.cancel. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.receive');
$pdo = db();
$g = receipt_or_404($pdo, (int) (req_val('receipt') ?? 0) ?: null);
if ($g['status'] !== 'draft') { refuse(422, 'Receipt ' . $g['number'] . ' is ' . $g['status'] . ' — only a draft is cancelled.'); }
ri_guard($pdo, static function () use ($pdo, $g): void {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE goods_receipts SET status = 'cancelled' WHERE id = :id AND status = 'draft'")->execute(['id' => $g['goods_receipt_id']]);
    stock_log($pdo, 'receipt.cancel', 'goods_receipt', (int) $g['goods_receipt_id'], ['purchase_order_id' => $g['purchase_order_id'], 'before' => ['status' => 'draft'], 'after' => ['status' => 'cancelled']]);
    $pdo->commit();
});
ri_done('Cancelled ' . $g['number'], $g['goods_receipt_id'], ri_land('/receipts/', 'cancelled'), 'receiptChanged', ['receipt_id' => $g['goods_receipt_id']]);
