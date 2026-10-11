<?php
declare(strict_types=1);
/** Action `waste_review`: waste.review (a Chef's); a posted log becomes reviewed. Log waste.review. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('waste.review');
$pdo = db();
$w = waste_or_404($pdo, (int) (req_val('waste') ?? 0) ?: null);
if ($w['status'] !== 'posted') { refuse(422, 'Waste ' . $w['number'] . ' is ' . $w['status'] . ' — only a posted log is reviewed.'); }
$me = (int) current_member_id();
ri_guard($pdo, static function () use ($pdo, $w, $me): void {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE waste_logs SET status = 'reviewed', reviewed_by = :by, reviewed_at = now() WHERE id = :id AND status = 'posted'")->execute(['by' => $me, 'id' => $w['waste_log_id']]);
    stock_log($pdo, 'waste.review', 'waste_log', (int) $w['waste_log_id'], ['ingredient_id' => $w['ingredient_id'], 'recipe_id' => $w['recipe_id'], 'before' => ['status' => 'posted'], 'after' => ['status' => 'reviewed']]);
    $pdo->commit();
});
ri_done('Reviewed ' . $w['number'], $w['waste_log_id'], ri_land('/waste/' . $w['waste_log_id'], 'reviewed'), 'wasteChanged');
