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

include_once "DiningHallParser.php";

class BonAppetitParser implements DiningHallParser{

    private $url;
    private $id;
    private $hall;

    function __construct($url, $id, $hall){
        $this->url = $url;
        $this->id = $id;
        $this->hall = $hall;
    }

    const SHOWN_STATIONS = [
        "breakfast grill", "breakfast @ home", "breakfast options", "@ home", "@home", "vegan salads", "stock pot",
        "ovens", "options", "grill", "grill special", "sweets", "expo", "vegan", "collins late night snack", "pasta-express", "sweets",

        "breakfast", "main plate", "global", "options", "stocks", "chef's table"
    ];

    const HIDDEN_STATIONS = [
        "breakfast toppings", "breads, bagels and spreads", "cold cereals", "cold cereal", "fruits and yogurts",
        "beverage", "beverages", "build your own sandwich",

        "cereal", "toppings & condiments", "deli bar"
    ];

    const EXPANDED_STATIONS = [
        "main plate", "global", "stocks",
        "breakfast @home", "@home", "vegan salads", "ovens", "options", "grill special", "stock pot"
    ];

    const ORDERED_STATIONS = [
        "chef's table", "main plate", "breakfast", "breakfast @home", "@home", "@ home", "breakfast options", "expo", "global", "options",
        "expo - mongolian", "expo - little italy", "grill", "pasta - express", "ovens", "collins late night snack", "ovens", "vegan", "vegan salads", "vegan - hummus & pita",
        "sweets", "stock pot", "stocks",
    ];

    const TRUNCATED_STATIONS = [
        "breakfast grill" => 5,"salad bar" => -1, "grill" => 3, "omelet bar" => -1, "breakfast" => 12,
        "breakfast @home" => 3, "breakfast options" => 5, "juice and smoothie bar" => -1, "expo - mongolian" => -1,
        "expo - little italy" => 3, "chef's table - pasta bar" => -1, "chef's table - taco bar" => -1
    ];

    const COMBINED_STATIONS = [
        "grill special" => ["grill"],
        "sweets" => ["sweets", "chocolate chip cookies"],
        "main plate" => ["main plate", "main plate in balance"],
        "ovens" => ["ovens", "ovens2"]
    ];

    function shouldHide($station){
        return in_array(strtolower($station), BonAppetitParser::HIDDEN_STATIONS);//!in_array(strtolower($station), BonAppetitParser::SHOWN_STATIONS);
    }

    function shouldExpand($station){
        return in_array(strtolower($station), BonAppetitParser::EXPANDED_STATIONS);
    }

    function truncateAmount($station){
        $screwPHP = BonAppetitParser::TRUNCATED_STATIONS;
        return isset($screwPHP[strtolower($station)]) ? $screwPHP[strtolower($station)] : false;
    }

    function makeTime($date, $time){
        list($hour, $minute) = explode(":", $time);
        list($year, $month, $day) = explode("-", $date);
        return mktime($hour, $minute, 0, $month, $day, $year);
    }

    function tryFindMerged($station){
        foreach(array_keys(BonAppetitParser::COMBINED_STATIONS) as $key){
            foreach(BonAppetitParser::COMBINED_STATIONS[$key] as $value){
                if(strtolower($station) == strtolower($value)){
                    return $key;
                }
            }
        }
        return $station;
    }

    public $json = null;
    public $info = null;
    private $expoInfo = array();
    function fetch(){
        if($_GET['developer']){
            //ini_set("display_errors", 1);
            //echo "developer";
        }

        ini_set("memory_limit", "256M");

        $includeTimes = false;// $_GET["returnTimes"];
        $showAll = isset($_GET["showAll"]) ? $_GET["showAll"] : false;

        //$raw = file_get_contents($this->url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept-Encoding: json",
            "Accept: */*",
            "Accept-Language: en-us"
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, false);
        $timeout = function_exists("currentMenuFetchTimeoutSeconds") ? currentMenuFetchTimeoutSeconds() : 45;
        if($timeout < 1){
            $this->json = null;
            return;
        }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(30, $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 128000);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        $raw = curl_exec ($ch);
        curl_close ($ch);

        $this->json = $json = json_decode($raw, true);

        if($_GET['developer']){
            //echo curl_error($ch);
            //echo "dveeloper $raw ";
            //print_r($this->json);
        }


        $info = array();

