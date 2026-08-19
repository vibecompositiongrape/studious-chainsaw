<?php
declare(strict_types=1);

// Use PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load config and PHPMailer
require __DIR__ . '/config.php';
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

// Basic validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$transcript = $_POST['transcript'] ?? '';

if (!$email || empty($transcript)) {
    http_response_code(400);
    echo 'Invalid input';
    exit;
}

$mail = new PHPMailer(true);

try {
    // Server settings from your config.php
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 465;

    // Recipients
    $mail->setFrom(SMTP_FROM, 'LegalStudyBot');
    $mail->addAddress($email);
    $mail->addReplyTo(SMTP_FROM, 'LegalStudyBot');

    // Content
    $mail->isHTML(false); // Set email format to plain text
    $mail->Subject = 'Your LegalStudyBot Transcript';
    $mail->Body    = "Here is your requested chat transcript:\n\n" . $transcript;
    $mail->AltBody = "Here is your requested chat transcript:\n\n" . $transcript;

    $mail->send();
    echo 'success';
} catch (Exception $e) {
    // Don't echo the detailed error to the user for security
    error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
    http_response_code(500);
    echo "Message could not be sent.";
}