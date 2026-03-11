<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header('Access-Control-Allow-Headers: X-Requested-With,Authorization,Content-Type');
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Respond to preflight request
    http_response_code(200);
    exit;
}

require '../config.php';

$response_array = [];
$httpResponseCode = 400;
$error = $message = $output = $loginResponseMessage = "";
global $conn, $timestamp, $tokenRefreshDuration;

$loginResponseCode = $permissions = $myperms = '';
$resp_message = $resp_status = '';
$full_name = $phone = $email = $region = $personalno = $designation = $output = $sessionid = $access_token = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    try {
        $adaccount = $_POST["ad_account"] ?? null;
        $encryptedPassword = $_POST["password"] ?? null;

        if (!$adaccount) {
            $httpResponseCode = 400;
            $error = "Bad Request";
            $message = "ad_account is required";

        } elseif (!$encryptedPassword) {
            $httpResponseCode = 400;
            $error = "Bad Request";
            $message = "password is required";

        } else {

            // Decryption key (should match the key used in Angular)
            $encryptionKey = $loginEncKey; //from config
            // Decrypt the password
            $decryptedPassword = decryptPassword($encryptedPassword, $encryptionKey);
//            echo "Enc: $encryptedPassword\n";
//            echo "Dec: $decryptedPassword\n";

            //$decryptedPassword = $encryptedPassword; //for local dev purposes


            //AD API
            $apiURL = "$adBaseUrl/user/login"; //AD API server from config
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiURL);
            curl_setopt($ch, CURLOPT_USERPWD, $adaccount . ":" . $decryptedPassword);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

            // Receive server response ...
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $server_output = curl_exec($ch);
            $_httpResponseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($_httpResponseCode == '200') {
                if (!empty($server_output)) {
                    $decodedData = json_decode($server_output);
                    $resp_name = $decodedData->name;
                    $resp_email = $decodedData->email;
                    $resp_phone = $decodedData->phonenumber;
                    if (!empty($resp_name)) {
                        //check if user exists in App's list of users
                        $queryStaff = "SELECT * FROM users WHERE ad_account_no='$adaccount'";
                        $resultStaff = mysqli_query($conn, $queryStaff) or die(mysqli_error($conn));
                        $count = mysqli_num_rows($resultStaff);
                        if ($count == 1) { //user exists in internal users's list
                            while ($roww = mysqli_fetch_assoc($resultStaff)) {
                                $userid = $roww['id'];
                                $account_status = $roww['status'];
                                $designation = $roww['designation'];
                                $role_id = $roww['role_id'];
                                $region = $roww['region'];
                                $full_name = $roww['full_name'];
                                $email = $roww['email'];
                                $phone = $roww['phone_no'];
                                $perms = [];

                                //get role name
                                $role_name = '';
                                if ($role_id) {
                                    $role_name = getProperties('roles', 'name', 'id', $role_id);
                                }

                                //find associated permissions
                                if ($role_id) {
                                    $queryPerms = "SELECT * FROM role_permissions_mapping WHERE (role_id='$role_id' AND status='active')";
                                    if ($resultPerms = $conn->query($queryPerms)) {
                                        if ($resultPerms->num_rows > 0) {
                                            while ($rowPerms = $resultPerms->fetch_assoc()) {
                                                $permId = $rowPerms['permission_id'];
                                                //get permission name
                                                $permName = getProperties('permissions', 'name', 'id', $permId);
                                                $perms[] = $permName;
//                                                $perms[] = $permId;
                                            }
                                        } else {
                                        }
                                    } else {
                                    }
                                }
                                //end perms

                            }
                            //$permissions = "ViewDashboard, ViewPC, ViewStaff, ViewComplaints, ViewBudget";
                            if ($account_status == 'active') {

                                //check 5-minute sessions
                                $queryToken = "SELECT * FROM tokens WHERE user_ad_account = '$adaccount' AND status='active' AND expires_on > '$timestamp'";
                                $resultsToken = mysqli_query($conn, $queryToken);
                                $countResults = mysqli_num_rows($resultsToken);
                                if ($countResults == 0) { //this is a new login

                                    //generate token
                                    session_start();
                                    $sessionid = session_id();
                                    $token = md5(uniqid() . rand(1000000, 9999999));
                                    $access_token = $token . "_" . $sessionid;
                                    $timestamp1 = strtotime($timestamp);
                                    $expiryTimestamp = $timestamp1 + $tokenRefreshDuration;  // Add time based on '../config.php'
                                    $expiryTimestamp = date("Y-m-d H:i:s", $expiryTimestamp); //convert to string


                                    $sql1 = "INSERT INTO tokens (user_ad_account, token, session_id, generated_on, last_used, expires_on)
                                      values ('$adaccount', '$access_token', '$sessionid', '$timestamp', '$timestamp', '$expiryTimestamp')";
                                    if (mysqli_query($conn, $sql1)) {
                                        //disable all other tokens that are still active
                                        $sqlUT = "UPDATE tokens SET status= 'deactivated' WHERE token!='$access_token' AND user_ad_account = '$adaccount' AND status='active'";
                                        if (mysqli_query($conn, $sqlUT)) {
                                        } else {
                                        }
                                    } else {
                                    }

                                    //update last active
                                    $sqlU = "UPDATE users SET last_login='$timestamp' WHERE ad_account_no = '$adaccount'";
                                    if (mysqli_query($conn, $sqlU)) {
                                    } else {
                                    }

                                    //give response
                                    $loginResponseCode = "01";
                                    $httpResponseCode = 200;

                                    $loginResponseMessage = "Login successful.";


                                } else {

                                    //1. disable old token that is still active
                                    $sqlUT = "UPDATE tokens SET status= 'deactivated' WHERE user_ad_account = '$adaccount' AND status='active'";
                                    if (mysqli_query($conn, $sqlUT)) {
                                    } else {
                                    }

                                    //2. create new token
                                    session_start();
                                    $sessionid = session_id();
                                    $token = md5(uniqid() . rand(1000000, 9999999));
                                    $access_token = $token . "_" . $sessionid;
                                    $timestamp1 = strtotime($timestamp);
                                    $expiryTimestamp = $timestamp1 + $tokenRefreshDuration;  // Add time from 'config'
                                    $expiryTimestamp = date("Y-m-d H:i:s", $expiryTimestamp); //convert to string

                                    $sql1 = "INSERT INTO tokens (user_ad_account, token, session_id, generated_on, last_used, expires_on)
                                    values ('$adaccount', '$access_token', '$sessionid', '$timestamp', '$timestamp', '$expiryTimestamp')";
                                    if (mysqli_query($conn, $sql1)) {
                                    } else {
                                    }

                                    //3. update last active
                                    $sqlU = "UPDATE users SET last_login='$timestamp' WHERE ad_account_no = '$adaccount'";
                                    if (mysqli_query($conn, $sqlU)) {
                                    } else {
                                    }
                                    //4. log action
                                    //addLog($accountno, 'login successful. Other sessions terminated');

                                    $loginResponseCode = "01";
                                    $httpResponseCode = 200;
                                    $loginResponseMessage = "Login successful. All other active session have been terminated.";


                                }
                            } else {
                                $httpResponseCode = 401;
                                $error = "Authentication failed";
                                $message = "login denied";
                            }
                        } else {
                            $httpResponseCode = 401;
                            $error = "Authentication failed";
                            $message = "User $adaccount not found. Please contact Admin.";
                        }

                    } else {
                        $httpResponseCode = 400;
                        $error = "Bad Request";
                        $message = "Unable to authenticate user (name/ email) - $adaccount. Please contact AD Admin.";
                    }
                } else {
                    $httpResponseCode = 401;
                    $error = "Authentication failed";
                    $message = "Profile not found.";
                }
            } elseif ($_httpResponseCode == '401') {
                if (!empty($server_output)) {
                    $decodedData = json_decode($server_output);
                    $resp_status = $decodedData->status ?? null;
                    $resp_message = $decodedData->message ?? null;
                }

                $httpResponseCode = 401;
                $error = "Unauthorized";
                $message = "$resp_message. $resp_status";
            } else { //to cater for other error codes
                if (!empty($server_output)) {
                    $decodedData = json_decode($server_output);
                    $resp_status = $decodedData->status ?? null;
                    $resp_message = $decodedData->message ?? null;
                }

                $httpResponseCode = 500;
                $error = "Login failed";
                $message = "Error occurred: $resp_message. $resp_status";
            }

        }
    } catch (mysqli_sql_exception $e) {
        // 2. Catch specific MySQLi exceptions (e.g., failed connection, SQL syntax error)
        $httpResponseCode = 500;
        $error = "Query failed";
        $message = "Database error: " . $e->getMessage() . $e->getCode();
    } catch (Exception $ex) {
        $httpResponseCode = 500;
        $error = "Exception error occurred";
        $message = $ex->getMessage();
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


if ($loginResponseCode == "01") {
    http_response_code(200);
    $user_profile = [
        'id' => $userid,
        'full_name' => $full_name,
        'personal_no' => $adaccount,
        'email' => $email,
        'phone_no' => $phone,
        'region' => $region,
        'designation' => $designation,
        'token' => $access_token,
        'role_id' => $role_id,
        'role_name' => $role_name,
        'permissions' => $perms //permissions array
    ];

    $response_array = [
        'code' => $loginResponseCode,
        'message' => $loginResponseMessage,
        // Nested 'user' object
        'user' => $user_profile
    ];

    logAction($adaccount, $userid, $token, '200', "Logged in successfully.");
} else if ($httpResponseCode != '200') {
    //display errors
    http_response_code($httpResponseCode);
    $response_array = [
        'error' => $error,
        'message' => $message
    ];


    logAction($adaccount ?? null, $userid ?? null, '', $httpResponseCode, "Login attempt failed. Error: $error. Message: $message.");
}


//output
$output = json_encode($response_array);
header('Content-Type: application/json');
echo $output;

function decryptPassword($encryptedPassword, $key)
{
    // 1. Decode the entire package back to binary
    $data = base64_decode($encryptedPassword);

    // Determine the required IV length (16 bytes for aes-256-cbc)
    $ivlen = openssl_cipher_iv_length('aes-256-cbc');

    // 2. Ensure the decoded string is long enough before splitting
    if (strlen($data) < $ivlen) {
        // The package is too short! Throw an error instead of proceeding.
        throw new Exception("Password decryption failed: Encrypted package too short.");
    }
    // 3. Extract the 16-byte IV
    $iv = substr($data, 0, $ivlen);
    // 4. Extract the rest as the cipher text
    $encrypted = substr($data, $ivlen);
    // 5. Decrypt and return
    $decryptedPassword = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($decryptedPassword) {
        return $decryptedPassword;
    } else {
        throw new Exception("Password decryption failed. $decryptedPassword");
    }
}
