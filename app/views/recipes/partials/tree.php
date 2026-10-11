<?php /** The nested view. Data: nodes, depth */ $depth = $depth ?? 0; ?>
<ul class="list-unstyled mb-0<?= $depth > 0 ? ' ms-3 border-start ps-2' : '' ?>">
<?php foreach ($nodes as $n): ?>
    <li class="py-1" id="tree-node-<?= (int) $n['recipe_line_id'] ?>">
        <?php if ($n['ingredient_id'] !== null): ?><i class="feather-box text-muted me-1"></i><?= e($n['ingredient_name']) ?> <span class="text-muted"><?= e($n['qty_display']) ?></span>
        <?php else: ?><i class="feather-corner-down-right text-muted me-1"></i><strong><?= e($n['sub_recipe_name']) ?></strong> <span class="text-muted"><?= e($n['qty_display']) ?></span><?= $n['sub_version_id'] === null ? ' <span class="badge bg-soft-warning text-warning">no version in force</span>' : '' ?>
            <?php if ($n['children'] !== []): ?><?= view('recipes/partials/tree.php', ['nodes' => $n['children'], 'depth' => $depth + 1]) ?><?php endif; ?>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
