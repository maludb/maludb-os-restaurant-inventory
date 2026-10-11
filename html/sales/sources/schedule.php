<?php
declare(strict_types=1);
/** Action source_schedule_set (settings.manage; `other` for an agent): schedule_minutes ≥ 0 (0 = on arrival). Log source.schedule_set. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
ri_handler_begin();
$pdo = db();
$src = source_or_404($pdo, req_val('source'), 'settings.manage');
$errors = [];
if (!req_has('schedule_minutes') || req_val('schedule_minutes') === '') {
    ri_refuse_fields(['schedule_minutes' => 'How often should it be pulled? 0 for on arrival.']);
}
$m = ri_int('schedule_minutes', null, 0, 10080, 'schedule', $errors);
if ($errors !== []) {
    ri_refuse_fields($errors);
}
ri_guard($pdo, static function () use ($pdo, $src, $m): void {
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE sources SET schedule_minutes = :m WHERE id = :id')->execute(['m' => $m, 'id' => $src['id']]);
    source_log($pdo, 'source.schedule_set', (int) $src['id'], (int) $src['scope_id'], ['before' => ['schedule_minutes' => (int) $src['schedule_minutes']], 'after' => ['schedule_minutes' => $m]]);
    $pdo->commit();
});
ri_done('Pulling ' . $src['name'] . ' ' . schedule_label((int) $m), (int) $src['id'], ri_land(return_path('/sales/sources/' . $src['id']), 'saved'), 'sourceChanged');
