<?php
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'motor_vehicle_repairs');
define('DB_PORT', '3306');

/* Attempt to connect to MySQL database */
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT);

// Check connection
if ($conn === false) {
    die("ERROR: Could not connect. " . $conn->connect_error);
}

//set time to refresh the token in 'seconds'
$tokenRefreshDuration = 500;

//iSupport Credentials - DEV
$client_username = "pdf";
$client_password = "1234567";
$sap_client = 210;
$base_url = "http://erp01-dev.dc01.kra.go.ke:8000";

//SMS settings 
$smsappname = "mservices";
$smsapppassword = "30814916f35661ac876c47e9de172f8143d3911b876d4760a26af45a6a5f8cad";
$smsappurl = "http://10.150.1.118:8076/sms/send";

//email settings
$emailHost = "10.150.11.11";
$emailPort = 25;
$emailFrom = "transport-noreply@kra.go.ke";
$emailFromName = "KRA Vehicle Repair Portal";
//allow send email - disable (false) when there are issues with email so as not to slow the workflow
$allow_send_email = true;
//max retries for email sending
$maxRetries = 3;
$emailsLimit=10;

$systemUrl = 'https://krahub.kra.go.ke';

//Release Versions
$appVersion = "1.0.0"; //Dec 2025

//AD API credentials
$adBaseUrl = "http://10.153.1.64:8595";

$loginEncKey = '1e500ac261ba47ab727d4a6cb882d3ec';

include 'functions.php';
?>