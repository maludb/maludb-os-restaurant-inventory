<?php
declare(strict_types=1);

/**
 * Purchasing's JSON presenters (purchasing.md "Files"): whitelists, never raw rows. A cost key is ABSENT when the caller may not see cost at the
 * site (the view already nulls it; the presenter drops the key so an agent's answer never carries a null that reads as "free") — the same
 * present_cost() the ledger uses. Internal notes ride with cost. Chips are the screens' words.
 */

function po_chip(string $status, string $id = ''): string
{
    return chip(PO_STATUS_WORDS[$status] ?? $status, PO_STATUS_CHIPS[$status] ?? 'secondary', $id);
}

function po_line_chip(string $status, string $id = ''): string
{
    return chip(PO_LINE_WORDS[$status] ?? $status, PO_LINE_CHIPS[$status] ?? 'secondary', $id);
}

function invoice_chip(string $status, string $id = ''): string
{
    return chip($status, INVOICE_STATUS_CHIPS[$status] ?? 'secondary', $id);
}

function match_chip(string $state, string $id = ''): string
{
    return chip(MATCH_STATE_WORDS[$state] ?? $state, MATCH_STATE_CHIPS[$state] ?? 'secondary', $id);
}

function present_po_line(array $l, bool $sees): array
{
    $out = ['line_id' => $l['purchase_order_line_id'], 'line_no' => $l['line_no'], 'ingredient_id' => $l['ingredient_id'], 'ingredient' => $l['ingredient'], 'supplier_item_id' => $l['supplier_item_id'],
        'supplier_sku' => $l['supplier_sku'], 'qty' => $l['qty_ordered'], 'unit' => $l['purchase_unit_code'], 'qty_base' => $l['qty_ordered_base'], 'base_unit' => $l['base_unit_code'],
        'qty_received' => $l['qty_received'] ?? null, 'qty_received_base' => $l['qty_received_base'], 'expected_on' => $l['expected_on'], 'status' => $l['status'], 'supplier_note' => $l['supplier_note'],
        'unit_cost' => $l['unit_cost'], 'line_total' => $l['line_total'] ?? null];
    if (!empty($l['has_math'])) {
        $out['arithmetic'] = ['need_base' => $l['need_base'], 'have_base' => $l['have_base'], 'on_order_base' => $l['on_order_base'], 'cap_base' => $l['cap_base'], 'safety_base' => $l['safety_base'],
            'raw_base' => $l['raw_base'], 'rounding_note' => $l['rounding_note']];
    }
    return present_cost($out, ['unit_cost', 'line_total'], $sees);
}

function present_event(array $e): array
{
    return ['event_id' => $e['event_id'], 'kind' => $e['kind'], 'source' => $e['source'], 'line_id' => $e['purchase_order_line_id'], 'line_no' => $e['line_no'] ?? null, 'expected_on' => $e['expected_on'],
        'supplier_order_ref' => $e['supplier_order_ref'], 'reason' => $e['reason'], 'note' => $e['note'], 'by' => $e['member_name'] ?? null, 'at' => json_ts($e['created_at'])];
}

/** A PO header as an agent reads it: the totals and the internal notes only with cost; the link's state (never the token) for one who may send. */
function present_po(array $p, array $lines, bool $sees, bool $mayLink = false, ?array $link = null): array
{
    $out = ['purchase_order_id' => $p['purchase_order_id'], 'number' => $p['number'], 'status' => $p['status'], 'origin' => $p['origin'], 'supplier_id' => $p['supplier_id'], 'supplier' => $p['supplier'] ?? null,
        'storage_area_id' => $p['storage_area_id'], 'area' => $p['area'] ?? null, 'ordered_on' => $p['ordered_on'], 'expected_on' => $p['expected_on'], 'cutoff_at' => json_ts($p['cutoff_at']),
        'supplier_order_ref' => $p['supplier_order_ref'], 'sent_via' => $p['sent_via'], 'sent_at' => json_ts($p['sent_at']), 'acknowledged_at' => json_ts($p['acknowledged_at']), 'notes' => $p['notes'],
        'closed_at' => json_ts($p['closed_at']), 'cancelled_at' => json_ts($p['cancelled_at']), 'cancel_reason' => $p['cancel_reason'],
        'subtotal' => $p['subtotal'], 'total' => $p['total'], 'internal_notes' => $p['internal_notes'],
        'lines' => array_map(static fn (array $l): array => present_po_line($l, $sees), $lines)];
    if ($mayLink) {
        $out['link'] = $link === null ? null : ['created_at' => json_ts($link['created_at']), 'expires_at' => json_ts($link['expires_at']), 'last_used_at' => json_ts($link['last_used_at']), 'views' => (int) $link['view_count']];
    }
    return present_cost($out, ['subtotal', 'total', 'internal_notes'], $sees);
}

