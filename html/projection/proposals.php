<?php
declare(strict_types=1);
/** /projection/proposals — the Purchasing agent's day (screen `proposal-list`; params date, kind, status): its findings under the morning note's seven headings, each accepted or dismissed by a person, the acceptance rate, Send the morning note. purchasing.draft. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
require_login();
require_right('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$date = proj_query_date('date', site_date($pdo, $site));
$filters = ['kind' => request_string('kind'), 'status' => request_string('status')];
$day = proposals($pdo, $site, $date, $filters);
$rate = acceptance_rate($pdo, $site, 30);
$last = proj_row($pdo, "SELECT occurred_at FROM activity_log WHERE action = 'buyer.note' AND scope_id = :s AND after->>'date' = :d AND (after->>'sent')::boolean ORDER BY id DESC LIMIT 1", ['s' => $site, 'd' => $date]);
log_screen_view($pdo, 'proposal-list', ['after' => ['date' => $date]]);
if (wants_json()) { respond_screen(['date' => $date, 'note_sent_at' => $last === null ? null : json_ts($last['occurred_at']), 'acceptance' => $rate, 'proposals' => array_map('present_proposal', $day['all']), 'counts' => $day['counts']]); }
$notices = ['sent' => ['success', 'The morning note is sent.'], 'already' => ['info', 'The morning note for this day was already sent.'], 'nobody' => ['warning', 'There is no one to send the note to — name the site\'s Manager in the site settings.'],
    'accepted' => ['success', 'Accepted.'], 'dismissed' => ['info', 'Dismissed.'], 'proposed' => ['success', 'Proposed.']];
render_screen('Purchasing agent', view('projection/proposals.php', ['day' => $day, 'date' => $date, 'filters' => $filters, 'rate' => $rate, 'lastSent' => $last['occurred_at'] ?? null, 'today' => site_date($pdo, $site),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'proposal-list', 'screen' => 'proposal-list', 'entity' => 'buyer_proposal']);
