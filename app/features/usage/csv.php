<?php
declare(strict_types=1);

/** The usage export (periods.md "Query functions"): the report's rows as CSV or JSON — by ingredient (every bucket in the count unit and in base units, values for who sees cost) or by category. */

function usage_csv(array $usage, string $by = 'ingredient'): string
{
    $sees = !$usage['withheld'];
    $out = fopen('php://temp', 'w+');
    if ($by === 'category') {
        fputcsv($out, array_merge(['category', 'ingredients'], $sees ? USAGE_VALUE_COLS : []));
        foreach ($usage['categories'] as $c) { fputcsv($out, array_merge([$c['category'], $c['ingredients']], $sees ? array_map(static fn (string $k) => $c[$k], USAGE_VALUE_COLS) : [])); }
    } else {
        fputcsv($out, array_merge(['ingredient', 'code', 'category', 'count_unit'], USAGE_BUCKETS, ['base_unit'], array_map(static fn (string $b): string => $b . '_base', USAGE_BUCKETS), $sees ? array_merge(['unit_cost_per_base'], USAGE_VALUE_COLS) : []));
        foreach ($usage['rows'] as $r) {
            fputcsv($out, array_merge([$r['name'], $r['code'], $r['category'], $r['count_unit']], array_map(static fn (string $b) => $r[$b . '_count'], USAGE_BUCKETS), [$r['base_unit_code']],
                array_map(static fn (string $b) => $r[$b . '_base'], USAGE_BUCKETS), $sees ? array_merge([$r['unit_cost']], array_map(static fn (string $k) => $r[$k], USAGE_VALUE_COLS)) : []));
        }
    }
    rewind($out);
    return (string) stream_get_contents($out);
}

function usage_json(array $usage, string $by = 'ingredient'): string
{
    $p = present_usage($usage);
    return json_encode($by === 'category' ? ['from' => $p['from'], 'to' => $p['to'], 'categories' => $p['categories'], 'totals' => $p['totals']] : $p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
}
