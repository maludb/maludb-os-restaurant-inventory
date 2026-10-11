<?php
declare(strict_types=1);
/** /ingredients/{id}/yield-tests/new — a yield test (screen `yield-test-add`): AP and EP with their units, trim and cook loss, by-products, apply. ingredients.write; people only. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
require_login();
require_right('ingredients.write');
require_human();
$pdo = db();
$i = catalog_ingredient_or_404($pdo, request_integer('id') ?? request_integer('ingredient'));
log_screen_view($pdo, 'yield-test-add', ['ingredient_id' => $i['ingredient_id']]);
if (wants_json()) { respond_screen(['ingredient' => present_ingredient($i), 'options' => ri_unit_options($pdo, $i['ingredient_id'])]); }
render_screen($i['name'] . ' · yield test', view('catalog/yield-test-form.php', ['i' => $i, 'options' => ri_unit_options($pdo, $i['ingredient_id']), 'here' => here_url()]),
    ['activeNav' => 'ingredient-list', 'screen' => 'yield-test-add', 'entity' => 'ingredient', 'recordId' => (string) $i['ingredient_id']]);
