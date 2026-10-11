<?php
declare(strict_types=1);
/** /menu/engineering?from=&to=&category=&site= — the menu-engineering matrix (screen menu-engineering; walled whole). */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('reports.read');
$pdo = db();
$site = request_integer('site') ?? (int) current_site_id();
require_site($site);
if (!sees_cost($site)) { refuse(403, 'You may not see cost.'); }
$to = request_date('to') ?: date('Y-m-d');
$from = request_date('from') ?: date('Y-m-d', strtotime($to . ' -27 days'));
$cat = request_integer('category');
$groups = menu_engineering($pdo, $site, $from, $to, $cat);
log_screen_view($pdo, 'menu-engineering', ['after' => ['window' => [$from, $to]]]);
if (wants_json()) { respond_screen(['site_id' => $site, 'from' => $from, 'to' => $to, 'groups' => $groups]); }
render_screen('Menu engineering', view('menu/engineering.php', ['groups' => $groups, 'from' => $from, 'to' => $to, 'siteId' => $site, 'sites' => held_sites(), 'categoryId' => $cat, 'categories' => menu_categories($pdo)]), ['activeNav' => 'menu-engineering', 'screen' => 'menu-engineering', 'entity' => 'menu_item']);
