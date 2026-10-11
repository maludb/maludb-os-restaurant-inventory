<?php /** A site's active areas as <option>s. Data: areas, selected */ ?>
<option value="">Choose…</option>
<?php foreach ($areas as $a): ?><option value="<?= (int) $a['storage_area_id'] ?>" <?= (string) ($selected ?? '') === (string) $a['storage_area_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
