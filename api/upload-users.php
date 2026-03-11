<?php

use JetBrains\PhpStorm\NoReturn;

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
$httpResponseCode = 400;
$currentUserAdAccount = $currentUserId = '';
$error = $message = $output = "";
$allowed_regions = ["western", "nairobi", "northern", "north rift", "south rift", "southern", "central"];
global $conn, $timestamp;


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

                    // Get user info
                    $currentUserAdAccount = getProperties('tokens', 'user_ad_account', 'token', $token);
                    $currentUserId = getProperties('users', 'id', 'ad_account_no', $currentUserAdAccount);


                    // Process API
                    try {

                        // Move uploaded files
                        $uploadTargetDirectory = "../../mvr-storage/";


                        //upload csv
                        if (!empty($_FILES['csv_upload']['name'])) {
                            $fileUploadResult = fileUpload('csv_upload');
                            if ($fileUploadResult['status'] === true) {
                                $uploadedFileName = $fileUploadResult['message'];

                                $csvFilePath = "../../mvr-storage/$uploadedFileName";

                                processImports($csvFilePath);

                            } else {
                                exitWithError(400, 'File upload failed', $fileUploadResult['message']); //will exit with an error
                            }
                        } else {
                            $httpResponseCode = 400;
                            $error = "Bad Request";
                            $message = "csv upload missing";
                        }

                    } catch (mysqli_sql_exception $e) {
                        // 2. Catch specific MySQLi exceptions (e.g., failed connection, SQL syntax error)
                        $httpResponseCode = 500;
                        $error = "Query failed.";
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

    logAction($currentUserAdAccount ?? null, $currentUserId ?? null, $token ?? null, $httpResponseCode, "Error: $error. Message $message");
}


//output
$output = json_encode($response_array);
header('Content-Type: application/json');
echo $output;


function fileUpload($fileToUploadIndex)
{

    $uniquid = uniqid() . "_";
    $uploadError = $fileName = '';

    global $uploadTargetDirectory;
    //clean name
    $_FILES["$fileToUploadIndex"]["name"] = str_replace('\'', '', $_FILES["$fileToUploadIndex"]["name"]);
    $_FILES["$fileToUploadIndex"]["name"] = str_replace("/", '', $_FILES["$fileToUploadIndex"]["name"]);
    $_FILES["$fileToUploadIndex"]["name"] = str_replace(' ', '_', $_FILES["$fileToUploadIndex"]["name"]);
    $_FILES["$fileToUploadIndex"]["name"] = str_replace("'", '', $_FILES["$fileToUploadIndex"]["name"]);
    $target_file = $uploadTargetDirectory . $uniquid . $_FILES["$fileToUploadIndex"]["name"];
    $target_file2 = $uniquid . $_FILES["$fileToUploadIndex"]["name"];
    $fileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    // Check file size
    if ($_FILES["$fileToUploadIndex"]["size"] > 5000000) {
        $uploadError .= "File is more than 5MB.";
    } else if ($fileType != "csv") {
        $uploadError .= "File type rejected";
    } else {
        if (move_uploaded_file($_FILES["$fileToUploadIndex"]["tmp_name"], $target_file)) {
            $fileName = $target_file2;
        } else {
            $uploadError .= "Sorry, there was an error uploading your file.";
        }
    }

    if ($fileName) {
        return [
            'status' => true,
            'message' => $fileName
        ];
    } else {
        return [
            'status' => false,
            'message' => $uploadError
        ];
    }
}


