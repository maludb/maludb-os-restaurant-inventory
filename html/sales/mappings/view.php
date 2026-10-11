<?php
declare(strict_types=1);
/** /sales/mappings/{mapping}?copy= — screen mapping-edit: name, delimiter, date format, one column per field (external_id, business_date, plu, qty required), the void and comp words, the modifier split; a sample file previewed (/sales/mappings/preview.php). A built-in is read-only — Copy makes ours. /sales/mappings/new?copy=generic starts a copy. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$key = request_string('key');
$copyOf = request_string('copy');
$m = $key === '' || $key === 'new' ? null : mapping_for($pdo, $key);
if ($key !== '' && $key !== 'new' && $m === null) { refuse(404, 'That mapping is not here.'); }
$base = $m ?? mapping_for($pdo, $copyOf !== '' ? $copyOf : 'generic');
log_screen_view($pdo, 'mapping-edit');
if (wants_json()) { respond_screen(['mapping' => $m === null ? null : ['key' => $m['key'], 'name' => $m['name'], 'builtin' => $m['is_builtin'], 'mapping' => $m['mapping']], 'fields' => RiPosNormalizer::FIELDS, 'required' => ['external_id', 'business_date', 'plu', 'qty']]); }
$notices = ['saved' => ['success', 'Saved.']];
render_screen($m === null ? 'New mapping' : 'Mapping · ' . $m['name'], view('pos/mappings/edit.php', ['m' => $m, 'base' => $base, 'mayEdit' => has_right('settings.manage') && ($m === null || !$m['is_builtin']), 'mayCopy' => has_right('settings.manage'),
    'notice' => ri_notice($_GET['notice'] ?? null, $notices), 'back' => back_link()]), ['activeNav' => 'mapping-list', 'screen' => 'mapping-edit', 'entity' => 'sales_file_mapping', 'recordId' => $m['key'] ?? '']);
