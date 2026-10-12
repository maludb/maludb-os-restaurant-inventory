<?php
declare(strict_types=1);

/**
 * The Purchasing agent's proposals (projection.md "Query functions"; signatures fixed — Phase 4's tools `buyer_proposals`, `morning_note` call these). A proposal is
 * the agent's finding of the day, accepted or dismissed by a person; the morning note groups them under its seven headings. Rows are read from the base table
 * for the SITE the caller asks for (every caller has asserted the site: a screen under require_site(), a tool under ri_require_reader, the worker for each site)
 * so the same function serves the screens and the worker, which has no member for the mcp_* views to answer.
 */
require_once dirname(__DIR__) . '/projection/queries.php';

const PROPOSAL_STATUS_CHIPS = ['proposed' => 'warning', 'accepted' => 'success', 'dismissed' => 'dark'];
const PROPOSAL_KINDS = ['order', 'stockout', 'price', 'pos', 'count', 'forecast', 'po_overdue', 'delivery'];
/** The note's seven headings in the design's order: key => [title, the proposal kinds under it, the screen it links to]. */
const NOTE_HEADINGS = [
    'orders' => ['Suggested orders', ['order'], '/purchasing/suggested'],
    'stockouts' => ['Stock-outs projected', ['stockout'], '/projection/stock?stockouts_only=yes'],
    'prices' => ['Prices', ['price'], '/purchasing/alerts'],
    'pos' => ['The POS', ['pos'], '/sales/'],
    'count' => ['The last count', ['count'], '/counts/'],
    'forecast' => ['The forecast', ['forecast'], '/projection/'],
    'purchase_orders' => ['Purchase orders and deliveries', ['po_overdue', 'delivery'], '/purchasing/due'],
];

function proposal_decode(array $r): array
{
    $r = stock_ints($r, ['proposal_id', 'scope_id', 'subject_id', 'drafted_record_id', 'proposed_by', 'decided_by']);
    $r['detail'] = json_decode((string) $r['detail'], true) ?: [];
    $r['heading'] = null;
    foreach (NOTE_HEADINGS as $key => [$t, $kinds]) { if (in_array($r['kind'], $kinds, true)) { $r['heading'] = $key; } }
    return $r;
}

const PROPOSAL_SELECT = 'SELECT b.id AS proposal_id, b.scope_id, b.kind, b.subject_type, b.subject_id, b.title, b.detail, b.drafted_record_type, b.drafted_record_id, b.status, b.proposed_by, pm.display_name AS proposed_by_name,
       b.note_date, b.decided_by, dm.display_name AS decided_by_name, b.decided_at, b.created_at FROM buyer_proposals b LEFT JOIN members pm ON pm.id = b.proposed_by LEFT JOIN members dm ON dm.id = b.decided_by';

function find_proposal(PDO $pdo, int $id): ?array
{
    $r = proj_row($pdo, PROPOSAL_SELECT . ' WHERE b.id = :id', ['id' => $id]);
    return $r === null ? null : proposal_decode($r);
}

/**
 * The day's proposals at a site, grouped by the note's headings: ['date', 'groups' => [key => {title, link, proposals[]}], 'all' => [...], 'counts'].
 * $filters: kind, status (proposed|accepted|dismissed).
 */
function proposals(PDO $pdo, int $scopeId, string $date, array $filters = []): array
{
    $where = 'b.scope_id = :s AND b.note_date = :d';
    $args = ['s' => $scopeId, 'd' => $date];
    if (in_array($filters['kind'] ?? '', PROPOSAL_KINDS, true)) { $where .= ' AND b.kind = :k'; $args['k'] = $filters['kind']; }
    if (isset(PROPOSAL_STATUS_CHIPS[$filters['status'] ?? ''])) { $where .= ' AND b.status = :st'; $args['st'] = $filters['status']; }
    $st = $pdo->prepare(PROPOSAL_SELECT . ' WHERE ' . $where . ' ORDER BY b.id');
    $st->execute($args);
    $all = array_map('proposal_decode', $st->fetchAll());
    $groups = [];
    foreach (NOTE_HEADINGS as $key => [$title, $kinds, $link]) { $groups[$key] = ['title' => $title, 'link' => $link, 'proposals' => []]; }
    foreach ($all as $p) { $groups[$p['heading']]['proposals'][] = $p; }
    return ['date' => $date, 'groups' => $groups, 'all' => $all, 'counts' => array_map(static fn (array $g): int => count($g['proposals']), $groups)];
}

/** The share of decided proposals accepted over the last $days days: ['decided', 'accepted', 'dismissed', 'rate' (0–100, one decimal) or null when none decided]. */
function acceptance_rate(PDO $pdo, int $scopeId, int $days = 30): array
{
    $r = proj_row($pdo, "SELECT count(*) FILTER (WHERE status IN ('accepted', 'dismissed')) AS decided, count(*) FILTER (WHERE status = 'accepted') AS accepted, count(*) FILTER (WHERE status = 'dismissed') AS dismissed,
              CASE WHEN count(*) FILTER (WHERE status IN ('accepted', 'dismissed')) > 0 THEN round(100.0 * count(*) FILTER (WHERE status = 'accepted') / count(*) FILTER (WHERE status IN ('accepted', 'dismissed')), 1) END AS rate
         FROM buyer_proposals WHERE scope_id = :s AND note_date >= current_date - CAST(:d AS integer)", ['s' => $scopeId, 'd' => $days]);
    return ['decided' => (int) $r['decided'], 'accepted' => (int) $r['accepted'], 'dismissed' => (int) $r['dismissed'], 'rate' => $r['rate']];
}

/** The member of the Purchasing agent — the agent the kernel hired as `buyer`, found through the mirror by its declared key (the feed carries it as the job title). Null when none is hired. */
function purchasing_agent_member_id(PDO $pdo): ?int
{
    $v = one_value($pdo, "SELECT id FROM members WHERE member_kind = 'agent' AND status = 'active' AND lower(COALESCE(job_title, '')) IN ('buyer', 'purchasing agent') ORDER BY id LIMIT 1");
    return $v === null ? null : (int) $v;
}

/** A proposal's detail as safe HTML by kind: a `summary` sentence, `lines` (strings or {text}), else the scalar facts as a list. Everything escaped. */
function proposal_detail_html(array $p): string
{
    $d = $p['detail'];
    if ($d === []) { return ''; }
    $h = '';
    if (isset($d['summary']) && is_string($d['summary'])) { $h .= '<p class="mb-1">' . e($d['summary']) . '</p>'; }
    $lines = [];
    foreach (is_array($d['lines'] ?? null) ? $d['lines'] : [] as $l) { $lines[] = is_array($l) ? (string) ($l['text'] ?? implode(' · ', array_map('strval', array_filter($l, 'is_scalar')))) : (string) $l; }
    if ($lines !== []) { $h .= '<ul class="mb-1 ps-3">' . implode('', array_map(static fn (string $l): string => '<li>' . e($l) . '</li>', array_slice($lines, 0, 12))) . (count($lines) > 12 ? '<li class="text-muted">…and ' . (count($lines) - 12) . ' more</li>' : '') . '</ul>'; }
    $facts = [];
    foreach ($d as $k => $v) {
        if (in_array($k, ['summary', 'lines', 'request_id'], true) || !is_scalar($v) || $v === '' || $v === null) { continue; }
        $facts[] = '<span class="me-3"><span class="text-muted">' . e(str_replace('_', ' ', (string) $k)) . '</span> ' . e(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v) . '</span>';
    }
    if ($facts !== []) { $h .= '<div class="fs-12">' . implode('', $facts) . '</div>'; }
    return $h;
}
