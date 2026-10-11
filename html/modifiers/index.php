<?php
declare(strict_types=1);
/** /modifiers/?source=&unmapped= — the POS modifiers with the unmapped first (screen modifier-list). recipes.write. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('recipes.write');
$pdo = db();
$site = (int) current_site_id();
$filters = ['source' => request_integer('source'), 'unmapped' => request_bool('unmapped')];
$rows = find_modifiers($pdo, $site, $filters);
log_screen_view($pdo, 'modifier-list');
if (wants_json()) { respond_screen(['filters' => $filters, 'modifiers' => array_map('present_modifier', $rows)]); }
$sources = $pdo->prepare('SELECT source_id, name FROM mcp_sources WHERE scope_id = :s AND active ORDER BY name'); $sources->execute(['s' => $site]);
render_screen('Modifiers', view('modifiers/modifiers.php', ['rows' => $rows, 'filters' => $filters, 'sources' => $sources->fetchAll(), 'tz' => member_timezone(), 'notice' => ri_notice($_GET['notice'] ?? null, ['mapped' => ['success', 'Mapped.']])]), ['activeNav' => 'modifier-list', 'screen' => 'modifier-list', 'entity' => 'modifier']);
