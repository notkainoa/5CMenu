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

fwrite(STDOUT, "PASS: menu window checks\n");
