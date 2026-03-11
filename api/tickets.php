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

require '../config.php';

$response_array = [];
$error = $message = $output = "";
global $conn;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
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


                    $currentUserAdAccount = getProperties('tokens', 'user_ad_account', 'token', $token);
                    $currentByUserId = getProperties('users', 'id', 'ad_account_no', $currentUserAdAccount);

                    //process Actual API
                    try {
                        $sql = "SELECT * FROM tickets ORDER BY id DESC";
                        if (isset($_GET['id'])) {
                            $ticketid = $_GET['id'];
                            $sql = "SELECT * FROM tickets WHERE id=$ticketid";
                        }
                        if (isset($_GET['raised_by_user_id'])) {
                            $raisedbyuserid = $_GET['raised_by_user_id'];
                            $sql = "SELECT * FROM tickets WHERE raised_by_user_id=$raisedbyuserid";
                        }
                        if (isset($_GET['vehicle_id'])) {
                            $vehicleid_ = $_GET['vehicle_id'];
                            $sql = "SELECT * FROM tickets WHERE vehicle_id=$vehicleid_";
                        }

                        $result = $conn->query($sql);
                        $tickets = []; // Initialize an array to hold all ticket data
                        if ($result) {
                            $countTickets = $result->num_rows;
                            if ($result->num_rows > 0) {

                                while ($row = $result->fetch_assoc()) {
                                    $tid = $row['id'];
                                    $raisedbyid = $row['raised_by_user_id'];
                                    $JIbyid = $row['joint_inspection_by_user_id'];
                                    $CQCuserid = $row['contract_quotation_confirmation_by_user_id'];
                                    $DCuserid = $row['defect_confirmation_by_user_id'];
                                    $RCbyuserid = $row['repair_recommendation_by_user_id'];
                                    $RAbyid = $row['repair_approval_by_user_id'];
                                    $HDuserid = $row['help_desk_user_id'];
                                    $vehicleid = $row['vehicle_id'];

                                    $vehicle_reg = getProperties('vehicles', 'registration', 'id', $vehicleid);
                                    $vehicle_make = getProperties('vehicles', 'make', 'id', $vehicleid);
                                    $vehicle_model = getProperties('vehicles', 'model', 'id', $vehicleid);

                                    $RAbyname = getProperties('users', 'full_name', 'id', $RAbyid);
                                    $RAbypno = getProperties('users', 'ad_account_no', 'id', $RAbyid);

                                    $RCbyusername = getProperties('users', 'full_name', 'id', $RCbyuserid);
                                    $RCbyuserpno = getProperties('users', 'ad_account_no', 'id', $RCbyuserid);

                                    $DCusername = getProperties('users', 'full_name', 'id', $DCuserid);
                                    $DCuserpno = getProperties('users', 'ad_account_no', 'id', $DCuserid);

                                    $CQCusername = getProperties('users', 'full_name', 'id', $CQCuserid);
                                    $CQCuserpno = getProperties('users', 'ad_account_no', 'id', $CQCuserid);

                                    $raisedbyname = getProperties('users', 'full_name', 'id', $raisedbyid);
                                    $raisedbypno = getProperties('users', 'ad_account_no', 'id', $raisedbyid);

                                    $JIbyname = getProperties('users', 'full_name', 'id', $JIbyid);
                                    $JIbypno = getProperties('users', 'ad_account_no', 'id', $JIbyid);

                                    $HDname = getProperties('users', 'full_name', 'id', $HDuserid);
                                    $HDpno = getProperties('users', 'ad_account_no', 'id', $HDuserid);


                                    $reference = (string)$tid;
                                    $reference = str_pad($reference, 4, '0', STR_PAD_LEFT);
                                    $reference ="KRA/Transport/$reference";

                                    $tickets[] = [
                                        'id' => $tid,
                                        'reference' => $reference,
                                        'vehicle_id' => $row['vehicle_id'],
                                        'vehicle_registration' => $vehicle_reg,
                                        'vehicle_make' => $vehicle_make,
                                        'vehicle_model' => $vehicle_model,
                                        'vehicle_region' => $row['vehicle_region'],
                                        'raised_by_user_id' => $raisedbyid,
                                        'raised_by_user_name' => $raisedbyname,
                                        'raised_by_user_pno' => $raisedbypno,
                                        'raised_by_user_region' => $row['user_region'],
                                        'category' => $row['category'],
                                        'observations' => $row['observations'],
                                        'attachment_1' => $row['attachment_1'],
                                        'attachment_2' => $row['attachment_2'],
                                        'attachment_3' => $row['attachment_3'],
                                        'ticket_raiser_last_edited_on' => $row['ticket_raiser_last_edited'],
                                        'submission_status' => $row['submission_status'],
                                        'submission_time' => $row['submission_time'],
                                        'raised_on' => $row['raised_on'],

                                        'last_repair_date' => $row['last_repair_date'],
                                        'mileage_at_last_repair' => $row['mileage_at_last_repair'],
                                        'current_mileage' => $row['current_mileage'],
                                        'joint_inspection_remarks' => $row['joint_inspection_remarks'],
                                        'joint_inspection_attachment' => $row['joint_inspection_attachment'],
                                        'joint_inspection_by_user_id' => $JIbyid,
                                        'joint_inspection_by_user_name' => $JIbyname,
                                        'joint_inspection_by_user_pno' => $JIbypno,
                                        'joint_inspection_last_timestamp' => $row['joint_inspection_timestamp'],
                                        'joint_inspection_last_edited' => $row['joint_inspection_last_edited'],

                                        'defect_confirmation_notes' => $row['defect_confirmation_notes'],
                                        'defect_confirmation_attachment' => $row['defect_confirmation_attachment'],
                                        'defect_confirmation_quotation_repair_cost' => $row['defect_confirmation_quotation_repair_cost'],
                                        'defect_confirmation_vendor' => $row['defect_confirmation_vendor'],
                                        'defect_confirmation_current_spend' => $row['defect_confirmation_current_spend'],
                                        'defect_confirmation_timestamp' => $row['defect_confirmation_timestamp'],
                                        'defect_confirmation_by_user_id' => $DCuserid,
                                        'defect_confirmation_by_user_name' => $DCusername,
                                        'defect_confirmation_by_user_pno' => $DCuserpno,
                                        'defect_confirmation_last_edited' => $row['joint_inspection_last_edited'],

                                        'contract_quotation_confirmation_status' => $row['contract_quotation_confirmation_status'],
                                        'contract_quotation_confirmation_remarks' => $row['contract_quotation_confirmation_remarks'],
                                        'contract_quotation_confirmation_by_user_id' => $CQCuserid,
                                        'contract_quotation_confirmation_by_user_name' => $CQCusername,
                                        'contract_quotation_confirmation_by_user_pno' => $CQCuserpno,
                                        'contract_quotation_confirmation_timestamp' => $row['contract_quotation_confirmation_timestamp'],
                                        'contract_quotation_confirmation_last_edited' => $row['contract_quotation_confirmation_last_edited'],

                                        'repair_recommendation_status' => $row['repair_recommendation_status'],
                                        'repair_recommendation_repair_cost' => $row['repair_recommendation_repair_cost'],
                                        'repair_recommendation_budget_allocation' => $row['repair_recommendation_budget_allocation'],
                                        'repair_recommendation_by_user_id' => $RCbyuserid,
                                        'repair_recommendation_by_user_name' => $RCbyusername,
                                        'repair_recommendation_by_user_pno' => $RCbyuserpno,
                                        'repair_recommendation_timestamp' => $row['repair_recommendation_timestamp'],
                                        'repair_recommendation_last_edited' => $row['repair_recommendation_last_edited'],

                                        'repair_approval_status' => $row['repair_approval_status'],
                                        'repair_approval_amount' => $row['repair_approval_amount'],
                                        'repair_approval_remarks' => $row['repair_approval_remarks'],
                                        'repair_approval_by_user_id' => $RAbyid,
                                        'repair_approval_by_user_name' => $RAbyname,
                                        'repair_approval_by_user_pno' => $RAbypno,
                                        'repair_approval_timestamp' => $row['repair_approval_timestamp'],
                                        'repair_approval_last_edited' => $row['repair_approval_last_edited'],

                                        'help_desk_user_id' => $HDuserid,
                                        'help_desk_user_name' => $HDname,
                                        'help_desk_user_pno' => $HDpno,
                                        'help_desk_po_no' => $row['help_desk_po_no'],
                                        'help_desk_mo_no' => $row['help_desk_mo_no'],
                                        'help_desk_po_amount' => $row['help_desk_po_amount'],
                                        'help_desk_remarks' => $row['help_desk_remarks'],
                                        'help_desk_attachment' => $row['help_desk_attachment'],
                                        'help_desk_timestamp' => $row['help_desk_timestamp'],
                                        'help_desk_last_edited' => $row['help_desk_last_edited'],

                                        'tracking_progress_level' => $row['tracking_progress_level'],
                                        'tracking_progress' => $row['tracking_progress']
                                    ];
                                }

                                $result->free(); // Free result set memory

                            } else {
                                //zero results
                                $httpResponseCode = 200;
                                $tickets = [
                                    'message' => "0 results",
                                ];
                            }
                            $httpResponseCode = 200;
                            $response_array = $tickets;

                            logAction($currentUserAdAccount, $currentByUserId, $token, '200', "Fetching tickets. $countTickets results found.");

                        } else {
                            $httpResponseCode = 500;
                            $error = "Query failed";
                            $message = "Query failed: " . $conn->error;
                        }
                    } catch (mysqli_sql_exception $e) {
                        // 2. Catch specific MySQLi exceptions (e.g., failed connection, SQL syntax error)
                        $httpResponseCode = 500;
                        $error = "Query failed.";
                        $message = "Database error: " . $e->getMessage() . $e->getCode();
                    } catch (Exception $ex) {
                        $httpResponseCode = 500;
                        $error = "Error occurred";
                        $message = "Exception occurred while processing your request." . $ex->getMessage();
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

    logAction($currentUserAdAccount ?? null, $currentByUserId ?? null, $token ?? null, $httpResponseCode, "Fetching tickets failed: Error: $error. Message $message");

}

//output
$output = json_encode($response_array);
header('Content-Type: application/json');
echo $output;