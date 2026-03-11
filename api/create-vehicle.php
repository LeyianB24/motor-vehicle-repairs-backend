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
$error = $message = $output = "";
global $conn;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve the Authorization header
    $headers = getallheaders();
    if (!array_key_exists('Authorization', $headers)) {
        $httpResponseCode = 401;
        $error = "AUTH Error";
        $message = "Authorization header is missing";
    } else {
        if (substr($headers['Authorization'], 0, 7) !== 'Bearer ') {
            $httpResponseCode = 401;
            $error = "AUTH Error";
            $message = "Token keyword is missing";
        } else {
            $token = trim(substr($headers['Authorization'], 7)); // Extract the token from the header

            if (!isTokenValid($token)) {
                $httpResponseCode = 401;
                $error = "AUTH Error";
                $message = "Invalid/ expired token.";

            } else {
                if (!refreshToken($token)) {
                    $httpResponseCode = 500;
                    $error = "Token Refresh Error";
                    $message = "Query failed: " . $conn->error;
                } else {


                    //process Actual API
                    $currentuserAdAccount = getProperties('tokens', 'user_ad_account', 'token', $token);
                    $currentuserId = getProperties('users', 'id', 'ad_account_no', $currentuserAdAccount);


                    try {
                        // Retrieve POST fields
                        $registration = $_POST["registration"] ?? null;
                        $make = $_POST["make"] ?? null;
                        $model = $_POST["model"] ?? null;
                        $ownership = $_POST["ownership"] ?? null;
                        $station = $_POST["station"] ?? null;
                        $region = $_POST["region"] ?? null;

                        $registration = str_replace(" ", "", $registration);
                        $registration = strtoupper($registration);

                        if (!$registration) {
                            //if vehicle id is submitted
                            $httpResponseCode = 400;
                            $error = "Bad Request";
                            $message = "Missing registration field";

                        } else {
                            //check if vehicle exists
                            if (countRecords("vehicles", "registration", $registration) != 0) {
                                $httpResponseCode = 400;
                                $error = "Bad Request";
                                $message = "Vehicle Registration: $registration already exists.";

                            } else {

                                $datasource='manual entry';
                                // Insert into tickets table
                                $stmt = $conn->prepare("INSERT INTO vehicles 
                                        (registration, make, model, ownership, station, region, data_source, created_by, created_on) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $stmt->bind_param(
                                    "sssssssss",
                                    $registration,
                                    $make,
                                    $model,
                                    $ownership,
                                    $station,
                                    $region,
                                    $datasource,
                                    $currentuserAdAccount,
                                    $timestamp
                                );

                                if ($stmt->execute()) {
                                    $newTicketId = $conn->insert_id; // Get the newly created ticket ID

                                    $message_ = "Vehicle ID $newTicketId, Reg: $registration created successfully.";
                                    $httpResponseCode = 200;
                                    $response_array = [
                                        "id" => $newTicketId,
                                        "message" => $message_
                                    ];

                                    logAction($currentuserAdAccount ?? null, $currentuserId ?? null, $token, $httpResponseCode, $message_);
                                } else {
                                    $httpResponseCode = 500;
                                    $error = "Query failed";
                                    $message = "Query failed: " . $stmt->error;

                                }
                            }
                        }
                    } catch (mysqli_sql_exception $e) {
                        // 2. Catch specific MySQLi exceptions (e.g., failed connection, SQL syntax error)
                        $httpResponseCode = 500;
                        $error = "Query failed.";
                        $message = "Database error: " . $e->getMessage() . $e->getCode();
                    } catch (Exception $ex) {
                        $httpResponseCode = 500;
                        $error = "Error occurred";
                        $message = "Exception occurred while processing your request." . $ex->getMessage();
                    } catch (\Throwable $e) {
                        // This catches ArgumentCountError, Exception, and all other Errors
                        $httpResponseCode = 500;
                        $error = "Error occurred";
                        $message = "Throwable: " . $e->getMessage();
                    }
                }
            }
        }
    }
} else {
    $httpResponseCode = 400;
    $error = "Bad Request";
    $message = "The request cannot be fulfilled due to bad method.";
}

//final
if ($httpResponseCode != '200') {
    //display errrors
    http_response_code($httpResponseCode);
    $response_array = [
        'error' => $error,
        'message' => $message
    ];

    logAction($currentuserAdAccount ?? null, $currentuserId ?? null, $token ?? null, $httpResponseCode, "Vehicle creation failed: Error: $error. Message $message");
}


//output
$output = json_encode($response_array);
header('Content-Type: application/json');
echo $output;