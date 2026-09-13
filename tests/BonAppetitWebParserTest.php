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

function assertTrue($condition, $message){
    if(!$condition){failTest($message);}
}

function parseFixture($hall, $file){
    $parser = new BonAppetitWebParser($hall, array(), strtotime("2026-09-05 00:00:00 America/Los_Angeles"));
    $reflection = new ReflectionClass($parser);
    $extractMeals = $reflection->getMethod("extractMeals");
    $mode = null;
    $meals = $extractMeals->invokeArgs($parser, array(
        file_get_contents(__DIR__ . "/fixtures/" . $file),
        "2026-09-05",
        &$mode
    ));
    return array($parser, $meals, $mode);
}

function stationNames($meal){
    $names = array();
    foreach(($meal["stations"] ?? array()) as $station){
        $names[] = strtolower($station["station"]);
    }
    return $names;
}

function stationByName($meal, $name){
    foreach(($meal["stations"] ?? array()) as $station){
        if(strtolower($station["station"]) === strtolower($name) || strtolower($station["stationOriginal"] ?? "") === strtolower($name)){
            return $station;
        }
    }
    return null;
}

function itemNames($station){
    $names = array();
    if($station == null){return $names;}
    foreach($station["menu"] as $item){
        $names[] = strtolower($item["name"]);
    }
    return $names;
}

$_GET = array();

list($parser, $meals, $mode) = parseFixture("fixture", "bamco-menu.html");
assertSameValue("bamco", $mode, "the exact Bamco.menu_items assignment should win over menu_items_nonce");
assertSameValue("Fixture Tofu Bowl", $meals["brunch"]["stations"][0]["menu"][0]["name"] ?? null, "the real menu item should be parsed");
assertSameValue("with seasonal vegetables", $meals["brunch"]["stations"][0]["menu"][0]["description"] ?? null, "bamco descriptions should survive");
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

$reflection = new ReflectionClass($parser);
$mealTimes = $reflection->getMethod("mealTimes");
$dinnerTimes = $mealTimes->invoke($parser, "2026-09-05", "dinner", array(
    "starttime_formatted" => "5:00 am",
    "endtime_formatted" => "7:00 am"
));
assertSameValue(strtotime("2026-09-05 17:00:00 America/Los_Angeles"), $dinnerTimes[0], "dinner times mislabeled as a.m. upstream should be normalized to evening");
assertSameValue(strtotime("2026-09-05 19:00:00 America/Los_Angeles"), $dinnerTimes[1], "dinner end times mislabeled as a.m. upstream should be normalized to evening");

$extractMeals = $reflection->getMethod("extractMeals");
$navigationOnly = "<!doctype html><nav><ul><li>About</li><li>Dining</li></ul></nav>";
$mode = null;
$emptyMeals = $extractMeals->invokeArgs($parser, array($navigationOnly, "2026-09-05", &$mode));
assertSameValue(array(), $emptyMeals, "navigation list items must not be accepted as a menu");

list($parser, $meals, $mode) = parseFixture("collins", "bamco-filter.html");
assertSameValue("bamco", $mode, "filter fixture should parse as bamco");
assertTrue(isset($meals["breakfast"], $meals["dinner"]), "filter fixture should include breakfast and dinner");

$breakfast = $meals["breakfast"];
$dinner = $meals["dinner"];
$breakfastStations = stationNames($breakfast);
$dinnerStations = stationNames($dinner);

assertTrue(!in_array("deli bar", $breakfastStations, true), "deli bars are topping catalogs and should be hidden");
assertTrue(!in_array("cereal", $breakfastStations, true), "cereal catalogs should be hidden");
assertTrue(!in_array("breakfast toppings", $breakfastStations, true), "breakfast toppings should be hidden");
assertTrue(!in_array("breakfast grill", $breakfastStations, true), "omelet-bar ingredient stations should empty out");
assertTrue(stationByName($dinner, "breakfast") == null, "a leftover breakfast station at dinner should be hidden");
assertTrue(stationByName($dinner, "chef's table: pasta bar") == null, "chef's table DIY bars should be hidden");

