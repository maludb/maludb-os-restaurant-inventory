<?php /** The activation sentence. Data: r = ['ok', 'text', 'ends_version_no'] */ ?>
<span class="<?= $r['ok'] ? 'text-muted' : 'text-danger' ?>" id="activate-preview-text" data-ok="<?= $r['ok'] ? '1' : '0' ?>"><?= e($r['text']) ?></span>
