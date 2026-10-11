<?php
declare(strict_types=1);

/**
 * The counts' handler prelude (counts.md "Handlers"): the gates, the 404s, the entry reader (the whole / loose pair of a form and the `entries`
 * JSON of an agent go through the SAME reader, so a rule cannot differ by door), count_log() (count_id and scope_id on every row), the line
 * partial both the page and Pattern C answer, and the notifications. Every handler: ri_handler_begin() → the right at the site → the record through
 * its view (a hidden record is the same 404 as a missing one) → ri_guard() around one transaction → the schema's verb → log → ri_done().
 * A quantity enters only as a number and a unit the database converts; PHP sums no quantity and computes no variance.
 */
require_once dirname(__DIR__) . '/stock/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

function counts_begin(string $right): void
{
    stock_begin($right);
}

/** log_activity() with the counts' keys: count_id and scope_id on every row (+ ingredient_id on a line's). */
function count_log(PDO $pdo, string $action, string $entityType, int $entityId, ?int $countId, array $opts = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, $opts + ['scope_id' => current_site_id(), 'count_id' => $countId]);
}

/** A count of this site; 404 for one that is not here (another site's, or none). */
function count_or_404(PDO $pdo, ?int $id): array
{
    return stock_here_or_404($id === null ? null : find_count($pdo, $id), 'That count');
}

function count_line_or_404(PDO $pdo, ?int $id): array
{
    $l = $id === null ? null : find_count_line($pdo, $id);
    if ($l === null) { respond_not_found('That count line is not here.'); }
    count_or_404($pdo, (int) $l['count_id']);
    return $l;
}

function sheet_or_404(PDO $pdo, ?int $id): array
{
    return stock_here_or_404($id === null ? null : find_count_sheet($pdo, $id), 'That count sheet');
}

function container_or_404(PDO $pdo, ?int $id): array
{
    $c = $id === null ? null : find_container($pdo, $id);
    if ($c === null) { respond_not_found('That bottle or keg is not here.'); }
    return $c;
}

/** A count whose lines still change (open, in review or in recount); else a 422 in words. */
function count_require_changeable(array $count): void
{
    if (!in_array($count['status'], ['open', 'review', 'recount'], true)) {
        throw new DomainException('Count ' . $count['number'] . ' is ' . count_status_words($count['status']) . ' — its lines no longer change.');
    }
}

/** A non-negative finite quantity from a raw value (a thousands separator is dropped); null when empty; an error on $field when not a number. */
function count_qty(mixed $raw, string $field, string $label, array &$errors): ?float
{
    if ($raw === null || is_array($raw)) { return null; }
    $v = str_replace([',', ' '], '', trim((string) $raw));
    if ($v === '') { return null; }
    if (!is_numeric($v) || !is_finite((float) $v) || (float) $v < 0 || (float) $v > 1000000000) { $errors[$field] = $label . ' is a number of zero or more.'; return null; }
    return round((float) $v, 4);
}

/**
 * What was counted on a line, as entries [{unit_code, qty}]: the `entries` JSON of an agent ([{unit|unit_code, qty}]), else a form's two pairs
 * whole + whole_unit and loose + loose_unit (an empty one is left out; a missing unit is the line's count unit / the base unit). null = nothing entered.
 */
