<?php
/**
Copyright 2019 Domenico Ottolia

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
 */

include_once __DIR__ . "/SodexoParser.php";
include_once __DIR__ . "/BonAppetitParser.php";
include_once __DIR__ . "/BonAppetitWebParser.php";
include_once __DIR__ . "/PomonaParser.php";
include_once __DIR__ . "/PomonaJSONParser.php";

$databaseMenuParserPath = __DIR__ . "/DatabaseMenuParser.php";
if(file_exists($databaseMenuParserPath)){
    include_once $databaseMenuParserPath;
}

function param($key, $default = NULL){
    return isset($_POST[$key]) ? $_POST[$key] : (isset($_GET[$key]) ? $_GET[$key] : $default);
}

function menuDayKey($date){
    $digits = preg_replace("/[^0-9]/", "", (string)$date);
    return strlen($digits) >= 8 ? substr($digits, 0, 8) : null;
}

function menuWindowStartTime($startTime, $startDateParam){
    date_default_timezone_set("America/Los_Angeles");

    if(!$startTime){
        $explodedStartDate = $startDateParam == null ? [] : preg_split("/[^0-9]+/", $startDateParam);
        $startTime = count($explodedStartDate) < 3 ? $startTime : mktime(0, 0, 0, $explodedStartDate[1], $explodedStartDate[2], $explodedStartDate[0]);
    }
    if(!$startTime){$startTime = time();}

    $localTime = new DateTimeImmutable("@" . intval($startTime));
    return $localTime->setTimezone(new DateTimeZone("America/Los_Angeles"))->setTime(0, 0, 0);
}

function menuWindowDays($startTime, $days){
    $menuDays = array();
    for($i = 0; $i < $days; $i++){
        $menuDays[] = $startTime->modify("+$i day");
    }
    return $menuDays;
}

function menuWindowFetchBudgetSeconds(){
    return 45;
}

function remainingMenuWindowBudget($startedAt, $now, $budgetSeconds = null){
    if($budgetSeconds === null){$budgetSeconds = menuWindowFetchBudgetSeconds();}
    return $budgetSeconds - ($now - $startedAt);
}

function canStartMenuWindowFetch($completedFetches, $remainingSeconds){
    return $completedFetches === 0 || $remainingSeconds >= 1;
}

function menuWindowFetchTimeoutSeconds($remainingSeconds){
    $timeout = (int)ceil($remainingSeconds);
    if($timeout < 1){$timeout = 1;}
    $budget = menuWindowFetchBudgetSeconds();
    return $timeout > $budget ? $budget : $timeout;
}

function currentMenuFetchTimeoutSeconds(){
    return isset($GLOBALS["MENU_WINDOW_FETCH_TIMEOUT"]) ? intval($GLOBALS["MENU_WINDOW_FETCH_TIMEOUT"]) : menuWindowFetchBudgetSeconds();
}

function collectMenuWindow($responses, $menuDays){
    $allowedDates = array();
    foreach($menuDays as $menuDay){
        $allowedDates[$menuDay->format("Ymd")] = true;
    }

    $menuByDate = array();
    foreach($responses as $response){
        if(!isset($response["menu"]) || !is_array($response["menu"])){continue;}
        foreach($response["menu"] as $menu){
            $key = isset($menu["date"]) ? menuDayKey($menu["date"]) : null;
            if($key === null || !isset($allowedDates[$key]) || isset($menuByDate[$key])){continue;}
            $menuByDate[$key] = $menu;
        }
    }

    $orderedMenu = array();
    foreach(array_keys($allowedDates) as $key){
        if(isset($menuByDate[$key])){$orderedMenu[] = $menuByDate[$key];}
    }
    return $orderedMenu;
}