        $inner = $json["days"];
        for($i = 0; $i < count($inner); $i++){
            $day = $inner[$i];
            $dayDate = $day["date"];
            $meals = array();

            $lateNightSnack = null;

            foreach($day["cafes"][$this->id]["dayparts"][0] as $meal){
                $stations = array();

                $grillSpecial = null;

                $mealName = strtolower($meal["label"]);
                $startTimeRaw = strtolower($meal["starttime"]);
                $endTimeRaw = strtolower($meal["endtime"]);

                $startTime = $this->makeTime($dayDate, $startTimeRaw);
                $endTime = $this->makeTime($dayDate, $endTimeRaw);

                foreach($meal["stations"] as $station){
                    $stationName = $this->fixString($station["label"]);

                    $stationName = preg_replace("/(expo|vegan|pasta) *- *(.+?)/", "$1 - $2", $stationName);
                    $oldSTN = $stationName;
                    $stationName = preg_replace("/^.*(chef.?s table) *: *(.+)$/", "$1—$2", $stationName);
                    $isSpecificChefTable = $stationName != $oldSTN && preg_match("/.*chef.?s *table.*/", $stationName);

                    $stationName = $this->tryFindMerged($stationName);

                    $shouldHide = $this->shouldHide($stationName) || ($stationName == "ovens" && $this->hall == "mcconnel") || ($stationName == "breakfast" && $mealName != "breakfast");
                    if($mealName == "late night" && strtolower($stationName) == "beverages"){$shouldHide = false;}


                    if($shouldHide && !$showAll){
                        continue;
                    }

                    $menu = array();

                    $truncateAmount = $this->truncateAmount($stationName);
                    $itemCount = 0;
                    $allNotSpecial = true;

                    if($isSpecificChefTable && !$truncateAmount){$truncateAmount = 5;}

                    foreach($station["items"] as $menuItemID){
                        $itemCount++;
                        if($truncateAmount && $itemCount > $truncateAmount){break;}

                        $menuItem = $json["items"][$menuItemID];

                        if(isset($menuItem["special"]) && !$menuItem["special"]){
                            $itemCount--;
                            continue;
                        }
                        else{
                            $allNotSpecial = false;
                        }

//                        if($isSpecificChefTable){
////                            if($menuItem["tier"] === "1"){
////                                $itemCount--;
////                                continue;
////                            }
//                        }

                        $vegan = false;
                        $vegetarian = false;
                        foreach(array_values($menuItem["cor_icon"]) as $cor_icon){
                            if(strtolower($cor_icon) == "vegan"){$vegan = true; $vegetarian = true;}
                            else if(strtolower($cor_icon) == "vegetarian"){$vegetarian = true;}
                        }

                        $name = $this->fixString($menuItem["label"]);
                        $desc = $this->fixString($menuItem["description"]);
                        $calories = intval($menuItem["nutrition"]["kcal"]);
                        //TODO: if desc != null
                        if($name == "house made desserts" || $name == "assorted house-made desserts" || $name == "performance bowl" || $name == "grilled to order" || $name == "flatbread pizza"){
                            $name = "$name -- " . $desc;
                        }

                        // Truncate all ingredients from options
                        $shouldBreak = false;
                        if($stationName == "options" && $calories > 0){continue;}

                        // Things matched by this may always be there but only actually be in the dining hall on certain days
//                        if(preg_match("/^chef's table: .+$/", $stationName)){
//                            $desc = $stationName;
//                            $name = preg_replace("/^chef's table: (.+?)( ?\\(.+\\))?$/", "$1", $stationName);
//                            $stationName = "chef's table";
//                            $shouldBreak = true;
//                        }

                        $name = preg_replace("/ *<[ \/]*br[ \/]*> */", " | ", $name);
                        $desc = preg_replace("/ *<[ \/]*br[ \/]*> */", " | ", $desc);

                        $name = ucwords($name);

                        $shouldAddNextItem = true;
                        foreach($menu as $menuItemExisting){
                            if(strtolower($menuItemExisting["name"]) == strtolower($name)){
                                $shouldAddNextItem = false;
                                $menuItemExisting["vegan"] |= $vegan;
                                $menuItemExisting["vegetarian"] |= $vegetarian;
                                if(strlen($desc) > strlen($menuItemExisting["description"])){
                                    $menuItemExisting["description"] = $desc;
                                }
                                break;
                            }
                        }
                        if(!$shouldAddNextItem){
                            $itemCount--;
                            continue;
                        }

                        $add = array(
                            "name" => $name,
                            "description" => $desc,
                            "vegan" => $vegan,
                            "vegetarian" => $vegetarian,
                            "calories" => $calories
                        );



                        if($includeTimes){
                            $add["startTime"] = $this->convertTime($menuItem["startTime"]);
                            $add["endTime"] = $this->convertTime($menuItem["endTime"]);
                        }

                        $menu[] = $add;

                        if($shouldBreak){break;}
                    }

                    if($allNotSpecial && $itemCount < 1){
                        continue;
                    }

                    if($stationName == "grill special"){
                        $stationName = "grill";
                        //continue;
                    }

                    if($stationName == "options" && $menu[0]["name"] == "build your own flatbread"){
                        unset($stations["ovens"]);
                    }

                    $stationPretty = ucwords($stationName);
                    $stationPretty = str_replace(" And ", " and ", $stationPretty);
                    $stationToAdd = array("station" => $stationPretty, "stationOriginal" => $stationName, "autoCollapse" => !$this->shouldExpand($station), "menu" => $menu);

                    if(strtolower($stationName) == "collins late night snack" && strtolower($mealName) == "dinner"){
                        $lateNightSnack = $stationToAdd;

                        //$isDev = isset($_GET["developer"]) && $_GET["developer"] == "true";

                        foreach(array_keys($meals) as $mealKey){
                            if(strtolower($mealKey) == "late night"){
                                $meals[$mealKey]["stations"][] = $stationToAdd;

                            }
                        }

                        continue;
                    }

//                    if($station == "grill"){
//                        if($grillSpecial == null){
//                            $grillSpecial = isset($stations["grill special"]["menu"]) ? $stations["grill special"]["menu"] : null;
//                        }
//
//                        if($grillSpecial != null){
//                            foreach($grillSpecial as $grillSp){
//                                array_unshift($menu, $grillSp);
//                            }
//                        }
//                    }

                    $shouldAdd = true;
                    foreach($stations as $snm => $stv){
                        if($stv["menu"] == $menu){$shouldAdd = false; break;}
                    }
                    if(!$shouldAdd){continue;}

                    $stationIndex = 0;
                    $stationFound = false;
                    foreach($stations as $stationIn){
                        if($stationIn["station"] == $stationName){
                            $stationFound = true;
                            break;
                        }
                        $stationIndex++;
                    }

                    if(!$stationFound){
                        $stations[] = $stationToAdd;
                    }
                    else{
                        $stations[$stationIndex]["menu"] = array_merge($stations[$stationIndex]["menu"], $menu);
                    }

//                    if(isset($stations[strtolower($stationName)])){
//                        $stations[strtolower($stationName)]["menu"] = array_merge($menu, $stations[strtolower($stationName)]["menu"]);
//                    }
//                    else{
//                        // had in here
//                    }

                }

                //$isDev = isset($_GET["developer"]) && $_GET["developer"] == "true";

                if($lateNightSnack != null && strtolower($mealName) == "late night"){
                    $stations[] = $lateNightSnack;
                }

                usort($stations, function ($item1, $item2) {
                    $i1 = array_search(strtolower($item1["station"]), BonAppetitParser::ORDERED_STATIONS);
                    $i2 = array_search(strtolower($item2["station"]), BonAppetitParser::ORDERED_STATIONS);
                    if($i1 === false){return 1;}
                    if($i2 === false){return 1;}
                    if($i1 === $i2){return 0;}
                    return $i1 > $i2 ? 1 : -1;
                });

                //FIXME: friendlyHours should be set to correct hours
                $meals[$mealName] = array(
                    "meal" => $meal["label"],
                    "startTime" => $startTime,
                    "endTime" => $endTime,
                    "stations" => $stations
                );
                //start => $meal["starttime"]
                //end => $meal["endtime"]
            }
            $info[$i] = array("date" => $day["date"], "time" => $this->convertTime($day["date"]), "meals" => $meals);
        }

        $this->info = array("menu" => $info);
    }

    function getInfo(){
        return $this->info;
    }

    private function convertTime($time){
        date_default_timezone_set("America/Los_Angeles");
        return strtotime($time);
    }

    private function fixString($str){
        return preg_replace("/^\s*(.*?)\s*$/", "$1", $str);
    }
}