function processImports($csvFilePath)
{
    global $conn, $timestamp, $currentUserAdAccount, $allowed_regions;
    if (file_exists($csvFilePath)) {
        // $conn->autocommit(FALSE);

        try {
            $file = fopen($csvFilePath, "r");

            $i = 0;
            $importData_arr = array();
            $insertedRecords = array();
            //echo count($importData_arr);
            while (($data = fgetcsv($file, 1000, ",")) !== FALSE) {
                $num = count($data);
                for ($c = 0; $c < $num; $c++) {
                    $importData_arr[$i][] = $data[$c];
                }
                $i++;
            }
            fclose($file);

            if (count($importData_arr) == 1) {
                exitWithError(400, 'Bad Request', '0 records found in the imported file.');
            } elseif (count($importData_arr) > 50) {
                exitWithError(400, 'Bad Request', 'Cannot import more than 50 records at a go.');
            } else {

                $skip = 0;
                $countInserted = $countDuplicated = 0;
                $duplicateFound = false;

                //loop begins, if error occurs, rollback
                foreach ($importData_arr as $data) {
                    if ($skip != 0) {
                        $rowNo = $skip + 1;

                        // Check if any field is empty
                        $adaccountno = isset($data[0]) ? trim($data[0]) : "";
                        $designation = isset($data[1]) ? trim($data[1]) : "";
                        $region = isset($data[2]) ? trim($data[2]) : "";
                        $role_id = isset($data[3]) ? trim($data[3]) : "";

                        if ($adaccountno === "" || $designation === "" || $region === "" || $role_id === "") {
                            // If one fails, throw an exception to trigger the rollback immediately
                            throw new Exception("Empty fields or missing columns in row #$rowNo. Content: [" . implode(", ", $data) . "]. Expected format: AD Account, Designation, Region, Role ID. Please ensure all 4 columns are provided and separated by commas.");
                        }

                        //validate designation
                        if (strlen($designation) > 100) {
                            // If one fails, throw an exception to trigger the rollback immediately
                            throw new Exception("Designation too long. Entry rejected in row #$rowNo: " . implode(", ", $data) . ". Please check your CSV file.");
                        }

                        //validate region
                        if (!in_array((strtolower($region)), $allowed_regions, true)) {
                            // If one fails, throw an exception to trigger the rollback immediately
                            throw new Exception("Unrecognized Region in row #$rowNo: " . implode(", ", $data) . ". Please check your CSV file.");
                        }

                        //validate role_id
                        if (!is_numeric($role_id)) {
                             throw new Exception("Invalid role_id in row #$rowNo: Role ID must be a number. Please check your CSV file.");
                        }
                        $roleExists = countRecords('roles', 'id', $role_id);
                        if ($roleExists == 0) {
                             throw new Exception("Unrecognized Role ID in row #$rowNo: Role ID $role_id does not exist. Please check your CSV file.");
                        }

                        //validate AD Account
                        $user_name = $user_phone = $user_email = "";
                        $adaccountno = strtoupper($adaccountno);
                        $adaccountno = str_replace(" ", "", $adaccountno);
                        $firstChar = substr($adaccountno, 0, 1);
                        $otherChar = substr($adaccountno, 1, 15);
                        if (strlen($adaccountno) > 4 && strlen($adaccountno) < 12 && ($firstChar == 'K' || $firstChar == 'T' && is_numeric($otherChar))) {
                            $userAdResult = getADProfile($adaccountno);
                            if ($userAdResult['status'] == 'active') {
                                $user_name = $userAdResult['name'];
                                $user_email = $userAdResult['email'];
                                $user_phone = $userAdResult['phone'];
                            } else {
                                // If one fails, throw an exception to trigger the rollback immediately
                                throw new Exception("AD Profile not found/ is not active in row #$rowNo");
                            }
                        } else {
                            // If one fails, throw an exception to trigger the rollback immediately
                            throw new Exception("AD Account no. format rejected in row #$rowNo");
                        }


                        $createdby = "$currentUserAdAccount via csv import";
                        $status = "inactive";
                        //check if exists
                        $ifExists = countRecords('users', 'ad_account_no', $adaccountno);
                        if ($ifExists == 0) {

                            getADProfile($adaccountno);

                            $stmt = $conn->prepare("INSERT INTO users(ad_account_no, full_name, email, phone_no, designation, region, role_id, status, created_by, created_on)
                                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param(
                                "ssssssssss",
                                $adaccountno,
                                $user_name,
                                $user_email,
                                $user_phone,
                                $designation,
                                $region,
                                $role_id,
                                $status,
                                $createdby,
                                $timestamp
                            );

                            if ($stmt->execute()) {
                                $countInserted++;
                                $insertedRecords[] = $adaccountno;
                            } else {
                                // If one insertion fails, throw an exception to trigger the rollback immediately
                                throw new Exception("Insertion failed for users: " . $adaccountno);
                            }
                        } else {
                            $countDuplicated++;
                        }
                    }
                    $skip++;
                }

                if ($countInserted < 1) {
                    exitWithError("400", "Data not saved" . count($importData_arr), "0 rows inserted. $countDuplicated duplicates found.");
                } else {
                    exitWithSuccess("$countInserted users uploaded successfully. $countDuplicated duplicates found.");
                }
            }
//            $conn->commit();
//            $conn->autocommit(TRUE);
        } catch
        (Exception $e) {
//            $conn->rollback();
//            $conn->autocommit(TRUE);

            foreach ($insertedRecords as $reg) {
                $deleteStmt = $conn->prepare("DELETE FROM users WHERE created_on = ?");
                $deleteStmt->bind_param("s", $timestamp);
                $deleteStmt->execute();
            }

            exitWithError('400', "Exception/ Data error occurred", $e->getMessage());
        } catch (mysqli_sql_exception $e) {
            exitWithError('500', "Query failed", "Database error: " . $e->getMessage() . $e->getCode());
        } catch (\Throwable $e) {
            exitWithError('500', "Error occurred", "Throwable: " . $e->getMessage());
        }

    } else {
        exitWithError('500', "File not found", 'CSV file could not be found.');
    }
}

function exitWithError($code, $error, $message)
{
    global $currentUserAdAccount, $currentUserId, $token, $httpResponseCode;
    logAction($currentUserAdAccount ?? null, $currentUserId ?? null, $token ?? null, $httpResponseCode, "Users upload failed: Error: $error. Message $message");

    http_response_code($code);
    $response_array = [
        'error' => $error,
        'message' => $message
    ];

    $output = json_encode($response_array);
    header('Content-Type: application/json');
    echo $output;

    exit;
}

function exitWithSuccess($message): void
{
    global $currentUserAdAccount, $currentUserId, $token, $httpResponseCode;
    http_response_code(200);
    $response_array = [
        'message' => $message
    ];

    $output = json_encode($response_array);
    header('Content-Type: application/json');
    echo $output;

    logAction($currentUserAdAccount ?? null, $currentUserId ?? null, $token ?? null, $httpResponseCode, "Uploaded users. $message");
    exit;
}

function getADProfile($adAccountNo)
{
    global $adBaseUrl;
    $resp_name = $resp_email = $resp_phone = null;
    $status = false;
    //Staff profile API via AD
    $apiURL = "$adBaseUrl/user/details?personalNumber=$adAccountNo"; //dev server
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
            $resp_name = strtolower($resp_name);
            $resp_name = ucwords($resp_name);

            $status = true;
        }
    }

    return [
        'status' => $status,
        'name' => $resp_name,
        'phone' => $resp_phone,
        'email' => $resp_email
    ];
}