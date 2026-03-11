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

                    $editedByUserAdAccount = getProperties('tokens', 'user_ad_account', 'token', $token);
                    $editedByUserId = getProperties('users', 'id', 'ad_account_no', $editedByUserAdAccount);

                    // Process API
                    try {
                        $adaccount = $_POST['ad_account'] ?? null;
                        $token = $_POST['token'] ?? null;

                        if (!$adaccount) {
                            $httpResponseCode = 400;
                            $error = "Bad Request";
                            $message = "ad_account is required";
                        } elseif (!$token) {
                            $httpResponseCode = 400;
                            $error = "Bad Request";
                            $message = "token is required";
                        } else {

                            //check if  exists
                            $queryCh = "SELECT * FROM tokens WHERE user_ad_account='$adaccount' AND token='$token'";
                            $resultsCh = mysqli_query($conn, $queryCh);
                            $countCh = mysqli_num_rows($resultsCh);
                            if ($countCh == 1) {

                                $status = 'logged out';
                                $sql = "UPDATE tokens SET status=?, logged_out_on=? WHERE (user_ad_account=? AND token=?)";
                                $stmt = $conn->prepare($sql);
                                $stmt->bind_param(
                                    "ssss",
                                    $status,
                                    $timestamp,
                                    $adaccount,
                                    $token
                                );

                                if ($stmt->execute()) {
                                    $httpResponseCode = 200;
                                    $message_ = "Logged out successfully";
                                    $response_array = [
                                        'message' => "Logged out successfully",
                                    ];

                                    logAction($editedByUserAdAccount, $editedByUserId, $token, $httpResponseCode, "Logged out successfully");
                                } else {
                                    $httpResponseCode = 400;
                                    $error = "Query failed";
                                    $message = "Query failed: " . $conn->error;
                                }
                            } else {
                                $httpResponseCode = 400;
                                $error = "Bad Request";
                                $message = "Token record not found.";
                            }
                        }
                    } catch (mysqli_sql_exception $e) {
                        // 2. Catch specific MySQLi exceptions (e.g., failed connection, SQL syntax error)
                        $httpResponseCode = 500;
                        $error = "Query failed.";
                        $message = "Database error: " . $e->getMessage() . $e->getCode();
                    } catch
                    (Exception $ex) {
                        $httpResponseCode = 500;
                        $error = "Bad Request";
                        $message = "Exception occurred while processing your request. " . $ex->getMessage();
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

    logAction($raisedbyuserAdAccount ?? null, $raisedbyuserId ?? null, $token ?? null, $httpResponseCode, "Logout failed: Error: $error. Message $message");
}


//output
$output = json_encode($response_array);
header('Content-Type: application/json');
echo $output;

