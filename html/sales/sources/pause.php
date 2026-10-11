<?php
declare(strict_types=1);
/** Action source_pause (settings.manage): paused yes (with a reason) or no (resume — the failures and the backoff cleared). Log source.pause. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
ri_handler_begin();
$pdo = db();
$src = source_or_404($pdo, req_val('source'), 'settings.manage');
$errors = [];
$paused = ri_yes('paused', true);
$reason = ri_text('reason', null, 200, 'reason', $errors);
if ($errors !== []) {
    ri_refuse_fields($errors);
}
ri_guard($pdo, static function () use ($pdo, $src, $paused, $reason): void {
    $pdo->beginTransaction();
    if ($paused) {
        $pdo->prepare('UPDATE sources SET paused_at = COALESCE(paused_at, now()), paused_reason = :r WHERE id = :id')->execute(['r' => $reason, 'id' => $src['id']]);
    } else {
        $pdo->prepare('UPDATE sources SET paused_at = NULL, paused_reason = NULL, consecutive_failures = 0, backoff_until = NULL WHERE id = :id')->execute(['id' => $src['id']]);
    }
    source_log($pdo, 'source.pause', (int) $src['id'], (int) $src['scope_id'], ['after' => ['paused' => $paused, 'reason' => $reason]]);
    $pdo->commit();
});
ri_done(($paused ? 'Paused ' : 'Resumed ') . $src['name'], (int) $src['id'], ri_land(return_path('/sales/sources/' . $src['id']), $paused ? 'paused' : 'resumed'), 'sourceChanged');
