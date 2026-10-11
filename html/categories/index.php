<?php
declare(strict_types=1);
/** /categories/ — the ingredient categories in order (screen `category-list`): the add form and inline row forms. ingredients.write. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ingredients.write');
$pdo = db();
$rows = categories($pdo, false);
log_screen_view($pdo, 'category-list');
if (wants_json()) { respond_screen(['categories' => $rows]); }
$notices = ['category_saved' => ['success', 'The category is saved.']];
render_screen('Categories', view('catalog/categories.php', ['rows' => $rows, 'edit' => request_integer('category'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'category-list', 'screen' => 'category-list', 'entity' => 'ingredient_category']);
