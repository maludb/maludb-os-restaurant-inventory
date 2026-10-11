<?php
declare(strict_types=1);
/** /search?q= — one search across ingredients, recipes, menu items, supplier items and suppliers (screen `search`; ri_find()). An empty q renders the box alone. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/search/queries.php';
require_login();
$pdo = db();
$q = mb_substr(trim(request_string('q')), 0, 120);
$groups = search_all($pdo, $q);
log_screen_view($pdo, 'search');
if (wants_json()) {
    respond_screen(['q' => $q, 'groups' => array_map(static fn (array $rows): array => array_map(static fn (array $r): array => ['kind' => $r['kind'], 'id' => $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'detail' => $r['detail'], 'url' => $r['url']], $rows), $groups)]);
}
render_screen($q === '' ? 'Search' : 'Search: ' . $q, view('search/results.php', ['q' => $q, 'groups' => $groups]), ['activeNav' => 'search', 'screen' => 'search']);
