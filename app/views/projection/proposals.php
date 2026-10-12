<?php /** The Purchasing agent's day (screen `proposal-list`). Data: day (proposals()), date, filters, rate, lastSent, today, notice */
$tz = site_timezone();
$action = '<form method="post" action="/projection/proposals/note.php" hx-post="/projection/proposals/note.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="date" value="' . e($date) . '"><button type="submit" class="btn btn-primary btn-touch" id="note-send-btn"><i class="feather-send me-1"></i>Send the morning note</button></form>';
?>
<?= view('shared/header.php', ['id' => 'proposal-list', 'title' => 'Purchasing agent', 'crumbs' => [['Home', '/'], ['Projection', null], ['Purchasing agent', null]], 'back' => back_link(), 'action' => $action]) ?>
<div class="main-content" id="proposal-list-content">
    <?= view('projection/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body py-2 fs-13 d-flex flex-wrap gap-3 align-items-center">
        <div id="note-last"><?= $lastSent === null ? '<span class="text-muted">The morning note for ' . e(format_date($date)) . ' has not been sent.</span>' : 'Morning note sent ' . e(format_ts($lastSent, $tz, 'M j, g:i A')) . '.' ?></div>
        <div id="acceptance-rate" class="ms-md-auto text-muted"><?= $rate['decided'] === 0 ? 'No proposal decided in the last 30 days.' : 'Accepted ' . (int) $rate['accepted'] . ' of ' . (int) $rate['decided'] . ' decided in 30 days (' . e(fmt_num($rate['rate'], 0)) . ' %).' ?></div>
    </div></div>
    <form method="get" action="/projection/proposals" hx-get="/projection/proposals" hx-target="#page-content" hx-push-url="true" hx-trigger="change, submit" class="card mb-3" id="proposal-filters"><div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="proposal-filter-date">Day</label><input type="date" name="date" id="proposal-filter-date" class="form-control btn-touch" value="<?= e($date) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="proposal-filter-kind">Kind</label><select name="kind" id="proposal-filter-kind" class="form-select btn-touch"><option value="">All</option><?php foreach (PROPOSAL_KINDS as $k): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $k)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="proposal-filter-status">Status</label><select name="status" id="proposal-filter-status" class="form-select btn-touch"><option value="">All</option><?php foreach (PROPOSAL_STATUS_CHIPS as $k => $_): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="proposal-filter-apply">Show</button></div>
    </div></form>
    <?php if ($day['all'] === []): ?>
        <div class="card" id="proposal-empty"><div class="card-body text-center text-muted py-5">The Purchasing agent has proposed nothing for <?= e(format_date($date)) ?>.<?= $date === $today ? ' The worker drafts the orders and notes the stock-outs every quarter hour; the agent adds its words at its morning duty.' : '' ?></div></div>
    <?php endif; ?>
    <?php $n = 0; foreach ($day['groups'] as $key => $g): $n++; if ($g['proposals'] === []) { continue; } ?>
        <h6 class="mt-3 mb-2" id="proposal-group-<?= e($key) ?>"><?= $n ?>. <?= e($g['title']) ?> <span class="text-muted fw-normal">(<?= count($g['proposals']) ?>)</span></h6>
        <?php foreach ($g['proposals'] as $p): ?><?= view('projection/partials/proposal-card.php', ['p' => $p]) ?><?php endforeach; ?>
    <?php endforeach; ?>
</div>
