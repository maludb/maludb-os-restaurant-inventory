<?php
declare(strict_types=1);
/** Action depletion_run (stock.receive at the site): ri_depletion_run(site, business_date, me) — a live run is voided and its rows reversed first; a date with no sales → 422 "No sales on …"; a site with no storage area → the function's sentence and the day left due. The reply is the run's card. Log depletion.run. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
ri_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$site = req_has('site') ? (int) req_val('site') : (int) current_site_id();
require_site($site);
require_right('stock.receive', $site);
$date = req_val('business_date');
if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
    ri_refuse_fields(['business_date' => 'Name the business date (YYYY-MM-DD).']);
}
if ((int) one_value($pdo, 'SELECT count(*) FROM pos_sales WHERE scope_id = :s AND business_date = :d::date', ['s' => $site, 'd' => $date]) === 0) {
    refuse(422, 'No sales on ' . $date . '.');
}
$card = pos_run_day($pdo, $site, $date, $me);
if (isset($card['error'])) {
    refuse(422, (string) $card['error']);
}
$did = 'Depleted ' . $date . ' (run ' . $card['run_no'] . ', ' . $card['status'] . '): ' . $card['lines_depleted'] . ' line' . ($card['lines_depleted'] === 1 ? '' : 's') . ', ' . $card['ingredients'] . ' ingredient' . ($card['ingredients'] === 1 ? '' : 's') . ($card['lines_unmapped'] ? ', ' . $card['lines_unmapped'] . ' unmapped' : '');
ri_done($did, $card['run_id'], ri_land(return_path('/sales/' . $date), 'depleted'), 'depletionChanged', ['card' => sees_cost($site) ? $card : array_merge($card, ['value' => null])]);
