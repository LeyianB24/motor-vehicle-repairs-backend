<?php

if ($allow_send_email == true) { //from config

    require_once __DIR__ . '/../vendor/autoload.php';

    if ($email_queue) {

        try {
            $recipients = $email_queue['recipients'] ?? [];

            if (empty($recipients) || !is_array($recipients)) {
                http_response_code(400);
                //  echo json_encode(['success' => false, 'error' => 'No recipients provided']);
                exit;
            }

            $results = [];

            foreach ($recipients as $r) {
                $recipientEmail = str_replace(" ", "", $r['email'] ?? '');
                $subject = $r['subject'] ?? '';

                if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                    $results[] = [
                        'recipient' => $recipientEmail,
                        'status' => 'failed',
                        'error' => 'Invalid email address'
                    ];
                    continue;
                }

                if (empty($subject)) {
                    $results[] = [
                        'recipient' => $recipientEmail,
                        'status' => 'failed',
                        'error' => 'Subject is empty'
                    ];
                    continue;
                }

                $mail = new PHPMailer\PHPMailer\PHPMailer();
                $mail->isSMTP();
                $mail->SMTPAuth = false;
                $mail->SMTPAutoTLS = false;
                $mail->SMTPDebug = 0;
                $mail->Host = $emailHost;
                $mail->Port = $emailPort;
                $mail->isHTML(true);
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->setFrom($emailFrom, $emailFromName);
                $mail->smtpConnect([
                    "ssl" => [
                        "verify_peer" => false,
                        "verify_peer_name" => false,
                        "allow_self_signed" => true
                    ]
                ]);

                $message_ = $r['body'] ?? '';
                ob_start();
                include __DIR__ . '/../email-body.php';
                $body = ob_get_clean();

                $mail->addAddress($recipientEmail);
                $mail->Subject = $subject;
                $mail->Body = $body;

                $emailStatus = '';
                $errorMsg = '';

                if (!$mail->send()) {
                    $errorMsg = $mail->ErrorInfo;
                    $emailStatus = "failed: $errorMsg";
                } else {
                    $emailStatus = "success";
                }
                $sentBy = 'system';
                $sentTimestamp = date("Y-m-d H:i:s");
                $stmt = $conn->prepare("
                INSERT INTO email_logs 
                (recipient_email, subject, message, status, error, sent_timestamp, sent_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                 ");
                $stmt->bind_param(
                    "sssssss",
                    $recipientEmail,
                    $subject,
                    $message_,
                    $emailStatus,
                    $errorMsg,
                    $sentTimestamp,
                    $sentBy
                );
                $stmt->execute();

                $results[] = [
                    'recipient' => $recipientEmail,
                    'status' => $emailStatus,
                    'error' => $errorMsg
                ];

                //    echo "Email status: $emailStatus";
            }
            $mail->SMTPKeepAlive = false;
            $mail->SmtpClose();
            $mail->getSMTPInstance()->reset();
        } catch (Exception $ex) {

        }

    } else {
        //echo "no email";
    }

}
?>
