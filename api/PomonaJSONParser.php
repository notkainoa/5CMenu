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

class PomonaJSONParser{

    const DAY_SECONDS = 24 * 60 * 60;

    private $jsonContents;
    private $site;
    private $startTime;
    function __construct($json, $site, $startTime){
        $this->jsonContents = $json;
        $this->site = strtolower($site);
        $this->startTime = round($startTime);
    }

    const ORDERED_STATIONS = [
        "entrée", "expo", "grill", "mainline", "starch", "pizza", "allergen friendly station", "salad", "salad bar", "vegetable", "vegan/veggie", "soup", "deli-salad", "dessert"
    ];

    const TRUNCATED_STATIONS = [
        "breakfast grill" => 5,
    ];

    const COMBINED_STATIONS = [
        "Grill" => ["grill", "grill station"],
        "Soup" => ["soup", "soup station", "soups"],
        "Expo" => ["expo", "expo station"]
    ];

    function truncateAmount($station){
        $screwPHP = PomonaJSONParser::TRUNCATED_STATIONS;
        return isset($screwPHP[$station]) ? $screwPHP[$station] : false;
    }

    function nearestMonday(){
        date_default_timezone_set("America/Los_Angeles");
        while(strtolower(date("D", $this->startTime)) != "mon"){
            $this->startTime-=self::DAY_SECONDS;
        }

        return date("n-j-y", $this->startTime);
    }

    function makeMealTime($currDay){
        //find current time from $currDay
        $dayTypesArr = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"];
        $dayTypeIndex = array_search(strtolower(date("D", $this->startTime)), $dayTypesArr);
        $currDayIndex = array_search(strtolower(substr(strtolower($currDay), 0, 3)), $dayTypesArr);
        $shift = 60 * 60 * 24;
        return $this->startTime + ($shift * ($currDayIndex - $dayTypeIndex));
    }

    function fetchInitialJSON($jsonContents){
//        $contents = file_get_contents($this->url);
//        $raw = preg_replace("#^.*(<div.*?id\s*=\s*['\"]dining-menu-from-json['\"].*?\\>).*$#s", "$1", $contents);
//        $jsonURL = preg_replace("#^.*data-dining-menu-json-url\s*=\s*['\"](.+?)['\"].*$#s", "$1", $raw);

        //        $isDev = $_GET["developer"] === "true";
        //        if($isDev){
        //                    echo "<br>$this->url<br>$raw";
        //
        //                    echo "--" . (preg_match("#spreadsheets#", $contents) ? "TRUE" : "FALSE");
        //
        //                    echo "<br>$spreadsheetID";
        //                    echo "<br>$spreadsheetURL";
        //                    $this->info[] = $spreadsheetURL;
        //        }

        //echo $jsonURL;
//        $jsonContents = file_get_contents($jsonURL);
//
//        $jsonContents = preg_replace("#^.*?(\\{.+\\}).*?$#", "$1", $jsonContents);
//        //echo $jsonContents;
//
//        return json_decode($jsonContents, true, 512, JSON_PARTIAL_OUTPUT_ON_ERROR);



//        $entries = $initialSpreadsheetJSON["feed"]["entry"];
//        $monday = $this->nearestMonday();
//        //        echo "<br>$monday";
//
//        $entryForUse = null;
//        foreach($entries as $entry){
//            $spreadsheetDate = $entry["title"]["\$t"];
//            //            echo "<br>  Checking $spreadsheetDate";
//            if($spreadsheetDate == $monday){
//                $entryForUse = $entry;
//                break;
//            }
//        }
//        //        echo "<br>$entryForUse";
//
//        if($entryForUse == null){return null;}
//
//        $colCount = $entryForUse["gs\$colCount"]["\$t"];
//        $rowCount = $entryForUse["gs\$rowCount"]["\$t"];
//
//        foreach($entryForUse["link"] as $link){
//            if($link["rel"] == "http://schemas.google.com/spreadsheets/2006#cellsfeed"){
//                return array($link["href"] . "?alt=json", $rowCount, $colCount);
//            }
//        }
//        return null;
    }

