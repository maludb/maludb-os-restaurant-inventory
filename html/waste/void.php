<?php
declare(strict_types=1);
/** Action `waste_void`: waste.review; ri_void_waste() reverses every movement the log posted. Log waste.void. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('waste.review');
$pdo = db();
$w = waste_or_404($pdo, (int) (req_val('waste') ?? 0) ?: null);
$errs = [];
$note = ri_text('note', null, 500, 'The note', $errs);
$me = (int) current_member_id();
ri_guard($pdo, static function () use ($pdo, $w, $me, $note): void {
    $pdo->beginTransaction();
    $pdo->prepare('SELECT ri_void_waste(:w, :by, :n)')->execute(['w' => $w['waste_log_id'], 'by' => $me, 'n' => $note]);
    stock_log($pdo, 'waste.void', 'waste_log', (int) $w['waste_log_id'], ['ingredient_id' => $w['ingredient_id'], 'recipe_id' => $w['recipe_id'], 'before' => ['status' => $w['status']], 'after' => ['status' => 'voided']]);
    $pdo->commit();
});
ri_done('Voided ' . $w['number'] . ' — its movements are reversed', $w['waste_log_id'], ri_land('/waste/' . $w['waste_log_id'], 'voided'), 'stockChanged', ['waste_id' => $w['waste_log_id'], 'transactions' => stock_doc_txns($pdo, 'waste', $w['waste_log_id'])]);
