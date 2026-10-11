<?php /** The parents a recipe is a sub-recipe of. Data: rows */ ?>
<?php if ($rows === []): ?><div class="p-3 text-muted fs-12">Not used as a sub-recipe.</div><?php else: ?>
<ul class="list-group list-group-flush fs-12"><?php foreach ($rows as $u): ?><li class="list-group-item" id="used-in-<?= (int) $u['recipe_id'] ?>-<?= (int) $u['version_no'] ?>"><?= hx_link('/recipes/' . (int) $u['recipe_id'], e($u['name'])) ?> <span class="text-muted">v<?= (int) $u['version_no'] ?> <?= e($u['status']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
