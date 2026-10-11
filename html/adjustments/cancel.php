<?php
declare(strict_types=1);
/** Action `adjustment_cancel`: counts.approve; a draft only. Log adjustment.cancel. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('counts.approve');
$pdo = db();
$j = adjustment_or_404($pdo, (int) (req_val('adjustment') ?? 0) ?: null);
if ($j['status'] !== 'draft') { refuse(422, 'Adjustment ' . $j['number'] . ' is ' . $j['status'] . ' — only a draft is cancelled.'); }
ri_guard($pdo, static function () use ($pdo, $j): void {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE inventory_adjustments SET status = 'cancelled' WHERE id = :id AND status = 'draft'")->execute(['id' => $j['adjustment_id']]);
    stock_log($pdo, 'adjustment.cancel', 'inventory_adjustment', (int) $j['adjustment_id'], ['before' => ['status' => 'draft'], 'after' => ['status' => 'cancelled']]);
    $pdo->commit();
});
ri_done('Cancelled ' . $j['number'], $j['adjustment_id'], ri_land('/adjustments/', 'cancelled'), 'adjustmentChanged');
