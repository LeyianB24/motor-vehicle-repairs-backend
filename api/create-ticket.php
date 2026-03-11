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
        http_response_code(401);
        $output = json_encode(["error" => "Authorization header is missing"]);
    } else {
        if (substr($headers['Authorization'], 0, 7) !== 'Bearer ') {
            $httpResponseCode = 401;
            $error = "AUTH Error";
            $message = "Authorization header is missing";
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
                    try {
                        // Retrieve POST fields
                        $ticketStatus = $_POST['ticket_status'] ?? 'draft';
                        $vehicleid = $_POST['vehicle_id'] ?? null;
                        $region = $_POST['region'] ?? '';
                        $category = $_POST['category'] ?? '';
                        $observation = $_POST['observation'] ?? '';

                        // Get user info
                        $raisedbyuserAdAccount = getProperties('tokens', 'user_ad_account', 'token', $token);
                        $raisedbyuserId = getProperties('users', 'id', 'ad_account_no', $raisedbyuserAdAccount);
                        $raisedbyuserRegion = getProperties('users', 'region', 'ad_account_no', $raisedbyuserAdAccount);
                        $vehicleRegion = getProperties('vehicles', 'region', 'id', $vehicleid);


                        if (!$vehicleid) {
                            //if vehicle id is submitted
                            $httpResponseCode = 400;
                            $error = "Bad Request";
                            $message = "Missing vehicle_id";

                        } else {
                            //check if vehicle exists
                            if (countRecords("vehicles", "id", $vehicleid) == 0) {
                                $httpResponseCode = 400;
                                $error = "Bad Request";
                                $message = "Vehicle not found";

                            } else {

                                $tracking_progress = 'Draft';
                                $tracking_progress_level = '1000';
                                // Insert into tickets table
                                $stmt = $conn->prepare("INSERT INTO tickets 
                                        (vehicle_id, vehicle_region, raised_by_user_id, user_region, raised_on, tracking_progress, tracking_progress_level) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmt->bind_param(
                                    "sssssss",
                                    $vehicleid,
                                    $vehicleRegion,
                                    $raisedbyuserId,
                                    $raisedbyuserRegion,
                                    $timestamp,
                                    $tracking_progress,
                                    $tracking_progress_level
                                );

                                if ($stmt->execute()) {
                                    $newTicketId = $conn->insert_id; // Get the newly created ticket ID

                                    $message_ = "Ticket ID $newTicketId created successfully.";
                                    $httpResponseCode = 200;
                                    $response_array = [
                                        "id" => $newTicketId,
                                        "message" => $message_
                                    ];

                                    logAction($raisedbyuserAdAccount ?? null, $raisedbyuserId ?? null, $token, $httpResponseCode, $message_);


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

    logAction($raisedbyuserAdAccount ?? null, $raisedbyuserId ?? null, $token ?? null, $httpResponseCode, "Create ticket failed: Error: $error. Message $message");
}


//output
$output = json_encode($response_array);
header('Content-Type: application/json');
echo $output;