$juice = stationByName($breakfast, "juice and smoothie bar");
assertTrue($juice != null, "featured juice drinks should remain");
assertSameValue(array("peach vanilla oat"), itemNames($juice), "always-on sodas in the juice bar should be dropped when featured drinks exist");

$breakfastMenu = stationByName($breakfast, "breakfast");
assertTrue($breakfastMenu != null, "an all-always-on breakfast station is the actual breakfast menu and should stay");
assertTrue(in_array("scrambled eggs", itemNames($breakfastMenu), true), "always-on breakfast dishes should not be dropped");
$blackBeans = null;
foreach($breakfastMenu["menu"] as $item){
    if(strtolower($item["name"]) === "black beans"){$blackBeans = $item;}
}
assertSameValue("", $blackBeans["description"] ?? "missing", "portion-only descriptions like 1/4 cup should be stripped");

$comfort = stationByName($breakfast, "comfort");
assertTrue($comfort != null, "comfort should remain at breakfast");
$potatoes = null;
$hashBrowns = null;
foreach($comfort["menu"] as $item){
    if(strtolower($item["name"]) === "garlic parsley breakfast potatoes"){$potatoes = $item;}
    if(strtolower($item["name"]) === "potato hash browns"){$hashBrowns = $item;}
}
assertTrue($potatoes != null, "featured breakfast potatoes should remain");
assertTrue(strpos($potatoes["description"], "smoked paprika") !== false, "short distinctive ingredients should backfill an empty description");
assertTrue(strpos($potatoes["description"], "flour") === false, "recipe bills of materials should not be copied into descriptions");
assertSameValue("", $hashBrowns["description"] ?? "missing", "seasoning-only descriptions should be stripped");

$omelet = stationByName($breakfast, "grill");
assertTrue($omelet != null, "the breakfast grill bar should remain as one build-your-own item");
assertSameValue(1, count($omelet["menu"]), "build-your-own bars should collapse to one item");
assertTrue(strpos(strtolower($omelet["menu"][0]["description"]), "vegan egg") !== false, "request-only notes should move onto the build-your-own item");

$hotCereal = stationByName($breakfast, "hot cereal");
assertTrue($hotCereal != null, "hot cereal should remain");
$hotNames = itemNames($hotCereal);
assertTrue(in_array("hot oatmeal", $hotNames, true), "hot oatmeal should remain");
assertTrue(in_array("almond overnight oats", $hotNames, true), "overnight oats should remain");
assertTrue(!in_array("roasted apple, roasted mushrooms, sautéed spinach, butternut squash", $hotNames, true), "a topping list that duplicates the previous description should be dropped");
$oatmeal = null;
foreach($hotCereal["menu"] as $item){
    if(strtolower($item["name"]) === "hot oatmeal"){$oatmeal = $item;}
}
assertTrue(strpos(strtolower($oatmeal["description"]), "roasted apple") === false, "overnight-oat toppings must not leak onto oatmeal");

$ovens = stationByName($breakfast, "ovens");
assertTrue($ovens != null, "ovens from ovens and ovens2 should merge");
assertTrue(in_array("cauliflower crust pepperoni pizza", itemNames($ovens), true), "pizza in ovens2 should survive");
assertTrue(!in_array("dried oregano", itemNames($ovens), true), "zero-calorie condiments on the pizza station should be dropped");

$saladBar = stationByName($breakfast, "salad bar");
assertTrue($saladBar != null, "salad bar should still show as a station");
assertSameValue(array("self serve salad bar"), itemNames($saladBar), "a featured salad-bar header should remain without the topping catalog");

$dinnerSaladBar = stationByName($dinner, "salad bar");
assertTrue($dinnerSaladBar != null, "a toppings-only salad bar should still show as an option");
assertSameValue(array("self-serve"), itemNames($dinnerSaladBar), "when there is no featured salad item, keep a self-serve placeholder instead of every topping");

$sweets = stationByName($breakfast, "sweets");
assertTrue(in_array("tres leches cake", itemNames($sweets), true), "always-on desserts should stay even when the station also has a featured cookie");

