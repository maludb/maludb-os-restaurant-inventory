<?php /** The one-line cancellation of an emailed purchase order. Data: po, site, supplier, reason, business */ ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; margin: 0; padding: 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; margin: 0 auto;">
    <tr><td>
        <p style="margin: 0 0 12px 0;"><?= e($business) ?> has cancelled purchase order <strong><?= e($po['number']) ?></strong> (<?= e($site['name']) ?>).</p>
        <?php if (trim((string) ($reason ?? '')) !== ''): ?><p style="margin: 0 0 12px 0;">Reason: <?= e((string) $reason) ?></p><?php endif; ?>
        <p style="margin: 0; color: #555;">Please do not fill it. The link you were sent no longer takes an answer.</p>
    </td></tr>
</table>
</body></html>
