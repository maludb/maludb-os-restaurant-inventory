<?php
declare(strict_types=1);

/**
 * The projection's writes (projection.md "Query functions" — Writes; the forecaster's contract is projection-and-ordering.md §1). The caller (a handler,
 * the worker) logs; run_forecast() owns its own transaction because it must not hold one open while the forecaster runs.
 *   - run_forecast: method 'baseline' writes a run whose rows are ri_forecast_baseline() (SQL, one INSERT … SELECT); 'holt_winters' reads the history, shells to
 *     bin/forecast.py (pure Python, no connection — it forecasts and nothing else) and writes the run it answers; both then score the baseline (MAE, WAPE, bias).
 *   - overrides, the calendar and the covers sync are rows; the database refuses what is not allowed (a factor AND a quantity, a window that ends first).
 */
require_once dirname(__DIR__, 2) . '/api/kernel.php';

/** The Python the forecaster runs under: RI_PYTHON, else the application's mcp/venv, else python3. */
function forecast_python(): string
{
    $env = (string) env('RI_PYTHON', '');
    if ($env !== '') { return $env; }
    $venv = APP_ROOT . '/mcp/venv/bin/python';
    return is_executable($venv) ? $venv : 'python3';
}

/** The forecaster's input for a site: the menu items sold in the last 84 days, each a daily series from its first sale in the window to yesterday (null = skip that day). */
function forecast_payload(PDO $pdo, int $scopeId, string $asOf, int $horizon, int $history): array
{
    $start = (new DateTimeImmutable($asOf))->modify('-' . $history . ' days')->format('Y-m-d');
    $yesterday = (new DateTimeImmutable($asOf))->modify('-1 day')->format('Y-m-d');
    $st = $pdo->prepare('SELECT DISTINCT business_date FROM sales_daily WHERE scope_id = :s AND day_part_id IS NULL AND business_date BETWEEN :a AND :b');
    $st->execute(['s' => $scopeId, 'a' => $start, 'b' => $yesterday]);
    $siteDays = array_fill_keys(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
    $st = $pdo->prepare('SELECT on_date FROM calendar_days WHERE scope_id = :s AND closed AND on_date BETWEEN :a AND :b');
    $st->execute(['s' => $scopeId, 'a' => $start, 'b' => (new DateTimeImmutable($asOf))->modify('+' . ($horizon - 1) . ' days')->format('Y-m-d')]);
    $closed = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    $closedSet = array_fill_keys($closed, true);
    $st = $pdo->prepare('SELECT sd.menu_item_id, sd.business_date, sd.qty FROM sales_daily sd JOIN menu_items m ON m.id = sd.menu_item_id
                          WHERE sd.scope_id = :s AND sd.day_part_id IS NULL AND sd.business_date BETWEEN :a AND :b AND m.active
                            AND EXISTS (SELECT 1 FROM sales_daily x WHERE x.scope_id = :s2 AND x.menu_item_id = sd.menu_item_id AND x.business_date >= CAST(:c AS date) - 84)
                          ORDER BY sd.menu_item_id, sd.business_date');
    $st->execute(['s' => $scopeId, 's2' => $scopeId, 'a' => $start, 'b' => $yesterday, 'c' => $asOf]);
    $sales = [];
    foreach ($st->fetchAll() as $r) { $sales[(int) $r['menu_item_id']][(string) $r['business_date']] = (float) $r['qty']; }
    $items = [];
    foreach ($sales as $id => $byDate) {
        $first = array_key_first($byDate);
        $y = [];
        for ($d = new DateTimeImmutable($first); $d->format('Y-m-d') <= $yesterday; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $y[] = isset($closedSet[$k]) || !isset($siteDays[$k]) ? null : ($byDate[$k] ?? 0.0);
        }
        $items[] = ['menu_item_id' => $id, 'start' => $first, 'y' => $y];
    }
    $future = array_values(array_filter($closed, static fn (string $d): bool => $d >= $asOf));
    return ['as_of' => $asOf, 'horizon' => $horizon, 'closed_future' => $future, 'items' => $items];
}

/** Shell to bin/forecast.py with the payload on stdin; its decoded answer, or a DomainException in words (the run is not touched). */
function forecast_exec(array $payload): array
{
    $cmd = escapeshellarg(forecast_python()) . ' ' . escapeshellarg(APP_ROOT . '/bin/forecast.py');
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) { throw new DomainException('The forecaster could not be started.'); }
    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $answer = json_decode(trim((string) $out), true);
    if ($code !== 0 || !is_array($answer) || isset($answer['error'])) {
        error_log('forecast.py: ' . trim($err) . ' ' . trim((string) $out));
        throw new DomainException('The forecaster failed' . (is_array($answer) && isset($answer['error']) ? ': ' . $answer['error'] : '.') . ' The current forecast stands.');
    }
    return $answer;
}

/** The accuracy rows (menu_item_id => wape) at a site before/after a computation — the morning note's "worsened" comparison (D11: no history table). */
function accuracy_snapshot(PDO $pdo, int $scopeId): array
{
    $st = $pdo->prepare('SELECT menu_item_id, wape FROM forecast_accuracy WHERE scope_id = :s AND horizon_days = 7 AND window_days = 28');
    $st->execute(['s' => $scopeId]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[(int) $r['menu_item_id']] = $r['wape']; }
    return $out;
}

/**
 * Make a forecast run for a site. $method baseline|holt_winters; a Holt-Winters run with no item of twelve weeks is a run of no rows (the baseline answers for every
 * item). Marks the run current (the previous one's flag cleared in the same transaction), then scores the baseline. Returns ['run_id', 'method', 'items', 'horizon_days',
 * 'history_days', 'seconds', 'skipped', 'accuracy_rows', 'worse' => [{menu_item_id, before, after}]]. $at stamps made_at (the worker's clock; now() otherwise).
 */
function run_forecast(PDO $pdo, int $scopeId, string $method, int $horizon, int $history, ?int $by, ?DateTimeInterface $at = null): array
{
    if (!in_array($method, ['baseline', 'holt_winters'], true)) { throw new DomainException('The method is baseline or holt_winters.'); }
    $horizon = max(1, min(92, $horizon));
    $history = max(28, min(1096, $history));
    $t0 = microtime(true);
    $asOf = proj_today($pdo);
    $before = accuracy_snapshot($pdo, $scopeId);
    $answer = null;
    if ($method === 'holt_winters') { $answer = forecast_exec(forecast_payload($pdo, $scopeId, $asOf, $horizon, $history)); }
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        $pdo->prepare('UPDATE forecast_runs SET is_current = false WHERE scope_id = :s AND is_current')->execute(['s' => $scopeId]);
        $params = $answer === null ? new stdClass() : ['items' => array_map(static fn (array $i): array => array_diff_key($i, ['days' => 1]), $answer['items']), 'skipped' => $answer['skipped']];
        $st = $pdo->prepare('INSERT INTO forecast_runs (scope_id, made_at, method, horizon_days, history_days, params, items, is_current, made_by) VALUES (:s, COALESCE(CAST(:at AS timestamptz), now()), :m, :h, :y, CAST(:p AS jsonb), 0, true, :by) RETURNING id');
        $st->execute(['s' => $scopeId, 'at' => $at?->format(DATE_ATOM), 'm' => $method, 'h' => $horizon, 'y' => $history, 'p' => json_encode($params, JSON_UNESCAPED_SLASHES), 'by' => $by]);
        $runId = (int) $st->fetchColumn();
        if ($answer === null) {
            $st = $pdo->prepare("INSERT INTO item_forecasts (run_id, menu_item_id, on_date, qty)
                SELECT :r, m.id, d::date, COALESCE(ri_forecast_baseline(:s, m.id, d::date), 0)
                  FROM menu_items m CROSS JOIN generate_series(CAST(:a AS date), CAST(:a2 AS date) + CAST(:h AS integer) - 1, interval '1 day') d
                 WHERE m.active AND EXISTS (SELECT 1 FROM sales_daily sd WHERE sd.scope_id = :s2 AND sd.menu_item_id = m.id AND sd.business_date >= CAST(:a3 AS date) - 84)");
            $st->execute(['r' => $runId, 's' => $scopeId, 's2' => $scopeId, 'a' => $asOf, 'a2' => $asOf, 'a3' => $asOf, 'h' => $horizon]);
            $items = (int) one_value($pdo, 'SELECT count(DISTINCT menu_item_id) FROM item_forecasts WHERE run_id = :r', ['r' => $runId]);
        } else {
            $ins = $pdo->prepare('INSERT INTO item_forecasts (run_id, menu_item_id, on_date, qty, lo, hi, sigma) VALUES (:r, :i, :d, :q, :lo, :hi, :sg)');
            foreach ($answer['items'] as $id => $it) {
                foreach ($it['days'] as $d) { $ins->execute(['r' => $runId, 'i' => (int) $id, 'd' => $d['on_date'], 'q' => $d['qty'], 'lo' => $d['lo'], 'hi' => $d['hi'], 'sg' => $it['sigma']]); }
            }
            $items = count($answer['items']);
        }
        $pdo->prepare('UPDATE forecast_runs SET items = :n WHERE id = :r')->execute(['n' => $items, 'r' => $runId]);
        if ($own) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $rows = (int) one_value($pdo, 'SELECT ri_forecast_accuracy_compute(:s, 28)', ['s' => $scopeId]);
    $worse = [];
    foreach (accuracy_snapshot($pdo, $scopeId) as $id => $after) {
        if (isset($before[$id]) && $after !== null && $before[$id] !== null && (float) $after - (float) $before[$id] > 0.1) { $worse[] = ['menu_item_id' => $id, 'before' => $before[$id], 'after' => $after]; }
    }
    return ['run_id' => $runId, 'method' => $method, 'items' => $items, 'horizon_days' => $horizon, 'history_days' => $history, 'seconds' => round(microtime(true) - $t0, 2),
            'skipped' => $answer['skipped'] ?? [], 'accuracy_rows' => $rows, 'worse' => $worse];
}

/** An override (status set, or proposed by an agent). $fields: menu_item_id|null, from_date, to_date, factor|null, qty|null, reason. Returns its id. */
function set_override(PDO $pdo, int $scopeId, array $fields, int $by, string $status = 'set'): int
{
    $st = $pdo->prepare('INSERT INTO forecast_overrides (scope_id, menu_item_id, from_date, to_date, factor, qty, reason, status, set_by) VALUES (:s, :mi, :f, :t, :fa, :q, :r, :st, :by) RETURNING id');
    $st->execute(['s' => $scopeId, 'mi' => $fields['menu_item_id'], 'f' => $fields['from_date'], 't' => $fields['to_date'], 'fa' => $fields['factor'], 'q' => $fields['qty'], 'r' => $fields['reason'], 'st' => $status, 'by' => $by]);
    return (int) $st->fetchColumn();
}

/** A person's decision on a proposed override: 'set' (it counts from now) or 'dismiss'. Returns the override row as it stands. */
function decide_override(PDO $pdo, int $id, string $decision, int $by): array
{
    $new = $decision === 'set' ? 'set' : 'dismissed';
    $st = $pdo->prepare("UPDATE forecast_overrides SET status = :n, set_by = :by WHERE id = :id AND status = 'proposed' RETURNING id, scope_id, menu_item_id, from_date, to_date, factor, qty, reason, status");
    $st->execute(['n' => $new, 'by' => $by, 'id' => $id]);
    $r = $st->fetch();
    if ($r === false) { throw new DomainException('That override was already decided.'); }
    return $r;
}

/** Remove an override in force (status set); the row goes. */
function remove_override(PDO $pdo, int $id): void
{
    $st = $pdo->prepare("DELETE FROM forecast_overrides WHERE id = :id AND status = 'set'");
    $st->execute(['id' => $id]);
    if ($st->rowCount() !== 1) { throw new DomainException('Not found.'); }
}

/** Save a trading day ($fields: closed bool, holiday, event, note). A day with nothing to say is no row. */
function set_calendar_day(PDO $pdo, int $scopeId, array $fields): void
{
    if (!$fields['closed'] && $fields['holiday'] === null && $fields['event'] === null && $fields['note'] === null) {
        $pdo->prepare('DELETE FROM calendar_days WHERE scope_id = :s AND on_date = :d')->execute(['s' => $scopeId, 'd' => $fields['on_date']]);
        return;
    }
    $pdo->prepare('INSERT INTO calendar_days (scope_id, on_date, closed, holiday, event, note) VALUES (:s, :d, :c, :h, :e, :n)
                   ON CONFLICT (scope_id, on_date) DO UPDATE SET closed = EXCLUDED.closed, holiday = EXCLUDED.holiday, event = EXCLUDED.event, note = EXCLUDED.note')
        ->execute(['s' => $scopeId, 'd' => $fields['on_date'], 'c' => $fields['closed'] ? 't' : 'f', 'h' => $fields['holiday'], 'e' => $fields['event'], 'n' => $fields['note']]);
}

/**
 * Read the booked covers from Reservations (K7 `reservations.covers_by_service`) for from..to — at most 62 days a call, two calls for a longer window — and write
 * covers_expected (source reservations). The answer's rows are {date, service, reservations, covers}; a service is mapped to the site's day part by its `service_name`
 * (case-insensitive), an unknown one is summed into the day's whole-day row. Reservations not connected (or unreachable) is NOT an error: the forecast runs without
 * covers, and the answer says so. Returns ['connected' => bool, 'code' => ?string, 'from', 'to', 'days', 'covers', 'rows'].
 */
function sync_covers(PDO $pdo, int $scopeId, string $from, string $to): array
{
    $site = proj_row($pdo, 'SELECT location_id FROM sites WHERE scope_id = :s', ['s' => $scopeId]);          // base tables: the worker has no member for the views
    $parts = [];
    $dp = $pdo->prepare('SELECT id AS day_part_id, service_name FROM day_parts WHERE scope_id = :s');
    $dp->execute(['s' => $scopeId]);
    foreach ($dp->fetchAll() as $p) { if ($p['service_name'] !== null && $p['service_name'] !== '') { $parts[mb_strtolower((string) $p['service_name'])] = (int) $p['day_part_id']; } }
    $fetched = [];
    for ($a = new DateTimeImmutable($from); $a <= new DateTimeImmutable($to); $a = $a->modify('+62 days')) {
        $b = min($a->modify('+61 days'), new DateTimeImmutable($to));
        $r = kernel_read($pdo, 'reservations', 'covers_by_service', ['location_id' => $site['location_id'] ?? null, 'from' => $a->format('Y-m-d'), 'to' => $b->format('Y-m-d')]);
        if ($r['status'] !== 200) { return ['connected' => false, 'code' => $r['error'], 'from' => $from, 'to' => $to, 'days' => 0, 'covers' => 0, 'rows' => 0]; }
        foreach (kernel_rows($r['result']) as $row) { $fetched[] = $row; }
    }
    $sum = [];
    foreach ($fetched as $row) {
        $date = (string) ($row['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $from || $date > $to) { continue; }
        $part = $parts[mb_strtolower((string) ($row['service'] ?? ''))] ?? 0;
        $sum[$date . '|' . $part] = ($sum[$date . '|' . $part] ?? 0) + max(0, (int) ($row['covers'] ?? 0));
    }
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        $pdo->prepare("DELETE FROM covers_expected WHERE scope_id = :s AND source = 'reservations' AND on_date BETWEEN :f AND :t")->execute(['s' => $scopeId, 'f' => $from, 't' => $to]);
        $ins = $pdo->prepare("INSERT INTO covers_expected (scope_id, on_date, day_part_id, covers, source) VALUES (:s, :d, :p, :c, 'reservations')
                              ON CONFLICT (scope_id, on_date, day_part_key) DO UPDATE SET covers = EXCLUDED.covers, source = 'reservations', synced_at = now() WHERE covers_expected.source = 'reservations'");
        $days = [];
        $covers = 0;
        foreach ($sum as $k => $c) {
            [$d, $p] = explode('|', $k);
            $ins->execute(['s' => $scopeId, 'd' => $d, 'p' => (int) $p === 0 ? null : (int) $p, 'c' => $c]);
            $days[$d] = true;
            $covers += $c;
        }
        if ($own) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return ['connected' => true, 'code' => null, 'from' => $from, 'to' => $to, 'days' => count($days), 'covers' => $covers, 'rows' => count($sum)];
}
