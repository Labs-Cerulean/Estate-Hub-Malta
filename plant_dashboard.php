<?php
/**
 * plant_dashboard.php - Director's Desktop View for Plant Hub
 * Final version: Fixed the FullCalendar 1.11M pixel engine bug.
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
    /* Desktop Dashboard Structure */
    .plant-dir-container { max-width: 1600px; margin: 0 auto; padding: 20px; width: 100%; box-sizing: border-box; }
    .page-title { font-size: 2rem; font-weight: 900; margin-bottom: 5px; opacity: 0.9; }
    .page-subtitle { font-size: 1rem; margin-bottom: 25px; opacity: 0.7; }
    
    /* Top KPI Grid */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .kpi-card { padding: 20px; border-bottom-width: 4px; border-bottom-style: solid; border-radius: 8px; background: rgba(100, 116, 139, 0.05); }
    .kpi-title { font-size: 0.85rem; text-transform: uppercase; font-weight: 700; opacity: 0.7; letter-spacing: 0.5px; }
    .kpi-value { font-size: 2.2rem; font-weight: 900; margin-top: 5px; }
    
    /* Middle Reports Grid */
    .reports-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 30px; margin-bottom: 30px; }
    .panel { padding: 25px; background: rgba(100, 116, 139, 0.05); border-radius: 12px; width: 100%; box-sizing: border-box; }
    .panel-header { font-size: 1.2rem; font-weight: 800; border-bottom: 2px solid rgba(100,116,139,0.2); padding-bottom: 10px; margin-bottom: 20px; opacity: 0.9;}
    
    /* Tables */
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; table-layout: fixed; }
    .data-table th { font-weight: 700; text-align: left; padding: 12px 10px; border-bottom: 2px solid rgba(100,116,139,0.2); opacity: 0.7; }
    .data-table td { padding: 12px 10px; border-bottom: 1px solid rgba(100,116,139,0.1); word-wrap: break-word; }
    .data-table tr:last-child td { border-bottom: none; }
    
    /* Calendar Overrides - Minimal & Clean */
    .fc { font-size: 0.95rem; }
    .fc-theme-standard td, .fc-theme-standard th, .fc-theme-standard .fc-scrollgrid { border-color: rgba(100,116,139,0.2) !important; }
    .fc .fc-toolbar-title { font-weight: 800 !important; font-size: 1.5rem !important; opacity: 0.9; }
    
    /* Allow event text to wrap naturally */
    .fc-event { white-space: normal !important; margin-bottom: 3px !important; }
    .fc-event-title { white-space: normal !important; line-height: 1.4; padding: 4px; overflow-wrap: break-word !important; font-size: 0.85rem; }
    
    /* Safety lock for the master container */
    .calendar-wrapper { width: 100%; overflow-x: hidden; }
    
    /* Simple Modal */
    #jobModalOverlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.7); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    #jobModal { width: 500px; max-width: 90%; border-radius: 16px; padding: 30px; position: relative; background: #1e293b; color: #f8fafc; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
    .close-modal { position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 1.5rem; color: inherit; opacity: 0.6; cursor: pointer; }
    .close-modal:hover { opacity: 1; }
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
        <div class="kpi-card" style="border-bottom-color: #3b82f6;">
            <div class="kpi-title">Total Scheduled Bookings</div>
            <div class="kpi-value" id="kpi-total">0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color: #f59e0b;">
            <div class="kpi-title">Pending Execution</div>
            <div class="kpi-value" id="kpi-pending">0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color: #10b981;">
            <div class="kpi-title">Completed Jobs</div>
            <div class="kpi-value" id="kpi-completed">0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color: #8b5cf6;">
            <div class="kpi-title" style="color: #a78bfa;">Invoiced Revenue</div>
            <div class="kpi-value" id="kpi-revenue" style="color: #a78bfa;">€0.00</div>
            <div style="font-size: 0.75rem; opacity: 0.7; margin-top: 5px;">*Excludes un-invoiced/pending jobs</div>
        </div>
    </div>

    <div class="reports-grid">
        <div class="panel">
            <div class="panel-header">Driver Hours (Current View)</div>
            <div id="driver-list-container">
                <p style="opacity: 0.7; font-size: 0.9rem;">Loading metrics...</p>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header" style="color: #ef4444;">
                Action Required: Un-Invoiced
            </div>
            <p style="font-size: 0.8rem; opacity: 0.7; margin-top: -15px; margin-bottom: 20px;">Jobs completed in this timeframe missing final ERP sync.</p>
            <div id="uninvoiced-list-container">
                <p style="opacity: 0.7; font-size: 0.9rem;">Loading list...</p>
            </div>
        </div>
    </div>

    <div style="background: rgba(100, 116, 139, 0.05); padding: 25px; border-radius: 12px; margin-bottom: 40px; width: 100%; box-sizing: border-box;">
        <div style="font-size: 1.2rem; font-weight: 800; border-bottom: 2px solid rgba(100,116,139,0.2); padding-bottom: 10px; margin-bottom: 20px; opacity: 0.9;">Master Fleet Schedule</div>
        
        <div class="calendar-wrapper">
            <div id="director-calendar"></div>
        </div>
    </div>
