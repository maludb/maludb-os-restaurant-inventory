<?php
declare(strict_types=1);
/** Action `watch_clear` (log watch.clear): one's own watch (an administrator clears anyone's); watch. It stays on the list, greyed. Location the watches. */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
proj_begin('ri.read');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$w = stock_here_or_404(find_watch($pdo, (int) request_integer('watch')), 'That watch');
if ($w['member_id'] !== $me && !is_ri_admin($site)) { respond_not_found('That watch is not here.'); }
ri_guard($pdo, static function () use ($pdo, $w): void { clear_watch($pdo, $w['watch_id']); });
proj_log($pdo, 'watch.clear', 'watch', $w['watch_id'], ['ingredient_id' => $w['ingredient_id'], 'after' => ['watch_id' => $w['watch_id'], 'ingredient_id' => $w['ingredient_id'], 'kind' => $w['kind']]]);
ri_done('Stopped watching ' . $w['ingredient'], $w['watch_id'], ri_land('/projection/watches', 'cleared'), 'watchChanged', ['watch_id' => $w['watch_id']]);
