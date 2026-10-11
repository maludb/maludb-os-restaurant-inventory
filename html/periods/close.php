<?php
declare(strict_types=1);
/** Action `period_close` (log period.close; `other` for an agent — it pauses): periods.close; period (the first open one past its last day, every day's sales depleted, no earlier one open), closing_count (an approved count) optional — ri_period_close() freezes the figures from ri_usage() and ri_food_sales() and sets the next period's opening; its refusals are the sentences. Location /periods/{id}. */
require_once dirname(__DIR__, 2) . '/app/features/periods/handler.php';
period_begin('periods.close');
$pdo = db();
$me = (int) current_member_id();
$p = period_or_404($pdo, request_integer('period'));
$errors = [];
$count = ri_ref($pdo, 'closing_count', null, 'SELECT 1 FROM mcp_inventory_counts WHERE count_id = :id AND scope_id = ' . (int) $p['scope_id'], 'count at this site', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$res = ri_guard($pdo, static function () use ($pdo, $p, $me, $count): array {
    $pdo->beginTransaction();
    $c = close_period($pdo, (int) $p['period_id'], $me, $count);
    period_log($pdo, 'period.close', 'period', (int) $p['period_id'], ['count_id' => $c['closing_count_id'] === null ? null : (int) $c['closing_count_id'],
        'after' => ['period_no' => (int) $c['no'], 'fiscal_year' => (int) $c['fiscal_year'], 'starts_on' => $c['starts_on'], 'ends_on' => $c['ends_on'], 'closing_count_id' => $c['closing_count_id'] === null ? null : (int) $c['closing_count_id'],
            'opening_value' => $c['opening_value'], 'purchases_value' => $c['purchases_value'], 'ending_value' => $c['ending_value'], 'cogs_value' => $c['cogs_value'], 'theoretical_value' => $c['theoretical_value'],
            'waste_value' => $c['waste_value'], 'food_sales' => $c['food_sales']]]);
    $pdo->commit();
    return $c;
});
$closed = find_period($pdo, (int) $p['period_id']);
$did = 'Closed ' . $closed['label'] . (sees_cost() && $res['cogs_value'] !== null ? ': cost of goods sold $' . number_format((float) $res['cogs_value'], 2) : '');
ri_done($did, (int) $p['period_id'], ri_land('/periods/' . $p['period_id'], 'closed'), 'periodChanged', ['period_id' => (int) $p['period_id'], 'period' => present_period($closed, period_figures($pdo, $closed), sees_cost())]);
