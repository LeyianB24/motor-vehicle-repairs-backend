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
require_once '../vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;

$response_array = [];
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

// Filters
$start_date        = $_GET['start_date'] ?? null;
$end_date          = $_GET['end_date'] ?? null;
$vehicle_id        = $_GET['vehicle_id'] ?? null;
$vehicle_region    = $_GET['vehicle_region'] ?? null;
$raised_by_user_id = $_GET['user_id'] ?? null;  
$tracking_progress = $_GET['tracking_progress'] ?? null;
$category          = $_GET['category'] ?? null;
$vendor            = $_GET['defect_confirmation_vendor'] ?? null;
$amount            = $_GET['amount'] ?? null;
$export_type       = $_GET['export'] ?? null;

// Date validation
if (empty($start_date) || empty($end_date)) {
    http_response_code(400);
    echo json_encode([
        "status" => "Failed",
        "error" => "Validation Error",
        "message" => "start_date and end_date are required and cannot be empty.",
        "code" => 400
    ]);
    exit;
}

$start_valid = DateTime::createFromFormat('Y-m-d', $start_date);
$end_valid   = DateTime::createFromFormat('Y-m-d', $end_date);
if (!$start_valid || $start_valid->format('Y-m-d') !== $start_date ||
    !$end_valid || $end_valid->format('Y-m-d') !== $end_date ||
    strtotime($end_date) < strtotime($start_date)) {
    http_response_code(400);
    echo json_encode([
        "status" => "Failed",
        "error" => "Validation Error",
        "message" => "Invalid dates. Ensure YYYY-MM-DD format and end_date >= start_date.",
        "code" => 400
    ]);
    exit;
}

