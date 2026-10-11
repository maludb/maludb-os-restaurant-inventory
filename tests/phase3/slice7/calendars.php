<?php
/** Slice 7 — costing calendars (periods.md "Proof": Calendars): the owner makes "Weeks" and it is the default, a second default clears the first, the preview of a week, a month and a 4-4-5 calendar, the form's refusals, the right, the agent's pause. Builds the world last. */
require __DIR__ . '/lib.php';
$owner = person(1); $dana = person(30); $marco = person(27); $ann = person(29);
$none = (int) one('SELECT count(*) FROM costing_calendars');

echo "1. The owner makes the weekly calendar\n";
$before = last_activity_id();
$r = post($owner, '/periods/calendars/save.php', ['name' => 'Weeks', 'kind' => 'week', 'year_start_on' => wk(1), 'week_starts' => '1', 'is_default' => '1']);
$weeks = (int) one("SELECT id FROM costing_calendars WHERE name = 'Weeks'");
ok($r['code'] === 302 && $weeks > 0 && str_contains($r['location'], '/periods/calendars?notice=created#calendar-row-' . $weeks), 'calendar_set makes "Weeks" and lands on its row');
$c = q('SELECT * FROM costing_calendars WHERE id = :i', ['i' => $weeks])[0];
ok($c['kind'] === 'week' && $c['year_start_on'] === wk(1) && (int) $c['week_starts'] === 1 && $c['is_default'] === true, 'a weekly calendar from this proof\'s year start, Monday, the default');
$a = activity('calendar.set', $before);
$af = json_decode($a[0]['after'] ?? '{}', true);
ok(count($a) === 1 && (int) $a[0]['scope_id'] === DOWNTOWN && $a[0]['entity_type'] === 'costing_calendar' && (int) $a[0]['entity_id'] === $weeks && $af['kind'] === 'week' && $af['year_start_on'] === wk(1) && $af['is_default'] === true, 'calendar.set is logged with scope_id and kind, year_start_on and is_default');
$h = page($owner, '/periods/calendars');
ok($h['code'] === 200 && has($h, 'data-screen="calendar-list"') && has($h, 'id="calendars-table"') && has($h, 'id="calendar-row-' . $weeks . '"') && has($h, 'id="calendar-form"') && has($h, 'id="calendar-form-field-year_start_on"') && has($h, 'id="calendar-preview"'), 'the screen: the table, the row, the form with its fields and the preview');

echo "2. One default\n";
$r = post_json($owner, '/periods/calendars/save.php', ['name' => 'Months', 'kind' => 'month', 'year_start_on' => date('Y') . '-01-01', 'is_default' => '1']);
$months = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $months > 0 && (int) one('SELECT count(*) FROM costing_calendars WHERE is_default') === 1 && (bool) one('SELECT is_default FROM costing_calendars WHERE id = :i', ['i' => $months]) && !(bool) one('SELECT is_default FROM costing_calendars WHERE id = :i', ['i' => $weeks]), 'a second default clears the first in the same transaction: exactly one default');
$r = post_json($owner, '/periods/calendars/save.php', ['calendar' => $weeks, 'is_default' => '1']);
ok($r['code'] === 200 && (bool) one('SELECT is_default FROM costing_calendars WHERE id = :i', ['i' => $weeks]) && (int) one('SELECT count(*) FROM costing_calendars WHERE is_default') === 1 && one('SELECT name FROM costing_calendars WHERE id = :i', ['i' => $weeks]) === 'Weeks', 'changing "Weeks" back to the default keeps its name and takes the default back (a field left out stays)');
$r = post_json($owner, '/periods/calendars/save.php', ['name' => 'Four-four-five', 'kind' => '4-4-5', 'year_start_on' => wk(1), 'week_starts' => '1']);
$c445 = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $c445 > 0 && !(bool) one('SELECT is_default FROM costing_calendars WHERE id = :i', ['i' => $c445]), 'a 4-4-5 calendar is made and is not the default unless asked');

