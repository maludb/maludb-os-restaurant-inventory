<?php
declare(strict_types=1);

/**
 * The counts' writes (counts.md "Query functions" — Writes). Each is one schema verb (ri_count_start, ri_count_line_set, ri_count_line_add,
 * ri_count_review, ri_count_approve, ri_count_discard) or one statement on the sheets and containers; the caller (a handler) opens the transaction
 * inside ri_guard() and logs. PHP sums no quantity and computes no variance: the verbs do. A refusal the schema words badly for a person (a unique
 * index, a CHECK, "N lines are still pending") is mapped to a sentence here, in one place.
 */

/** The schema's refusal in a sentence for a person, or null when its own words are fine. */
function count_sentence(PDOException $e): ?string
{
    $m = $e->getMessage();
    $code = (string) $e->getCode();
    if ($code === '23505') {
        return match (true) {
            str_contains($m, 'inventory_counts_one_open') => 'A full count is already open at this site — finish or discard it.',
            str_contains($m, 'count_sheets_scope_id_name_key') => 'A sheet with that name exists at this site.',
            str_contains($m, 'count_sheet_lines_sheet_id_storage_area_id_ingredient_id_key') => 'That ingredient is already on this sheet in that area.',
            str_contains($m, 'inventory_count_lines_count_id') => 'That ingredient is already on this count in that area.',
            str_contains($m, 'containers_ingredient_id_name_key') => 'That ingredient already has a bottle or keg with that name.',
            str_contains($m, 'containers_default') => 'That ingredient already has a default bottle or keg.',
            default => null,
        };
    }
    if ($code === '23514' && str_contains($m, 'containers')) {
        return str_contains($m, 'containers_check') ? 'The empty weight must be less than the full weight.' : 'The full weight and the volume must be more than zero.';
    }
    if (preg_match('/ERROR:\s+(\d+) lines are still pending/', $m, $x)) {
        $n = (int) $x[1];
        return $n === 1 ? '1 line is still pending — count it, zero it or skip it.' : $n . ' lines are still pending — count them, zero them or skip them.';
    }
    if (preg_match('/ERROR:\s+1 lines over tolerance await a recount/', $m)) {
        return '1 line over tolerance awaits a recount (approve with force to accept it).';
    }
    return null;
}

/** Run $f; the schema's refusals become our sentences (a DomainException ri_guard() words as a 422). */
function count_try(callable $f): mixed
{
    try {
        return $f();
    } catch (PDOException $e) {
        $s = count_sentence($e);
        if ($s !== null) { throw new DomainException($s); }
        throw $e;
    }
}

// ------------------------------------------------------------------------------------------------ counts
/** Start a count: ri_count_start() makes the lines (from the sheet, or every balance at the site) with the book figure frozen as of $asOf (a UTC timestamp). Returns the new count's row and its line count. */
function start_count(PDO $pdo, int $scopeId, int $by, ?int $sheetId, ?string $asOf, string $kind, ?bool $blind, ?string $notes): array
{
    return count_try(static function () use ($pdo, $scopeId, $by, $sheetId, $asOf, $kind, $blind, $notes): array {
        $st = $pdo->prepare('SELECT id FROM ri_count_start(:s, :by, CAST(:sh AS bigint), COALESCE(CAST(:a AS timestamptz), now()), :k, CAST(:b AS boolean))');
        $st->execute(['s' => $scopeId, 'by' => $by, 'sh' => $sheetId, 'a' => $asOf, 'k' => $kind, 'b' => $blind === null ? null : ($blind ? 't' : 'f')]);
        $id = (int) $st->fetchColumn();
        if ($notes !== null) { $pdo->prepare('UPDATE inventory_counts SET notes = :n WHERE id = :id')->execute(['n' => $notes, 'id' => $id]); }
        $lines = (int) one_value($pdo, 'SELECT count(*) FROM inventory_count_lines WHERE count_id = :c', ['c' => $id]);
        return ['count_id' => $id, 'lines' => $lines];
    });
}

/**
 * One line's entry through ri_count_line_set(): $entries = [{unit_code, qty}] (null = none), a gross weight against a container, or a state
 * (counted | zero | skipped). Idempotent: the same entries twice leave the same line. Returns the line re-read through the view (the blind rule).
 */
function set_count_line(PDO $pdo, int $lineId, int $by, ?array $entries, ?float $grossG, ?int $containerId, string $state, ?string $note): array
{
    count_try(static function () use ($pdo, $lineId, $by, $entries, $grossG, $containerId, $state, $note): void {
        $st = $pdo->prepare('SELECT id FROM ri_count_line_set(:l, :by, CAST(:e AS jsonb), CAST(:g AS numeric), CAST(:c AS bigint), :st, :n)');
        $st->execute(['l' => $lineId, 'by' => $by, 'e' => $entries === null ? null : json_encode($entries, JSON_THROW_ON_ERROR), 'g' => $grossG === null ? null : number_format($grossG, 2, '.', ''),
            'c' => $containerId, 'st' => $state, 'n' => $note]);
        $st->fetchColumn();
    });
    return find_count_line($pdo, $lineId) ?? throw new DomainException('Not found.');
}

