<?php
declare(strict_types=1);
/** Action `usage_export` (log export.download): exports.all; from, to (up to 400 days), format (csv or json), by (ingredient or category), basis — the report's rows from ri_usage() as a download named usage-{site}-{from}-{to}.csv; JSON mode answers {ok, rows, count} for format=json (up to 2,000 rows — a larger window is told to narrow). */
require_once dirname(__DIR__, 2) . '/app/features/periods/handler.php';
require_login();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) { refuse(405, 'Use GET or POST.'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { ri_handler_begin(); stock_refuse_eval(); }
require_right('exports.all');
$pdo = db();
$site = (int) current_site_id();
$w = usage_window_or_refuse($pdo, $site);
$format = request_string('format', 'csv');
$by = request_string('by', 'ingredient');
$errors = [];
if (!in_array($format, ['csv', 'json'], true)) { $errors['format'] = 'The format is csv or json.'; }
if (!in_array($by, ['ingredient', 'category'], true)) { $errors['by'] = 'By is ingredient or category.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
$u = usage($pdo, $site, $w['from'], $w['to'], ['basis' => request_string('basis'), 'sort' => request_string('sort'), 'category' => request_string('category')]);
$n = $by === 'category' ? count($u['categories']) : count($u['rows']);
if ($format === 'json' && $n > 2000) { refuse(422, 'That window has ' . $n . ' rows — narrow it (up to 2,000 rows as JSON).'); }
$sName = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) (current_site()['name'] ?? $site))) ?: (string) $site;
log_activity($pdo, 'export.download', 'site', $site, ['scope_id' => $site, 'after' => ['document' => 'usage', 'from' => $w['from'], 'to' => $w['to'], 'by' => $by, 'format' => $format, 'rows' => $n, 'basis' => $u['basis']]]);
emit_action_status(true, ['did' => 'Exported ' . $n . ' usage rows', 'record_id' => $site]);
if ($format === 'json') {
    $body = json_decode(usage_json($u, $by), true);
    if (wants_json() || ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') { json_response(['ok' => true, 'count' => $n, 'rows' => $by === 'category' ? $body['categories'] : $body['rows'], 'from' => $w['from'], 'to' => $w['to'], 'by' => $by] + ($u['withheld'] ? ['cost_withheld' => true] : [])); }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="usage-' . $sName . '-' . $w['from'] . '-' . $w['to'] . '.json"');
    echo usage_json($u, $by);
    exit;
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="usage-' . $sName . '-' . $w['from'] . '-' . $w['to'] . ($by === 'category' ? '-by-category' : '') . '.csv"');
echo usage_csv($u, $by);