    private function makeMealArray($input, $station){
        if($input == null){return null;}
        $arr = array();

        $splitRegex = $this->site == "oldenborg" ? "[,/]" : ",";
        foreach(preg_split("#$splitRegex ?#", $input) as $val){
            $arr[] = array("name" => $val);
        }
        return array("menu" => $arr, "station" => $station, "stationOriginal" => $station);
    }

    public static function tryFindMerged($station){
        foreach(array_keys(PomonaJSONParser::COMBINED_STATIONS) as $key){
            foreach(PomonaJSONParser::COMBINED_STATIONS[$key] as $value){
                if(strtolower($station) == strtolower($value)){
                    return $key;
                }
            }
        }
        return $station;
    }

    private function markDateClosed(&$formattedMeals, $date){
        if(!isset($formattedMeals[$date])){$formattedMeals[$date] = "CLOSED";}
    }

    private function addMenuItem(&$formattedMeals, $date, $mealType, $menuItem){
        if(!is_array($menuItem)){return;}
        if(isset($menuItem["@displayonwebsite"]) && strtolower($menuItem["@displayonwebsite"]) !== "yes"){return;}

        $station = self::tryFindMerged(isset($menuItem["@category"]) ? $menuItem["@category"] : "Station");
        $itemName = trim(isset($menuItem["@shortName"]) ? $menuItem["@shortName"] : "");
        if($itemName === ""){return;}

        if(!isset($formattedMeals[$date]) || $formattedMeals[$date] === "CLOSED"){$formattedMeals[$date] = array();}
        if(!isset($formattedMeals[$date][$mealType])){$formattedMeals[$date][$mealType] = array();}
        if(!isset($formattedMeals[$date][$mealType][$station])){$formattedMeals[$date][$mealType][$station] = array();}

        $vegan = false;
        $vegetarian = false;
        $dietaryChoices = isset($menuItem["dietaryChoices"]["dietaryChoice"]) ? $menuItem["dietaryChoices"]["dietaryChoice"] : array();
        if($this->isAssoc($dietaryChoices)){$dietaryChoices = array($dietaryChoices);}
        foreach($dietaryChoices as $choice){
            if(!is_array($choice) || strtolower(isset($choice["#text"]) ? $choice["#text"] : "") !== "yes"){continue;}
            $choiceName = strtolower(isset($choice["@id"]) ? $choice["@id"] : "");
            if($choiceName === "vegan"){$vegan = true; $vegetarian = true;}
            if($choiceName === "vegetarian"){$vegetarian = true;}
        }

        $nutrientValues = isset($menuItem["@nutrients"]) ? explode("|", $menuItem["@nutrients"]) : array();
        $calories = isset($nutrientValues[0]) && is_numeric(trim($nutrientValues[0])) ? intval(round(floatval(trim($nutrientValues[0])))) : 0;

        $formattedMeals[$date][$mealType][$station][] = array(
            "name" => $itemName,
            "description" => trim(isset($menuItem["@itemDailyComment"]) && $menuItem["@itemDailyComment"] !== "" ? $menuItem["@itemDailyComment"] : (isset($menuItem["@description"]) ? $menuItem["@description"] : "")),
            "vegan" => $vegan,
            "vegetarian" => $vegetarian,
            "calories" => $calories
        );
    }

    private function defaultHoursForMeal($date, $mealType){
        $dayOfWeek = intval(date("N", $date));
        $isWeekend = $dayOfWeek >= 6;
        $meal = strtolower($mealType);

        if(strpos($meal, "breakfast") !== false){
            return array(7, 30, $this->site === "frary" && !$isWeekend ? 10 : 9, 30);
        }
        if(strpos($meal, "brunch") !== false){return array(10, 30, 13, 30);}
        if(strpos($meal, "lunch") !== false){return array(11, 0, 13, 30);}
        if(strpos($meal, "dinner") !== false){return array(17, 0, 19, 30);}
        return array(0, 0, 0, 0);
    }

