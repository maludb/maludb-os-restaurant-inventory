<?php
declare(strict_types=1);
/** Action `receipt_post`: stock.receive; ri_post_receipt() — the balances move, the site's average advances, price history is written, a PO's lines advance. The header's temperature_ok is the AND of the lines'. Log receipt.post (purchase_order_id, the lines in base with their units, value only for a cost-seer). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.receive');
$pdo = db();
$g = receipt_or_404($pdo, (int) (req_val('receipt') ?? 0) ?: null);
$me = (int) current_member_id();
ri_guard($pdo, static function () use ($pdo, $g, $me): void {
    $pdo->beginTransaction();
    $pdo->prepare('SELECT ri_post_receipt(:g, :by)')->execute(['g' => $g['goods_receipt_id'], 'by' => $me]);
    $pdo->prepare('UPDATE goods_receipts SET temperature_ok = (SELECT CASE WHEN count(temperature_ok) = 0 THEN NULL ELSE bool_and(temperature_ok) END FROM goods_receipt_lines WHERE goods_receipt_id = :g) WHERE id = :g2')
        ->execute(['g' => $g['goods_receipt_id'], 'g2' => $g['goods_receipt_id']]);
    stock_log($pdo, 'receipt.post', 'goods_receipt', (int) $g['goods_receipt_id'], ['purchase_order_id' => $g['purchase_order_id'], 'after' => ['number' => $g['number'], 'status' => 'posted',
        'lines' => receipt_loggable_lines(receipt_lines($pdo, (int) $g['goods_receipt_id']), sees_cost())]]);
    $pdo->commit();
});
$lines = receipt_lines($pdo, $g['goods_receipt_id']);
$txns = stock_doc_txns($pdo, 'goods_receipt', $g['goods_receipt_id']);
ri_done('Posted ' . $g['number'] . ': ' . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') . ' into ' . $g['area'], $g['goods_receipt_id'], ri_land('/receipts/' . $g['goods_receipt_id'], 'posted'), 'stockChanged',
    ['receipt_id' => $g['goods_receipt_id'], 'transactions' => $txns, 'lines' => array_map(static fn (array $l): array => ['ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty_received'], 'unit' => $l['purchase_unit_code'], 'qty_base' => $l['qty_base'], 'base_unit' => $l['base_unit_code']], $lines)]);
