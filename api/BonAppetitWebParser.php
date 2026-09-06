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

class BonAppetitWebParser implements DiningHallParser{

    private $hall;
    private $sourceURLs;
    private $startTime;

    public $info = null;
    public $modeUsed = null;
    public $sourceUsed = null;

    function __construct($hall, $sourceURLs, $startTime){
        $this->hall = strtolower($hall);
        $this->sourceURLs = $sourceURLs;
        $this->startTime = $startTime ? round($startTime) : time();
    }

    function fetch(){
        $dateString = date("Y-m-d", $this->startTime);
        $defaultInfo = $this->buildInfo($dateString, array());

        foreach($this->sourceURLs as $sourceURLTemplate){
            $sourceURL = str_replace("{date}", $dateString, $sourceURLTemplate);
            $contents = $this->fetchURL($sourceURL);
            if($contents == null || strlen($contents) < 1){continue;}

            $candidateURLs = $this->extractCandidateURLs($contents, $sourceURL, $dateString);
            array_unshift($candidateURLs, $sourceURL);
            $candidateURLs = array_values(array_unique($candidateURLs));

            foreach($candidateURLs as $candidateURL){
                $candidateContents = ($candidateURL === $sourceURL) ? $contents : $this->fetchURL($candidateURL);
                if($candidateContents == null || strlen($candidateContents) < 1){continue;}

                $mode = null;
                $meals = $this->extractMeals($candidateContents, $dateString, $mode);
                if(count($meals) < 1){continue;}

                $this->modeUsed = $mode;
                $this->sourceUsed = $candidateURL;
                error_log("BonAppetitWebParser[$this->hall] mode=$mode source=$candidateURL");

                $this->info = $this->buildInfo($dateString, $meals);
                if(isset($_GET["developer"]) && $_GET["developer"] === "true"){
                    $this->info["debug"] = array("mode" => $mode, "source" => $candidateURL);
                }
                return;
            }
        }

        $this->modeUsed = "empty";
        $this->sourceUsed = null;
        error_log("BonAppetitWebParser[$this->hall] mode=empty source=none");
        $this->info = $defaultInfo;
        if(isset($_GET["developer"]) && $_GET["developer"] === "true"){
            $this->info["debug"] = array("mode" => "empty", "source" => null);
        }
    }

    function getInfo(){
        return $this->info;
    }

