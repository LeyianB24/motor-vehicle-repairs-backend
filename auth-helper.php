<?php
require_once "../config.php";

function authenticateRequest()
{
    global $conn;

    $headers = getallheaders();

    if (!array_key_exists('Authorization', $headers)) {
        return [
            "status" => false,
            "code" => 401,
            "error" => "AUTH Error",
            "message" => "Authorization header is missing"
        ];
    }

    if (substr($headers['Authorization'], 0, 7) !== 'Bearer ') {
        return [
            "status" => false,
            "code" => 401,
            "error" => "AUTH Error",
            "message" => "Token keyword is missing"
        ];
    }

    $token = trim(substr($headers['Authorization'], 7));

    if (!isTokenValid($token)) {
        return [
            "status" => false,
            "code" => 401,
            "error" => "AUTH Error",
            "message" => "Invalid/expired token"
        ];
    }

    if (!refreshToken($token)) {
        return [
            "status" => false,
            "code" => 500,
            "error" => "Token Refresh Error",
            "message" => "Query failed: " . $conn->error
        ];
    }

    return [
        "status" => true,
        "token" => $token
    ];
}
