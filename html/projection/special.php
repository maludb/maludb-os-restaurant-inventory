<?php
declare(strict_types=1);
/** /projection/special — can we run the special? (screen `special-check`; params recipe, date, portions): a recipe, a date and the portions against the projected stock that day — what it needs, what is short and the supplier that can bring it in time. reports.read. */
require_once dirname(__DIR__, 2) . '/app/features/projection/handler.php';
projection_screen_gate();
$pdo = db();
$site = (int) current_site_id();
$today = proj_today($pdo);
$recipe = request_integer('recipe');
$date = proj_query_date('date', '');
$portionsRaw = str_replace([',', ' '], '', request_string('portions'));
$errors = [];
$result = null;
if ($recipe !== null || $date !== '' || $portionsRaw !== '') {
    if ($recipe === null) { $errors['recipe'] = 'Choose the recipe.'; }
    if ($date === '') { $errors['date'] = 'Choose the day.'; }
    elseif ($date < $today || $date > (new DateTimeImmutable($today))->modify('+28 days')->format('Y-m-d')) { $errors['date'] = 'Choose a day from today to ' . date('M j', (int) strtotime($today . ' +28 days')) . '.'; }
    if ($portionsRaw === '' || !is_numeric($portionsRaw) || (float) $portionsRaw < 1 || (float) $portionsRaw > 10000) { $errors['portions'] = 'Portions are 1 to 10,000.'; }
    if ($errors !== [] && wants_json()) { respond_invalid(array_values($errors), $errors); }
    if ($errors === []) { $result = special_check($pdo, $site, (int) $recipe, $date, (float) $portionsRaw); }
}
log_screen_view($pdo, 'special-check', $result === null ? [] : ['after' => ['recipe_id' => $recipe, 'date' => $date, 'portions' => (float) $portionsRaw]]);
if (wants_json()) { respond_screen($result === null ? ['verdict' => null, 'needs' => []] : present_special($result)); }
render_screen('Can we run it?', view('projection/special.php', ['result' => $result, 'recipeId' => $recipe, 'date' => $date, 'portions' => $portionsRaw, 'errors' => $errors, 'mayDraft' => has_right('purchasing.draft'), 'today' => $today]),
    ['activeNav' => 'special-check', 'screen' => 'special-check', 'entity' => 'recipe', 'recordId' => $recipe === null ? '' : (string) $recipe]);