    private function fetchURL($url){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: */*",
            "Accept-Language: en-us"
        ]);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 128000);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $raw !== false && $status >= 200 && $status < 400 ? $raw : null;
    }

    private function extractCandidateURLs($html, $baseURL, $dateString){
        $candidates = array();

        if(!preg_match_all('/(?:href|data-menu-url|data-href)\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)){
            return $candidates;
        }

        foreach($matches[1] as $hrefRaw){
            $href = trim(html_entity_decode($hrefRaw));
            if(strlen($href) < 1 || strpos($href, "javascript:") === 0 || strpos($href, "#") === 0){continue;}

            $lower = strtolower($href);
            $seemsRelevant =
                strpos($lower, "cafebonappetit") !== false ||
                strpos($lower, "menu") !== false ||
                strpos($lower, "dining") !== false;
            if(!$seemsRelevant){continue;}

            $resolved = $this->resolveURL($baseURL, $href);
            if($resolved == null){continue;}
            $parts = parse_url($resolved);
            $path = isset($parts["path"]) ? $parts["path"] : "";
            if(preg_match('/\.(?:css|js|jpe?g|png|gif|svg|webp|woff2?|ico)(?:$|\?)/i', $path)){continue;}

            if(isset($parts["host"]) && preg_match('/(^|\.)cafebonappetit\.com$/i', $parts["host"])){
                $resolved = $this->addDateToCafeURL($resolved, $dateString);
            }
            $candidates[] = $resolved;
        }

        return array_values(array_unique($candidates));
    }

    private function addDateToCafeURL($url, $dateString){
        $parts = parse_url($url);
        if(!isset($parts["path"]) || !preg_match('#^(/cafe/[^/]+/)(?:\d{4}-\d{2}-\d{2}/?)?$#', $parts["path"], $matches)){
            return $url;
        }

        $result = $parts["scheme"] . "://" . $parts["host"];
        if(isset($parts["port"])){$result .= ":" . $parts["port"];}
        $result .= $matches[1] . $dateString . "/";
        return $result;
    }

    private function resolveURL($baseURL, $target){
        if(preg_match('/^https?:\/\//i', $target)){return $target;}
        if(strpos($target, "//") === 0){return "https:" . $target;}

        $base = parse_url($baseURL);
        if(!isset($base["scheme"]) || !isset($base["host"])){return null;}

        $scheme = $base["scheme"];
        $host = $base["host"];
        $port = isset($base["port"]) ? ":" . $base["port"] : "";
        $path = isset($base["path"]) ? $base["path"] : "/";

        if(strpos($target, "/") === 0){
            return "$scheme://$host$port$target";
        }

        $dir = preg_replace('/\/[^\/]*$/', '/', $path);
        return "$scheme://$host$port$dir$target";
    }

    private function extractMeals($html, $dateString, &$mode){
        $meals = $this->extractBamcoMeals($html, $dateString);
        if(count($meals) > 0){
            $normalized = $this->normalizeMeals($meals, $dateString);
            if(count($normalized) > 0){
                $mode = "bamco";
                return $normalized;
            }
        }

        $meals = $this->extractJSONStateMeals($html, $dateString);
        if(count($meals) > 0){
            $normalized = $this->normalizeMeals($meals, $dateString);
            if(count($normalized) > 0){
                $mode = "json-state";
                return $normalized;
            }
        }

        $meals = $this->extractDOMMeals($html, $dateString);
        if(count($meals) > 0){
            $normalized = $this->normalizeMeals($meals, $dateString);
            if(count($normalized) > 0){
                $mode = "dom";
                return $normalized;
            }
        }

        $mode = "none";
        return array();
    }

    private function extractBamcoMeals($html, $dateString){
        $menuItems = $this->extractBamcoMenuItems($html);
        $dayparts = $this->extractBamcoDayparts($html);

        if(count($dayparts) < 1){return array();}

        $meals = array();
        foreach($dayparts as $daypart){
            if(!is_array($daypart)){continue;}
            $mealLabel = isset($daypart["label"]) ? $daypart["label"] : (isset($daypart["name"]) ? $daypart["name"] : "Unspecified");
            $mealKey = $this->normalizeMealKey($mealLabel);
            if(!isset($meals[$mealKey])){
                $meals[$mealKey] = array(
                    "meal" => $mealLabel,
                    "starttime" => isset($daypart["starttime"]) ? $daypart["starttime"] : null,
                    "endtime" => isset($daypart["endtime"]) ? $daypart["endtime"] : null,
                    "starttime_formatted" => isset($daypart["starttime_formatted"]) ? $daypart["starttime_formatted"] : null,
                    "endtime_formatted" => isset($daypart["endtime_formatted"]) ? $daypart["endtime_formatted"] : null,
                    "stations" => array()
                );
            }

            $stations = isset($daypart["stations"]) ? $daypart["stations"] : array();
            foreach($stations as $station){
                $stationLabel = isset($station["label"]) ? $station["label"] : (isset($station["name"]) ? $station["name"] : "Station");
                $stationKey = strtolower(trim($stationLabel));
                if(!isset($meals[$mealKey]["stations"][$stationKey])){
                    $meals[$mealKey]["stations"][$stationKey] = array("station" => $stationLabel, "items" => array());
                }

                $ids = isset($station["items"]) ? $station["items"] : array();
                foreach($ids as $itemID){
                    $item = null;
                    if(isset($menuItems[$itemID])){$item = $menuItems[$itemID];}
                    else if(isset($menuItems[strval($itemID)])){$item = $menuItems[strval($itemID)];}
                    else if(is_array($itemID)){$item = $itemID;}

                    if(!is_array($item)){continue;}
                    $this->appendItem($meals[$mealKey]["stations"][$stationKey]["items"], $item);
                }
            }
        }

        return $meals;
    }

    private function extractBamcoMenuItems($html){
        $marker = "Bamco.menu_items";
        $jsonObj = $this->extractAssignmentObject($html, $marker);
        if($jsonObj == null){return array();}

        $decoded = json_decode($jsonObj, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function extractBamcoDayparts($html){
        $dayparts = array();
        if(!preg_match_all('/Bamco\.dayparts\[[^\]]+\]\s*=\s*/', $html, $matches, PREG_OFFSET_CAPTURE)){return $dayparts;}

        foreach($matches[0] as $match){
            $offset = $match[1] + strlen($match[0]);
            $firstBrace = strpos($html, "{", $offset);
            if($firstBrace === false){continue;}
            $objectRaw = $this->extractBalancedObject($html, $firstBrace);
            if($objectRaw == null){continue;}

            $decoded = json_decode($objectRaw, true);
            if(is_array($decoded)){$dayparts[] = $decoded;}
        }
        return $dayparts;
    }

