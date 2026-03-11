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

                    $currentUserAdAccount = getProperties('tokens', 'user_ad_account', 'token', $token);
                    $currentUserId = getProperties('users', 'id', 'ad_account_no', $currentUserAdAccount);

                    // Process API
                    try {
                        $user_id = $_POST['user_id'] ?? null;

                        $region = $_POST['region'] ?? null;
                        $designation = $_POST['designation'] ?? null;
                        $role_id = $_POST['role_id'] ?? null;
                        $status = $_POST['status'] ?? null;
                        $phone = $_POST['phone'] ?? null;
                        $region = trim($region);

                        if (!$user_id) {
                            $httpResponseCode = 400;
                            $error = "Bad Request";
                            $message = "user_id is required";
                        } else {

                            //check if user exists
                            $checkIfExists = countRecords('users', 'id', $user_id);
                            if ($checkIfExists == 1) {

                                //validate region
                                if ($region && in_array((strtolower(trim($region))), $allowed_regions, true)) {


                                    //check if role exists and if is active
                                    $checkRoleExists = countRecords('roles', 'id', $role_id);
                                    $checkRoleStatus = getProperties('roles', 'status', 'id', $role_id);
                                    if ($checkRoleExists == 1 && $checkRoleStatus == '1') {

                                        $sql = "UPDATE users SET region=?, designation=?, role_id=?, status=?, phone_no=?,
                            last_edited_by=?, last_edited_on=? WHERE id=?";
                                        $stmt = $conn->prepare($sql);
                                        $stmt->bind_param(
                                            "sssssssi",
                                            $region,
                                            $designation,
                                            $role_id,
                                            $status,
                                            $phone,
                                            $editedByUserAdAccount,
                                            $timestamp,
                                            $user_id
                                        );

                                        if ($stmt->execute()) {
                                            $message_ = "User id $user_id edited successfully";
                                            $httpResponseCode = 200;
                                            $response_array = [
                                                'message' => $message_,
                                            ];

                                            logAction($currentUserAdAccount, $currentUserId, $token, $httpResponseCode, $message_);
                                        } else {
                                            $httpResponseCode = 400;
                                            $error = "Query failed";
                                            $message = "Query failed: " . $conn->error;
                                        }
                                    } else {
                                        $httpResponseCode = 400;
                                        $error = "Bad Request";
                                        $message = "Role ID $role_id does not exist or is not active.";
                                    }
                                } else {
                                    $httpResponseCode = 400;
                                    $error = "Bad Request";
                                    $message = "Unrecognized region.";
                                }
                            } else {
                                $httpResponseCode = 400;
                                $error = "Bad Request";
                                $message = "User ID $user_id  does not exist";
                            }

                        }
                    } catch (mysqli_sql_exception $e) {
                        // 2. Catch specific MySQLi exceptions (e.g., failed connection, SQL syntax error)
                        $httpResponseCode = 500;
                        $error = "Query failed.";
                        $message = "Database error: " . $e->getMessage() . $e->getCode();
                    } catch (Exception $ex) {
                        $httpResponseCode = 500;
                        $error = "Bad Request";
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

    logAction($currentUserAdAccount ?? null, $currentUserId ?? null, $token ?? null, $httpResponseCode, "Edit user id " . ($user_id ?? null) . " failed: Error: $error. Message $message");
}


//output
$output = json_encode($response_array);
header('Content-Type: application/json');
echo $output;

