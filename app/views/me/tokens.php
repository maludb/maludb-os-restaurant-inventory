<?php /** The caller's tokens and the MCP URLs to connect their AI to (screen `my-tokens`). Data: rows, raw (just minted, once), tz */ $base = rtrim((string) env('APP_URL', ''), '/'); ?>
<?= view('shared/header.php', ['id' => 'my-tokens', 'title' => 'Tokens', 'crumbs' => [['Home', '/'], ['My settings', '/me/settings'], ['Tokens', null]]]) ?>
<div class="main-content" id="tokens-content">
    <?php if ($raw): ?>
    <div class="row"><div class="col-lg-12"><div class="alert alert-warning" id="token-minted">
        <div class="fw-bold mb-1">Your new token "<?= e($raw['label']) ?>" — copy it now; it is shown once.</div>
        <code class="user-select-all text-break" id="token-minted-value"><?= e($raw['raw']) ?></code>
    </div></div></div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-xl-5">
            <div class="card mb-0 h-100" id="tokens-connect"><div class="card-header"><h5 class="card-title mb-0">Connect your AI to the kitchen</h5></div><div class="card-body fs-12">
                <p>Your own AI tools read this application's two memories with a token as a Bearer token. They see exactly what you see at the restaurants you hold — cost only where you may read it. A token reads; nothing automated writes here but a hired agent through the operating system.</p>
                <div class="mb-2"><span class="text-muted">Records:</span> <code id="tokens-url-records" class="text-break"><?= e($base) ?>/mcp/records</code></div>
                <div class="mb-3"><span class="text-muted">Activity:</span> <code id="tokens-url-activity" class="text-break"><?= e($base) ?>/mcp/activity</code></div>
                <hr>
                <form method="post" action="/me/tokens/mint.php" id="token-mint-form" class="row g-2 align-items-end" hx-post="/me/tokens/mint.php" hx-target="#page-content" hx-swap="innerHTML">
                    <?= csrf_field() ?>
                    <div class="col-7"><label class="form-label fs-12 text-muted" for="token-mint-form-field-label">Label</label><input type="text" name="label" id="token-mint-form-field-label" class="form-control btn-touch" required maxlength="80" placeholder="Claude Desktop"></div>
                    <div class="col-3"><label class="form-label fs-12 text-muted" for="token-mint-form-field-expires-in-days">Days</label><input type="number" name="expires_in_days" id="token-mint-form-field-expires-in-days" class="form-control btn-touch" min="1" max="3650" placeholder="never"></div>
                    <div class="col-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="token-mint-form-save-btn" aria-label="Mint"><i class="feather-key"></i></button></div>
                </form>
            </div></div>
        </div>
        <div class="col-xl-7">
            <div class="card mb-0 h-100" id="tokens-card">
                <div class="card-header"><h5 class="card-title mb-0">Your tokens</h5></div>
                <div class="card-body custom-card-action p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="tokens-table">
                    <thead class="thead-light"><tr><th>Label</th><th>Ends in</th><th>Created</th><th class="d-none d-md-table-cell">Last used</th><th class="d-none d-md-table-cell">Expires</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="tokens-empty">No tokens yet.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $t): $id = (int) $t['id']; $state = token_state($t); $live = $state === 'live'; $dot = ['live' => 'success', 'revoked' => 'dark', 'expired' => 'secondary'][$state]; ?>
                        <tr id="token-row-<?= $id ?>" class="<?= $live ? '' : 'text-muted' ?>">
                            <td><span class="wd-10 ht-10 bg-<?= $dot ?> me-2 d-inline-block rounded-circle"></span><?= e($t['label']) ?><?= $live ? '' : ' <span class="badge bg-soft-' . $dot . ' text-' . $dot . '">' . $state . '</span>' ?></td>
                            <td><code>…<?= e($t['last4'] ?? '') ?></code></td><td><?= e(format_ts($t['created_at'], $tz, 'M j, Y')) ?></td>
                            <td class="d-none d-md-table-cell"><?= e(format_ts($t['last_used_at'], $tz) ?: 'never') ?></td>
                            <td class="d-none d-md-table-cell"><?= e(format_ts($t['expires_at'], $tz, 'M j, Y') ?: 'never') ?></td>
                            <td class="text-end"><?php if ($t['revoked_at'] === null): ?><form method="post" action="/me/tokens/revoke.php" hx-post="/me/tokens/revoke.php" hx-confirm="Revoke the token <?= e($t['label']) ?>? Anything using it stops working." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="token" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" title="Revoke" id="token-row-<?= $id ?>-revoke" aria-label="Revoke <?= e($t['label']) ?>"><i class="feather-x"></i></button></form><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div></div>
            </div>
        </div>
    </div>
</div>