    private function extractJSONStateMeals($html, $dateString){
        $objects = array();

        if(preg_match_all('/<script[^>]+type=["\']application\/json["\'][^>]*>(.*?)<\/script>/is', $html, $jsonScripts)){
            foreach($jsonScripts[1] as $script){
                $decoded = json_decode(trim($script), true);
                if(is_array($decoded)){$objects[] = $decoded;}
            }
        }

        $stateMarkers = array("window.__PRELOADED_STATE__", "window.__INITIAL_STATE__", "__NEXT_DATA__");
        foreach($stateMarkers as $marker){
            $objRaw = $this->extractAssignmentObject($html, $marker);
            if($objRaw == null){continue;}
            $decoded = json_decode($objRaw, true);
            if(is_array($decoded)){$objects[] = $decoded;}
        }

        if(count($objects) < 1){return array();}

        $dayparts = array();
        $menuItems = array();
        foreach($objects as $obj){
            $this->walkJSONState($obj, $dayparts, $menuItems);
        }

        if(count($dayparts) < 1){return array();}
        return $this->buildMealsFromDayparts($dayparts, $menuItems);
    }

    private function walkJSONState($node, &$dayparts, &$menuItems){
        if(!is_array($node)){return;}

        if(isset($node["menu_items"]) && is_array($node["menu_items"])){
            $menuItems = array_merge($menuItems, $node["menu_items"]);
        }
        if(isset($node["menuItems"]) && is_array($node["menuItems"])){
            $menuItems = array_merge($menuItems, $node["menuItems"]);
        }
        if(isset($node["items"]) && is_array($node["items"]) && $this->isAssoc($node["items"])){
            $menuItems = array_merge($menuItems, $node["items"]);
        }

        if(isset($node["dayparts"]) && is_array($node["dayparts"])){
            foreach($node["dayparts"] as $daypart){
                if(is_array($daypart) && isset($daypart["stations"])){$dayparts[] = $daypart;}
            }
        }
        if(isset($node["dayParts"]) && is_array($node["dayParts"])){
            foreach($node["dayParts"] as $daypart){
                if(is_array($daypart) && isset($daypart["stations"])){$dayparts[] = $daypart;}
            }
        }
        if(isset($node["stations"]) && (isset($node["label"]) || isset($node["name"]))){
            $dayparts[] = $node;
        }

        foreach($node as $value){
            $this->walkJSONState($value, $dayparts, $menuItems);
        }
    }