function count_entries_from_request(array $line, array &$errors): ?array
{
    $out = [];
    $raw = req_val('entries');
    if ($raw !== null && $raw !== '') {
        $v = json_decode($raw, true);
        if (!is_array($v) || !array_is_list($v)) { $errors['entries'] = 'Entries are a JSON list of {unit, qty}.'; return null; }
        foreach ($v as $e) {
            if (!is_array($e)) { $errors['entries'] = 'Entries are a JSON list of {unit, qty}.'; return null; }
            $unit = trim((string) ($e['unit_code'] ?? $e['unit'] ?? ''));
            $q = count_qty($e['qty'] ?? null, 'entries', 'Every quantity', $errors);
            if ($unit === '') { $errors['entries'] = 'Say the unit of every entry.'; continue; }
            if ($q === null) { $errors['entries'] ??= 'Every entry needs a quantity of zero or more.'; continue; }
            $out[] = ['unit_code' => $unit, 'qty' => $q];
        }
        return $out === [] ? null : $out;
    }
    foreach (['whole' => [$line['count_unit'], 'The count'], 'loose' => [$line['base_unit_code'], 'The loose count']] as $f => [$defaultUnit, $label]) {
        $rawq = req_val($f);
        if ($rawq === null || $rawq === '') { continue; }
        $q = count_qty($rawq, $f, $label, $errors);
        if ($q === null) { continue; }
        $out[] = ['unit_code' => (req_val($f . '_unit') ?: $defaultUnit), 'qty' => $q];
    }
    return $out === [] ? null : $out;
}

/** The scale's reading and the container it was read against, from the request: [gross_g|null, container_id|null]; an error on the field when wrong. */
function count_weight_from_request(PDO $pdo, array $line, array &$errors): array
{
    $gross = null;
    if (req_has('gross_weight_g') && (string) req_val('gross_weight_g') !== '') {
        $g = count_qty(req_val('gross_weight_g'), 'gross_weight_g', 'The weight', $errors);
        if ($g !== null && $g <= 0) { $errors['gross_weight_g'] = 'The weight is more than zero grams.'; $g = null; }
        $gross = $g;
    }
    $cid = null;
    if (req_has('container') && (string) req_val('container') !== '') {
        $c = ctype_digit((string) req_val('container')) ? find_container($pdo, (int) req_val('container')) : null;
        if ($c === null || $c['ingredient_id'] !== (int) $line['ingredient_id'] || !$c['active']) { $errors['container'] = 'That bottle or keg is not one of this ingredient\'s.'; }
        else { $cid = $c['container_id']; }
    }
    return [$gross, $cid];
}

/** A note field up to 500 characters. */
function count_note(array &$errors): ?string
{
    return ri_text('note', null, 500, 'The note', $errors);
}

/** The count's line as the page and the Pattern C answer render it. */
function count_line_html(PDO $pdo, array $count, array $line, ?string $error = null, bool $saved = false): string
{
    $prefs = my_prefs($pdo, (int) current_member_id());
    $mayEdit = in_array($count['status'], ['open', 'review', 'recount'], true) && has_right('stock.count', (int) $count['scope_id']);
    return view('counts/partials/count-line.php', ['line' => $line, 'count' => $count, 'units' => count_line_units($pdo, $line, $prefs['display_units']), 'mayEdit' => $mayEdit,
        'containerOptions' => (int) $line['container_count'] > 1 ? containers_of($pdo, (int) $line['ingredient_id']) : [], 'error' => $error, 'saved' => $saved, 'seesCost' => sees_cost((int) $count['scope_id'])]);
}

/** {lines, done, pending, area: {id, lines, done}} — the header the count sheet's script reads to move the progress bar without a reload. */
function count_progress_header(PDO $pdo, int $countId, int $areaId): void
{
    $p = count_progress($pdo, $countId);
    $a = array_values(array_filter(count_areas($pdo, $countId), static fn (array $x): bool => $x['storage_area_id'] === $areaId))[0] ?? ['lines' => 0, 'done' => 0];
    header('X-Count-Progress: ' . json_encode(['lines' => $p['lines'], 'done' => $p['lines'] - $p['pending'], 'pending' => $p['pending'], 'area' => ['id' => $areaId, 'lines' => (int) $a['lines'], 'done' => (int) $a['done']]]));
}

/** A site-local "YYYY-MM-DDTHH:MM" (or an ISO moment with a zone) → a UTC timestamp string; null with the error on $field when it is not a moment or lies more than a day ahead. */
function count_parse_as_of(string $v, int $scopeId, array &$errors, string $field = 'as_of'): ?string
{
    try {
        $dt = new DateTimeImmutable($v, new DateTimeZone(site_timezone($scopeId)));
    } catch (Exception) {
        $errors[$field] = 'As of is a date and time (YYYY-MM-DDTHH:MM) in the site\'s time.';
        return null;
    }
    if ($dt > new DateTimeImmutable('now +1 day +5 minutes')) { $errors[$field] = 'A count is not as of more than a day ahead.'; return null; }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
}

