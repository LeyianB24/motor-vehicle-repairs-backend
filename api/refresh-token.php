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

date_default_timezone_set("Africa/Nairobi");
$timestamp = date("Y-m-d H:i:s");

$output = "";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Retrieve the Authorization header
    $headers = getallheaders();
    if (!array_key_exists('Authorization', $headers)) {
        http_response_code(401);
        $output = json_encode(["error" => "Authorization header is missing"]);
    } else {
        if (substr($headers['Authorization'], 0, 7) !== 'Bearer ') {
            http_response_code(401);
            $output = json_encode(["error" => "Token keyword is missing"]);
        } else {
            $token = trim(substr($headers['Authorization'], 7)); // Extract the token from the header

            require '../config.php';
            $expiryTimestamp = date("Y-m-d H:i:s", strtotime($timestamp) + $tokenRefreshDuration); // Add seconds based on the duration set in '../config.php'

            //check if token is valid
            $stmt = $conn->prepare("SELECT * FROM tokens WHERE token = ? AND status='active'"); //do not check if expired
            $stmt->bind_param("s", $token, );
            $stmt->execute();
            $resultsToken = $stmt->get_result();

            if ($resultsToken->num_rows != 1) {
                http_response_code(401);
                $output = json_encode(["error" => "Invalid token."]);
            } else {
                // Update token's last used time &  expiry time
                $stmtUpdate = $conn->prepare("UPDATE tokens SET last_used= ?, expires_on=? WHERE token = ?");
                $stmtUpdate->bind_param("sss", $timestamp, $expiryTimestamp, $token);
                if ($stmtUpdate->execute()) {
                    http_response_code(200);
                    $output = json_encode(["success" => "Token refreshed successfully. To: $expiryTimestamp."]);
                } else {
                    http_response_code(500);
                    $output = json_encode(["error" => "Failed to update token information"]);
                }
            }
        }
    }
} else {
    http_response_code(400);
    $output = json_encode(["error" => "The request cannot be fulfilled due to bad syntax."]);
}

echo $output;
