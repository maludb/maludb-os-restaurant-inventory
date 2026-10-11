<?php /** The probe's answer inline. Data: res */ ?>
<div class="alert alert-<?= $res['ok'] ? 'success' : 'danger' ?> mb-0 fs-12" id="source-probe-result" role="status"><?= e($res['message']) ?></div>
