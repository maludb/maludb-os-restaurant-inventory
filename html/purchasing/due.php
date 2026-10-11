<?php
declare(strict_types=1);
/** /purchasing/due — the deliveries expected on or before a day at this site (screen `deliveries-due`; param on, today by default): ri_deliveries_due(). Reads open to every reader (ri.read); Receive needs stock.receive. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$errors = [];
$on = po_date(request_string('on'), 'on', 'The day', $errors) ?? site_today($site);
$rows = deliveries_due($pdo, $site, $on);
log_screen_view($pdo, 'deliveries-due');
if (wants_json()) {
    respond_screen(['on' => $on, 'deliveries' => array_map('present_due', $rows), 'may' => ['receive' => has_right('stock.receive')]]);
}
render_screen('Deliveries due', view('purchasing/due.php', ['rows' => $rows, 'on' => $on, 'today' => site_today($site), 'mayReceive' => has_right('stock.receive'), 'notice' => $errors === [] ? null : ['warning', (string) reset($errors)]]),
    ['activeNav' => 'deliveries-due', 'screen' => 'deliveries-due', 'entity' => 'purchase_order']);
