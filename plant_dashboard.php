<?php
/**
 * plant_dashboard.php - Director's Desktop View for Plant Hub
 * Cleaned version: ZERO custom CSS hacks on FullCalendar.
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

include 'header.php'; // Include your standard Estate Hub desktop header
?>

<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>

<style>
    /* Clean Dashboard Structure */
    .plant-dir-container { max-width: 1600px; margin: 0 auto; padding: 20px; font-family: 'Inter', sans-serif; }
    .page-title { font-size: 2rem; font-weight: 900; margin-bottom: 5px; color: #0f172a; }
    .page-subtitle { font-size: 1rem; margin-bottom: 25px; color: #64748b; }
    
    /* Top KPI Grid */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .kpi-card { padding: 20px; border-radius: 8px; background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .kpi-title { font-size: 0.85rem; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.5px; margin-bottom: 8px; }
    .kpi-value { font-size: 2.2rem; font-weight: 900; color: #0f172a; }
    
    /* Middle Reports Grid */
    .reports-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 30px; margin-bottom: 30px; }
    .panel { padding: 25px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .panel-header { font-size: 1.2rem; font-weight: 800; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 20px; color: #0f172a; }
    
    /* Tables */
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .data-table th { font-weight: 700; text-align: left; padding: 12px 10px; border-bottom: 2px solid #e2e8f0; color: #475569; }
    .data-table td { padding: 12px 10px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
    .data-table tr:last-child td { border-bottom: none; }
    
    /* Simple Modal */
    #jobModalOverlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    #jobModal { width: 500px; max-width: 90%; border-radius: 16px; padding: 30px; position: relative; background: #fff; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
    .close-modal { position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer; }
    .close-modal:hover { color: #0f172a; }
</style>

<div class="plant-dir-container">
    <div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 class="page-title">Fleet Director Dashboard</h1>
            <p class="page-subtitle" id="dynamic-subtitle">Loading dates...</p>
        </div>
        <div style="margin-bottom: 25px;">
            <a href="plant_bookings.php" target="_blank" style="padding: 10px 20px; background: #3b82f6; color: white; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-flex; align-items: center; gap: 8px;"><i class="fas fa-external-link-alt"></i> Open Operations Hub</a>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card" style="border-top: 4px solid #3b82f6;">
            <div class="kpi-title">Total Bookings</div>
            <div class="kpi-value" id="kpi-total">0</div>
        </div>
        <div class="kpi-card" style="border-top: 4px solid #f59e0b;">
            <div class="kpi-title">Pending Execution</div>
            <div class="kpi-value" id="kpi-pending">0</div>
        </div>
        <div class="kpi-card" style="border-top: 4px solid #10b981;">
            <div class="kpi-title">Completed Jobs</div>
            <div class="kpi-value" id="kpi-completed">0</div>
        </div>
        <div class="kpi-card" style="border-top: 4px solid #8b5cf6; background: #f8fafc;">
            <div class="kpi-title" style="color: #6d28d9;">Invoiced Revenue</div>
            <div class="kpi-value" id="kpi-revenue" style="color: #6d28d9;">€0.00</div>
        </div>
    </div>

    <div class="reports-grid">
        <div class="panel">
            <div class="panel-header">Driver Hours (Current View)</div>
            <div id="driver-list-container">
                <p style="color: #64748b; font-size: 0.9rem;">Loading metrics...</p>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header" style="color: #ef4444;">Action Required: Un-Invoiced</div>
            <p style="font-size: 0.8rem; color: #64748b; margin-top: -15px; margin-bottom: 20px;">Jobs completed in this timeframe missing final ERP sync.</p>
            <div id="uninvoiced-list-container">
                <p style="color: #64748b; font-size: 0.9rem;">Loading list...</p>
            </div>
        </div>
    </div>

    <div class="panel" style="padding: 25px;">
        <div class="panel-header" style="margin-bottom: 20px;">Master Fleet Schedule</div>
        <div id="director-calendar"></div>
    </div>
</div>

<div id="jobModalOverlay">
    <div id="jobModal">
        <button class="close-modal" onclick="document.getElementById('jobModalOverlay').style.display='none'"><i class="fas fa-times"></i></button>
        <h3 id="m_title" style="margin-top: 0; font-weight: 900; font-size: 1.5rem; color: #0f172a;">Job Details</h3>
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
        
        // Pure initialization with NO forced heights or layout hacks.
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth', // Defaulting to Monthly avoids the timeGrid scrollbar bug entirely.
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listWeek'
            },
            slotMinTime: '06:00:00',
            slotMaxTime: '20:00:00',
            allDaySlot: false,
            events: 'api/plant_actions.php?action=fetch_bookings',
            
            datesSet: function(info) {
                document.getElementById('dynamic-subtitle').innerText = "Current View: " + info.view.title;
                
                const fd = new FormData();
                fd.append('action', 'get_dashboard_stats');
                fd.append('start', info.startStr);
                fd.append('end', info.endStr);
                
                fetch('api/plant_actions.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    document.getElementById('kpi-total').innerText = data.kpi.total_jobs || 0;
                    document.getElementById('kpi-pending').innerText = data.kpi.pending_jobs || 0;
                    document.getElementById('kpi-completed').innerText = data.kpi.completed_jobs || 0;
                    document.getElementById('kpi-revenue').innerText = '€' + parseFloat(data.kpi.invoiced_revenue || 0).toFixed(2);
                    
                    const drvCont = document.getElementById('driver-list-container');
                    if (data.drivers.length === 0) {
                        drvCont.innerHTML = '<p style="color: #64748b; font-size: 0.9rem;">No hours scheduled in this view.</p>';
                    } else {
                        let dHtml = '<table class="data-table"><thead><tr><th style="width:40%;">Driver</th><th>Jobs</th><th>Est. Hrs</th></tr></thead><tbody>';
                        data.drivers.forEach(d => {
                            let act = parseFloat(d.actual_hours) > 0 ? `<br><small style="color: #10b981;">Act: ${parseFloat(d.actual_hours).toFixed(1)}h</small>` : '';
                            dHtml += `<tr>
                                <td style="font-weight: 600;">${d.first_name} ${d.last_name}</td>
                                <td>${d.job_count}</td>
                                <td>${parseFloat(d.scheduled_hours).toFixed(1)}h ${act}</td>
                            </tr>`;
                        });
                        dHtml += '</tbody></table>';
                        drvCont.innerHTML = dHtml;
                    }

                    const uninvCont = document.getElementById('uninvoiced-list-container');
                    if (data.uninvoiced.length === 0) {
                        uninvCont.innerHTML = '<div style="padding: 15px; background: #ecfdf5; color: #065f46; border-radius: 8px; font-size: 0.9rem; font-weight: 600; text-align: center;"><i class="fas fa-check-circle"></i> All completed jobs are invoiced!</div>';
                    } else {
                        let uHtml = '<div style="display: flex; flex-direction: column; gap: 10px;">';
                        data.uninvoiced.forEach(uj => {
                            let clientStr = uj.client_name ? uj.client_name : (uj.project_name ? uj.project_name : 'Unknown');
                            uHtml += `
                            <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; background: #f8fafc; display: flex; justify-content: space-between; align-items: center;">
                                <div style="overflow: hidden;">
                                    <div style="font-weight: 700; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #0f172a;">${uj.plant_name}</div>
                                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <b>Date:</b> ${uj.formatted_date} | <b>Client:</b> ${clientStr}
                                    </div>
                                </div>
                                <button onclick="window.open('print_plant_invoice.php?booking_id=${uj.id}', '_blank')" style="min-width: 120px; padding: 8px 12px; font-size: 0.8rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; transition: background 0.2s;">
                                    Invoice Job
                                </button>
                            </div>`;
                        });
                        uHtml += '</div>';
                        uninvCont.innerHTML = uHtml;
                    }
                });
            },
            
            eventClick: function(info) {
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
                        ${job.comments ? `<div style="margin-top: 15px; padding: 15px; background: #f1f5f9; border-left: 4px solid #6366f1; border-radius: 4px;"><b>Notes:</b><br>${job.comments}</div>` : ''}
                    `;
                    
                    document.getElementById('jobModalOverlay').style.display = 'flex';
                });
            }
        });
        
        calendar.render();
    });
</script>

<?php include 'footer.php'; ?>