// Fetch tickets from DB
try {
    $sql = "SELECT * FROM tickets WHERE record_status='active'";
    $params = [];
    if ($start_date) { $sql .= " AND DATE(raised_on) >= ?"; $params[] = $start_date; }
    if ($end_date)   { $sql .= " AND DATE(raised_on) <= ?"; $params[] = $end_date; }
    if ($vehicle_id) {
    if (is_numeric($vehicle_id)) {
            $sql .= " AND vehicle_id = ?";
            $params[] = $vehicle_id;
        } else {
            $sql .= " AND vehicle_id IN (SELECT id FROM vehicles WHERE registration LIKE ?)";
            $params[] = "%{$vehicle_id}%";
        }
    }
    if ($vehicle_region) { $sql .= " AND vehicle_region = ?"; $params[] = $vehicle_region; }
    if ($raised_by_user_id) {
    if (is_numeric($raised_by_user_id)) {
            $sql .= " AND raised_by_user_id = ?";
            $params[] = $raised_by_user_id;
        } else {
            $sql .= " AND raised_by_user_id IN (SELECT id FROM users WHERE full_name LIKE ?)";
            $params[] = "%{$raised_by_user_id}%";
        }
    }
    if ($tracking_progress) { $sql .= " AND tracking_progress = ?"; $params[] = $tracking_progress; }
    if ($category) { $sql .= " AND category = ?"; $params[] = $category; }
    if ($vendor) { $sql .= " AND defect_confirmation_vendor = ?"; $params[] = $vendor; }
    if ($amount) { $sql .= " AND repair_approval_amount = ?"; $params[] = $amount; }
    $sql .= " ORDER BY id ASC";

    $stmt = $conn->prepare($sql);
    if ($params) {
        $types = str_repeat("s", count($params));
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) throw new mysqli_sql_exception("Query failed: " . $stmt->error);

    $result = $stmt->get_result();
    $tickets = [];
    while ($row = $result->fetch_assoc()) {
        $vehicle_registration = '';
        $stmtVehicle = $conn->prepare("SELECT registration FROM vehicles WHERE id = ?");
        $stmtVehicle->bind_param("s", $row['vehicle_id']);
        $stmtVehicle->execute();
        $resVehicle = $stmtVehicle->get_result();
        if ($vRow = $resVehicle->fetch_assoc()) {
            $vehicle_registration = $vRow['registration'];
        }
        $stmtVehicle->close();

        $tickets[] = [
        "id" => $row['id'],
        "vehicle_id" => $row['vehicle_id'], 
        "vehicle_registration" => $vehicle_registration, 
        "vehicle_region" => $row['vehicle_region'],

        "raised_by_user_id" => $row['raised_by_user_id'],
        "raised_by_user_name" => getProperties('users','full_name','id',$row['raised_by_user_id']),

        "category" => $row['category'],
        "tracking_progress" => $row['tracking_progress'],

        "observations" => $row['observations'],

        "joint_inspection_by_user_id" => $row['joint_inspection_by_user_id'],
        "joint_inspection_timestamp" => $row['joint_inspection_timestamp'],

        "defect_confirmation_vendor" => $row['defect_confirmation_vendor'],
        "repair_cost" => $row['defect_confirmation_quotation_repair_cost'],
        "defect_confirmation_by_user_id" => $row['defect_confirmation_by_user_id'],
        "defect_confirmation_timestamp" => $row['defect_confirmation_timestamp'],

        "repair_recommendation_by_user_id" => $row['repair_recommendation_by_user_id'],
        "repair_recommendation_timestamp" => $row['repair_recommendation_timestamp'],

        "repair_approval_status" => $row['repair_approval_status'],
        "repair_approval_amount" => $row['repair_approval_amount'],
        "repair_approval_by_user_id" => $row['repair_approval_by_user_id'],
        "repair_approval_timestamp" => $row['repair_approval_timestamp'],

        "raised_on" => $row['raised_on'],
        "submission_status" => $row['submission_status'],
        "submission_time" => $row['submission_time'],
        "last_repair_date" => $row['last_repair_date'],
        "vehicle_current_mileage" => $row['current_mileage'],
        "help_desk_assigned" => $row['help_desk_user_id'],
        "po_number" => $row['help_desk_po_no'],
        "mo_number" => $row['help_desk_mo_no'],
        "po_amount" => $row['help_desk_po_amount'],
        "remarks" => $row['help_desk_remarks']
    ];

    }

    // PDF Export
    if ($export_type === 'pdf') {

        require_once __DIR__ . '/../vendor/autoload.php';

        // landscape orientation
        $mpdf = new \Mpdf\Mpdf([
            'orientation' => 'L',
            'tempDir' => __DIR__ . '/../tmp'
        ]);

        $ticketsTableHtml = "<table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Vehicle</th>
            <th>Region</th>
            <th>Raised On</th>
            <th>Raised By</th>
            <th>Category</th>
            <th>Observations</th>
            <th>Tracking Progress</th>
            <th>Approved Amount(KES)</th>
            <th>Submission Time</th>
        </tr>
        </thead>
        <tbody>";

        $counter = 1;
        foreach ($tickets as $t) {
            $ticketsTableHtml .= "
            <tr>
                <td>{$counter}</td>
                <td>{$t['vehicle_registration']}</td>
                <td>{$t['vehicle_region']}</td>
                <td>{$t['raised_on']}</td>
                <td>{$t['raised_by_user_name']}</td>
                <td>{$t['category']}</td>
                <td>{$t['observations']}</td>
                <td>{$t['tracking_progress']}</td>
                <td>{$t['repair_approval_amount']}</td>
                <td>{$t['submission_time']}</td>
            </tr>";
            $counter++;
        }
        
        $ticketsTableHtml .= "</tbody></table>";

        $start_date = $_GET['start_date'] ?? null;
        $end_date   = $_GET['end_date'] ?? null;

        ob_start();
        include '../templates/tickets_template.php';
        $html = ob_get_clean();

        $mpdf->WriteHTML($html);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="kra_motor_vehicle_repairs_tickets_report.pdf"');

        $mpdf->Output();
        exit;
    }

    // Excel Export
    if ($export_type === 'excel') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'ID','Vehicle Registration','Region','Raised By','Category','Status',
            'Submission Time','Tracking Progress','Last Repair Date','Mileage','Observations',
            'Joint Inspection By','Joint Inspection Timestamp','Vendor','Repair Cost(KES)',
            'Defect Confirmation By','Defect Confirmation Timestamp','Repair Recommendation By',
            'Repair Recommendation Timestamp','Approval Status','Repair Approval Amount(KES)','Repair Approval By',
            'Repair Approval Timestamp','Help Desk Assigned','PO_Number','MO_Number','PO_Amount(KES)','Remarks'
        ];

        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col.'1', $h);
            $col++;
        }

        $rowNum = 2;
        foreach ($tickets as $ticket) {
            $sheet->setCellValue('A'.$rowNum, $ticket['id'])
                ->setCellValue('B'.$rowNum, $ticket['vehicle_registration'])
                ->setCellValue('C'.$rowNum, $ticket['vehicle_region'])
                ->setCellValue('D'.$rowNum, $ticket['raised_by_user_name'])
                ->setCellValue('E'.$rowNum, $ticket['category'])
                ->setCellValue('F'.$rowNum, $ticket['submission_status'])
                ->setCellValue('G'.$rowNum, $ticket['submission_time'])
                ->setCellValue('H'.$rowNum, $ticket['tracking_progress'])
                ->setCellValue('I'.$rowNum, $ticket['last_repair_date'])
                ->setCellValue('J'.$rowNum, $ticket['vehicle_current_mileage'])
                ->setCellValue('K'.$rowNum, $ticket['observations'])
                ->setCellValue('L'.$rowNum, getProperties('users','full_name','id',$ticket['joint_inspection_by_user_id']))
                ->setCellValue('M'.$rowNum, $ticket['joint_inspection_timestamp'])
                ->setCellValue('N'.$rowNum, $ticket['defect_confirmation_vendor'])
                ->setCellValue('O'.$rowNum, $ticket['repair_cost'])
                ->setCellValue('P'.$rowNum, getProperties('users','full_name','id',$ticket['defect_confirmation_by_user_id']))
                ->setCellValue('Q'.$rowNum, $ticket['defect_confirmation_timestamp'])
                ->setCellValue('R'.$rowNum, getProperties('users','full_name','id',$ticket['repair_recommendation_by_user_id']))
                ->setCellValue('S'.$rowNum, $ticket['repair_recommendation_timestamp'])
                ->setCellValue('T'.$rowNum, $ticket['repair_approval_status'])
                ->setCellValue('U'.$rowNum, $ticket['repair_approval_amount'])
                ->setCellValue('V'.$rowNum, getProperties('users','full_name','id',$ticket['repair_approval_by_user_id']))
                ->setCellValue('W'.$rowNum, $ticket['repair_approval_timestamp'])
                ->setCellValue('X'.$rowNum, getProperties('users','full_name','id',$ticket['help_desk_assigned']))
                ->setCellValue('Y'.$rowNum, $ticket['po_number'])
                ->setCellValue('Z'.$rowNum, $ticket['mo_number'])
                ->setCellValue('AA'.$rowNum, $ticket['po_amount'])
                ->setCellValue('AB'.$rowNum, $ticket['remarks']);
            $rowNum++;
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="kra_motor_vehicle_repairs_tickets_report.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // JSON response
    http_response_code(200);
    echo json_encode([
        "message" => "Reports fetched successfully",
        "status" => "Success",
        "code" => 200,
        "criteria" => $_GET,
        "count" => count($tickets),
        "tickets" => $tickets
    ]);
    exit;

} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "Failed",
        "error" => "Database error",
        "message" => $e->getMessage(),
        "code" => 500
    ]);
    exit;
}


