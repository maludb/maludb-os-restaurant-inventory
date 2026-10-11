<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; the design system's reference is
 * record-picker.md). One entry per kind of record a form chooses; every key is a bigint. Phase 2 ships the picker with the shell and
 * registers nothing: slice 1 adds `ingredient`, `supplier`, `supplier_item` (param `supplier`), `category`, `area`; slice 2 adds
 * `recipe` (params `kind`, `exclude`), `menu_item`, `modifier`. The proofs register a `member` source of their own (tests/phase2/picker.php).
 *
 * Every `gate` is the authorization the screens that use the field apply (null = allowed, else the refusal's words) — asked AT THE
 * CURRENT SITE through has_right(); every row comes from the mcp_* views. `search` reuses the feature's own query function and only
 * filters and slices what it returns; `label` names one record for a form's first render and a 422 re-render.
 */
$sources = [];
require_once __DIR__ . '/features/catalog/queries.php';
// slice 1 (catalog.md): the catalog's records a form chooses
$sources['ingredient'] = [
    'title' => 'Ingredients', 'noun' => 'ingredient', 'params' => ['is_prep' => 'int'],
    'gate' => static fn (array $p): ?string => has_right('ri.read') ? null : 'You may not see the ingredients here.',
    'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
        $r = find_ingredients($pdo, (int) current_site_id(), ['q' => $q, 'status' => 'active', 'is_prep' => $params['is_prep'] === null ? '' : ($params['is_prep'] ? 'yes' : 'no')], 25, ($page - 1) * 25);
        $rows = array_map(static fn (array $i): array => ['id' => $i['ingredient_id'], 'label' => $i['name'] . ' · ' . $i['code'], 'detail' => $i['category'] . ' · ' . $i['recipe_unit_code'], 'badge' => $i['is_prep'] ? 'prep' : null, 'color' => 'info'], $r['rows']);
        $pages = max(1, (int) ceil($r['total'] / 25));
        return ['rows' => $rows, 'total' => $r['total'], 'page' => min($page, $pages), 'page_size' => 25, 'pages' => $pages];
    },
    'label' => static function (PDO $pdo, int|string $id): ?string { $i = find_ingredient($pdo, (int) $id); return $i === null ? null : $i['name'] . ' · ' . $i['code']; },
];
$sources['supplier'] = [
    'title' => 'Suppliers', 'noun' => 'supplier', 'params' => [],
    'gate' => static fn (array $p): ?string => has_right('ri.read') ? null : 'You may not see the suppliers here.',
    'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
        $r = find_suppliers($pdo, (int) current_site_id(), ['q' => $q, 'status' => 'active'], 25, ($page - 1) * 25);
        $rows = array_map(static fn (array $s): array => ['id' => $s['supplier_id'], 'label' => $s['name'], 'detail' => SUPPLIER_KINDS[$s['kind']] . ' · ' . ORDER_METHODS[$s['order_method']], 'badge' => null], $r['rows']);
        $pages = max(1, (int) ceil($r['total'] / 25));
        return ['rows' => $rows, 'total' => $r['total'], 'page' => min($page, $pages), 'page_size' => 25, 'pages' => $pages];
    },
    'label' => static function (PDO $pdo, int|string $id): ?string { $s = find_supplier($pdo, (int) $id); return $s === null ? null : $s['name']; },
];
$sources['supplier_item'] = [
    'title' => 'Supplier items', 'noun' => 'item', 'params' => ['supplier' => 'int', 'ingredient' => 'int'],
    'gate' => static fn (array $p): ?string => has_right('ri.read') ? null : 'You may not see the supplier items here.',
    'search' => static fn (PDO $pdo, string $q, array $params, int $page): array => supplier_item_pick($pdo, $q, $params['supplier'], $params['ingredient'], $page),
    'label' => static function (PDO $pdo, int|string $id): ?string { $si = find_supplier_item($pdo, (int) $id); return $si === null ? null : ($si['supplier_sku'] ? $si['supplier_sku'] . ' · ' : '') . $si['ingredient'] . ' — ' . $si['supplier'] . ' (' . $si['purchase_unit_code'] . ')'; },
];
$sources['category'] = [
    'title' => 'Categories', 'noun' => 'category', 'params' => [],
    'gate' => static fn (array $p): ?string => has_right('ri.read') ? null : 'You may not see the categories here.',
    'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
        $rows = array_map(static fn (array $c): array => ['id' => (int) $c['category_id'], 'label' => $c['name'], 'detail' => CATEGORY_KINDS[$c['kind']] . ' · ' . $c['key']], categories($pdo));
        return picker_result_from_list($rows, $q, $page);
    },
    'label' => static function (PDO $pdo, int|string $id): ?string { $c = category_by_id_or_key($pdo, (string) $id); return $c === null ? null : $c['name']; },
];
$sources['area'] = [
    'title' => 'Storage areas', 'noun' => 'storage area', 'params' => [],
    'gate' => static fn (array $p): ?string => has_right('ri.read') ? null : 'You may not see the storage areas here.',
    'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
        $rows = array_map(static fn (array $a): array => ['id' => $a['storage_area_id'], 'label' => $a['name'], 'detail' => AREA_KINDS[$a['kind']] ?? $a['kind']], find_storage_areas($pdo, (int) current_site_id()));
        return picker_result_from_list($rows, $q, $page);
    },
    'label' => static function (PDO $pdo, int|string $id): ?string { $a = find_storage_area($pdo, (int) $id); return $a === null || $a['scope_id'] !== (int) current_site_id() ? null : $a['name']; },
];
foreach (glob(__DIR__ . '/pickers/*.php') ?: [] as $file) {                 // each slice's own sources (app/pickers/<slice>.php); the first definition of a key wins
    $sources += (array) require $file;
}
$extra = getenv('RI_PICKER_EXTRA');                       // the proofs' own sources (never set in production)
if ($extra !== false && $extra !== '' && getenv('APP_ENV') !== 'prod' && is_file($extra)) {
    $sources += (array) require $extra;
}
return $sources;
