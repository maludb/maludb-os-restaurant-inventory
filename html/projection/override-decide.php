<?php
declare(strict_types=1);
/** Action `forecast_override_decide` (log forecast.override_decide): forecast.adjust; override (a proposed one), decision (set | dismiss). Set: it counts from now. Location the item's forecast. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
proj_begin('forecast.adjust');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$o = stock_here_or_404(find_override($pdo, (int) request_integer('override')), 'That override');
$errors = [];
$decision = ri_choice('decision', null, ['set', 'dismiss'], 'The decision', $errors, false);
if ($decision === null && !isset($errors['decision'])) { $errors['decision'] = 'Say set or dismiss.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
$r = ri_guard($pdo, static fn (): array => decide_override($pdo, $o['override_id'], (string) $decision, $me));
proj_log($pdo, 'forecast.override_decide', 'forecast_override', $o['override_id'], ['after' => ['override_id' => $o['override_id'], 'menu_item_id' => $o['menu_item_id'], 'decision' => $decision, 'from_date' => $o['from_date'], 'to_date' => $o['to_date'], 'factor' => $o['factor'], 'qty' => $o['qty']]]);
ri_done($decision === 'set' ? 'Override set — it counts from now' : 'Override dismissed', $o['override_id'], ri_land($o['menu_item_id'] === null ? '/projection/' : '/projection/items/' . $o['menu_item_id'], $decision === 'set' ? 'override' : 'dismissed'), 'forecastChanged', ['override_id' => $o['override_id'], 'decision' => $decision]);
