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
$praEmails = ['nicholasv@pramalta.com']; // Add actual emails here
$praxEmails = ['nicholasv@pramalta.com']; // Add actual emails here

function generateJobPdfFile($job) {
    $tempDir = __DIR__ . '/../temp_pdfs/';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    // 1. Generate the clean Job Ref Name (e.g., PRA-2026-0027)
    $prefix = ($job['billing_company_id'] == '26') ? 'PRAX' : 'PRA';
    $year = date('Y', strtotime($job['booking_date']));
    $paddedId = str_pad($job['id'], 4, '0', STR_PAD_LEFT);
    $jobRef = $job['job_ref'] ?? "{$prefix}-{$year}-{$paddedId}";

    $filePath = $tempDir . "{$jobRef}.pdf";

    // Prepare Signature Image (If your DB saves a base64 signature, it renders here)
    $signatureHtml = "";
    if (!empty($job['client_signature'])) {
        // Assuming 'client_signature' column holds something like "data:image/png;base64,iVBORw0K..."
        $signatureHtml = "<img src='" . $job['client_signature'] . "' style='max-height: 50px; max-width: 200px;'>";
    }

    // 2. Build the Professional HTML Layout for DomPDF
    $html = "
    <html>
    <head>
        <style>
            body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.4; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .title { font-size: 20px; font-weight: bold; color: #1e293b; text-transform: uppercase; }
            .ref-box { text-align: right; font-size: 14px; }
            .section-title { background: #f1f5f9; font-weight: bold; padding: 6px 10px; margin-top: 20px; margin-bottom: 10px; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; color: #475569; }
            .info-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            .info-table td { padding: 4px 0; vertical-align: top; }
            .info-label { width: 30%; font-weight: bold; color: #64748b; }
            .info-value { width: 70%; color: #0f172a; }
            .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 20px; }
            .data-table th { background: #0f172a; color: white; text-align: left; padding: 8px; font-size: 11px; text-transform: uppercase; }
            .data-table td { padding: 10px 8px; border-bottom: 1px solid #e2e8f0; }
            .totals-table { width: 100%; border-collapse: collapse; }
            .totals-table td { padding: 4px 8px; text-align: right; }
            .totals-label { font-weight: bold; color: #64748b; }
            .sig-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .sig-table td { padding: 8px 0; font-size: 13px; }
            .footer { margin-top: 40px; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 15px; }
        </style>
    </head>
    <body>

        <table class='header-table'>
            <tr>
                <td class='title'>{$prefix}<br><span style='font-size:12px; color:#64748b; font-weight:normal;'>DELIVERY NOTE / RFP</span></td>
                <td class='ref-box'>
                    <strong>Date:</strong> " . date('d M Y', strtotime($job['booking_date'])) . "<br>
                    <strong>Job Ref:</strong> <span style='color:#3b82f6; font-weight:bold;'>{$jobRef}</span>
                </td>
            </tr>
        </table>

        <div class='section-title'>Billed To (Client Details)</div>
        <table class='info-table'>
            <tr>
                <td class='info-label'>ERP Account Name</td>
                <td class='info-value'><strong>" . ($job['client_name'] ?? 'PRA CONSTRUCTION-ARIA SANNAT') . "</strong></td>
            </tr>
            <tr>
                <td class='info-label'>ERP Account Code</td>
                <td class='info-value'>" . ($job['client_code'] ?? '0539') . "</td>
            </tr>
            <tr>
                <td class='info-label'>Project/Location</td>
                <td class='info-value'>" . htmlspecialchars($job['location'] ?? 'Arya') . "</td>
            </tr>
            <tr>
                <td class='info-label'>Booking Type</td>
                <td class='info-value'>" . htmlspecialchars($job['booking_type'] ?? 'IN-HOUSE') . "</td>
            </tr>
        </table>

        <div class='section-title'>Job Report (Execution Details)</div>
        <table class='info-table'>
            <tr>
                <td class='info-label'>Machinery</td>
                <td class='info-value'>" . htmlspecialchars($job['plant_name']) . "</td>
            </tr>
            <tr>
                <td class='info-label'>Reg Plate</td>
                <td class='info-value'>" . htmlspecialchars($job['reg_plate'] ?? 'PLT_019') . "</td>
            </tr>
            <tr>
                <td class='info-label'>Driver</td>
                <td class='info-value'>" . htmlspecialchars($job['driver_name'] ?? 'Yusuf Koska') . "</td>
            </tr>
            <tr>
                <td class='info-label'>Time Logged</td>
                <td class='info-value'>" . ($job['time_start'] ?? '07:00') . " to " . ($job['time_end'] ?? '10:00') . "</td>
            </tr>
        </table>

        <table class='data-table'>
            <thead>
                <tr>
                    <th style='width: 15%;'>ERP Code</th>
                    <th style='width: 50%;'>Description</th>
                    <th style='width: 10%; text-align: center;'>Qty/Hrs</th>
                    <th style='width: 10%; text-align: right;'>Rate (€)</th>
                    <th style='width: 15%; text-align: right;'>Amount (€)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>" . ($job['erp_code'] ?? '0020') . "</td>
                    <td>Fixed Callout Charge<br><span style='font-size:11px; color:#64748b;'>(Job Ref: {$jobRef})</span></td>
                    <td style='text-align: center;'>" . number_format($job['qty'] ?? 1, 2) . "</td>
                    <td style='text-align: right;'>" . number_format($job['rate'] ?? 700, 4) . "</td>
                    <td style='text-align: right;'>" . number_format($job['subtotal'] ?? 700, 2) . "</td>
                </tr>
            </tbody>
        </table>

        <div class='section-title'>Client Representative Verification</div>
        <table style='width: 100%;'>
            <tr>
                <td style='width: 50%; vertical-align: top; padding-right: 20px;'>
                    <table class='sig-table'>
                        <tr>
                            <td style='width: 40px;'><strong>Name:</strong></td>
                            <td style='border-bottom: 1px solid #94a3b8; color: #0f172a; padding-left: 5px;'>" . htmlspecialchars($job['client_rep_name'] ?? 'Maka') . "</td>
                        </tr>
                        <tr>
                            <td><strong>ID:</strong></td>
                            <td style='border-bottom: 1px solid #94a3b8; color: #0f172a; padding-left: 5px;'>" . htmlspecialchars($job['client_rep_id'] ?? '') . "</td>
                        </tr>
                        <tr>
                            <td style='vertical-align: bottom; height: 50px;'><strong>Sign:</strong></td>
                            <td style='border-bottom: 1px solid #94a3b8; vertical-align: bottom; padding-left: 5px; height: 50px;'>
                                {$signatureHtml}
                            </td>
                        </tr>
                    </table>
                </td>

                <td style='width: 50%; vertical-align: top;'>
                    <table class='totals-table'>
                        <tr>
                            <td class='totals-label'>Gross Subtotal</td>
                            <td>€ " . number_format($job['subtotal'] ?? 700, 2) . "</td>
                        </tr>
                        <tr>
                            <td class='totals-label'>Net Subtotal</td>
                            <td>€ " . number_format($job['subtotal'] ?? 700, 2) . "</td>
                        </tr>
                        <tr>
                            <td class='totals-label'>VAT (18%)</td>
                            <td>€ " . number_format(($job['subtotal'] ?? 700) * 0.18, 2) . "</td>
                        </tr>
                        <tr style='font-size: 15px; font-weight: bold; color: #0f172a;'>
                            <td style='border-top: 2px solid #0f172a; padding-top: 6px;'>Total Due</td>
                            <td style='border-top: 2px solid #0f172a; padding-top: 6px;'>€ " . number_format(($job['subtotal'] ?? 700) * 1.18, 2) . "</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <div class='footer'>
            <strong>Payment Instructions:</strong> Payable to " . ($prefix === 'PRAX' ? 'PRAX Concrete Ltd.' : 'PRA Construction Ltd.') . "<br>
            <strong>Bank:</strong> BOV &nbsp;|&nbsp; <strong>IBAN:</strong> MT44VALL22013000000050004052994
        </div>

    </body>
    </html>";

    // 3. Render via DomPDF
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    file_put_contents($filePath, $dompdf->output());

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
    
    // --- UPDATED EMAIL BRANDING ---
    $htmlBody = "<h2>Plant Bookings Hub</h2>";
    $htmlBody .= "<h3>$companyName - Daily Delivery Notes</h3>";
    $htmlBody .= "<p>Please find attached the delivery notes for completed plant bookings for <strong>$dateLabel</strong>.</p>";
    
    // --- UPDATED EMAIL TABLE WITH MORE INFO ---
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
        $plantInfo = htmlspecialchars($job['plant_name']) . "<br><span style='font-size:11px; color:#64748b;'>" . htmlspecialchars($job['reg_plate'] ?? '') . "</span>";
        $driver = htmlspecialchars($job['driver_name'] ?? 'N/A');
        $shift = ($job['time_start'] ?? '--:--') . " to " . ($job['time_end'] ?? '--:--');
        $totalDue = number_format(($job['subtotal'] ?? 700) * 1.18, 2); // Assuming 18% VAT applied

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
