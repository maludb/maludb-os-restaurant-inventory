<?php
declare(strict_types=1);
/** POST /sales/mappings/preview.php (settings.manage): a sample file through the mapping as the form stands (`mapping` JSON or the fields, else the key's) — the first five rows parsed as a table, the missing required columns in red. Keeps no file; writes nothing. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
ri_handler_begin();
$pdo = db();
require_right('settings.manage');
$base = mapping_for($pdo, (string) (req_val('mapping_key') ?: req_val('key') ?: 'generic'));
$m = $base['mapping'] ?? [];
foreach (['delimiter', 'date_format', 'modifier_split', 'comp_rule'] as $k) { if (req_has($k)) { $m[$k] = (string) req_val($k); } }
foreach (['void_values', 'comp_values'] as $k) { if (req_has($k)) { $m[$k] = array_values(array_filter(array_map('trim', explode(',', mb_strtolower((string) req_val($k)))), 'strlen')); } }
foreach (RiPosNormalizer::FIELDS as $f) { if (req_has('col_' . $f)) { $m['columns'][$f] = req_val('col_' . $f) === '' ? null : req_val('col_' . $f); } }
[$tmp, $fileName] = pos_uploaded_file('sample');
[$tz, $cut] = pos_site_clock($pdo, (int) current_site_id());
try {
    $r = RiPosFileConnector::read($tmp, $m, $tz, $cut, $fileName);
} catch (DomainException $e) {
    refuse(422, $e->getMessage());
}
$rows = [];
foreach ($r['checks'] as $c) { foreach ($c['lines'] as $l) { if (count($rows) < 5) { $rows[] = ['external_id' => $c['external_id'], 'business_date' => $c['business_date'], 'closed_at' => $c['closed_at'], 'plu' => $l['plu'], 'name' => $l['name'], 'qty' => $l['qty'], 'line_total' => $l['line_total'], 'voided' => $l['voided'], 'comp' => $l['comp'], 'modifiers' => array_column($l['modifiers'], 'code')]; } } }
emit_action_status(true, ['did' => 'Previewed ' . $fileName]);
if (wants_json()) { respond_saved(['headers' => $r['headers'], 'rows' => $rows, 'missing' => $r['missing'], 'bad' => array_slice($r['bad'], 0, 20)]); }
echo view('pos/mappings/_preview-table.php', ['headers' => $r['headers'], 'rows' => $rows, 'missing' => $r['missing'], 'bad' => $r['bad'], 'columns' => (array) ($m['columns'] ?? [])]);
