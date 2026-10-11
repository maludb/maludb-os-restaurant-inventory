<?php
declare(strict_types=1);
/** Action depletion_run_void (counts.approve at the run's site; `deletion` for an agent): ri_depletion_void(run, me, note) — the run's rows reversed, the run void, the day due again. Log depletion.void. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
ri_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$run = run_or_404($pdo, request_integer('run') ?? (ctype_digit((string) req_val('run')) ? (int) req_val('run') : null));
require_right('counts.approve', (int) $run['scope_id']);
$errors = [];
$note = ri_text('note', null, 200, 'note', $errors);
if ($errors !== []) {
    ri_refuse_fields($errors);
}
$r = ri_guard($pdo, static function () use ($pdo, $me, $run, $note): array {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM ri_depletion_void(:r, :by, :n)');
    $st->execute(['r' => $run['depletion_run_id'], 'by' => $me, 'n' => $note]);
    $r = $st->fetch();
    log_activity($pdo, 'depletion.void', 'depletion_run', (int) $run['depletion_run_id'], ['scope_id' => (int) $run['scope_id'], 'after' => ['business_date' => $run['business_date'], 'run_no' => (int) $run['run_no'], 'note' => $note, 'reversed' => (int) $run['ingredients']]]);
    $pdo->commit();
    return $r;
});
ri_done('Voided run ' . $run['run_no'] . ' of ' . $run['business_date'] . ' — its rows reversed; the day is due again', (int) $run['depletion_run_id'], ri_land(return_path('/sales/runs/' . $run['depletion_run_id']), 'voided'), 'depletionChanged');
