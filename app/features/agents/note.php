<?php
declare(strict_types=1);

/**
 * The morning note (projection.md "The morning note, assembled"; design §5). Seven headings in the design's order, each `{key, title, count, lines[], link, source}` where a
 * line is `{text, value, link}`: a heading reads the day's `buyer_proposals` of its kinds when any stand (the agent's own words) and the SQL gatherers otherwise — so the note
 * exists even when the kernel's agent did not run. A `value` is a cost: the note is rendered per RECIPIENT and a recipient who sees no cost reads "—" (DECISION 10).
 * Everything here reads base tables and the SQL functions (never the mcp_* views): the worker has no acting member, and slice 9's morning_note_inputs pass calls this.
 */
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/ordering/queries.php';

const NOTE_ACK_DAYS = 2;                  // a sent order unacknowledged for MORE than this many days is a finding (projection.md heading 7)
const NOTE_MAX_CHARS = 4000;

function note_line(string $text, ?string $value = null, ?string $link = null): array
{
    return ['text' => $text, 'value' => $value, 'link' => $link];
}

/** The site's name and zone. */
function note_site(PDO $pdo, int $scopeId): array
{
    return proj_row($pdo, 'SELECT name, timezone FROM sites WHERE scope_id = :s', ['s' => $scopeId]) ?? ['name' => 'Site ' . $scopeId, 'timezone' => 'UTC'];
}

/** "Sat 13:00" for a timestamp in the site's zone. */
function note_when(?string $utc, string $tz): string
{
    return $utc === null ? '' : format_ts($utc, $tz, 'D H:i');
}

