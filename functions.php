<?php

date_default_timezone_set("Africa/Nairobi");
if (!isset($timestamp)) {
    date_default_timezone_set("Africa/Nairobi");
    $timestamp = date("Y-m-d H:i:s");
}


function isTokenValid($token): bool
{
    global $conn, $timestamp;
    //check if token is valid
    $stmt = $conn->prepare("SELECT * FROM tokens WHERE token = ? AND status='active' AND expires_on > ?");
    $stmt->bind_param("ss", $token, $timestamp);
    $stmt->execute();
    $resultsToken = $stmt->get_result();

    if ($resultsToken->num_rows == 1) {
        return true;
    } else {
        return false;
    }
}


function refreshToken($token): bool
{
    global $conn, $timestamp, $tokenRefreshDuration;
    $newExpiryTimestamp = date("Y-m-d H:i:s", strtotime($timestamp) + $tokenRefreshDuration); // Add seconds based on the duration set in '../config.php'
    // Update token's last used time &  expiry time
    $stmtUpdate = $conn->prepare("UPDATE tokens SET last_used= ?, expires_on=? WHERE token = ?");
    $stmtUpdate->bind_param("sss", $timestamp, $newExpiryTimestamp, $token);
    if ($stmtUpdate->execute()) {
        return true;
    } else {
        return false;
    }
}

$ip_address = getIp();

function getProperties($table, $column, $key, $value)
{
    global $conn;
    $sql22 = "SELECT $column FROM $table WHERE $key='$value'";
    if ($result22 = $conn->query($sql22)) {
        if ($result22->num_rows == 1) {
            while ($row = $result22->fetch_array()) {
                $property_value = $row[$column];
            }
        } else {
            $property_value = '';
        }
    } else {
        $property_value = '';
    }
    return $property_value;
}

function countRecords($table, $column, $value)
{
    global $conn;
    $queryS = "SELECT * FROM $table WHERE $column='$value'";
    $resultsS = mysqli_query($conn, $queryS);
    $countS = mysqli_num_rows($resultsS);
    return $countS;
}


function validateDate($date)
{
    $format = 'Y-m-d';
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function logAction($user, $userid, $token, $responsecode, $action): void
{
    global $timestamp, $conn, $ip_address, $appVersion;
    $stmt = $conn->prepare("INSERT INTO logs 
    (user, user_id, action, response_code, token, ip_address, time_stamp, app_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
        "ssssssss",
        $user,
        $userid,
        $action,
        $responsecode,
        $token,
        $ip_address,
        $timestamp,
        $appVersion
    );
    if ($stmt->execute()) {

    } else {
    }

}

function logActionToFile($user, $sessionid, $action)
{
    global $ip_address;
    date_default_timezone_set("Africa/Nairobi");
    $timestamp = date("Y-m-d H:i:s");
    $datestamp = date("Y-m-d");

    // Get current page URL
    $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $currentURL = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . $_SERVER['QUERY_STRING'];

    // Get server related info
    $referrer_url = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/';
    $user_agent = $_SERVER['HTTP_USER_AGENT'];

    try {
        //log action
        $LoggerFile = "../mvr-storage/logs/actions-$datestamp.log";  //saves in storage folder outside the application
        $file = fopen($LoggerFile, "a");
        fwrite($file, "$timestamp User: $user Session: $sessionid Current URL: $currentURL Referrer URL: $referrer_url User Agent: $user_agent IP Address: $ip_address Action: $action");
        fwrite($file, "\r\r");
        fclose($file);
    } catch (TypeError $e) {
        error_log($e);
        echo "Type error: " . $e->getMessage() . "\n";
    } catch (Exception $exception) {
        error_log($exception);
        echo "Exception: " . $exception->getMessage() . "\n";
    }

}

function getIp()
{ //rev Aug 2025

    if (isset($_SERVER["HTTP_CLIENT_IP"])) {
        // Check for IP address from shared Internet
        $ip = $_SERVER["HTTP_CLIENT_IP"];
    } elseif (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
        // Check for the proxy user
        $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
    } elseif (isset($_SERVER["REMOTE_ADDR"])) {
        // Check if REMOTE_ADDR is available (might not be available in cron)
        $ip = $_SERVER["REMOTE_ADDR"];
    } else {
        // Use a default IP address or handle the case as needed
        $ip = 'Unknown'; // Default to localhost
    }

    return $ip;
}

?>