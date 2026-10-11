<?php
declare(strict_types=1);

/**
 * The ledger's JSON presenters (ledger.md "Files"): whitelists, never raw rows. A cost key is ABSENT when the caller may not see cost at the
 * site (the view already nulls it; the presenter drops the key so an agent's answer never carries a null that reads as "free").
 */

function present_cost(array $out, array $costKeys, bool $sees): array
{
    if (!$sees) { foreach ($costKeys as $k) { unset($out[$k]); } $out['cost_withheld'] = true; }
    return $out;
}

function present_level(array $r, bool $sees): array
{
    return present_cost(['ingredient_id' => $r['ingredient_id'], 'name' => $r['name'], 'code' => $r['code'], 'category' => $r['category_key'], 'storage_area_id' => $r['storage_area_id'], 'area' => $r['area'],
        'qty_base' => $r['qty_base'], 'base_unit' => $r['base_unit_code'], 'count_unit' => $r['count_unit'], 'qty_in_count_unit' => $r['qty_in_count_unit'] ?? null,
        'days_on_hand' => $r['days_on_hand'], 'below_par' => $r['below_par'], 'par_base' => $r['par_base'], 'value' => $r['value']], ['value'], $sees);
}

function present_movement(array $r, bool $sees): array
{
    return present_cost(['transaction_id' => $r['transaction_id'], 'occurred_at' => json_ts($r['occurred_at']), 'business_date' => $r['business_date'], 'type' => $r['txn_type'],
        'ingredient_id' => $r['ingredient_id'], 'ingredient' => $r['ingredient'], 'storage_area_id' => $r['storage_area_id'], 'area' => $r['area'], 'qty_base' => $r['qty_base'], 'base_unit' => $r['base_unit_code'],
        'unit_cost_per_base' => $r['unit_cost_per_base'], 'value' => $r['value'], 'document' => $r['document'], 'reference_kind' => $r['reference_kind'], 'reference_id' => $r['reference_id'],
        'counterparty' => $r['counterparty'], 'reason' => $r['reason'], 'by' => $r['actor'], 'reverses_id' => $r['reverses_id'], 'reversed' => $r['reversed'], 'late' => $r['late']], ['unit_cost_per_base', 'value'], $sees);
}

function present_txn(array $t, bool $sees): array
{
    return present_cost(['transaction_id' => (int) $t['transaction_id'], 'type' => $t['txn_type'], 'ingredient_id' => (int) $t['ingredient_id'], 'ingredient' => $t['ingredient'] ?? null, 'storage_area_id' => (int) $t['storage_area_id'],
        'qty_base' => $t['qty_base'], 'base_unit' => $t['base_unit_code'] ?? null, 'unit_cost_per_base' => $t['unit_cost_per_base'], 'value' => $t['value'], 'business_date' => $t['business_date'], 'reverses_id' => $t['reverses_id'] ?? null,
        'note' => $t['note']], ['unit_cost_per_base', 'value'], $sees);
}

function present_receipt(array $g, array $lines, bool $sees): array
{
    return ['receipt_id' => $g['goods_receipt_id'], 'number' => $g['number'], 'status' => $g['status'], 'supplier_id' => $g['supplier_id'], 'supplier' => $g['supplier'], 'purchase_order_id' => $g['purchase_order_id'],
        'po_number' => $g['po_number'], 'storage_area_id' => $g['storage_area_id'], 'area' => $g['area'], 'received_on' => $g['received_on'], 'received_at' => json_ts($g['received_at']),
        'delivery_note_ref' => $g['delivery_note_ref'], 'invoice_ref' => $g['invoice_ref'], 'temperature_ok' => $g['temperature_ok'] === null ? null : (bool) $g['temperature_ok'], 'notes' => $g['notes'],
        'posted_at' => json_ts($g['posted_at']), 'lines' => array_map(static fn (array $l): array => present_cost(['line_id' => $l['goods_receipt_line_id'], 'line_no' => $l['line_no'], 'ingredient_id' => $l['ingredient_id'],
            'ingredient' => $l['ingredient'], 'supplier_item_id' => $l['supplier_item_id'], 'purchase_order_line_id' => $l['purchase_order_line_id'], 'qty' => $l['qty_received'], 'unit' => $l['purchase_unit_code'],
            'qty_base' => $l['qty_base'], 'base_unit' => $l['base_unit_code'], 'weight_qty' => $l['weight_qty'], 'weight_unit' => $l['weight_unit_code'], 'unit_cost' => $l['unit_cost'], 'cost_per_base' => $l['cost_per_base'],
            'discrepancy_kind' => $l['discrepancy_kind'], 'discrepancy_note' => $l['discrepancy_note'], 'temperature_c' => $l['temperature_c'], 'temperature_ok' => $l['temperature_ok'] === null ? null : (bool) $l['temperature_ok'],
            'putaway_area_id' => $l['putaway_area_id']], ['unit_cost', 'cost_per_base'], $sees), $lines)];
}