function note_g_orders(PDO $pdo, int $scopeId, string $tz): array
{
    $st = $pdo->prepare('SELECT d.supplier_id, s.name, d.cutoff_at, d.delivery_on, a.lines, a.thin, a.total
          FROM ri_suppliers_due(:s, 36) d JOIN suppliers s ON s.id = d.supplier_id
          CROSS JOIN LATERAL (SELECT count(*) FILTER (WHERE x.qty_units > 0) AS lines, count(*) FILTER (WHERE x.qty_units > 0 AND x.thin_history) AS thin,
                                     sum(round(x.qty_units * x.unit_cost, 2)) FILTER (WHERE x.qty_units > 0) AS total FROM ri_suggested_order(:s2, d.supplier_id, now()) x) a ORDER BY d.cutoff_at, s.name');
    $st->execute(['s' => $scopeId, 's2' => $scopeId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $lines = (int) $r['lines'];
        $po = suggested_po_today($pdo, $scopeId, (int) $r['supplier_id']);
        $when = 'cut-off ' . note_when($r['cutoff_at'], $tz) . ' for ' . date('D', (int) strtotime((string) $r['delivery_on']));
        $text = $lines === 0 ? $r['name'] . ' — nothing to order (' . $when . ')'
            : $r['name'] . ' — ' . $lines . ' line' . ($lines === 1 ? '' : 's') . ' to order, ' . $when . ($po !== null ? '; drafted ' . $po['number'] : '; not drafted yet') . ((int) $r['thin'] > 0 ? ' (thin history on ' . (int) $r['thin'] . ')' : '');
        $out[] = note_line($text, $lines === 0 || $r['total'] === null ? null : '$' . number_format((float) $r['total'], 2), $po !== null ? '/purchasing/' . $po['purchase_order_id'] : '/purchasing/suggested?supplier=' . $r['supplier_id']);
    }
    return $out;
}

function note_g_stockouts(PDO $pdo, int $scopeId): array
{
    $st = $pdo->prepare('SELECT s.ingredient_id, i.name, s.stockout_on, s.on_hand_base FROM ri_stockouts_projected(:s, 7) s JOIN ingredients i ON i.id = s.ingredient_id ORDER BY s.stockout_on, i.name');
    $st->execute(['s' => $scopeId]);
    return array_map(static fn (array $r): array => note_line($r['name'] . ' runs out ' . date('D M j', (int) strtotime((string) $r['stockout_on'])) . ' — ' . ri_qty_text($pdo, (int) $r['ingredient_id'], $r['on_hand_base']) . ' on hand', null, '/projection/stock/' . $r['ingredient_id']), $st->fetchAll());
}

function note_g_prices(PDO $pdo, int $scopeId): array
{
    $out = [];
    $st = $pdo->prepare('SELECT m.ingredient_id, i.name, m.pct FROM ri_price_movers(:s, 1) m JOIN ingredients i ON i.id = m.ingredient_id
                          WHERE abs(m.pct) >= (SELECT price_alert_pct FROM ri_settings WHERE id = 1) ORDER BY abs(m.pct) DESC');
    $st->execute(['s' => $scopeId]);
    foreach ($st->fetchAll() as $r) { $out[] = note_line($r['name'] . ' moved in price', ((float) $r['pct'] > 0 ? '+' : '') . number_format((float) $r['pct'], 1) . ' %', '/ingredients/' . $r['ingredient_id']); }
    $open = (int) one_value($pdo, 'SELECT count(*) FROM price_alerts WHERE (scope_id = :s OR scope_id IS NULL) AND seen_at IS NULL', ['s' => $scopeId]);
    if ($open > 0) { $out[] = note_line($open . ' price alert' . ($open === 1 ? ' is' : 's are') . ' unseen', null, '/purchasing/alerts'); }
    $mis = (int) one_value($pdo, "SELECT count(*) FROM ri_invoice_mismatches(:s)", ['s' => $scopeId]);
    if ($mis > 0) { $out[] = note_line($mis . ' invoice line' . ($mis === 1 ? '' : 's') . ' do not match the order or the receipt', null, '/invoices/'); }
    return $out;
}

function note_g_pos(PDO $pdo, int $scopeId): array
{
    $st = $pdo->prepare("SELECT name, consecutive_failures, paused_at, array_to_string(days_missing, ', ') AS days_missing, unmapped_plus, unmapped_modifiers, pending_runs FROM ri_pos_health(:s) ORDER BY name");
    $st->execute(['s' => $scopeId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $bits = [];
        if ((int) $r['consecutive_failures'] > 0) { $bits[] = (int) $r['consecutive_failures'] . ' failed pull' . ((int) $r['consecutive_failures'] === 1 ? '' : 's') . ($r['paused_at'] !== null ? ' (paused)' : ''); }
        if ((string) $r['days_missing'] !== '') { $bits[] = 'no sales for ' . $r['days_missing']; }
        if ((int) $r['unmapped_plus'] > 0) { $bits[] = (int) $r['unmapped_plus'] . ' unmapped item' . ((int) $r['unmapped_plus'] === 1 ? '' : 's'); }
        if ((int) $r['unmapped_modifiers'] > 0) { $bits[] = (int) $r['unmapped_modifiers'] . ' unmapped modifier' . ((int) $r['unmapped_modifiers'] === 1 ? '' : 's'); }
        if ((int) $r['pending_runs'] > 0) { $bits[] = (int) $r['pending_runs'] . ' depletion run' . ((int) $r['pending_runs'] === 1 ? '' : 's') . ' waiting'; }
        if ($bits !== []) { $out[] = note_line($r['name'] . ': ' . implode('; ', $bits), null, '/sales/'); }
    }
    return $out;
}

function note_g_count(PDO $pdo, int $scopeId): array
{
    $c = proj_row($pdo, "SELECT id, number, as_of FROM inventory_counts WHERE scope_id = :s AND status = 'approved' ORDER BY as_of DESC, id DESC LIMIT 1", ['s' => $scopeId]);
    if ($c === null) { return []; }
    $out = [];
    $st = $pdo->prepare('SELECT v.ingredient_id, i.name, v.variance_base, v.variance_value FROM ri_count_variance(:c) v JOIN ingredients i ON i.id = v.ingredient_id WHERE v.over_tolerance ORDER BY abs(COALESCE(v.variance_value, 0)) DESC, i.name LIMIT 10');
    $st->execute(['c' => (int) $c['id']]);
    foreach ($st->fetchAll() as $r) {
        $out[] = note_line($c['number'] . ': ' . $r['name'] . ' ' . ((float) $r['variance_base'] > 0 ? '+' : '') . ri_qty_text($pdo, (int) $r['ingredient_id'], $r['variance_base']) . ' against the book',
            $r['variance_value'] === null ? null : ((float) $r['variance_value'] > 0 ? '+' : '−') . '$' . number_format(abs((float) $r['variance_value']), 2), '/counts/' . $c['id']);
    }
    $sk = $pdo->prepare('SELECT name, lines_skipped FROM ri_count_areas_skipped(:c)');
    $sk->execute(['c' => (int) $c['id']]);
    foreach ($sk->fetchAll() as $r) { $out[] = note_line($c['number'] . ': ' . $r['name'] . ' was skipped', null, '/counts/' . $c['id']); }
    return $out;
}

function note_g_forecast(PDO $pdo, int $scopeId, string $date): array
{
    $out = [];
    $st = $pdo->prepare('SELECT m.id, m.name, a.wape FROM forecast_accuracy a JOIN menu_items m ON m.id = a.menu_item_id WHERE a.scope_id = :s AND a.horizon_days = 7 AND a.window_days = 28 AND a.wape >= 0.3 ORDER BY a.wape DESC, m.name LIMIT 5');
    $st->execute(['s' => $scopeId]);
    foreach ($st->fetchAll() as $r) { $out[] = note_line($r['name'] . ': the forecast is off by ' . number_format((float) $r['wape'] * 100, 0) . ' % (WAPE)', null, '/projection/items/' . $r['id']); }
    $st = $pdo->prepare("SELECT c.on_date, c.closed, c.holiday, c.event FROM calendar_days c WHERE c.scope_id = :s AND c.on_date BETWEEN CAST(:d AS date) AND CAST(:d2 AS date) + 14 AND (c.closed OR c.holiday IS NOT NULL)
          AND NOT EXISTS (SELECT 1 FROM forecast_overrides o WHERE o.scope_id = c.scope_id AND o.status = 'set' AND c.on_date BETWEEN o.from_date AND o.to_date) ORDER BY c.on_date");
    $st->execute(['s' => $scopeId, 'd' => $date, 'd2' => $date]);
    foreach ($st->fetchAll() as $r) { $out[] = note_line(date('D M j', (int) strtotime((string) $r['on_date'])) . ': ' . ($r['holiday'] ?? ($r['closed'] ? 'closed' : 'event')) . ($r['closed'] ? ' (closed)' : '') . ' — no override set', null, '/projection/calendar?month=' . substr((string) $r['on_date'], 0, 7)); }
    return $out;
}

function note_g_pos_orders(PDO $pdo, int $scopeId, string $date): array
{
    $out = [];
    $st = $pdo->prepare("SELECT o.purchase_order_id, o.number, s.name, o.days_unacknowledged FROM ri_purchase_orders_open(:s) o JOIN suppliers s ON s.id = o.supplier_id WHERE o.status = 'sent' AND o.days_unacknowledged > " . NOTE_ACK_DAYS . ' ORDER BY o.days_unacknowledged DESC');
    $st->execute(['s' => $scopeId]);
    foreach ($st->fetchAll() as $r) { $out[] = note_line($r['number'] . ' (' . $r['name'] . ') is not acknowledged after ' . (int) $r['days_unacknowledged'] . ' days', null, '/purchasing/' . $r['purchase_order_id']); }
    $st = $pdo->prepare('SELECT d.purchase_order_id, d.number, s.name, d.expected_on FROM ri_deliveries_due(:s, CAST(:d AS date)) d JOIN suppliers s ON s.id = d.supplier_id WHERE NOT d.received ORDER BY d.expected_on');
    $st->execute(['s' => $scopeId, 'd' => $date]);
    foreach ($st->fetchAll() as $r) { $out[] = note_line($r['number'] . ' (' . $r['name'] . ') was due ' . date('D M j', (int) strtotime((string) $r['expected_on'])) . ' — nothing received', null, '/purchasing/due'); }
    return $out;
}

/** The day's proposals of a heading as note lines (the agent's words): title, its value if the detail carries one, the drafted record or the subject. Dismissed ones are left out. */
function note_proposal_lines(array $props): array
{
    $out = [];
    foreach ($props as $p) {
        if ($p['status'] === 'dismissed') { continue; }
        $value = isset($p['detail']['value']) && is_scalar($p['detail']['value']) ? (string) $p['detail']['value'] : (isset($p['detail']['total']) && is_scalar($p['detail']['total']) ? '$' . number_format((float) $p['detail']['total'], 2) : null);
        $link = record_url($p['drafted_record_type'], $p['drafted_record_id']) ?? record_url($p['subject_type'], $p['subject_id']);
        $out[] = note_line($p['title'] . ($p['status'] === 'accepted' ? ' (accepted)' : ''), $value, $link);
    }
    return $out;
}

/**
 * The note for a site and a day: ['site', 'date', 'headings' => [key => {key, title, count, lines[], link, source}], 'proposals' => n]. A heading that has proposals of its
 * kinds that day reads them; the others read their gatherer.
 */
function morning_note_assemble(PDO $pdo, int $scopeId, string $date): array
{
    $site = note_site($pdo, $scopeId);
    $tz = (string) $site['timezone'];
    $day = proposals($pdo, $scopeId, $date);
    $gather = [
        'orders' => static fn (): array => note_g_orders($pdo, $scopeId, $tz),
        'stockouts' => static fn (): array => note_g_stockouts($pdo, $scopeId),
        'prices' => static fn (): array => note_g_prices($pdo, $scopeId),
        'pos' => static fn (): array => note_g_pos($pdo, $scopeId),
        'count' => static fn (): array => note_g_count($pdo, $scopeId),
        'forecast' => static fn (): array => note_g_forecast($pdo, $scopeId, $date),
        'purchase_orders' => static fn (): array => note_g_pos_orders($pdo, $scopeId, $date),
    ];
    $headings = [];
    foreach (NOTE_HEADINGS as $key => [$title, $kinds, $link]) {
        $fromProps = $day['groups'][$key]['proposals'];
        $lines = $fromProps !== [] ? note_proposal_lines($fromProps) : $gather[$key]();
        $headings[$key] = ['key' => $key, 'title' => $title, 'count' => count($lines), 'lines' => $lines, 'link' => $link, 'source' => $fromProps !== [] ? 'proposals' : 'gatherer'];
    }
    return ['site' => $site['name'], 'date' => $date, 'headings' => $headings, 'proposals' => count($day['all'])];
}

/** The note as one Markdown document for a reader who $seesCost or not (a value reads "—" otherwise), at most NOTE_MAX_CHARS, a link on every line. */
function morning_note_markdown(array $note, bool $seesCost): string
{
    $build = static function (int $cap) use ($note, $seesCost): string {
        $md = '# Morning note — ' . $note['site'] . ', ' . date('D M j', (int) strtotime((string) $note['date'])) . "\n";
        $n = 0;
        foreach ($note['headings'] as $h) {
            $n++;
            $md .= "\n## " . $n . '. ' . $h['title'] . ' (' . $h['count'] . ")\n";
            if ($h['lines'] === []) { $md .= "Nothing to report.\n"; continue; }
            foreach (array_slice($h['lines'], 0, $cap) as $l) {
                $md .= '- ' . $l['text'] . ($l['value'] !== null ? ' — ' . ($seesCost ? $l['value'] : '—') : '') . ($l['link'] !== null ? ' [open](' . app_url($l['link']) . ')' : '') . "\n";
            }
            if (count($h['lines']) > $cap) { $md .= '- …and ' . (count($h['lines']) - $cap) . " more — [open](" . app_url($h['link']) . ")\n"; }
        }
        return $md;
    };
    foreach ([10, 6, 3, 1] as $cap) {
        $md = $build($cap);
        if (mb_strlen($md) <= NOTE_MAX_CHARS) { return $md; }
    }
    return mb_substr($md, 0, NOTE_MAX_CHARS - 1) . '…';
}
