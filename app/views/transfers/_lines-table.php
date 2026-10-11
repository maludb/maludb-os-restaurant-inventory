<?php /** A transfer's lines. Data: transfer, lines, mayEdit, mayReceive */
$t = $transfer; $pdo = db(); $tid = (int) $t['transfer_id'];
?>
<div class="card mb-3" id="transfer-lines"><div class="card-header"><h5 class="card-title mb-0">Lines <span class="text-muted fs-12">(<?= count($lines) ?>)</span></h5></div>
<?php if ($lines === []): ?><div class="card-body text-muted" id="transfer-lines-empty">No lines yet.</div><?php else: ?>
<?php if ($t['status'] === 'in_transit' && $mayReceive): ?><form method="post" action="/transfers/receive.php" hx-post="/transfers/receive.php" hx-target="#flash" id="transfer-receive-form" hx-confirm="Receive <?= e($t['number']) ?> into <?= e($t['to_area']) ?>?"><?= csrf_field() ?><input type="hidden" name="transfer" value="<?= $tid ?>"><?php endif; ?>
<div class="table-responsive"><table class="table align-middle mb-0" id="transfer-lines-table"><thead><tr><th>#</th><th>Ingredient</th><th>Quantity</th><th>Received</th><th></th></tr></thead><tbody>
<?php foreach ($lines as $l): $lid = $l['line_id']; ?>
    <tr id="transfer-line-row-<?= $lid ?>">
        <td><?= (int) $l['line_no'] ?></td><td><?= hx_link('/ingredients/' . $l['ingredient_id'], e($l['ingredient'])) ?></td>
        <td><span class="fw-semibold"><?= e(fmt_num($l['qty'], 4) . ' ' . $l['unit_code']) ?></span> <span class="fs-12 text-muted">(<?= e(fmt_num($l['qty_base'], 3) . ' ' . $l['base_unit_code']) ?>)</span></td>
        <td><?php if ($t['status'] === 'in_transit' && $mayReceive): ?><div class="input-group" style="max-width:16rem"><input type="number" step="any" min="0" class="form-control btn-touch" name="received[<?= $lid ?>]" id="transfer-receive-qty-<?= $lid ?>" value="<?= e(fmt_input($l['qty'])) ?>"><input type="hidden" name="received_unit[<?= $lid ?>]" value="<?= e($l['unit_code']) ?>"><span class="input-group-text"><?= e($l['unit_code']) ?></span></div>
            <?php elseif ($t['status'] === 'received'): ?><?= e(format_qty($pdo, $l['ingredient_id'], $l['qty_received_base'], $l['unit_code'])) ?><?= (float) $l['qty_received_base'] < (float) $l['qty_base'] ? ' <span class="badge bg-soft-warning text-warning" id="transfer-line-short-' . $lid . '">' . e(format_qty($pdo, $l['ingredient_id'], (string) ((float) $l['qty_base'] - (float) $l['qty_received_base']), $l['unit_code'])) . ' left in transit on the document</span>' : '' ?><?php endif; ?></td>
        <td class="text-end"><?php if ($t['status'] === 'draft' && $mayEdit): ?><button type="submit" form="transfer-line-remove-<?= $lid ?>" class="btn btn-light btn-sm btn-touch text-danger" id="transfer-line-remove-btn-<?= $lid ?>" aria-label="Remove"><i class="feather-x"></i></button><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($t['status'] === 'in_transit' && $mayReceive): ?><div class="card-footer stock-post-bar"><button type="submit" class="btn btn-success btn-touch w-100" id="transfer-receive-btn"><i class="feather-download me-1"></i>Receive</button></div></form><?php endif; ?>
<?php if ($t['status'] === 'draft' && $mayEdit): foreach ($lines as $l): ?><form method="post" action="/transfers/line-remove.php" hx-post="/transfers/line-remove.php" hx-target="#flash" id="transfer-line-remove-<?= $l['line_id'] ?>" class="d-none"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $l['line_id'] ?>"></form><?php endforeach; endif; ?>
<?php endif; ?></div>
