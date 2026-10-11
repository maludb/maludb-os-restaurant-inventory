<?php
declare(strict_types=1);
/** Action `transfer_cancel`: stock.transfer; a draft only. Log transfer.cancel. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.transfer');
$pdo = db();
$t = transfer_or_404($pdo, (int) (req_val('transfer') ?? 0) ?: null);
if ($t['scope_id'] !== (int) current_site_id()) { respond_not_found('That transfer is not here.'); }
if ($t['status'] !== 'draft') { refuse(422, 'Transfer ' . $t['number'] . ' is ' . str_replace('_', ' ', $t['status']) . ' — only a draft is cancelled.'); }
ri_guard($pdo, static function () use ($pdo, $t): void {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE inventory_transfers SET status = 'cancelled' WHERE id = :id AND status = 'draft'")->execute(['id' => $t['transfer_id']]);
    stock_log($pdo, 'transfer.cancel', 'inventory_transfer', (int) $t['transfer_id'], ['before' => ['status' => 'draft'], 'after' => ['status' => 'cancelled']]);
    $pdo->commit();
});
ri_done('Cancelled ' . $t['number'], $t['transfer_id'], ri_land('/transfers/', 'cancelled'), 'transferChanged');
