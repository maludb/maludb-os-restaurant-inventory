<?php /** The purchase order as an email to the supplier (purchasing.md): the lines in their units with their SKU and our reference, the date, our notes and the door's link. Never a cost. Data: po, lines, site, supplier, link, message, business */ ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; margin: 0; padding: 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; margin: 0 auto;">
    <tr><td>
        <h2 style="margin: 0 0 4px 0;">Purchase order <?= e($po['number']) ?></h2>
        <p style="margin: 0 0 12px 0; color: #555;">From <?= e($business) ?> to <?= e($supplier['name']) ?></p>
        <p style="margin: 0 0 12px 0;"><strong>Deliver to:</strong> <?= e($site['name']) ?><?= ($site['address'] ?? '') !== '' ? '<br>' . nl2br(e($site['address'])) : '' ?>
        <?php if (!empty($po['expected_on'])): ?><br><strong>Delivery wanted:</strong> <?= e(format_date($po['expected_on'])) ?><?php endif; ?></p>
        <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse: collapse; border: 1px solid #ddd;">
            <tr style="background: #f4f4f4; text-align: left;"><th style="border-bottom: 1px solid #ddd;">Item</th><th style="border-bottom: 1px solid #ddd;">SKU</th><th style="border-bottom: 1px solid #ddd; text-align: right;">Quantity</th></tr>
            <?php foreach ($lines as $l): ?>
            <tr><td style="border-bottom: 1px solid #eee;"><?= e($l['ingredient']) ?></td><td style="border-bottom: 1px solid #eee;"><?= e((string) $l['supplier_sku']) ?></td><td style="border-bottom: 1px solid #eee; text-align: right;"><?= e(fmt_num($l['qty_ordered'], 4) . ' ' . $l['purchase_unit_code']) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <?php if (trim((string) ($po['notes'] ?? '')) !== ''): ?><p style="margin: 12px 0 0 0;"><strong>Notes:</strong><br><?= nl2br(e((string) $po['notes'])) ?></p><?php endif; ?>
        <?php if (trim((string) ($message ?? '')) !== ''): ?><p style="margin: 12px 0 0 0;"><?= nl2br(e((string) $message)) ?></p><?php endif; ?>
        <p style="margin: 20px 0 8px 0;">Acknowledge the order, decline a line or set the delivery date — no account needed:</p>
        <p style="margin: 0 0 16px 0;"><a href="<?= e($link) ?>" style="display: inline-block; background: #3454d1; color: #fff; padding: 12px 20px; text-decoration: none; border-radius: 4px;">Open purchase order <?= e($po['number']) ?></a></p>
        <p style="margin: 0; color: #777; font-size: 12px;"><?= e($link) ?></p>
    </td></tr>
</table>
</body></html>
