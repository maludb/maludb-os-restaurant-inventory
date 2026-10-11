<?php
declare(strict_types=1);
/** GET /recipes/activate-preview?version=&from= — what activation would do, as a sentence (Pattern A fragment); never writes. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('recipes.write');
$pdo = db();
$v = version_or_404($pdo, request_integer('version'));
$from = request_date('from') ?: date('Y-m-d');
$r = activation_preview($pdo, (int) $v['recipe_version_id'], $from);
if (wants_json()) { json_response(['ok' => true] + $r); }
header('Cache-Control: no-store');
echo view('recipes/partials/activate-preview.php', ['r' => $r]);
