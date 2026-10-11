<?php /** A draft: the header, the order guide's rows, the line form, the lines (screen `purchase-order-edit`). Data: po, lines, line, unitOptions, guide, window, areas, today, seesCost, notice */
$poId = (int) $po['purchase_order_id'];
?>
<?= view('shared/header.php', ['id' => 'purchase-order-edit', 'title' => 'Change ' . $po['number'], 'crumbs' => [['Home', '/'], ['Purchase orders', '/purchasing/'], [$po['number'], '/purchasing/' . $poId], ['Edit', null]],
    'back' => back_link() ?? ['/purchasing/' . $poId, $po['number']], 'action' => hx_link('/purchasing/' . $poId, '<i class="feather-check me-1"></i>Done', 'btn btn-light btn-touch', 'id="po-edit-done-btn"')]) ?>
<div class="main-content" id="purchase-order-edit-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('purchasing/_po-form.php', ['cur' => $po, 'supplier' => null, 'window' => $window, 'areas' => $areas, 'today' => $today, 'seesCost' => $seesCost, 'hasLines' => $lines !== []]) ?>
    <?= view('purchasing/_lines-table.php', ['po' => $po, 'lines' => $lines, 'seesCost' => $seesCost, 'editable' => true]) ?>
    <?= view('purchasing/_line-form.php', ['po' => $po, 'line' => $line, 'unitOptions' => $unitOptions, 'seesCost' => $seesCost]) ?>
    <?php if ($line === null): ?><?= view('purchasing/_guide-rows.php', ['po' => $po, 'guide' => $guide, 'seesCost' => $seesCost]) ?><?php endif; ?>
</div>
