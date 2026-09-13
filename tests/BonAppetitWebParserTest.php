<?php

require_once __DIR__ . "/../api/BonAppetitWebParser.php";

function failTest($message){
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assertSameValue($expected, $actual, $message){
    if($expected !== $actual){
        failTest($message . " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
    }
}

$parser = new BonAppetitWebParser("fixture", array(), strtotime("2026-09-05 00:00:00 America/Los_Angeles"));
$reflection = new ReflectionClass($parser);
$extractMeals = $reflection->getMethod("extractMeals");
$mode = null;
$meals = $extractMeals->invokeArgs($parser, array(
    file_get_contents(__DIR__ . "/fixtures/bamco-menu.html"),
    "2026-09-05",
    &$mode
));

assertSameValue("bamco", $mode, "the exact Bamco.menu_items assignment should win over menu_items_nonce");
assertSameValue("Fixture Tofu Bowl", $meals["brunch"]["stations"][0]["menu"][0]["name"] ?? null, "the real menu item should be parsed");
assertSameValue(true, $meals["brunch"]["stations"][0]["menu"][0]["vegan"] ?? null, "dietary metadata should survive normalization");
assertSameValue(420, $meals["brunch"]["stations"][0]["menu"][0]["calories"] ?? null, "calories should survive normalization");
assertSameValue(
    strtotime("2026-09-05 10:30:00 America/Los_Angeles"),
    $meals["brunch"]["startTime"] ?? null,
    "the upstream meal start time should be preserved"
);
assertSameValue(
    strtotime("2026-09-05 13:30:00 America/Los_Angeles"),
    $meals["brunch"]["endTime"] ?? null,
    "the upstream meal end time should be preserved"
);

$mealTimes = $reflection->getMethod("mealTimes");
$dinnerTimes = $mealTimes->invoke($parser, "2026-09-05", "dinner", array(
    "starttime_formatted" => "5:00 am",
    "endtime_formatted" => "7:00 am"
));
assertSameValue(strtotime("2026-09-05 17:00:00 America/Los_Angeles"), $dinnerTimes[0], "dinner times mislabeled as a.m. upstream should be normalized to evening");
assertSameValue(strtotime("2026-09-05 19:00:00 America/Los_Angeles"), $dinnerTimes[1], "dinner end times mislabeled as a.m. upstream should be normalized to evening");

$navigationOnly = "<!doctype html><nav><ul><li>About</li><li>Dining</li></ul></nav>";
$mode = null;
$meals = $extractMeals->invokeArgs($parser, array($navigationOnly, "2026-09-05", &$mode));
assertSameValue(array(), $meals, "navigation list items must not be accepted as a menu");

fwrite(STDOUT, "PASS: BonAppetitWebParser fixture checks\n");
