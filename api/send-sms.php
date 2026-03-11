<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config.php';
require_once __DIR__ . '/../functions.php';

$response_array = [];
$httpResponseCode = 400;
global $conn;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $headers = getallheaders();
    if (!isset($headers['Authorization']) || substr($headers['Authorization'], 0, 7) !== 'Bearer ') {
        http_response_code(401);
        echo json_encode(['error' => 'AUTH Error', 'message' => 'Authorization header missing or invalid']);
        exit;
    }

    $token = trim(substr($headers['Authorization'], 7));

    if (!isTokenValid($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'AUTH Error', 'message' => 'Invalid or expired token']);
        exit;
    }

    if (!refreshToken($token)) {
        http_response_code(500);
        echo json_encode(['error' => 'Token Refresh Error', 'message' => 'Unable to refresh token']);
        exit;
    }

    try{
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $sentBy = getProperties('tokens', 'user_ad_account', 'token', $token);

        $recipientTelephone = $_POST['recipient_phone'] ?? '';
        $message = $_POST['message'] ?? '';

        $errors = [];

        $recipientTelephone = str_replace([' ', '-', '(', ')'], '', $recipientTelephone);

        if (empty($recipientTelephone)) {
            $errors[] = "Recipient phone number is required.";
        } elseif (!ctype_digit($recipientTelephone)) {
            $errors[] = "Phone number must contain digits only.";
        } elseif (substr($recipientTelephone, 0, 3) !== '254') {
            $errors[] = "Phone number must start with 254.";
        } elseif (!preg_match('/^\d{12}$/', $recipientTelephone)) {
            $errors[] = "Phone number must be exactly 12 digits long.";
        }

        if (empty($message)) {
            $errors[] = "Message cannot be empty.";
        } elseif (strlen($message) > 320) {
            $errors[] = "Message is too long.";
        }

        if (!empty($errors)) {
            $response_array = [
                'success' => false,
                'errors' => $errors
            ];
            http_response_code(400);
            echo json_encode($response_array);
            exit;
        }

        $smsappname;      
        $smsapppassword;  
        $smsappurl;       
        $lasteditedby;    
        $lasteditedon;  

        date_default_timezone_set("Africa/Nairobi");
        $sentTimestamp = date("Y-m-d H:i:s");

        $success = $error = '';
        $resp_status = '0';
        $resp_message = '';
        $resp_safaricom = '';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $smsappurl); 
        curl_setopt($ch, CURLOPT_USERPWD, $smsappname . ":" . $smsapppassword); 
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "phonenumber=$recipientTelephone&message=$message");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec($ch);

        if ($server_output === false) {
            throw new Exception("CURL Error: " . curl_error($ch));
        }

        curl_close($ch);

        if (!empty($server_output)) {
            $decodedData = json_decode($server_output);
            $resp_status = $decodedData->status ?? '0';
            $resp_message = $decodedData->message ?? '';
            $resp_data = $decodedData->data ?? null;
            $resp_safaricom = $resp_data->safaricom ?? '';

            if ($resp_status == '1') {
                $success = "Status: $resp_status, Saf: $resp_safaricom: sent to $recipientTelephone";
            } else {
                $error .= "Could not send message. Status: $resp_status, Message: $resp_message";
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO sms_logs
            (recipient_telephone, message, response_status, response_message, response_safaricom, success, error, sent_timestamp, sent_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sssssssss",
            $recipientTelephone,
            $message,
            $resp_status,
            $resp_message,
            $resp_safaricom,
            $success,
            $error,
            $sentTimestamp,
            $sentBy
        );
        $stmt->execute();

        $response_array = [
            'success' => $resp_status == '1',
            'message' => "SMS sent to $recipientTelephone",
            'status' => $resp_status,
            'response' => $resp_safaricom,
            'error' => $error
        ];

        $httpResponseCode = 200;
    } catch(Exception $ex){
        $httpResponseCode = 500;
        $response_array = [
            'error' => 'Error occurred',
            'message' => 'Error occurred while processing your request. ' . $ex->getMessage()
        ];
    }

} else {
    $httpResponseCode = 400;
    $response_array = [
        "error" => "Bad Request",
        "message" => "The request cannot be fulfilled due to bad method."
    ];
}

http_response_code($httpResponseCode);
echo json_encode($response_array);
