<?php /** A purchase order's events, newest first. Data: events, tz */ ?>
<div class="card mb-3" id="po-events"><div class="card-header"><h5 class="card-title mb-0">Events</h5></div>
<?php if ($events === []): ?><div class="card-body text-muted fs-12" id="po-events-empty">Nothing has happened to this order yet.</div><?php else: ?>
<div class="stock-table-wrap"><table class="table mb-0 fs-12" id="po-events-table"><thead><tr><th>When</th><th>What</th><th>From</th><th>Details</th></tr></thead><tbody>
<?php foreach ($events as $e): ?>
    <tr id="po-event-row-<?= (int) $e['event_id'] ?>">
        <td class="text-nowrap text-muted"><?= e(format_ts($e['created_at'], $tz, 'M j g:i A')) ?></td>
        <td><?= e(PO_EVENT_WORDS[$e['kind']] ?? $e['kind']) ?><?= $e['line_no'] !== null ? ' <span class="text-muted">line ' . (int) $e['line_no'] . ' ' . e((string) $e['ingredient']) . '</span>' : '' ?></td>
        <td><?= chip($e['source'], PO_EVENT_SOURCES[$e['source']] ?? 'secondary') ?> <?= e((string) ($e['member_name'] ?? '')) ?></td>
        <td><?= e(trim(($e['supplier_order_ref'] ? 'their reference ' . $e['supplier_order_ref'] . ' ' : '') . ($e['expected_on'] ? '· delivery ' . format_date($e['expected_on']) . ' ' : '') . ($e['reason'] ? '· ' . $e['reason'] . ' ' : '') . ($e['note'] ?? ''))) ?></td>
    </tr>
<?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