/** An ingredient found on the shelf that is not on the sheet: ri_count_line_add() (db/018) makes the line with the book figure as of the count's moment; entries, when given, are set. */
function add_count_line(PDO $pdo, int $countId, int $areaId, int $ingredientId, ?array $entries, int $by): array
{
    $id = count_try(static function () use ($pdo, $countId, $areaId, $ingredientId, $by): int {
        $st = $pdo->prepare('SELECT id FROM ri_count_line_add(:c, :a, :i, :by)');
        $st->execute(['c' => $countId, 'a' => $areaId, 'i' => $ingredientId, 'by' => $by]);
        return (int) $st->fetchColumn();
    });
    if ($entries !== null) { return set_count_line($pdo, $id, $by, $entries, null, null, 'counted', null); }
    return find_count_line($pdo, $id) ?? throw new DomainException('Not found.');
}

/** Review: variances, tolerances, the status review or recount (ri_count_review). Returns the count row; pending lines → the function's sentence, singular or plural. */
function review_count(PDO $pdo, int $countId, int $by): array
{
    count_try(static function () use ($pdo, $countId, $by): void {
        $st = $pdo->prepare('SELECT id FROM ri_count_review(:c, :by)');
        $st->execute(['c' => $countId, 'by' => $by]);
        $st->fetchColumn();
    });
    return find_count($pdo, $countId) ?? throw new DomainException('Not found.');
}

/** A second person's count of a line over tolerance (ri_count_line_set on a count in recount writes recount_qty_base and refuses the counter), then the review again so the flags and the status refresh. */
function recount_line(PDO $pdo, int $lineId, int $by, ?array $entries, ?float $grossG, ?string $note): array
{
    $line = set_count_line($pdo, $lineId, $by, $entries, $grossG, null, 'counted', $note);
    review_count($pdo, (int) $line['count_id'], $by);
    return find_count_line($pdo, $lineId) ?? $line;
}

/**
 * Approve (ri_count_approve): a count_correction per counted or zeroed line whose counted (or recounted) figure differs from the frozen book figure,
 * dated at the count's moment, one group. Returns the count, the corrections it posted and the units posted by ingredient (≤ 50).
 */
function approve_count(PDO $pdo, int $countId, int $by, bool $force): array
{
    count_try(static function () use ($pdo, $countId, $by, $force): void {
        $st = $pdo->prepare('SELECT id FROM ri_count_approve(:c, :by, CAST(:f AS boolean))');
        $st->execute(['c' => $countId, 'by' => $by, 'f' => $force ? 't' : 'f']);
        $st->fetchColumn();
    });
    $corrections = count_corrections($pdo, $countId);
    $delta = [];
    foreach ($corrections as $t) { $delta[$t['ingredient_id']] = ['ingredient_id' => $t['ingredient_id'], 'qty_base' => $t['qty_base']]; }
    return ['count' => find_count($pdo, $countId), 'corrections' => $corrections, 'units_delta' => array_slice(array_values($delta), 0, 50)];
}

function discard_count(PDO $pdo, int $countId, int $by, ?string $reason): array
{
    count_try(static function () use ($pdo, $countId, $by): void {
        $st = $pdo->prepare('SELECT id FROM ri_count_discard(:c, :by)');
        $st->execute(['c' => $countId, 'by' => $by]);
        $st->fetchColumn();
    });
    return find_count($pdo, $countId) ?? throw new DomainException('Not found.');
}

// ------------------------------------------------------------------------------------------------ sheets
/** Make or change a sheet ($fields: name, kind, active); with $fill the sheet takes every ingredient with a balance in every area, in walk order (ri_count_sheet_fill). Returns its id. */
function save_count_sheet(PDO $pdo, int $scopeId, ?int $id, array $fields, bool $fill, int $by): int
{
    return count_try(static function () use ($pdo, $scopeId, $id, $fields, $fill, $by): int {
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO count_sheets (scope_id, name, kind, active, created_by) VALUES (:s, :n, :k, :a, :by) RETURNING id');
            $st->execute(['s' => $scopeId, 'n' => $fields['name'], 'k' => $fields['kind'], 'a' => ($fields['active'] ?? true) ? 't' : 'f', 'by' => $by]);
            $id = (int) $st->fetchColumn();
        } else {
            $pdo->prepare('UPDATE count_sheets SET name = :n, kind = :k, active = :a WHERE id = :id')->execute(['n' => $fields['name'], 'k' => $fields['kind'], 'a' => ($fields['active'] ?? true) ? 't' : 'f', 'id' => $id]);
        }
        if ($fill) { $pdo->prepare('SELECT ri_count_sheet_fill(:id)')->execute(['id' => $id]); }
        return $id;
    });
}

