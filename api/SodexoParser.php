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

class SodexoParser implements DiningHallParser{

    private $hall;
    private $url;
    private $developer;
    function __construct($hall, $url, $developer = false){
        $this->hall = $hall;
        $this->url = $url;
        $this->developer = $developer;
    }

    const HIDDEN_STATIONS = [
        "salad bar", "deli bar", "hot cereal", "sub connection", "deli bar hmc", "deli", "have a great day", "have a great day!", "rice", "potatoes", "sauces", "action-made to order"
    ];

    const EXPANDED_STATIONS = [
        "main plate", "global", "stocks",
        "breakfast @home", "@home", "vegan salads", "ovens", "options", "grill special", "stock pot"
    ];

    const ORDERED_STATIONS = [
        "exhibition", "entrée", "entrées", "dim sum", "entrees", "entree", "chicken entree", "beef entree", "fish/seafood entree", "pork", "action", "creations", "creations lto's", "breakfast grill", "chef's corner lto's",
        "chef's corner", "international", "oven", "taco bar", "breakfast", "grill breakfast", "grill", "the grill dinner", "vegetarian entrees", "special salad station",
        "veggie valley", "pasta/noodles", "pizza",
        "simple servings", "vegetables", "miscellaneous", "soups", "soup bar", "specialty salads", "hmc special salad", "salad", "hmc salad", "stg", "dessert", "desserts", "fruit bar", "bakery",

        "salad bar yogurt"
    ];

    const TRUNCATED_STATIONS = [
        "breakfast grill" => 5, "salad bar" => -1, "grill" => 3, "omelet bar" => -1, "breakfast" => 12,
        "breakfast @home" => 3, "breakfast options" => -1, "international" => 6, "burger shack" => -1
    ];

    const COMBINED_STATIONS = [
        "Special Salad Station" =>
        ["hmc salad", "special hot station salad north", "special bar salad-s", "special hot station salad south", "special station salad north", "special station salad south"],
        "Miscellaneous" =>
        ["misc", "-"],
        "Soups" =>
        ["stew", "stews", "soup"],
        "Breakfast Grill" =>
        ["breakfast grill", "grill breakfast"],
        "The Grill Dinner" =>
        ["the grill dinner"],
        "Entrée" =>
        ["entrée", "entrées", "entree", "entrees"],
    ];

    function shouldHide($station){
        return in_array(strtolower($station), SodexoParser::HIDDEN_STATIONS);
    }

    function shouldExpand($station){
        return in_array(strtolower($station), SodexoParser::EXPANDED_STATIONS);
    }

    function truncateAmount($station){
        $station = strtolower($station);
        $screwPHP = SodexoParser::TRUNCATED_STATIONS;
        return isset($screwPHP[$station]) ? $screwPHP[$station] : false;
    }

    function tryFindMerged($station){
        foreach(array_keys(SodexoParser::COMBINED_STATIONS) as $key){
            foreach(SodexoParser::COMBINED_STATIONS[$key] as $value){
                if(strtolower($station) == strtolower($value)){
                    return $key;
                }
            }
        }
        return $station;
    }