    public $json = null;
    public $info = null;
    function fetch(){
        ini_set("memory_limit", "128M");

        $includeTimes = false;// $_GET["returnTimes"];

        //$json = $this->fetchInitialJSON($jsonContents);
        //echo $info;

        // dining-menu-from-json
        // https://my.pomona.edu/eatec/Frary.json

        $json = $this->jsonContents;
        $parentJSON = $json["EatecExchange"];
        $meals = $parentJSON["menu"];

        $finalMenu = array();

        $formattedMeals = array();

        // Find Start time from https://www.pomona.edu/administration/dining/menus/...
        $hoursInfo = PomonaParser::fetchHoursInfo($this->site);

        foreach($meals as $meal){
            $date = $meal["@servedate"];
            $mealType = $meal["@mealperiodname"];
            $menuBulletin = $meal["@menubulletin"];
            //dining hall is closed
            if(strtolower($menuBulletin) == "closed" && strtolower($mealType) == "closed"){
                $this->markDateClosed($formattedMeals, $date);
                continue;
            }
            $menu = isset($meal["recipes"]["recipe"]) ? $meal["recipes"]["recipe"] : array();

            //$mealType === "Closed" if meal is closed
            //echo "In $mealType for $date<br>";

            //print_r($menu);

            if($this->isAssoc($menu)){
                $this->addMenuItem($formattedMeals, $date, $mealType, $menu);
            }
            else{
                foreach($menu as $menuItem){
                    $this->addMenuItem($formattedMeals, $date, $mealType, $menuItem);
                }
            }
        }

        foreach(array_keys($formattedMeals) as $date){

            $year = substr($date, 0, 4);
            $month = substr($date, 4, 2);
            $day = substr($date, 6, 2);
            $hour = $minute = 0;
            $time = mktime($hour, $minute, 0, $month, $day, $year);

            $meals = array();

            $isClosed = $formattedMeals[$date] == "CLOSED";
            if($isClosed){
                $finalMenu[] = array("date" => $date, "time" => $time, "open" => false, "messages" => ["screenMessage" => "Closed today"]);

                continue;
            }

            foreach(array_keys($formattedMeals[$date]) as $mealType){

                $stationInfoArr = array();

                foreach(array_keys($formattedMeals[$date][$mealType]) as $station){

                    $stationMenu = array();

                    foreach($formattedMeals[$date][$mealType][$station] as $menuItem){
                        $stationMenu[] = $menuItem;
                    }

                    $stationInfoArr[] = ["station" => $station, "stationOriginal" => $station, "menu" => $stationMenu];
                }


                usort($stationInfoArr, function ($item1, $item2) {
                    $i1 = array_search(strtolower($item1["station"]), PomonaJSONParser::ORDERED_STATIONS);
                    $i2 = array_search(strtolower($item2["station"]), PomonaJSONParser::ORDERED_STATIONS);
                    if($i1 === false){return 1;}
                    if($i2 === false){return 1;}
                    if($i1 === $i2){return 0;}
                    return $i1 > $i2 ? 1 : -1;
                });

                $mealTime = mktime(0, 0, 0, $month, $day, $year);
                $dayType = strtolower(date("D", $mealTime));

                $hours = PomonaParser::getHoursForMeal($hoursInfo, $dayType, strtolower($mealType));
                if(!is_array($hours) || count($hours) < 4){$hours = $this->defaultHoursForMeal($mealTime, $mealType);}
                list($startHour, $startMinute, $endHour, $endMinute) = $hours;

                $startTime = self::makeTime($year, $month, $day, $startHour, $startMinute);
                $endTime = self::makeTime($year, $month, $day, $endHour, $endMinute);

                $meals[strtolower($mealType)] = ["meal" => $mealType, "stations" => $stationInfoArr, "startTime" => $startTime, "endTime" => $endTime];

                if($startHour == 0 && $startMinute == 0 && $endTime == 0 && $endMinute == 0){
                    $arr["meals"][strtolower($mealType)]["friendlyHours"] = "";
                }
            }



            $finalMenu[] = array("date" => $date, "time" => $time, "meals" => $meals);
        }

        $this->info = array("menu" => $finalMenu);

        //echo json_encode($sorted);

//        function get($val, $default = null){
//            return isset($val) ? $val : $default;
//        }
//
//        // Find Start time from https://www.pomona.edu/administration/dining/menus/...
//        $hoursInfo = $this->fetchHoursInfo();
//
//        function readyInfoAdd($arr, $hoursInfo){
//            //find brakfast too
//            foreach(["brunch", "breakfast", "lunch", "dinner", "snack"] as $x){
//                $erase = true;
//                unset($arr["meals"][$x]["stations"][""]);
//
//                foreach(array_values($arr["meals"][$x]["stations"]) as $arrVal){
//                    if($arrVal != null){$erase = false;}
//                }
//
//                if($erase){
//                    $arr["meals"][$x] = null;
//                    unset($arr["meals"][$x]);
//                }
//            }
//
//            foreach(["brunch", "breakfast", "lunch", "dinner", "snack"] as $x){
//                if($arr["meals"][$x] != null){
//                    $arr["meals"][$x]["meal"] = $x;
//
//                    $mealTime = $arr["time"];
//                    $mealTimeDate = date("Y-m-d", $mealTime);
//                    list($year, $month, $day) = explode("-", $mealTimeDate);
//
//                    $dayType = strtolower(date("D", $mealTime));
//
//                    list($startHour, $startMinute, $endHour, $endMinute) = PomonaJSONParser::getHoursForMeal($hoursInfo, $dayType, $x);
//
//                    $startTime = PomonaJSONParser::makeTime($year, $month, $day, $startHour, $startMinute);
//                    $endTime = PomonaJSONParser::makeTime($year, $month, $day, $endHour, $endMinute);
//
//                    if($startHour == 0 && $startMinute == 0 && $endTime == 0 && $endMinute == 0){
//                        $arr["meals"][$x]["friendlyHours"] = "";
//                    }
//                    $arr["meals"][$x]["startTime"] = $startTime;
//                    $arr["meals"][$x]["endTime"] = $endTime;
//
//                    $stationsCurrent = $arr["meals"][$x]["stations"];
//                    usort($stationsCurrent, function ($item1, $item2) {
//                        $i1 = array_search(strtolower($item1["station"]), PomonaJSONParser::ORDERED_STATIONS);
//                        $i2 = array_search(strtolower($item2["station"]), PomonaJSONParser::ORDERED_STATIONS);
//                        if($i1 === false){return 1;}
//                        if($i2 === false){return 1;}
//                        if($i1 == $i2){return 0;}
//                        return $i1 > $i2 ? 1 : -1;
//                    });
//
//                    $arr["meals"][$x]["stations"] = $stationsCurrent;
//                }
//            }
//
//            return $arr;
//        }
//
//
//        $info = null;
//
//        if($this->site == "oldenborg"){
//            foreach(["B", "C", "D", "E", "F"] as $key){
//                $day = $sorted["$key" . "2"];
//
//                $mealTime = $this->makeMealTime($day);
//                $mealDate = date("c", $mealTime);
//
//                $infoAdd = array("date" => $mealDate, "time" => $mealTime, "meals" => array());
//                for($i = 4; $i < $rowCount; $i++){
//                    $station = $sorted["A$i"];
//                    if(isset($sorted["$key$i"]) && $sorted["$key$i"] != null){
//                        $infoAdd["meals"]["lunch"]["stations"][] = $this->makeMealArray($sorted["$key$i"], $station);
//                    }
//                }
//
//                if(count($infoAdd["meals"]) > 0){
//                    $info[] = readyInfoAdd($infoAdd, $hoursInfo);
//                }
//            }
//        }
//        else{
//            $currDay = null;
//            $infoAdd = null;
//
//            for($i = 2; $i < $rowCount; $i++){
//                if(get($sorted["A$i"]) != null){
//                    $i += 1;
//                    $currDay = $sorted["A$i"];
//                    if($infoAdd != null){
//                        $info[] = readyInfoAdd($infoAdd, $hoursInfo);
//                    }
//
//                    $mealTime = $this->makeMealTime($currDay);
//                    $mealDate = date("c", $mealTime);
//
//                    $infoAdd = array("date" => $mealDate, "time" => $mealTime, "meals" => array());
//                    continue; /*never reaches B$i, so next is B$i+1*/
//                }
//                $station = get($sorted["B$i"]);
//                $brunch = null;
//                $breakfast = get($sorted["C$i"]);
//                $lunch = get($sorted["D$i"]);
//                if($lunch == null){
//                    $brunch = $breakfast;
//                    $breakfast = null;
//                }
//                $dinner = get($sorted["E$i"]);
//
//                $infoAdd["meals"]["breakfast"]["stations"][] = $this->makeMealArray($breakfast, $station);
//                $infoAdd["meals"]["brunch"]["stations"][] = $this->makeMealArray($brunch, $station);
//                $infoAdd["meals"]["lunch"]["stations"][] = $this->makeMealArray($lunch, $station);
//                $infoAdd["meals"]["dinner"]["stations"][] = $this->makeMealArray($dinner, $station);
//            }
//
//            if($infoAdd != null){$info[] = readyInfoAdd($infoAdd, $hoursInfo);}
//        }
//
//        $this->info = array("menu" => $info);
//        if($this->site == "oldenborg"){
//            // If the day is 6 or 7, or Sat or Sun, oldenborg is always closed
//            if(date("N", $this->startTime) >= 6){
//                $this->info["diningHallOpen"] = false;
//            }
//        }
    }

