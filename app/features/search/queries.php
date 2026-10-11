<?php
declare(strict_types=1);

/** One search (screen `search`, sso-shell.md): ri_find() rows grouped by kind, each with its url through record_url(). */
const SEARCH_KINDS = ['ingredient' => 'Ingredients', 'recipe' => 'Recipes', 'menu_item' => 'Menu items', 'supplier_item' => 'Supplier items', 'supplier' => 'Suppliers'];

function search_all(PDO $pdo, string $q, int $limit = 25): array
{
    $q = trim($q);
    $groups = [];
    if ($q === '') {
        return $groups;
    }
    $st = $pdo->prepare('SELECT kind, id, code, name, detail, rank FROM ri_find(CAST(:q AS text), CAST(:l AS integer))');
    $st->execute(['q' => $q, 'l' => max(1, min(200, $limit))]);
    foreach ($st->fetchAll() as $r) {
        $r['id'] = (int) $r['id'];
        $r['url'] = record_url($r['kind'], $r['id']);
        $groups[$r['kind']][] = $r;
    }
    return $groups;
}
