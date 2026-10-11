<?php /** THE RECEIVING SCREEN (screen `receipt-view`). Data: receipt, lines, line (edited), unitOptions, facts, areas, poLines, seesCost, mayEdit, movements, prices, notes, attachments, mayNote, me, isAdmin, trail, notice, errors? */
$g = $receipt; $rid = (int) $g['goods_receipt_id']; $draft = $g['status'] === 'draft';
$tz = site_timezone();
?>
<?= view('shared/header.php', ['id' => 'receipt-view', 'title' => $g['number'] . ($g['supplier'] ? ' · ' . $g['supplier'] : ''), 'crumbs' => [['Home', '/'], ['Receipts', '/receipts/'], [$g['number'], null]], 'back' => back_link() ?? ['/receipts/', 'Receipts'],
    'action' => $draft && $mayEdit ? hx_link('/receipts/' . $rid . '/edit', '<i class="feather-edit-2 me-1"></i>Header', 'btn btn-light btn-touch', 'id="receipt-view-edit-btn"') : '']) ?>
<div class="main-content" id="receipt-view-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="receipt-header"><div class="card-body d-flex flex-wrap gap-3 align-items-center fs-12">
        <span><?= doc_chip($g['status'], 'receipt-status') ?></span>
        <span><i class="feather-map-pin me-1"></i><?= e($g['area']) ?></span>
        <span><i class="feather-calendar me-1"></i><?= e(format_date($g['received_on'])) ?></span>
        <?php if ($g['po_number']): ?><span id="receipt-po">PO <?= is_file(dirname(__DIR__, 3) . '/html/purchasing/view.php') ? hx_link('/purchasing/' . $g['purchase_order_id'], e($g['po_number'])) : e($g['po_number']) ?></span><?php endif; ?>
        <?php if ($g['delivery_note_ref']): ?><span>Delivery note <?= e($g['delivery_note_ref']) ?></span><?php endif; ?>
        <?php if ($g['invoice_ref']): ?><span>Invoice <?= e($g['invoice_ref']) ?></span><?php endif; ?>
        <?php if ($g['temperature_ok'] !== null): ?><span class="<?= $g['temperature_ok'] ? 'text-success' : 'text-danger' ?>" id="receipt-temperature"><i class="feather-thermometer me-1"></i><?= $g['temperature_ok'] ? 'every temperature OK' : 'a temperature NOT OK' ?></span><?php endif; ?>
        <?php if ($g['posted_at']): ?><span class="text-muted">Posted by <?= e($g['posted_by_name'] ?? '') ?> · <?= e(format_ts($g['posted_at'], $tz, 'M j g:i A')) ?></span><?php endif; ?>
    </div><?php if ($g['notes']): ?><div class="card-footer fs-12"><?= nl2br(e($g['notes'])) ?></div><?php endif; ?></div>
    <?php if ($draft && $mayEdit): ?><?= view('receipts/_line-form.php', ['receipt' => $g, 'line' => $line, 'unitOptions' => $unitOptions, 'facts' => $facts, 'areas' => $areas, 'poLines' => $poLines, 'seesCost' => $seesCost, 'errors' => $errors ?? []]) ?><?php endif; ?>
    <?= view('receipts/_lines-table.php', ['receipt' => $g, 'lines' => $lines, 'seesCost' => $seesCost, 'mayEdit' => $mayEdit]) ?>
    <?php if ($draft && $mayEdit): ?>
        <div class="stock-post-bar d-flex gap-2" id="receipt-actions">
            <form method="post" action="/receipts/post.php" hx-post="/receipts/post.php" hx-target="#flash" class="flex-grow-1" hx-confirm="Post <?= e($g['number']) ?>: <?= count($lines) ?> lines into <?= e($g['area']) ?>?"><?= csrf_field() ?><input type="hidden" name="receipt" value="<?= $rid ?>"><button type="submit" class="btn btn-success btn-touch w-100" id="receipt-post-btn" <?= $lines === [] ? 'disabled' : '' ?>><i class="feather-check me-1"></i>Post</button></form>
            <form method="post" action="/receipts/cancel.php" hx-post="/receipts/cancel.php" hx-target="#flash" hx-confirm="Cancel <?= e($g['number']) ?>? Nothing has been posted."><?= csrf_field() ?><input type="hidden" name="receipt" value="<?= $rid ?>"><button type="submit" class="btn btn-outline-danger btn-touch" id="receipt-cancel-btn">Cancel</button></form>
        </div>
    <?php endif; ?>
    <?php if ($g['status'] === 'posted'): ?>
        <?= view('stock/_doc-movements.php', ['id' => 'receipt-movements', 'title' => 'What it posted', 'rows' => $movements, 'seesCost' => $seesCost]) ?>
        <?php if ($seesCost && $prices !== []): ?>
        <div class="card mb-3" id="receipt-prices"><div class="card-header"><h5 class="card-title mb-0">Price history it wrote</h5></div><div class="table-responsive"><table class="table mb-0 fs-12" id="receipt-prices-table"><thead><tr><th>Ingredient</th><th class="text-end">Before</th><th class="text-end">Now</th><th>Given as</th></tr></thead><tbody>
            <?php foreach ($prices as $h): ?><tr id="receipt-price-row-<?= (int) $h['price_history_id'] ?>"><td><?= e($h['ingredient']) ?></td><td class="text-end"><?= $h['old_per_base'] === null ? '—' : '$' . e(fmt_num($h['old_per_base'], 6)) . ' / ' . e($h['base_unit_code']) ?></td><td class="text-end">$<?= e(fmt_num($h['new_per_base'], 6)) ?> / <?= e($h['base_unit_code']) ?></td><td><?= $h['per_unit_price'] === null ? '' : '$' . e(fmt_num($h['per_unit_price'], 4)) . ' / ' . e($h['per_unit_code']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
        <?php endif; ?>
    <?php endif; ?>
    <div class="card mb-3" id="receipt-files"><div class="card-header"><h5 class="card-title mb-0">Delivery note and notes</h5></div><div class="card-body">
        <?= view('catalog/partials/notes-tab.php', ['type' => 'goods_receipt', 'id' => $rid, 'notes' => $notes, 'attachments' => $attachments, 'mayNote' => $mayNote, 'me' => $me, 'isAdmin' => $isAdmin, 'tz' => $tz, 'back' => '/receipts/' . $rid]) ?>
    </div></div>
    <?= view('stock/_trail.php', ['id' => 'receipt-trail', 'rows' => $trail]) ?>
</div>
