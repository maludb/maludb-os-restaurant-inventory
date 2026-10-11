<?php
declare(strict_types=1);

/**
 * Units — the ONE place a quantity is rendered in a unit (catalog.md "Query functions"; units-and-costing.md §1). Every later slice
 * calls these and never formats a quantity or multiplies a factor itself: the database converts (ri_to_base / ri_from_base), PHP
 * prints. Loaded by app/units.php (the build spec's name) and by the catalog's handler.
 */

const MEASURE_CLASSES = ['weight' => 'Weight', 'volume' => 'Volume', 'each' => 'Each'];
const CLASS_BASE = ['weight' => 'g', 'volume' => 'ml', 'each' => 'each'];
const CLASS_ICON = ['weight' => 'feather-anchor', 'volume' => 'feather-droplet', 'each' => 'feather-hash'];
/** Imperial display units per class (display_qty for a person whose display_units is imperial). */
const IMPERIAL_DISPLAY = ['weight' => ['oz', 'lb'], 'volume' => ['floz', 'qt', 'gal']];
const METRIC_DISPLAY = ['weight' => ['g', 'kg'], 'volume' => ['ml', 'l']];

/** The global units: [{unit_code, name, measure_class, to_base_factor, is_base, display_order, active}] by class then order. */
function global_units(PDO $pdo, bool $activeOnly = true): array
{
    return $pdo->query('SELECT unit_code, name, measure_class, to_base_factor, is_base, display_order, active FROM mcp_units' . ($activeOnly ? ' WHERE active' : '') . ' ORDER BY measure_class, display_order, unit_code')->fetchAll();
}

