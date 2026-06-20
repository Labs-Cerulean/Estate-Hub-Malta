<?php
/**
 * cron_lifecycle_manager.php - Automated Asset Deployment Closer
 * * This script runs autonomously to close "Auto-Scheduled" (driverless) jobs 
 * once their scheduled end date has passed, allowing them to flow into the billing ledger.
 */

require_once 'init.php'; // Includes your PDO database connection

// --- 1. ENTERPRISE SECURITY BARRIER ---
// We strictly protect this endpoint so random internet bots cannot trigger your billing cycles.
$providedToken = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
$expectedToken = getenv('CRON_SECRET_TOKEN');

if (empty($expectedToken) || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    die("Unauthorized: Invalid or missing Cron Token.");
}

// Force Malta Timezone
date_default_timezone_set('Europe/Malta');
$currentDateTime = new DateTime();
$todayDateStr = $currentDateTime->format('Y-m-d');

echo "Initializing Lifecycle Manager at " . $currentDateTime->format('Y-m-d H:i:s') . "...\n";

// --- 2. FIND EXPIRED AUTO-SCHEDULED JOBS ---
// Find jobs that are driverless, currently open, and their end date is in the past.
$query = "
    SELECT pb.id, pb.booking_date, pb.end_date, pb.end_time, pb.start_time, p.name as plant_name
    FROM plant_bookings pb
    JOIN plants p ON pb.plant_id = p.id
    WHERE p.lifecycle_type = 'Auto-Scheduled'
    AND pb.status IN ('Pending', 'In Progress', 'Paused')
    AND COALESCE(pb.end_date, pb.booking_date) < ?
";

$stmt = $pdo->prepare($query);
$stmt->execute([$todayDateStr]);
$expiredJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($expiredJobs) === 0) {
    echo "Scan complete. No expired auto-scheduled jobs found.\n";
    exit;
}

echo "Found " . count($expiredJobs) . " expired job(s) to close.\n";

// --- 3. PROCESS & CLOSE EACH JOB ---
$updateStmt = $pdo->prepare("
    UPDATE plant_bookings 
    SET status = 'Completed', 
        punch_out_time = ?, 
        punch_in_time = COALESCE(punch_in_time, ?) 
    WHERE id = ?
");

$auditStmt = $pdo->prepare("
    INSERT INTO plant_audit_log (user_id, booking_id, action_type, details, ip_address, created_at) 
    VALUES (0, ?, 'SYSTEM_AUTO_CLOSE', ?, 'CRON_SERVER', NOW())
");

$closedCount = 0;

foreach ($expiredJobs as $job) {
    $jobId = $job['id'];
    $finalDate = !empty($job['end_date']) ? $job['end_date'] : $job['booking_date'];
    
    // We set their official "Punch Out" time to exactly when they were scheduled to end
    $endTimeStr = !empty($job['end_time']) ? $job['end_time'] : '17:00:00';
    $calculatedPunchOut = $finalDate . ' ' . $endTimeStr;
    
    // If they never "started", we backfill the start time so the math engine doesn't break
    $startTimeStr = !empty($job['start_time']) ? $job['start_time'] : '08:00:00';
    $calculatedPunchIn = $job['booking_date'] . ' ' . $startTimeStr;
    
    try {
        $pdo->beginTransaction();
        
        // 1. Close the job
        $updateStmt->execute([$calculatedPunchOut, $calculatedPunchIn, $jobId]);
        
        // 2. Log the automated action
        $details = "Automated deployment period reached. System safely closed the job for " . $job['plant_name'] . ".";
        $auditStmt->execute([$jobId, $details]);
        
        $pdo->commit();
        echo "Successfully closed Job ID: $jobId\n";
        $closedCount++;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Failed to close Job ID: $jobId. Error: " . $e->getMessage() . "\n";
    }
}

echo "\nLifecycle Manager run complete. $closedCount job(s) pushed to the ledger.\n";
?>
