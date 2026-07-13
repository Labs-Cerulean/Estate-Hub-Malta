<?php
/**
 * api/cron_delivery_reports.php
 * Automated & Manual Daily Delivery Notes Mailer for PRA and PRAX
 */

require_once '../init.php'; 
require_once '../email_helper.php';
require_once '../vendor/autoload.php'; 
require_once '../S3FileManager.php'; // ADDED: Required to fetch Cloudflare Logos
require_once __DIR__ . '/../includes/plant_rfp_lines.php';

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

date_default_timezone_set('Europe/Malta');
$todayDate = date('Y-m-d');

$startDate = $_POST['start_date'] ?? $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_POST['end_date'] ?? $_GET['end_date'] ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT pb.*, p.name as plant_name, p.registration_plate, p.billing_company_id, p.category,
           p.pricing_type, p.lifecycle_type, p.min_hours, p.nom_code_fixed, p.nom_code_variable, p.setup_fee, p.nom_code_setup,
           p.has_configurations, p.configurations,
           bc.name as developer_name, bc.logo_path as developer_logo, bc.bank_name, bc.iban,
           u.first_name, u.last_name, prj.name as project_name
    FROM plant_bookings pb 
    JOIN plants p ON pb.plant_id = p.id 
    JOIN clients bc ON p.billing_company_id = bc.id
    LEFT JOIN projects prj ON pb.project_id = prj.id
    LEFT JOIN users u ON pb.driver_id = u.id 
    WHERE pb.booking_date >= ? AND pb.booking_date <= ? 
    AND pb.status != 'Cancelled'
    AND p.billing_company_id IN ('24', '26')
