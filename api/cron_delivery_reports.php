<?php
/**
 * api/cron_delivery_reports.php
 * Automated & Manual Daily Delivery Notes Mailer for PRA and PRAX
 */

// 1. Include dependencies
require_once '../init.php'; 
require_once '../email_helper.php';
require_once '../vendor/autoload.php'; 

// 2. Security: Validate access using Environment Variable
$cronToken = getenv('CRON_SECRET_TOKEN');

$isManualRequest = false;
if (isset($_SESSION['user_id']) && function_exists('getCurrentRole')) {
    $role = getCurrentRole();
    if (in_array($role, ['admin', 'director', 'system_manager'])) {
        $isManualRequest = true;
    }
}

$isCronRequest = isset($_GET['token']) && !empty($cronToken) && hash_equals($cronToken, $_GET['token']);

if (!$isManualRequest && !$isCronRequest) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized Access.']));
}

// ---> CRITICAL FIX: RELEASE SESSION LOCK <---
// This prevents the server from freezing when it tries to fetch the PDFs!
session_write_close(); 

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

// 5. Separate by Company
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
$praEmails = ['nicholasv@pramalta.com']; 
$praxEmails = ['nicholasv@pramalta.com']; 

// 7. PDF Generation Helper Function
function generateJobPdfFile($job) {
    $tempDir = __DIR__ . '/../temp_pdfs/';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    $prefix = ($job['billing_company_id'] == '26') ? 'PRAX' : 'PRA';
    $year = date('Y', strtotime($job['booking_date']));
    $paddedId = str_pad($job['id'], 4, '0', STR_PAD_LEFT);
    $jobRef = $job['job_ref'] ?? "{$prefix}-{$year}-{$paddedId}";
    $filePath = $tempDir . "{$jobRef}.pdf";

    $domain = $_SERVER['HTTP_HOST'] ?? 'your-app.up.railway.app'; 
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $targetUrl = "{$protocol}://{$domain}/print_plant_invoice.php?booking_id=" . $job['id'] . "&readonly=1";

    $cronToken = getenv('CRON_SECRET_TOKEN');

    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "X-Cron-Token: " . $cronToken . "\r\n",
            'ignore_errors' => true,
            'timeout' => 30 // Prevents infinite hanging if a page fails
        ]
    ];
    $context = stream_context_create($opts);
    $content = @file_get_contents($targetUrl, false, $context);

    if (!$content) {
        return false; 
    }

    if (strpos(trim($content), '%PDF-') === 0) {
        file_put_contents($filePath, $content);
    } else {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new \Dompdf\Dompdf($options);
        
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
    
    $htmlBody = "<h2>Plant Bookings Hub</h2>";
    $htmlBody .= "<h3>$companyName - Daily Delivery Notes</h3>";
    $htmlBody .= "<p>Please find attached the delivery notes for completed plant bookings for <strong>$dateLabel</strong>.</p>";
    
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

    foreach($jobsList as $job) {
        $prefix = ($job['billing_company_id'] == '26') ? 'PRAX' : 'PRA';
        $year = date('Y', strtotime($job['booking_date']));
        $paddedId = str_pad($job['id'], 4, '0', STR_PAD_LEFT);
        $jobRef = $job['job_ref'] ?? "{$prefix}-{$year}-{$paddedId}";

        $plantInfo = htmlspecialchars($job['plant_name']) . "<br><span style='font-size:11px; color:#64748b;'>" . htmlspecialchars($job['registration_plate'] ?? '') . "</span>";
        $driver = htmlspecialchars($job['driver_name'] ?? 'N/A');
        
        // Use actual database time columns
        $tIn = !empty($job['punch_in_time']) ? date('H:i', strtotime($job['punch_in_time'])) : (!empty($job['start_time']) ? date('H:i', strtotime($job['start_time'])) : '--:--');
        $tOut = !empty($job['punch_out_time']) ? date('H:i', strtotime($job['punch_out_time'])) : (!empty($job['end_time']) ? date('H:i', strtotime($job['end_time'])) : '--:--');
        $shift = "{$tIn} to {$tOut}";

        // Use actual database totals (final_subtotal is Ex. VAT)
        $subtotal = (float)($job['final_subtotal'] ?? 0);
        $totalDue = $subtotal > 0 ? '€ ' . number_format($subtotal * 1.18, 2) : 'TBC'; 

        $htmlBody .= "<tr>
                        <td><strong>{$jobRef}</strong></td>
                        <td>{$plantInfo}</td>
                        <td>{$driver}</td>
                        <td>{$shift}</td>
                        <td>" . $job['booking_date'] . "</td>
                        <td><strong>{$totalDue}</strong></td>
                      </tr>";
        
        $pdfPath = generateJobPdfFile($job);
        if ($pdfPath) {
            $attachments[] = $pdfPath;
        }
    }
    
    $htmlBody .= "</table><br><p><em>Automated by Estate Hub Fleet System</em></p>";

    $emailSuccess = sendSystemEmail($recipients, $subject, $htmlBody, $attachments);

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
