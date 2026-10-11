<?php
declare(strict_types=1);
/** Action `count_discard` (log count.discard; `deletion` for an agent — it pauses): counts.approve; a count that is not approved — nothing is posted; the reason is logged. */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
counts_begin('counts.approve');
$pdo = db();
$me = (int) current_member_id();
$count = count_or_404($pdo, request_integer('count'));
$errors = [];
$reason = ri_text('reason', null, 500, 'The reason', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
ri_guard($pdo, static function () use ($pdo, $count, $me, $reason): void {
    if ($count['status'] === 'discarded') { throw new DomainException('Count ' . $count['number'] . ' is already discarded.'); }
    $pdo->beginTransaction();
    discard_count($pdo, (int) $count['count_id'], $me, $reason);
    count_log($pdo, 'count.discard', 'inventory_count', (int) $count['count_id'], (int) $count['count_id'], ['after' => ['number' => $count['number'], 'reason' => $reason]]);
    $pdo->commit();
});
ri_done('Discarded ' . $count['number'], (int) $count['count_id'], ri_land('/counts/', 'discarded'), 'countChanged', ['count_id' => (int) $count['count_id']]);
