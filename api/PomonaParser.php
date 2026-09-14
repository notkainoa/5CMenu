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
include_once "PomonaGoogleParser.php";
include_once "PomonaJSONParser.php";


class PomonaParser implements DiningHallParser{

    const DAY_SECONDS = 24 * 60 * 60;

    private $url;
    private $site;
    private $startTime;
    function __construct($url, $site, $startTime){
        $this->url = $url;
        $this->site = strtolower($site);
        $this->startTime = round($startTime);
    }

    function fetchInitialURLAndType(){

    }

    public $info = null;
    function fetch(){

        if($this->site === "oldenborg"){
            $date = date("Y-m-d", $this->startTime);
            $this->info = array(
                "menu" => array(array(
                    "date" => $date,
                    "time" => strtotime($date . " 00:00:00"),
                    "open" => false,
                    "messages" => array("screenMessage" => "Oldenborg Dining Hall is closed.")
                )),
                "diningHallOpen" => false
            );
            return;
        }

        $arrContextOptions=array(
            "ssl"=>array(
                "verify_peer"=>false,
                "verify_peer_name"=>false,
            ),
        );


        $contents = function_exists("menuWindowFileGetContents")
            ? menuWindowFileGetContents($this->url, $arrContextOptions)
            : file_get_contents($this->url, false, stream_context_create($arrContextOptions));
        if(!is_string($contents) || strlen($contents) < 1){
            $this->info = array("menu" => array());
            return;
        }

        $rawForJSON = preg_replace("#^.*(<div.*?id\s*=\s*['\"]dining-menu-from-json['\"].*?\\>).*$#s", "$1", $contents);
        $jsonURL = preg_replace("#^.*data-dining-menu-json-url\s*=\s*['\"](.+?)['\"].*$#s", "$1", $rawForJSON);

        //        $jsonContents = file_get_contents($jsonURL);
        //
        //        $jsonContents = preg_replace("#^.*?(\\{.+\\}).*?$#", "$1", $jsonContents);

        $json = null;
        if($jsonURL != null && strlen($jsonURL) > 0){
            $jsonContents = function_exists("menuWindowFileGetContents")
                ? menuWindowFileGetContents($jsonURL, $arrContextOptions)
                : file_get_contents($jsonURL, false, stream_context_create($arrContextOptions));
            if(is_string($jsonContents) && strlen($jsonContents) > 0){
                $jsonContents = preg_replace("#^.*?(\\{.+\\}).*?$#", "$1", $jsonContents);
                $json = json_decode($jsonContents, true, 512, JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
        }

        // Note: This should always be the statement that's called
        //use new json format
        if($json != null){
            $jsonParser = new PomonaJSONParser($json, $this->site, $this->startTime);
            $jsonParser->fetch();
            $this->info = $jsonParser->getInfo();
        }

        // use google format
        else{
            $raw = preg_replace("#^.*(<div.*?id\s*=\s*['\"]menu-from-google['\"].*?\\>).*$#s", "$1", $contents);
            $spreadsheetID = preg_replace("#^.*data-google-spreadsheet-id\s*=\s*['\"](.+?)['\"].*$#s", "$1", $raw);
            $spreadsheetURL = "https://spreadsheets.google.com/feeds/worksheets/$spreadsheetID/public/basic?alt=json";

            $jsonParser = new PomonaGoogleParser($spreadsheetURL, $this->site, $this->startTime);
            $jsonParser->fetch();
            $this->info = $jsonParser->getInfo();
        }


    }

    static function getHoursForMeal($hoursInfo, $day, $mealType){
        $dayOrdinal = PomonaParser::getDayOrdinal($day);

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

    static function fetchHoursInfo($site){
        $diningHallInfoURL = "https://www.pomona.edu/administration/dining/menus/$site";
        $diningHallInfoContents = function_exists("menuWindowFileGetContents")
            ? menuWindowFileGetContents($diningHallInfoURL)
            : file_get_contents($diningHallInfoURL);
        if(!is_string($diningHallInfoContents) || strlen($diningHallInfoContents) < 1){
            return [];
        }

        $info = [];
        for($i = 1; $i <= 5; $i++){
            $result = PomonaParser::getDiningDaysInfo($diningHallInfoContents, "dining-days-col-$i");
            if($result != null){
                $info[] = $result;
            }
        }

        return $info;
    }

    private static function getDiningDaysInfo($contents, $class){
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

            $timeStartInfo = PomonaParser::convert12HourTo24Hour($timeStartRaw);
            $timeEndInfo = PomonaParser::convert12HourTo24Hour($timeEndRaw);

            if($mealType == "continental breakfast"){$mealType = "breakfast";}
            $diningHoursArr[$mealType] = [$timeStartInfo[0], $timeStartInfo[1], $timeEndInfo[0], $timeEndInfo[1]];
        }

        return [PomonaParser::getDayOrdinal($diningDaysSplit[0]), PomonaParser::getDayOrdinal($diningDaysSplit[1]), $diningHoursArr];
    }

    static function getDayOrdinal($day){
        $fixed = preg_replace("#[^a-z]#", "", strtolower($day));
        if(strpos($fixed, "mon") !== false){return 1;}
        else if(strpos($fixed, "tue") !== false){return 2;}
        else if(strpos($fixed, "wed") !== false){return 3;}
        else if(strpos($fixed, "thu") !== false){return 4;}
        else if(strpos($fixed, "fri") !== false){return 5;}
        else if(strpos($fixed, "sat") !== false){return 6;}
        else if(strpos($fixed, "sun") !== false){return 7;}
    }

    static private function convert12HourTo24Hour($time){
        $fixed = preg_replace("#[^a-z0-9:]#", "", strtolower($time));
        $exploded = explode(":", preg_replace("#[^0-9:]#", "", $fixed));
        $hours = $exploded[0];
        $minutes = count($exploded) > 1 ? $exploded[1] : 0;
        $hourShift = preg_match("/^.*pm.*$/", $fixed) && $hours < 12 ? 12 : 0;
        return array($hours + $hourShift, $minutes);
    }

    function getInfo(){
        return $this->info;
    }
}
