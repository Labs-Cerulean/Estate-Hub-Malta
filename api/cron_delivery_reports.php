<?php
/**
 * api/cron_delivery_reports.php
 * Automated & Manual Daily Delivery Notes Mailer for PRA and PRAX
 */

// 1. Include dependencies (Adjust paths if your structure is different)
require_once '../init.php'; 
require_once '../email_helper.php'; // The PHPMailer script we just made

// If you decide to use DomPDF to convert your invoices to PDF, you'll need the autoloader.
// Since you added Composer, Railway loads this automatically, but it's safe to include here:
require_once '../vendor/autoload.php'; 

// 2. Security: Validate access
$cronToken = '~~ZcKZDV6sF%+2wr)qEt'; // Match this with your cron-job.org URL

// Check if it's an admin clicking the manual button
$isManualRequest = false;
if (isset($_SESSION['user_id']) && function_exists('getCurrentRole')) {
    $role = getCurrentRole();
    if (in_array($role, ['admin', 'director', 'system_manager'])) {
        $isManualRequest = true;
    }
}

// Check if it's the automated server cron job
$isCronRequest = isset($_GET['token']) && $_GET['token'] === $cronToken;

if (!$isManualRequest && !$isCronRequest) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized Access.']));
}

// 3. Determine Date Range
$startDate = $_POST['start_date'] ?? $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_POST['end_date'] ?? $_GET['end_date'] ?? date('Y-m-d');

// 4. Fetch Completed Jobs in Date Range
// Assuming your DB connection is $pdo (change to $conn if you use mysqli)
$stmt = $pdo->prepare("
    SELECT pb.*, p.name as plant_name, p.billing_company_id 
    FROM plant_bookings pb 
    JOIN plants p ON pb.plant_id = p.id 
    WHERE pb.booking_date >= ? AND pb.booking_date <= ? 
    AND pb.status = 'Completed'
");
$stmt->execute([$startDate, $endDate]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Separate by Company (24 = PRA, 26 = PRAX)
$praJobs = [];
$praxJobs = [];

foreach ($jobs as $job) {
    if ($job['billing_company_id'] == '24') { 
        $praJobs[] = $job; 
    } elseif ($job['billing_company_id'] == '26') { 
        $praxJobs[] = $job; 
    }
}

// 6. Define the Billing Department Emails
$praEmails = ['billing@pra.com']; // Add actual emails here
$praxEmails = ['billing@prax.com']; // Add actual emails here

// 7. PDF Generation Helper Function
// NOTE: To make this generate real PDFs from your HTML, run: composer require dompdf/dompdf
function generateJobPdfFile($job) {
    // Create a temporary directory for PDFs if it doesn't exist
    $tempDir = __DIR__ . '/../temp_pdfs/';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    $filePath = $tempDir . "Delivery_Note_Job_" . $job['id'] . ".pdf";

    /* --- DOMPDF IMPLEMENTATION (Uncomment once you install dompdf) ---*/
    $html = "<h1>Delivery Note: Job #" . $job['id'] . "</h1>";
    $html .= "<p>Plant: " . $job['plant_name'] . "</p>";
    $html .= "<p>Date: " . $job['booking_date'] . "</p>";
    // ... add the rest of your invoice HTML variables here ...
    
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    file_put_contents($filePath, $dompdf->output());
   /*  ------------------------------------------------------------------ */

    // Fallback: If DomPDF isn't installed yet, we create a temporary text file just to test attachments
    if (!class_exists('\Dompdf\Dompdf')) {
        file_put_contents($filePath . '.txt', "Delivery Note Details for Job ID: " . $job['id']);
        return $filePath . '.txt';
    }

    return $filePath;
}

// 8. Processing & Sending Function
function processAndSendCompanyEmails($companyName, $jobsList, $recipients, $start, $end) {
    if (empty($jobsList)) {
        return "0 jobs found.";
    }

    $isSingleDay = ($start === $end);
    $dateLabel = $isSingleDay ? $start : "$start to $end";
    
    $subject = "$companyName Delivery Notes - $dateLabel";
    
    $htmlBody = "<h2>$companyName - Daily Plant Delivery Notes</h2>";
    $htmlBody .= "<p>Please find attached the delivery notes for completed plant bookings for <strong>$dateLabel</strong>.</p>";
    $htmlBody .= "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width:100%; max-width: 600px;'>
                    <tr style='background:#f1f5f9;'>
                        <th>Job ID</th>
                        <th>Plant/Machine</th>
                        <th>Date</th>
                    </tr>";

    $attachments = [];

    // Loop jobs, add to HTML table, and generate PDFs
    foreach($jobsList as $job) {
        $htmlBody .= "<tr>
                        <td>#" . $job['id'] . "</td>
                        <td>" . htmlspecialchars($job['plant_name']) . "</td>
                        <td>" . $job['booking_date'] . "</td>
                      </tr>";
        
        // Generate the PDF and add the file path to our attachments array
        $pdfPath = generateJobPdfFile($job);
        if ($pdfPath) {
            $attachments[] = $pdfPath;
        }
    }
    
    $htmlBody .= "</table><br><p><em>Automated by Estate Hub Fleet System</em></p>";

    // Send via our email_helper.php
    $emailSuccess = sendSystemEmail($recipients, $subject, $htmlBody, $attachments);

    // CLEANUP: Delete the temporary PDFs from the server so we don't waste storage
    foreach($attachments as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    if ($emailSuccess) {
        return count($jobsList) . " delivery notes sent successfully.";
    } else {
        return "Error sending email.";
    }
}

// 9. Execute for both companies
$results = [];
$results['pra'] = processAndSendCompanyEmails('PRA Construction', $praJobs, $praEmails, $startDate, $endDate);
$results['prax'] = processAndSendCompanyEmails('PRAX Concrete', $praxJobs, $praxEmails, $startDate, $endDate);

// 10. Return Response
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success', 
    'date_range' => "$startDate to $endDate", 
    'results' => $results
]);
exit;
