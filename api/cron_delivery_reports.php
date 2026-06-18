<?php
/**
 * api/cron_delivery_reports.php
 * Automated & Manual Daily Delivery Notes Mailer for PRA and PRAX
 */

require_once '../init.php'; 
require_once '../email_helper.php';
require_once '../vendor/autoload.php'; 

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

session_write_close(); 

$startDate = $_POST['start_date'] ?? $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_POST['end_date'] ?? $_GET['end_date'] ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT pb.*, p.name as plant_name, p.registration_plate, p.billing_company_id, p.category,
           p.pricing_type, p.min_hours, p.nom_code_fixed, p.nom_code_variable, p.setup_fee, p.nom_code_setup,
           bc.name as developer_name, bc.logo_path as developer_logo, bc.bank_name, bc.iban,
           u.first_name, u.last_name, prj.name as project_name
    FROM plant_bookings pb 
    JOIN plants p ON pb.plant_id = p.id 
    JOIN clients bc ON p.billing_company_id = bc.id
    LEFT JOIN projects prj ON pb.project_id = prj.id
    LEFT JOIN users u ON pb.driver_id = u.id 
    WHERE pb.booking_date >= ? AND pb.booking_date <= ? 
    AND pb.status = 'Completed'
");
$stmt->execute([$startDate, $endDate]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$praJobs = [];
$praxJobs = [];

foreach ($jobs as $job) {
    if ($job['billing_company_id'] == '24') { 
        $praJobs[] = $job; 
    } elseif ($job['billing_company_id'] == '26') { 
        $praxJobs[] = $job; 
    }
}

$praEmails = ['nicholasv@pramalta.com']; 
$praxEmails = ['nicholasv@pramalta.com']; 

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

    // --- REBUILDING THE JAVASCRIPT MATH IN NATIVE PHP ---
    $pricingType = $job['pricing_type'];
    $minHours = (float)$job['min_hours'];
    
    $rateFixed = (float)($job['final_rate_fixed'] ?? 0);
    $rateVar = (float)($job['final_rate_var'] ?? 0);
    $rateSetup = (float)($job['final_setup_fee'] ?? 0);
    $hasSetupFee = ((isset($job['apply_setup_fee']) && $job['apply_setup_fee'] == 1) || $rateSetup > 0);
    
    $totalQty = (float)($job['final_hours'] ?? 0);
    if ($pricingType === 'per_trip' && $job['qty_trips'] > 0) {
        $totalQty = (float)$job['qty_trips'];
    }
    
    $rawNomFixed = htmlspecialchars($job['nom_code_fixed'] ?? 'MISSING');
    $rawNomVar = htmlspecialchars($job['nom_code_variable'] ?? 'MISSING');
    $rawNomSetup = htmlspecialchars($job['nom_code_setup'] ?? '0000');

    $tableRows = "";
    $grossSubtotal = 0;

    if ($hasSetupFee) {
        $sTotal = $rateSetup;
        $grossSubtotal += $sTotal;
        $tableRows .= "<tr>
            <td><b>{$rawNomSetup}</b></td>
            <td>Setup / Mobilisation Fee<br><i style='font-size:11px; color:#64748b;'>(Job Ref: {$jobRef})</i></td>
            <td style='text-align: right;'>1.00</td>
            <td style='text-align: right;'>" . number_format($rateSetup, 4) . "</td>
            <td style='text-align: right;'><b>" . number_format($sTotal, 2) . "</b></td>
        </tr>";
    }

    if ($pricingType === 'fixed_then_hourly') {
        $fTotal = $rateFixed;
        $grossSubtotal += $fTotal;
        $tableRows .= "<tr>
            <td><b>{$rawNomFixed}</b></td>
            <td>Fixed Callout Charge<br><i style='font-size:11px; color:#64748b;'>(Job Ref: {$jobRef})</i></td>
            <td style='text-align: right;'>1.00</td>
            <td style='text-align: right;'>" . number_format($rateFixed, 4) . "</td>
            <td style='text-align: right;'><b>" . number_format($fTotal, 2) . "</b></td>
        </tr>";

        $extraHours = max(0, $totalQty - $minHours);
        if ($extraHours > 0) {
            $vTotal = $extraHours * $rateVar;
            $grossSubtotal += $vTotal;
            $tableRows .= "<tr>
                <td><b>{$rawNomVar}</b></td>
                <td>Additional Hourly Rate<br><i style='font-size:11px; color:#64748b;'>(Extra Hours > {$minHours})</i></td>
                <td style='text-align: right;'>" . number_format($extraHours, 2) . "</td>
                <td style='text-align: right;'>" . number_format($rateVar, 4) . "</td>
                <td style='text-align: right;'><b>" . number_format($vTotal, 2) . "</b></td>
            </tr>";
        }
    } elseif ($pricingType === 'per_trip') {
        $tTotal = $totalQty * $rateFixed;
        $grossSubtotal += $tTotal;
        $tableRows .= "<tr>
            <td><b>{$rawNomFixed}</b></td>
            <td>Trip Execution Charge<br><i style='font-size:11px; color:#64748b;'>(Job Ref: {$jobRef})</i></td>
            <td style='text-align: right;'>{$totalQty} Trips</td>
            <td style='text-align: right;'>" . number_format($rateFixed, 4) . "</td>
            <td style='text-align: right;'><b>" . number_format($tTotal, 2) . "</b></td>
        </tr>";
    } else {
        $hTotal = $totalQty * $rateVar;
        $grossSubtotal += $hTotal;
        $tableRows .= "<tr>
            <td><b>{$rawNomVar}</b></td>
            <td>Standard Hourly Operation<br><i style='font-size:11px; color:#64748b;'>(Job Ref: {$jobRef})</i></td>
            <td style='text-align: right;'>{$totalQty} Hrs</td>
            <td style='text-align: right;'>" . number_format($rateVar, 4) . "</td>
            <td style='text-align: right;'><b>" . number_format($hTotal, 2) . "</b></td>
        </tr>";
    }

    $discountPct = (float)($job['final_discount_pct'] ?? 0);
    $totalDiscount = $grossSubtotal * ($discountPct / 100);
    $netSubtotal = $grossSubtotal - $totalDiscount;
    $vat = $netSubtotal * 0.18;
    $finalTotal = $netSubtotal + $vat;

    $clientDisplay = !empty($job['client_name']) ? htmlspecialchars($job['client_name']) : 'N/A';
    $clientCodeDisplay = !empty($job['client_code']) ? htmlspecialchars($job['client_code']) : 'MISSING CODE';
    $projectDisplay = !empty($job['project_name']) ? htmlspecialchars($job['project_name']) : 'N/A';
    
    $tIn = !empty($job['punch_in_time']) ? date('H:i', strtotime($job['punch_in_time'])) : (!empty($job['start_time']) ? date('H:i', strtotime($job['start_time'])) : '--:--');
    $tOut = !empty($job['punch_out_time']) ? date('H:i', strtotime($job['punch_out_time'])) : (!empty($job['end_time']) ? date('H:i', strtotime($job['end_time'])) : '--:--');
    $timeLogged = "{$tIn} to {$tOut}";
    
    $signatureHtml = "";
    if (!empty($job['signature_data'])) {
        $signatureHtml = "<img src='" . $job['signature_data'] . "' style='max-width: 100%; max-height: 80px; object-fit: contain;'>";
    } else {
        $signatureHtml = "<div style='height: 80px; line-height:80px; color: #94a3b8; font-style:italic;'>No Signature on File</div>";
    }
    
    $devName = htmlspecialchars($job['developer_name'] ?? 'Company');
    $bankName = htmlspecialchars($job['bank_name'] ?? 'BOV');
    $iban = htmlspecialchars($job['iban'] ?? 'MT44VALL22013000000050004052994');

    // --- EXACT REPLICA OF THE WEB HTML TEMPLATE ---
    $html = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: #fff; color: #000; font-size: 13px; line-height: 1.4; margin: 0; padding: 0; }
            .header { border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
            .header table { width: 100%; border: none; }
            .header td { border: none; padding: 0; vertical-align: bottom; }
            .title { font-size: 24px; font-weight: bold; text-transform: uppercase; color: #0f172a; }
            .grid-container { width: 100%; margin-bottom: 25px; }
            .grid-container td { vertical-align: top; padding: 0; border: none; }
            .box { padding: 15px; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc; }
            .box h4 { margin: 0 0 10px 0; color: #3b82f6; text-transform: uppercase; font-size: 11px; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; }
            .data-row { margin-bottom: 5px; }
            .data-label { font-weight: 600; color: #475569; display: inline-block; width: 35%; }
            .data-val { font-weight: 700; display: inline-block; width: 60%; vertical-align: top; }
            
            .invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 1px solid #cbd5e1; }
            .invoice-table th, .invoice-table td { border-bottom: 1px solid #cbd5e1; padding: 10px; text-align: left; }
            .invoice-table th { background: #f1f5f9; color: #475569; font-weight: 700; text-transform: uppercase; font-size: 11px; }
            
            .verification-box { border: 1px solid #cbd5e1; padding: 15px; border-radius: 8px; text-align: center; background: #f8fafc; margin-right: 20px; }
            .verification-box h4 { margin: 0 0 10px 0; color: #475569; text-transform: uppercase; font-size: 11px; }
            
            .totals-box { border: 1px solid #cbd5e1; border-radius: 8px; padding: 15px; background: #f8fafc; }
            .totals-row { margin-bottom: 8px; font-size: 14px; clear: both; overflow: auto; }
            .totals-row .label { float: left; font-weight: 600; color: #475569; }
            .totals-row .val { float: right; font-weight: 700; }
            .totals-final { border-top: 2px solid #000; padding-top: 8px; margin-top: 8px; font-size: 18px; font-weight: 900; }
            .totals-final .label { color: #000; }
        </style>
    </head>
    <body>
        <div class='header'>
            <table>
                <tr>
                    <td style='width: 50%;'>
                        <div class='title'>{$prefix}</div>
                        <div style='font-size:12px; color:#64748b; margin-top:2px;'>DELIVERY NOTE / RFP</div>
                    </td>
                    <td style='width: 50%; text-align: right;'>
                        <div style='color: #475569; margin-bottom: 4px;'>Date: <b>" . date('d M Y', strtotime($job['booking_date'])) . "</b></div>
                        <div style='color: #475569;'>Job Ref: <b style='color: #000;'>{$jobRef}</b></div>
                    </td>
                </tr>
            </table>
        </div>

        <table class='grid-container'>
            <tr>
                <td style='width: 48%; padding-right: 10px;'>
                    <div class='box'>
                        <h4>Billed To (Client Details)</h4>
                        <div class='data-row'><span class='data-label'>ERP Account Name</span><span class='data-val'>{$clientDisplay}</span></div>
                        <div class='data-row'><span class='data-label'>ERP Account Code</span><span class='data-val'>{$clientCodeDisplay}</span></div>
                        <div class='data-row'><span class='data-label'>Project / Location</span><span class='data-val'>{$projectDisplay}</span></div>
                        <div class='data-row'><span class='data-label'>Booking Type</span><span class='data-val' style='text-transform:uppercase;'>{$job['booking_type']}</span></div>
                    </div>
                </td>
                <td style='width: 48%; padding-left: 10px;'>
                    <div class='box'>
                        <h4>Job Report (Execution Details)</h4>
                        <div class='data-row'><span class='data-label'>Machinery</span><span class='data-val'>" . htmlspecialchars($job['plant_name']) . "</span></div>
                        <div class='data-row'><span class='data-label'>Reg Plate</span><span class='data-val'>" . htmlspecialchars($job['registration_plate'] ?? 'N/A') . "</span></div>
                        <div class='data-row'><span class='data-label'>Driver</span><span class='data-val'>" . htmlspecialchars($job['first_name'] ?? 'Unassigned') . " " . htmlspecialchars($job['last_name'] ?? '') . "</span></div>
                        <div class='data-row'><span class='data-label'>Time Logged</span><span class='data-val'>{$timeLogged}</span></div>
                    </div>
                </td>
            </tr>
        </table>

        <table class='invoice-table'>
            <thead>
                <tr>
                    <th style='width: 15%;'>ERP Code</th>
                    <th style='width: 45%;'>Description</th>
                    <th style='text-align: right;'>Qty / Hrs</th>
                    <th style='text-align: right;'>Rate (€)</th>
                    <th style='text-align: right;'>Amount (€)</th>
                </tr>
            </thead>
            <tbody>
                {$tableRows}
            </tbody>
        </table>

        <table style='width: 100%; border: none;'>
            <tr>
                <td style='width: 50%; vertical-align: top; border: none; padding: 0;'>
                    <div class='verification-box'>
                        <h4>Client Representative Verification</h4>
                        {$signatureHtml}
                        <div style='border-top: 1px solid #cbd5e1; margin-top: 10px; padding-top: 10px; font-size: 11px;'>
                            <b>Name:</b> " . htmlspecialchars($job['client_rep_name'] ?? 'N/A') . " &nbsp;|&nbsp; 
                            <b>ID:</b> " . htmlspecialchars($job['client_rep_id_card'] ?? 'N/A') . "
                        </div>
                    </div>
                    <div style='margin-top: 20px; font-size: 11px; color: #475569;'>
                        <b>Payment Instructions:</b> Payable to {$devName}.<br>
                        Bank: {$bankName} | IBAN: {$iban}
                    </div>
                </td>
                <td style='width: 50%; vertical-align: top; border: none; padding: 0;'>
                    <div class='totals-box'>
                        <div class='totals-row'>
                            <span class='label'>Gross Subtotal</span>
                            <span class='val'>€ " . number_format($grossSubtotal, 2) . "</span>
                        </div>
                        " . ($totalDiscount > 0 ? "
                        <div class='totals-row' style='color: #ef4444;'>
                            <span class='label'>Discount (" . number_format($discountPct, 1) . "%)</span>
                            <span class='val'>- € " . number_format($totalDiscount, 2) . "</span>
                        </div>" : "") . "
                        <div class='totals-row' style='border-top: 1px dashed #cbd5e1; padding-top: 5px; margin-top: 5px;'>
                            <span class='label'>Net Subtotal</span>
                            <span class='val'>€ " . number_format($netSubtotal, 2) . "</span>
                        </div>
                        <div class='totals-row'>
                            <span class='label'>VAT (18%)</span>
                            <span class='val'>€ " . number_format($vat, 2) . "</span>
                        </div>
                        <div class='totals-row totals-final'>
                            <span class='label'>Total Due</span>
                            <span class='val'>€ " . number_format($finalTotal, 2) . "</span>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Helvetica');
    $dompdf = new \Dompdf\Dompdf($options);
    
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    file_put_contents($filePath, $dompdf->output());

    return $filePath;
}

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
        
        $driverName = trim(($job['first_name'] ?? '') . ' ' . ($job['last_name'] ?? ''));
        $driver = !empty($driverName) ? htmlspecialchars($driverName) : 'N/A';
        
        $tIn = !empty($job['punch_in_time']) ? date('H:i', strtotime($job['punch_in_time'])) : (!empty($job['start_time']) ? date('H:i', strtotime($job['start_time'])) : '--:--');
        $tOut = !empty($job['punch_out_time']) ? date('H:i', strtotime($job['punch_out_time'])) : (!empty($job['end_time']) ? date('H:i', strtotime($job['end_time'])) : '--:--');
        $shift = "{$tIn} to {$tOut}";

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

$results = [];
$results['pra'] = processAndSendCompanyEmails('PRA Construction', $praJobs, $praEmails, $startDate, $endDate);

sleep(3); 

$results['prax'] = processAndSendCompanyEmails('PRAX Concrete', $praxJobs, $praxEmails, $startDate, $endDate);

header('Content-Type: application/json');
echo json_encode([
    'status' => 'success', 
    'date_range' => "$startDate to $endDate", 
    'results' => $results
]);
exit;
