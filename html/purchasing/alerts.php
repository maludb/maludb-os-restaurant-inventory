<?php
declare(strict_types=1);
/** /purchasing/alerts — the cost moves over the threshold at this site (screen `price-alert-list`; params seen, from, page). Reads open to every reader (ri.read); the whole screen is cost, so one who sees none gets the sentence and no rows. Seen needs purchasing.draft, the threshold prices.write. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$sees = sees_cost($site);
$filters = ['seen' => request_string('seen'), 'from' => request_string('from')];
$page = max(1, request_integer('page') ?? 1);
$r = alerts_list($pdo, $site, $filters, $page);
log_screen_view($pdo, 'price-alert-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $r['total'], 'open' => $r['open'], 'alerts' => array_map('present_alert', $r['rows']), 'thresholds' => $sees ? thresholds($pdo) : null,
        'cost_withheld' => !$sees, 'may' => ['seen' => has_right('purchasing.draft'), 'threshold' => has_right('prices.write')]]);
}
$notices = ['seen' => ['success', 'Marked seen.'], 'threshold' => ['success', 'The threshold is saved.']];
render_screen('Price alerts', view('purchasing/alerts.php', ['rows' => $r['rows'], 'total' => $r['total'], 'open' => $r['open'], 'page' => $page, 'filters' => $filters, 'seesCost' => $sees, 'thresholds' => $sees ? thresholds($pdo) : null,
    'maySee' => has_right('purchasing.draft'), 'mayThreshold' => has_right('prices.write'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'price-alert-list', 'screen' => 'price-alert-list', 'entity' => 'price_alert']);
