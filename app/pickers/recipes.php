<?php
declare(strict_types=1);
/**
 * Slice 2's picker sources (app/pickers.php loads every app/pickers/*.php and keeps the first definition of a key): `recipe` (params
 * kind — 1 menu_item, 2 prep, 3 modifier; exclude — a recipe id to leave out), `menu_item` (param source), `modifier` (param source), and a
 * FALLBACK `ingredient` for the editor's line form until slice 1's own source lands (slice 1's wins when it is there).
 */
require_once dirname(__DIR__) . '/features/recipes/queries.php';
$kinds = [1 => 'menu_item', 2 => 'prep', 3 => 'modifier'];
return [
    'recipe' => [
        'title' => 'Recipes', 'noun' => 'recipe', 'params' => ['kind' => 'int', 'exclude' => 'int'],
        'gate' => static fn (array $p): ?string => has_right('ri.read') ? null : 'You may not see the recipes here.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($kinds): array {
            $rows = [];
            foreach (recipes_for_pick($pdo, $q, $kinds[$params['kind'] ?? 0] ?? null, $params['exclude'] ?? null) as $r) {
                $rows[] = ['id' => (int) $r['recipe_id'], 'label' => $r['name'] . ' · ' . $r['code'], 'detail' => str_replace('_', ' ', $r['kind']) . ($r['category'] ? ' · ' . $r['category'] : '') . ($r['version_no'] ? ' · v' . $r['version_no'] . ' in force' : ' · no version in force'),
                          'badge' => str_replace('_', ' ', $r['kind']), 'color' => ['menu_item' => 'primary', 'prep' => 'info', 'modifier' => 'secondary'][$r['kind']] ?? 'secondary'];
            }
            return picker_result_from_list($rows, '', $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string { $r = find_recipe($pdo, (int) $id); return $r === null ? null : $r['name'] . ' · ' . $r['code']; },
    ],
    'menu_item' => [
        'title' => 'Menu items', 'noun' => 'menu item', 'params' => ['source' => 'int'],
        'gate' => static fn (array $p): ?string => has_right('ri.read') ? null : 'You may not see the menu here.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            $rows = [];
            foreach (find_menu_items($pdo, (int) current_site_id(), ['q' => $q, 'source' => $params['source'] ?? null], 500) as $m) {
                $rows[] = ['id' => (int) $m['menu_item_id'], 'label' => $m['plu'] . ' · ' . $m['name'], 'detail' => ($m['source_name'] ?? 'typed') . ($m['recipe_name'] ? ' · ' . $m['recipe_name'] : ' · unmapped')];
            }
            return picker_result_from_list($rows, '', $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string { $r = find_menu_item($pdo, (int) $id); return $r === null ? null : $r['plu'] . ' · ' . $r['name']; },
    ],
    'modifier' => [
        'title' => 'Modifiers', 'noun' => 'modifier', 'params' => ['source' => 'int'],
        'gate' => static fn (array $p): ?string => has_right('recipes.write') ? null : 'You may not map modifiers here.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            $rows = [];
            foreach (find_modifiers($pdo, (int) current_site_id(), ['source' => $params['source'] ?? null]) as $m) {
                $rows[] = ['id' => (int) $m['modifier_id'], 'label' => $m['code'] . ' · ' . $m['name'], 'detail' => $m['action']];
            }
            return picker_result_from_list($rows, $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string { $st = $pdo->prepare('SELECT code, name FROM mcp_modifiers WHERE modifier_id = :id'); $st->execute(['id' => $id]); $r = $st->fetch(); return $r ? $r['code'] . ' · ' . $r['name'] : null; },
    ],
    'ingredient' => [
        'title' => 'Ingredients', 'noun' => 'ingredient', 'params' => ['is_prep' => 'int'],
        'gate' => static fn (array $p): ?string => has_right('ri.read') ? null : 'You may not see the ingredients here.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            $st = $pdo->prepare('SELECT i.ingredient_id, i.code, i.name, i.base_unit_code, c.name AS category FROM mcp_ingredients i JOIN mcp_ingredient_categories c ON c.category_id = i.category_id WHERE i.active' . (isset($params['is_prep']) ? ' AND i.is_prep = :p' : '') . ' ORDER BY i.name');
            $st->execute(isset($params['is_prep']) ? ['p' => $params['is_prep'] ? 't' : 'f'] : []);
            $rows = array_map(static fn (array $i): array => ['id' => (int) $i['ingredient_id'], 'label' => $i['name'] . ' · ' . $i['code'], 'detail' => $i['category'] . ' · ' . $i['base_unit_code']], $st->fetchAll());
            return picker_result_from_list($rows, $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string { $st = $pdo->prepare('SELECT name, code FROM mcp_ingredients WHERE ingredient_id = :id'); $st->execute(['id' => $id]); $r = $st->fetch(); return $r ? $r['name'] . ' · ' . $r['code'] : null; },
    ],
];
