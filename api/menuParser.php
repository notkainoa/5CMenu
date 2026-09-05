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

include_once "SodexoParser.php";
include_once "BonAppetitParser.php";
include_once "BonAppetitWebParser.php";
include_once "PomonaParser.php";
include_once "PomonaJSONParser.php";
include_once "DatabaseMenuParser.php";

function param($key, $default = NULL){
    return isset($_POST[$key]) ? $_POST[$key] : (isset($_GET[$key]) ? $_GET[$key] : $default);
}

function run($action){
    $startTime = param("startTime");
    date_default_timezone_set("America/Los_Angeles");
    $startDateParam = param("startDate", param("date"));

    if(!$startTime){
        $explodedStartDate = $startDateParam == null ? [] : preg_split("/[^0-9]+/", $startDateParam);
        $startTime = count($explodedStartDate) < 3 ? $startTime : mktime(0, 0, 0, $explodedStartDate[1], $explodedStartDate[2], $explodedStartDate[0]);
    }

    $startDate = date('m/d/Y', $startTime);
    $startDateBonAppetit = date('Y-m-d', $startTime);
    $diningHall = strtolower(param("diningHall"));
    if($diningHall == "mallot" || $diningHall == "mallott"){
        $diningHall = "malott";
    }
    $parser = null;

    $allowCheckDatabase = strtolower(param("source")) != "sodexo";
    $shouldCheckDatabase = strtolower(param("source")) == "database";

    // Note: This check is for internal stuff, it doesn't have anything to do with getting the menu!
    if($shouldCheckDatabase || $allowCheckDatabase){
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
                "https://malottcommons.cafebonappetit.com/",
                "https://www.scrippscollege.edu/dining"
            ), $startTime);
            //old menuid 288
            //11082
            break;
        case "mcconnel":
            $parser = new BonAppetitWebParser("mcconnel", array(
                "https://pitzer.cafebonappetit.com/cafe/mcconnell/",
                "https://www.pitzer.edu/student-life/living-pitzer/dining"
            ), $startTime);
            break;
        case "collins":
            $parser = new BonAppetitWebParser("collins", array(
                "https://collins-cmc.cafebonappetit.com/cafe/collins/",
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

    $parser->fetch();
    return $parser->getInfo();
}