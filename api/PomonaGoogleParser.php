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

class PomonaGoogleParser {

    const DAY_SECONDS = 24 * 60 * 60;

    private $spreadsheetURL;
    private $site;
    private $startTime;
    function __construct($spreadsheetURL, $site, $startTime){
        $this->spreadsheetURL = $spreadsheetURL;
        $this->site = strtolower($site);
        $this->startTime = round($startTime);
    }

    const ORDERED_STATIONS = [
        "entrée", "expo", "grill", "mainline", "starch", "pizza", "salad", "vegetable", "vegan/veggie", "soup", "soups", "deli-salad", "dessert"
    ];

    const TRUNCATED_STATIONS = [
        "breakfast grill" => 5,
    ];

    function truncateAmount($station){
        $screwPHP = PomonaGoogleParser::TRUNCATED_STATIONS;
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

    function fetchInitialJSONURL(){
//        $contents = file_get_contents($this->url);
//        $raw = preg_replace("#^.*(<div.*?id\s*=\s*['\"]menu-from-google['\"].*?\\>).*$#s", "$1", $contents);
//        $spreadsheetID = preg_replace("#^.*data-google-spreadsheet-id\s*=\s*['\"](.+?)['\"].*$#s", "$1", $raw);
//        $spreadsheetURL = "https://spreadsheets.google.com/feeds/worksheets/$spreadsheetID/public/basic?alt=json";

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



        $initialSpreadsheetJSON = json_decode(function_exists("menuWindowFileGetContents")
            ? menuWindowFileGetContents($this->spreadsheetURL)
            : file_get_contents($this->spreadsheetURL), true);

        $entries = $initialSpreadsheetJSON["feed"]["entry"];
        $monday = $this->nearestMonday();
        //        echo "<br>$monday";

        $entryForUse = null;
        foreach($entries as $entry){
            $spreadsheetDate = $entry["title"]["\$t"];
            //            echo "<br>  Checking $spreadsheetDate";
            if($spreadsheetDate == $monday){
                $entryForUse = $entry;
                break;
            }
        }
        //        echo "<br>$entryForUse";

        if($entryForUse == null){return null;}

        $colCount = $entryForUse["gs\$colCount"]["\$t"];
        $rowCount = $entryForUse["gs\$rowCount"]["\$t"];

        foreach($entryForUse["link"] as $link){
            if($link["rel"] == "http://schemas.google.com/spreadsheets/2006#cellsfeed"){
                return array($link["href"] . "?alt=json", $rowCount, $colCount);
            }
        }
        return null;
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

    public $json = null;
    public $info = null;
    function fetch(){
        ini_set("memory_limit", "128M");

        $includeTimes = false;// $_GET["returnTimes"];

        $info = $this->fetchInitialJSONURL();
        //echo $info;

        // dining-menu-from-json
        // https://my.pomona.edu/eatec/Frary.json

        if($info == null){
            $this->info = array(
                "messages" => [
                    "screenMessage" => "We're having issues with Pomona right now. Check back soon."
                ]
            );
            return;
        }

        $jsonURL = $info[0];
        $rowCount = $info[1];
        $colCount = $info[2];
        //echo("JSONURL START-- $jsonURL  --END");
        $raw = function_exists("menuWindowFileGetContents")
            ? menuWindowFileGetContents($jsonURL)
            : file_get_contents($jsonURL);
        $json = json_decode($raw, true);

        $sorted = array();
        foreach($json["feed"]["entry"] as $entry){
            $sorted[$entry["title"]["\$t"]] = $entry["content"]["\$t"];
        }

        //echo json_encode($sorted);

        function get($val, $default = null){
            return isset($val) ? $val : $default;
        }

        // Find Start time from https://www.pomona.edu/administration/dining/menus/...
        $hoursInfo = PomonaParser::fetchHoursInfo($this->site);

        function readyInfoAdd($arr, $hoursInfo){
            //find brakfast too
            foreach(["brunch", "breakfast", "lunch", "dinner", "snack"] as $x){
                $erase = true;
                unset($arr["meals"][$x]["stations"][""]);

                foreach(array_values($arr["meals"][$x]["stations"]) as $arrVal){
                    if($arrVal != null){$erase = false;}
                }

                if($erase){
                    $arr["meals"][$x] = null;
                    unset($arr["meals"][$x]);
                }
            }

            foreach(["brunch", "breakfast", "lunch", "dinner", "snack"] as $x){
                if($arr["meals"][$x] != null){
                    $arr["meals"][$x]["meal"] = $x;

                    $mealTime = $arr["time"];
                    $mealTimeDate = date("Y-m-d", $mealTime);
                    list($year, $month, $day) = explode("-", $mealTimeDate);

                    $dayType = strtolower(date("D", $mealTime));

                    list($startHour, $startMinute, $endHour, $endMinute) = PomonaParser::getHoursForMeal($hoursInfo, $dayType, $x);

                    $startTime = PomonaGoogleParser::makeTime($year, $month, $day, $startHour, $startMinute);
                    $endTime = PomonaGoogleParser::makeTime($year, $month, $day, $endHour, $endMinute);

                    if($startHour == 0 && $startMinute == 0 && $endTime == 0 && $endMinute == 0){
                        $arr["meals"][$x]["friendlyHours"] = "";
                    }
                    $arr["meals"][$x]["startTime"] = $startTime;
                    $arr["meals"][$x]["endTime"] = $endTime;

                    $stationsCurrent = $arr["meals"][$x]["stations"];
                    usort($stationsCurrent, function ($item1, $item2) {
                        $i1 = array_search(strtolower($item1["station"]), PomonaGoogleParser::ORDERED_STATIONS);
                        $i2 = array_search(strtolower($item2["station"]), PomonaGoogleParser::ORDERED_STATIONS);
                        if($i1 === false){return 1;}
                        if($i2 === false){return 1;}
                        if($i1 == $i2){return 0;}
                        return $i1 > $i2 ? 1 : -1;
                    });

                    $arr["meals"][$x]["stations"] = $stationsCurrent;
                }
            }

            return $arr;
        }


        $info = null;

        if($this->site == "oldenborg"){
            foreach(["B", "C", "D", "E", "F"] as $key){
                $day = $sorted["$key" . "2"];

                $mealTime = $this->makeMealTime($day);
                $mealDate = date("c", $mealTime);

                $infoAdd = array("date" => $mealDate, "time" => $mealTime, "meals" => array());
                for($i = 4; $i < $rowCount; $i++){
                    $station = $sorted["A$i"];
                    if(isset($sorted["$key$i"]) && $sorted["$key$i"] != null){
                        $infoAdd["meals"]["lunch"]["stations"][] = $this->makeMealArray($sorted["$key$i"], $station);
                    }
                }

                if(count($infoAdd["meals"]) > 0){
                    $info[] = readyInfoAdd($infoAdd, $hoursInfo);
                }
            }
        }
        else{
            $currDay = null;
            $infoAdd = null;

            for($i = 2; $i < $rowCount; $i++){
                if(get($sorted["A$i"]) != null){
                    $i += 1;
                    $currDay = $sorted["A$i"];
                    if($infoAdd != null){
                        $info[] = readyInfoAdd($infoAdd, $hoursInfo);
                    }

                    $mealTime = $this->makeMealTime($currDay);
                    $mealDate = date("c", $mealTime);

                    $infoAdd = array("date" => $mealDate, "time" => $mealTime, "meals" => array());
                    continue; /*never reaches B$i, so next is B$i+1*/
                }
                $station = get($sorted["B$i"]);
                $brunch = null;
                $breakfast = get($sorted["C$i"]);
                $lunch = get($sorted["D$i"]);
                if($lunch == null){
                    $brunch = $breakfast;
                    $breakfast = null;
                }
                $dinner = get($sorted["E$i"]);

                $infoAdd["meals"]["breakfast"]["stations"][] = $this->makeMealArray($breakfast, $station);
                $infoAdd["meals"]["brunch"]["stations"][] = $this->makeMealArray($brunch, $station);
                $infoAdd["meals"]["lunch"]["stations"][] = $this->makeMealArray($lunch, $station);
                $infoAdd["meals"]["dinner"]["stations"][] = $this->makeMealArray($dinner, $station);
            }

            if($infoAdd != null){$info[] = readyInfoAdd($infoAdd, $hoursInfo);}
        }

        $this->info = array("menu" => $info);
        if($this->site == "oldenborg"){
            // If the day is 6 or 7, or Sat or Sun, oldenborg is always closed
            if(date("N", $this->startTime) >= 6){
                $this->info["diningHallOpen"] = false;
            }
        }
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



    private function fixString($str){
        return preg_replace("/^\s*(.*?)\s*$/", "$1", $str);
    }
}