// ------------------------------------------------------------------------------------------------ notifications
function count_notify(PDO $pdo, int $memberId, string $kind, array $count, string $title, string $body, string $dedupe): void
{
    $dedupe .= ':m' . $memberId;            // ri_notify() dedupes on the key alone, across members: one key per recipient
    $pdo->prepare('SELECT ri_notify(:m, :k, \'inventory_count\', :id, :t, :b, :d, false, :s)')
        ->execute(['m' => $memberId, 'k' => $kind, 'id' => $count['count_id'], 't' => mb_substr($title, 0, 200), 'b' => $body, 'd' => $dedupe, 's' => $count['scope_id']]);
}

/**
 * After a review (counts.md "Notifications"): the members holding counts.approve are told the count is ready; when it went to recount, the members
 * holding stock.count other than those who counted the lines over tolerance are told which lines need a second count.
 */
function count_notify_review(PDO $pdo, array $count): void
{
    $id = (int) $count['count_id'];
    $over = (int) $count['lines_over'];
    foreach (members_holding($pdo, 'counts.approve', (int) $count['scope_id']) as $m) {
        count_notify($pdo, $m, 'count_review', $count, $count['number'] . ' is ready to review', $count['number'] . ' is ready to review: ' . $count['lines'] . ' lines, ' . $over . ' over tolerance', 'count:' . $id . ':review');
    }
    if ($count['status'] === 'recount') {
        $st = $pdo->prepare('SELECT DISTINCT counted_by FROM mcp_inventory_count_lines WHERE count_id = :c AND over_tolerance AND recount_qty_base IS NULL AND counted_by IS NOT NULL');
        $st->execute(['c' => $id]);
        $counters = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $n = (int) $count['lines_awaiting_recount'];
        foreach (members_holding($pdo, 'stock.count', (int) $count['scope_id']) as $m) {
            if (in_array($m, $counters, true)) { continue; }
            count_notify($pdo, $m, 'count_recount', $count, $n . ' lines of ' . $count['number'] . ' need a second count', $n . ' lines of ' . $count['number'] . ' need a second count', 'count:' . $id . ':recount');
        }
    }
}

/**
 * After an approval: the site's Manager is told when the total variance exceeds 1 % of the last closed period's cost of goods sold, or $100 when
 * no period is closed yet (DECISION 3). The Manager is the site's, else the business's (design D14).
 */
function count_notify_variance(PDO $pdo, array $count): void
{
    $total = $count['total_variance_value'];
    if ($total === null) { return; }
    $cogs = one_value($pdo, "SELECT cogs_value FROM periods WHERE scope_id = :s AND status = 'closed' AND cogs_value IS NOT NULL ORDER BY ends_on DESC LIMIT 1", ['s' => $count['scope_id']]);
    $limit = $cogs !== null && (float) $cogs > 0 ? (float) $cogs * 0.01 : 100.0;
    if (abs((float) $total) <= $limit) { return; }
    $manager = one_value($pdo, 'SELECT COALESCE(ss.manager_member_id, (SELECT manager_member_id FROM ri_settings WHERE id = 1)) FROM sites s LEFT JOIN site_settings ss ON ss.scope_id = s.scope_id WHERE s.scope_id = :s', ['s' => $count['scope_id']]);
    if ($manager === null) { return; }
    $word = (float) $total < 0 ? 'short' : 'over';
    count_notify($pdo, (int) $manager, 'variance', $count, $count['number'] . ' was approved ' . $word . ' by $' . number_format(abs((float) $total), 2), $count['number'] . ' was approved with a total variance of $' . number_format((float) $total, 2) . ' (' . $count['lines'] . ' lines).', 'count:' . (int) $count['count_id'] . ':variance');
}
