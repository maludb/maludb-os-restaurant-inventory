<?php
declare(strict_types=1);
/**
 * CLI probes the kit proof (tests/phase0/run.sh) drives — each prints plain lines the shell compares. Needs the proof's environment
 * (RI_DEV_ENV exported by run.sh). Never run against an installed database.
 *   php probe.php log                 log_activity with the audit keys (scope_id, ingredient_id, …), then with values that would throw (it must not)
 *   php probe.php apikey <raw>        api_authenticate() as a key: who it acts as, whether it is a key, and the rate rule's answer
 *   php probe.php mail                malumail_send() with and without a key
 *   php probe.php site <member> <scope>   require_site() as the member for the site: "held" or the refusal
 */
if (PHP_SAPI !== 'cli') { exit(1); }
$mode = $argv[1] ?? '';
if ($mode === 'apikey') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . ($argv[2] ?? '');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require dirname(__DIR__, 2) . '/app/api/bootstrap.php';
    $member = api_authenticate();
    echo 'authenticated:' . $member['id'] . ':key=' . ((api_key()['token_id'] ?? 0) > 0 ? 'yes' : 'no') . ':label=' . (api_key()['label'] ?? '') . "\n";
    log_activity(db(), 'probe.api', 'ingredient', 3, ['scope_id' => 101, 'after' => ['label' => api_key()['label'] ?? null]]);
    echo "logged\n";
    exit(0);
}
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = db();
if ($mode === 'log') {
    log_activity($pdo, 'probe.event', 'recipe', 77, ['actor_member_id' => null, 'scope_id' => 101, 'ingredient_id' => 5, 'after' => ['count' => 2]]);
    log_activity($pdo, 'probe.ingredient', 'ingredient', '9', ['actor_member_id' => null, 'scope_id' => 102]);
    log_activity($pdo, 'probe.noscope', 'purchase_order', 4, ['actor_member_id' => null]);
    log_activity($pdo, 'probe.bad_keys', 'inventory_count', 'not-a-number', ['actor_member_id' => null, 'ingredient_id' => '-5', 'scope_id' => 'x']);
    echo "logged\n";
    try {
        log_activity($pdo, 'probe.bad_actor', 'recipe', null, ['actor_member_id' => 'not-a-number']);          // a bigint column refuses it
        log_activity($pdo, 'probe.bad_json', 'recipe', null, ['actor_member_id' => null, 'after' => ['x' => NAN]]);   // json_encode throws
        log_activity($pdo, 'probe.bad_arrays', null, null, ['actor_member_id' => null, 'recipe_id' => ['x'], 'token_id' => []]);
        echo "survived\n";
    } catch (Throwable $e) {
        echo 'threw:' . get_class($e) . "\n";
    }
    exit(0);
}
if ($mode === 'mail') {
    try {
        $r = malumail_send(['from' => 'kit@example.invalid', 'to' => 'someone@example.invalid', 'subject' => 'Kit proof', 'text' => 'hello']);
        echo 'sent:' . $r['status'] . "\n";
    } catch (Throwable $e) {
        echo 'mail-threw:' . $e->getMessage() . "\n";
    }
    exit(0);
}
if ($mode === 'site') {
    $_SESSION['member_id'] = (int) ($argv[2] ?? 0);
    db_apply_context($pdo);
    $scope = (int) ($argv[3] ?? 0);
    echo in_array($scope, array_column(held_sites(), 'scope_id'), true) ? "held\n" : "not-held\n";
    echo (has_right('stock.count', $scope) ? 'count' : 'no-count') . ':' . (sees_cost($scope) ? 'cost' : 'no-cost') . "\n";
    exit(0);
}
fwrite(STDERR, "unknown mode\n");
exit(2);