    function getInfo(){
        return $this->info;
    }

    static function makeTime($year, $month, $day, $hour, $minute){
        return mktime($hour, $minute, 0, $month, $day, $year);
    }

    private function convertTime($time){
        date_default_timezone_set("America/Los_Angeles");
        return strtotime($time);
    }

    function getHoursForMeal($hoursInfo, $day, $mealType){
        $dayOrdinal = PomonaJSONParser::getDayOrdinal($day);

        // finding the day
        foreach($hoursInfo as $info){
            if($dayOrdinal >= $info[0] && $dayOrdinal <= $info[1]){
                foreach(array_keys($info[2]) as $infoMealType){
                    if(strpos(preg_replace("[^a-z]", "", strtolower($infoMealType)), strtolower($mealType)) !== false){
                        return $info[2][$infoMealType];
                    }
                }
            }
        }

        //couldn't find the correct date, so checking for same meal on other days
        foreach($hoursInfo as $info){
            foreach(array_keys($info[2]) as $infoMealType){
                if(strpos(preg_replace("[^a-z]", "", strtolower($infoMealType)), strtolower($mealType)) !== false){
                    return $info[2][$infoMealType];
                }
            }
        }

        return null;
    }

    private function fetchHoursInfo(){
        $diningHallInfoURL = "https://www.pomona.edu/administration/dining/menus/$this->site";
        $diningHallInfoContents = file_get_contents($diningHallInfoURL);

        $info = [];
        for($i = 1; $i <= 5; $i++){
            $result = PomonaJSONParser::getDiningDaysInfo($diningHallInfoContents, "dining-days-col-$i");
            if($result != null){
                $info[] = $result;
            }
        }

        return $info;
    }

