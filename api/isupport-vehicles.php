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

include '../config.php';
global $conn, $base_url, $sap_client, $client_password, $client_username;
$response_array = [];
$message = $output = $error = $csrfToken = $cookie = $feedback = '';
$httpResponseCode = 400;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filter = '';
    if (isset($_GET['filter'])) {
        $filter = $_GET['filter'];
    }

    // Step 1: Retrieve CSRF Token
    $url_queryToken = "{$base_url}/zvehicledata/vehicle?sap-client=$sap_client";
    $ch = curl_init();
    $content_type = 'Content-Type: application/json';
    $authentication = 'Authorization: Basic ' . base64_encode($client_username . ":" . $client_password);
    $accept = 'Accept: application/json';
    $key = 'x-csrf-token: fetch';

    curl_setopt($ch, CURLOPT_URL, $url_queryToken);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array($content_type, $authentication, $accept, $key));

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        $httpResponseCode = 500;
        $error = "CURL Error occurred";
        $message = "Curl error while fetching iSupport: " . curl_error($ch);

    } else {

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($result, 0, $headerSize);
        $body = substr($result, $headerSize);

        if (preg_match('/^x-csrf-token:\s*([^\r\n]*)/mi', $headers, $matches)) {
            $csrfToken = trim($matches[1]);
        }
        if (preg_match_all('/^set-cookie:\s*([^\r\n]*)/mi', $headers, $matches)) {
            $cookie = trim($matches[1][1]);
        }
        curl_close($ch);

        if ($httpCode === 200) {
            if ($csrfToken) {
                //CSRF Successfully gotten
                // Step 2: Use CSRF Token in POST Request
                //echo "Token:" . $csrfToken;

                sendVehiclesRequest($csrfToken, $cookie, $filter);
            } else {
                $httpResponseCode = 500;
                $error = "CSRF Error";
                $message = "CSRF Token not found in headers";
            }
        } else {
            $httpResponseCode = $httpCode;
            $error = "Error occurred";
            $message = "An internal error occurred. Code";
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
} else {
//if 200
    $httpResponseCode = '200';
    http_response_code(200);

}

//output
$output = json_encode($response_array);
header('Content-Type: application/json');
echo $output;


function sendVehiclesRequest(string $csrfToken, string $cookie, string $filter)
{
    global $error, $base_url, $sap_client, $authentication, $content_type, $accept, $httpResponseCode, $message;

    $ch2 = curl_init();
    $xtoken = 'x-csrf-token: ' . $csrfToken;
    $cookie_ = 'Cookie: ' . $cookie;
    $url = "{$base_url}/zvehicledata/vehicle?sap-client=$sap_client";

    $request_data = [
        "license_plate" => $filter
    ];

    // Convert the PHP array into a JSON string
    $data_json = json_encode($request_data);

    curl_setopt($ch2, CURLOPT_URL, $url);
    curl_setopt($ch2, CURLOPT_POST, true); // Set as POST request
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HEADER, false);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, $data_json);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, array($authentication, $content_type, $accept, $xtoken, $cookie_));
    curl_setopt($ch2, CURLINFO_HEADER_OUT, true);
    $result_ = curl_exec($ch2);
    $httpCode_ = curl_getinfo($ch2, CURLINFO_HTTP_CODE);

    if (curl_errno($ch2)) {
        $httpResponseCode = 500;
        $error = "CURL Error occurred";
        $message = 'Curl error in iSupport call: ' . curl_error($ch2);

    } else {
        if ($httpCode_ === 200) {
            processResponse($result_);
        } else {
            $httpResponseCode = $httpCode_;
            $error = "Internal error occurred";
            $message = "An internal error occurred. Code: $httpCode_";
        }
    }
}


function processResponse($result)
{
    global $httpResponseCode, $error, $message, $response_array, $conn, $timestamp;
    $decoded_array = json_decode($result, true);

    try {

        // Check for JSON decoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            $httpResponseCode = 500;
            $error = "JSON Decoding erro";
            $message = "JSON Decoding Error: " . json_last_error_msg() . "\n";
        } else {
            //print_r($decoded_array);

            // Loop through the array to access individual vehicle records
            $vehicles = [];
            foreach ($decoded_array as $index => $vehicle) { //all
                // Each $vehicle is now an associative array (e.g., $vehicle['number_plate'])
                $number_plate = $vehicle['number_plate'];
                $work_center = $vehicle['work_center'];
                $vehicle_type = $vehicle['vehicle_type'];
                $company_code = $vehicle['company_code'];
                $cost_center = $vehicle['cost_center'];
                $maint_plant = $vehicle['maint_plant'];

                if (strlen($number_plate) < 10) {
                    //update vehicles tables
                    $regionname = $maint_plant;
                    if ($maint_plant) {
                        //get region name
                        $regionname = getProperties('regions', 'region_name', 'region_code', $maint_plant);

                        //check if exists
                        $number_plate = str_replace(" ", "", $number_plate);
                        $number_plate = strtoupper($number_plate);

                        $countV = countRecords('vehicles', 'registration', $number_plate);
                        if ($countV == 0) {
                            //does not exist, create new
                            $datasource = 'iSupport';
                            $createdby = "System auto from iSupport";
                            $stmt = $conn->prepare("INSERT INTO vehicles 
                                                    (registration, body_type, region, station, data_source, created_by, created_on) 
                                                       VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param(
                                "sssssss",
                                $number_plate,
                                $vehicle_type,
                                $regionname,
                                $work_center,
                                $datasource,
                                $createdby,
                                $timestamp,
                            );
                            if ($stmt->execute()) {
//                            $newUserId = $conn->insert_id; // Get the newly created ticket ID
                            } else {
                            }
                        }
                    }

                    $vehicles[] = [
                        'sn' => $index + 1,
                        'number_plate' => $number_plate,
                        'vehicle_type' => $vehicle_type,
                        'work_center' => $work_center,
                        'company_code' => $company_code,
                        'cost_center' => $cost_center,
                        'maint_plant' => $maint_plant,
                    ];
                }
            }
            $httpResponseCode = 200;
            $response_array = $vehicles;
        }
    } catch (mysqli_sql_exception $e) {
        // 2. Catch specific MySQLi exceptions (e.g., failed connection, SQL syntax error)
        $httpResponseCode = 500;
        $error = "Query failed";
        $message = "Database error: " . $e->getMessage() . $e->getCode();
    } catch (Exception $e) {
        $httpResponseCode = 500;
        $error = "Error occurred";
        $message = "An unexpected error occurred: " . $e->getMessage();
    }
}

?>
