<?php
/** CLI helper for the proofs: `php asof.php <scope> "<site-local time>"` prints default_count_as_of() at that instant (the app's own function, the scratch database). */
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/features/counts/queries.php';
$scope = (int) $argv[1];
echo default_count_as_of($scope, new DateTimeImmutable($argv[2], new DateTimeZone((string) one_value(db(), 'SELECT timezone FROM sites WHERE scope_id = :s', ['s' => $scope])))), "\n";
