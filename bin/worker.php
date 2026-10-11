<?php
declare(strict_types=1);

/**
 * The application's worker — one pass a minute from restaurant-inventory-worker.timer (design §14): due POS pulls and the email
 * intake, the depletion runs for days with new sales, the forecast pass nightly per site, the suggested orders ahead of each
 * supplier's cut-off, price alerts, watches, the notification outbox (in-app, mail, K6 texts), agent dispatches, the supplier
 * link's expiry, the morning note's inputs at the duty's hour, key-usage pruning. Idempotent; advisory-locked so two runs never
 * overlap; each pass its own try (one failing never stops the next); the report is printed as one JSON line, logged once as
 * `worker.pass` (counts and sentences, never a payload, a credential or a key) and kept as a worker_passes row when the table
 * exists (db/007+, Spaces' shape).
 *   php bin/worker.php [--passes=a,b] [--limit=200]
 * With --passes the named passes run whether or not they are due; without it every pass runs. RI_WORKER_NOW (ISO 8601) replaces the
 * clock a pass selects by; honoured only when APP_ENV is not prod. Exits 1 when a pass failed.
 *
 * PHASE 0: every pass but `key_usage_prune` is a STUB that changes nothing and answers 0 — the slice whose data it touches fills it in
 * (design §10): `price_alerts`, `link_expiry` slice 4's; `pos_pulls`, `email_intake`, `depletion_runs` slice 5's (BUILT); `forecast_pass`,
 * `suggested_orders`, `watches` slice 8's; `notify_outbox`, `dispatches`, `morning_note_inputs` slice 9's. Each stub names its slice.
 * REAL from the kit: `key_usage_prune` (ri_prune_key_usage, db/005).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

/** The passes, in the order they run. A new pass is a name here and a function worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array. */
const WORKER_PASSES = ['pos_pulls', 'email_intake', 'depletion_runs', 'price_alerts', 'link_expiry', 'forecast_pass', 'suggested_orders', 'watches', 'notify_outbox', 'dispatches', 'morning_note_inputs', 'key_usage_prune'];

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--passes=')) {
        $only = array_values(array_filter(explode(',', substr($a, 9))));
        if (array_diff($only, WORKER_PASSES) !== []) {
            fwrite(STDERR, 'Unknown pass: ' . implode(', ', array_diff($only, WORKER_PASSES)) . '. Passes: ' . implode(', ', WORKER_PASSES) . ".\n");
            exit(2);
        }
    } elseif (str_starts_with($a, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($a, 8)));
    } else {
        fwrite(STDERR, "Usage: php bin/worker.php [--passes=a,b] [--limit=N]\n");
        exit(2);
    }
}
$now = new DateTimeImmutable();
if (($fake = getenv('RI_WORKER_NOW')) !== false && $fake !== '' && app_is_prod() === false) {
    $now = new DateTimeImmutable($fake);
}
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('restaurant_inventory_worker'))")->fetchColumn()) {
    echo json_encode(['skipped' => 'another pass holds the lock']) . "\n";
    exit(0);
}
$GLOBALS['__public_door'] = 'cron';                 // every row a pass writes is source cron, actor none
$started = microtime(true);
$report = ['at' => $now->format(DATE_ATOM), 'ran' => [], 'errors' => []];
foreach ($only === [] ? WORKER_PASSES : $only as $pass) {
    try {
        $report['ran'][$pass] = ('worker_pass_' . $pass)($pdo, $limit, $now);
    } catch (Throwable $e) {
        error_log('worker ' . $pass . ': ' . $e->getMessage());
        $report['errors'][] = $pass . ': ' . mb_substr($e->getMessage(), 0, 200);
    }
}
$report['seconds'] = round(microtime(true) - $started, 2);
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $report]);
try {
    if ($pdo->query("SELECT to_regclass('public.worker_passes') IS NOT NULL")->fetchColumn()) {
        $pdo->prepare('INSERT INTO worker_passes (started_at, finished_at, steps, error) VALUES (:s, now(), :steps, :err)')
            ->execute(['s' => date(DATE_ATOM, (int) $started), 'steps' => json_encode($report['ran'], JSON_UNESCAPED_SLASHES),
                       'err' => $report['errors'] === [] ? null : implode('; ', $report['errors'])]);
    }
} catch (Throwable $e) {
    error_log('worker_passes: ' . $e->getMessage());
}
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('restaurant_inventory_worker'))");
exit($report['errors'] === [] ? 0 : 1);

