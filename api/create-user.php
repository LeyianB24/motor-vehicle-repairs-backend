<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Respond to preflight request
    http_response_code(200);
    exit;
}

require '../config.php';

$response_array = [];
$httpResponseCode = 400;
$error = $message = $output = "";
$allowed_regions = ["western", "nairobi", "northern", "north rift", "south rift", "southern", "central"];
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

                    //process API
                    // Get user info
                    $currentUserAdAccount = getProperties('tokens', 'user_ad_account', 'token', $token);
                    $currentUserId = getProperties('users', 'id', 'ad_account_no', $currentUserAdAccount);

                    try {

                        $adaccount = $_POST["ad_account"] ?? null;
                        $region = $_POST["region"] ?? null;

                        if (strlen($adaccount) > 5) {
                            $adaccount = str_replace(" ", "", $adaccount);
                            $adaccount = strtoupper($adaccount);
                            $firstChar = substr($adaccount, 0, 1);
                            $otherChar = substr($adaccount, 1, 15);
                            if ($firstChar == 'K' || $firstChar == 'T' && is_numeric($otherChar)) {

                                //Staff profile API via AD
                                $apiURL = "$adBaseUrl/user/details?personalNumber=$adaccount"; //dev server
                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $apiURL);
                                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

                                // Receive server response ...
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                $server_output = curl_exec($ch);
                                $httpResponseCode1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                                curl_close($ch);

                                if ($httpResponseCode1 == '200') {
                                    if (!empty($server_output)) {
                                        $decodedData = json_decode($server_output);
                                        $resp_name = $decodedData->name;
                                        $resp_email = $decodedData->email;
                                        $resp_phone = $decodedData->phonenumber;
                                        $resp_name = str_ireplace("\'", '', $resp_name); //apostrophe clean'

                                        $resp_name = mysqli_escape_string($conn, $resp_name);
                                        $resp_name = strtolower($resp_name);
                                        $resp_name = ucwords($resp_name);

                                        if (!empty($resp_name) && !empty($resp_email)) {

                                            //validate region
                                            if ($region && in_array((strtolower(trim($region))), $allowed_regions, true)) {
                                                $region = trim($region);
                                                //check if exist
                                                $countExits = countRecords('users', 'ad_account_no', $adaccount);
                                                if ($countExits == 0) {
                                                    $status = 'inactive';

                                                    $stmt = $conn->prepare("INSERT INTO users 
                                                    (ad_account_no, full_name, email, phone_no, region, status, created_by, created_on) 
                                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                                    $stmt->bind_param(
                                                        "ssssssss",
                                                        $adaccount,
                                                        $resp_name,
                                                        $resp_email,
                                                        $resp_phone,
                                                        $region,
                                                        $status,
                                                        $currentUserAdAccount,
                                                        $timestamp,
                                                    );

                                                    if ($stmt->execute()) {
                                                        $newUserId = $conn->insert_id; // Get the newly created ticket ID

                                                        $httpResponseCode = 200;
                                                        $message_ = "User account $adaccount ($newUserId) created successfully.";
                                                        $response_array = [
                                                            "id" => $newUserId,
                                                            "message" => $message_,
                                                        ];

                                                        $resp_email = strtolower($resp_email);
                                                        $emailsubject = "KRA Motor Vehicle Repair Systerm";
                                                        $emailbody = "Hello $adaccount, your account has been created in the KRA Motor Vehicle Repair System.<br>To access the system, use your domain credentials to log in via: <a href='https://kra.go.ke/'>https://kra.go.ke/</a>";
                                                        $recipientemail = str_replace(" ", "", $resp_email);
                                                        //sendEmail($recipientemail, $emailsubject, $emailbody);

                                                        logAction($currentUserAdAccount, $currentUserAdAccount, $token, $httpResponseCode, $message_);
                                                    } else {
                                                        $httpResponseCode = 500;
                                                        $error = "Query failed";
                                                        $message = "Query failed: " . $stmt->error;
                                                    }


                                                } else {
                                                    $httpResponseCode = 400;
                                                    $error = "Bad Request";
                                                    $message = "User $adaccount already exists.";
                                                }
                                            } else {
                                                $httpResponseCode = 400;
                                                $error = "Bad Request";
                                                $message = "Unrecognized region.";
                                            }
                                        } else {
                                            $httpResponseCode = 400;
                                            $error = "Bad Request";
                                            $message = "Unable to authenticate user (name/ email) - $adaccount. Please contact AD Admin.";
                                        }
                                    } else {
                                        $httpResponseCode = 400;
                                        $error = "Bad Request";
                                        $message = "$adaccount User profile not found.";
                                    }
                                } else {
                                    $resp_message = $resp_status = "failed";
                                    if (!empty($server_output)) {
                                        $decodedData = json_decode($server_output);
                                        $resp_status = $decodedData->status;
                                        $resp_message = $decodedData->message;
                                    }

                                    $httpResponseCode = 500;
                                    $error = "Error occurred";
                                    $message = "$resp_message. $resp_status";
                                }

                            } else {
                                $httpResponseCode = 400;
                                $error = "Bad Request";
                                $message = "Wrong AD Account format";
                            }
                        } else {
                            $httpResponseCode = 400;
                            $error = "Bad Request";
                            $message = "Wrong AD Account format";
                        }


                    } catch (mysqli_sql_exception $e) {
                        // 2. Catch specific MySQLi exceptions (e.g., failed connection, SQL syntax error)
                        $httpResponseCode = 500;
                        $error = "Query failed";
                        $message = "Database error: " . $e->getMessage() . $e->getCode();
                    } catch (Exception $ex) {
                        $httpResponseCode = 500;
                        $error = "Error occurred";
                        $message = "Exception occurred while processing your request. " . $ex->getMessage();
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
    //display errors
    http_response_code($httpResponseCode);
    $response_array = [
        'error' => $error,
        'message' => $message
    ];

    logAction($currentUserAdAccount ?? null, $currentUserId ?? null, $token ?? null, $httpResponseCode, "Create user failed: Error: $error. Message $message");
}

//output
$output = json_encode($response_array);
header('Content-Type: application/json');
echo $output;