<?php
declare(strict_types=1);
/** /sales/runs/{run} — screen depletion-run-view: the card and the movements it posted (or, for a void run, those and their reversals); Re-run, Void. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$run = run_or_404($pdo, request_integer('id'));
$scope = (int) $run['scope_id'];
$moves = run_movements($pdo, (int) $run['depletion_run_id']);
log_screen_view($pdo, 'depletion-run-view', ['scope_id' => $scope]);
if (wants_json()) { respond_screen(['run' => present_run($run), 'movements' => array_map(static fn ($m) => ['transaction_id' => (int) $m['transaction_id'], 'txn_type' => $m['txn_type'], 'ingredient_id' => (int) $m['ingredient_id'], 'name' => $m['name'],
    'qty_base' => (float) $m['qty_base'], 'qty' => $m['qty_display'], 'area' => $m['area'], 'value' => $m['value'] === null ? null : (float) $m['value'], 'reverses_id' => $m['reverses_id']], $moves)]); }
$notices = ['voided' => ['warning', 'The run was voided — its rows reversed; the day is due again.']];
render_screen('Run ' . $run['run_no'] . ' · ' . format_date((string) $run['business_date']), view('pos/runs/view.php', ['run' => $run, 'moves' => $moves, 'seesCost' => sees_cost($scope), 'mayRun' => has_right('stock.receive', $scope),
    'mayVoid' => has_right('counts.approve', $scope), 'tz' => site_timezone($scope), 'notice' => ri_notice($_GET['notice'] ?? null, $notices), 'back' => back_link()]), ['activeNav' => 'depletion-run-list', 'screen' => 'depletion-run-view', 'entity' => 'depletion_run', 'recordId' => (string) $run['depletion_run_id']]);
