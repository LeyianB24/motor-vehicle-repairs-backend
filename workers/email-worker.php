<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

echo "\n\n--- Email Worker Started at " . date('Y-m-d H:i:s') . " ---\n";
// Fetch pending or retryable emails
$queue = $conn->query("
    SELECT * FROM emails_queue 
    WHERE status = 'pending' OR (status = 'failed' AND retry_count < $maxRetries)
    ORDER BY queued_at ASC 
    LIMIT $emailsLimit
");

if ($queue->num_rows === 0) {
    echo "No emails in the queue.\n";
} else {
    while ($email = $queue->fetch_assoc()) {

    $mail = new PHPMailer\PHPMailer\PHPMailer();

    $mail->isSMTP();
    $mail->SMTPAuth = false;
    $mail->SMTPAutoTLS = false;
    $mail->Host = $emailHost;
    $mail->Port = $emailPort;
    $mail->isHTML(true);
    $mail->setFrom($emailFrom, $emailFromName);
    $mail->addAddress($email['email']);
    $mail->Subject = $email['subject'];

    $message_ = $email['message'];
    ob_start();
    include __DIR__ . '/../email-body.php';
    $mail->Body = ob_get_clean();

    // Send email
    $success = $mail->send();
    $status = $success ? 'sent' : 'failed';
    $errorMsg = $success ? '' : $mail->ErrorInfo;
    $sentTimestamp = date("Y-m-d H:i:s");

    // Update queue table
    if ($success) {
        $stmt = $conn->prepare("UPDATE emails_queue SET status='sent', response_at=NOW() WHERE id=?");
        $stmt->bind_param("i", $email['id']);
        $stmt->execute();
        //echo email 
        echo "Email " . $email['id'] . " sent  at " . $sentTimestamp . "\n";
    } else {
        $newRetryCount = $email['retry_count'] + 1;
        $nextStatus = ($newRetryCount >= $maxRetries) ? 'failed' : 'pending';

        $stmt = $conn->prepare("
            UPDATE emails_queue 
            SET status=?, retry_count=?, response_at=NOW(), message=CONCAT(message, '\n\nError: ', ?) 
            WHERE id=?
        ");
        $stmt->bind_param("sisi", $nextStatus, $newRetryCount, $errorMsg, $email['id']);
        $stmt->execute();
        //echo email
        echo "Email " . $email['id'] . " failed to send at " . $sentTimestamp . ". Error: " . $errorMsg . "\n";
    }

    // Log to email_logs table
    $sentBy = $email['sent_by'] ?? 'system';
    $stmt = $conn->prepare("
        INSERT INTO email_logs 
        (recipient_email, subject, message, status, error, sent_timestamp, sent_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "sssssss",
        $email['email'],
        $email['subject'],
        $message_,  
        $status,
        $errorMsg,
        $sentTimestamp,
        $sentBy
    );
    $stmt->execute();

    $mail->clearAddresses();
    $mail->clearAttachments();
}
}


$conn->close();
echo "--- Email Worker Ended at " . date('Y-m-d H:i:s') . " ---\n";