// ---- the passes (stubs until their slice) ------------------------------------------------------------------------------------------

/** SLICE 5 (built) — sources due by schedule: the POS's file over SFTP, the Square API; a pull per fetch, backoff on failure (app/features/pos/worker.php). */
function worker_pass_pos_pulls(PDO $pdo, int $limit, DateTimeImmutable $now): array { require_once APP_ROOT . '/app/features/pos/worker.php'; return pos_pass_pulls($pdo, $limit, $now); }
/** SLICE 5 (built) — the email intake at MaluMail: a POS report mailed to the intake address lands as a pull (`\\Seen` only once the pull row is written). */
function worker_pass_email_intake(PDO $pdo, int $limit, DateTimeImmutable $now): array { require_once APP_ROOT . '/app/features/pos/worker.php'; return pos_pass_email_intake($pdo, $limit, $now); }
/** SLICE 5 (built) — one depletion run per site per business date with new sales (ri_depletion_due() → ri_depletion_run()); a failure leaves the day due and notifies the Manager once a day. */
function worker_pass_depletion_runs(PDO $pdo, int $limit, DateTimeImmutable $now): array { require_once APP_ROOT . '/app/features/pos/worker.php'; return pos_pass_depletion($pdo, $limit, $now); }
/** SLICE 4 — price alerts: an invoice line whose price moved past the threshold (per category or ingredient) raises one. */
function worker_pass_price_alerts(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['raised' => 0, 'stub' => 'slice 4']; }
/** SLICE 4 — the supplier's secure link dies 30 days after its purchase order closes. */
function worker_pass_link_expiry(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['expired' => 0, 'stub' => 'slice 4']; }
/** SLICE 8 — the forecast pass nightly per site (after the sales cut): the baseline and Holt-Winters per menu item, exploded to ingredient demand; accuracy scored against yesterday. */
function worker_pass_forecast_pass(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sites' => 0, 'stub' => 'slice 8']; }
/** SLICE 8 — suggested orders ahead of each supplier's cut-off: one drafted PO per supplier with every step on the line; never sent. */
function worker_pass_suggested_orders(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['drafted' => 0, 'stub' => 'slice 8']; }
/** SLICE 8 — watches fired once per state change (a projected stock-out, a cost moved): a notification, a K6 text, a dispatch when the watch names an agent. */
function worker_pass_watches(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['fired' => 0, 'stub' => 'slice 8']; }
/** SLICE 9 — notification_outbox: queued email (MaluMail's REST send) and text (K6) rows sent, failed ones retried with backoff. */
function worker_pass_notify_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sent' => 0, 'stub' => 'slice 9']; }
/** SLICE 9 — agent_dispatches: a watch that names an agent becomes ONE chat turn of that agent through the kernel, carrying ids. */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sent' => 0, 'stub' => 'slice 9']; }
/** SLICE 9 — the Purchasing agent's inputs at the duty's hour per site: the suggested orders due, stock-outs projected, price movers, the POS's health, the last count's variance, the forecast's misses, open POs — counts for its note. */
function worker_pass_morning_note_inputs(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['cut' => 0, 'stub' => 'slice 9']; }
/** ri_prune_key_usage() (db/005): minute buckets older than a day, day buckets older than 35 days. Real from the kit. */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['pruned' => (int) one_value($pdo, 'SELECT ri_prune_key_usage()')];
}
