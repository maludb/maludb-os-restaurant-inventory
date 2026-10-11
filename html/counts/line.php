<?php
declare(strict_types=1);
/** Action `count_line_set` (log count.line_set, ingredient_id): stock.count; ONE of entries (JSON [{unit, qty}] — or a form's whole + whole_unit and loose + loose_unit), gross_weight_g (+ container), or state = zero | skipped. ri_count_line_set() converts and sums; idempotent. A Pattern C request answers the line's partial. */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
counts_begin('stock.count');
$pdo = db();
$me = (int) current_member_id();
$line = count_line_or_404($pdo, request_integer('count_line'));
$count = count_or_404($pdo, (int) $line['count_id']);
$errors = [];
$state = ri_choice('state', 'counted', ['counted', 'zero', 'skipped'], 'The state', $errors, false) ?? 'counted';
$entries = null;
$gross = null;
$container = null;
if ($state === 'counted') {
    $entries = count_entries_from_request($line, $errors);
    [$gross, $container] = count_weight_from_request($pdo, $line, $errors);
}
$note = count_note($errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$pattern = is_htmx_request() && !wants_json();
$new = ri_guard($pdo, static function () use ($pdo, $count, $line, $me, $state, $entries, $gross, $container, $note): array {
        count_require_changeable($count);
        if ($count['status'] === 'recount' && $line['over_tolerance'] && $line['recount_qty_base'] === null) {
            throw new DomainException('This line is over tolerance and waits for a recount by another person — recount it on the review.');
        }
        $pdo->beginTransaction();
        $new = set_count_line($pdo, (int) $line['count_line_id'], $me, $entries, $gross, $container, $state, $note);
        count_log($pdo, 'count.line_set', 'inventory_count_line', (int) $line['count_line_id'], (int) $count['count_id'], ['ingredient_id' => (int) $line['ingredient_id'],
            'after' => ['count_line_id' => (int) $line['count_line_id'], 'ingredient_id' => (int) $line['ingredient_id'], 'state' => $new['state'], 'counted_qty_base' => $new['counted_qty_base'], 'entries' => $entries, 'gross_weight_g' => $gross]]);
        $pdo->commit();
        return $new;
    });
$words = $new['state'] === 'skipped' ? 'Skipped ' . $line['name'] : ($new['state'] === 'zero' ? $line['name'] . ': none on the shelf' : 'Counted ' . $line['name']);
if ($pattern) {
    emit_action_status(true, ['did' => $words, 'record_id' => (int) $line['count_line_id']]);
    hx_trigger('countChanged');
    count_progress_header($pdo, (int) $count['count_id'], (int) $line['storage_area_id']);
    echo count_line_html($pdo, $count, $new, null, true);
    exit;
}
ri_done($words, (int) $line['count_line_id'], ri_land('/counts/' . $count['count_id'] . '?area=' . $line['storage_area_id'], null, 'count-line-' . $line['count_line_id']), 'countChanged',
    ['count_id' => (int) $count['count_id'], 'line' => present_count_line($new)]);
