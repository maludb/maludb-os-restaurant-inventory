<?php
/**
 * Helpers for slice 6's proofs (docs/build-specs/counts.md "Proof"), run through tests/phase3/slice6/run.sh: the scratch database ri_dev_s6, the
 * application on :9329, the fake kernel :9330, the fake MaluDB + MaluMail :9331 (the Apache internal vhost :9332). The request helpers are slice 3's
 * (tests/phase3/slice3/lib.php — BASE follows P_APP). count_world() makes, through the real doors, the world the proofs count:
 *   Walk-in (SMOKE Walk-in): eggs 266 each (the book), olive oil 1 case4;  Dry store: flour 6 bag50;  Bar: gin 2 case6 (9 000 ml), limes 1.5 cases (300 each);
 *   Cold room: one case of chicken.   Costs: eggs $0.30, limes $0.19, flour $25 a bag, gin $108 a case of six, chicken $3.10 a pound.
 * The people: 28 Bea (Kitchen, Downtown) counts, 30 Dana (Chef) reviews and approves and recounts, 27 Marco (Manager) hears of variances, 29 Ann (Viewer),
 * 40 the expert (an agent Chef), 42 the Purchasing agent (Manager); 1 the owner.
 */
require_once dirname(__DIR__) . '/slice3/lib.php';

/** The scratch world: stock received through the receipts door, a Manager named for Downtown. Idempotent per database. Returns ledger_world()'s ids. */
function count_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $w = ledger_world();
    if (on_hand($w['egg']) == 0.0) {
        $dana = person(30);
        stock_in($dana, $w['walkin'], [['ingredient' => 'L-EGG', 'qty' => 266, 'unit' => 'each', 'unit_cost' => 0.30], ['ingredient' => 'L-OIL', 'qty' => 1, 'unit' => 'case4', 'unit_cost' => 40]], $w['sysco']);
        stock_in($dana, $w['dry'], [['ingredient' => 'L-FLOUR', 'qty' => 6, 'unit' => 'bag50', 'unit_cost' => 25]], $w['sysco']);
        stock_in($dana, $w['bar'], [['ingredient' => 'L-GIN', 'qty' => 2, 'unit' => 'case6', 'unit_cost' => 108], ['ingredient' => 'L-LIME', 'qty' => 1.5, 'unit' => 'case', 'unit_cost' => 38]], $w['produce']);
        $g = stock_in($dana, $w['cold'], [['ingredient' => 'L-CHK', 'qty' => 1, 'unit' => 'case', 'weight_qty' => 38.5, 'weight_unit' => 'lb', 'unit_cost' => 3.10]], $w['sysco']);
        ri_sql('UPDATE site_settings SET manager_member_id = 27 WHERE scope_id = :s', ['s' => DOWNTOWN]);
    }
    // the gin bottle (counts.md "Containers"): 750 ml, 1 240 g full, 500 g empty, a 30 g pourer
    return $w;
}

/** Discard every open count at Downtown (SQL, the proofs' reset between lifecycles) so a full count may be started again. */
function reset_counts(): void { ri_sql("UPDATE inventory_counts SET status = 'discarded' WHERE scope_id = :s AND status IN ('open', 'review', 'recount')", ['s' => DOWNTOWN]); }

/** Start a count through the door; returns [id, response]. */
function start_count_as(string $jar, array $form = []): array
{
    $r = post($jar, '/counts/start.php', $form);
    $id = (int) one("SELECT COALESCE(max(id), 0) FROM inventory_counts WHERE scope_id = :s", ['s' => DOWNTOWN]);
    return [$id, $r];
}
function line_of(int $count, int $ingredient, ?int $area = null): int
{
    return (int) one('SELECT id FROM inventory_count_lines WHERE count_id = :c AND ingredient_id = :i' . ($area ? ' AND storage_area_id = ' . $area : ''), ['c' => $count, 'i' => $ingredient]);
}
function cline(int $id): array { return q('SELECT * FROM inventory_count_lines WHERE id = :i', ['i' => $id])[0] ?? []; }
function ccount(int $id): array { return q('SELECT * FROM inventory_counts WHERE id = :i', ['i' => $id])[0] ?? []; }
function says_error(array $r, string $needle): bool { return str_contains(html_entity_decode(strip_tags($r['body']), ENT_QUOTES | ENT_HTML5), $needle) || str_contains(json_encode(json_decode($r['body'], true)), str_replace('"', '\\"', $needle)); }
/** The site-local time of the day's sales cut (tonight at 04:00) as the form shows it. */
function tonight_cut(): string { return default_count_as_of_for_proof(); }
function default_count_as_of_for_proof(): string
{
    $r = get_json(person(30), '/counts/new');
    return (string) ($r['json']['data']['as_of_default'] ?? '');
}
function notifications_of(int $member, string $kind, ?int $since = null): array { return q('SELECT * FROM notifications WHERE member_id = :m AND kind = :k AND id > :s ORDER BY id', ['m' => $member, 'k' => $kind, 's' => $since ?? 0]); }
function last_note_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM notifications'); }

/** The hidden `lines[]` values of the form (data-move) that contains the button with this id, in order. */
function move_lines(string $html, string $buttonId): array
{
    if (!preg_match_all('#<form method="post" action="/counts/sheets/reorder.php" data-move="[a-z]+">(.*?)</form>#s', $html, $m)) { return []; }
    foreach ($m[1] as $f) { if (str_contains($f, 'id="' . $buttonId . '"')) { preg_match_all('#name="lines\[\]" value="(\d+)"#', $f, $v); return array_map('intval', $v[1]); } }
    return [];
}
/** The sheet lines of an area in shelf order: [ingredient_id, …]. */
function sheet_order(int $sheet, int $area): array { return array_map('intval', array_column(q('SELECT ingredient_id FROM count_sheet_lines WHERE sheet_id = :s AND storage_area_id = :a ORDER BY shelf_order, id', ['s' => $sheet, 'a' => $area]), 'ingredient_id')); }
/** The first sentence a refusal says, whatever the door (HTML message, flash or JSON). */
function sentence_of(array $r): string
{
    $j = json_decode($r['body'], true);
    if (is_array($j) && isset($j['error']['message'])) { return (string) $j['error']['message']; }
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($r['body']), ENT_QUOTES | ENT_HTML5)));
}
function says(array $r, string $needle): bool { return str_contains(html_entity_decode(strip_tags($r['body']), ENT_QUOTES | ENT_HTML5), $needle) || str_contains($r['body'], $needle); }
