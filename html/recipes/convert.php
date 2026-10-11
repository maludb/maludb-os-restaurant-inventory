<?php
declare(strict_types=1);
/** GET /recipes/convert?ingredient=&sub_recipe=&qty=&unit= — the conversion sentence (Pattern A fragment) over ri_to_base(); never writes. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$ing = request_string('ingredient'); $sub = request_string('sub_recipe');
$r = convert_sentence($pdo, $ing === '' ? null : ri_resolve_ingredient($pdo, $ing), $sub === '' ? null : ri_resolve_recipe($pdo, $sub), request_string('qty'), request_string('unit'));
if (wants_json()) { json_response(['ok' => true] + $r); }
header('Cache-Control: no-store');
echo view('recipes/partials/conversion.php', ['r' => $r]);