");
$stmt->execute([$startDate, $endDate]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$praBuckets = ['overdue' => [], 'awaiting_invoice' => [], 'invoiced' => []];
$praxBuckets = ['overdue' => [], 'awaiting_invoice' => [], 'invoiced' => []];

foreach ($jobs as $job) {
    $bucket = classifyDeliveryReportJob($job, $todayDate);
    if ($bucket === null) {
        continue;
    }
    if ($job['billing_company_id'] == '24') {
        $praBuckets[$bucket][] = $job;
    } elseif ($job['billing_company_id'] == '26') {
        $praxBuckets[$bucket][] = $job;
    }
}

$praEmails = ['nicholasv@pramalta.com', 'accounts@pramalta.com', 'marka@agiusgroup.com', 'clydes@pramalta.com', 'jasons@pramalta.com']; 

$praxEmails = ['nicholasv@pramalta.com', 'thomasg@pandamalta.com', 'marka@agiusgroup.com', 'AlessiaA@AgiusGroup.Com', 'GabriellaA@AgiusGroup.Com', 'clydes@pramalta.com'];

function classifyDeliveryReportJob(array $job, string $todayDate): ?string {
    $status = $job['status'] ?? '';
    $payment = $job['payment_status'] ?? 'Pending';

    if ($status === 'Completed' && in_array($payment, ['Invoiced', 'Settled'], true)) {
        return 'invoiced';
    }
    if ($status === 'Completed' && $payment === 'Pending') {
        return 'awaiting_invoice';
    }
    if (in_array($status, ['Pending', 'In Progress', 'Paused'], true)) {
        $endDate = !empty($job['end_date']) ? $job['end_date'] : ($job['booking_date'] ?? '');
        if ($endDate !== '' && $endDate < $todayDate) {
            return 'overdue';
        }
    }
    return null;
}

function getDeliveryJobRef(array $job): string {
    $prefix = ($job['billing_company_id'] == '26') ? 'PRAX' : 'PRA';
    $year = date('Y', strtotime($job['booking_date']));
    $paddedId = str_pad((string)$job['id'], 4, '0', STR_PAD_LEFT);
    return $job['job_ref'] ?? "{$prefix}-{$year}-{$paddedId}";
}

function formatDeliveryScheduledWindow(array $job): string {
    $date = htmlspecialchars($job['booking_date'] ?? '—');
    $start = !empty($job['start_time']) ? substr($job['start_time'], 0, 5) : '';
    $end = !empty($job['end_time']) ? substr($job['end_time'], 0, 5) : '';
    if ($start && $end) {
        return "{$date}<br><span style='font-size:11px; color:#64748b;'>{$start} – {$end}</span>";
    }
    return $date;
}

function formatDeliveryJobCells(array $job, PDO $pdo): array {
    $jobRef = htmlspecialchars(getDeliveryJobRef($job));
    $plantInfo = htmlspecialchars($job['plant_name']) . "<br><span style='font-size:11px; color:#64748b;'>" . htmlspecialchars($job['registration_plate'] ?? '') . "</span>";
    $driverName = trim(($job['first_name'] ?? '') . ' ' . ($job['last_name'] ?? ''));
    $driver = !empty($driverName) ? htmlspecialchars($driverName) : 'N/A';
    $clientName = !empty($job['client_name']) ? htmlspecialchars($job['client_name']) : 'N/A';
    $clientCode = !empty($job['client_code']) ? htmlspecialchars($job['client_code']) : '—';
    $clientCell = "{$clientName}<br><span style='font-size:11px; color:#64748b;'>Code: {$clientCode}</span>";
    $locationCell = htmlspecialchars(getPlantJobLocationLabel($job));
    $sessions = getPlantJobSessions($pdo, (int)($job['id'] ?? 0));
    $shift = htmlspecialchars(getPlantJobTimeLogged($pdo, $job, $sessions));

    return compact('jobRef', 'plantInfo', 'driver', 'clientCell', 'locationCell', 'shift', 'sessions');
}

function formatDeliveryEstimatedTotal(array $job): string {
    $subtotal = (float)($job['final_subtotal'] ?? 0);
    if ($subtotal > 0) {
        return '<strong>€ ' . number_format($subtotal * 1.18, 2) . '</strong>';
    }
    return '<span style="color:#64748b;">Awaiting figures</span>';
}

function generateJobPdfFile($job, $pdo, $sessions = null) {
    $tempDir = __DIR__ . '/../temp_pdfs/';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    $prefix = ($job['billing_company_id'] == '26') ? 'PRAX' : 'PRA';
    $year = date('Y', strtotime($job['booking_date']));
    $paddedId = str_pad($job['id'], 4, '0', STR_PAD_LEFT);
    $jobRef = $job['job_ref'] ?? "{$prefix}-{$year}-{$paddedId}";
    $filePath = $tempDir . "{$jobRef}.pdf";

    // --- FETCH SECURE LOGO VIA CLOUDFLARE S3 ---
    $logoHtml = "";
    if (!empty($job['developer_logo'])) {
        $s3 = new S3FileManager();
        $logoPath = $job['developer_logo'];
        if (strpos($logoPath, 'http') === false) {
            $logoPath = $s3->getPresignedUrl($logoPath, '+60 minutes');
        }
        if ($logoPath) {
            $logoHtml = "<img src='{$logoPath}' style='max-width: 200px; max-height: 80px; object-fit: contain;'>";
        }
    } else {
        $devName = htmlspecialchars($job['developer_name'] ?? 'Company');
        $logoHtml = "<h2 style='margin:0; color:#0f172a; font-size:24px; text-transform:uppercase;'>{$devName}</h2>";
    }

    $pricingType = $job['pricing_type'];
    if ($sessions === null) {
        $sessions = getPlantJobSessions($pdo, (int)($job['id'] ?? 0));
    }

    $invoiceTable = buildPlantRfpInvoiceTable($job, $sessions, $jobRef);
    $tableRows = $invoiceTable['rows'];
    $grossSubtotal = $invoiceTable['grossSubtotal'];

    $discountPct = (float)($job['final_discount_pct'] ?? 0);
    $totalDiscount = $grossSubtotal * ($discountPct / 100);
    $netSubtotal = $grossSubtotal - $totalDiscount;
    $vat = $netSubtotal * 0.18;
    $finalTotal = $netSubtotal + $vat;

    $clientDisplay = !empty($job['client_name']) ? htmlspecialchars($job['client_name']) : 'N/A';
    $clientCodeDisplay = !empty($job['client_code']) ? htmlspecialchars($job['client_code']) : 'MISSING CODE';
    $projectDisplay = htmlspecialchars(getPlantJobLocationLabel($job));
    $timeLogged = htmlspecialchars(getPlantJobTimeLogged($pdo, $job, $sessions));

    $driverRaw = trim(($job['first_name'] ?? '') . ' ' . ($job['last_name'] ?? ''));
    $driverDisplay = $driverRaw !== '' ? htmlspecialchars($driverRaw) : 'Unassigned';
    
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
            body { font-family: 'DejaVu Sans', sans-serif; background: #fff; color: #000; font-size: 13px; line-height: 1.4; margin: 0; padding: 0; }
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
                        {$logoHtml}
                    </td>
                    <td style='width: 50%; text-align: right;'>
                        <div class='title'>DELIVERY NOTE / RFP</div>
                        <div style='color: #475569; margin-top: 5px; margin-bottom: 4px;'>Date: <b>" . date('d M Y', strtotime($job['booking_date'])) . "</b></div>
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
                        <div class='data-row'><span class='data-label'>Driver</span><span class='data-val'>{$driverDisplay}</span></div>
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
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    file_put_contents($filePath, $dompdf->output());

    return $filePath;
}

function processAndSendCompanyEmails($companyName, array $buckets, $recipients, $start, $end, $pdo) {
    $overdue = $buckets['overdue'] ?? [];
    $awaiting = $buckets['awaiting_invoice'] ?? [];
    $invoiced = $buckets['invoiced'] ?? [];

    $overdueCount = count($overdue);
    $awaitingCount = count($awaiting);
    $invoicedCount = count($invoiced);
    $totalCount = $overdueCount + $awaitingCount + $invoicedCount;

    if ($totalCount === 0) {
        return "0 jobs found.";
    }

    $isSingleDay = ($start === $end);
    $dateLabel = $isSingleDay ? $start : "$start to $end";

    $subject = "Plant Bookings Hub: $companyName Delivery Report ($dateLabel)";
    if ($overdueCount || $awaitingCount || $invoicedCount) {
        $subject .= " — {$overdueCount} overdue · {$awaitingCount} awaiting invoice · {$invoicedCount} invoiced";
    }

    $tableStyle = "border-collapse: collapse; width:100%; max-width: 1100px; font-family: Arial, sans-serif; font-size: 13px; margin-bottom: 24px;";
    $thStyle = "background:#0f172a; color:white; text-align:left; padding:8px;";
    $tdStyle = "padding:8px; border:1px solid #e2e8f0; vertical-align:top;";

    $htmlBody = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='font-family:Arial,sans-serif; color:#0f172a;'>";
    $htmlBody .= "<h2 style='margin-bottom:4px;'>Plant Bookings Hub</h2>";
    $htmlBody .= "<h3 style='margin-top:0; color:#334155;'>{$companyName} — Weekly Billing &amp; Action Report</h3>";
    $htmlBody .= "<p style='color:#475569;'>Period: <strong>{$dateLabel}</strong></p>";

    $htmlBody .= "<table cellpadding='0' cellspacing='0' style='border-collapse:collapse; margin: 16px 0 24px; max-width:700px; width:100%;'>
        <tr>
            <td style='padding:12px 16px; background:#fef2f2; border:1px solid #fecaca; border-left:4px solid #dc2626; width:33%;'>
                <div style='font-size:22px; font-weight:800; color:#dc2626;'>{$overdueCount}</div>
                <div style='font-size:11px; font-weight:700; text-transform:uppercase; color:#991b1b;'>Overdue</div>
            </td>
            <td style='padding:12px 16px; background:#fff7ed; border:1px solid #fed7aa; border-left:4px solid #ea580c; width:33%;'>
                <div style='font-size:22px; font-weight:800; color:#ea580c;'>{$awaitingCount}</div>
                <div style='font-size:11px; font-weight:700; text-transform:uppercase; color:#9a3412;'>Awaiting invoice</div>
            </td>
            <td style='padding:12px 16px; background:#f0fdf4; border:1px solid #bbf7d0; border-left:4px solid #16a34a; width:33%;'>
                <div style='font-size:22px; font-weight:800; color:#16a34a;'>{$invoicedCount}</div>
                <div style='font-size:11px; font-weight:700; text-transform:uppercase; color:#166534;'>Invoiced</div>
            </td>
        </tr>
    </table>";
    $htmlBody .= "<p style='color:#64748b; font-size:13px; margin-bottom:28px;'>PDF delivery notes are attached <strong>only</strong> for invoiced jobs (Section 3). Orange and red rows require action in Plant Bookings Hub.</p>";

    if ($overdueCount > 0) {
        $htmlBody .= "<h4 style='color:#dc2626; margin:0 0 6px; text-transform:uppercase; font-size:13px; letter-spacing:0.04em;'>Section 1 — Action required: Overdue open bookings</h4>";
        $htmlBody .= "<p style='margin:0 0 10px; color:#64748b; font-size:12px;'>These bookings are past their scheduled end date and are still open. Process on site or cancel in Plant Bookings Hub.</p>";
        $htmlBody .= "<table border='0' cellpadding='8' cellspacing='0' style='{$tableStyle}'>
            <tr>
                <th style='{$thStyle} background:#991b1b;'>Action required</th>
                <th style='{$thStyle}'>Job Ref</th>
                <th style='{$thStyle}'>Plant &amp; Reg</th>
                <th style='{$thStyle}'>Driver</th>
                <th style='{$thStyle}'>Client</th>
                <th style='{$thStyle}'>Project / Location</th>
                <th style='{$thStyle}'>Scheduled</th>
                <th style='{$thStyle}'>Status</th>
            </tr>";

        foreach ($overdue as $job) {
            $cells = formatDeliveryJobCells($job, $pdo);
            $status = htmlspecialchars($job['status'] ?? '—');
            $htmlBody .= "<tr style='background:#fef2f2;'>
                <td style='{$tdStyle} border-left:4px solid #dc2626; color:#dc2626; font-weight:700;'>Booking overdue — process or cancel</td>
                <td style='{$tdStyle}'><strong>{$cells['jobRef']}</strong></td>
                <td style='{$tdStyle}'>{$cells['plantInfo']}</td>
                <td style='{$tdStyle}'>{$cells['driver']}</td>
                <td style='{$tdStyle}'>{$cells['clientCell']}</td>
                <td style='{$tdStyle}'>{$cells['locationCell']}</td>
                <td style='{$tdStyle}'>" . formatDeliveryScheduledWindow($job) . "</td>
                <td style='{$tdStyle}'><strong>{$status}</strong></td>
            </tr>";
        }
        $htmlBody .= "</table>";
    }

    if ($awaitingCount > 0) {
        $htmlBody .= "<h4 style='color:#ea580c; margin:0 0 6px; text-transform:uppercase; font-size:13px; letter-spacing:0.04em;'>Section 2 — Action required: Completed jobs awaiting invoice</h4>";
        $htmlBody .= "<p style='margin:0 0 10px; color:#64748b; font-size:12px;'>Work is done but not yet invoiced. Complete billing figures and push to ERP.</p>";
        $htmlBody .= "<table border='0' cellpadding='8' cellspacing='0' style='{$tableStyle}'>
            <tr>
                <th style='{$thStyle} background:#9a3412;'>Action required</th>
                <th style='{$thStyle}'>Job Ref</th>
                <th style='{$thStyle}'>Plant &amp; Reg</th>
                <th style='{$thStyle}'>Driver</th>
                <th style='{$thStyle}'>Client</th>
                <th style='{$thStyle}'>Project / Location</th>
                <th style='{$thStyle}'>Shift time</th>
                <th style='{$thStyle}'>Date</th>
                <th style='{$thStyle}'>Est. total</th>
            </tr>";

        foreach ($awaiting as $job) {
            $cells = formatDeliveryJobCells($job, $pdo);
            $htmlBody .= "<tr style='background:#fff7ed;'>
                <td style='{$tdStyle} border-left:4px solid #ea580c; color:#ea580c; font-weight:700;'>Job completed — invoice &amp; push to ERP</td>
                <td style='{$tdStyle}'><strong>{$cells['jobRef']}</strong></td>
                <td style='{$tdStyle}'>{$cells['plantInfo']}</td>
                <td style='{$tdStyle}'>{$cells['driver']}</td>
                <td style='{$tdStyle}'>{$cells['clientCell']}</td>
                <td style='{$tdStyle}'>{$cells['locationCell']}</td>
                <td style='{$tdStyle}'>{$cells['shift']}</td>
                <td style='{$tdStyle}'>" . htmlspecialchars($job['booking_date'] ?? '—') . "</td>
                <td style='{$tdStyle}'>" . formatDeliveryEstimatedTotal($job) . "</td>
            </tr>";
        }
        $htmlBody .= "</table>";
    }

    $attachments = [];

    if ($invoicedCount > 0) {
        $htmlBody .= "<h4 style='color:#16a34a; margin:0 0 6px; text-transform:uppercase; font-size:13px; letter-spacing:0.04em;'>Section 3 — Invoiced delivery notes</h4>";
        $htmlBody .= "<p style='margin:0 0 10px; color:#64748b; font-size:12px;'>Delivery note PDFs for these jobs are attached to this email.</p>";
        $htmlBody .= "<table border='0' cellpadding='8' cellspacing='0' style='{$tableStyle}'>
            <tr>
                <th style='{$thStyle}'>Job Ref</th>
                <th style='{$thStyle}'>Plant &amp; Reg</th>
                <th style='{$thStyle}'>Driver</th>
                <th style='{$thStyle}'>Client</th>
                <th style='{$thStyle}'>Project / Location</th>
                <th style='{$thStyle}'>Shift time</th>
                <th style='{$thStyle}'>Date</th>
                <th style='{$thStyle}'>Total (€)</th>
                <th style='{$thStyle}'>Invoice ref</th>
            </tr>";

        foreach ($invoiced as $job) {
            $cells = formatDeliveryJobCells($job, $pdo);
            $subtotal = (float)($job['final_subtotal'] ?? 0);
            $totalDue = $subtotal > 0 ? '€ ' . number_format($subtotal * 1.18, 2) : 'TBC';
            $invoiceRef = !empty($job['invoice_sysref']) && !in_array($job['invoice_sysref'], ['N/A', 'SUCCESS_NO_REF'], true)
                ? htmlspecialchars($job['invoice_sysref'])
                : '—';

            $htmlBody .= "<tr>
                <td style='{$tdStyle}'><strong>{$cells['jobRef']}</strong></td>
                <td style='{$tdStyle}'>{$cells['plantInfo']}</td>
                <td style='{$tdStyle}'>{$cells['driver']}</td>
                <td style='{$tdStyle}'>{$cells['clientCell']}</td>
                <td style='{$tdStyle}'>{$cells['locationCell']}</td>
                <td style='{$tdStyle}'>{$cells['shift']}</td>
                <td style='{$tdStyle}'>" . htmlspecialchars($job['booking_date'] ?? '—') . "</td>
                <td style='{$tdStyle}'><strong>{$totalDue}</strong></td>
                <td style='{$tdStyle}'>{$invoiceRef}</td>
            </tr>";

            $pdfPath = generateJobPdfFile($job, $pdo, $cells['sessions']);
            if ($pdfPath) {
                $attachments[] = $pdfPath;
            }
        }
        $htmlBody .= "</table>";
    }

    $hubUrl = defined('APP_URL') ? rtrim(APP_URL, '/') . '/plant_bookings.php' : 'plant_bookings.php';
    $htmlBody .= "<p style='margin-top:24px; color:#64748b; font-size:12px;'><a href='{$hubUrl}' style='color:#2563eb;'>Open Plant Bookings Hub</a></p>";
    $htmlBody .= "<p><em>Automated by Estate Hub Fleet System</em></p></body></html>";

    $emailSuccess = sendSystemEmail($recipients, $subject, $htmlBody, $attachments);

    foreach ($attachments as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    if ($emailSuccess === true) {
        return "{$invoicedCount} delivery note(s) sent; {$overdueCount} overdue, {$awaitingCount} awaiting invoice flagged.";
    }
    return "Failed: " . $emailSuccess;
}

$results = [];
$results['pra'] = processAndSendCompanyEmails('PRA Construction', $praBuckets, $praEmails, $startDate, $endDate, $pdo);

sleep(3); 

$results['prax'] = processAndSendCompanyEmails('PRAX Concrete', $praxBuckets, $praxEmails, $startDate, $endDate, $pdo);

header('Content-Type: application/json');
echo json_encode([
    'status' => 'success', 
    'date_range' => "$startDate to $endDate", 
    'results' => $results
]);
exit;
