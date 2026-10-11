<?php /** The supplier's door with a link that is not valid, has expired or never existed (404). Data: business */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Purchase order · <?= e($business ?? app_name()) ?></title>
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png">
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css">
</head>
<body class="bg-light">
    <main class="container py-5" style="max-width: 560px" id="door-content" data-screen="supplier-door" data-entity="purchase_order">
        <div class="card"><div class="card-body text-center py-5">
            <h1 class="h4 mb-3" id="door-invalid-title">This link is not valid or has expired.</h1>
            <p class="text-muted mb-0" id="door-invalid-text">Ask <?= e($business ?? 'the restaurant') ?> to send you the purchase order again.</p>
        </div></div>
    </main>
</body>
</html>
