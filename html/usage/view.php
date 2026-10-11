<?php
declare(strict_types=1);
/** /usage/{ingredient} (usage/view.php, the canonical numbered-record rewrite) — one ingredient's window (screen `usage-ingredient`; params from, to, pick): the buckets, the recipes that consumed it and how much each, the waste logged, the count lines, the movements, the cost history. Every reader at the site opens it (ri.read); cost is walled. */
require_once dirname(__DIR__, 2) . '/app/features/periods/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$ingId = request_integer('id') ?? request_integer('ingredient');
$w = usage_window_or_refuse($pdo, $site);
$d = $ingId === null ? null : usage_ingredient($pdo, $site, $ingId, $w['from'], $w['to'], request_string('basis') ?: null);
if ($d === null) { respond_not_found('That ingredient is not here.'); }
log_screen_view($pdo, 'usage-ingredient', ['ingredient_id' => $ingId, 'after' => ['from' => $w['from'], 'to' => $w['to']]]);
if (wants_json()) { respond_screen(['window' => ['from' => $w['from'], 'to' => $w['to'], 'pick' => $w['pick']]] + present_usage_ingredient($d)); }
render_screen($d['ingredient']['name'] . ' — usage', view('usage/ingredient.php', ['d' => $d, 'w' => $w, 'seesCost' => !$d['withheld']]),
    ['activeNav' => 'usage-report', 'screen' => 'usage-ingredient', 'entity' => 'ingredient', 'recordId' => (string) $ingId]);
