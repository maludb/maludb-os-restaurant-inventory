<?php /** The open POs of a supplier at this site as <option>s. Data: options, selected */ ?>
<option value="">None</option>
<?php foreach ($options as $p): ?><option value="<?= (int) $p['purchase_order_id'] ?>" <?= (string) ($selected ?? '') === (string) $p['purchase_order_id'] ? 'selected' : '' ?>><?= e($p['number'] . ' · ' . $p['status'] . ($p['expected_on'] ? ' · due ' . format_date($p['expected_on']) : '')) ?></option><?php endforeach; ?>