    public $json = null;
    public $info = null;
    function fetch(){
        ini_set("memory_limit", "128M");

        $includeTimes = false;// $_GET["returnTimes"];
        $showAll = isset($_GET["showAll"]) ? $_GET["showAll"] : false;

//        $isDev = $_GET["developer"] === "true";
//        if($isDev){
//            echo "<br>$this->url";
//
//        }

        $contents = function_exists("menuWindowFileGetContents")
            ? menuWindowFileGetContents($this->url)
            : file_get_contents($this->url);
        if(!is_string($contents) || strlen($contents) < 1){
            $this->info = array("menu" => array());
            return;
        }
        //print_r($contents);
        //$raw = preg_replace("#^(.+?)$#is", "$1", $contents);
        //echo $raw;
        $raw = preg_replace("#^.*?<div.*?id\s*=\s*['\"]nutData['\"].*?>(.*?)#si", "$1", $contents);


        $raw = preg_replace("#^(.+)\\<\\/div>.*$#s", "$1", $raw);
        $raw = preg_replace("#^(.+)\\<\\/div>.*$#s", "$1", $raw);
        //print_r($raw);
        $this->json = $json = json_decode($raw, true);
        if(!is_array($json)){
            $this->info = array("menu" => array());
            return;
        }

        $info = array();
        for($i = 0; $i < count($json); $i++){
            $day = $json[$i];
            $meals = array();
            foreach($day["dayParts"] as $meal){
                $stations = array();

                $startTime = null;
                $endTime = null;

                foreach($meal["courses"] as $station){
                    // prints chef 's corner

                    $stationName = preg_replace("/ *([^a-zA-Z]) */", "$1", $station["courseName"]);
                    //TODO REMOVE $stationName = preg_replace("/[^a-zA-Z ]/", "$1", preg_replace("/([^a-zA-Z]) */", "$1", $stationName));
                    $stationName = preg_replace("/^ *(.+?) *$/", "$1", $stationName);
                    $stationName = preg_replace("/^(.+?) +SCR$/", "$1", $stationName);

                    if(preg_match("/[^a-z]+$/", $stationName)){
                        $stationName = ucwords(strtolower($stationName));
                        $stationName = str_replace(" And ", " and ", $stationName);
                        $stationName = str_replace(" To ", " to ", $stationName);
                        $stationName = str_replace("Hmc ", "HMC ", $stationName);
                    }

                    if($_GET["developer"] === "true"){
                        //echo "<br> Checking --$stationName--";
                    }
                    if(preg_match("/^[\s-]*$/", $stationName)){
                        //echo " -- FOUND ON -$stationName-<br>";
                        $stationName = "Miscellaneous";
                    }

                    $stationName = $this->tryFindMerged($stationName);

                    $shouldHide = $this->shouldHide($stationName);
                    if($shouldHide && !$showAll){
                        continue;
                    }
                    $menu = array();

                    $truncateAmount = $this->truncateAmount($stationName);
                    $itemCount = 0;

                    foreach($station["menuItems"] as $menuItem){
                        $itemCount++;
                        if($truncateAmount && $itemCount > $truncateAmount){break;}

                        $convertedST = $this->convertTime($menuItem["startTime"]);
                        $convertedET = $this->convertTime($menuItem["endTime"]);
                        if($startTime == null && $convertedST > 0){
                            $startTime = $convertedST;
                        }
                        if($endTime == null && $convertedET > 0){
                            $endTime = $convertedET;
                        }

                        $add = array(
                            "name" => $this->fixString($menuItem["formalName"]),
                            "description" => $this->fixString($menuItem["description"]),
                            "vegan" => $menuItem["isVegan"],
                            "vegetarian" => $menuItem["isVegetarian"],
                            "mindful" => $menuItem["isMindful"],
                            "calories" => intval($menuItem["calories"])
                        );

                        if($includeTimes){
                            $add["startTime"] = $convertedST;
                            $add["endTime"] = $convertedET;
                        }

                        if(strlen($add["name"]) == 0){
                            continue;
                        }

                        $menu[] = $add;
                    }

                    $stationToAdd = array(
                        "station" => $stationName,
                        "stationOriginal" => $station["courseName"],
                        "startTime" => $startTime,
                        "endTime" => $endTime,
                        "menu" => $menu
                    );

                    if(strtolower($stationName) == "miscellaneous" && count($menu) == 0){
                        continue;
                    }


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

                }

                usort($stations, function ($item1, $item2) {
                    $i1 = array_search(strtolower($item1["station"]), SodexoParser::ORDERED_STATIONS);
                    $i2 = array_search(strtolower($item2["station"]), SodexoParser::ORDERED_STATIONS);
                    if($i1 === false){$i1 = 255;}
                    if($i2 === false){$i2 = 255;}
                    if($i1 === $i2){return 0;}

                    return $i1 > $i2 ? 1 : -1;
                });

//                $hours = null;
//                if($this->hall == "hoch"){
//                    //https://hmc.sodexomyway.com/dining-near-me/hours#
//                    // or use format https://scrippsdining.sodexomyway.com/dining-near-me/malott
//                    $mealType = strtolower($meal["dayPartName"]);
//                    $hours = ($mealType == "brunch" ?
//                        "10:45AM - 1:00PM" : ($mealType == "breakfast" ? "7:30AM - 9:30AM" :
//                            ($mealType == "lunch" ? "11:15AM - 1:00PM" : "5:00PM - 7:00PM")));
//                }
//                else if($this->hall == "mallott"){
//                    //https://scrippsdining.sodexomyway.com/dining-near-me/hours#
//                    //https://scrippsdining.sodexomyway.com/dining-near-me/malott
//                    $mealType = strtolower($meal["dayPartName"]);
//                    $hours = ($mealType == "brunch" ?
//                        "10:30AM - 12:45PM" : ($mealType == "breakfast" ? "7:30AM - 10:30AM" :
//                            ($mealType == "lunch" ? "11:15AM - 1:30PM" : "4:45PM - 7:00PM")));
//                }

                $meals[strtolower($meal["dayPartName"])] = array(
                    "meal" => $meal["dayPartName"],
                    "startTime" => $startTime,
                    "endTime" => $endTime,
                    "stations" => $stations
                );

                //TODO: ACTUALLY FETCH DINING HALL HOURS
                if($this->hall == "malott"){
                    //COVID-19
                    if(strtolower($meal["dayPartName"]) == "brunch"){
                        $meals[strtolower($meal["dayPartName"])]["friendlyHours"] = "10:00 am - 12 noon";
                    }
                    else if(strtolower($meal["dayPartName"]) == "dinner"){
                        $meals[strtolower($meal["dayPartName"])]["friendlyHours"] = "4:00 pm - 6:00 pm";
                    }

//                    $dayType = strtolower(date("D", $this->convertTime($day["date"])));
//                    if($dayType == "sat" || $dayType == "sun"){
//                        if(strtolower($meal["dayPartName"]) == "dinner"){
//                            $meals[strtolower($meal["dayPartName"])]["friendlyHours"] = "5:00 pm - 6:30 pm";
//                        }
//                    }
//                    else{
//                        if(strtolower($meal["dayPartName"]) == "dinner"){
//                            $meals[strtolower($meal["dayPartName"])]["friendlyHours"] = "4:45 pm - 7:00 pm";
//                        }
//                        else if(strtolower($meal["dayPartName"]) == "breakfast"){
//                            $meals[strtolower($meal["dayPartName"])]["friendlyHours"] = "7:30 am - 10:00 am";
//                        }
//                    }

                }
                else if($this->hall == "hoch"){
                    $dayType = strtolower(date("D", $this->convertTime($day["date"])));
                    if($dayType == "sat" || $dayType == "sun"){}
                    else{
                        if(strtolower($meal["dayPartName"]) == "breakfast"){
                            $meals[strtolower($meal["dayPartName"])]["friendlyHours"] = "7:30 am - 9:30 am";
                        }
                        if(strtolower($meal["dayPartName"]) == "lunch"){
                            $meals[strtolower($meal["dayPartName"])]["friendlyHours"] = "11:15 am - 1:00 pm";
                        }
                    }
                }
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
        return preg_replace("/^\s*(.*?)\s*$/", "$1", (string)$str);
    }
}
