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
//require_once __DIR__ . '/../vendor/autoload.php';

$response_array = [];
$httpResponseCode = 400;
global $conn;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    try {

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

        $sentBy = getProperties('tokens', 'user_ad_account', 'token', $token);

        $inputJSON = file_get_contents('php://input');
        $input = json_decode($inputJSON, true);

        $recipients = $input['recipients'] ?? [];

        if (empty($recipients) || !is_array($recipients)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No recipients provided']);
            exit;
        }

        $results = [];

        foreach ($recipients as $r) {
            $recipientEmail = str_replace(" ", "", $r['email'] ?? '');
            $subject = $r['subject'] ?? '';
            $message_ = $r['body'] ?? '';

            if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $results[] = [
                    'recipient' => $recipientEmail,
                    'status' => 'failed',
                    'error' => 'Invalid email address'
                ];
                continue;
            }

            $stmt = $conn->prepare("
                INSERT INTO emails_queue (email, subject, message,queued_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->bind_param("sss", $recipientEmail, $subject, $message_);
            $stmt->execute();

            $results[] = [
                'recipient' => $recipientEmail,
                'status' => 'Email sent successfully',
                'error' => ''
            ];
        }

        $response_array = [
            'success' => true,
            'results' => $results
        ];
        $httpResponseCode = 200;

    } catch (Exception $ex) {
        $httpResponseCode = 500;
        $response_array = [
            'error' => 'Error occurred',
            'message' => 'Exception occurred while processing your request. ' . $ex->getMessage()
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
?>
