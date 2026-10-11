<?php
declare(strict_types=1);
/** Action `count_recount` (log count.recount, ingredient_id): stock.count; a line over tolerance on a count in recount, counted again by ANOTHER person (the schema refuses the counter) — entries (JSON, or whole + loose), gross_weight_g, note; then the review runs again so the flags and the status refresh. */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
counts_begin('stock.count');
$pdo = db();
$me = (int) current_member_id();
$line = count_line_or_404($pdo, request_integer('count_line'));
$count = count_or_404($pdo, (int) $line['count_id']);
$errors = [];
$entries = count_entries_from_request($line, $errors);
[$gross] = count_weight_from_request($pdo, $line, $errors);
$note = count_note($errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$new = ri_guard($pdo, static function () use ($pdo, $count, $line, $me, $entries, $gross, $note): array {
    if ($count['status'] !== 'recount' || !$line['over_tolerance'] || $line['recount_qty_base'] !== null) {
        throw new DomainException($line['name'] . ' is not waiting for a recount' . ($count['status'] === 'recount' ? '.' : ' — ' . $count['number'] . ' is ' . count_status_words($count['status']) . '.'));
    }
    $pdo->beginTransaction();
    $new = recount_line($pdo, (int) $line['count_line_id'], $me, $entries, $gross, $note);
    count_log($pdo, 'count.recount', 'inventory_count_line', (int) $line['count_line_id'], (int) $count['count_id'], ['ingredient_id' => (int) $line['ingredient_id'],
        'after' => ['count_line_id' => (int) $line['count_line_id'], 'ingredient_id' => (int) $line['ingredient_id'], 'recount_qty_base' => $new['recount_qty_base'], 'recount_by' => $me, 'entries' => $entries, 'gross_weight_g' => $gross]]);
    $pdo->commit();
    return $new;
});
ri_done('Recounted ' . $line['name'], (int) $line['count_line_id'], ri_land('/counts/' . $count['count_id'] . '/review', 'recounted', 'review-row-' . $line['count_line_id']), 'countChanged',
    ['count_id' => (int) $count['count_id'], 'line' => present_count_line($new)]);
