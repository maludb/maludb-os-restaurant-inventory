<?php
/**
 * The supplier's purchase-order page (screen `supplier-door`), no shell, no cost: one column at 375 px, the lines as cards, the three forms stacked with full-width buttons of 48 px or more.
 * Data: po (door_po), lines (door_lines), token, doorToken, open, closedWords, errors, notice, business, today
 */
$statusWords = ['sent' => 'waiting for your answer', 'acknowledged' => 'acknowledged', 'partial' => 'partly delivered', 'received' => 'delivered', 'closed' => 'closed', 'closed_short' => 'closed', 'cancelled' => 'cancelled'];
$lineChip = ['open' => ['secondary', 'to fill'], 'acknowledged' => ['primary', 'acknowledged'], 'declined' => ['danger', 'you cannot fill it'], 'partial' => ['warning', 'partly delivered'], 'received' => ['success', 'delivered'], 'closed_short' => ['warning', 'closed short'], 'cancelled' => ['dark', 'cancelled']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title><?= e($po['number']) ?> · <?= e($business) ?></title>
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png">
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css">
    <style>
        .door-btn { min-height: 48px; font-size: 1rem; font-weight: 600; width: 100%; }
        #door-content .form-control { min-height: 48px; }
        #door-content .card { margin-bottom: 1rem; }
        .door-qty { font-size: 1.35rem; font-weight: 700; }
        details > summary { cursor: pointer; min-height: 44px; display: flex; align-items: center; }
    </style>
</head>
<body class="bg-light">
    <main class="container py-3" style="max-width: 640px" id="door-content" data-screen="supplier-door" data-entity="purchase_order" data-scope-id="<?= (int) $po['scope_id'] ?>">
        <div class="mb-3">
            <div class="text-muted fs-12" id="door-business"><?= e($business) ?></div>
            <h1 class="h3 mb-1" id="door-number">Purchase order <?= e($po['number']) ?></h1>
            <div><span class="badge bg-<?= $po['status'] === 'cancelled' ? 'dark' : ($po['status'] === 'sent' ? 'info' : 'primary') ?>" id="door-status"><?= e($statusWords[$po['status']] ?? $po['status']) ?></span>
                <span class="text-muted ms-1">for <?= e($po['supplier']) ?></span></div>
        </div>
        <?php if (!empty($notice)): ?><div class="alert alert-<?= e($notice[0]) ?>" role="status" id="door-notice"><?= e($notice[1]) ?></div><?php endif; ?>
        <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert" id="door-errors"><?php foreach ($errors as $k => $msg): ?><div id="door-error-<?= e($k) ?>"><?= e($msg) ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if (!$open): ?><div class="alert alert-secondary" id="door-closed"><?= e($closedWords) ?></div><?php endif; ?>

        <div class="card" id="door-header"><div class="card-body">
            <div class="mb-2"><div class="text-muted fs-12">Deliver to</div><div class="fw-semibold" id="door-site"><?= e($po['site']) ?></div><?php if (($po['site_address'] ?? '') !== ''): ?><div id="door-address"><?= nl2br(e($po['site_address'])) ?></div><?php endif; ?>
                <?php if (!empty($po['area'])): ?><div class="text-muted fs-12">Into: <?= e($po['area']) ?></div><?php endif; ?></div>
            <div class="mb-2"><div class="text-muted fs-12">Ordered</div><div id="door-ordered"><?= e(format_date($po['ordered_on'])) ?></div></div>
            <div class="mb-2"><div class="text-muted fs-12">Delivery wanted</div><div class="fw-semibold" id="door-expected"><?= $po['expected_on'] ? e(format_date($po['expected_on'])) : 'not set' ?></div></div>
            <?php if (!empty($po['supplier_order_ref'])): ?><div class="mb-2"><div class="text-muted fs-12">Your reference</div><div id="door-ref"><?= e($po['supplier_order_ref']) ?></div></div><?php endif; ?>
            <?php if (trim((string) ($po['notes'] ?? '')) !== ''): ?><div><div class="text-muted fs-12">Notes from us</div><div id="door-notes"><?= nl2br(e((string) $po['notes'])) ?></div></div><?php endif; ?>
        </div></div>

        <h2 class="h5 mb-2">What we are ordering</h2>
        <div id="door-lines">
        <?php foreach ($lines as $l): $chip = $lineChip[$l['status']] ?? ['secondary', $l['status']]; $lid = (int) $l['line_id']; ?>
            <div class="card" id="door-line-<?= $lid ?>"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div><div class="fw-semibold"><?= e($l['reference']) ?></div><div class="text-muted fs-12"><?= $l['supplier_sku'] ? 'Your SKU ' . e($l['supplier_sku']) : 'No SKU' ?></div></div>
                    <span class="badge bg-<?= e($chip[0]) ?>"><?= e($chip[1]) ?></span>
                </div>
                <div class="door-qty mt-1" id="door-line-qty-<?= $lid ?>"><?= e(fmt_num($l['qty_ordered'], 4) . ' ' . $l['purchase_unit_code']) ?></div>
                <?php if (!empty($l['supplier_note'])): ?><div class="fs-12 mt-1" id="door-line-note-<?= $lid ?>">Your note: <?= e($l['supplier_note']) ?></div><?php endif; ?>
                <?php if ($open && in_array($l['status'], ['open', 'acknowledged'], true)): ?>
                <details class="mt-2" id="door-decline-<?= $lid ?>">
                    <summary class="text-danger fw-semibold">We cannot fill this line</summary>
                    <form method="post" action="/s/<?= e($token) ?>/decline" id="door-decline-form-<?= $lid ?>" class="mt-2">
                        <input type="hidden" name="door_token" value="<?= e($doorToken) ?>"><input type="hidden" name="line" value="<?= $lid ?>">
                        <label class="form-label fs-12 text-muted" for="door-decline-reason-<?= $lid ?>">Why (for example: out of stock)</label>
                        <input type="text" name="reason" id="door-decline-reason-<?= $lid ?>" class="form-control mb-2" maxlength="300" required>
                        <button type="submit" class="btn btn-outline-danger door-btn" id="door-decline-submit-<?= $lid ?>">Decline this line</button>
                    </form>
                </details>
                <?php endif; ?>
            </div></div>
        <?php endforeach; ?>
        </div>

        <?php if ($open): ?>
        <div class="card" id="door-ack"><div class="card-header"><h2 class="h5 mb-0">Acknowledge the order</h2></div><div class="card-body">
            <form method="post" action="/s/<?= e($token) ?>/acknowledge" id="door-ack-form">
                <input type="hidden" name="door_token" value="<?= e($doorToken) ?>">
                <div class="mb-3"><label class="form-label fs-12 text-muted" for="door-ack-ref">Your order reference</label><input type="text" name="ref" id="door-ack-ref" class="form-control" maxlength="80"></div>
                <div class="mb-3"><label class="form-label fs-12 text-muted" for="door-ack-date">Delivery date</label><input type="date" name="date" id="door-ack-date" class="form-control" min="<?= e($today) ?>" value="<?= e((string) ($po['expected_on'] ?? '')) ?>"></div>
                <button type="submit" class="btn btn-primary door-btn" id="door-ack-submit">Acknowledge</button>
            </form>
        </div></div>
        <div class="card" id="door-date"><div class="card-header"><h2 class="h5 mb-0">Set the delivery date</h2></div><div class="card-body">
            <form method="post" action="/s/<?= e($token) ?>/date" id="door-date-form">
                <input type="hidden" name="door_token" value="<?= e($doorToken) ?>">
                <div class="mb-3"><label class="form-label fs-12 text-muted" for="door-date-input">The day it will arrive</label><input type="date" name="date" id="door-date-input" class="form-control" min="<?= e($today) ?>" required></div>
                <button type="submit" class="btn btn-outline-primary door-btn" id="door-date-submit">Set the date</button>
            </form>
        </div></div>
        <?php endif; ?>
        <p class="text-muted fs-12 text-center">No account needed. This page is yours alone — do not share the link.</p>
    </main>
</body>
</html>
