<?php /** Start a purchase order (screen `purchase-order-add`). Data: supplier (row or null), window, areas, today */
?>
<?= view('shared/header.php', ['id' => 'purchase-order-add', 'title' => 'New purchase order', 'crumbs' => [['Home', '/'], ['Purchase orders', '/purchasing/'], ['New', null]], 'back' => back_link() ?? ['/purchasing/', 'Purchase orders']]) ?>
<div class="main-content" id="purchase-order-add-content">
    <?= view('stock/_assets.php') ?>
    <?= view('purchasing/_po-form.php', ['cur' => null, 'supplier' => $supplier, 'window' => $window, 'areas' => $areas, 'today' => $today, 'seesCost' => sees_cost(), 'hasLines' => false]) ?>
</div>
