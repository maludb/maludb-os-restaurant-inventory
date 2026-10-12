<?php
declare(strict_types=1);
/** Action `watch_set` (log watch.set): ri.read (one's own); ingredient, kind (below_par | stockout_projected | cost_above | cost_moved), threshold (cost_above: a cost per recipe unit; cost_moved: a percentage), agent (to dispatch when it fires), text_me, note, watch (to change one). A new watch takes the current state, so it fires on the NEXT change. Location the watches. */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
proj_begin('ri.read');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$cur = null;
if (req_has('watch') && (string) req_val('watch') !== '') {
    $cur = stock_here_or_404(find_watch($pdo, (int) request_integer('watch')), 'That watch');
    if ($cur['member_id'] !== $me && !is_ri_admin($site)) { respond_not_found('That watch is not here.'); }
}
$errors = [];
$ing = ri_ref($pdo, 'ingredient', $cur['ingredient_id'] ?? null, 'SELECT 1 FROM mcp_ingredients WHERE ingredient_id = :id AND active', 'ingredient', $errors, false);
if ($ing === null && !isset($errors['ingredient'])) { $errors['ingredient'] = 'Choose the ingredient to watch.'; }
$kind = ri_choice('kind', $cur['kind'] ?? null, array_keys(WATCH_KINDS), 'The kind', $errors, false);
if ($kind === null && !isset($errors['kind'])) { $errors['kind'] = 'Choose what to watch for: ' . implode(', ', array_keys(WATCH_KINDS)) . '.'; }
$threshold = proj_decimal('threshold', 'The threshold', $errors, 100000.0);
if ($threshold === null && !req_has('threshold') && $cur !== null) { $threshold = $cur['kind'] === 'cost_above' ? $cur['threshold_recipe_unit'] : $cur['threshold']; }
if (in_array($kind, ['cost_above', 'cost_moved'], true) && $threshold === null && !isset($errors['threshold'])) { $errors['threshold'] = $kind === 'cost_above' ? 'Give the cost per recipe unit to watch for.' : 'Give the percentage to watch for.'; }
$agent = ri_ref($pdo, 'agent', $cur['agent_member_id'] ?? null, "SELECT 1 FROM members m WHERE m.id = :id AND m.member_kind = 'agent' AND m.status = 'active' AND cardinality(ri_member_roles_at(m.id, " . $site . ')) > 0', 'agent', $errors);
$text = ri_yes('text_me', $cur['text_me'] ?? false);
$note = ri_text('note', $cur['note'] ?? null, 200, 'The note', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $site, $me, $cur, $ing, $kind, $threshold, $agent, $text, $note): int {
    $pdo->beginTransaction();
    $id = set_watch($pdo, $site, $cur['watch_id'] ?? null, ['ingredient_id' => $ing, 'kind' => $kind, 'threshold' => $threshold, 'agent_member_id' => $agent, 'text_me' => $text, 'note' => $note], $cur['member_id'] ?? $me);
    $pdo->commit();
    return $id;
});
$w = find_watch($pdo, $id);
proj_log($pdo, 'watch.set', 'watch', $id, ['ingredient_id' => $ing, 'after' => ['watch_id' => $id, 'ingredient_id' => $ing, 'kind' => $kind, 'threshold' => $w['threshold'], 'agent_member_id' => $agent, 'text_me' => $text]]);
ri_done(($cur === null ? 'Watching ' : 'Changed the watch on ') . $w['ingredient'] . ': ' . strtolower(WATCH_KINDS[$kind]), $id, ri_land('/projection/watches', 'saved', 'watch-row-' . $id), 'watchChanged', ['watch_id' => $id]);
