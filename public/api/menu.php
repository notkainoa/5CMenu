<?php

ini_set("display_errors", "0");
ini_set("log_errors", "1");

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

require_once __DIR__ . "/../../api/menuParser.php";

try{
    $result = run("menu");
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
catch(InvalidArgumentException $error){
    http_response_code(400);
    echo json_encode(array("error" => $error->getMessage()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
catch(Throwable $error){
    error_log("5CMenu API error: " . $error->getMessage());
    http_response_code(502);
    $response = array("error" => "The upstream menu could not be fetched.");
    if(isset($_GET["developer"]) && $_GET["developer"] === "true"){
        $response["detail"] = $error->getMessage();
    }
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
