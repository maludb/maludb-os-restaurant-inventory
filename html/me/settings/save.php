<?php
declare(strict_types=1);
/** Action `preferences_save` (log `member.preferences_save`: the fields changed — never the kinds' sentences). Own; people only. A `timezone` sent is refused in words (it is the directory's). Location /me/settings; refresh prefsChanged. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/me/queries.php';
ri_handler_begin();
require_human();
$pdo = db();
$me = (int) current_member_id();
$site = (int) current_site_id();
if (req_has('timezone')) {
    ri_refuse_fields(['timezone' => 'Your time zone comes from the operating system; change it there.']);
}
$errors = [];
$fields = [];
if (req_has('units')) { $fields['display_units'] = ri_choice('units', 'metric', ['metric', 'imperial'], 'The units', $errors, false); }
if (req_has('default_area')) { $v = (string) req_val('default_area'); $fields['default_area_id'] = $v === '' ? null : (ctype_digit($v) ? (int) $v : null); if ($v !== '' && !ctype_digit($v)) { $errors['default_area'] = 'Choose a storage area from the list.'; } }
if (req_has('email_enabled')) { $fields['email_enabled'] = ri_yes('email_enabled'); }
if (req_has('text_enabled')) { $fields['text_enabled'] = ri_yes('text_enabled'); }
foreach (['kinds', 'text_kinds'] as $k) {
    $list = request_list($k);
    if ($list !== null) {
        $bad = array_diff($list, array_keys(NOTICE_KINDS));
        if ($bad !== []) { $errors[$k] = 'Unknown kind: ' . implode(', ', $bad) . '.'; }
        $fields[$k] = array_values(array_intersect($list, array_keys(NOTICE_KINDS)));
    }
}
if ($errors !== []) { ri_refuse_fields($errors); }
$r = ri_guard($pdo, static function () use ($pdo, $me, $site, $fields): array {
    $r = save_prefs($pdo, $me, $site, $fields);
    log_activity($pdo, 'member.preferences_save', 'member', $me, ['before' => $r['before'], 'after' => $r['after']]);
    return $r;
});
ri_done('Saved your settings', $me, ri_land('/me/settings', 'prefs_saved'), 'prefsChanged');
