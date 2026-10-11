<?php
declare(strict_types=1);
/** Action `transfer_send`: stock.transfer at the sending site; ri_transfer_send() — a transfer_out per line at the sender's average, in transit. Log transfer.send. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.transfer');
$pdo = db();
$t = transfer_or_404($pdo, (int) (req_val('transfer') ?? 0) ?: null);
if ($t['scope_id'] !== (int) current_site_id()) { respond_not_found('That transfer is not here.'); }
$me = (int) current_member_id();
ri_guard($pdo, static function () use ($pdo, $t, $me): void {
    $pdo->beginTransaction();
    $pdo->prepare('SELECT ri_transfer_send(:t, :by)')->execute(['t' => $t['transfer_id'], 'by' => $me]);
    stock_log($pdo, 'transfer.send', 'inventory_transfer', (int) $t['transfer_id'], ['after' => ['status' => 'in_transit', 'lines' => array_map(static fn (array $l): array => ['ingredient_id' => $l['ingredient_id'], 'qty_base' => $l['qty_base'], 'unit' => $l['unit_code']], transfer_lines($pdo, (int) $t['transfer_id']))]]);
    $pdo->commit();
});
$lines = transfer_lines($pdo, $t['transfer_id']);
ri_done('Sent ' . $t['number'] . ': ' . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') . ' left ' . $t['from_area'], $t['transfer_id'], ri_land('/transfers/' . $t['transfer_id'], 'sent'), 'stockChanged', ['transfer_id' => $t['transfer_id'], 'transactions' => stock_doc_txns($pdo, 'transfer', $t['transfer_id'])]);
