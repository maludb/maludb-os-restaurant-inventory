<?php
declare(strict_types=1);

/**
 * The projection's two charts as inline SVG (projection.md "Files": no library, no external script; the dataviz neutrals with one accent). Each scales to its
 * container through the viewBox, carries a <title> and a text description for a reader without sight, and states every number it draws in the table beneath it
 * on the page. Colours are classes (assets/css/projection.css) so the dark skin reads too.
 *  - forecast_chart_svg(): the last eight weeks' actuals as bars, the next days' forecast as a line with its band shaded, closed days hatched, overrides marked.
 *  - projected_chart_svg(): projected on hand by day as a line, the receipts due as steps up, the zero line, the stock-out day marked.
 */

/** A number for an attribute: two decimals at most, no trailing zeros. */
function chart_n(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
}

/**
 * The item's chart. $actuals: item_actuals() rows; $days: item_forecast()['days']; $overrideDays: override_days() (a date => true map); $closedDays: date => true for the
 * forecast's closed days (source closed). Returns the <figure> markup; id forecast-chart.
 */
function forecast_chart_svg(array $actuals, array $days, array $overrideDays, string $name): string
{
    $slots = count($actuals) + count($days);
    if ($slots === 0) { return '<figure class="viz-root" id="forecast-chart"><div class="text-muted fs-12 py-3">Nothing to chart yet.</div></figure>'; }
    $w = 640; $h = 240; $x0 = 44; $x1 = $w - 8; $y0 = 16; $y1 = $h - 28;
    $max = 1.0;
    foreach ($actuals as $a) { $max = max($max, (float) ($a['qty'] ?? 0)); }
    foreach ($days as $d) { $max = max($max, (float) $d['qty'], (float) ($d['hi'] ?? 0)); }
    $max = ceil($max * 1.08);
    $step = ($x1 - $x0) / $slots;
    $X = static fn (int $i): float => $x0 + $step * ($i + 0.5);
    $Y = static fn (float $v): float => $y1 - ($v / $max) * ($y1 - $y0);
    $svg = '<figure class="viz-root fc-chart" id="forecast-chart" data-weeks="8" data-forecast-days="' . count($days) . '">';
    $svg .= '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-labelledby="forecast-chart-title forecast-chart-desc">';
    $svg .= '<title id="forecast-chart-title">' . e($name) . ': sold over the last ' . count($actuals) . ' days and forecast for the next ' . count($days) . '</title>';
    $svg .= '<desc id="forecast-chart-desc">Bars are what was sold each day; the line is the forecast with its band shaded; hatched days are closed; a ring marks an override.</desc>';
    $svg .= '<defs><pattern id="fc-hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="6" class="fc-hatch-line"/></pattern></defs>';
    for ($g = 0; $g <= 3; $g++) {
        $v = $max * $g / 3;
        $svg .= '<line class="fc-grid" x1="' . $x0 . '" y1="' . chart_n($Y($v)) . '" x2="' . $x1 . '" y2="' . chart_n($Y($v)) . '"/><text class="fc-axis" x="' . ($x0 - 5) . '" y="' . chart_n($Y($v) + 3) . '" text-anchor="end">' . e(chart_n($v)) . '</text>';
    }
    $nA = count($actuals);
    foreach ($actuals as $i => $a) {
        $bw = max(1.5, $step * 0.72);
        if ($a['closed']) {
            $svg .= '<rect class="fc-closed" x="' . chart_n($X($i) - $bw / 2) . '" y="' . $y0 . '" width="' . chart_n($bw) . '" height="' . ($y1 - $y0) . '" fill="url(#fc-hatch)"><title>' . e($a['on_date']) . ' closed</title></rect>';
        } elseif ($a['qty'] !== null) {
            $top = $Y((float) $a['qty']);
            $svg .= '<rect class="fc-bar" x="' . chart_n($X($i) - $bw / 2) . '" y="' . chart_n($top) . '" width="' . chart_n($bw) . '" height="' . chart_n(max(0.5, $y1 - $top)) . '"><title>' . e($a['on_date']) . ': sold ' . e(chart_n((float) $a['qty'])) . '</title></rect>';
        }
    }
    $band = [];
    $line = [];
    $bandBack = [];
    foreach ($days as $j => $d) {
        $x = $X($nA + $j);
        $line[] = chart_n($x) . ',' . chart_n($Y((float) $d['qty']));
        if ($d['lo'] !== null && $d['hi'] !== null) { $band[] = chart_n($x) . ',' . chart_n($Y((float) $d['hi'])); array_unshift($bandBack, chart_n($x) . ',' . chart_n($Y((float) $d['lo']))); }
    }
    if (count($band) > 1) { $svg .= '<polygon class="fc-band" points="' . implode(' ', array_merge($band, $bandBack)) . '"/>'; }
    foreach ($days as $j => $d) {
        if ($d['source'] === 'closed') {
            $svg .= '<rect class="fc-closed" x="' . chart_n($X($nA + $j) - $step / 2) . '" y="' . $y0 . '" width="' . chart_n($step) . '" height="' . ($y1 - $y0) . '" fill="url(#fc-hatch)"><title>' . e($d['on_date']) . ' closed</title></rect>';
        }
    }
    $svg .= '<polyline class="fc-line" fill="none" points="' . implode(' ', $line) . '"/>';
    foreach ($days as $j => $d) {
        if (isset($overrideDays[(string) $d['on_date']]) || $d['source'] === 'override') {
            $svg .= '<circle class="fc-override" cx="' . chart_n($X($nA + $j)) . '" cy="' . chart_n($Y((float) $d['qty'])) . '" r="4"><title>' . e($d['on_date']) . ': override</title></circle>';
        }
    }
    $svg .= '<line class="fc-today" x1="' . chart_n($x0 + $step * $nA) . '" y1="' . $y0 . '" x2="' . chart_n($x0 + $step * $nA) . '" y2="' . $y1 . '"/>';
    $svg .= '<text class="fc-axis" x="' . chart_n($x0 + $step * $nA) . '" y="' . ($h - 8) . '" text-anchor="middle">today</text>';
    if ($nA > 0) { $svg .= '<text class="fc-axis" x="' . $x0 . '" y="' . ($h - 8) . '" text-anchor="start">' . e(date('M j', (int) strtotime((string) $actuals[0]['on_date']))) . '</text>'; }
    if ($days !== []) { $svg .= '<text class="fc-axis" x="' . $x1 . '" y="' . ($h - 8) . '" text-anchor="end">' . e(date('M j', (int) strtotime((string) $days[count($days) - 1]['on_date']))) . '</text>'; }
    $svg .= '</svg><figcaption class="fs-12 text-muted"><span class="fc-key fc-key-bar"></span> sold <span class="fc-key fc-key-line"></span> forecast <span class="fc-key fc-key-band"></span> band <span class="fc-key fc-key-ring"></span> override</figcaption></figure>';
    return $svg;
}