    private function buildMealsFromDayparts($dayparts, $menuItems){
        $meals = array();

        foreach($dayparts as $daypart){
            if(!is_array($daypart)){continue;}
            $mealLabel = isset($daypart["label"]) ? $daypart["label"] : (isset($daypart["name"]) ? $daypart["name"] : "Unspecified");
            $mealKey = $this->normalizeMealKey($mealLabel);

            if(!isset($meals[$mealKey])){
                $meals[$mealKey] = array(
                    "meal" => $mealLabel,
                    "starttime" => isset($daypart["starttime"]) ? $daypart["starttime"] : null,
                    "endtime" => isset($daypart["endtime"]) ? $daypart["endtime"] : null,
                    "starttime_formatted" => isset($daypart["starttime_formatted"]) ? $daypart["starttime_formatted"] : null,
                    "endtime_formatted" => isset($daypart["endtime_formatted"]) ? $daypart["endtime_formatted"] : null,
                    "stations" => array()
                );
            }

            $stations = isset($daypart["stations"]) ? $daypart["stations"] : array();
            foreach($stations as $station){
                if(!is_array($station)){continue;}
                $stationLabel = isset($station["label"]) ? $station["label"] : (isset($station["name"]) ? $station["name"] : "Station");
                $stationKey = strtolower(trim($stationLabel));
                if(!isset($meals[$mealKey]["stations"][$stationKey])){
                    $meals[$mealKey]["stations"][$stationKey] = array("station" => $stationLabel, "items" => array());
                }

                $items = isset($station["items"]) ? $station["items"] : array();
                foreach($items as $itemRef){
                    $item = null;
                    if(is_array($itemRef)){$item = $itemRef;}
                    else if(isset($menuItems[$itemRef])){$item = $menuItems[$itemRef];}
                    else if(isset($menuItems[strval($itemRef)])){$item = $menuItems[strval($itemRef)];}
                    if(!is_array($item)){continue;}
                    $this->appendItem($meals[$mealKey]["stations"][$stationKey]["items"], $item);
                }
            }
        }

        return $meals;
    }

    private function extractDOMMeals($html, $dateString){
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $query = "//*[contains(concat(' ', normalize-space(@class), ' '), ' site-panel__daypart-item-title ') or contains(concat(' ', normalize-space(@class), ' '), ' daypart-item-title ') or contains(concat(' ', normalize-space(@class), ' '), ' item-name ')][ancestor::*[contains(@class,'daypart')]]";
        $nodes = $xpath->query($query);

        $meals = array();
        if(!$nodes){return $meals;}

        foreach($nodes as $node){
            $text = $this->cleanString($node->textContent);
            if(strlen($text) < 2 || strlen($text) > 120){continue;}

            $mealLabel = $this->findMealLabelForNode($xpath, $node);
            $mealKey = $this->normalizeMealKey($mealLabel);

            if(!isset($meals[$mealKey])){
                $meals[$mealKey] = array("meal" => $mealLabel, "stations" => array());
            }

            $stationLabel = $this->findStationLabelForNode($xpath, $node);
            $stationKey = strtolower(trim($stationLabel));
            if(!isset($meals[$mealKey]["stations"][$stationKey])){
                $meals[$mealKey]["stations"][$stationKey] = array("station" => $stationLabel, "items" => array());
            }

            $item = array("label" => $text, "description" => "", "nutrition" => array("kcal" => 0));
            $this->appendItem($meals[$mealKey]["stations"][$stationKey]["items"], $item);
        }

        return $meals;
    }

    private function findMealLabelForNode($xpath, $node){
        $headingNode = $xpath->query("preceding::*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5][1]", $node)->item(0);
        if($headingNode != null){
            $headingText = strtolower($this->cleanString($headingNode->textContent));
            if(strpos($headingText, "breakfast") !== false){return "Breakfast";}
            if(strpos($headingText, "brunch") !== false){return "Brunch";}
            if(strpos($headingText, "lunch") !== false){return "Lunch";}
            if(strpos($headingText, "dinner") !== false){return "Dinner";}
            if(strpos($headingText, "late night") !== false){return "Late Night";}
        }

        $container = $xpath->query("ancestor::*[contains(@class,'daypart')][1]", $node)->item(0);
        if($container != null){
            $title = $xpath->query(".//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5][1]", $container)->item(0);
            if($title != null){
                $titleText = strtolower($this->cleanString($title->textContent));
                if(strpos($titleText, "breakfast") !== false){return "Breakfast";}
                if(strpos($titleText, "brunch") !== false){return "Brunch";}
                if(strpos($titleText, "lunch") !== false){return "Lunch";}
                if(strpos($titleText, "dinner") !== false){return "Dinner";}
                if(strpos($titleText, "late night") !== false){return "Late Night";}
            }
        }

        return "Unspecified";
    }

    private function findStationLabelForNode($xpath, $node){
        $stationContainer = $xpath->query("ancestor::*[contains(@class,'station')][1]", $node)->item(0);
        if($stationContainer != null){
            $labelNode = $xpath->query(".//*[self::h2 or self::h3 or self::h4 or self::strong][1]", $stationContainer)->item(0);
            if($labelNode != null){
                $label = $this->cleanString($labelNode->textContent);
                if(strlen($label) > 0){return $label;}
            }
        }
        return "Station";
    }

