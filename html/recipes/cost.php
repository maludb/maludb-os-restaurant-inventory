<?php
declare(strict_types=1);
/** /recipes/{recipe}/cost?site=&weeks= — the cost per portion by week (screen recipe-cost-history; walled whole). */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = request_integer('site') ?? (int) current_site_id();
require_site($site);
if (!sees_cost($site)) { refuse(403, 'You may not see cost.'); }
$id = request_integer('id');
$cur = recipe_or_404($pdo, $id);
$weeks = min(104, max(4, request_integer('weeks') ?? 52));
$rows = cost_history_weeks($pdo, $id, $site, $weeks);
log_screen_view($pdo, 'recipe-cost-history', ['recipe_id' => $id]);
if (wants_json()) { respond_screen(['recipe_id' => $id, 'site_id' => $site, 'weeks' => $weeks, 'rows' => $rows]); }
render_screen('Cost history — ' . $cur['name'], view('recipes/cost-history.php', ['recipe' => $cur, 'rows' => $rows, 'siteId' => $site, 'sites' => held_sites(), 'weeks' => $weeks, 'back' => back_link()]), ['activeNav' => 'recipe-list', 'screen' => 'recipe-cost-history', 'entity' => 'recipe', 'recordId' => (string) $id]);