/** One global unit's row, or null. */
function global_unit(PDO $pdo, string $code): ?array
{
    $st = $pdo->prepare('SELECT unit_code, name, measure_class, to_base_factor, is_base, display_order, active FROM mcp_units WHERE unit_code = :c');
    $st->execute(['c' => $code]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A quantity in a unit → the ingredient's base unit, by the database (its refusal is the caller's field error). */
function qty_to_base(PDO $pdo, int $ingredientId, string $qty, string $unitCode): string
{
    $st = $pdo->prepare('SELECT ri_to_base(:i, :q, :u)');
    $st->execute(['i' => $ingredientId, 'q' => $qty, 'u' => $unitCode]);
    return (string) $st->fetchColumn();
}

/** A base quantity shown in a unit, by the database; null when the unit cannot be converted. */
function qty_from_base(PDO $pdo, int $ingredientId, string $qtyBase, string $unitCode): ?string
{
    try {
        $st = $pdo->prepare('SELECT ri_from_base(:i, :q, :u)');
        $st->execute(['i' => $ingredientId, 'q' => $qtyBase, 'u' => $unitCode]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    } catch (PDOException $e) {
        return null;
    }
}

/** A number with thousands separators and at most $decimals decimals; a whole number prints whole. */
/** A number for the value of an <input type="number">: no thousands separator (a browser drops "3,000"), trailing zeros trimmed. */
function fmt_input(float|int|string|null $n, int $decimals = 4): string
{
    if ($n === null || $n === '') { return ''; }
    $r = rtrim(rtrim(number_format(round((float) $n, $decimals), $decimals, '.', ''), '0'), '.');
    return $r === '' || $r === '-' ? '0' : $r;
}

function fmt_num(float|int|string|null $n, int $decimals = 2): string
{
    if ($n === null || $n === '') { return ''; }
    $f = (float) $n;
    $r = round($f, $decimals);
    if (abs($r - round($r)) < 1e-9) { return number_format($r, 0); }
    return rtrim(rtrim(number_format($r, $decimals, '.', ','), '0'), '.');
}

/** The ingredient's units for a select: its own (kind-filtered) then its class's global units (and the classes it bridges). [{code, label, factor, kind, measure_class}] */
function ri_unit_options(PDO $pdo, int $ingredientId, ?string $kind = null): array
{
    $st = $pdo->prepare('SELECT ingredient_id, measure_class, base_unit_code, recipe_unit_code, density_g_per_ml, each_weight_g, each_volume_ml FROM mcp_ingredients WHERE ingredient_id = :id');
    $st->execute(['id' => $ingredientId]);
    $i = $st->fetch();
    if ($i === false) { return []; }
    $out = [];
    $st = $pdo->prepare('SELECT unit_code, name, to_base_factor, kind FROM mcp_ingredient_units WHERE ingredient_id = :id AND active' . ($kind !== null ? ' AND kind = :k' : '') . ' ORDER BY kind, unit_code');
    $st->execute($kind !== null ? ['id' => $ingredientId, 'k' => $kind] : ['id' => $ingredientId]);
    foreach ($st->fetchAll() as $u) {
        $out[] = ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ' — ' . $u['name'], 'factor' => $u['to_base_factor'], 'kind' => $u['kind'], 'measure_class' => $i['measure_class'], 'own' => true];
    }
    $classes = [$i['measure_class']];
    if ($i['density_g_per_ml'] !== null) { $classes[] = $i['measure_class'] === 'weight' ? 'volume' : 'weight'; }
    if ($i['measure_class'] === 'each') { if ($i['each_weight_g'] !== null) { $classes[] = 'weight'; } if ($i['each_volume_ml'] !== null) { $classes[] = 'volume'; } }
    elseif ($i['each_weight_g'] !== null || $i['each_volume_ml'] !== null) { $classes[] = 'each'; }
    foreach (global_units($pdo) as $g) {
        if (in_array($g['measure_class'], $classes, true)) {
            $out[] = ['code' => $g['unit_code'], 'label' => $g['unit_code'] . ' — ' . $g['name'], 'factor' => $g['to_base_factor'], 'kind' => 'global', 'measure_class' => $g['measure_class'], 'own' => false];
        }
    }
    return $out;
}

/** The ingredient's units with their factor sentence: own units then the class's global ones. [{unit_code, name, kind, to_base_factor, own, is_default_count, active, sentence}] */
function ingredient_units(PDO $pdo, int $ingredientId, bool $activeOnly = true): array
{
    $st = $pdo->prepare('SELECT ingredient_id, measure_class, base_unit_code FROM mcp_ingredients WHERE ingredient_id = :id');
    $st->execute(['id' => $ingredientId]);
    $i = $st->fetch();
    if ($i === false) { return []; }
    $rows = [];
    $st = $pdo->prepare('SELECT ingredient_unit_id, unit_code, name, to_base_factor, kind, is_default_count, active FROM mcp_ingredient_units WHERE ingredient_id = :id' . ($activeOnly ? ' AND active' : '') . ' ORDER BY active DESC, kind, unit_code');
    $st->execute(['id' => $ingredientId]);
    foreach ($st->fetchAll() as $u) {
        $u['own'] = true;
        $u['sentence'] = format_factor_sentence($u['unit_code'], (string) $u['to_base_factor'], $i['base_unit_code']);
        $rows[] = $u;
    }
    foreach (global_units($pdo) as $g) {
        if ($g['measure_class'] === $i['measure_class']) {
            $rows[] = ['ingredient_unit_id' => null, 'unit_code' => $g['unit_code'], 'name' => $g['name'], 'to_base_factor' => $g['to_base_factor'], 'kind' => 'global', 'is_default_count' => false, 'active' => true, 'own' => false,
                'sentence' => $g['is_base'] ? 'the base unit' : format_factor_sentence($g['unit_code'], (string) $g['to_base_factor'], $i['base_unit_code'])];
        }
    }
    return $rows;
}

/** "1 bag50 = 22 679.6 g" */
function format_factor_sentence(string $unitCode, string $factor, string $baseUnit): string
{
    return '1 ' . $unitCode . ' = ' . fmt_num($factor, 4) . ' ' . $baseUnit;
}

/** The default count unit of an ingredient (its own flagged one), else null. */
function default_count_unit(PDO $pdo, int $ingredientId): ?string
{
    $st = $pdo->prepare('SELECT unit_code FROM mcp_ingredient_units WHERE ingredient_id = :id AND is_default_count AND active');
    $st->execute(['id' => $ingredientId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

/**
 * A base quantity in a unit with the unit's code — "2 bag50" / "2.5 kg" / "180 each"; when $unitCode is null the ingredient's default
 * count unit, else the base. The ONE place a quantity is rendered (catalog.md).
 */
function format_qty(PDO $pdo, int $ingredientId, string|float|int|null $qtyBase, ?string $unitCode = null, int $decimals = 2): string
{
    if ($qtyBase === null || $qtyBase === '') { return '—'; }
    $st = $pdo->prepare('SELECT base_unit_code FROM mcp_ingredients WHERE ingredient_id = :id');
    $st->execute(['id' => $ingredientId]);
    $base = (string) $st->fetchColumn();
    $unit = $unitCode ?? default_count_unit($pdo, $ingredientId) ?? $base;
    if ($unit === $base) { return fmt_num($qtyBase, $decimals) . ' ' . $base; }
    $v = qty_from_base($pdo, $ingredientId, (string) $qtyBase, $unit);
    return $v === null ? fmt_num($qtyBase, $decimals) . ' ' . $base : fmt_num($v, $decimals) . ' ' . $unit;
}

/** A base quantity for a PERSON: the count unit when one is set, else a global unit of the class in their display units (metric or imperial), the larger unit when ≥ 1 of it. */
function display_qty(PDO $pdo, int $ingredientId, string|float|int|null $qtyBase, string $displayUnits = 'metric', int $decimals = 2): string
{
    if ($qtyBase === null || $qtyBase === '') { return '—'; }
    $st = $pdo->prepare('SELECT measure_class, base_unit_code FROM mcp_ingredients WHERE ingredient_id = :id');
    $st->execute(['id' => $ingredientId]);
    $i = $st->fetch();
    if ($i === false) { return fmt_num($qtyBase, $decimals); }
    if ($i['measure_class'] === 'each') { return fmt_num($qtyBase, $decimals) . ' each'; }
    $units = $displayUnits === 'imperial' ? IMPERIAL_DISPLAY[$i['measure_class']] : METRIC_DISPLAY[$i['measure_class']];
    $chosen = $units[0];
    foreach ($units as $u) { $v = qty_from_base($pdo, $ingredientId, (string) $qtyBase, $u); if ($v !== null && abs((float) $v) >= 1) { $chosen = $u; } }
    $v = qty_from_base($pdo, $ingredientId, (string) $qtyBase, $chosen);
    return ($v === null ? fmt_num($qtyBase, $decimals) . ' ' . $i['base_unit_code'] : fmt_num($v, $decimals) . ' ' . $chosen);
}

/** "2.5 kg = 2 500 g" — a quantity and its base, for a conversion sentence; null when the unit cannot convert. */
function ri_qty_sentence(PDO $pdo, int $ingredientId, string $qtyBase, ?string $unit = null, int $decimals = 2): string
{
    $st = $pdo->prepare('SELECT base_unit_code FROM mcp_ingredients WHERE ingredient_id = :id');
    $st->execute(['id' => $ingredientId]);
    $base = (string) $st->fetchColumn();
    $unit ??= default_count_unit($pdo, $ingredientId);
    if ($unit === null || $unit === $base) { return fmt_num($qtyBase, $decimals) . ' ' . $base; }
    $v = qty_from_base($pdo, $ingredientId, $qtyBase, $unit);
    return $v === null ? fmt_num($qtyBase, $decimals) . ' ' . $base : fmt_num($v, $decimals) . ' ' . $unit . ' = ' . fmt_num($qtyBase, $decimals) . ' ' . $base;
}

/** "$0.0011 / g · $25.00 / bag50 · $1.10 / lb" from a per-base cost; '' when $perBase is null (walled). */
function ri_cost_sentence(PDO $pdo, int $ingredientId, ?string $perBase, array $units = [], string $currency = '$'): string
{
    if ($perBase === null || $perBase === '') { return ''; }
    $st = $pdo->prepare('SELECT base_unit_code FROM mcp_ingredients WHERE ingredient_id = :id');
    $st->execute(['id' => $ingredientId]);
    $base = (string) $st->fetchColumn();
    $parts = [$currency . fmt_num($perBase, 4) . ' / ' . $base];
    foreach ($units as $u) {
        if ($u === $base) { continue; }
        $per = cost_per_unit($pdo, $ingredientId, $perBase, $u);
        if ($per !== null) { $parts[] = $currency . fmt_num($per, 2) . ' / ' . $u; }
    }
    return implode(' · ', $parts);
}

/** A per-base cost expressed per one unit (cost × base units per unit), through ri_to_base; null when the unit cannot convert. */
function cost_per_unit(PDO $pdo, int $ingredientId, ?string $perBase, string $unitCode): ?string
{
    if ($perBase === null) { return null; }
    try {
        $per = qty_to_base($pdo, $ingredientId, '1', $unitCode);
    } catch (PDOException $e) {
        return null;
    }
    return number_format((float) $perBase * (float) $per, 4, '.', '');
}

/** Money for a screen: "$12.34", or "—" with the withheld title when null. */
function money(?string $amount, int $decimals = 2): string
{
    return $amount === null || $amount === '' ? '<span class="text-muted" title="cost withheld">—</span>' : '$' . number_format((float) $amount, $decimals);
}

// ---- moved from app/recipes_units.php when the two copies were reconciled (2026-10-11): one place for every quantity and money helper
/** A number with the thousands separator and at most $decimals decimals (a whole number prints without decimals). */
function ri_fmt(string|float|int|null $n, int $decimals = 2): string
{
    if ($n === null || $n === '') { return ''; }
    $f = (float) $n;
    $s = number_format($f, $decimals, '.', ' ');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

function ri_money(string|float|int|null $n, string $currency = '$', int $decimals = 2): string
{
    return $n === null || $n === '' ? '' : $currency . number_format((float) $n, $decimals, '.', ',');
}

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
