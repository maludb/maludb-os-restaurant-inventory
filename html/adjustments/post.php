<?php
declare(strict_types=1);
/** Action `stock_adjust` (log adjustment.post — `deletion` for an agent: a quantity changes with no other document): counts.approve; ri_post_adjustment(). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('counts.approve');
$pdo = db();
$j = adjustment_or_404($pdo, (int) (req_val('adjustment') ?? 0) ?: null);
$me = (int) current_member_id();
ri_guard($pdo, static function () use ($pdo, $j, $me): void {
    $pdo->beginTransaction();
    $pdo->prepare('SELECT ri_post_adjustment(:a, :by)')->execute(['a' => $j['adjustment_id'], 'by' => $me]);
    stock_log($pdo, 'adjustment.post', 'inventory_adjustment', (int) $j['adjustment_id'], ['after' => ['status' => 'posted', 'reason' => $j['reason_code'], 'lines' => array_map(static fn (array $l): array => ['ingredient_id' => $l['ingredient_id'], 'qty_delta_base' => $l['qty_delta_base'], 'unit' => $l['unit_code']], adjustment_lines($pdo, (int) $j['adjustment_id']))]]);
    $pdo->commit();
});
ri_done('Posted ' . $j['number'] . ' (' . $j['reason'] . ')', $j['adjustment_id'], ri_land('/adjustments/' . $j['adjustment_id'], 'posted'), 'stockChanged', ['adjustment_id' => $j['adjustment_id'], 'transactions' => stock_doc_txns($pdo, 'adjustment', $j['adjustment_id'])]);