function present_po_row(array $p, bool $sees): array
{
    return present_cost(['purchase_order_id' => $p['purchase_order_id'], 'number' => $p['number'], 'status' => $p['status'], 'origin' => $p['origin'], 'supplier_id' => $p['supplier_id'], 'supplier' => $p['supplier'],
        'ordered_on' => $p['ordered_on'], 'expected_on' => $p['expected_on'], 'acknowledged_at' => json_ts($p['acknowledged_at']), 'lines' => (int) $p['line_count'], 'last_event' => $p['last_event_kind'],
        'total' => $p['total']], ['total'], $sees);
}

function present_invoice_line(array $l): array
{
    return ['line_id' => $l['invoice_line_id'], 'line_no' => $l['line_no'], 'supplier_item_id' => $l['supplier_item_id'], 'ingredient_id' => $l['ingredient_id'], 'ingredient' => $l['ingredient'] ?? null,
        'description' => $l['description'], 'qty' => $l['qty'], 'unit' => $l['unit_code'], 'unit_price' => $l['unit_price'], 'line_total' => $l['line_total'], 'receipt_line_id' => $l['receipt_line_id'],
        'received_qty' => $l['received_qty'] ?? null, 'received_unit_cost' => $l['received_cost'] ?? null, 'match_state' => $l['match_state'], 'diff_qty_base' => $l['diff_qty'], 'diff_qty' => $l['diff_qty_unit'] ?? null,
        'diff_value' => $l['diff_value'], 'settle_note' => $l['settle_note']];
}

/** An invoice is whole-screen cost: the caller reaching it sees cost (the views are empty otherwise), so nothing is dropped here. */
function present_invoice(array $i, array $lines): array
{
    return ['invoice_id' => $i['invoice_id'], 'number' => $i['number'], 'invoice_no' => $i['invoice_no'], 'kind' => $i['kind'], 'status' => $i['status'], 'supplier_id' => $i['supplier_id'], 'supplier' => $i['supplier'] ?? null,
        'invoice_date' => $i['invoice_date'], 'received_on' => $i['received_on'], 'purchase_order_id' => $i['purchase_order_id'], 'po_number' => $i['po_number'] ?? null, 'receipt_id' => $i['goods_receipt_id'],
        'receipt_number' => $i['receipt_number'] ?? null, 'subtotal' => $i['subtotal'], 'tax' => $i['tax'], 'total' => $i['total'], 'attachment_id' => $i['attachment_id'], 'notes' => $i['notes'],
        'settled_at' => json_ts($i['settled_at']), 'lines' => array_map('present_invoice_line', $lines)];
}

function present_invoice_row(array $i): array
{
    return ['invoice_id' => $i['invoice_id'], 'number' => $i['number'], 'invoice_no' => $i['invoice_no'], 'kind' => $i['kind'], 'status' => $i['status'], 'supplier_id' => $i['supplier_id'], 'supplier' => $i['supplier'],
        'invoice_date' => $i['invoice_date'], 'received_on' => $i['received_on'], 'total' => $i['total'], 'differences' => (int) $i['differences']];
}

function present_alert(array $a): array
{
    return ['alert_id' => $a['price_alert_id'], 'raised_at' => json_ts($a['raised_at']), 'ingredient_id' => $a['ingredient_id'], 'ingredient' => $a['ingredient'], 'supplier' => $a['supplier'],
        'unit' => $a['unit'], 'old_per_unit' => $a['old_per_unit'], 'new_per_unit' => $a['new_per_unit'], 'old_per_recipe_unit' => $a['old_per_recipe'], 'new_per_recipe_unit' => $a['new_per_recipe'],
        'recipe_unit' => $a['recipe_unit_code'], 'old_per_base' => $a['old_per_base'], 'new_per_base' => $a['new_per_base'], 'pct' => $a['pct'], 'document' => $a['document'], 'document_kind' => $a['reference_kind'],
        'document_id' => $a['reference_id'], 'seen' => $a['seen_at'] !== null, 'seen_by' => $a['seen_by_name'], 'seen_at' => json_ts($a['seen_at'])];
}

function present_due(array $d): array
{
    return ['purchase_order_id' => $d['purchase_order_id'], 'number' => $d['number'], 'supplier_id' => $d['supplier_id'], 'supplier' => $d['supplier'], 'status' => $d['status'], 'expected_on' => $d['expected_on'],
        'received' => $d['received'], 'overdue' => $d['overdue']];
}
