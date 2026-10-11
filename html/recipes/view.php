<?php
declare(strict_types=1);
/** /recipes/{recipe}?version=&as_of=&site=&tab= — one recipe (screen recipe-view): the version strip, the lines with their cost shares (walled), the tree, the batch, the method, the menu items, used in, notes, the trail. */
require_once dirname(__DIR__, 2) . '/app/features/recipes/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$id = request_integer('id');
$cur = recipe_or_404($pdo, $id);
$site = request_integer('site') ?? (int) current_site_id();
require_site($site);
$asOf = request_date('as_of') ?: null;
$full = recipe_full($pdo, $id, $site, request_integer('version'), $asOf);
log_screen_view($pdo, 'recipe-view', ['recipe_id' => $id]);
if (wants_json()) { respond_screen(present_recipe_full($full, sees_cost($site)) + ['site_id' => $site, 'deletable' => recipe_deletable($pdo, $id), 'trail' => array_map(static fn (array $a): array => ['at' => $a['occurred_at'], 'action' => $a['action'], 'actor' => $a['actor_name']], recipe_activity($pdo, 'recipe', $id))]); }
$notices = ['created' => ['success', 'The recipe was made with its first draft.'], 'saved' => ['success', 'Saved.'], 'archived' => ['warning', 'Archived.'], 'restored' => ['success', 'Restored.'], 'activated' => ['success', 'The version is in force.'], 'discarded' => ['warning', 'The draft was discarded.'], 'retired' => ['warning', 'The version was retired.'], 'drafted' => ['success', 'A new draft was started.']];
render_screen($cur['name'], view('recipes/recipe.php', ['full' => $full, 'siteId' => $site, 'sites' => held_sites(), 'seesCost' => sees_cost($site), 'mayWrite' => has_right('recipes.write'), 'mayDelete' => has_right('records.delete') && recipe_deletable($pdo, $id) === null,
    'deletable' => recipe_deletable($pdo, $id), 'tab' => request_string('tab'), 'trail' => recipe_activity($pdo, 'recipe', $id), 'tz' => member_timezone(), 'back' => back_link(), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'recipe-list', 'screen' => 'recipe-view', 'entity' => 'recipe', 'recordId' => (string) $id]);
