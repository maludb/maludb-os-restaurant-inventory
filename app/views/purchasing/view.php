<?php
/** One purchase order (screen `purchase-order-view`). Data: po, lines, tab, events, receipts, invoices, link, once (the new link's URL, shown once), window, seesCost, may (draft, send, receive, invoice), trail, notice */
$poId = (int) $po['purchase_order_id'];
$tz = site_timezone();
$status = $po['status'];
$draft = $status === 'draft';
$open = in_array($status, PO_OPEN_STATES, true);
$tabs = ['events' => 'Events', 'receipts' => 'Receipts'] + ($seesCost ? ['invoices' => 'Invoices'] : []) + ($may['send'] ? ['link' => 'Link'] : []);
$here = '/purchasing/' . $poId;
$action = $draft && $may['draft'] ? hx_link($here . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="po-edit-btn"') : '';
?>
<?= view('shared/header.php', ['id' => 'purchase-order-view', 'title' => $po['number'] . ' · ' . $po['supplier'], 'crumbs' => [['Home', '/'], ['Purchase orders', '/purchasing/'], [$po['number'], null]], 'back' => back_link() ?? ['/purchasing/', 'Purchase orders'], 'action' => $action]) ?>
<div class="main-content" id="purchase-order-view-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($once !== null): ?>
    <div class="card mb-3 border-warning" id="po-link-once"><div class="card-body">
        <div class="fw-semibold mb-1">The supplier's link — shown once</div>
        <div class="fs-12 text-muted mb-2">Copy it now if the email did not go or you are sending it by hand. It cannot be shown again; a new link can be made on the Link tab.</div>
        <input type="text" readonly class="form-control btn-touch" id="po-link-once-url" value="<?= e($once) ?>" onclick="this.select()">
    </div></div>
    <?php endif; ?>
    <div class="card mb-3" id="po-header"><div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center fs-12">
            <span id="po-status"><?= po_chip($status, 'po-status-chip') ?></span>
            <span><?= chip(PO_ORIGINS[$po['origin']] ?? $po['origin'], PO_ORIGIN_CHIPS[$po['origin']] ?? 'secondary', 'po-origin-chip') ?></span>
            <span><i class="feather-truck me-1"></i><?= hx_link('/suppliers/' . $po['supplier_id'], e($po['supplier']), '', 'id="po-supplier"') ?> <span class="text-muted">· <?= e(ORDER_METHODS[$po['order_method']] ?? $po['order_method']) ?></span></span>
            <?php if ($po['area']): ?><span><i class="feather-map-pin me-1"></i><?= e($po['area']) ?></span><?php endif; ?>
            <span id="po-ordered"><i class="feather-calendar me-1"></i>Ordered <?= e(format_date($po['ordered_on'])) ?></span>
            <span id="po-expected"><i class="feather-clock me-1"></i>Expected <?= $po['expected_on'] ? e(format_date($po['expected_on'])) : '—' ?></span>
            <?php if ($po['supplier_order_ref']): ?><span id="po-ref">Their reference <?= e($po['supplier_order_ref']) ?></span><?php endif; ?>
            <?php if ($draft && $window['has_schedule']): ?><span class="text-muted" id="po-cutoff">Next cut-off <?= e(window_words($window)) ?></span><?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-3 mt-2 fs-12 text-muted">
            <span>Drafted by <?= e((string) ($po['created_by_name'] ?? '—')) ?></span>
            <?php if ($po['sent_at']): ?><span id="po-sent">Sent <?= e(SENT_VIA_WORDS[$po['sent_via']] ?? (string) $po['sent_via']) ?> by <?= e((string) ($po['sent_by_name'] ?? '—')) ?> · <?= e(format_ts($po['sent_at'], $tz, 'M j g:i A')) ?></span><?php endif; ?>
            <?php if ($po['acknowledged_at']): ?><span id="po-acknowledged">Acknowledged <?= e(format_ts($po['acknowledged_at'], $tz, 'M j g:i A')) ?></span><?php endif; ?>
            <?php if ($po['cancelled_at']): ?><span id="po-cancelled">Cancelled <?= e(format_ts($po['cancelled_at'], $tz, 'M j g:i A')) ?><?= $po['cancel_reason'] ? ' · ' . e($po['cancel_reason']) : '' ?></span><?php endif; ?>
        </div>
        <?php if ($seesCost): ?><div class="mt-2 fs-12" id="po-totals">Subtotal <?= money($po['subtotal']) ?> · Total <span class="fw-semibold"><?= money($po['total']) ?></span></div><?php endif; ?>
    </div>
    <?php if (trim((string) ($po['notes'] ?? '')) !== ''): ?><div class="card-footer fs-12" id="po-notes"><span class="text-muted">To the supplier:</span> <?= nl2br(e((string) $po['notes'])) ?></div><?php endif; ?>
    <?php if ($seesCost && trim((string) ($po['internal_notes'] ?? '')) !== ''): ?><div class="card-footer fs-12" id="po-internal-notes"><span class="text-muted">Internal:</span> <?= nl2br(e((string) $po['internal_notes'])) ?></div><?php endif; ?>
    </div>

    <?= view('purchasing/_lines-table.php', ['po' => $po, 'lines' => $lines, 'seesCost' => $seesCost, 'editable' => false, 'mayDecline' => $may['draft']]) ?>

    <?php if ($draft && ($may['send'] || $may['draft'])): ?>
    <div class="row g-3 mb-3" id="po-actions-draft">
        <?php if ($may['send']): ?>
        <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="card-title mb-0">Send it</h5></div><div class="card-body">
            <form method="post" action="/purchasing/send.php" hx-post="/purchasing/send.php" hx-target="#flash" id="po-send-form" hx-confirm="Send <?= e($po['number']) ?> to <?= e($po['supplier']) ?> by email? This commits the order.">
                <?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poId ?>">
                <label class="form-label fs-12 text-muted" for="po-send-message">A note on the email (optional)</label>
                <textarea name="message" id="po-send-message" rows="2" maxlength="2000" class="form-control mb-2"></textarea>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="po-send-btn" <?= $lines === [] ? 'disabled' : '' ?>><i class="feather-send me-1"></i>Send by email</button>
            </form></div></div></div>
        <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="card-title mb-0">Or mark it placed</h5></div><div class="card-body">
            <form method="post" action="/purchasing/place.php" hx-post="/purchasing/place.php" hx-target="#flash" id="po-place-form" hx-confirm="Mark <?= e($po['number']) ?> placed with <?= e($po['supplier']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poId ?>">
                <div class="row g-2 mb-2">
                    <div class="col-5"><label class="form-label fs-12 text-muted" for="po-place-via">Placed by</label><select name="via" id="po-place-via" class="form-select btn-touch"><?php foreach (['portal' => 'Portal', 'phone' => 'Phone', 'api' => 'API', 'edi' => 'EDI'] as $k => $w): ?><option value="<?= $k ?>" <?= $po['order_method'] === $k ? 'selected' : '' ?>><?= $w ?></option><?php endforeach; ?></select></div>
                    <div class="col-7"><label class="form-label fs-12 text-muted" for="po-place-ref">Their order reference</label><input type="text" name="supplier_order_ref" id="po-place-ref" class="form-control btn-touch" maxlength="80" required></div>
                </div>
                <button type="submit" class="btn btn-outline-primary btn-touch w-100" id="po-place-btn" <?= $lines === [] ? 'disabled' : '' ?>>Mark placed</button>
            </form></div></div></div>
        <?php endif; ?>
        <?php if ($may['send']): ?>
        <div class="col-12"><form method="post" action="/purchasing/cancel.php" hx-post="/purchasing/cancel.php" hx-target="#flash" hx-confirm="Cancel the draft <?= e($po['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poId ?>"><button type="submit" class="btn btn-outline-danger btn-touch" id="po-cancel-btn">Cancel this draft</button></form></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$draft && $status !== 'cancelled' && !in_array($status, ['closed', 'closed_short'], true)): ?>
    <div class="card mb-3" id="po-actions"><div class="card-header"><h5 class="card-title mb-0">What next</h5></div><div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-2">
            <?php if ($may['receive'] && $open): ?><?= hx_link('/receipts/new?purchase_order=' . $poId, '<i class="feather-download me-1"></i>Receive', 'btn btn-primary btn-touch flex-grow-1 flex-md-grow-0', 'id="po-receive-btn"') ?><?php endif; ?>
            <?php if ($may['invoice'] && $seesCost): ?><?= hx_link('/invoices/new?purchase_order=' . $poId, '<i class="feather-file-text me-1"></i>Enter an invoice', 'btn btn-light btn-touch flex-grow-1 flex-md-grow-0', 'id="po-invoice-btn"') ?><?php endif; ?>
            <?php if ($may['send'] && $status === 'received'): ?>
                <form method="post" action="/purchasing/close.php" hx-post="/purchasing/close.php" hx-target="#flash" class="flex-grow-1 flex-md-grow-0" hx-confirm="Close <?= e($po['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poId ?>"><button type="submit" class="btn btn-success btn-touch w-100" id="po-close-btn">Close</button></form>
            <?php elseif ($may['send'] && $open): ?>
                <form method="post" action="/purchasing/close.php" hx-post="/purchasing/close.php" hx-target="#flash" class="flex-grow-1 flex-md-grow-0" hx-confirm="Close <?= e($po['number']) ?> short? The lines still open will not come."><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poId ?>"><input type="hidden" name="short" value="yes"><button type="submit" class="btn btn-outline-warning btn-touch w-100" id="po-close-short-btn">Close short</button></form>
            <?php endif; ?>
            <?php if ($may['send'] && in_array($status, ['sent', 'acknowledged'], true)): ?>
                <form method="post" action="/purchasing/cancel.php" hx-post="/purchasing/cancel.php" hx-target="#flash" class="flex-grow-1 flex-md-grow-0" hx-confirm="Cancel <?= e($po['number']) ?>? <?= e($po['supplier']) ?> will be told<?= $po['sent_via'] === 'email' ? ' by email' : '' ?>."><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poId ?>"><button type="submit" class="btn btn-outline-danger btn-touch w-100" id="po-cancel-btn">Cancel</button></form>
            <?php endif; ?>
        </div>
        <?php if ($may['draft'] && $open): ?>
        <details class="mb-2" id="po-ack-details"><summary class="fw-semibold" style="min-height: 44px; display: flex; align-items: center">The supplier confirmed by phone or email — record it</summary>
            <form method="post" action="/purchasing/acknowledge.php" hx-post="/purchasing/acknowledge.php" hx-target="#flash" id="po-ack-form" class="mt-2">
                <?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poId ?>">
                <div class="row g-2 mb-2"><div class="col-6"><label class="form-label fs-12 text-muted" for="po-ack-date">Delivery date</label><input type="date" name="expected_on" id="po-ack-date" class="form-control btn-touch" value="<?= e((string) ($po['expected_on'] ?? '')) ?>"></div>
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="po-ack-ref">Their reference</label><input type="text" name="supplier_order_ref" id="po-ack-ref" class="form-control btn-touch" maxlength="80"></div></div>
                <button type="submit" class="btn btn-light btn-touch w-100" id="po-ack-btn">Record the acknowledgement</button></form></details>
        <?php endif; ?>
        <?php if ($may['send']): ?>
        <details id="po-message-details"><summary class="fw-semibold" style="min-height: 44px; display: flex; align-items: center">Message the supplier</summary>
            <form method="post" action="/purchasing/message.php" hx-post="/purchasing/message.php" hx-target="#flash" id="po-message-form" class="mt-2">
                <?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poId ?>"><input type="hidden" name="supplier" value="<?= (int) $po['supplier_id'] ?>">
                <div class="mb-2"><label class="form-label fs-12 text-muted" for="po-message-subject">Subject</label><input type="text" name="subject" id="po-message-subject" class="form-control btn-touch" maxlength="150" value="About purchase order <?= e($po['number']) ?>"></div>
                <div class="mb-2"><label class="form-label fs-12 text-muted" for="po-message-body">Message</label><textarea name="body" id="po-message-body" rows="3" maxlength="4000" class="form-control" required></textarea></div>
                <button type="submit" class="btn btn-light btn-touch w-100" id="po-message-btn" hx-confirm="Send this message to <?= e($po['supplier']) ?>?">Send the message</button></form></details>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-3" id="po-tabs">
        <?php foreach ($tabs as $k => $w): ?><li class="nav-item"><?= hx_link($here . '?tab=' . $k, e($w), 'nav-link' . ($tab === $k ? ' active' : ''), 'id="po-tab-' . $k . '"') ?></li><?php endforeach; ?>
    </ul>
    <?php if ($tab === 'events'): ?><?= view('purchasing/_events.php', ['events' => $events, 'tz' => $tz]) ?>
    <?php elseif ($tab === 'receipts'): ?>
    <div class="card mb-3" id="po-receipts"><div class="card-header"><h5 class="card-title mb-0">Receipts against it</h5></div>
        <?php if ($receipts === []): ?><div class="card-body text-muted fs-12" id="po-receipts-empty">Nothing has been received against this order yet.</div><?php else: ?>
        <div class="stock-table-wrap"><table class="table mb-0 fs-12" id="po-receipts-table"><thead><tr><th>Receipt</th><th>Status</th><th>Received</th><th>Area</th><th class="text-end">Lines</th></tr></thead><tbody>
        <?php foreach ($receipts as $g): ?><tr id="po-receipt-row-<?= (int) $g['goods_receipt_id'] ?>"><td><?= hx_link('/receipts/' . $g['goods_receipt_id'], e($g['number']), 'fw-semibold') ?></td><td><?= doc_chip($g['status']) ?></td><td><?= e(format_date($g['received_on'])) ?></td><td><?= e((string) $g['area']) ?></td><td class="text-end"><?= (int) $g['lines'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
    <?php elseif ($tab === 'invoices'): ?>
    <div class="card mb-3" id="po-invoices"><div class="card-header"><h5 class="card-title mb-0">Invoices that bill it</h5></div>
        <?php if ($invoices === []): ?><div class="card-body text-muted fs-12" id="po-invoices-empty">No invoice names this order yet.</div><?php else: ?>
        <div class="stock-table-wrap"><table class="table mb-0 fs-12" id="po-invoices-table"><thead><tr><th>Invoice</th><th>Theirs</th><th>Date</th><th class="text-end">Total</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($invoices as $i): ?><tr id="po-invoice-row-<?= (int) $i['invoice_id'] ?>"><td><?= hx_link('/invoices/' . $i['invoice_id'], e($i['number']), 'fw-semibold') ?></td><td><?= e($i['invoice_no']) ?><?= $i['kind'] === 'credit' ? ' ' . chip('credit', 'warning') : '' ?></td><td><?= e(format_date($i['invoice_date'])) ?></td><td class="text-end"><?= money($i['total']) ?></td><td><?= invoice_chip($i['status']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
    <?php elseif ($tab === 'link'): ?><?= view('purchasing/_link.php', ['po' => $po, 'link' => $link, 'tz' => $tz]) ?>
    <?php endif; ?>
    <?= view('stock/_trail.php', ['id' => 'po-trail', 'rows' => $trail]) ?>
</div>
