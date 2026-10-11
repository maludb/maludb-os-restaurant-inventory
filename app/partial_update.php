<?php
declare(strict_types=1);

/**
 * Partial updates for the agents' door (mcp-and-api.md §4): under an action token with
 * `_partial=1`, the fields the caller did not send are filled from the record's BASE row, and only
 * when the caller can see the record through its mcp_* view. A browser can never trigger this.
 */

/** endpoint → [table, the form's id field, read view, the view's id column]. Each slice adds its save endpoints here (the manifest's
 * `any field of`). Empty until Phase 2: the first entries are slice 1's, e.g. '/ingredients/save.php' => ['ingredients', 'ingredient',
 * 'mcp_ingredients', 'ingredient_id']. */
const PARTIAL_UPDATE_TARGETS = [];
/** The targets: the constant above plus each slice's app/partial_targets/<slice>.php (endpoint => [table, id field, view, view id]). */
function partial_update_targets(): array
{
    static $t = null;
    if ($t === null) {
        $t = PARTIAL_UPDATE_TARGETS;
        foreach (glob(__DIR__ . '/partial_targets/*.php') ?: [] as $file) { $t += (array) require $file; }
    }
    return $t;
}

function partial_update_prefill(PDO $pdo): void
{
    if (empty($GLOBALS['__action_authed']) || ($_POST['_partial'] ?? '') !== '1' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    unset($_POST['_partial']);
    $target = partial_update_targets()[(string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH)] ?? null;
    if ($target === null) {
        return;
    }
    [$table, $idField, $view, $viewId] = $target;
    $raw = (string) ($_POST[$idField] ?? '');
    $int = filter_var($raw, FILTER_VALIDATE_INT);
    if ($int !== false && $int >= 1) {
        $id = $int;
    } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw)) {
        $id = strtolower($raw);
    } else {
        return;
    }
    $visible = $pdo->prepare("SELECT 1 FROM {$view} WHERE {$viewId} = :id");
    $visible->execute(['id' => $id]);
    if ($visible->fetchColumn() === false) {
        return;
    }
    $pk = 'id';
    $st = $pdo->prepare("SELECT * FROM {$table} WHERE {$pk} = :id");
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return;
    }
    foreach ($row as $column => $value) {
        if ($column === $pk || $value === null || array_key_exists($column, $_POST)) {
            continue;
        }
        $_POST[$column] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
