<?php
declare(strict_types=1);
/** /projection/watches — watches (screen `watch-list`; params ingredient, watch): mine (everyone's for an administrator) — an ingredient, a condition, the agent to dispatch, text me, when it last fired; the New watch form. A watch fires once per change of state. ri.read. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$admin = is_ri_admin($site);
$rows = watches($pdo, $site, $admin ? null : $me);
$edit = request_integer('watch') === null ? null : find_watch($pdo, (int) request_integer('watch'));
if ($edit !== null && ($edit['scope_id'] !== $site || ($edit['member_id'] !== $me && !$admin))) { $edit = null; }
$prefill = $edit === null ? ['ingredient_id' => request_integer('ingredient'), 'kind' => request_string('kind', 'stockout_projected'), 'threshold' => '', 'agent_member_id' => null, 'text_me' => false, 'note' => ''] : ['ingredient_id' => $edit['ingredient_id'], 'kind' => $edit['kind'],
    'threshold' => $edit['kind'] === 'cost_above' ? $edit['threshold_recipe_unit'] : $edit['threshold'], 'agent_member_id' => $edit['agent_member_id'], 'text_me' => $edit['text_me'], 'note' => $edit['note']];
log_screen_view($pdo, 'watch-list');
if (wants_json()) { respond_screen(['watches' => array_map('present_watch', $rows)]); }
$notices = ['saved' => ['success', 'The watch is saved — it fires on the next change.'], 'cleared' => ['info', 'The watch is stopped.']];
render_screen('Watches', view('projection/watches.php', ['rows' => $rows, 'admin' => $admin, 'me' => $me, 'edit' => $edit, 'prefill' => $prefill, 'agents' => watch_agents($pdo, $site), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'watch-list', 'screen' => 'watch-list', 'entity' => 'watch']);
