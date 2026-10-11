<?php
declare(strict_types=1);
/** /counts/containers/?ingredient=&edit= — the bottles and kegs the bar weighs (screen `container-list`). ri.read to see; ingredients.write adds and edits (Edit loads the row's values into the form). */
require_once dirname(__DIR__, 3) . '/app/features/counts/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$ingredientId = request_integer('ingredient');
$ingredient = $ingredientId === null ? null : find_ingredient($pdo, $ingredientId);
if ($ingredientId !== null && $ingredient === null) { respond_not_found('That ingredient is not here.'); }
$rows = containers($pdo, $ingredientId);
$edit = request_integer('edit') === null ? null : find_container($pdo, (int) request_integer('edit'));
log_screen_view($pdo, 'container-list');
if (wants_json()) { respond_screen(['ingredient_id' => $ingredientId, 'containers' => array_map('present_container', $rows)]); }
$notices = ['saved' => ['success', 'Saved.'], 'created' => ['success', 'Added.']];
render_screen('Bottles and kegs', view('counts/containers/index.php', ['rows' => $rows, 'ingredient' => $ingredient, 'edit' => $edit, 'mayWrite' => has_right('ingredients.write'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'container-list', 'screen' => 'container-list', 'entity' => 'container']);
