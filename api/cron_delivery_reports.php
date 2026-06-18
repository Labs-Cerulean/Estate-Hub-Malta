<?php
/**
 * api/cron_delivery_reports.php
 * Automated & Manual Daily Delivery Notes Mailer for PRA and PRAX
 */

// 1. Include dependencies (Adjust paths if your structure is different)
require_once '../init.php'; 
require_once '../email_helper.php';
require_once '../vendor/autoload.php'; 

// 2. Security: Validate access using Environment Variable
$cronToken = getenv('CRON_SECRET_TOKEN');

// Check if it's an admin clicking the manual button
$isManualRequest = false;
if (isset($_SESSION['user_id']) && function_exists('getCurrentRole')) {
    $role = getCurrentRole();
    if (in_array($role, ['admin', 'director', 'system_manager'])) {
        $isManualRequest = true;
    }
}

// Check if it's the automated server cron job (e.g. cron-job.org calling with ?token=...)
// We use hash_equals() to prevent timing attacks
$isCronRequest = isset($_GET['token']) && !empty($cronToken) && hash_equals($cronToken, $_GET['token']);

if (!$isManualRequest && !$isCronRequest) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized Access.']));
}

// 3. Determine Date Range
$startDate = $_POST['start_date'] ?? $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_POST['end_date'] ?? $_GET['end_date'] ?? date('Y-m-d');

// 4. Fetch Completed Jobs in Date Range
$stmt = $pdo->prepare("
    SELECT pb.*, p.name as plant_name, p.registration_plate, p.billing_company_id 
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
$praEmails = ['nicholasv@pramalta.com']; // Replace with real PRA billing email eventually
$praxEmails = ['nicholasv@pramalta.com']; // Replace with real PRAX billing email eventually

// 7. PDF Generation Helper Function (Fetches from print_plant_invoice.php)
function generateJobPdfFile($job) {
    $tempDir = __DIR__ . '/../temp_pdfs/';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    // Generate the clean Job Ref Name
    $prefix = ($job['billing_company_id'] == '26') ? 'PRAX' : 'PRA';
    $year = date('Y', strtotime($job['booking_date']));
    $paddedId = str_pad($job['id'], 4, '0', STR_PAD_LEFT);
    $jobRef = $job['job_ref'] ?? "{$prefix}-{$year}-{$paddedId}";
    $filePath = $tempDir . "{$jobRef}.pdf";

    // Build the exact URL (Removed the token from the URL for security)
    $domain = $_SERVER['HTTP_HOST'] ?? 'your-app.up.railway.app'; 
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $targetUrl = "{$protocol}://{$domain}/print_plant_invoice.php?booking_id=" . $job['id'];

    // Fetch the secure token from Railway's environment vault
    $cronToken = getenv('CRON_SECRET_TOKEN');

    // Fetch the content using a secure, hidden HTTP Header
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "X-Cron-Token: " . $cronToken . "\r\n",
            'ignore_errors' => true
        ]
    ];
    $context = stream_context_create($opts);
    $content = @file_get_contents($targetUrl, false, $context);

    if (!$content) {
        return false; // Failed to fetch
    }

    // Save the PDF
    // Check if the page already outputs a raw PDF file
    if (strpos(trim($content), '%PDF-') === 0) {
        file_put_contents($filePath, $content);
    } 
    // Otherwise, if it outputs HTML, use DomPDF to render it perfectly
    else {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($content);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($filePath, $dompdf->output());
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
    
    $subject = "Plant Bookings Hub: $companyName Delivery Notes ($dateLabel)";
    
    // --- EMAIL BRANDING ---
    $htmlBody = "<h2>Plant Bookings Hub</h2>";
    $htmlBody .= "<h3>$companyName - Daily Delivery Notes</h3>";
    $htmlBody .= "<p>Please find attached the delivery notes for completed plant bookings for <strong>$dateLabel</strong>.</p>";
    
    // --- EMAIL TABLE WITH MORE INFO ---
    $htmlBody .= "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; width:100%; max-width: 800px; font-family: Arial, sans-serif; font-size: 13px;'>
                    <tr style='background:#0f172a; color:white; text-align: left;'>
                        <th>Job Ref</th>
                        <th>Plant & Reg</th>
                        <th>Driver</th>
                        <th>Shift Time</th>
                        <th>Date</th>
                        <th>Total (€)</th>
                    </tr>";

    $attachments = [];

    // Loop jobs, add to HTML table, and generate PDFs
    foreach($jobsList as $job) {
        // Generate the exact Job Ref to match the PDF
        $prefix = ($job['billing_company_id'] == '26') ? 'PRAX' : 'PRA';
        $year = date('Y', strtotime($job['booking_date']));
        $paddedId = str_pad($job['id'], 4, '0', STR_PAD_LEFT);
        $jobRef = $job['job_ref'] ?? "{$prefix}-{$year}-{$paddedId}";

        // Prepare table variables
        $plantInfo = htmlspecialchars($job['plant_name']) . "<br><span style='font-size:11px; color:#64748b;'>" . htmlspecialchars($job['registration_plate'] ?? '') . "</span>";
        $driver = htmlspecialchars($job['driver_name'] ?? 'N/A');
        $shift = ($job['time_start'] ?? '--:--') . " to " . ($job['time_end'] ?? '--:--');
        $totalDue = number_format(($job['subtotal'] ?? 700) * 1.18, 2); 

        $htmlBody .= "<tr>
                        <td><strong>{$jobRef}</strong></td>
                        <td>{$plantInfo}</td>
                        <td>{$driver}</td>
                        <td>{$shift}</td>
                        <td>" . $job['booking_date'] . "</td>
                        <td><strong>€ {$totalDue}</strong></td>
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

    // CLEANUP: Delete the temporary PDFs from the server
    foreach($attachments as $file) {
        if (file_exists($file)) unlink($file);
    }

    if ($emailSuccess === true) {
        return count($jobsList) . " delivery notes sent successfully.";
    } else {
        return "Failed: " . $emailSuccess;
    }
}

// 9. Execute for both companies
$results = [];
$results['pra'] = processAndSendCompanyEmails('PRA Construction', $praJobs, $praEmails, $startDate, $endDate);

// Tell the server to pause for 3 seconds so Google SMTP doesn't block the connection
sleep(3); 

$results['prax'] = processAndSendCompanyEmails('PRAX Concrete', $praxJobs, $praxEmails, $startDate, $endDate);

// 10. Return Response
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success', 
    'date_range' => "$startDate to $endDate", 
    'results' => $results
]);
exit;
