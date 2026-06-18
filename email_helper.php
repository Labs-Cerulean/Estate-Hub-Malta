<?php
// If using Composer, include the autoload file:
require_once __DIR__ . '/vendor/autoload.php';

// If NOT using Composer, uncomment these and point to where you dropped the PHPMailer files:
// require 'path/to/PHPMailer/src/Exception.php';
// require 'path/to/PHPMailer/src/PHPMailer.php';
// require 'path/to/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendSystemEmail($toEmail, $subject, $htmlBody, $attachments = []) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        
        // IMPORTANT: Authenticate with your REAL account
        $mail->Username   = 'nicholas@labscerulean.com'; 
        $mail->Password   = 'xvjlrerwbvvrszoz'; // Generated from Nicholas's account
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // IMPORTANT: Send the email AS the alias
        $mail->setFrom('no-reply@labscerulean.com', 'Estate Hub Plant & Fleet');
        $mail->addReplyTo('no-reply@labscerulean.com', 'No Reply'); // Explicitly tells email clients not to reply

        // Add Recipients (Can be a string or an array of emails)
        if (is_array($toEmail)) {
            foreach ($toEmail as $email) {
                $mail->addAddress($email);
            }
        } else {
            $mail->addAddress($toEmail);
        }

        // Attachments
        foreach ($attachments as $filePath) {
            if (file_exists($filePath)) {
                $mail->addAttachment($filePath); 
            }
        }

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
        } catch (Exception $e) {
        // Return the exact error instead of false
        return "Mailer Error: {$mail->ErrorInfo}";
    }
}