</div>

<div id="jobModalOverlay">
    <div id="jobModal">
        <button class="close-modal" onclick="document.getElementById('jobModalOverlay').style.display='none'"><i class="fas fa-times"></i></button>
        <h3 id="m_title" style="margin-top: 0; font-weight: 900; font-size: 1.5rem;">Job Details</h3>
        <hr style="border: 1px solid rgba(255,255,255,0.1); margin: 15px 0;">
        <div id="m_body" style="font-size: 1rem; opacity: 0.9; line-height: 1.6;">
            Loading...
        </div>
        <div style="margin-top: 25px; display: flex; justify-content: flex-end;">
            <button onclick="document.getElementById('jobModalOverlay').style.display='none'" style="padding: 10px 20px; background: rgba(255,255,255,0.1); color: inherit; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

<script>
    // Delaying initialization until the browser has fully drawn the page prevents any remaining size-guessing bugs
    window.addEventListener('load', function() {
        const calendarEl = document.getElementById('director-calendar');
        
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listWeek'
            },
            slotMinTime: '06:00:00',
            slotMaxTime: '20:00:00',
            allDaySlot: false,
            
            // THE FIX: Giving it a solid, fixed height allows FullCalendar to calculate internal scrollbars correctly. 
            // Setting 'auto' here is what caused the 1.11 million pixel panic!
            height: 750, 
            
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
                        drvCont.innerHTML = '<p style="opacity: 0.7; font-size: 0.9rem;">No hours scheduled in this view.</p>';
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
                        uninvCont.innerHTML = '<div style="padding: 15px; background: rgba(16, 185, 129, 0.1); color: #10b981; border-radius: 8px; font-size: 0.9rem; font-weight: 600; text-align: center;"><i class="fas fa-check-circle"></i> All completed jobs are invoiced!</div>';
                    } else {
                        let uHtml = '<div style="display: flex; flex-direction: column; gap: 10px;">';
                        data.uninvoiced.forEach(uj => {
                            let clientStr = uj.client_name ? uj.client_name : (uj.project_name ? uj.project_name : 'Unknown');
                            uHtml += `
                            <div style="border: 1px solid rgba(100,116,139,0.2); border-radius: 8px; padding: 15px; background: rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center;">
                                <div style="overflow: hidden;">
                                    <div style="font-weight: 700; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${uj.plant_name}</div>
                                    <div style="font-size: 0.8rem; opacity: 0.8; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
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
                        ${job.comments ? `<div style="margin-top: 15px; padding: 15px; background: rgba(0,0,0,0.2); border-left: 4px solid #6366f1; border-radius: 4px;"><b>Notes:</b><br>${job.comments}</div>` : ''}
                    `;
                    
                    document.getElementById('jobModalOverlay').style.display = 'flex';
                });
            }
        });
        
        calendar.render();
    });
</script>

<?php include 'footer.php'; ?>
