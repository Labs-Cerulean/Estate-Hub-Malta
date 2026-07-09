<?php
/**
 * manage-delivery-emails.php - Hidden Standalone Manual Trigger
 * This file is unlinked in the UI. Access it by typing the URL directly.
 */
require_once 'init.php';

// SECURITY: Ensure a random person can't just type this URL and use it.
// They must be logged in as an authorized role to view the page.
if (!isset($_SESSION['user_id']) || !function_exists('getCurrentRole')) {
    header('Location: login.php');
    exit;
}

$role = getCurrentRole();
if (!in_array($role, ['admin', 'director', 'system_manager'])) {
    die("Unauthorized access. You do not have permission to view this control panel.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Note Control Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 30px; border-radius: 12px; width: 100%; max-width: 450px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3); box-sizing: border-box; border: 1px solid #334155; }
        h1 { font-size: 1.5rem; margin-top: 0; color: #f1f5f9; display: flex; align-items: center; gap: 10px; }
        p { color: #94a3b8; font-size: 0.95rem; line-height: 1.5; margin-bottom: 25px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 0.85rem; color: #cbd5e1; text-transform: uppercase; letter-spacing: 0.5px; }
        input[type="date"] { width: 100%; padding: 12px; background: #0f172a; border: 1px solid #475569; border-radius: 6px; color: white; font-size: 1rem; margin-bottom: 20px; box-sizing: border-box; }
        input[type="date"]:focus { border-color: #3b82f6; outline: none; }
        .btn-submit { width: 100%; background: #3b82f6; border: none; color: white; padding: 14px; border-radius: 6px; font-weight: bold; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: background 0.2s; }
        .btn-submit:hover { background: #2563eb; }
        .btn-submit:disabled { background: #475569; cursor: not-allowed; }
        #log-window { margin-top: 25px; background: #0f172a; border-radius: 6px; padding: 15px; font-family: monospace; font-size: 0.85rem; border: 1px solid #334155; display: none; }
        .log-success { color: #4ade80; }
        .log-error { color: #f87171; }
    </style>
</head>
<body>

<div class="card">
    <h1><i class="fas fa-paper-plane" style="color: #3b82f6;"></i> Delivery Note Mailer</h1>
    <p>Select a date range to generate and email the PRA and PRAX billing report: overdue bookings, jobs awaiting invoice, and invoiced delivery notes (PDFs attach only to invoiced jobs).</p>
    
    <form id="emailForm" onsubmit="triggerManualEmails(event)">
        <label for="start_date">Start Date</label>
        <input type="date" id="start_date" required value="<?php echo date('Y-m-d'); ?>">
        
        <label for="end_date">End Date</label>
        <input type="date" id="end_date" required value="<?php echo date('Y-m-d'); ?>">
        
        <button type="submit" id="btn-submit" class="btn-submit">
            <span>Send Billing Report</span>
        </button>
    </form>

    <div id="log-window"></div>
</div>

<script>
function triggerManualEmails(event) {
    event.preventDefault();
    
    const start = document.getElementById('start_date').value;
    const end = document.getElementById('end_date').value;
    const btn = document.getElementById('btn-submit');
    const log = document.getElementById('log-window');
    
    // UI Loading State
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing System Orders...';
    log.style.display = 'block';
    log.innerHTML = '<span style="color:#64748b;">> Connecting to API execution kernel...</span>';
    
    const formData = new FormData();
    formData.append('start_date', start);
    formData.append('end_date', end);
    
    // Send request to your background endpoint
    fetch('api/cron_delivery_reports.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) throw new Error('Network execution failure.');
        return response.json();
    })
    .then(data => {
        if (data.status === 'success') {
            log.innerHTML = `
                <div class="log-success">> Success: Processing complete for ${data.date_range}</div>
                <div style="margin-top:5px; color:#cbd5e1;">- PRA Status: ${data.results.pra}</div>
                <div style="color:#cbd5e1;">- PRAX Status: ${data.results.prax}</div>
            `;
        } else {
            log.innerHTML = `<div class="log-error">> System Error: ${data.message}</div>`;
        }
    })
    .catch(err => {
        log.innerHTML = `<div class="log-error">> Execution Interrupted: Unable to contact mail cluster.</div>`;
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = 'Send Billing Report';
    });
}
</script>

</body>
</html>