function present_transfer(array $t, array $lines): array
{
    return ['transfer_id' => $t['transfer_id'], 'number' => $t['number'], 'status' => $t['status'], 'from_site' => $t['scope_id'], 'from_site_name' => $t['from_site'], 'to_site' => $t['to_scope_id'], 'to_site_name' => $t['to_site'],
        'from_area_id' => $t['from_area_id'], 'from_area' => $t['from_area'], 'to_area_id' => $t['to_area_id'], 'to_area' => $t['to_area'], 'notes' => $t['notes'], 'shipped_at' => json_ts($t['shipped_at']), 'received_at' => json_ts($t['received_at']),
        'lines' => array_map(static fn (array $l): array => ['line_id' => $l['line_id'], 'ingredient_id' => $l['ingredient_id'], 'ingredient' => $l['ingredient'], 'qty' => $l['qty'], 'unit' => $l['unit_code'],
            'qty_base' => $l['qty_base'], 'qty_received_base' => $l['qty_received_base'], 'base_unit' => $l['base_unit_code']], $lines)];
}

function present_adjustment(array $j, array $lines, bool $sees): array
{
    return ['adjustment_id' => $j['adjustment_id'], 'number' => $j['number'], 'status' => $j['status'], 'storage_area_id' => $j['storage_area_id'], 'area' => $j['area'], 'reason' => $j['reason_code'], 'notes' => $j['notes'],
        'posted_at' => json_ts($j['posted_at']), 'lines' => array_map(static fn (array $l): array => present_cost(['line_id' => $l['line_id'], 'ingredient_id' => $l['ingredient_id'], 'ingredient' => $l['ingredient'],
            'qty_delta' => $l['qty_delta'], 'unit' => $l['unit_code'], 'qty_delta_base' => $l['qty_delta_base'], 'base_unit' => $l['base_unit_code'], 'unit_cost_per_base' => $l['unit_cost_per_base'], 'note' => $l['note']], ['unit_cost_per_base'], $sees), $lines)];
}

function present_batch(array $b, bool $sees): array
{
    return present_cost(['batch_id' => $b['production_batch_id'], 'number' => $b['number'], 'status' => $b['status'], 'recipe_id' => $b['recipe_id'], 'recipe' => $b['recipe'], 'recipe_version_id' => $b['recipe_version_id'],
        'batches' => $b['batches'], 'output_ingredient_id' => $b['output_ingredient_id'], 'output_qty_base' => $b['output_qty_base'], 'output_base_unit' => $b['output_base_unit'] ?? null, 'storage_area_id' => $b['storage_area_id'],
        'consume_area_id' => $b['consume_area_id'], 'made_at' => json_ts($b['made_at']), 'cost_total' => $b['cost_total'], 'notes' => $b['notes']], ['cost_total'], $sees);
}

function present_waste(array $w, bool $sees): array
{
    return present_cost(['waste_id' => $w['waste_log_id'], 'number' => $w['number'], 'status' => $w['status'], 'stage' => $w['stage'], 'reason' => $w['reason_code'], 'ingredient_id' => $w['ingredient_id'], 'ingredient' => $w['ingredient'],
        'recipe_id' => $w['recipe_id'], 'recipe' => $w['recipe'], 'qty' => $w['qty'], 'unit' => $w['unit_code'], 'qty_base' => $w['qty_base'], 'gross_weight_g' => $w['gross_weight_g'], 'tare_g' => $w['tare_g'],
        'station' => $w['station'], 'day_part' => $w['day_part'], 'storage_area_id' => $w['storage_area_id'], 'disposition' => $w['disposition'], 'business_date' => $w['business_date'], 'logged_at' => json_ts($w['logged_at']),
        'cost' => $w['cost'], 'photo_attachment_id' => $w['photo_attachment_id'] ?? null, 'notes' => $w['notes']], ['cost'], $sees);
}

/** A status chip for a document (draft, in_transit, posted, received, reviewed, cancelled, voided). */
function doc_chip(string $status, string $id = ''): string
{
    return chip(str_replace('_', ' ', $status), DOC_STATUS_CHIPS[$status] ?? 'secondary', $id);
}

/** A transaction type chip. */
function txn_chip(string $type): string
{
    return chip(TXN_WORDS[$type] ?? $type, TXN_CHIPS[$type] ?? 'secondary');
}
