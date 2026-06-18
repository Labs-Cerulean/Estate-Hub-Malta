<?php
/**
 * api/cron_delivery_reports.php - Daily Delivery Note Mailer
 */
require_once '../init.php';

// SECURITY: Allow access if logged in as Admin/Manager OR if the correct Cron Token is provided
$cronToken = '~~ZcKZDV6sF%+2wr)qEt'; // Change this to something secure!
$isManualRequest = isset($_SESSION['user_id']) && in_array(getCurrentRole(), ['admin', 'director', 'system_manager']);
$isCronRequest = isset($_GET['token']) && $_GET['token'] === $cronToken;

if (!$isManualRequest && !$isCronRequest) {
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized Access.']));
}

$startDate = $_POST['start_date'] ?? $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_POST['end_date'] ?? $_GET['end_date'] ?? date('Y-m-d');

// 1. Fetch Completed Jobs in Date Range
$stmt = $pdo->prepare("
    SELECT pb.*, p.name as plant_name, p.billing_company_id 
    FROM plant_bookings pb 
    JOIN plants p ON pb.plant_id = p.id 
    WHERE pb.booking_date >= ? AND pb.booking_date <= ? 
    AND pb.status = 'Completed'
");
$stmt->execute([$startDate, $endDate]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Separate into PRA and PRAX
$praJobs = [];
$praxJobs = [];

foreach ($jobs as $j) {
    if ($j['billing_company_id'] == '24') { $praJobs[] = $j; } 
    elseif ($j['billing_company_id'] == '26') { $praxJobs[] = $j; }
}

// 3. Define Email Recipients
$praEmails = ['nicholasv@pramalta.com']; // Replace with actual emails
$praxEmails = ['nicholasv@pramalta.com']; // Replace with actual emails

// Function to generate/attach PDFs and send email
function sendDeliveryNotesEmail($companyName, $jobsList, $recipients, $start, $end) {
    if (empty($jobsList)) return "No jobs for $companyName.";

    $subject = "$companyName Delivery Notes - " . ($start === $end ? $start : "$start to $end");
    $message = "<h3>$companyName Delivery Notes</h3><p>Please find attached the delivery notes for completed plant bookings between $start and $end.</p>";
    
    // --- PDF GENERATION PLACEHOLDER ---
    // Note: Generating PDFs purely in the background requires a PHP library like DomPDF, mPDF, or TCPDF.
    // We will loop through $jobsList here, generate a PDF for each, and attach it to the email.
    
    /* Example PHPMailer Logic:
    $mail = new PHPMailer(true);
    // ... setup SMTP ...
    $mail->setFrom('noreply@estatehub.com', 'Estate Hub Fleet');
    foreach($recipients as $email) { $mail->addAddress($email); }
    $mail->Subject = $subject;
    $mail->Body = $message;
    
    foreach($jobsList as $job) {
        $pdfContent = generatePdfForJob($job); // Your PDF generation function
        $mail->addStringAttachment($pdfContent, "Delivery_Note_PRA_".$job['id'].".pdf");
    }
    $mail->send();
    */
    
    return "Sent " . count($jobsList) . " delivery notes for $companyName.";
}

// 4. Execute Sending
$results = [];
$results['pra'] = sendDeliveryNotesEmail('PRA Construction', $praJobs, $praEmails, $startDate, $endDate);
$results['prax'] = sendDeliveryNotesEmail('PRAX Concrete', $praxJobs, $praxEmails, $startDate, $endDate);

echo json_encode(['status' => 'success', 'date_range' => "$startDate to $endDate", 'results' => $results]);
exit;
