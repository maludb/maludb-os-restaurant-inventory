<?php
declare(strict_types=1);
/** /sales/runs/?status=&from=&to=&page= — screen depletion-run-list: date, status, run no, lines total / depleted / unmapped / voided, modifiers unmapped, ingredients, value (walled), started, by (a person or "worker"). */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$f = ['status' => request_string('status'), 'from' => request_string('from'), 'to' => request_string('to')];
$page = max(1, request_integer('page') ?? 1);
$r = run_list($pdo, $site, $f, $page);
log_screen_view($pdo, 'depletion-run-list');
if (wants_json()) { respond_screen(['filters' => $f, 'page' => $page, 'total' => $r['total'], 'runs' => array_map('present_run', $r['rows'])]); }
render_screen('Depletion runs', view('pos/runs/list.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'f' => $f, 'seesCost' => sees_cost($site), 'tz' => site_timezone($site)]),
    ['activeNav' => 'depletion-run-list', 'screen' => 'depletion-run-list', 'entity' => 'depletion_run']);
