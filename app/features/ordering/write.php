<?php
declare(strict_types=1);

/**
 * Drafting the suggested orders (projection.md "Posting" — Suggested orders). The application only ever DRAFTS: ri_suggested_order_draft() writes a purchase order in
 * status draft with every step of the rule on each line, and sending it (money out) is slice 4's `purchase_order_send`. A supplier that already has a draft of origin
 * `suggested` or `agent` made today is skipped (DECISION 6) so a re-run — a second click, the agent's duty after the worker's pass — never doubles an order.
 */
require_once __DIR__ . '/queries.php';

/**
 * Run the suggested orders for one supplier (`$supplierId`) or every supplier due within $dueHours. With $draft false it only reads (the screen's refresh); with it,
 * one draft per supplier. Returns ['suppliers' => [{supplier_id, supplier, lines (to order), drafted_po_id, number, skipped, reason, cutoff_at, delivery_on, total}]].
 * $by is the member drafting (null for the worker), $origin 'suggested' (a person) or 'agent'. The Manager is told once per draft the agent made (`agent_drafted`).
 */
function run_suggested(PDO $pdo, int $scopeId, ?int $supplierId, int $dueHours, bool $draft, ?int $by, string $origin = 'suggested'): array
{
    if ($supplierId !== null) {
        $ids = [$supplierId];
    } else {
        $st = $pdo->prepare('SELECT supplier_id FROM ri_suppliers_due(:s, CAST(:h AS integer)) ORDER BY cutoff_at, supplier_id');
        $st->execute(['s' => $scopeId, 'h' => $dueHours]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    $out = ['suppliers' => []];
    foreach ($ids as $id) {
        // base tables and the SQL functions only — the worker has no member, and the mcp_* views answer nothing without one
        $card = proj_row($pdo, 'SELECT s.name, w.cutoff_at, w.delivery_on FROM suppliers s CROSS JOIN LATERAL ri_next_order_windows(s.id, :s, now()) w WHERE s.id = :sup', ['s' => $scopeId, 'sup' => $id]);
        if ($card === null) { continue; }
        $agg = proj_row($pdo, 'SELECT count(*) FILTER (WHERE qty_units > 0) AS lines, sum(round(qty_units * unit_cost, 2)) FILTER (WHERE qty_units > 0) AS total FROM ri_suggested_order(:s, :sup, now())', ['s' => $scopeId, 'sup' => $id]);
        $card['to_order'] = (int) ($agg['lines'] ?? 0);
        $card['total'] = $agg['total'] ?? null;
        $row = ['supplier_id' => $id, 'supplier' => $card['name'], 'lines' => $card['to_order'], 'drafted_po_id' => null, 'number' => null, 'skipped' => false, 'reason' => null,
                'cutoff_at' => $card['cutoff_at'], 'delivery_on' => $card['delivery_on'], 'total' => $card['total']];
        if ($draft) {
            $already = suggested_po_today($pdo, $scopeId, $id);
            if ($already !== null) {
                $row['skipped'] = true;
                $row['reason'] = 'already drafted today';
                $row['drafted_po_id'] = $already['purchase_order_id'];
                $row['number'] = $already['number'];
            } elseif ($card['to_order'] === 0) {
                $row['skipped'] = true;
                $row['reason'] = 'covered';
            } else {
                $po = one_value($pdo, 'SELECT ri_suggested_order_draft(:s, :sup, CAST(:by AS bigint), :o)', ['s' => $scopeId, 'sup' => $id, 'by' => $by, 'o' => $origin]);
                if ($po !== null) {
                    $row['drafted_po_id'] = (int) $po;
                    $row['number'] = (string) one_value($pdo, 'SELECT number FROM purchase_orders WHERE id = :id', ['id' => (int) $po]);
                    if ($origin === 'agent') { tell_agent_drafted($pdo, $scopeId, (int) $po, $row); }
                } else {
                    $row['skipped'] = true;
                    $row['reason'] = 'covered';
                }
            }
        }
        $out['suppliers'][] = $row;
    }
    return $out;
}

/** The sentence a run of suppliers answers with: "3 drafted, 1 covered, 1 already drafted today", or for one supplier "Nothing to order from Sysco before Tuesday". */
function suggested_summary(array $res): string
{
    $s = $res['suppliers'];
    if ($s === []) { return 'No supplier has a cut-off in that window.'; }
    if (count($s) === 1) {
        $r = $s[0];
        if ($r['drafted_po_id'] !== null && !$r['skipped']) { return 'Drafted ' . $r['number'] . ' for ' . $r['supplier'] . ' — ' . $r['lines'] . ' line' . ($r['lines'] === 1 ? '' : 's') . '. It is a draft: a Manager sends it.'; }
        if ($r['reason'] === 'already drafted today') { return 'Already drafted today — ' . $r['number'] . '.'; }
        if ($r['reason'] === 'covered') { return 'Nothing to order from ' . $r['supplier'] . ' before ' . date('l', (int) strtotime((string) $r['delivery_on'])) . '.'; }
        return $r['lines'] . ' line' . ($r['lines'] === 1 ? '' : 's') . ' to order from ' . $r['supplier'] . '.';
    }
    $n = ['drafted' => 0, 'covered' => 0, 'already' => 0, 'read' => 0];
    foreach ($s as $r) {
        if ($r['drafted_po_id'] !== null && !$r['skipped']) { $n['drafted']++; }
        elseif ($r['reason'] === 'covered') { $n['covered']++; }
        elseif ($r['reason'] === 'already drafted today') { $n['already']++; }
        else { $n['read']++; }
    }
    $parts = [];
    foreach (['drafted' => 'drafted', 'covered' => 'covered', 'already' => 'already drafted today', 'read' => 'to order'] as $k => $w) { if ($n[$k] > 0) { $parts[] = $n[$k] . ' ' . $w; } }
    return ucfirst(implode(', ', $parts)) . '.';
}

/** The Manager is told once of a PO the Purchasing agent drafted: "the Purchasing agent drafted PO-00044 for Sysco: 6 lines, $412" (kind agent_drafted; the value only where the reader sees cost). */
function tell_agent_drafted(PDO $pdo, int $scopeId, int $poId, array $row): void
{
    $manager = one_value($pdo, 'SELECT COALESCE(ss.manager_member_id, (SELECT manager_member_id FROM ri_settings WHERE id = 1)) FROM sites s LEFT JOIN site_settings ss ON ss.scope_id = s.scope_id WHERE s.scope_id = :s', ['s' => $scopeId]);
    if ($manager === null) { return; }
    $sees = member_sees_cost($pdo, (int) $manager, $scopeId);
    $total = $row['total'] === null ? '' : ', $' . number_format((float) $row['total'], 2);
    $pdo->prepare("SELECT ri_notify(:m, 'agent_drafted', 'purchase_order', :po, :t, :b, :d, false, :s)")->execute([
        'm' => (int) $manager, 'po' => $poId, 't' => mb_substr('The Purchasing agent drafted ' . $row['number'] . ' for ' . $row['supplier'], 0, 200),
        'b' => $row['number'] . ': ' . $row['lines'] . ' line' . ($row['lines'] === 1 ? '' : 's') . ($sees ? $total : '') . '. It is a draft — open it to review and send.', 'd' => 'agent_drafted:po:' . $poId, 's' => $scopeId]);
}

/** Does a member see cost at a site? (db/004's rule for ANOTHER member than the caller: reports.read, or stock.count with the site's kitchen_sees_cost on.) */
function member_sees_cost(PDO $pdo, int $memberId, int $scopeId): bool
{
    return db_bool($pdo, "SELECT ri_member_has_right(:m, 'reports.read', :s) OR (ri_member_has_right(:m2, 'stock.count', :s2) AND COALESCE((SELECT kitchen_sees_cost FROM site_settings WHERE scope_id = :s3), false))",
        ['m' => $memberId, 's' => $scopeId, 'm2' => $memberId, 's2' => $scopeId, 's3' => $scopeId]);
}
