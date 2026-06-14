<?php
/**
 * plant_dashboard.php - Director's Command Center for Plant Hub
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

// -----------------------------------------------------------------
// MICRO-API: Feed Live GPS Data to the Map (Keeps logic in one file)
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'map_data') {
    header('Content-Type: application/json');
    $query = "
        SELECT pb.id, pb.status, pb.location_lat, pb.location_lng, pb.client_name, 
               p.name as plant_name, p.category, prj.name as project_name 
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        LEFT JOIN projects prj ON pb.project_id = prj.id 
        WHERE pb.status IN ('In Progress', 'Paused') 
        AND pb.location_lat IS NOT NULL AND pb.location_lat != ''
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
// -----------------------------------------------------------------

include 'header.php'; 
?>

<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    body { background-color: #f1f5f9; }
    
    .cmd-center { max-width: 1600px; margin: 0 auto; padding: 30px 20px; width: 100%; box-sizing: border-box; font-family: 'Inter', sans-serif; color: #0f172a; }
    
    /* Header Polish */
    .header-bar { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; }
    .page-title { font-size: 2.2rem; font-weight: 900; margin: 0 0 5px 0; color: #1e293b; letter-spacing: -0.5px; }
    .page-subtitle { font-size: 1.1rem; margin: 0; color: #64748b; font-weight: 500; }
    .action-btn { padding: 12px 24px; background: #2563eb; color: white; border-radius: 8px; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); }
    .action-btn:hover { background: #1d4ed8; transform: translateY(-1px); }

    /* KPI Cards */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .kpi-card { background: #ffffff; padding: 25px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; position: relative; overflow: hidden; }
    .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; }
    .kpi-card.blue::before { background: #3b82f6; }
    .kpi-card.orange::before { background: #f59e0b; }
    .kpi-card.green::before { background: #10b981; }
    .kpi-card.purple::before { background: #8b5cf6; }
    
    .kpi-title { font-size: 0.85rem; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.5px; margin-bottom: 5px; }
    .kpi-value { font-size: 2.5rem; font-weight: 900; color: #0f172a; line-height: 1; }

    /* Dashboard Layout */
    .dash-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; }
    @media (max-width: 1100px) { .dash-layout { grid-template-columns: 1fr; } }

    /* UI Panels */
    .panel { background: #ffffff; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; overflow: hidden; display: flex; flex-direction: column; }
    .panel-header { padding: 20px 25px; border-bottom: 1px solid #e2e8f0; font-size: 1.1rem; font-weight: 800; color: #1e293b; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; }
    .panel-body { padding: 25px; flex: 1; }

    /* Tables */
    .modern-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .modern-table th { font-size: 0.8rem; text-transform: uppercase; color: #64748b; padding: 12px 10px; border-bottom: 2px solid #e2e8f0; text-align: left; }
    .modern-table td { padding: 15px 10px; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem; color: #334155; }
    .modern-table tr:last-child td { border-bottom: none; }

    /* Uninvoiced List */
    .alert-card { border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px; margin-bottom: 12px; transition: 0.2s; display: flex; justify-content: space-between; align-items: center; background: #fff; }
    .alert-card:hover { border-color: #3b82f6; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1); }
    .alert-btn { padding: 8px 16px; font-size: 0.85rem; background: #f1f5f9; color: #2563eb; border: none; border-radius: 6px; cursor: pointer; font-weight: 700; transition: 0.2s; }
    .alert-btn:hover { background: #2563eb; color: #fff; }

    /* Map Markers */
    .map-marker-pulse { width: 16px; height: 16px; background: #10b981; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); animation: pulse 1.5s infinite; }
    .map-marker-paused { width: 16px; height: 16px; background: #f59e0b; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
    @keyframes pulse { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }

    /* FullCalendar Polish */
    .fc { font-family: 'Inter', sans-serif; --fc-border-color: #e2e8f0; --fc-list-event-hover-bg-color: #f8fafc; }
    .fc .fc-toolbar-title { font-weight: 900 !important; font-size: 1.3rem !important; color: #1e293b; }
    .fc .fc-list-day-cushion { background-color: #f1f5f9 !important; font-weight: 800; color: #475569; padding: 12px !important; }
    .fc .fc-button-primary { background: #fff !important; border: 1px solid #cbd5e1 !important; color: #475569 !important; font-weight: 600; text-transform: capitalize; }
    .fc .fc-button-active { background: #f1f5f9 !important; color: #0f172a !important; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05) !important; }
</style>

<div class="cmd-center">
    <div class="header-bar">
        <div>
            <h1 class="page-title">Fleet Command Center</h1>
            <p class="page-subtitle" id="dynamic-subtitle">Analyzing Operations Data...</p>
        </div>
        <a href="plant_bookings.php" target="_blank" class="action-btn">
            <i class="fas fa-layer-group"></i> Manage Operations Hub
        </a>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card blue">
            <div class="kpi-title">Bookings (Selected View)</div>
            <div class="kpi-value" id="kpi-total">0</div>
        </div>
        <div class="kpi-card orange">
            <div class="kpi-title">Pending Execution</div>
            <div class="kpi-value" id="kpi-pending">0</div>
        </div>
        <div class="kpi-card green">
            <div class="kpi-title">Successfully Completed</div>
            <div class="kpi-value" id="kpi-completed">0</div>
        </div>
        <div class="kpi-card purple">
            <div class="kpi-title">Invoiced Revenue</div>
            <div class="kpi-value" id="kpi-revenue">€0.00</div>
        </div>
    </div>

    <div class="dash-layout">
        
        <div class="panel">
            <div class="panel-header">
                <div><i class="fas fa-satellite-dish" style="color: #3b82f6; margin-right: 8px;"></i> Live Fleet Telemetry</div>
                <span style="font-size: 0.75rem; background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 20px; font-weight: 700;">Live</span>
            </div>
            <div id="fleetMap" style="height: 450px; width: 100%;"></div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div><i class="fas fa-exclamation-circle" style="color: #ef4444; margin-right: 8px;"></i> Action Required</div>
            </div>
            <div class="panel-body" style="background: #f8fafc; padding: 20px; overflow-y: auto; max-height: 450px;">
                <p style="font-size: 0.85rem; color: #64748b; margin-top: 0;">Completed jobs awaiting ERP Sync.</p>
                <div id="uninvoiced-list-container">
                    <p style="color: #94a3b8; font-size: 0.9rem; text-align: center; margin-top: 40px;"><i class="fas fa-spinner fa-spin"></i> Loading alerts...</p>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div><i class="fas fa-calendar-alt" style="color: #8b5cf6; margin-right: 8px;"></i> Master Agenda</div>
            </div>
            <div class="panel-body">
                <div id="director-calendar"></div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div><i class="fas fa-users" style="color: #10b981; margin-right: 8px;"></i> Driver Performance</div>
            </div>
            <div class="panel-body">
                <div id="driver-list-container">
                    <p style="color: #94a3b8; font-size: 0.9rem; text-align: center; margin-top: 40px;"><i class="fas fa-spinner fa-spin"></i> Loading metrics...</p>
                </div>
            </div>
        </div>
        
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        
        // --- 1. INITIALIZE LIVE MAP ---
        // Carto Voyager is a beautiful, clean base map that looks highly professional
        const map = L.map('fleetMap').setView([35.917973, 14.409943], 11); // Centered on Malta
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap &copy; CARTO',
            subdomains: 'abcd',
            maxZoom: 19
        }).addTo(map);

        function loadMapTelemetry() {
            fetch('plant_dashboard.php?action=map_data')
            .then(r => r.json())
            .then(jobs => {
                // Clear existing markers
                map.eachLayer((layer) => {
                    if (layer instanceof L.Marker) { layer.remove(); }
                });

                jobs.forEach(job => {
                    if (job.location_lat && job.location_lng) {
                        const isWorking = job.status === 'In Progress';
                        
                        // Custom CSS Marker based on status
                        const iconHtml = isWorking 
                            ? '<div class="map-marker-pulse"></div>' 
                            : '<div class="map-marker-paused"></div>';
                            
                        const customIcon = L.divIcon({ html: iconHtml, className: '', iconSize: [16, 16], iconAnchor: [8, 8] });
                        
                        const clientText = job.client_name ? job.client_name : (job.project_name || 'Unknown Location');
                        const statusBadge = isWorking 
                            ? `<span style="background:#10b981; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">Active</span>`
                            : `<span style="background:#f59e0b; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">Paused</span>`;

                        const popupHtml = `
                            <div style="font-family:'Inter', sans-serif; min-width: 180px;">
                                <div style="font-weight:bold; font-size:1rem; margin-bottom:5px;">${job.plant_name}</div>
                                <div style="font-size:0.85rem; color:#475569; margin-bottom:8px;">${clientText}</div>
                                ${statusBadge}
                            </div>
                        `;

                        L.marker([job.location_lat, job.location_lng], { icon: customIcon })
                         .addTo(map)
                         .bindPopup(popupHtml);
                    }
                });
            });
        }
        
        loadMapTelemetry();
        setInterval(loadMapTelemetry, 60000); // Auto-refresh telemetry every minute

        // --- 2. INITIALIZE CALENDAR & KPIs ---
        const calendarEl = document.getElementById('director-calendar');
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'listWeek',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'listDay,listWeek,listMonth'
            },
            height: 600,
            events: 'api/plant_actions.php?action=fetch_bookings',
            
            datesSet: function(info) {
                document.getElementById('dynamic-subtitle').innerText = "Data Date Range: " + info.startStr.split('T')[0] + " to " + info.endStr.split('T')[0];
                
                const fd = new FormData();
                fd.append('action', 'get_dashboard_stats');
                fd.append('start', info.startStr);
                fd.append('end', info.endStr);
                
                fetch('api/plant_actions.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    // Update KPIs
                    document.getElementById('kpi-total').innerText = data.kpi.total_jobs || 0;
                    document.getElementById('kpi-pending').innerText = data.kpi.pending_jobs || 0;
                    document.getElementById('kpi-completed').innerText = data.kpi.completed_jobs || 0;
                    document.getElementById('kpi-revenue').innerText = '€' + parseFloat(data.kpi.invoiced_revenue || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
                    
                    // Update Drivers
                    const drvCont = document.getElementById('driver-list-container');
                    if (data.drivers.length === 0) {
                        drvCont.innerHTML = '<p style="color: #94a3b8; font-size: 0.9rem; text-align: center; margin-top: 40px;"><i class="fas fa-bed"></i> No hours scheduled.</p>';
                    } else {
                        let dHtml = '<table class="modern-table"><thead><tr><th>Driver</th><th>Jobs</th><th>Est. Hrs</th><th>Act. Hrs</th></tr></thead><tbody>';
                        data.drivers.forEach(d => {
                            let act = parseFloat(d.actual_hours) > 0 ? `<span style="color:#10b981; font-weight:700;">${parseFloat(d.actual_hours).toFixed(1)}h</span>` : `<span style="color:#cbd5e1;">-</span>`;
                            dHtml += `<tr>
                                <td style="font-weight: 700;">${d.first_name} ${d.last_name}</td>
                                <td>${d.job_count}</td>
                                <td>${parseFloat(d.scheduled_hours).toFixed(1)}h</td>
                                <td>${act}</td>
                            </tr>`;
                        });
                        dHtml += '</tbody></table>';
                        drvCont.innerHTML = dHtml;
                    }

                    // Update Alerts
                    const uninvCont = document.getElementById('uninvoiced-list-container');
                    if (data.uninvoiced.length === 0) {
                        uninvCont.innerHTML = '<div style="padding: 25px; text-align: center; background: #ecfdf5; border: 1px dashed #10b981; color: #047857; border-radius: 8px; font-weight: 700;"><i class="fas fa-check-circle" style="font-size:2rem; margin-bottom:10px;"></i><br>All completed jobs are invoiced!</div>';
                    } else {
                        let uHtml = '';
                        data.uninvoiced.forEach(uj => {
                            let clientStr = uj.client_name ? uj.client_name : (uj.project_name ? uj.project_name : 'Unknown Client');
                            uHtml += `
                            <div class="alert-card">
                                <div style="overflow: hidden; padding-right: 15px;">
                                    <div style="font-weight: 800; font-size: 0.95rem; color: #1e293b; margin-bottom: 4px;">${uj.plant_name}</div>
                                    <div style="font-size: 0.8rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <i class="far fa-calendar-alt"></i> ${uj.formatted_date} &nbsp;|&nbsp; <i class="far fa-building"></i> ${clientStr}
                                    </div>
                                </div>
                                <button onclick="window.open('print_plant_invoice.php?booking_id=${uj.id}', '_blank')" class="alert-btn">
                                    Invoice
                                </button>
                            </div>`;
                        });
                        uninvCont.innerHTML = uHtml;
                    }
                });
            }
        });
        
        calendar.render();
    });
</script>

<?php include 'footer.php'; ?>
