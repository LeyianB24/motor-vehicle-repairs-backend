<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Respond to preflight request
    http_response_code(200);
    exit;
}

require '../config.php';

$response_array = [];
$filterregistration = $error = $message = $output = "";
global $conn;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {


    //process Actual API
    try {
        $sql = "SELECT * FROM vehicles ORDER BY id DESC";
        if (isset($_GET['id'])) {
            $vehicleid = $_GET['id'];
            $sql = "SELECT * FROM vehicles WHERE id=$vehicleid";
        }
        if (isset($_GET['region'])) {
            $region = $_GET['region'];
            $sql = "SELECT * FROM vehicles WHERE region='$region'";
        }
        if (isset($_GET['registration'])) {
            $filterregistration = $_GET['registration'];
            $sql = "SELECT * FROM vehicles WHERE registration='$filterregistration'";
        }

        $result = $conn->query($sql);
        $tickets = []; // Initialize an array to hold all ticket data
        if ($result) {
            $countVehicles = $result->num_rows;
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $id = $row['id'];
                    //count tickets
                    $associatedTickets = countRecords('tickets', 'vehicle_id', $id);

                    $tickets[] = [
                        'id' => $row['id'],
                        'registration' => $row['registration'],
                        'region' => $row['region'],
                        'make' => $row['make'],
                        'model' => $row['model'],
                        'chassis_no' => $row['chassis_no'],
                        'engine_no' => $row['engine_no'],
                        'colour' => $row['colour'],
                        'description' => $row['description'],
                        'ownership' => $row['ownership'],
                        'body_type' => $row['body_type'],
                        'yom' => $row['yom'],
                        'rating_cc' => $row['rating_cc'],
                        'fuel_type' => $row['fuel_type'],
                        'data_source' => $row['data_source'],
                        'created_by' => $row['created_by'],
                        'created_on' => $row['created_on'],
                        'last_edited_by' => $row['last_edited_by'],
                        'last_edited_on' => $row['last_edited_on'],
                        'status' => $row['status'],
                        'associated_tickets' => $associatedTickets

                    ];
                }

                $result->free(); // Free result set memory
            } else {
                //zero results
                $httpResponseCode = 200;
                $tickets = [
                    'message' => "0 results",
                ];
            }
            $httpResponseCode = 200;
            $response_array = $tickets;

            logAction('api', 'api', '', '200', "Fetching vehicles $filterregistration $countVehicles results found.");
        } else {
            $httpResponseCode = 500;
            $error = "Query failed";
            $message = "Query failed: " . $conn->error;
        }

    } catch (mysqli_sql_exception $e) {
        // 2. Catch specific MySQLi exceptions (e.g., failed connection, SQL syntax error)
        $httpResponseCode = 500;
        $error = "Query failed.";
        $message = "Database error: " . $e->getMessage() . $e->getCode();
    } catch (Exception $ex) {
        $httpResponseCode = 400;
        $error = "Bad Request";
        $message = "Exception occurred while processing your request. " . $ex->getMessage();
    } catch (\Throwable $e) {
        // This catches ArgumentCountError, Exception, and all other Errors
        $httpResponseCode = 500;
        $error = "Error occurred";
        $message = "Throwable: " . $e->getMessage();
    }

} else {
    $httpResponseCode = 400;
    $error = "Bad Request";
    $message = "The request cannot be fulfilled due to bad method.";
}

//final
if ($httpResponseCode != '200') {
    //display errors
    http_response_code($httpResponseCode);
    $response_array = [
        'error' => $error,
        'message' => $message
    ];

    logAction('api', "api", "", $httpResponseCode, "Fetching vehicles $filterregistration failed: Error: $error. Message $message");
}

//output
$output = json_encode($response_array);
header('Content-Type: application/json');
echo $output;