    private function normalizeMeals($meals, $dateString){
        $normalized = array();
        foreach($meals as $mealKey => $meal){
            if(!isset($meal["stations"]) || count($meal["stations"]) < 1){continue;}

            $mealLabel = isset($meal["meal"]) ? $meal["meal"] : ucwords($mealKey);
            $times = $this->mealTimes($dateString, $mealKey, $meal);

            $stations = array();
            foreach($meal["stations"] as $stationInfo){
                if(!isset($stationInfo["items"]) || count($stationInfo["items"]) < 1){continue;}

                $stationName = isset($stationInfo["station"]) ? $stationInfo["station"] : "Station";
                $stationOriginal = strtolower(trim($stationName));
                $prettyStation = ucwords($stationName);
                $prettyStation = str_replace(" And ", " and ", $prettyStation);

                $menu = array();
                foreach($stationInfo["items"] as $item){
                    $name = isset($item["name"]) ? $item["name"] : "";
                    if(strlen($name) < 1){continue;}
                    $menu[] = array(
                        "name" => $name,
                        "description" => isset($item["description"]) ? $item["description"] : "",
                        "vegan" => isset($item["vegan"]) ? boolval($item["vegan"]) : false,
                        "vegetarian" => isset($item["vegetarian"]) ? boolval($item["vegetarian"]) : false,
                        "calories" => isset($item["calories"]) ? intval($item["calories"]) : 0
                    );
                }

                if(count($menu) < 1){continue;}
                $stations[] = array(
                    "station" => $prettyStation,
                    "stationOriginal" => $stationOriginal,
                    "autoCollapse" => true,
                    "menu" => $menu
                );
            }

            if(count($stations) < 1){continue;}
            $normalized[$mealKey] = array(
                "meal" => $mealLabel,
                "startTime" => $times[0],
                "endTime" => $times[1],
                "stations" => $stations
            );
        }

        return $normalized;
    }

    private function appendItem(&$destination, $itemRaw){
        if(isset($itemRaw["special"]) && !$itemRaw["special"]){return;}

        $name = isset($itemRaw["label"]) ? $itemRaw["label"] : (isset($itemRaw["name"]) ? $itemRaw["name"] : "");
        $name = ucwords($this->cleanString($name));
        if(strlen($name) < 1){return;}

        $description = isset($itemRaw["description"]) ? $this->cleanString($itemRaw["description"]) : "";
        $calories = 0;
        if(isset($itemRaw["nutrition"]["kcal"])){$calories = intval($itemRaw["nutrition"]["kcal"]);}
        else if(isset($itemRaw["kcal"])){$calories = intval($itemRaw["kcal"]);}

        $vegan = false;
        $vegetarian = false;
        if(isset($itemRaw["cor_icon"]) && is_array($itemRaw["cor_icon"])){
            foreach($itemRaw["cor_icon"] as $icon){
                $iconFixed = strtolower($icon);
                if($iconFixed == "vegan"){$vegan = true; $vegetarian = true;}
                if($iconFixed == "vegetarian"){$vegetarian = true;}
            }
        }
        if(isset($itemRaw["vegan"])){$vegan = boolval($itemRaw["vegan"]); if($vegan){$vegetarian = true;}}
        if(isset($itemRaw["vegetarian"])){$vegetarian = boolval($itemRaw["vegetarian"]); }

        foreach($destination as $existing){
            if(strtolower($existing["name"]) === strtolower($name)){return;}
        }

        $destination[] = array(
            "name" => $name,
            "description" => $description,
            "vegan" => $vegan,
            "vegetarian" => $vegetarian,
            "calories" => $calories
        );
    }

    private function normalizeMealKey($mealLabel){
        $fixed = strtolower($this->cleanString($mealLabel));
        if(strpos($fixed, "breakfast") !== false){return "breakfast";}
        if(strpos($fixed, "brunch") !== false){return "brunch";}
        if(strpos($fixed, "lunch") !== false){return "lunch";}
        if(strpos($fixed, "dinner") !== false){return "dinner";}
        if(strpos($fixed, "late") !== false && strpos($fixed, "night") !== false){return "late night";}
        return $fixed == "" ? "unspecified" : $fixed;
    }

