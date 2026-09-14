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

    const HIDDEN_STATIONS = array(
        "breakfast toppings", "breads, bagels and spreads", "cold cereals", "cold cereal", "cereal",
        "fruits and yogurts", "beverage", "beverages", "build your own sandwich",
        "toppings and condiments", "condiments", "deli bar", "deli", "omelet bar",
        "grill bread", "grill fried side items", "grill fried sides", "breakfast bar",
        "pasta-express", "pasta express"
    );

    const PRESENCE_STATIONS = array("salad bar");

    const JUICE_STATIONS = array("juice and smoothie bar", "juice bar");

    const SWEETS_STATIONS = array("sweets", "bakery", "breakfast bakery", "ovens", "ovens2");

    const EXPANDED_STATIONS = array(
        "main plate", "global", "stocks", "stock pot", "breakfast @home", "@home", "@ home",
        "vegan salads", "ovens", "options", "grill", "grill special", "comfort", "chef's table",
        "herbivore", "oasis", "plant forward"
    );

    const ORDERED_STATIONS = array(
        "chef's table", "main plate", "breakfast", "breakfast @ home", "breakfast @home", "@home", "@ home",
        "breakfast options", "options", "expo", "global", "comfort", "grill", "herbivore", "oasis",
        "plant forward", "simply oasis", "hot cereal", "ovens", "sweets", "stock pot", "stocks"
    );

    const COMBINED_STATIONS = array(
        "ovens" => array("ovens", "ovens2"),
        "sweets" => array("sweets", "chocolate chip cookies"),
        "grill" => array("grill", "grill special")
    );

    const BARE_TOPPINGS = array(
        "spinach", "onion", "onions", "lettuce", "tomato", "tomatoes", "pepper", "peppers",
        "mushroom", "mushrooms", "cinnamon", "raisin", "raisins", "dried cranberry", "dried cranberries",
        "cocoa powder", "brown sugar", "sugar brown", "crushed red pepper", "dried oregano", "oregano",
        "liquid egg", "whole egg", "cheddar jack cheese", "maple syrup", "butter unsalted", "unsalted butter",
        "pickle", "parmesan cheese", "red pepper flakes", "salt", "black pepper", "cream cheese",
        "almond butter", "peanut butter", "honey", "artichoke hearts", "bell pepper", "jalapeno",
        "pickled jalapeno", "sun-dried tomatoes", "flour tortilla", "whipped butter", "chocolate chips",
        "oreo crumbles"
    );

    const BREAKFAST_GRILL_COMPONENTS = array(
        "fried egg", "scrambled eggs", "scrambled egg whites", "cage-free scrambled eggs",
        "whole egg", "liquid egg", "cheddar jack cheese", "flour tortilla", "croissant",
        "italian sausage", "pancetta", "plant-based sausage (morningstar)", "plant-based sausage"
    );

    const GENERIC_SEASONINGS = array(
        "oil", "canola oil", "olive oil", "salt", "pepper", "black pepper", "water", "cooking spray"
    );

    const RECIPE_STAPLES = array(
        "flour", "sugar", "brown sugar", "baking powder", "baking soda", "vanilla extract",
        "pure vanilla extract", "sour cream"
    );

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
        $timeout = function_exists("currentMenuFetchTimeoutSeconds") ? currentMenuFetchTimeoutSeconds() : 45;
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(30, $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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

            $grouped = array();
            $order = array();
            foreach($meal["stations"] as $stationInfo){
                if(!isset($stationInfo["items"]) || count($stationInfo["items"]) < 1){continue;}

                $stationName = isset($stationInfo["station"]) ? $stationInfo["station"] : "Station";
                $canonical = $this->canonicalStationName($stationName);
                if($this->shouldHideStation($canonical, $mealKey)){continue;}

                $mergedKey = $this->mergedStationKey($canonical);
                if(!isset($grouped[$mergedKey])){
                    $grouped[$mergedKey] = array(
                        "station" => $this->prettyStationName($mergedKey),
                        "canonical" => $mergedKey,
                        "items" => array()
                    );
                    $order[] = $mergedKey;
                }
                $grouped[$mergedKey]["items"] = $this->mergeStationMenus($grouped[$mergedKey]["items"], $stationInfo["items"]);
            }

            $stations = array();
            foreach($order as $mergedKey){
                $group = $grouped[$mergedKey];
                $canonical = $group["canonical"];
                $items = $group["items"];
                if(!$this->shouldShowAll()){
                    $items = $this->keepJuiceSpecials($canonical, $items);
                    $items = $this->keepPresenceStation($canonical, $items);
                    $items = $this->dropAlwaysOnWhenFeatured($canonical, $items);
                    $items = $this->foldStationExtras($items);
                    $items = $this->dropBareToppings($canonical, $items);
                }

                $menu = array();
                foreach($items as $item){
                    $publicItem = $this->publicMenuItem($item);
                    if($publicItem == null){continue;}
                    $menu[] = $publicItem;
                }
                if(count($menu) < 1){continue;}
                $stations[] = array(
                    "station" => $group["station"],
                    "stationOriginal" => $mergedKey,
                    "autoCollapse" => !$this->shouldExpandStation($mergedKey),
                    "menu" => $menu
                );
            }

            if(count($stations) < 1){continue;}
            usort($stations, array($this, "compareStations"));
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

        $ingredients = "";
        if(isset($itemRaw["ingredients"]) && is_string($itemRaw["ingredients"])){
            $ingredients = $this->cleanString($itemRaw["ingredients"]);
        }

        $destination[] = array(
            "name" => $name,
            "description" => $description,
            "vegan" => $vegan,
            "vegetarian" => $vegetarian,
            "calories" => $calories,
            "special" => isset($itemRaw["special"]) ? $itemRaw["special"] : null,
            "ingredients" => $ingredients
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

    private function shouldShowAll(){
        if(!isset($_GET["showAll"])){return false;}
        return filter_var($_GET["showAll"], FILTER_VALIDATE_BOOLEAN);
    }

    private function canonicalStationName($name){
        $fixed = strtolower($this->cleanString($name));
        $fixed = str_replace(array("’", "‘", "`"), "'", $fixed);
        $fixed = str_replace("&", "and", $fixed);
        $fixed = preg_replace("/\s+/", " ", $fixed);
        $fixed = str_replace("@ home", "@home", $fixed);
        return trim($fixed);
    }

    private function shouldHideStation($canonical, $mealKey){
        if($this->shouldShowAll()){return false;}
        if(in_array($canonical, self::HIDDEN_STATIONS, true)){
            if(in_array($canonical, array("beverage", "beverages"), true) && $mealKey === "late night"){
                return false;
            }
            return true;
        }
        if(preg_match("/^chef's table\s*:/", $canonical)){return true;}
        if($canonical === "breakfast" && $mealKey !== "breakfast" && $mealKey !== "brunch"){return true;}
        return false;
    }

    private function keepJuiceSpecials($canonical, $items){
        if(!in_array($canonical, self::JUICE_STATIONS, true)){return $items;}
        $featured = array();
        foreach($items as $item){
            if($this->isFeaturedItem($item)){$featured[] = $item;}
        }
        return count($featured) > 0 ? $featured : array();
    }

    private function keepPresenceStation($canonical, $items){
        if(!in_array($canonical, self::PRESENCE_STATIONS, true)){return $items;}

        $featured = array();
        foreach($items as $item){
            if($this->isFeaturedItem($item)){$featured[] = $item;}
        }
        if(count($featured) > 0){return $featured;}

        return array(array(
            "name" => "Self-serve",
            "description" => "",
            "vegan" => false,
            "vegetarian" => false,
            "calories" => 0,
            "special" => null,
            "ingredients" => ""
        ));
    }

    private function dropAlwaysOnWhenFeatured($canonical, $items){
        if(in_array($canonical, self::SWEETS_STATIONS, true)){return $items;}
        $hasFeatured = false;
        foreach($items as $item){
            if($this->isFeaturedItem($item)){$hasFeatured = true; break;}
        }
        if(!$hasFeatured){return $items;}

        $kept = array();
        foreach($items as $item){
            if($this->isAlwaysOnItem($item)){continue;}
            $kept[] = $item;
        }
        return $kept;
    }

    private function dropBareToppings($canonical, $items){
        $kept = array();
        foreach($items as $item){
            $name = isset($item["name"]) ? $item["name"] : "";
            if($this->isBareTopping($name, $canonical)){continue;}
            $kept[] = $item;
        }
        return $kept;
    }

    private function foldStationExtras($items){
        if(count($items) < 1){return $items;}

        $headerIndex = null;
        foreach($items as $index => $item){
            if($this->isBarHeader(isset($item["name"]) ? $item["name"] : "")){
                $headerIndex = $index;
                break;
            }
        }

        if($headerIndex !== null){
            $header = $items[$headerIndex];
            $notes = array();
            foreach($items as $index => $item){
                if($index === $headerIndex){continue;}
                $note = $this->extraNoteText($item);
                if($note !== "" && !$this->textAlreadyCovered($header["description"], $note) && !$this->textAlreadyCovered(implode("; ", $notes), $note)){
                    $notes[] = $note;
                }
            }
            if(count($notes) > 0){
                $header["description"] = $this->joinDescriptions(isset($header["description"]) ? $header["description"] : "", implode(", ", $notes));
            }
            return array($header);
        }

        $dishes = array();
        $pendingPrefix = array();
        $pendingSuffix = array();
        foreach($items as $item){
            if($this->isFoldableExtra($item)){
                if(count($dishes) < 1){$pendingPrefix[] = $item;}
                else{$pendingSuffix[] = $item;}
                continue;
            }
            if(count($pendingSuffix) > 0 && count($dishes) > 0){
                $this->applyNotesToDishes($dishes, $pendingSuffix);
                $pendingSuffix = array();
            }
            $dishes[] = $item;
        }
        $this->applyNotesToDishes($dishes, array_merge($pendingPrefix, $pendingSuffix));
        return $dishes;
    }

    private function applyNotesToDishes(&$dishes, $noteItems){
        if(count($dishes) < 1 || count($noteItems) < 1){return;}

        $notes = array();
        foreach($noteItems as $noteItem){
            $note = $this->extraNoteText($noteItem);
            if($note === "" || $this->isBoilerplateInstruction($note)){continue;}
            $covered = false;
            foreach($dishes as $dish){
                if($this->textAlreadyCovered(isset($dish["description"]) ? $dish["description"] : "", $note)){
                    $covered = true;
                    break;
                }
            }
            if(!$covered){$notes[] = $note;}
        }
        if(count($notes) < 1){return;}

        $joined = implode("; ", $notes);
        foreach($dishes as &$dish){
            $dish["description"] = $this->joinDescriptions(isset($dish["description"]) ? $dish["description"] : "", $joined);
        }
        unset($dish);
    }

    private function extraNoteText($item){
        $name = isset($item["name"]) ? $item["name"] : "";
        $description = isset($item["description"]) ? $item["description"] : "";
        if($this->isBoilerplateInstruction($name) && $description === ""){return "";}
        if(preg_match("/^toppings$/", strtolower($name))){return $description;}
        if($this->isComponentList($name) || $this->isInstruction($name) || $this->isBareTopping($name, "")){
            return $description !== "" ? $name . ": " . $description : $name;
        }
        return $description !== "" ? $name . ": " . $description : $name;
    }

    private function isFoldableExtra($item){
        $name = isset($item["name"]) ? $item["name"] : "";
        return $this->isInstruction($name) || $this->isComponentList($name);
    }

    private function isBarHeader($name){
        $fixed = strtolower($name);
        return preg_match("/\bbuild your own\b/", $fixed)
            || preg_match("/\b(salad bar|pasta bar|parfait bar|fruit salad bar)\b/", $fixed);
    }

    private function isInstruction($name){
        $fixed = strtolower($name);
        return preg_match("/made (fresh )?to order/", $fixed)
            || preg_match("/available upon request/", $fixed)
            || preg_match("/^live grill\b/", $fixed)
            || preg_match("/^toppings$/", $fixed);
    }

    private function isBoilerplateInstruction($text){
        return preg_match("/^(live grill\s*-+\s*)?made (fresh )?to order$/i", trim($text)) === 1;
    }

    private function isComponentList($name){
        $segments = preg_split("/,\s*/", $name);
        $segments = array_values(array_filter(array_map("trim", $segments), function($part){return strlen($part) > 0;}));
        $count = count($segments);
        if($count < 3){return false;}
        $allShort = true;
        foreach($segments as $segment){
            if(strlen($segment) > 50){$allShort = false; break;}
        }
        $cooked = preg_match("/\b(grilled|roasted|steamed|baked|sauteed|sautéed|fried|braised|poached|smoked|charbroil|charred|seared|stuffed|glazed|marinated)\b/i", $name);
        $composedPlate = $cooked && ($count < 4 || preg_match("/\b(chicken|beef|steak|pork|turkey|fish|salmon|cod|tofu|tempeh|potato|potatoes|mashed|rice|gravy|pasta|noodles)\b/i", $name));
        if($composedPlate){return false;}
        if($count >= 4 && $allShort){return true;}
        return $count === 3 && $allShort;
    }

    private function isBareTopping($name, $canonicalStation){
        $fixed = $this->canonicalStationName($name);
        $fixed = preg_replace("/^(add|extra|real|fresh|house-made|house made)\s+/", "", $fixed);
        if(in_array($fixed, self::BARE_TOPPINGS, true)){return true;}
        if($canonicalStation === "breakfast grill" && in_array($fixed, self::BREAKFAST_GRILL_COMPONENTS, true)){return true;}
        return false;
    }

    private function isFeaturedItem($item){
        if(!isset($item["special"])){return false;}
        $value = $item["special"];
        return $value === true || $value === 1 || $value === "1";
    }

    private function isAlwaysOnItem($item){
        if(!isset($item["special"])){return false;}
        $value = $item["special"];
        return $value === false || $value === 0 || $value === "0";
    }

    private function publicMenuItem($item){
        $name = isset($item["name"]) ? $item["name"] : "";
        if(strlen($name) < 1){return null;}

        $description = $this->cleanDescription(isset($item["description"]) ? $item["description"] : "");
        if($description === ""){
            $description = $this->descriptionFromIngredients($name, isset($item["ingredients"]) ? $item["ingredients"] : "");
        }

        return array(
            "name" => $name,
            "description" => $description,
            "vegan" => isset($item["vegan"]) ? boolval($item["vegan"]) : false,
            "vegetarian" => isset($item["vegetarian"]) ? boolval($item["vegetarian"]) : false,
            "calories" => isset($item["calories"]) ? intval($item["calories"]) : 0
        );
    }

    private function cleanDescription($description){
        $description = $this->cleanString($description);
        if($description === ""){return "";}
        if(preg_match("/^\d+(\/\d+)?\s*(cup|each|fl oz|oz|tbsp|tsp)$/i", $description)){return "";}

        $parts = preg_split("/,\s*/", strtolower($description));
        $allSeasoning = count($parts) > 0;
        foreach($parts as $part){
            $part = preg_replace("/^with\s+/", "", trim($part));
            if($part === "" || in_array($part, self::GENERIC_SEASONINGS, true) || preg_match("/cooking spray$/", $part)){continue;}
            $allSeasoning = false;
            break;
        }
        return $allSeasoning ? "" : $description;
    }

    private function descriptionFromIngredients($name, $ingredients){
        $ingredients = $this->cleanString($ingredients);
        if($ingredients === "" || strpos($ingredients, "(") !== false){return "";}

        $parts = preg_split("/,\s*/", $ingredients);
        $kept = array();
        $nameLower = strtolower($name);
        foreach($parts as $part){
            $part = trim($part);
            if($part === ""){continue;}
            $lower = strtolower($part);
            if(in_array($lower, self::GENERIC_SEASONINGS, true)){continue;}
            if(preg_match("/cooking spray$/", $lower)){continue;}
            if(in_array($lower, self::RECIPE_STAPLES, true)){return "";}
            if(preg_match("/\b(\d+\s*(fl oz|oz)|rtc|curate|frozen)\b/", $lower)){continue;}
            if(strlen($part) > 48){continue;}
            if(strpos($nameLower, $lower) !== false){continue;}
            $stem = rtrim($lower, "s");
            if(strlen($stem) >= 3 && strpos($nameLower, $stem) !== false){continue;}
            if($this->ingredientWordsCoveredByName($nameLower, $lower)){continue;}
            $kept[] = $part;
        }

        if(count($kept) < 1 || count($kept) > 4){return "";}
        if(count($parts) >= 8){return "";}

        $pretty = $this->joinList($kept);
        return preg_match("/^with\b/i", $pretty) ? $pretty : "with " . $pretty;
    }

    private function ingredientWordsCoveredByName($nameLower, $ingredientLower){
        $words = preg_split("/[\s-]+/", $ingredientLower);
        $meaningful = 0;
        foreach($words as $word){
            if(strlen($word) < 3){continue;}
            $meaningful++;
            if(strpos($nameLower, $word) === false){return false;}
        }
        return $meaningful > 0;
    }

    private function joinList($parts){
        $count = count($parts);
        if($count === 1){return $parts[0];}
        if($count === 2){return $parts[0] . " and " . $parts[1];}
        return implode(", ", array_slice($parts, 0, $count - 1)) . ", and " . $parts[$count - 1];
    }

    private function joinDescriptions($existing, $extra){
        $existing = trim($existing);
        $extra = trim($extra);
        if($extra === ""){return $existing;}
        if($existing === ""){return $extra;}
        if($this->textAlreadyCovered($existing, $extra)){return $existing;}
        return $existing . "; " . $extra;
    }

    private function textAlreadyCovered($haystack, $needle){
        $hay = strtolower($this->cleanString($haystack));
        $need = strtolower($this->cleanString($needle));
        if($hay === "" || $need === ""){return false;}
        if(strpos($hay, $need) !== false){return true;}
        $prefix = substr($need, 0, min(40, strlen($need)));
        return strlen($prefix) >= 20 && strpos($hay, $prefix) !== false;
    }

    private function mergedStationKey($canonical){
        foreach(self::COMBINED_STATIONS as $key => $values){
            if(in_array($canonical, $values, true)){return $key;}
        }
        return $canonical;
    }

    private function prettyStationName($canonical){
        $pretty = ucwords($canonical);
        $pretty = str_replace(" And ", " and ", $pretty);
        $pretty = str_replace("@home", "@Home", $pretty);
        $pretty = str_replace("@ Home", "@Home", $pretty);
        return $pretty;
    }

    private function shouldExpandStation($canonical){
        return in_array($canonical, self::EXPANDED_STATIONS, true);
    }

    private function mergeStationMenus($existing, $incoming){
        foreach($incoming as $item){
            $duplicate = false;
            foreach($existing as $current){
                if(strtolower($current["name"]) === strtolower($item["name"])){
                    $duplicate = true;
                    break;
                }
            }
            if(!$duplicate){$existing[] = $item;}
        }
        return $existing;
    }

    private function compareStations($item1, $item2){
        $i1 = array_search($item1["stationOriginal"], self::ORDERED_STATIONS, true);
        $i2 = array_search($item2["stationOriginal"], self::ORDERED_STATIONS, true);
        if($i1 === false){$i1 = 1000;}
        if($i2 === false){$i2 = 1000;}
        if($i1 === $i2){return 0;}
        return $i1 > $i2 ? 1 : -1;
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
