<?php /** The version strip: every version a chip, the chosen one active. Data: versions, chosen (id|null), recipeId */ ?>
<div class="d-flex flex-wrap gap-2 align-items-center" id="version-strip">
    <?php foreach ($versions as $v): $id = (int) $v['recipe_version_id']; $active = $chosen === $id;
        $color = $v['status'] === 'draft' ? 'warning' : ($v['status'] === 'active' && $v['effective_to'] === null ? 'success' : 'dark');
        $text = 'v' . (int) $v['version_no'] . ' · ' . ($v['status'] === 'draft' ? 'draft' : (format_date($v['effective_from']) . ' – ' . ($v['effective_to'] === null ? ($v['status'] === 'active' ? '(in force)' : 'retired') : format_date($v['effective_to'])))); ?>
        <?= hx_link('/recipes/' . $recipeId . '?version=' . $id, e($text), 'badge bg-soft-' . $color . ' text-' . $color . ' py-2 px-3' . ($active ? ' border border-' . $color : ''), 'id="version-chip-' . $id . '"') ?>
    <?php endforeach; ?>
    <?php if ($versions === []): ?><span class="badge bg-soft-dark text-dark py-2 px-3" id="version-chip-none">no version</span><?php endif; ?>
</div>
