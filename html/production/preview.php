<?php
declare(strict_types=1);
/** GET /production/preview?recipe=&batches= — what a batch will consume against on hand (Pattern A under the batch form). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('stock.produce');
$pdo = db();
$recipe = request_string('recipe') !== '' ? stock_recipe_by_ref($pdo, request_string('recipe')) : null;
$batches = (float) str_replace(',', '', request_string('batches') ?: '1');
if ($batches <= 0) { $batches = 1.0; }
$preview = $recipe === null || $recipe['kind'] !== 'prep' ? null : batch_preview($pdo, (int) $recipe['recipe_id'], $batches, (int) current_site_id());
if (wants_json()) { respond_screen(['preview' => $preview]); }
echo view('production/_preview.php', ['preview' => $preview, 'recipe' => $recipe]);