$grill = stationByName($dinner, "grill");
assertTrue($grill != null, "the dinner grill should remain");
$grillNames = itemNames($grill);
assertTrue(in_array("grass fed beef smash burger", $grillNames, true), "featured grill dishes should remain");
assertTrue(in_array("french fries", $grillNames, true), "fries after the topping lists should remain a separate dish");
assertTrue(!in_array("onion", $grillNames, true), "always-on grill toppings should be dropped when featured dishes exist");
assertTrue(!in_array("beef patty", $grillNames, true), "always-on generic patties should yield to today's featured grill items");
assertTrue(!in_array("live grill - made fresh to order", $grillNames, true), "made-to-order boilerplate should not be a menu row");
$burger = null;
$fries = null;
foreach($grill["menu"] as $item){
    if(strtolower($item["name"]) === "grass fed beef smash burger"){$burger = $item;}
    if(strtolower($item["name"]) === "french fries"){$fries = $item;}
}
assertTrue(strpos(strtolower($burger["description"]), "lettuce") !== false, "comma-separated topping rows should fold onto the preceding grill dishes");
assertTrue(strpos(strtolower($fries["description"]), "lettuce") === false, "topping lists should not attach to dishes that come after them");

$global = stationByName($dinner, "global");
assertTrue($global != null, "the pasta bar station should remain");
assertSameValue(1, count($global["menu"]), "a pasta bar should collapse to the header item");
assertTrue(strpos(strtolower($global["menu"][0]["description"]), "marinara") !== false, "pasta-bar components belong in the header description");

$comfortDinner = stationByName($dinner, "comfort");
assertTrue($comfortDinner != null, "comfort should remain at dinner");
assertSameValue(array("steamed spinach, kale, shallots"), itemNames($comfortDinner), "a cooked vegetable plate with commas is a dish, not a topping list");

$dinnerSweets = stationByName($dinner, "sweets");
assertTrue($dinnerSweets != null, "sweets should remain at dinner");
assertTrue(in_array("lemon bars", itemNames($dinnerSweets), true), "lemon bars are a dessert, not a salad bar");
assertSameValue("", $dinnerSweets["menu"][0]["description"], "baking recipes should not be used as the dessert description");

list($mcParser, $mcMeals, $mcMode) = parseFixture("mcconnel", "bamco-filter.html");
assertTrue(isset($mcMeals["breakfast"]), "McConnell breakfast should parse");
$mcOvens = stationByName($mcMeals["breakfast"], "ovens");
assertTrue($mcOvens != null, "ovens stay visible so pastries are listed");
assertTrue(in_array("house-made scones", itemNames($mcOvens), true), "oven pastries should remain");

$_GET["showAll"] = "true";
list($rawParser, $rawMeals, $rawMode) = parseFixture("collins", "bamco-filter.html");
assertTrue(stationByName($rawMeals["breakfast"], "deli bar") != null, "showAll should keep hidden topping stations");
assertTrue(in_array("onion", itemNames(stationByName($rawMeals["dinner"], "grill")), true), "showAll should keep always-on grill toppings");
$_GET["showAll"] = "TRUE";
list($upperParser, $upperMeals, $upperMode) = parseFixture("collins", "bamco-filter.html");
assertTrue(stationByName($upperMeals["breakfast"], "deli bar") != null, "showAll=TRUE should keep hidden topping stations");
$_GET["showAll"] = "false";
list($stillFilteredParser, $stillFilteredMeals, $stillFilteredMode) = parseFixture("collins", "bamco-filter.html");
assertTrue(stationByName($stillFilteredMeals["breakfast"], "deli bar") == null, "showAll=false should still hide topping catalogs");
$_GET = array();

$isComponentList = $reflection->getMethod("isComponentList");
assertSameValue(
    false,
    $isComponentList->invoke($parser, "roasted chicken, mashed potatoes, green beans, gravy"),
    "a cooked four-part plate is a dish, not a foldable topping list"
);
assertSameValue(
    true,
    $isComponentList->invoke($parser, "roasted apple, roasted mushrooms, sautéed spinach, butternut squash"),
    "a four-part roasted produce row is still a topping list"
);

fwrite(STDOUT, "PASS: BonAppetitWebParser fixture checks\n");