    private function getDiningDaysInfo($contents, $class){
        $classRegex = "['\"]" . $class . "['\"]";
        $rawContainer = preg_replace("#^.*<div\s+class\s*=\s*$classRegex.*?>(.*)$#s", "$1", $contents);

        if(preg_match("#\\<!--\w*\\<div\s+?class\s*=\s*$classRegex.*?\\>(.*)#s", $contents) || preg_match("#\\<\w*!--\w*div\s+class\s*=\s*$classRegex.*?\\>(.*)#s", $contents)){
            return null;
        }
        else if(!preg_match("#\\<div\s+?class\s*=\s*$classRegex.*?\\>(.*)#s", $contents)){
            return null;
        }

        $diningDaysContainer = preg_replace("#<div.*?class\s*=\s*['\"]dining-days['\"].*?\\>(.*?)#s", "$1", $rawContainer);
        $diningDays = preg_replace("#^(.+?)\\<\s*\\/div\s*>.*$#s", "$1", $diningDaysContainer);

        $diningDaysFixed = preg_replace("#[^a-z\\-]#", "", strtolower($diningDays));
        $diningDaysSplit = explode("-", $diningDaysFixed);

        $diningHoursContainer = preg_replace("#<div.*?class\s*=\s*['\"]dining-hours['\"].*?\\>(.*?)#s", "$1", $rawContainer);
        $diningHoursList = preg_replace("#^(.+?)\\<\s*\\/div\s*>.*$#s", "$1", $diningHoursContainer);

        $splitDiningHours = preg_split("#<[/\\s]*br[/\\s]*>#", $diningHoursList);



        $diningHoursArr = array();
        foreach($splitDiningHours as $diningHour){

            $mealTypeRaw = preg_replace("#<\s*span.*>(.+?)<\s*/\s*span.*>.*#s", "$1", $diningHour);
            $mealType = strtolower(str_replace(":", "", $mealTypeRaw));

            $timeRangeRaw = preg_replace("#.*<\s*/\s*span.*>(.+)$#s", "$1", $diningHour);
            $timeRangeFixed = preg_replace("#[^0-9a-z:\\-]#", "$1", strtolower($timeRangeRaw));
            $timeRangeSplit = preg_split("#[^0-9a-z:]+#", $timeRangeFixed);

            $timeStartRaw = $timeRangeSplit[0];
            $timeEndRaw = $timeRangeSplit[1];

            $timeStartInfo = $this->convert12HourTo24Hour($timeStartRaw);
            $timeEndInfo = $this->convert12HourTo24Hour($timeEndRaw);

            if($mealType == "continental breakfast"){$mealType = "breakfast";}
            $diningHoursArr[$mealType] = [$timeStartInfo[0], $timeStartInfo[1], $timeEndInfo[0], $timeEndInfo[1]];
        }

        return [$this->getDayOrdinal($diningDaysSplit[0]), $this->getDayOrdinal($diningDaysSplit[1]), $diningHoursArr];
    }