/** Add or change a sheet line (the unit must be one the ingredient converts — ri_to_base says if not). Returns the line's id. */
function set_sheet_line(PDO $pdo, int $sheetId, ?int $lineId, int $areaId, int $ingredientId, ?int $shelfOrder, ?string $unit): int
{
    return count_try(static function () use ($pdo, $sheetId, $lineId, $areaId, $ingredientId, $shelfOrder, $unit): int {
        if ($unit !== null && $unit !== '') { $pdo->prepare('SELECT ri_to_base(:i, 1, :u)')->execute(['i' => $ingredientId, 'u' => $unit]); }
        if ($lineId === null) {
            $order = $shelfOrder ?? ((int) one_value($pdo, 'SELECT COALESCE(max(shelf_order), 0) FROM count_sheet_lines WHERE sheet_id = :s AND storage_area_id = :a', ['s' => $sheetId, 'a' => $areaId]) + 1);
            $st = $pdo->prepare('INSERT INTO count_sheet_lines (sheet_id, storage_area_id, ingredient_id, shelf_order, count_unit_code) VALUES (:s, :a, :i, :o, :u) RETURNING id');
            $st->execute(['s' => $sheetId, 'a' => $areaId, 'i' => $ingredientId, 'o' => $order, 'u' => $unit === '' ? null : $unit]);
            return (int) $st->fetchColumn();
        }
        $pdo->prepare('UPDATE count_sheet_lines SET storage_area_id = :a, ingredient_id = :i, shelf_order = COALESCE(:o, shelf_order), count_unit_code = :u WHERE id = :id AND sheet_id = :s')
            ->execute(['a' => $areaId, 'i' => $ingredientId, 'o' => $shelfOrder, 'u' => $unit === '' ? null : $unit, 'id' => $lineId, 's' => $sheetId]);
        return $lineId;
    });
}

function remove_sheet_line(PDO $pdo, int $lineId): void
{
    $pdo->prepare('DELETE FROM count_sheet_lines WHERE id = :id')->execute(['id' => $lineId]);
}

/** Renumber one area's lines 1..n in the order given (one statement); a line not named keeps its place after the named ones. */
function reorder_sheet_lines(PDO $pdo, int $sheetId, int $areaId, array $lineIds): void
{
    $pdo->prepare('WITH ranked AS (SELECT l.id, row_number() OVER (ORDER BY COALESCE(array_position(CAST(:ids AS bigint[]), l.id), 1000000), l.shelf_order, l.id) AS n
                                     FROM count_sheet_lines l WHERE l.sheet_id = :s AND l.storage_area_id = :a)
                   UPDATE count_sheet_lines l SET shelf_order = r.n FROM ranked r WHERE l.id = r.id')
        ->execute(['ids' => pg_array_literal(array_map('intval', $lineIds)), 's' => $sheetId, 'a' => $areaId]);
}

// ------------------------------------------------------------------------------------------------ containers
/** Make or change a bottle or keg ($fields: ingredient_id, name, full_weight_g, empty_weight_g, pourer_tare_g, volume_ml, is_default, active). One default per ingredient: choosing one clears the previous in the same transaction. Returns its id. */
function save_container(PDO $pdo, ?int $id, array $fields): int
{
    return count_try(static function () use ($pdo, $id, $fields): int {
        if ($fields['is_default'] && ($fields['active'] ?? true)) {
            $pdo->prepare('UPDATE containers SET is_default = false WHERE ingredient_id = :i AND is_default AND (CAST(:id AS bigint) IS NULL OR id <> :id2)')->execute(['i' => $fields['ingredient_id'], 'id' => $id, 'id2' => $id ?? 0]);
        }
        $args = ['i' => $fields['ingredient_id'], 'n' => $fields['name'], 'f' => $fields['full_weight_g'], 'e' => $fields['empty_weight_g'], 'p' => $fields['pourer_tare_g'], 'v' => $fields['volume_ml'],
                 'd' => $fields['is_default'] ? 't' : 'f', 'a' => ($fields['active'] ?? true) ? 't' : 'f'];
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO containers (ingredient_id, name, full_weight_g, empty_weight_g, pourer_tare_g, volume_ml, is_default, active) VALUES (:i, :n, :f, :e, :p, :v, :d, :a) RETURNING id');
            $st->execute($args);
            return (int) $st->fetchColumn();
        }
        $pdo->prepare('UPDATE containers SET ingredient_id = :i, name = :n, full_weight_g = :f, empty_weight_g = :e, pourer_tare_g = :p, volume_ml = :v, is_default = :d, active = :a WHERE id = :id')->execute($args + ['id' => $id]);
        return $id;
    });
}
