<?php
declare(strict_types=1);
/** Action `period_reopen` (log period.reopen; `other` for an agent — it pauses): settings.manage; period (the last closed one), reason (required, up to 200 characters) — ri_period_reopen(); "A later period is closed — reopen it first". Location /periods/{id}. */
require_once dirname(__DIR__, 2) . '/app/features/periods/handler.php';
period_begin('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$p = period_or_404($pdo, request_integer('period'));
$errors = [];
$reason = ri_text('reason', null, 200, 'The reason', $errors, true);
if ($reason === null && !isset($errors['reason'])) { $errors['reason'] = 'Say why it is reopened — the reason is kept in the trail.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
ri_guard($pdo, static function () use ($pdo, $p, $me, $reason): void {
    $pdo->beginTransaction();
    $c = reopen_period($pdo, (int) $p['period_id'], $me, $reason);
    period_log($pdo, 'period.reopen', 'period', (int) $p['period_id'], ['after' => ['period_no' => (int) $c['no'], 'reason' => $reason]]);
    $pdo->commit();
});
$open = find_period($pdo, (int) $p['period_id']);
ri_done('Reopened ' . $open['label'], (int) $p['period_id'], ri_land('/periods/' . $p['period_id'], 'reopened'), 'periodChanged', ['period_id' => (int) $p['period_id'], 'period' => present_period($open, period_figures($pdo, $open), sees_cost())]);
