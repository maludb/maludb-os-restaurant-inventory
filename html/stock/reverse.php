<?php
declare(strict_types=1);
/** Action `stock_reverse`: counts.approve (a reversal changes the book like a count does); ri_reverse_transaction() posts the opposite row, once. Log stock.reverse (ingredient_id). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('counts.approve');
$pdo = db();
$t = txn_for_reverse($pdo, (int) (req_val('transaction') ?? 0));
if ($t === null || $t['scope_id'] !== (int) current_site_id()) { respond_not_found('That movement is not here.'); }
if ($t['txn_type'] === 'reversal') { refuse(422, 'A reversal is not reversed — post the movement again.'); }
$errs = [];
$note = ri_text('note', null, 500, 'The note', $errs);
if ($errs !== []) { ri_refuse_fields($errs); }
$me = (int) current_member_id();
$rev = ri_guard($pdo, static function () use ($pdo, $t, $me, $note): int {
    $pdo->beginTransaction();
    $id = (int) one_value($pdo, 'SELECT ri_reverse_transaction(:t, :by, :n)', ['t' => $t['transaction_id'], 'by' => $me, 'n' => $note]);
    stock_log($pdo, 'stock.reverse', 'inventory_transaction', $t['transaction_id'], ['ingredient_id' => $t['ingredient_id'], 'after' => ['reversal_id' => $id, 'reverses_id' => $t['transaction_id'], 'type' => $t['txn_type'], 'qty_base' => (string) (-(float) $t['qty_base'])]]);
    $pdo->commit();
    return $id;
});
ri_done('Reversed the ' . (TXN_WORDS[$t['txn_type']] ?? $t['txn_type']) . ' of ' . $t['ingredient'], $rev, ri_land('/stock/movements?ingredient=' . $t['ingredient_id'], 'reversed'), 'stockChanged',
    ['reversal_id' => $rev, 'reverses_id' => $t['transaction_id'], 'qty_base' => number_format(-(float) $t['qty_base'], 4, '.', '')]);
