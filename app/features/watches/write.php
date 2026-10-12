<?php
declare(strict_types=1);

/**
 * Watch writes (projection.md "Posting" — Watches). A new watch takes the CURRENT state as its last_state (ri_watch_state(), DECISION 7): a condition already true fires on
 * the NEXT change, not at creation. A cost_above threshold arrives per recipe unit and is stored per base unit (the database converts); a cost_moved one is a percentage.
 */
require_once __DIR__ . '/queries.php';

/**
 * Create ($id null) or change a watch. $fields: ingredient_id, kind, threshold (per recipe unit for cost_above, a percentage for cost_moved, null otherwise), agent_member_id|null,
 * text_me bool, note|null. $by is the watcher (a change keeps the original member). Returns the id.
 */
function set_watch(PDO $pdo, int $scopeId, ?int $id, array $fields, int $by): int
{
    $threshold = $fields['threshold'];
    if ($threshold !== null && $fields['kind'] === 'cost_above') {
        $threshold = one_value($pdo, 'SELECT CAST(:t AS numeric) / ri_to_base(i.id, 1, i.recipe_unit_code) FROM ingredients i WHERE i.id = :i', ['t' => $threshold, 'i' => $fields['ingredient_id']]);
    }
    if (!in_array($fields['kind'], ['cost_above', 'cost_moved'], true)) { $threshold = null; }
    $args = ['i' => $fields['ingredient_id'], 'k' => $fields['kind'], 't' => $threshold, 'a' => $fields['agent_member_id'], 'x' => $fields['text_me'] ? 't' : 'f', 'n' => $fields['note']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO watches (member_id, agent_member_id, scope_id, ingredient_id, kind, threshold, text_me, note) VALUES (:m, :a, :s, :i, :k, :t, CAST(:x AS boolean), :n) RETURNING id');
        $st->execute($args + ['m' => $by, 's' => $scopeId]);
        $id = (int) $st->fetchColumn();
    } else {
        $pdo->prepare('UPDATE watches SET agent_member_id = :a, ingredient_id = :i, kind = :k, threshold = :t, text_me = CAST(:x AS boolean), note = :n, active = true WHERE id = :id')->execute($args + ['id' => $id]);
    }
    $pdo->prepare('UPDATE watches SET last_state = ri_watch_state(id) WHERE id = :id')->execute(['id' => $id]);
    return $id;
}

/** Stop a watch (it stays on the list, greyed, so its history reads). */
function clear_watch(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('UPDATE watches SET active = false WHERE id = :id AND active');
    $st->execute(['id' => $id]);
    if ($st->rowCount() !== 1) { throw new DomainException('That watch is already cleared.'); }
}
