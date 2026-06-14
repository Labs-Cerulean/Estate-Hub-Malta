<?php
/**
 * plant_dashboard.php - Director's Command Center
 */
require_once 'init.php';
require_once 'session-check.php';
require_once 'user-functions.php';

$role = $_SESSION['role'] ?? '';
$hasDirectorAccess = in_array($role, ['admin', 'director']);
if (!$hasDirectorAccess) die("Unauthorized Access.");

// MICRO-API: Feed Map Data
if (isset($_GET['action']) && $_GET['action'] == 'map_data') {
    header('Content-Type: application/json');
    $mode = $_GET['mode'] ?? 'live';
    
    if ($mode === 'period') {
        $start = $_GET['start'] ?? date('Y-m-d');
        $end = $_GET['end'] ?? date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT pb.id, pb.status, pb.location_lat, pb.location_lng, pb.client_name, 
                   p.name as plant_name, p.category, prj.name as project_name 
            FROM plant_bookings pb 
            JOIN plants p ON pb.plant_id = p.id 
            LEFT JOIN projects prj ON pb.project_id = prj.id 
            WHERE pb.location_lat IS NOT NULL AND pb.location_lat != ''
            AND pb.booking_date >= ? AND pb.booking_date <= ?
        ");
        $stmt->execute([$start, $end]);
    } else {
        $stmt = $pdo->prepare("
            SELECT pb.id, pb.status, pb.location_lat, pb.location_lng, pb.client_name, 
                   p.name as plant_name, p.category, prj.name as project_name 
            FROM plant_bookings pb 
            JOIN plants p ON pb.plant_id = p.id 
            LEFT JOIN projects prj ON pb.project_id = prj.id 
            WHERE pb.status IN ('In Progress', 'Paused') 
            AND pb.location_lat IS NOT NULL AND pb.location_lat != ''
        ");
        $stmt->execute();
    }
    
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
include 'header.php'; 
?>

<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .cmd-center { max-width: 1600px; margin: 0 auto; padding: 30px 20px; width: 100%; box-sizing: border-box; }
    .header-bar { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
    .page-title { font-size: 2.2rem; font-weight: 900; margin: 0 0 5px 0; opacity: 0.9; }
    .page-subtitle { font-size: 1.1rem; margin: 0; opacity: 0.7; font-weight: 500; }
    
    .kpi-section-title { font-size: 1rem; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 15px; border-bottom: 2px solid rgba(128,128,128,0.2); padding-bottom: 5px; }
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
    
    /* Clickable KPI Cards */
    .kpi-card { background: rgba(128, 128, 128, 0.05); padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.2); border-bottom: 4px solid transparent; cursor: pointer; transition: 0.2s; position: relative; }
    .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.3); background: rgba(128, 128, 128, 0.1); }
    .kpi-card::after { content: '\f0b0'; font-family: 'Font Awesome 5 Free'; font-weight: 900; position: absolute; top: 15px; right: 15px; opacity: 0; transition: 0.2s; color: inherit; }
    .kpi-card:hover::after { opacity: 0.5; }
    
    .kpi-title { font-size: 0.8rem; text-transform: uppercase; font-weight: 700; opacity: 0.7; margin-bottom: 5px; }
    .kpi-value { font-size: 2rem; font-weight: 900; opacity: 0.9; line-height: 1.2; }

    .dash-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
    @media (max-width: 1100px) { .dash-layout { grid-template-columns: 1fr; } }

    .panel { background: rgba(128, 128, 128, 0.05); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.2); display: flex; flex-direction: column; overflow: hidden; }
    .panel-header { padding: 15px 20px; border-bottom: 2px solid rgba(128, 128, 128, 0.2); font-size: 1.2rem; font-weight: 800; opacity: 0.9; display: flex; align-items: center; justify-content: space-between; }
    .panel-body { padding: 20px; flex: 1; }

    /* Breakdown Table */
    .breakdown-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .breakdown-table th { background: rgba(0,0,0,0.1); padding: 10px; font-weight: 800; text-transform: uppercase; border: 1px solid rgba(128,128,128,0.2); opacity: 0.8; }
    .breakdown-table td { padding: 10px; border: 1px solid rgba(128,128,128,0.1); opacity: 0.9; text-align: center; }
    .breakdown-table td:first-child { text-align: left; font-weight: 700; }
    
    /* Map Markers */
    .map-marker-pulse { width: 16px; height: 16px; background: #10b981; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); animation: pulse 1.5s infinite; }
    .map-marker-paused { width: 16px; height: 16px; background: #f59e0b; border-radius: 50%; border: 3px solid #fff; }
    .map-marker-completed { width: 16px; height: 16px; background: #3b82f6; border-radius: 50%; border: 3px solid #fff; }
    .map-marker-pending { width: 16px; height: 16px; background: #94a3b8; border-radius: 50%; border: 3px solid #fff; }
    @keyframes pulse { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }

    /* Drilldown Modal */
    #drillModalOverlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    #drillModal { width: 700px; max-width: 95%; max-height: 85vh; background: #1e293b; color: #f8fafc; border-radius: 12px; padding: 25px; display: flex; flex-direction: column; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
    
    .fc { font-size: 0.95rem; --fc-page-bg-color: transparent; --fc-neutral-bg-color: rgba(255, 255, 255, 0.05); --fc-list-event-hover-bg-color: rgba(255, 255, 255, 0.1); --fc-border-color: rgba(128, 128, 128, 0.2); }
    .fc-theme-standard .fc-list { background: transparent !important; }
</style>

<div class="cmd-center">
    <div class="header-bar">
        <div>
            <h1 class="page-title">Fleet Command Center</h1>
            <p class="page-subtitle" id="dynamic-subtitle">Analyzing Operations Data...</p>
        </div>
    </div>

    <!-- MAP (Full Horizontal Width) -->
    <div class="panel" style="margin-bottom: 30px;">
        <div class="panel-header">
            <div><i class="fas fa-satellite-dish" style="color:#3b82f6;"></i> Fleet Telemetry</div>
            <div>
                <select id="mapModeToggle" onchange="loadMapTelemetry()" style="padding: 6px 12px; border-radius: 6px; border: 1px solid rgba(128,128,128,0.3); background: rgba(128,128,128,0.1); color: inherit; font-size: 0.85rem; font-weight: bold; cursor: pointer; outline: none;">
                    <option value="live">Live (Active Jobs Only)</option>
                    <option value="period">Period (All Jobs in Selected View)</option>
                </select>
            </div>
        </div>
        <div id="fleetMap" style="height: 350px; width: 100%; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; z-index: 1;"></div>
    </div>

    <!-- OPERATIONAL KPIs -->
    <div class="kpi-section-title">Operational Output (Quantity & Hours)</div>
    <div class="kpi-grid">
        <div class="kpi-card" style="border-bottom-color:#10b981;" onclick="openDrilldown('completed_book', 'Completed Bookings')">
            <div class="kpi-title">Completed Jobs</div>
            <div class="kpi-value" id="kpi-completed-book">0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color:#10b981;" onclick="openDrilldown('completed_hrs', 'Executed Hours')">
            <div class="kpi-title">Executed Hours</div>
            <div class="kpi-value" id="kpi-completed-hrs">0.0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color:#f59e0b;" onclick="openDrilldown('planned_book', 'Planned Bookings')">
            <div class="kpi-title">Planned Jobs</div>
            <div class="kpi-value" id="kpi-planned-book">0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color:#f59e0b;" onclick="openDrilldown('planned_hrs', 'Planned Hours')">
            <div class="kpi-title">Planned Hours</div>
            <div class="kpi-value" id="kpi-planned-hrs">0.0</div>
        </div>
    </div>

    <!-- FINANCIAL KPIs -->
    <div class="kpi-section-title">Financial Output (Revenue & RFPs)</div>
    <div class="kpi-grid">
        <div class="kpi-card" style="border-bottom-color:#10b981; color:#10b981;" onclick="openDrilldown('rev_gen', 'Revenue Generated')">
            <div class="kpi-title">Generated Revenue</div>
            <div class="kpi-value" id="kpi-rev-gen">€0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color:#f59e0b; color:#f59e0b;" onclick="openDrilldown('rev_pipe', 'Pipeline Revenue')">
            <div class="kpi-title">Pipeline Revenue</div>
            <div class="kpi-value" id="kpi-rev-pipe">€0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color:#3b82f6; color:#3b82f6;" onclick="openDrilldown('rev_total', 'Total Estimated Revenue')">
            <div class="kpi-title">Total Est. Revenue</div>
            <div class="kpi-value" id="kpi-rev-total">€0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color:#8b5cf6;" onclick="openDrilldown('rfps', 'Issued RFPs')">
            <div class="kpi-title">Issued RFPs</div>
            <div class="kpi-value" id="kpi-rfps">0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color:#ec4899; color:#ec4899;" onclick="openDrilldown('erp', 'ERP Synced Revenue')">
            <div class="kpi-title">ERP Synced Revenue</div>
            <div class="kpi-value" id="kpi-erp">€0</div>
        </div>
    </div>

    <!-- MAIN GRID (Agenda & Breakdown) -->
    <div class="dash-layout">
        <div class="panel">
            <div class="panel-header"><div><i class="fas fa-calendar-alt" style="color:#8b5cf6;"></i> Master Agenda (Click to view RFP)</div></div>
            <div class="panel-body"><div id="director-calendar"></div></div>
        </div>

        <div class="panel">
            <div class="panel-header"><div><i class="fas fa-chart-bar" style="color:#10b981;"></i> Plant Bookings Breakdown</div></div>
            <div class="panel-body" style="overflow-y: auto; padding: 0;">
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Machinery</th>
                            <th style="background:rgba(16,185,129,0.1); color:#10b981;">Completed<br><span style="font-size:0.7rem;">Qty / Hrs / €</span></th>
                            <th style="background:rgba(245,158,11,0.1); color:#f59e0b;">Planned<br><span style="font-size:0.7rem;">Qty / Hrs / €</span></th>
                            <th style="background:rgba(59,130,246,0.1); color:#3b82f6;">Combined Total<br><span style="font-size:0.7rem;">Qty / Hrs / €</span></th>
                        </tr>
                    </thead>
                    <tbody id="plant-breakdown-body">
                        <tr><td colspan="4" style="text-align:center; padding:20px;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Drilldown Modal -->
<div id="drillModalOverlay">
    <div id="drillModal">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:15px; margin-bottom:15px;">
            <h3 id="drillTitle" style="margin:0; font-size:1.4rem;">Metric Breakdown</h3>
            <button onclick="document.getElementById('drillModalOverlay').style.display='none'" style="background:none; border:none; color:#94a3b8; font-size:1.5rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <div style="overflow-y:auto; flex:1; margin-bottom: 20px;">
            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead style="position:sticky; top:0; background:#1e293b;">
                    <tr>
                        <th style="text-align:left; padding:10px; border-bottom:2px solid rgba(255,255,255,0.1); color:#94a3b8;">Date</th>
                        <th style="text-align:left; padding:10px; border-bottom:2px solid rgba(255,255,255,0.1); color:#94a3b8;">Job Details</th>
                        <th style="text-align:right; padding:10px; border-bottom:2px solid rgba(255,255,255,0.1); color:#94a3b8;">Value</th>
                    </tr>
                </thead>
                <tbody id="drillBody"></tbody>
            </table>
        </div>
        <div style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 15px; text-align: right;">
            <button onclick="document.getElementById('drillModalOverlay').style.display='none'" style="padding: 10px 24px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;">Close Window</button>
        </div>
    </div>
</div>

<script>
    let currentDrillData = {};
    let calStartCache = '';
    let calEndCache = '';

    function openDrilldown(key, title) {
        document.getElementById('drillTitle').innerText = title;
        const tbody = document.getElementById('drillBody');
        const data = currentDrillData[key] || [];
        
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; padding:30px; color:#64748b;">No data found for this period.</td></tr>';
        } else {
            tbody.innerHTML = data.map(item => `
                <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                    <td style="padding:12px 10px; white-space:nowrap; color:#cbd5e1;">${item.date}</td>
                    <td style="padding:12px 10px;">${item.desc}</td>
                    <td style="padding:12px 10px; text-align:right; font-weight:bold; color:#fff;">${item.val}</td>
                </tr>
            `).join('');
        }
        document.getElementById('drillModalOverlay').style.display = 'flex';
    }

    const map = L.map('fleetMap').setView([35.917973, 14.409943], 11);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', { maxZoom: 19 }).addTo(map);

    function loadMapTelemetry() {
        const mode = document.getElementById('mapModeToggle').value;
        let url = 'plant_dashboard.php?action=map_data&mode=' + mode;
        if (mode === 'period' && calStartCache !== '') {
            url += '&start=' + calStartCache + '&end=' + calEndCache;
        }

        fetch(url).then(r => r.json()).then(jobs => {
            map.eachLayer(layer => { if (layer instanceof L.Marker) layer.remove(); });
            jobs.forEach(job => {
                if (job.location_lat) {
                    let iconHtml = '';
                    let badge = '';
                    
                    if (job.status === 'In Progress') {
                        iconHtml = '<div class="map-marker-pulse"></div>';
                        badge = `<span style="background:#10b981; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">Active</span>`;
                    } else if (job.status === 'Paused') {
                        iconHtml = '<div class="map-marker-paused"></div>';
                        badge = `<span style="background:#f59e0b; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">Paused</span>`;
                    } else if (job.status === 'Completed') {
                        iconHtml = '<div class="map-marker-completed"></div>';
                        badge = `<span style="background:#3b82f6; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">Completed</span>`;
                    } else {
                        iconHtml = '<div class="map-marker-pending"></div>';
                        badge = `<span style="background:#94a3b8; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">${job.status}</span>`;
                    }

                    const cIcon = L.divIcon({ html: iconHtml, className: '', iconSize: [16,16], iconAnchor: [8,8] });
                    const clientText = job.client_name || job.project_name || 'Unknown';
                    
                    L.marker([job.location_lat, job.location_lng], { icon: cIcon }).addTo(map)
                     .bindPopup(`<div style="color:#000;"><b>${job.plant_name}</b><br>${clientText}<br><div style="margin-top:5px;">${badge}</div></div>`);
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        loadMapTelemetry(); 
        setInterval(loadMapTelemetry, 60000);

        const calendarEl = document.getElementById('director-calendar');
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'listWeek',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'listDay,listWeek,listMonth' },
            buttonText: { listDay: 'Day', listWeek: 'Week', listMonth: 'Month' },
            height: 600,
            events: 'api/plant_actions.php?action=fetch_bookings',
            
            eventClick: function(info) {
                fetch(`api/plant_actions.php?action=get_job&id=${info.event.id}`).then(r => r.json()).then(job => {
                    if (job.status === 'Completed' && parseFloat(job.final_subtotal) > 0) {
                        window.open(`print_plant_invoice.php?booking_id=${job.id}&readonly=1`, 'rfpPopup', 'width=1000,height=900,scrollbars=yes');
                    } else {
                        alert("Access Denied: RFP has not yet been approved or finalized for this job.");
                    }
                });
            },
            
            datesSet: function(info) {
                calStartCache = info.startStr.split('T')[0];
                calEndCache = info.endStr.split('T')[0];
                
                // If the map is in Period mode, refresh it to match the new dates
                if (document.getElementById('mapModeToggle').value === 'period') {
                    loadMapTelemetry();
                }

                document.getElementById('dynamic-subtitle').innerText = "Viewing Data for " + (info.view.type === 'listDay' ? "this Day" : (info.view.type === 'listMonth' ? "this Month" : "this Week"));
                const fd = new FormData(); fd.append('action', 'get_dashboard_stats'); fd.append('start', info.startStr); fd.append('end', info.endStr);
                
                fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
                    document.getElementById('kpi-completed-book').innerText = data.kpi.completed_bookings;
                    document.getElementById('kpi-completed-hrs').innerText = parseFloat(data.kpi.executed_hours).toFixed(1);
                    document.getElementById('kpi-rev-gen').innerText = '€' + parseFloat(data.kpi.revenue_generated).toLocaleString(undefined,{maximumFractionDigits:0});
                    
                    document.getElementById('kpi-planned-book').innerText = data.kpi.planned_bookings;
                    document.getElementById('kpi-planned-hrs').innerText = parseFloat(data.kpi.planned_hours).toFixed(1);
                    document.getElementById('kpi-rev-pipe').innerText = '€' + parseFloat(data.kpi.projected_revenue).toLocaleString(undefined,{maximumFractionDigits:0});
                    
                    document.getElementById('kpi-rfps').innerText = data.kpi.rfps_issued;
                    document.getElementById('kpi-erp').innerText = '€' + parseFloat(data.kpi.erp_invoiced).toLocaleString(undefined,{maximumFractionDigits:0});
                    document.getElementById('kpi-rev-total').innerText = '€' + parseFloat(data.kpi.total_est_revenue).toLocaleString(undefined,{maximumFractionDigits:0});
                    
                    currentDrillData = data.drilldown || {};

                    const tbody = document.getElementById('plant-breakdown-body');
                    if (data.plants.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px;">No jobs found for this period.</td></tr>';
                    } else {
                        let html = '';
                        let currentCat = '';
                        data.plants.forEach(p => {
                            if (p.category !== currentCat) {
                                html += `<tr><td colspan="4" style="background:rgba(0,0,0,0.2); font-weight:900; text-transform:uppercase; font-size:0.8rem; letter-spacing:1px; color:#94a3b8;">${p.category || 'General'}</td></tr>`;
                                currentCat = p.category;
                            }
                            let t_qty = p.c_qty + p.p_qty; let t_hrs = p.c_hrs + p.p_hrs; let t_rev = p.c_rev + p.p_rev;
                            
                            html += `<tr>
                                <td>${p.plant_name}</td>
                                <td style="color:#10b981;"><b>${p.c_qty}</b> / ${p.c_hrs.toFixed(1)} / €${p.c_rev.toLocaleString(undefined,{maximumFractionDigits:0})}</td>
                                <td style="color:#f59e0b;"><b>${p.p_qty}</b> / ${p.p_hrs.toFixed(1)} / €${p.p_rev.toLocaleString(undefined,{maximumFractionDigits:0})}</td>
                                <td style="color:#3b82f6;"><b>${t_qty}</b> / ${t_hrs.toFixed(1)} / €${t_rev.toLocaleString(undefined,{maximumFractionDigits:0})}</td>
                            </tr>`;
                        });
                        tbody.innerHTML = html;
                    }
                });
            }
        });
        calendar.render();
    });
</script>

<?php include 'footer.php'; ?>
