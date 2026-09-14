<?php

require_once __DIR__ . "/../api/menuParser.php";

function failTest($message){
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assertSameValue($expected, $actual, $message){
    if($expected !== $actual){
        failTest($message . " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
    }
}

$start = menuWindowStartTime(null, "2026-03-06");
$window = menuWindowDays($start, 7);
assertSameValue(
    array("20260306", "20260307", "20260308", "20260309", "20260310", "20260311", "20260312"),
    array_map(function($day){return $day->format("Ymd");}, $window),
    "the default window should include the requested day and the next six calendar days"
);

$responses = array(
    array("menu" => array(
        array("date" => "2026-03-06", "marker" => "first"),
        array("date" => "2026-03-08T00:00:00", "marker" => "third"),
        array("date" => "2026-03-13", "marker" => "outside")
    )),
    array("menu" => array(
        array("date" => "20260307", "marker" => "second"),
        array("date" => "20260308", "marker" => "duplicate"),
        array("date" => "20260312", "marker" => "seventh")
    ))
);
$menu = collectMenuWindow($responses, $window);
assertSameValue(
    array("first", "second", "third", "seventh"),
    array_map(function($day){return $day["marker"];}, $menu),
    "the response should keep only the seven-day window, sort it, and retain the first result for duplicate dates"
);

$budget = menuWindowFetchBudgetSeconds();
assertSameValue(true, canStartMenuWindowFetch(0, -10), "the first fetch should start even if the budget is already exhausted");
assertSameValue(true, canStartMenuWindowFetch(1, 1), "later fetches should start when at least one second remains in the overall budget");
assertSameValue(false, canStartMenuWindowFetch(1, 0.9), "later fetches should not start when the remaining budget cannot cover a timed-out request");
assertSameValue($budget, remainingMenuWindowBudget(0, 0, $budget), "the remaining budget should start equal to the overall window");
assertSameValue(1, remainingMenuWindowBudget(0, $budget - 1, $budget), "the remaining budget should shrink with elapsed time");
assertSameValue(1, menuWindowFetchTimeoutSeconds(0.2), "a remaining slice under one second should still time out in one second");
assertSameValue(12, menuWindowFetchTimeoutSeconds(11.1), "a remaining slice should become a whole-second fetch timeout");
assertSameValue($budget, menuWindowFetchTimeoutSeconds($budget + 10), "a fetch timeout should never exceed the overall budget");

fwrite(STDOUT, "PASS: menu window checks\n");