    private function defaultMealTimes($dateString, $mealKey){
        $ranges = array(
            "breakfast" => array("07:00", "10:00"),
            "brunch" => array("10:00", "14:00"),
            "lunch" => array("11:00", "14:30"),
            "dinner" => array("17:00", "20:00"),
            "late night" => array("20:00", "23:30"),
            "unspecified" => array("00:00", "23:59")
        );

        $chosen = isset($ranges[$mealKey]) ? $ranges[$mealKey] : $ranges["unspecified"];
        return array(
            $this->convertDateAndTime($dateString, $chosen[0]),
            $this->convertDateAndTime($dateString, $chosen[1])
        );
    }

    private function mealTimes($dateString, $mealKey, $meal){
        if(isset($meal["starttime_formatted"], $meal["endtime_formatted"]) && $meal["starttime_formatted"] !== "" && $meal["endtime_formatted"] !== ""){
            return array(
                $this->convertDateAndMealTime($dateString, $meal["starttime_formatted"], $mealKey),
                $this->convertDateAndMealTime($dateString, $meal["endtime_formatted"], $mealKey)
            );
        }
        if(isset($meal["starttime"], $meal["endtime"]) && $meal["starttime"] !== "" && $meal["endtime"] !== ""){
            return array(
                $this->convertDateAndMealTime($dateString, $meal["starttime"], $mealKey),
                $this->convertDateAndMealTime($dateString, $meal["endtime"], $mealKey)
            );
        }
        return $this->defaultMealTimes($dateString, $mealKey);
    }

    private function convertDateAndMealTime($dateString, $time, $mealKey){
        $timestamp = $this->convertDateAndTime($dateString, $time);
        if(($mealKey === "dinner" || $mealKey === "late night") && intval(date("G", $timestamp)) < 12){
            $timestamp += 12 * 60 * 60;
        }
        return $timestamp;
    }

    private function convertDateAndTime($date, $time){
        date_default_timezone_set("America/Los_Angeles");
        $dateTimeRaw = "$date $time";
        $timeResult = strtotime($dateTimeRaw);
        return $timeResult === false ? $this->startTime : $timeResult;
    }

    private function buildInfo($dateString, $meals){
        $menu = array(
            array(
                "date" => $dateString,
                "time" => $this->convertDateAndTime($dateString, "00:00"),
                "meals" => $meals
            )
        );
        return array("menu" => $menu);
    }

    private function extractAssignmentObject($contents, $marker){
        $assignmentPattern = '/' . preg_quote($marker, '/') . '\s*=\s*/';
        if(!preg_match($assignmentPattern, $contents, $matches, PREG_OFFSET_CAPTURE)){return null;}

        $assignmentEnd = $matches[0][1] + strlen($matches[0][0]);
        $bracePos = strpos($contents, "{", $assignmentEnd);
        if($bracePos === false){return null;}

        return $this->extractBalancedObject($contents, $bracePos);
    }

    private function extractBalancedObject($text, $startPos){
        $length = strlen($text);
        if($startPos >= $length || $text[$startPos] !== "{"){return null;}

        $depth = 0;
        $inString = false;
        $stringQuote = "";
        $escaped = false;

        for($i = $startPos; $i < $length; $i++){
            $char = $text[$i];

            if($inString){
                if($escaped){$escaped = false; continue;}
                if($char === "\\"){$escaped = true; continue;}
                if($char === $stringQuote){$inString = false; $stringQuote = "";}
                continue;
            }

            if($char === '"' || $char === "'"){
                $inString = true;
                $stringQuote = $char;
                continue;
            }

            if($char === "{"){$depth++;}
            if($char === "}"){
                $depth--;
                if($depth === 0){
                    return substr($text, $startPos, $i - $startPos + 1);
                }
            }
        }

        return null;
    }

    private function cleanString($str){
        $decoded = html_entity_decode($str, ENT_QUOTES | ENT_HTML5);
        $decoded = preg_replace("/\s+/", " ", $decoded);
        return trim($decoded);
    }

    private function isAssoc($arr){
        if(!is_array($arr)){return false;}
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