function fetchMenu($diningHall, $startTime, $source){
    $startDate = date('m/d/Y', $startTime);
    if($diningHall == "mallot" || $diningHall == "mallott"){
        $diningHall = "malott";
    }
    if($diningHall == "mcconnell"){$diningHall = "mcconnel";}
    $parser = null;

    $allowCheckDatabase = !in_array($source, array("sodexo", "live", "web"));
    $shouldCheckDatabase = $source == "database";

    // Note: This check is for internal stuff, it doesn't have anything to do with getting the menu!
    if(class_exists("DatabaseMenuParser") && ($shouldCheckDatabase || $allowCheckDatabase)){
        $parser = new DatabaseMenuParser(strtolower($diningHall), $startTime);
        $parser->fetch();
        $parserInfo = $parser->getInfo();

        if(!$parserInfo["empty"] || $shouldCheckDatabase){
            return $parserInfo;
        }
    }


    ini_set("pcre.backtrack_limit", "23001337");
    ini_set("pcre.recursion_limit", "23001337");

    switch($diningHall){
        case "hoch":
            // old menuid 344
            $parser = new SodexoParser("hoch", "https://menus.sodexomyway.com/BiteMenu/MenuOnly?menuId=15258&locationId=13147001&startdate=$startDate", param("developer") === "true");
            break;
        case "malott":
            $parser = new BonAppetitWebParser("malott", array(
                "https://scripps.cafebonappetit.com/cafe/malott-dining-commons/{date}/",
                "https://scripps.cafebonappetit.com/"
            ), $startTime);
            //old menuid 288
            //11082
            break;
        case "mcconnel":
            $parser = new BonAppetitWebParser("mcconnel", array(
                "https://pitzer.cafebonappetit.com/cafe/mcconnell-bistro/{date}/",
                "https://www.pitzer.edu/student-life/living-pitzer/dining"
            ), $startTime);
            break;
        case "collins":
            $parser = new BonAppetitWebParser("collins", array(
                "https://collins-cmc.cafebonappetit.com/cafe/collins/{date}/",
                "https://www.cmc.edu/student-life/residential-life/dining"
            ), $startTime);
            break;
        case "frank":
            $parser = new PomonaParser("https://www.pomona.edu/administration/dining/menus/frank", "frank", $startTime);
            break;
        case "frary":
            $parser = new PomonaParser("https://www.pomona.edu/administration/dining/menus/frary", "frary", $startTime);
            break;
        case "oldenborg":
            $parser = new PomonaParser("https://www.pomona.edu/administration/dining/menus/oldenborg", "oldenborg", $startTime);
            break;
    }

    if($parser == null){
        throw new InvalidArgumentException("Unknown dining hall: $diningHall");
    }

    $parser->fetch();
    return $parser->getInfo();
}

function run($action){
    $startTime = menuWindowStartTime(param("startTime"), param("startDate", param("date")));
    $days = intval(param("days", 7));
    $days = max(1, min($days, 7));
    $diningHall = strtolower((string)param("diningHall", ""));
    $source = strtolower((string)param("source", ""));
    $menuDays = menuWindowDays($startTime, $days);
    $responses = array();
    $startedAt = microtime(true);

    foreach($menuDays as $menuDay){
        $remaining = remainingMenuWindowBudget($startedAt, microtime(true));
        if(!canStartMenuWindowFetch(count($responses), $remaining)){break;}
        $timeout = menuWindowFetchTimeoutSeconds($remaining > 0 ? $remaining : menuWindowFetchBudgetSeconds());
        $previousTimeout = ini_get("default_socket_timeout");
        $GLOBALS["MENU_WINDOW_FETCH_TIMEOUT"] = $timeout;
        ini_set("default_socket_timeout", (string)$timeout);
        try {
            $responses[] = fetchMenu($diningHall, $menuDay->getTimestamp(), $source);
        } finally {
            ini_set("default_socket_timeout", $previousTimeout);
            unset($GLOBALS["MENU_WINDOW_FETCH_TIMEOUT"]);
        }
        $menu = collectMenuWindow($responses, $menuDays);
        if(count($menu) === count($menuDays)){break;}
    }

    $result = count($responses) > 0 ? $responses[0] : array();
    $result["menu"] = collectMenuWindow($responses, $menuDays);
    return $result;
}