echo "3. The preview of the periods a calendar would make\n";
$pv = static fn (int $cal, int $y): array => get_json($owner, "/periods/calendars?calendar=$cal&year=$y")['json']['data']['preview'] ?? [];
$p = $pv($weeks, fiscal_year());
$days = array_unique(array_column($p['periods'] ?? [], 'days'));
ok(in_array(count($p['periods'] ?? []), [52, 53], true) && $days === [7] && ($p['periods'][0]['starts_on'] ?? '') === wk(1), 'weeks: 52 or 53 seven-day periods from the year start (' . count($p['periods'] ?? []) . ')');
$p = $pv($months, (int) date('Y'));
ok(count($p['periods'] ?? []) === 12 && ($p['periods'][0]['days'] ?? 0) === 31 && ($p['periods'][1]['days'] ?? 0) === (((int) date('Y') % 4 === 0) ? 29 : 28), 'months: twelve, January 31 days, February 28 or 29');
$p = $pv($c445, fiscal_year());
$d = array_column($p['periods'] ?? [], 'days');
ok(count($d) === 12 && array_slice($d, 0, 3) === [28, 28, 35] && array_slice($d, 3, 3) === [28, 28, 35], '4-4-5: twelve periods of 28, 28, 35 days');
ok(array_sum(array_column($pv($c445, fiscal_year())['periods'], 'days')) >= 364, '…covering at least 52 weeks');
$h = page($owner, "/periods/calendars?calendar=$c445&year=" . fiscal_year());
ok(has($h, 'id="calendar-preview-table"') && has($h, 'id="calendar-preview-row-12"') && has($h, '12 periods would be made'), 'the preview table is on the screen, before the button');

echo "4. The form's refusals and the right\n";
$r = post_json($owner, '/periods/calendars/save.php', ['name' => 'Bad', 'kind' => 'fortnight', 'year_start_on' => wk(1)]);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && isset($r['json']['error']['fields']['kind']), 'a kind that is not one: 422 naming the field');
$r = post_json($owner, '/periods/calendars/save.php', ['name' => 'Bad', 'kind' => 'week', 'year_start_on' => '2026-02-30']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['year_start_on']), 'a date that is not a date: 422 naming the field');
$r = post_json($owner, '/periods/calendars/save.php', ['kind' => 'week', 'year_start_on' => wk(1)]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['name']), 'a calendar needs a name');
ok(post($dana, '/periods/calendars/save.php', ['name' => 'Nope', 'kind' => 'week', 'year_start_on' => wk(1)])['code'] === 403 && post($marco, '/periods/calendars/save.php', ['name' => 'Nope', 'kind' => 'week', 'year_start_on' => wk(1)])['code'] === 403, 'a calendar is configuration: the Chef and the Manager are refused (settings.manage)');
ok(page($dana, '/periods/calendars')['code'] === 403 && page($marco, '/periods/calendars')['code'] === 403 && page($ann, '/periods/calendars')['code'] === 403, '…and the screen is the administrator\'s');
ok((int) one('SELECT count(*) FROM costing_calendars') === $none + 3, '…and nothing was made by the refusals');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['calendar_set']['approval'] ?? null) === 'other', 'an agent\'s calendar_set pauses at the kernel\'s hook (approval other)');

echo "5. An agent changes one field\n";
$r = jpost(1, '/periods/calendars/save.php', ['calendar' => (string) $c445, '_partial' => '1', 'name' => 'Four-four-five (a)']);
ok($r['code'] === 200 && one('SELECT name FROM costing_calendars WHERE id = :i', ['i' => $c445]) === 'Four-four-five (a)' && one('SELECT kind FROM costing_calendars WHERE id = :i', ['i' => $c445]) === '4-4-5' && one('SELECT year_start_on FROM costing_calendars WHERE id = :i', ['i' => $c445]) === wk(1), '_partial=1: the name changes and the kind and the year start are kept');

period_world();
ok((int) one('SELECT count(*) FROM periods WHERE scope_id = 101') >= 52 && (int) one('SELECT count(*) FROM costing_calendars WHERE is_default') === 1, 'the proofs\' world is made: the year\'s periods at Downtown, one default calendar');
finish();