/**
 * One ingredient's projected on hand: $days = projected_ingredient()['days'] (on_date, demand_base, receipts_base, projected_base), $stockout = its first negative date. The y axis is
 * the base quantity (the table beneath says it in the count unit). Returns the <figure>; id projected-chart.
 */
function projected_chart_svg(array $days, ?string $stockout, string $name, string $unit): string
{
    if ($days === []) { return '<figure class="viz-root" id="projected-chart"><div class="text-muted fs-12 py-3">Nothing to chart yet.</div></figure>'; }
    $w = 640; $h = 220; $x0 = 56; $x1 = $w - 10; $y0 = 14; $y1 = $h - 26;
    $vals = array_map(static fn (array $d): float => (float) $d['projected_base'], $days);
    $hi = max(1.0, max($vals));
    $lo = min(0.0, min($vals));
    $span = max(1.0, $hi - $lo);
    $n = count($days);
    $step = ($x1 - $x0) / max(1, $n - 1);
    $X = static fn (int $i): float => $x0 + $step * $i;
    $Y = static fn (float $v): float => $y1 - (($v - $lo) / $span) * ($y1 - $y0);
    $pts = [];
    foreach ($days as $i => $d) { $pts[] = chart_n($X($i)) . ',' . chart_n($Y((float) $d['projected_base'])); }
    $svg = '<figure class="viz-root fc-chart" id="projected-chart" data-days="' . $n . '"><svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-labelledby="projected-chart-title projected-chart-desc">';
    $svg .= '<title id="projected-chart-title">' . e($name) . ': projected on hand for the next ' . $n . ' days</title><desc id="projected-chart-desc">The line is the projected quantity on hand in ' . e($unit) . '; a step up is a delivery due; the dark line is zero.'
        . ($stockout !== null ? ' It runs out on ' . e($stockout) . '.' : ' It does not run out in this window.') . '</desc>';
    for ($g = 0; $g <= 3; $g++) {
        $v = $lo + $span * $g / 3;
        $svg .= '<line class="fc-grid" x1="' . $x0 . '" y1="' . chart_n($Y($v)) . '" x2="' . $x1 . '" y2="' . chart_n($Y($v)) . '"/><text class="fc-axis" x="' . ($x0 - 5) . '" y="' . chart_n($Y($v) + 3) . '" text-anchor="end">' . e(fmt_num_adaptive($v)) . '</text>';
    }
    $svg .= '<line class="fc-zero" x1="' . $x0 . '" y1="' . chart_n($Y(0)) . '" x2="' . $x1 . '" y2="' . chart_n($Y(0)) . '"/>';
    $svg .= '<polyline class="fc-line" fill="none" points="' . implode(' ', $pts) . '"/>';
    foreach ($days as $i => $d) {
        if ((float) $d['receipts_base'] > 0) {
            $svg .= '<line class="fc-receipt" x1="' . chart_n($X($i)) . '" y1="' . $y1 . '" x2="' . chart_n($X($i)) . '" y2="' . chart_n($Y((float) $d['projected_base'])) . '"><title>' . e($d['on_date']) . ': delivery due</title></line>';
        }
        if ($stockout !== null && $d['on_date'] === $stockout) {
            $svg .= '<circle class="fc-stockout" cx="' . chart_n($X($i)) . '" cy="' . chart_n($Y((float) $d['projected_base'])) . '" r="5"><title>' . e($d['on_date']) . ': first day under zero</title></circle>';
        }
    }
    $svg .= '<text class="fc-axis" x="' . $x0 . '" y="' . ($h - 7) . '" text-anchor="start">' . e(date('M j', (int) strtotime((string) $days[0]['on_date']))) . '</text>'
        . '<text class="fc-axis" x="' . $x1 . '" y="' . ($h - 7) . '" text-anchor="end">' . e(date('M j', (int) strtotime((string) $days[$n - 1]['on_date']))) . '</text>';
    return $svg . '</svg></figure>';
}
