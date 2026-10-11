<?php
declare(strict_types=1);
/** Action source_delete (records.delete; `deletion` for an agent): a source with sales → 422 "Pause it — its sales are history"; one without is removed with its pulls and credentials. Log source.delete. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
ri_handler_begin();
$pdo = db();
$src = source_or_404($pdo, req_val('source'), 'records.delete');
if ((int) one_value($pdo, 'SELECT count(*) FROM pos_sales WHERE source_id = :s', ['s' => $src['id']]) > 0) {
    refuse(422, 'Pause it — its sales are history.');
}
ri_guard($pdo, static function () use ($pdo, $src): void {
    $pdo->beginTransaction();
    source_log($pdo, 'source.delete', (int) $src['id'], (int) $src['scope_id'], ['before' => source_loggable($src)]);
    $pdo->prepare('UPDATE sources SET credential_id = NULL, last_pull_id = NULL WHERE id = :id')->execute(['id' => $src['id']]);
    $pdo->prepare('DELETE FROM sources WHERE id = :id')->execute(['id' => $src['id']]);
    $pdo->commit();
});
ri_done('Deleted the source ' . $src['name'], (int) $src['id'], ri_land('/sales/sources/', 'deleted'), 'sourceChanged');
