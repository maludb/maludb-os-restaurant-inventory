<?php /** A message to a supplier (purchasing.md supplier_message). Data: supplier, subject, body, po, site, business */ ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; margin: 0; padding: 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; margin: 0 auto;">
    <tr><td>
        <?php if (!empty($po)): ?><p style="margin: 0 0 12px 0; color: #555;">About purchase order <?= e($po['number']) ?> (<?= e($site['name']) ?>)</p><?php endif; ?>
        <p style="margin: 0 0 12px 0;"><?= nl2br(e($body)) ?></p>
        <p style="margin: 16px 0 0 0; color: #555;">— <?= e($business) ?></p>
    </td></tr>
</table>
</body></html>
