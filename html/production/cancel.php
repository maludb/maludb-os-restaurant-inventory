<?php
declare(strict_types=1);
/** Action `production_cancel`: stock.produce; a draft only. Log production.cancel (recipe_id). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.produce');
$pdo = db();
$b = batch_or_404($pdo, (int) (req_val('batch') ?? 0) ?: null);
if ($b['status'] !== 'draft') { refuse(422, 'Batch ' . $b['number'] . ' is ' . $b['status'] . ' — only a draft is cancelled.'); }
ri_guard($pdo, static function () use ($pdo, $b): void {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE production_batches SET status = 'cancelled' WHERE id = :id AND status = 'draft'")->execute(['id' => $b['production_batch_id']]);
    stock_log($pdo, 'production.cancel', 'production_batch', (int) $b['production_batch_id'], ['recipe_id' => $b['recipe_id'], 'before' => ['status' => 'draft'], 'after' => ['status' => 'cancelled']]);
    $pdo->commit();
});
ri_done('Cancelled ' . $b['number'], $b['production_batch_id'], ri_land('/production/', 'cancelled'), 'productionChanged');
