<?php
declare(strict_types=1);
/**
 * /purchasing/suggested — the suggested orders (screen `suggested-order-list`; params supplier, due_hours): one card per supplier due within due_hours (36 by default) — its next cut-off and delivery, the protection interval, every line's
 * need, have, on order, cap, raw and rounding (ri_suggested_order(): the rule, step by step), Draft the PO per supplier, Draft all due, Recompute. The application only drafts; a Manager sends. purchasing.draft.
 */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
require_login();
require_right('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$sup = request_integer('supplier');
$hours = (int) (request_integer('due_hours') ?? 36);
$hours = in_array($hours, SUGGESTED_DUE_HOURS, true) ? $hours : 36;
$cards = suggested_orders($pdo, $site, $sup, $hours);
log_screen_view($pdo, 'suggested-order-list', ['after' => ['due_hours' => $hours, 'supplier_id' => $sup]]);
if (wants_json()) { respond_screen(['due_hours' => $hours, 'suppliers' => array_map('present_suggested_card', $cards)]); }
$tally = ['d' => (int) request_integer('d'), 'c' => (int) request_integer('c'), 'a' => (int) request_integer('a')];
$notices = ['run' => ['info', match (true) {
    request_string('r') === 'covered' => 'Nothing to order from this supplier before its next delivery — everything is covered.',
    request_string('r') === 'already' => 'Already drafted today.',
    request_string('r') === 'read' => 'The suggestion is recomputed — nothing was drafted.',
    default => ($tally['d'] + $tally['c'] + $tally['a']) === 0 ? 'No supplier has a cut-off in that window.' : implode(', ', array_filter([$tally['d'] > 0 ? $tally['d'] . ' drafted' : '', $tally['c'] > 0 ? $tally['c'] . ' covered' : '', $tally['a'] > 0 ? $tally['a'] . ' already drafted today' : ''])) . '.',
}]];
render_screen('Suggested orders', view('purchasing/suggested/index.php', ['cards' => $cards, 'hours' => $hours, 'supplierId' => $sup, 'suppliers' => suppliers_with_schedule($pdo, $site), 'sees' => sees_cost($site), 'notice' => ri_notice($_GET['notice'] ?? null, $notices),
    'nowIso' => (string) one_value($pdo, 'SELECT now()')]), ['activeNav' => 'suggested-order-list', 'screen' => 'suggested-order-list', 'entity' => 'purchase_order']);
