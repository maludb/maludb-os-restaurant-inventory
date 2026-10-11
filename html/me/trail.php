<?php
declare(strict_types=1);
/**
 * /me/trail?from=&to=&page= — my own activity in words (screen `my-trail`), newest first, 50 a page, every row with its site; with a record param
 * (`ingredient`, `recipe`, `purchase_order`, `count`, `scope` — the audit keys; `member` another person's, the admin's) that record's history. Pattern B on #trail-results.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/me/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/me/present.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$record = null;
foreach (['ingredient', 'recipe', 'purchase_order', 'count', 'scope', 'member'] as $k) {
    if (($v = request_integer($k)) !== null) { $record = [$k, $v]; break; }
}
if ($record !== null && $record[0] === 'member' && $record[1] !== $me && !is_ri_admin()) {
    $st = $pdo->prepare('SELECT member_kind FROM members WHERE id = :id');
    $st->execute(['id' => $record[1]]);
    if ($st->fetchColumn() !== 'agent') { require_right('settings.manage'); }
}
$from = request_date('from'); $to = request_date('to');
$filters = ['own' => $record === null, 'from' => $from ?: null, 'to' => $to ?: null];
if ($record !== null) { $filters[$record[0]] = $record[1]; }
$page = max(1, (int) (request_integer('page') ?? 1));
$result = find_my_activity($pdo, $me, $page, $filters);
$rows = $result['rows'];
foreach ($rows as &$r) { $r['sentence'] = activity_sentence($r); }
unset($r);
$query = array_filter([($record[0] ?? 'x') => $record[1] ?? null, 'from' => $filters['from'], 'to' => $filters['to']], static fn ($v) => $v !== null && $v !== '');
if (!(is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'trail-results')) {
    log_screen_view($pdo, 'my-trail');
}
if (wants_json()) {
    respond_screen(['rows' => array_map('present_activity_row', $rows), 'page' => $page, 'more' => $result['more'], 'filters' => $query]);
}
$data = ['rows' => $rows, 'page' => $page, 'more' => $result['more'], 'filters' => $filters, 'query' => $query, 'tz' => member_timezone(), 'record' => $record];
$resultsHtml = view('me/rows.php', $data);
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'trail-results') {
    header('Vary: HX-Request');
    echo $resultsHtml;
    exit;
}
render_screen('My trail', view('me/trail.php', $data + ['resultsHtml' => $resultsHtml]), ['activeNav' => 'my-trail', 'screen' => 'my-trail', 'entity' => $record !== null ? $record[0] : '', 'recordId' => $record !== null ? (string) $record[1] : '']);
