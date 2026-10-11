<?php /** The four-quadrant scatter (inline SVG): x = mix %, y = CM; the two thresholds. Data: rows (every item), cmAvg, mixThreshold */
$w = 640; $h = 320; $pad = 36;
$maxX = max(1.0, max(array_map(static fn ($r) => (float) $r['mix_pct'], $rows) ?: [1])) * 1.15;
$ys = array_map(static fn ($r) => (float) $r['cm'], $rows); $maxY = max(0.01, max($ys ?: [0.01])) * 1.15; $minY = min(0.0, min($ys ?: [0]));
$sx = static fn (float $x): float => $pad + $x / $maxX * ($w - 2 * $pad);
$sy = static fn (float $y): float => $h - $pad - ($y - $minY) / ($maxY - $minY) * ($h - 2 * $pad);
?>
<svg viewBox="0 0 <?= $w ?> <?= $h ?>" class="w-100" id="engineering-chart" role="img" aria-label="Menu engineering: popularity against contribution margin" style="max-height: 360px">
    <line x1="<?= $pad ?>" y1="<?= $h - $pad ?>" x2="<?= $w - $pad ?>" y2="<?= $h - $pad ?>" stroke="currentColor" stroke-opacity="0.3"/><line x1="<?= $pad ?>" y1="<?= $pad ?>" x2="<?= $pad ?>" y2="<?= $h - $pad ?>" stroke="currentColor" stroke-opacity="0.3"/>
    <line x1="<?= round($sx((float) $mixThreshold), 1) ?>" y1="<?= $pad ?>" x2="<?= round($sx((float) $mixThreshold), 1) ?>" y2="<?= $h - $pad ?>" stroke="#f59e0b" stroke-dasharray="4 3"/><text x="<?= round($sx((float) $mixThreshold) + 4, 1) ?>" y="<?= $pad + 10 ?>" font-size="10" fill="#f59e0b">mix <?= e(ri_fmt($mixThreshold, 1)) ?> %</text>
    <line x1="<?= $pad ?>" y1="<?= round($sy((float) $cmAvg), 1) ?>" x2="<?= $w - $pad ?>" y2="<?= round($sy((float) $cmAvg), 1) ?>" stroke="#f59e0b" stroke-dasharray="4 3"/><text x="<?= $pad + 4 ?>" y="<?= round($sy((float) $cmAvg) - 4, 1) ?>" font-size="10" fill="#f59e0b">CM <?= e(ri_money($cmAvg)) ?></text>
    <?php foreach ($rows as $r): $c = ['star' => '#22c55e', 'plowhorse' => '#f59e0b', 'puzzle' => '#3b82f6', 'dog' => '#ef4444'][$r['quadrant']] ?? '#64748b'; $x = round($sx((float) $r['mix_pct']), 1); $y = round($sy((float) $r['cm']), 1); ?>
        <circle cx="<?= $x ?>" cy="<?= $y ?>" r="6" fill="<?= $c ?>" id="engineering-point-<?= (int) $r['menu_item_id'] ?>"><title><?= e($r['name']) ?>: <?= e($r['quadrant']) ?></title></circle><text x="<?= $x + 8 ?>" y="<?= $y + 4 ?>" font-size="11" fill="currentColor"><?= e(mb_substr($r['name'], 0, 18)) ?></text>
    <?php endforeach; ?>
    <text x="<?= $w - $pad ?>" y="<?= $h - 8 ?>" font-size="10" text-anchor="end" fill="currentColor" fill-opacity="0.6">popularity (mix %) →</text><text x="<?= $pad ?>" y="<?= $pad - 8 ?>" font-size="10" fill="currentColor" fill-opacity="0.6">contribution margin ↑</text>
</svg>
