<?php
/**
 * plant_dashboard.php - Director's Desktop View for Plant Hub
 * High-level reporting, scheduling, and financial metrics.
 */
require_once 'init.php';
require_once 'session-check.php';
require_once 'user-functions.php';

$role = $_SESSION['role'] ?? '';

// STRICT RESTRICTION: Only Admin and Director roles allowed
$hasDirectorAccess = in_array($role, ['admin', 'director']);
if (!$hasDirectorAccess) {
    die("Unauthorized Access. This dashboard is strictly restricted to Admins and Directors only.");
}

// 1. Calculate Weekly Date Boundaries
$startOfWeek = date('Y-m-d', strtotime('monday this week'));
$endOfWeek = date('Y-m-d', strtotime('sunday this week'));

// 2. Fetch Weekly KPIs
$statsStmt = $pdo->prepare("SELECT 
    COUNT(id) as total_jobs,
    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_jobs,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_jobs,
    SUM(CASE WHEN payment_status IN ('Invoiced', 'Settled') THEN final_subtotal ELSE 0 END) as invoiced_revenue
    FROM plant_bookings 
    WHERE booking_date >= ? AND booking_date <= ?");
$statsStmt->execute([$startOfWeek, $endOfWeek]);
$kpi = $statsStmt->fetch(PDO::FETCH_ASSOC);

// 3. Fetch Driver Hours Leaderboard (Current Week)
$driverStmt = $pdo->prepare("SELECT 
    u.first_name, u.last_name, 
    COUNT(pb.id) as job_count,
    SUM(TIME_TO_SEC(TIMEDIFF(pb.end_time, pb.start_time))/3600) as scheduled_hours,
    SUM(pb.final_hours) as actual_hours
    FROM plant_bookings pb
    JOIN users u ON pb.driver_id = u.id
    WHERE pb.booking_date >= ? AND pb.booking_date <= ?
    GROUP BY u.id
    ORDER BY scheduled_hours DESC");
$driverStmt->execute([$startOfWeek, $endOfWeek]);
$driverStats = $driverStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Fetch Action Required: Completed but Uninvoiced Jobs
$uninvoicedStmt = $pdo->query("SELECT pb.id, p.name as plant_name, pb.booking_date, pb.client_name, prj.name as project_name 
    FROM plant_bookings pb 
    JOIN plants p ON pb.plant_id = p.id
    LEFT JOIN projects prj ON pb.project_id = prj.id
    WHERE pb.status = 'Completed' AND pb.payment_status = 'Pending'
    ORDER BY pb.booking_date ASC LIMIT 8");
$uninvoicedJobs = $uninvoicedStmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php'; // Include your standard Estate Hub desktop header
?>

<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>

<style>
    /* Desktop-Specific Dashboard Styling */
    .plant-dir-container { max-width: 1600px; margin: 0 auto; padding: 20px; font-family: 'Inter', sans-serif; }
    .page-title { font-size: 2rem; font-weight: 900; color: #0f172a; margin-bottom: 5px; }
    .page-subtitle { color: #64748b; font-size: 1rem; margin-bottom: 25px; }
    
    /* KPI Grid */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .kpi-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .kpi-title { font-size: 0.85rem; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.5px; }
    .kpi-value { font-size: 2.2rem; font-weight: 900; color: #0f172a; margin-top: 5px; }
    
    /* CRASH FIX: Replaced CSS Grid with safe Flexbox Layout */
    .layout-flex { display: flex; gap: 30px; flex-wrap: wrap; }
    .calendar-panel { flex: 2.5; min-width: 0; /* min-width:0 is a flexbox safety lock */ }
    .side-panel { flex: 1; min-width: 320px; }
    
    .panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; }
    .panel-header { font-size: 1.2rem; font-weight: 800; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
    
    /* Tables */
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .data-table th { background: #f8fafc; color: #475569; font-weight: 700; text-align: left; padding: 10px; border-bottom: 1px solid #e2e8f0; }
    .data-table td { padding: 10px; border-bottom: 1px solid #e2e8f0; color: #0f172a; }
    .data-table tr:last-child td { border-bottom: none; }
    
    /* Calendar Overrides */
    .fc { font-size: 0.9rem; }
    .fc .fc-toolbar-title { font-weight: 800 !important; font-size: 1.5rem !important; color: #0f172a; }
    .fc-event-title { white-space: pre-wrap !important; line-height: 1.4; padding: 2px 4px; }
    
    /* Simple Modal */
    #jobModalOverlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    #jobModal { background: #fff; width: 500px; max-width: 90%; border-radius: 16px; padding: 30px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); position: relative; }
    .close-modal { position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer; }
    .close-modal:hover { color: #0f172a; }
</style>

<div class="plant-dir-container">
    <div style="display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h1 class="page-title">Fleet Director Dashboard</h1>
            <p class="page-subtitle">Week of <?= date('d M Y', strtotime($startOfWeek)) ?> to <?= date('d M Y', strtotime($endOfWeek)) ?></p>
        </div>
        <div style="margin-bottom: 25px;">
            <a href="plant_bookings.php" class="btn btn-primary" target="_blank"><i class="fas fa-external-link-alt"></i> Open Operations Hub</a>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card" style="border-bottom: 4px solid #3b82f6;">
            <div class="kpi-title">Total Scheduled Bookings</div>
            <div class="kpi-value"><?= number_format($kpi['total_jobs'] ?? 0) ?></div>
        </div>
        <div class="kpi-card" style="border-bottom: 4px solid #f59e0b;">
            <div class="kpi-title">Pending Execution</div>
            <div class="kpi-value"><?= number_format($kpi['pending_jobs'] ?? 0) ?></div>
        </div>
        <div class="kpi-card" style="border-bottom: 4px solid #10b981;">
            <div class="kpi-title">Completed Jobs</div>
            <div class="kpi-value"><?= number_format($kpi['completed_jobs'] ?? 0) ?></div>
        </div>
        <div class="kpi-card" style="border-bottom: 4px solid #6366f1; background: #f8fafc;">
            <div class="kpi-title" style="color: #4f46e5;">Invoiced Revenue (Week)</div>
            <div class="kpi-value" style="color: #4f46e5;">€<?= number_format($kpi['invoiced_revenue'] ?? 0, 2) ?></div>
            <div style="font-size: 0.75rem; color: #64748b; margin-top: 5px;">*Excludes un-invoiced/pending jobs</div>
        </div>
    </div>

    <div class="layout-flex">
        
        <div class="calendar-panel">
            <div class="panel">
                <div class="panel-header">Master Fleet Schedule</div>
                <div id="director-calendar"></div>
            </div>
        </div>

        <div class="side-panel">
            <div class="panel" style="margin-bottom: 30px;">
                <div class="panel-header">Driver Hours (This Week)</div>
                <?php if (empty($driverStats)): ?>
                    <p style="color: #64748b; font-size: 0.9rem;">No hours scheduled this week.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Driver</th>
                                <th>Jobs</th>
                                <th>Est. Hrs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($driverStats as $d): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?></td>
                                    <td><?= $d['job_count'] ?></td>
                                    <td>
                                        <?= number_format($d['scheduled_hours'], 1) ?>h
                                        <?php if ($d['actual_hours'] > 0): ?>
                                            <br><small style="color: #10b981;">Act: <?= number_format($d['actual_hours'], 1) ?>h</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-header" style="color: #ef4444;">
                    Action Required: Un-Invoiced
                </div>
                <p style="font-size: 0.8rem; color: #64748b; margin-top: -10px; margin-bottom: 15px;">Jobs marked completed by drivers but missing final ERP sync.</p>
                
                <?php if (empty($uninvoicedJobs)): ?>
                    <div style="padding: 15px; background: #ecfdf5; color: #065f46; border-radius: 8px; font-size: 0.9rem; font-weight: 600; text-align: center;">
                        <i class="fas fa-check-circle"></i> All completed jobs are invoiced!
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($uninvoicedJobs as $uj): ?>
                            <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; background: #f8fafc;">
                                <div style="font-weight: 700; font-size: 0.95rem; color: #0f172a;"><?= htmlspecialchars($uj['plant_name']) ?></div>
                                <div style="font-size: 0.8rem; color: #64748b; margin-top: 3px;">
                                    <b>Date:</b> <?= date('d M', strtotime($uj['booking_date'])) ?> | 
                                    <b>Client:</b> <?= htmlspecialchars($uj['client_name'] ?: ($uj['project_name'] ?: 'Unknown')) ?>
                                </div>
                                <button onclick="window.open('print_plant_invoice.php?booking_id=<?= $uj['id'] ?>', '_blank')" style="margin-top: 8px; padding: 4px 8px; font-size: 0.75rem; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
                                    Review & Invoice
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="jobModalOverlay">
    <div id="jobModal">
        <button class="close-modal" onclick="document.getElementById('jobModalOverlay').style.display='none'"><i class="fas fa-times"></i></button>
        <h3 id="m_title" style="margin-top: 0; color: #0f172a; font-weight: 900; font-size: 1.5rem;">Job Details</h3>
        <hr style="border: 1px solid #e2e8f0; margin: 15px 0;">
        <div id="m_body" style="font-size: 1rem; color: #475569; line-height: 1.6;">
            Loading...
        </div>
        <div style="margin-top: 25px; display: flex; justify-content: flex-end;">
            <button onclick="document.getElementById('jobModalOverlay').style.display='none'" style="padding: 10px 20px; background: #e2e8f0; color: #0f172a; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const calendarEl = document.getElementById('director-calendar');
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            slotMinTime: '06:00:00',
            slotMaxTime: '20:00:00',
            allDaySlot: false,
            contentHeight: 'auto', // Fixes internal scrolling
            events: 'api/plant_actions.php?action=fetch_bookings',
            eventClick: function(info) {
                // Fetch full details from API and populate modal
                fetch(`api/plant_actions.php?action=get_job&id=${info.event.id}`)
                .then(r => r.json())
                .then(job => {
                    document.getElementById('m_title').innerHTML = `<i class="fas fa-truck text-indigo-500"></i> ${job.plant_name}`;
                    
                    let statCol = job.status === 'Completed' ? '#10b981' : (job.status === 'In Progress' ? '#f59e0b' : '#6366f1');
                    
                    document.getElementById('m_body').innerHTML = `
                        <div style="margin-bottom:10px;"><b>Driver:</b> ${job.driver_id ? 'Assigned (ID: '+job.driver_id+')' : '<span style="color:#ef4444;">Unassigned</span>'}</div>
                        <div style="margin-bottom:10px;"><b>Date:</b> ${job.booking_date} (${job.start_time.substring(0,5)} - ${job.end_time.substring(0,5)})</div>
                        <div style="margin-bottom:10px;"><b>Status:</b> <span style="color:${statCol}; font-weight:bold;">${job.status}</span></div>
                        <div style="margin-bottom:10px;"><b>Target:</b> ${job.location_text}</div>
                        ${job.comments ? `<div style="margin-top: 15px; padding: 15px; background: #f8fafc; border-left: 4px solid #6366f1; border-radius: 4px;"><b>Notes:</b><br>${job.comments}</div>` : ''}
                    `;
                    
                    document.getElementById('jobModalOverlay').style.display = 'flex';
                });
            }
        });
        calendar.render();
    });
</script>

<?php include 'footer.php'; ?>
