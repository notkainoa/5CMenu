<?php

require_once __DIR__ . "/../api/BonAppetitWebParser.php";

date_default_timezone_set("America/Los_Angeles");
$date = $argv[1] ?? date("Y-m-d");
$timestamp = strtotime($date . " 00:00:00");
$halls = array(
    "collins" => array("https://collins-cmc.cafebonappetit.com/cafe/collins/{date}/", "https://www.cmc.edu/student-life/residential-life/dining"),
    "mcconnel" => array("https://pitzer.cafebonappetit.com/cafe/mcconnell-bistro/{date}/", "https://www.pitzer.edu/student-life/living-pitzer/dining"),
    "malott" => array("https://scripps.cafebonappetit.com/cafe/malott-dining-commons/{date}/", "https://scripps.cafebonappetit.com/")
);

$failed = false;
$_GET["developer"] = "true";

foreach($halls as $hall => $urls){
    $parser = new BonAppetitWebParser($hall, $urls, $timestamp);
    $parser->fetch();
    $info = $parser->getInfo();
    $itemCount = 0;
    $stationCount = 0;
    foreach(($info["menu"] ?? array()) as $day){
        foreach(($day["meals"] ?? array()) as $meal){
            $stationCount += count($meal["stations"] ?? array());
            foreach(($meal["stations"] ?? array()) as $station){
                $itemCount += count($station["menu"] ?? array());
            }
        }
    }

    $mode = $info["debug"]["mode"] ?? "missing";
    $source = $info["debug"]["source"] ?? "missing";
    $ok = $mode === "bamco" && $stationCount > 0 && $itemCount >= 10 && strpos($source, "cafebonappetit.com") !== false;
    fwrite(STDOUT, sprintf(
        "%s %s mode=%s stations=%d items=%d source=%s\n",
        $ok ? "PASS" : "FAIL",
        $hall,
        $mode,
        $stationCount,
        $itemCount,
        $source
    ));
    $failed = $failed || !$ok;
}

exit($failed ? 1 : 0);
