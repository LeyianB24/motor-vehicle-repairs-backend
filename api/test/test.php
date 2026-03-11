<?php
date_default_timezone_set("Africa/Nairobi");
$datestamp = date("Y-m-d");
$timestamp = date("Y-m-d H:i:s");
$feedback = '';

try {
    //log action
//        $LoggerFile = "mylogs-$datestamp.log";  //create a log file - local
//    $LoggerFile = "/var/www/html/mvr2/mvr-apis/test/mylog-$datestamp.log";  //create a log file - dev server
    $LoggerFile = "/var/www/html/mvr/mvr-apis/test/mylog-$datestamp.log";  //create a log file - QA server
    $file = fopen($LoggerFile, "a");
    fwrite($file, " Record created at $timestamp\n");
    fwrite($file, "\r");
    fclose($file);
    $feedback = "Record created";
} catch (TypeError $e) {
    error_log($e);
    $feedback = "Type error: " . $e->getMessage() . "\n";

} catch (Exception $exception) {
    error_log($exception);
    $feedback = "Exception: " . $exception->getMessage() . "\n";
}

echo "Feedback: " . $feedback;