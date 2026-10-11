<?php /** My trail (screen `my-trail`). Data: rows, page, more, filters, query, tz, resultsHtml, record ([key, id]|null) */ ?>
<?= view('shared/header.php', ['id' => 'my-trail', 'title' => 'My trail', 'crumbs' => [['Home', '/'], ['My trail', null]], 'back' => back_link()]) ?>
<div class="main-content" id="trail-content">
    <div class="card stretch stretch-full" id="trail-card">
        <div class="card-header">
            <h5 class="card-title"><?= $record !== null ? 'The ' . str_replace('_', ' ', $record[0]) . "'s history" : 'What you did' ?></h5>
            <form id="trail-filters" class="d-flex flex-wrap gap-2" method="get" action="/me/trail" hx-get="/me/trail" hx-target="#trail-results" hx-swap="outerHTML" hx-trigger="change" hx-push-url="true">
                <?php if ($record !== null): ?><input type="hidden" name="<?= e($record[0]) ?>" value="<?= e((string) $record[1]) ?>"><?php endif; ?>
                <input type="date" name="from" id="trail-filter-from" class="form-control form-control-sm w-auto btn-touch" value="<?= e((string) ($filters['from'] ?? '')) ?>" aria-label="From">
                <input type="date" name="to" id="trail-filter-to" class="form-control form-control-sm w-auto btn-touch" value="<?= e((string) ($filters['to'] ?? '')) ?>" aria-label="To">
            </form>
        </div>
        <?= $resultsHtml ?>
    </div>
</div>
