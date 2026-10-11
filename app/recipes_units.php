<?php
declare(strict_types=1);

/**
 * The shared unit helpers (docs/build-specs/units-and-costing.md §1, signatures fixed) as slice 2 needs them. Slice 1 builds the
 * canonical copy in app/features/ingredients/units.php; this file defines each helper ONLY when that copy has not — the two never collide
 * (the lead reconciles; whichever loads first wins). PHP never multiplies a factor here: every conversion is ri_from_base() / ri_to_base().
 */
foreach ([__DIR__ . '/features/units/queries.php', __DIR__ . '/features/ingredients/units.php'] as $canonical) {   // slice 1's canonical copy wins
    if (is_file($canonical)) { require_once $canonical; }
}

if (!function_exists('ri_fmt')) {
    /** A number with the thousands separator and at most $decimals decimals (a whole number prints without decimals). */
    function ri_fmt(string|float|int|null $n, int $decimals = 2): string
    {
        if ($n === null || $n === '') { return ''; }
        $f = (float) $n;
        $s = number_format($f, $decimals, '.', ' ');
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
if (!function_exists('ri_money')) {
    function ri_money(string|float|int|null $n, string $currency = '$', int $decimals = 2): string
    {
        return $n === null || $n === '' ? '' : $currency . number_format((float) $n, $decimals, '.', ',');
    }
}
if (!function_exists('ri_ingredient_row')) {
    function ri_ingredient_row(PDO $pdo, int $ingredientId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM mcp_ingredients WHERE ingredient_id = :id');
        $st->execute(['id' => $ingredientId]);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }
}
if (!function_exists('ri_from_base_db')) {
    /** ri_from_base() through the database; null when the unit cannot be converted. */
    function ri_from_base_db(PDO $pdo, int $ingredientId, string $qtyBase, string $unit): ?string
    {
        try {
            $st = $pdo->prepare('SELECT ri_from_base(:i, :q, :u)');
            $st->execute(['i' => $ingredientId, 'q' => $qtyBase, 'u' => $unit]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? null : (string) $v;
        } catch (PDOException) {
            return null;
        }
    }
}
if (!function_exists('ri_qty_sentence')) {
    /** "2 bag50 (45 359.24 g)" / "3 flat + 7 (97 each)" / "1.5 floz (44.36 ml)"; $unit null = the ingredient's default count unit, else its base. */
    function ri_qty_sentence(PDO $pdo, int $ingredientId, string $qtyBase, ?string $unit = null, int $decimals = 2): string
    {
        $i = ri_ingredient_row($pdo, $ingredientId);
        if ($i === null) { return ri_fmt($qtyBase, $decimals); }
        $base = (string) $i['base_unit_code'];
        $baseText = ri_fmt($qtyBase, $base === 'each' ? 0 : $decimals) . ' ' . $base;
        if ($unit === null) {
            $st = $pdo->prepare('SELECT unit_code FROM mcp_ingredient_units WHERE ingredient_id = :i AND is_default_count AND active');
            $st->execute(['i' => $ingredientId]);
            $unit = $st->fetchColumn() ?: null;
        }
        if ($unit === null || $unit === $base) { return $baseText; }
        $inUnit = ri_from_base_db($pdo, $ingredientId, $qtyBase, $unit);
        if ($inUnit === null) { return $baseText; }
        $whole = floor((float) $inUnit);
        $st = $pdo->prepare('SELECT kind FROM mcp_ingredient_units WHERE ingredient_id = :i AND unit_code = :u');
        $st->execute(['i' => $ingredientId, 'u' => $unit]);
        $kind = $st->fetchColumn();
        if ($kind === 'count' && $whole >= 1 && (float) $inUnit - $whole > 0.0001) {
            $per = ri_from_base_db($pdo, $ingredientId, '1', $unit);          // base per one unit = 1 / per
            $perUnitBase = $per !== null && (float) $per > 0 ? 1 / (float) $per : null;
            if ($perUnitBase !== null) {
                $rem = (float) $qtyBase - $whole * $perUnitBase;
                return ri_fmt($whole, 0) . ' ' . $unit . ' + ' . ri_fmt($rem, $base === 'each' ? 0 : $decimals) . ' (' . $baseText . ')';
            }
        }
        return ri_fmt($inUnit, $decimals) . ' ' . $unit . ' (' . $baseText . ')';
    }
}
if (!function_exists('ri_cost_sentence')) {
    /** "$0.0011 / g · $25.00 / bag50 · $1.10 / lb" from a per-base cost; '' when $perBase is null (walled). */
    function ri_cost_sentence(PDO $pdo, int $ingredientId, ?string $perBase, array $units = [], string $currency = '$'): string
    {
        if ($perBase === null || $perBase === '') { return ''; }
        $i = ri_ingredient_row($pdo, $ingredientId);
        if ($i === null) { return ''; }
        $parts = [$currency . ri_fmt($perBase, 4) . ' / ' . $i['base_unit_code']];
        foreach ($units as $u) {
            $per = ri_from_base_db($pdo, $ingredientId, '1', $u);               // units per one base → cost per unit = perBase / per
            if ($per !== null && (float) $per > 0) { $parts[] = $currency . ri_fmt((float) $perBase / (float) $per, 2) . ' / ' . $u; }
        }
        return implode(' · ', $parts);
    }
}
if (!function_exists('ri_unit_options')) {
    /** The <option>s of a unit <select> for an ingredient: its own units (kind-filtered) then the global units of its class (and the other classes it bridges); [['code','label','factor','kind']]. */
    function ri_unit_options(PDO $pdo, int $ingredientId, ?string $kind = null): array
    {
        $i = ri_ingredient_row($pdo, $ingredientId);
        if ($i === null) { return []; }
        $out = [];
        $st = $pdo->prepare('SELECT unit_code, name, to_base_factor, kind FROM mcp_ingredient_units WHERE ingredient_id = :i AND active' . ($kind !== null ? ' AND kind = :k' : '') . ' ORDER BY is_default_count DESC, kind, name');
        $st->execute($kind !== null ? ['i' => $ingredientId, 'k' => $kind] : ['i' => $ingredientId]);
        foreach ($st->fetchAll() as $u) { $out[] = ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ' — ' . $u['name'], 'factor' => $u['to_base_factor'], 'kind' => $u['kind']]; }
        $classes = [$i['measure_class']];
        if ($i['density_g_per_ml'] !== null) { $classes[] = $i['measure_class'] === 'weight' ? 'volume' : 'weight'; }
        if ($i['each_weight_g'] !== null) { $classes[] = $i['measure_class'] === 'each' ? 'weight' : 'each'; }
        if ($i['each_volume_ml'] !== null) { $classes[] = $i['measure_class'] === 'each' ? 'volume' : 'each'; }
        $st = $pdo->prepare('SELECT unit_code, name, to_base_factor, measure_class FROM mcp_units WHERE active AND measure_class = ANY (CAST(:c AS text[])) ORDER BY measure_class = :mine DESC, display_order');
        $st->execute(['c' => '{' . implode(',', array_unique($classes)) . '}', 'mine' => $i['measure_class']]);
        foreach ($st->fetchAll() as $u) { $out[] = ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ($u['unit_code'] === $u['name'] ? '' : ' — ' . $u['name']), 'factor' => $u['to_base_factor'], 'kind' => 'global']; }
        return $out;
    }
}
if (!function_exists('format_qty')) {
    /** A base quantity in the ingredient's base unit, for a table cell. */
    function format_qty(PDO $pdo, int $ingredientId, string $qtyBase): string
    {
        return ri_qty_sentence($pdo, $ingredientId, $qtyBase, null);
    }
}
