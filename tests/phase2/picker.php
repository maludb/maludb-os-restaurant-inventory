<?php
/** The proofs' own picker source (RI_PICKER_EXTRA names this file; never in production): members here, so the modal is proven before slice 1 registers a real source. */
return [
    'member' => [
        'title' => 'People', 'noun' => 'person', 'params' => [],
        'gate' => static fn (array $p): ?string => has_right('ri.read') ? null : 'You may not see the people here.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            $rows = array_map(static fn (array $m): array => ['id' => (int) $m['member_id'], 'label' => $m['display_name'], 'detail' => $m['member_kind']], $pdo->query('SELECT member_id, display_name, member_kind FROM mcp_members ORDER BY display_name')->fetchAll());
            return picker_result_from_list($rows, $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            $st = $pdo->prepare('SELECT display_name FROM mcp_members WHERE member_id = :id'); $st->execute(['id' => $id]); $n = $st->fetchColumn(); return $n === false ? null : (string) $n;
        },
    ],
];
