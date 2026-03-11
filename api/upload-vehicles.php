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
$allowed_regions = ["western", "nairobi", "northern", "north rift", "south rift", "southern", "central"];
$response_array = [];
$httpResponseCode = 400;
$currentUserAdAccount = $currentUserId = '';
$error = $message = $output = "";

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
            } elseif (count($importData_arr) > 1000) {
                exitWithError(400, 'Bad Request', 'Cannot import more than 1000 records at a go.');
            } else {


                $skip = 0;
                $countInserted = $countDuplicated = 0;
                $duplicateFound = false;

                foreach ($importData_arr as $data) {
                    if ($skip != 0) {
                        $rowNo = $skip + 1;
                        // Check if any field is empty
                        if ($data[0] === "" ||
                            $data[1] === "" ||
                            $data[2] === "" ||
                            $data[3] === "" ||
                            $data[4] === "" ||
                            $data[5] === "") {

                            // If one fails, throw an exception to trigger the rollback immediately
                            throw new Exception("Empty fields in row #$rowNo: " . implode(", ", $data) . ". Please check your CSV file.");
                        }

                        // Check if reg no length
                        if (strlen($data[0]) > 10) {
                            // If one fails, throw an exception to trigger the rollback immediately
                            throw new Exception("Registration no. format rejected in row #$rowNo: " . implode(", ", $data) . ". Please check your CSV file.");
                        }

                        $registration = $data[0];
                        $make = $data[1];
                        $model = $data[2];
                        $ownership = $data[3];
                        $region = $data[4];
                        $station = $data[5];
                        $region = trim($region);

                        //validate region
                        if (!in_array((strtolower($region)), $allowed_regions, true)) {
                            // If one fails, throw an exception to trigger the rollback immediately
                            throw new Exception("Unrecognized Region in row #$rowNo: " . implode(", ", $data) . ". Please check your CSV file.");
                        }


                        $registration = strtoupper($registration);
                        $registration = str_replace(" ", "", $registration);
                        $registration = strtoupper($registration);
                        $datasource = 'manual entry (uploaded via csv)';

                        //check if exists
                        $ifExists = countRecords('vehicles', 'registration', $registration);
                        if ($ifExists == 0) {
                            $stmt = $conn->prepare("INSERT INTO vehicles(registration, make, model, ownership, region, station, data_source, created_by, created_on)
                                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param(
                                "sssssssss",
                                $registration,
                                $make,
                                $model,
                                $ownership,
                                $region,
                                $station,
                                $datasource,
                                $currentUserAdAccount,
                                $timestamp
                            );

                            if ($stmt->execute()) {
                                $countInserted++;
                                $insertedRecords[] = $registration;
                            } else {
                                // If one insertion fails, throw an exception to trigger the rollback immediately
                                throw new Exception("Insertion failed for registration: " . $registration);
                            }
                        } else {
                            $countDuplicated++;
                        }
                    }
                    $skip++;
                }

                if ($countInserted < 1) {
                    exitWithError("400", "Data not saved", "0 rows inserted. $countDuplicated duplicates found.");
                } else {
                    exitWithSuccess("$countInserted vehicles uploaded successfully. $countDuplicated duplicates found.");
                }
            }
//            $conn->commit();
//            $conn->autocommit(TRUE);
        } catch (Exception $e) {
//            $conn->rollback();
//            $conn->autocommit(TRUE);

            foreach ($insertedRecords as $reg) {
                $deleteStmt = $conn->prepare("DELETE FROM vehicles WHERE created_on = ?");
                $deleteStmt->bind_param("s", $timestamp);
                $deleteStmt->execute();
            }

            exitWithError('400', "Exception/ data error occurred",  $e->getMessage());
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

    logAction($currentUserAdAccount ?? null, $currentUserId ?? null, $token ?? null, $httpResponseCode, "Vehicles upload failed: Error: $error. Message $message");

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

    logAction($currentUserAdAccount ?? null, $currentUserId ?? null, $token ?? null, $httpResponseCode, "Uploaded vehicles. $message");
    exit;
}
