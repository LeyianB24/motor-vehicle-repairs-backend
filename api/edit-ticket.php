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
$allowed_actions = ["service_request", "joint_inspection", "defect_confirmation", "contract_confirmation", "repair_recommendation", "repair_approval", "help_desk"];
$response_array = [];
$reference = $error = $message = $output = "";
$tracking_progress = $submission_time = null;
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

                    $editedByUserAdAccount = getProperties('tokens', 'user_ad_account', 'token', $token);
                    $editedByUserId = getProperties('users', 'id', 'ad_account_no', $editedByUserAdAccount);
                    $editedByUserEmail = getProperties('users', 'email', 'ad_account_no', $editedByUserAdAccount);
                    $editedByUserName = getProperties('users', 'full_name', 'ad_account_no', $editedByUserAdAccount);

                    // Process API
                    try {
                        $ticket_id = $_POST['ticket_id'] ?? null;
                        $action = $_POST['action'] ?? null;

                        //raise ticket fields
                        $category = $_POST['category'] ?? null;
                        $observations = $_POST['observations'] ?? null;
                        $submission_status = $_POST['submission_status'] ?? null;
                        $ticket_status = $_POST['ticket_status'] ?? null;

                        //joint inspection fields
                        $tracking_progress = $_POST['tracking_progress'] ?? null;
                        $last_repair_date = $_POST['last_repair_date'] ?? null;
                        $mileage_at_last_repair = $_POST['mileage_at_last_repair'] ?? null;
                        $current_mileage = $_POST['current_mileage'] ?? null;
                        $joint_inspection_remarks = $_POST['joint_inspection_remarks'] ?? null;

                        //defect confirmation fields defect_confirmation
                        $DC_notes = $_POST['defect_confirmation_notes'] ?? null;
                        $DC_repaircosts = $_POST['defect_confirmation_quotation_repair_cost'] ?? null;
                        $DC_currentspend = $_POST['defect_confirmation_current_spend'] ?? null;
                        $DC_currentspend = $_POST['defect_confirmation_current_spend'] ?? null;
                        $DC_vendor = $_POST['defect_confirmation_vendor'] ?? null;


                        //contract confirmation
                        $CQC_status = $_POST['contract_quotation_confirmation_status'] ?? null;
                        $CQC_remarks = $_POST['contract_quotation_confirmation_remarks'] ?? null;

                        //Repair recommendation
                        $RC_status = $_POST['repair_recommendation_status'] ?? null;
                        $RC_repair_cost = $_POST['repair_recommendation_repair_cost'] ?? null;
                        $RC_budget = $_POST['repair_recommendation_budget_allocation'] ?? null;

                        //Repair approval
                        $RA_status = $_POST['repair_approval_status'] ?? null;
                        $RA_remarks = $_POST['repair_approval_remarks'] ?? null;
                        $RA_amount = $_POST['repair_approval_amount'] ?? null;

                        //Help desk
                        $HD_po = $_POST['help_desk_po_no'] ?? null;
                        $HD_mo = $_POST['help_desk_mo_no'] ?? null;
                        $HD_po_amount = $_POST['help_desk_po_amount'] ?? null;
                        $HD_remarks = $_POST['help_desk_remarks'] ?? null;


                        if (!$ticket_id) {
                            $httpResponseCode = 400;
                            $error = "Bad Request";
                            $message = "ticket_id cannot be empty";
                        } else if (!$action) {
                            $httpResponseCode = 400;
                            $error = "Bad Request";
                            $message = "action is required";
                        } else if (!in_array($action, $allowed_actions, true)) {
                            $httpResponseCode = 400;
                            $error = "Bad Request";
                            $message = "wrong action";
                        } else {

                            // Move uploaded files
                            $uploadTargetDirectory = "../../mvr-storage/";


                            //check if ticket exists
                            $query_ = "SELECT * FROM tickets WHERE id='$ticket_id'";
                            $results_ = mysqli_query($conn, $query_);
                            $count_ = mysqli_num_rows($results_);
                            if ($count_ == 1) {
                                //get file upload current values - to help in retaining them
                                while ($row_ = $results_->fetch_assoc()) {
                                    $attachment_1 = $row_['attachment_1'];
                                    $attachment_2 = $row_['attachment_2'];
                                    $attachment_3 = $row_['attachment_3'];
                                    $JI_attachment = $row_['joint_inspection_attachment'];
                                    $DC_attachment = $row_['defect_confirmation_attachment'];
                                    $HD_attachment = $row_['help_desk_attachment'];

                                    $tracking_progress = $row_['tracking_progress'];
                                    $tracking_progress_level = $row_['tracking_progress_level'];

                                    //get id and ref
                                    $reference = (string)$ticket_id;
                                    $reference = str_pad($reference, 4, '0', STR_PAD_LEFT);
                                    $reference = "KRA/Transport/$reference";

                                }

                                if ($action == "service_request") {
                                    if (empty($category) || empty($observations) || empty($submission_status)) {
                                        exitWithError(400, 'Bad request', 'Some required fields are missing/ empty.');
                                    }


                                    if ($submission_status == 'submitted') {
                                        $tracking_progress = 'Ticket submitted. Awaiting joint inspection.';
                                        $tracking_progress_level = '1001';
                                        $submission_time = $timestamp;
                                    }

                                    //upload files (1, 2, 3)
                                    if (!empty($_FILES['attachment_1']['name'])) {
                                        $fileUploadResult = fileUpload('attachment_1');
                                        if ($fileUploadResult['status'] === true) {
                                            $attachment_1 = $fileUploadResult['message'];
                                        } else {
                                            exitWithError(400, 'File upload failed', $fileUploadResult['message']); //will exit with an error
                                        }
                                    }
                                    if (!empty($_FILES['attachment_2']['name'])) {
                                        $fileUploadResult = fileUpload('attachment_2');
                                        if ($fileUploadResult['status'] === true) {
                                            $attachment_2 = $fileUploadResult['message'];
                                        } else {
                                            exitWithError(400, 'File upload failed', $fileUploadResult['message']); //will exit with an error
                                        }
                                    }
                                    if (!empty($_FILES['attachment_3']['name'])) {
                                        $fileUploadResult = fileUpload('attachment_3');
                                        if ($fileUploadResult['status'] === true) {
                                            $attachment_3 = $fileUploadResult['message'];
                                        } else {
                                            exitWithError(400, 'File upload failed', $fileUploadResult['message']); //will exit with an error
                                        }
                                    }

                                    $sql = "UPDATE tickets SET category=?, observations=?, attachment_1=?, attachment_2=?, attachment_3=?,
                                    submission_status=?, submission_time=?, tracking_progress=?, tracking_progress_level=?,
                                    ticket_raiser_last_edited=? WHERE id=?";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->bind_param(
                                        "ssssssssssi",
                                        $category,
                                        $observations,
                                        $attachment_1,
                                        $attachment_2,
                                        $attachment_3,
                                        $submission_status,
                                        $submission_time,
                                        $tracking_progress,
                                        $tracking_progress_level,
                                        $timestamp,
                                        $ticket_id
                                    );
                                }

                                if ($action == "joint_inspection") {
                                    if (empty($last_repair_date) || empty($mileage_at_last_repair) || empty($current_mileage)) {
                                        exitWithError(400, 'Bad request', 'Some required fields are missing/ empty.');
                                    }

                                    $tracking_progress = 'Joint inspection complete. Pending defect confirmation.';
                                    $tracking_progress_level = '1002';
                                    //upload file
                                    if (!empty($_FILES['joint_inspection_attachment']['name'])) {
                                        $fileUploadResult = fileUpload('joint_inspection_attachment');
                                        if ($fileUploadResult['status'] === true) {
                                            $JI_attachment = $fileUploadResult['message'];
                                        } else {
                                            exitWithError(400, 'File upload failed', $fileUploadResult['message']); //will exit with an error
                                        }
                                    }

                                    $sql = "UPDATE tickets SET tracking_progress=?, tracking_progress_level=?, last_repair_date=?, mileage_at_last_repair=?,
               current_mileage=?, joint_inspection_remarks=?, joint_inspection_attachment=?, joint_inspection_by_user_id=?,
              joint_inspection_timestamp=?, joint_inspection_last_edited=? WHERE id=?";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->bind_param(
                                        "ssssssssssi",
                                        $tracking_progress,
                                        $tracking_progress_level,
                                        $last_repair_date,
                                        $mileage_at_last_repair,
                                        $current_mileage,
                                        $joint_inspection_remarks,
                                        $JI_attachment,
                                        $editedByUserId,
                                        $timestamp,
                                        $timestamp,
                                        $ticket_id
                                    );
                                }

                                if ($action == "defect_confirmation") {
                                    if (!isset($DC_repaircosts) || !isset($DC_currentspend) || empty($DC_notes) || empty($DC_vendor)) {
                                        exitWithError(400, 'Bad request', 'Some required fields are missing/ empty.');
                                    }

                                    //upload file
                                    if (!empty($_FILES['defect_confirmation_attachment']['name'])) {
                                        $fileUploadResult = fileUpload('defect_confirmation_attachment');
                                        if ($fileUploadResult['status'] === true) {
                                            $DC_attachment = $fileUploadResult['message'];
                                        } else {
                                            exitWithError(400, 'File upload failed', $fileUploadResult['message']); //will exit with an error
                                        }
                                    }

                                    $tracking_progress = 'Defect confirmation complete. Pending confirmation of contract.';
                                    $tracking_progress_level = '1003';

                                    $sql = "UPDATE tickets SET tracking_progress=?, tracking_progress_level=?, defect_confirmation_notes=?, 
                   defect_confirmation_quotation_repair_cost=?, defect_confirmation_vendor=?,
               defect_confirmation_current_spend=?, defect_confirmation_attachment=?, defect_confirmation_timestamp=?, defect_confirmation_by_user_id=?,
            defect_confirmation_last_edited=? WHERE id=?";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->bind_param(
                                        "ssssssssssi",
                                        $tracking_progress,
                                        $tracking_progress_level,
                                        $DC_notes,
                                        $DC_repaircosts,
                                        $DC_vendor,
                                        $DC_currentspend,
                                        $DC_attachment,
                                        $timestamp,
                                        $editedByUserId,
                                        $timestamp,
                                        $ticket_id
                                    );
                                }

                                if ($action == "contract_confirmation") {

                                    if (!$CQC_status) {
                                        exitWithError(400, 'Bad request', 'Confirmation status is required.');
                                    }

                                    if ($CQC_status == 'confirmed') {
                                        $tracking_progress = 'Contract/ Quotation confirmed. Pending recommendation for repair.';
                                        $tracking_progress_level = '1004';
                                    }
                                    $sql = "UPDATE tickets SET tracking_progress=?, tracking_progress_level=?, contract_quotation_confirmation_status=?, 
                   contract_quotation_confirmation_remarks=?, contract_quotation_confirmation_by_user_id=?,
               contract_quotation_confirmation_timestamp=?, contract_quotation_confirmation_last_edited=? WHERE id=?";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->bind_param(
                                        "sssssssi",
                                        $tracking_progress,
                                        $tracking_progress_level,
                                        $CQC_status,
                                        $CQC_remarks,
                                        $editedByUserId,
                                        $timestamp,
                                        $timestamp,
                                        $ticket_id
                                    );
                                }

                                if ($action == "repair_recommendation") {
                                    //figures can be 0, use isset to accept 0s
                                    if (!$RC_status || !isset($RC_repair_cost) || !isset($RC_budget)) {
                                        exitWithError(400, 'Bad request', 'Some required fields are missing/ empty.');
                                    }

                                    if ($RC_status == 'recommended') {
                                        $tracking_progress = 'Repair recommended. Pending repair approval.';
                                        $tracking_progress_level = '1005';
                                    }
                                    $sql = "UPDATE tickets SET tracking_progress=?, tracking_progress_level=?, 
                            repair_recommendation_status=?, repair_recommendation_repair_cost=?,  
                            repair_recommendation_budget_allocation=?,repair_recommendation_by_user_id=?, repair_recommendation_timestamp=?,
                            repair_recommendation_last_edited=? WHERE id=?";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->bind_param(
                                        "ssssssssi",
                                        $tracking_progress,
                                        $tracking_progress_level,
                                        $RC_status,
                                        $RC_repair_cost,
                                        $RC_budget,
                                        $editedByUserId,
                                        $timestamp,
                                        $timestamp,
                                        $ticket_id
                                    );
                                }

                                if ($action == "repair_approval") {
                                    //figures can be 0, use isset to accept 0s
                                    if (!isset($RA_status) || !isset($RA_amount)) {
                                        exitWithError(400, 'Bad request', "Some required fields are missing/ empty.");
                                    }

                                    $assignedToHelpDeskUserID = '';
                                    if ($RA_status == 'approved') {
                                        $tracking_progress = 'Approved.';
                                        $tracking_progress_level = '1006';

                                        //allocate ticket to help desk
                                        if (str_contains($category, 'tyres')) {
                                            $helpDeskOfficer = getUsersWithPermission('Help Desk Tyres');
                                        } elseif (str_contains($category, 'repair')) {
                                            $helpDeskOfficer = getUsersWithPermission('Help Desk Repair');
                                        } else {
                                            $helpDeskOfficer = getUsersWithPermission('Help Desk Service');
                                        }
                                        if (count($helpDeskOfficer) > 0) {
                                            $helpDeskOfficer = $helpDeskOfficer[0]; //send to the first one you find
                                            $assignedToHelpDeskUserID = $helpDeskOfficer['id'];
                                            $assignedToHelpDeskUserEmail = $helpDeskOfficer['email'];
                                            $assignedToHelpDeskUserName = $helpDeskOfficer['full_name'];

                                        }
                                        //end allocate
                                    }
                                    $sql = "UPDATE tickets SET tracking_progress=?, tracking_progress_level=?, 
                            repair_approval_status=?, repair_approval_remarks=?, repair_approval_amount=?, help_desk_user_id=?,  
                            repair_approval_by_user_id=?,repair_approval_timestamp=?, repair_approval_last_edited=?
                             WHERE id=?";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->bind_param(
                                        "sssssssssi",
                                        $tracking_progress,
                                        $tracking_progress_level,
                                        $RA_status,
                                        $RA_remarks,
                                        $RA_amount,
                                        $assignedToHelpDeskUserID,
                                        $editedByUserId,
                                        $timestamp,
                                        $timestamp,
                                        $ticket_id
                                    );
                                }


                                if ($action == "help_desk") {
                                    if (!($HD_remarks)) {
                                        exitWithError(400, 'Bad request', "Some required fields are missing/ empty.");
                                    }

                                    if ($HD_po) { //po closes the ticket
                                        $tracking_progress = 'Closed.';
                                        $tracking_progress_level = '1007';
                                    } else {//revert to previous state
                                        $tracking_progress = 'Approved.';
                                        $tracking_progress_level = '1006';
                                    }

                                    //upload file
                                    if (!empty($_FILES['help_desk_attachment']['name'])) {
                                        $fileUploadResult = fileUpload('help_desk_attachment');
                                        if ($fileUploadResult['status'] === true) {
                                            $HD_attachment = $fileUploadResult['message'];
                                        } else {
                                            exitWithError(400, 'File upload failed', $fileUploadResult['message']); //will exit with an error
                                        }
                                    }


                                    $sql = "UPDATE tickets SET help_desk_remarks=?, help_desk_mo_no=?, help_desk_po_no=?,
                                    help_desk_po_amount=?,
                                    help_desk_attachment=?,  tracking_progress=?, tracking_progress_level=?,
                                    help_desk_user_id=?, help_desk_timestamp=?, help_desk_last_edited=? WHERE id=?";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->bind_param(
                                        "ssssssssssi",
                                        $HD_remarks,
                                        $HD_mo,
                                        $HD_po,
                                        $HD_po_amount,
                                        $HD_attachment,
                                        $tracking_progress,
                                        $tracking_progress_level,
                                        $editedByUserId,
                                        $timestamp,
                                        $timestamp,
                                        $ticket_id
                                    );
                                }


                                if ($stmt->execute()) {
                                    $message_ = "Ticket $ticket_id edited successfully. Action: $action";
                                    $httpResponseCode = 200;
                                    $response_array = [
                                        'message' => $message_,
                                    ];
                                    logAction($editedByUserAdAccount, $editedByUserId, $token, $httpResponseCode, $message_);

                                    //send email to next actor

                                    $actionBySelf = $permissionName = '';
                                    if ($action == "service_request" && $submission_status == 'submitted') {
                                        $actionBySelf = "Service Request Raised";
                                        $permissionName = "Joint Inspection";
                                    } elseif ($action == "joint_inspection") {
                                        $actionBySelf = "Join Inspection";
                                        $permissionName = "Defect Confirmation";
                                    } elseif ($action == "defect_confirmation") {
                                        $actionBySelf = "Defect Confirmation";
                                        $permissionName = "Contract Confirmation";
                                    } elseif ($action == "contract_confirmation") {
                                        $actionBySelf = "Contract Confirmation";
                                        $permissionName = "Repair Recommendation";
                                    } elseif ($action == "repair_recommendation") {
                                        $actionBySelf = "Repair Recommendation";
                                        $permissionName = "Repair Approval";
                                    } elseif ($action == "repair_approval") {
                                        $actionBySelf = "Repair Approval";
                                        //notify people in help desk
                                        if (str_contains($category, 'tyres')) {
                                            $permissionName = "Help Desk Tyres";
                                        } elseif (str_contains($category, 'repair')) {
                                            $permissionName = "Help Desk Repairs";
                                        } elseif (str_contains($category, 'service')) {
                                            $permissionName = "Help Desk Service";
                                        }
                                    } elseif ($action == "help_desk") {
                                        $actionBySelf = "Help Desk Processing";
                                    }

                                    if ($permissionName) {
                                        $nextActors = getUsersWithPermission($permissionName);
//                                        echo json_encode($nextActors);
                                        if (!empty($nextActors) > 0) {
//                                            $email_queue = [];
                                            foreach ($nextActors as $actor) {

                                                $recipientName = $actor['full_name'] ?? 'User';
                                                $email = $actor['email'] ?? null;
                                                // Skip if email is missing or empty
                                                if ($email) {

                                                    //add to queue
                                                    $body = "Dear $recipientName,<br>";
                                                    $body .= "A Motor Vehicle Repair Ticket: <b>$reference</b> is awaiting your review and action in <b>$permissionName</b>. Please log in to the system to proceed.<br>";
                                                    $body .= "Login here: <a target='_blank' href='$systemUrl'>$systemUrl</a><br>";
                                                    $body .= "Thank you.";
                                                    $subject = "Vehicle Repair Ticket: #$ticket_id | $permissionName";

                                                    addToEmailQueue($email, $subject, $body);

                                                    //prepare a json array
                                                    /* $body = "Dear $recipientName,<br>";
                                                     $body .= "A Motor Vehicle Repair Ticket: <b>$reference</b> is awaiting your review and action in <b>$permissionName</b>. Please log in to the system to proceed.<br>";
                                                     $body .= "Thank you.";
                                                     $subject = "Vehicle Repair Ticket: #$ticket_id | $permissionName";

                                                     // Add the structured email data to the queue array
                                                     $email_queue[] = [
                                                         'email' => $email,
                                                         'subject' => $subject,
                                                         'body' => $body
                                                     ];*/
                                                }
                                            }
                                            // $email_queue = ["recipients" => $email_queue];
                                            //send to email api
//                                            echo json_encode($email_queue);
                                            //  require_once 'send-email-function.php';
                                        }
                                    }

                                    //email to self
                                    if ($actionBySelf) {
                                        $body = "Dear $editedByUserName,<br>";
                                        $body .= "A Motor Vehicle Repair Ticket: <b>$reference</b> has been processed in <b>$actionBySelf</b> level at $timestamp and forwarded to next actor. Please log in to the system to see progress.<br>";
                                        $body .= "Login here: <a target='_blank' href='$systemUrl'>$systemUrl</a><br>";
                                        $body .= "Thank you.";
                                        $subject = "Vehicle Repair Ticket: #$ticket_id | $actionBySelf";

                                        addToEmailQueue($editedByUserEmail, $subject, $body);
                                    }

                                } else {
                                    $httpResponseCode = 400;
                                    $error = "Query failed";
                                    $message = "Query failed: " . $conn->error;
                                }
                            } else {
                                $httpResponseCode = 400;
                                $error = "Bad Request";
                                $message = "Ticket ID $ticket_id does not exist";
                            }
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

    $ticket_id = $ticket_id ?? null;
    logAction($editedByUserAdAccount ?? null, $editedByUserId ?? null, $token ?? null, $httpResponseCode, "Edit ticket id " . ($ticket_id ?? null) . " failed: Error: $error. Message $message");
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
    if ($_FILES["$fileToUploadIndex"]["size"] > 20000000) {
        $uploadError .= "File is more than 20MB.";
    } else if ($fileType != "jpg" && $fileType != "png" && $fileType != "jpeg" && $fileType != "csv" && $fileType != "pdf"
        && $fileType != "docx" && $fileType != "doc" && $fileType != "xlsx" && $fileType != "zip") {
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

function exitWithError($code, $error, $message)
{
    global $editedByUserAdAccount, $editedByUserId, $token, $httpResponseCode, $ticket_id;
    http_response_code($code);
    $response_array = [
        'error' => $error,
        'message' => $message
    ];

    $output = json_encode($response_array);
    header('Content-Type: application/json');
    echo $output;

    logAction($editedByUserAdAccount ?? null, $editedByUserId ?? null, $token ?? null, $httpResponseCode, "Edit ticket failed $ticket_id failed: Error: $error. Message $message");
    exit;
}

function getHelpDeskOfficer1($category)
{
    $category = "tyres";
    $category = strtolower($category);
    global $conn;
    //check category:
    if (str_contains($category, "service")) {

    }
    $usersWithPerm = [];
    //get users and their associated permissions
    $sql = "SELECT * FROM users WHERE status='active'";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $userid = $row['id'];
            $roleid = $row['role_id'];

            //check if role_id has the permission listed
            $perms = [];
            $queryPerms = "SELECT * FROM role_permissions_mapping WHERE (role_id='$roleid' AND status='active')";
            if ($resultPerms = $conn->query($queryPerms)) {
                if ($resultPerms->num_rows > 0) {
                    while ($rowPerms = $resultPerms->fetch_assoc()) {
                        $permId = $rowPerms['permission_id'];
                        //get permission name
                        $permName = getProperties('permissions', 'name', 'id', $permId);
                        $perms[] = $permName;
                    }
                }
            }
            if (in_array((strtolower(trim($category))), $perms, true)) {
                $usersWithPerm = $userid;
            }
        }

        $result = [
            'status' => true,
            'users' => $usersWithPerm
        ];
    } else {
        $result = [
            'status' => false,
        ];
    }

    return $result;
}

function getUsersWithPermission(string $permissionName): array
{
    global $conn;
    $users = [];
    $sql = "
        SELECT 
            u.id,
            u.email,
            u.full_name
        FROM
            users u
        JOIN
            role_permissions_mapping rpm ON u.role_id = rpm.role_id
        JOIN
            permissions p ON rpm.permission_id = p.id
        WHERE
            p.name = '$permissionName'
            AND rpm.status = 'active'
            AND u.status = 'active';
    ";

    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL Prepare Failed: " . $conn->error);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();

    } catch (Exception $e) {
        throw new Exception("Database error: " . $e->getMessage());
    }
    //returns id, name & email
    return $users;
}


function addToEmailQueue($email, $subject, $message)
{
    global $conn, $timestamp;
    $stmt = $conn->prepare("INSERT INTO emails_queue (email, subject, message, queued_at) 
                            VALUES (?, ?, ?, ?)");
    $stmt->bind_param(
        "ssss",
        $email,
        $subject,
        $message,
        $timestamp,
    );
    if ($stmt->execute()) {
    } else {
    }
}
//end send email to self
