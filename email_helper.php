<?php
// Include the Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

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
        $mail->Username   = 'nicholasv@labscerulean.com'; 
        
        // Fetch the secure App Password from Railway's environment vault
        $mail->Password   = getenv('SMTP_APP_PASSWORD'); 
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // IMPORTANT: Send the email AS the alias
        $mail->setFrom('no-reply@labscerulean.com', 'Estate Hub Malta');
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
        $mail->CharSet = 'UTF-8'; // ADD THIS LINE TO FIX THE EURO SYMBOL
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Return the exact error string so our cron script can log it if it fails
        return "Mailer Error: {$mail->ErrorInfo}";
    }
}
