<?php
declare(strict_types=1);
/** Action `morning_note_send` (log buyer.note): purchasing.draft; date (today by default). The note is assembled from the day's proposals (the SQL gatherers where the agent made none) and sent to the site's Manager once a day; a second send is a 200 that names the earlier one. Location the day's proposals. */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
proj_begin('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$errors = [];
$date = proj_date('date', site_date($pdo, $site), 'The date', $errors) ?? site_date($pdo, $site);
if ($errors !== []) { ri_refuse_fields($errors); }
$r = ri_guard($pdo, static fn (): array => send_morning_note($pdo, $site, $date, $me));
$tz = site_timezone($site);
if ($r['already'] !== null) {
    ri_done('The morning note was already sent at ' . format_ts($r['already'], $tz, 'g:i A'), $site, ri_land('/projection/proposals?date=' . $date, 'already'), 'proposalChanged', ['sent' => false, 'already' => json_ts($r['already'])]);
}
ri_done($r['sent'] ? 'Morning note sent to ' . count($r['recipients']) . ' ' . (count($r['recipients']) === 1 ? 'person' : 'people') : 'There is no one to send the note to — name the site\'s Manager in the site settings', $site,
    ri_land('/projection/proposals?date=' . $date, $r['sent'] ? 'sent' : 'nobody'), 'proposalChanged', ['sent' => $r['sent'], 'recipients' => count($r['recipients']), 'headings' => $r['headings'], 'proposals' => $r['proposals']]);
