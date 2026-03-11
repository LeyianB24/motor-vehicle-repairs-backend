<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config.php';
require '../auth-helper.php';

global $conn;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(400);
    echo json_encode([
        "message" => "Invalid method",
        "status" => "Failed",
        "error" => "Bad Request",
        "code" => 400
    ]);
    exit;
}

$auth = authenticateRequest();
if (!$auth["status"]) {
    http_response_code($auth["code"]);
    echo json_encode([
        "error" => $auth["error"],
        "message" => $auth["message"],
        "code" => $auth["code"]
    ]);
    exit;
}

try {
    try {
        $currentMonth = (int)date('n');
         $currentYear  = (int) date('Y');
//        $currentMonth = 7;
//        $currentYear = 2022;
        if (isset($_GET['year'])) {
            $year = (int)$_GET['year'];
        } else {
            $year = ($currentMonth >= 7) ? $currentYear : ($currentYear - 1);
        }

        $startDate = "$year-07-01 00:00:00";
        $endDate = ($year + 1) . "-06-30 23:59:59";

        $vehicleResult = $conn->query("SELECT COUNT(*) AS total FROM vehicles WHERE created_on BETWEEN '$startDate' AND '$endDate'");
        $vehicles = $vehicleResult->fetch_assoc()['total'] ?? 0;

        $activeVehiclesResult = $conn->query("
            SELECT COUNT(*) AS total 
            FROM vehicles 
            WHERE status='active' 
        ");
        $activeVehicles = $activeVehiclesResult->fetch_assoc()['total'] ?? 0;

        $inactiveVehiclesResult = $conn->query("
            SELECT COUNT(*) AS total 
            FROM vehicles 
            WHERE status='inactive' 
            AND created_on BETWEEN '$startDate' AND '$endDate'
        ");
        $inactiveVehicles = $inactiveVehiclesResult->fetch_assoc()['total'] ?? 0;

        $userResult = $conn->query("SELECT COUNT(*) AS total FROM users");
        $users = $userResult->fetch_assoc()['total'] ?? 0;

        $totalTicketsResult = $conn->query("
            SELECT COUNT(*) AS total 
            FROM tickets 
            WHERE raised_on BETWEEN '$startDate' AND '$endDate'
        ");
        $totalTickets = $totalTicketsResult->fetch_assoc()['total'] ?? 0;

        $ticketStatusResult = $conn->query("
            SELECT 
                SUM(CASE WHEN LOWER(tracking_progress) LIKE '%draft%' THEN 1 ELSE 0 END) AS draft,
                SUM(CASE WHEN LOWER(tracking_progress) LIKE '%rejected%' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN LOWER(tracking_progress) LIKE '%closed%' THEN 1 ELSE 0 END) AS closed
            FROM tickets
            WHERE raised_on BETWEEN '$startDate' AND '$endDate'
        ");

        $ticketsByStatus = $ticketStatusResult->fetch_assoc() ?? ['draft' => 0, 'open' => 0, 'rejected' => 0, 'closed' => 0];

        $ticketCategoryResult = $conn->query("
            SELECT 
                SUM(CASE WHEN LOWER(category) LIKE '%minor%' THEN 1 ELSE 0 END) AS minor,
                SUM(CASE WHEN LOWER(category) LIKE '%medium%' THEN 1 ELSE 0 END) AS medium,
                SUM(CASE WHEN LOWER(category) LIKE '%major%' THEN 1 ELSE 0 END) AS major,
                SUM(CASE WHEN LOWER(category) LIKE '%tyre%' THEN 1 ELSE 0 END) AS tyres,
                SUM(CASE WHEN LOWER(category) LIKE '%repair%' THEN 1 ELSE 0 END) AS repairs
            FROM tickets
            WHERE raised_on BETWEEN '$startDate' AND '$endDate'
        ");

        $ticketsByCategory = $ticketCategoryResult->fetch_assoc() ?? [
            'minor' => 0,
            'medium' => 0,
            'major' => 0,
            'tyres' => 0,
            'repairs' => 0
        ];

        //sum of categories
        $totalCategoryTickets =
            (int)$ticketsByCategory['minor'] +
            (int)$ticketsByCategory['medium'] +
            (int)$ticketsByCategory['major'] +
            (int)$ticketsByCategory['tyres'] +
            (int)$ticketsByCategory['repairs'];

        $lastTicketsResult = $conn->query("
            SELECT t.id, v.registration AS vehicle_registration, t.submission_status
            FROM tickets t
            JOIN vehicles v ON t.vehicle_id = v.id
            WHERE t.raised_on BETWEEN '$startDate' AND '$endDate'
            ORDER BY t.raised_on DESC
            LIMIT 3
        ");

        $lastTickets = [];
        if ($lastTicketsResult && $lastTicketsResult->num_rows > 0) {
            while ($row = $lastTicketsResult->fetch_assoc()) {
                $lastTickets[] = [
                    "id" => (int)$row['id'],
                    "vehicle_registration" => $row['vehicle_registration'],
                    "submission_status" => $row['submission_status'],
                ];
            }
        }

        $openTickets = $totalTickets - ($ticketsByStatus['rejected'] + $ticketsByStatus['closed']);

        $response = [
            "status" => "Success",
            "code" => 200,
            "message" => "Summary report fetched successfully",
            "period" => [
                "year" => $year,
                "start_date" => $startDate,
                "end_date" => $endDate
            ],
            "summary" => [
                "vehicles" => [
                    "total" => (int)$vehicles,
                    "active" => (int)$activeVehicles,
                    "inactive" => (int)$inactiveVehicles,
                ],
                "users" => (int)$users,
                "tickets" => [
                    "total" => (int)$totalTickets,
                    "draft" => (int)$ticketsByStatus['draft'],
                    "open" => (int)($openTickets),
                    "rejected" => (int)$ticketsByStatus['rejected'],
                    "closed" => (int)$ticketsByStatus['closed'],
                    "last_three" => $lastTickets
                ],
                "categories" => [
                    "total" => (int)$totalCategoryTickets,
                    "minor" => (int)$ticketsByCategory['minor'],
                    "medium" => (int)$ticketsByCategory['medium'],
                    "major" => (int)$ticketsByCategory['major'],
                    "tyres" => (int)$ticketsByCategory['tyres'],
                    "repairs" => (int)$ticketsByCategory['repairs'],
                ]
            ]
        ];

        http_response_code(200);
        echo json_encode($response);
        exit;

    } catch (mysqli_sql_exception $e) {
        throw new Exception("Database query failed: " . $e->getMessage(), 500);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "error" => "Database error",
        "message" => $e->getMessage(),
        "code" => $e->getCode() ?: 500
    ]);
    exit;
}
