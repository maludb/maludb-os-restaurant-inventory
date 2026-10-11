<?php
declare(strict_types=1);

/**
 * The supplier's door (purchasing.md "The public door"; html/s.php). NO session: the hashed token is the authority (ri_supplier_link_resolve());
 * the page is read through plain SQL as the application with app.member_id unset — the allow-listed columns of the purchase order, its lines
 * (the SKU, our reference, the quantity in the supplier's unit) and the site's name and address; NEVER a cost, a total, an internal note or another
 * supplier's name. Its three POSTs carry a hidden token signed over the PO id and the hour (a session-less CSRF), and each writes ONE supplier
 * event through ri_po_supplier_event() (source `door`, no member), notifies the PO's creator and logs `purchase_order.supplier_*` as `portal`.
 */

/** The signed hidden field of a PO's door forms for an hour (default: now). */
function door_token(int $poId, ?int $hour = null): string
{
    $hour ??= intdiv(time(), 3600);
    return hash_hmac('sha256', 'door:' . $poId . ':' . $hour, action_token_key());
}

/** Is $given this PO's door token for this hour or the last (a form open across the turn of an hour still posts)? */
function door_verify(int $poId, string $given): bool
{
    $now = intdiv(time(), 3600);
    return $given !== '' && (hash_equals(door_token($poId, $now), $given) || hash_equals(door_token($poId, $now - 1), $given));
}

/** The PO as the supplier may see it, or null: the allow-listed columns, never cost. */
function door_po(PDO $pdo, int $poId): ?array
{
    $st = $pdo->prepare('SELECT p.id AS purchase_order_id, p.number, p.status, p.scope_id, p.ordered_on, p.expected_on, p.notes, p.supplier_order_ref, p.acknowledged_at, p.sent_via, p.created_by, p.sent_by,
            s.name AS supplier, st.name AS site, st.address AS site_address, a.name AS area
        FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id JOIN sites st ON st.scope_id = p.scope_id LEFT JOIN storage_areas a ON a.id = p.storage_area_id WHERE p.id = :id');
    $st->execute(['id' => $poId]);
    $r = $st->fetch();
    return $r === false ? null : stock_ints($r, ['purchase_order_id', 'scope_id', 'created_by', 'sent_by']);
}

/** The lines as the supplier reads them: SKU, our reference (the ingredient's name), the quantity in their unit, status, their note. */
function door_lines(PDO $pdo, int $poId): array
{
    $st = $pdo->prepare('SELECT l.id AS line_id, l.line_no, l.supplier_sku, l.purchase_unit_code, l.qty_ordered, l.status, l.supplier_note, l.expected_on, i.name AS reference
        FROM purchase_order_lines l JOIN ingredients i ON i.id = l.ingredient_id WHERE l.purchase_order_id = :p ORDER BY l.line_no');
    $st->execute(['p' => $poId]);
    return array_map(static fn (array $r): array => stock_ints($r, ['line_id', 'line_no']), $st->fetchAll());
}

/** Does the door still take the supplier's answers? Only a PO that is sent, acknowledged or partly received. */
function door_open(array $po): bool
{
    return in_array($po['status'], PO_OPEN_STATES, true);
}

/** The sentence a read-only door says. */
function door_closed_words(array $po): string
{
    return match ($po['status']) {
        'cancelled' => 'This purchase order was cancelled. Please do not fill it.',
        'closed', 'closed_short' => 'This purchase order is closed. Thank you.',
        'received' => 'This purchase order has been received in full. Thank you.',
        default => 'This purchase order is not open for answers.',
    };
}

/** Log a door event: source `portal`, no actor, the PO's site and audit key; the supplier's words ride in `after` (a reference, a reason, a date — short). */
function door_log(PDO $pdo, string $action, array $po, array $after): void
{
    log_activity($pdo, $action, 'purchase_order', (int) $po['purchase_order_id'], ['purchase_order_id' => (int) $po['purchase_order_id'], 'scope_id' => (int) $po['scope_id'],
        'actor_member_id' => null, 'source' => 'portal', 'after' => ['source' => 'door'] + $after]);
}
