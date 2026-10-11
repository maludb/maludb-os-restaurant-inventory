<?php /** The conversion sentence. Data: r = ['ok', 'text', 'qty_base'] */ ?>
<span class="<?= $r['ok'] ? 'text-success' : 'text-danger' ?>" id="editor-conversion-text" data-ok="<?= $r['ok'] ? '1' : '0' ?>"><?= e($r['text']) ?></span>