    function getDayOrdinal($day){
        $fixed = preg_replace("#[^a-z]#", "", strtolower($day));
        if(strpos($fixed, "mon") !== false){return 1;}
        else if(strpos($fixed, "tue") !== false){return 2;}
        else if(strpos($fixed, "wed") !== false){return 3;}
        else if(strpos($fixed, "thu") !== false){return 4;}
        else if(strpos($fixed, "fri") !== false){return 5;}
        else if(strpos($fixed, "sat") !== false){return 6;}
        else if(strpos($fixed, "sun") !== false){return 7;}
    }

    private function convert12HourTo24Hour($time){
        $fixed = preg_replace("#[^a-z0-9:]#", "", strtolower($time));
        $exploded = explode(":", preg_replace("#[^0-9:]#", "", $fixed));
        $hours = $exploded[0];
        $minutes = count($exploded) > 1 ? $exploded[1] : 0;
        $hourShift = preg_match("/^.*pm.*$/", $fixed) && $hours < 12 ? 12 : 0;
        return array($hours + $hourShift, $minutes);
    }

    private function fixString($str){
        return preg_replace("/^\s*(.*?)\s*$/", "$1", $str);
    }

    private function isAssoc($arr)
    {
        if($arr == null || !is_array($arr)){return false;}

        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
