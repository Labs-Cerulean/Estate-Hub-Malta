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
    $mode = $_GET['mode'] ?? 'period'; 
    
    $query = "
        SELECT pb.id, pb.status, pb.location_lat, pb.location_lng, pb.client_name, pb.booking_date, pb.start_time, pb.end_time,
               p.name as plant_name, p.category, prj.name as project_name,
               u.first_name, u.last_name
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        LEFT JOIN projects prj ON pb.project_id = prj.id 
        LEFT JOIN users u ON pb.driver_id = u.id
        WHERE pb.location_lat IS NOT NULL AND pb.location_lat != ''
    ";
    
    if ($mode === 'period') {
        $start = $_GET['start'] ?? date('Y-m-d');
        $end = $_GET['end'] ?? date('Y-m-d');
        $stmt = $pdo->prepare($query . " AND pb.booking_date >= ? AND pb.booking_date <= ?");
        $stmt->execute([$start, $end]);
    } else {
        $stmt = $pdo->prepare($query . " AND pb.status IN ('In Progress', 'Paused')");
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

    /* Map Slider Toggle */
    .map-toggle-wrapper { display: flex; background: rgba(0,0,0,0.1); border-radius: 30px; position: relative; padding: 4px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); }
    .map-toggle-btn { flex: 1; text-align: center; padding: 6px 16px; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; color: #64748b; cursor: pointer; z-index: 2; transition: color 0.3s ease; position: relative; }
    .map-toggle-btn.active { color: #fff; }
    .map-toggle-slider { position: absolute; top: 4px; left: 4px; width: calc(50% - 4px); height: calc(100% - 8px); background: #3b82f6; border-radius: 30px; transition: transform 0.3s cubic-bezier(0.4, 0.0, 0.2, 1), background-color 0.3s; z-index: 1; }
    .map-toggle-wrapper[data-mode="live"] .map-toggle-slider { transform: translateX(100%); background: #10b981; }

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
    
    /* FULLCALENDAR - STRICT CUSTOM FIXES */
    .fc { font-size: 0.95rem; --fc-page-bg-color: transparent; --fc-neutral-bg-color: rgba(255, 255, 255, 0.05); --fc-list-event-hover-bg-color: transparent; --fc-border-color: rgba(128, 128, 128, 0.2); }
    .fc-theme-standard .fc-list { background: transparent !important; }
    
    /* Completely hide native time and dot cells to prevent overlap */
    .fc-list-event-time, .fc-list-event-graphic { display: none !important; }
    
    /* Expand the title cell to full width and add hover effect to the cell itself */
    .fc-list-event-title { padding: 12px 15px !important; vertical-align: middle !important; width: 100% !important; transition: background 0.2s; }
    .fc-list-event:hover .fc-list-event-title { background-color: rgba(255, 255, 255, 0.05) !important; cursor: pointer; }
</style>

<div class="cmd-center">
    <div class="header-bar">
        <div>
            <h1 class="page-title">Fleet Command Center</h1>
            <p class="page-subtitle" id="dynamic-subtitle">Analyzing Operations Data...</p>
        </div>
    </div>

    <div class="panel" style="margin-bottom: 30px;">
        <div class="panel-header">
            <div><i class="fas fa-satellite-dish" style="color:#3b82f6;"></i> Fleet Telemetry</div>
            <div class="map-toggle-wrapper" id="mapToggleWrapper" data-mode="period">
                <div class="map-toggle-slider"></div>
                <div class="map-toggle-btn active" id="btnPeriod" onclick="setMapMode('period')">Period</div>
                <div class="map-toggle-btn" id="btnLive" onclick="setMapMode('live')">Live</div>
            </div>
        </div>
        <div id="fleetMap" style="height: 350px; width: 100%; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; z-index: 1;"></div>
    </div>

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

<div id="jobModalOverlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div id="jobModal" style="width: 500px; max-width: 95%; background: #1e293b; color: #f8fafc; border-radius: 12px; padding: 25px; display: flex; flex-direction: column; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:15px; margin-bottom:15px;">
            <h3 id="jobModalTitle" style="margin:0; font-size:1.4rem;">Job Details</h3>
            <button onclick="document.getElementById('jobModalOverlay').style.display='none'" style="background:none; border:none; color:#94a3b8; font-size:1.5rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        
        <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); color: #f59e0b; padding: 12px 15px; border-radius: 8px; font-weight: 700; font-size: 0.9rem; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-exclamation-triangle" style="font-size: 1.2rem;"></i> 
            <div>This job has not been billed or finalized yet. Here is the scheduled information:</div>
        </div>
        
        <div id="jobModalBody" style="font-size: 0.95rem; line-height: 1.6; color: #cbd5e1;">
            </div>
        
        <div style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 15px; margin-top: 20px; text-align: right;">
            <button onclick="document.getElementById('jobModalOverlay').style.display='none'" style="padding: 10px 24px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;">Close Window</button>
        </div>
    </div>
</div>

<script>
    let currentDrillData = {};
    let calStartCache = '';
    let calEndCache = '';
    let activeMapMode = 'period'; 

    function setMapMode(mode) {
        activeMapMode = mode;
        const wrapper = document.getElementById('mapToggleWrapper');
        wrapper.setAttribute('data-mode', mode);
        
        if (mode === 'live') {
            document.getElementById('btnLive').classList.add('active');
            document.getElementById('btnPeriod').classList.remove('active');
        } else {
            document.getElementById('btnPeriod').classList.add('active');
            document.getElementById('btnLive').classList.remove('active');
        }
        loadMapTelemetry();
    }

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
        let url = 'plant_dashboard.php?action=map_data&mode=' + activeMapMode;
        if (activeMapMode === 'period' && calStartCache !== '') {
            url += '&start=' + calStartCache + '&end=' + calEndCache;
        }

        fetch(url).then(r => r.json()).then(jobs => {
            map.eachLayer(layer => { if (layer instanceof L.Marker) layer.remove(); });
            jobs.forEach(job => {
                if (job.location_lat) {
                    let iconHtml = ''; let badge = '';
                    
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
                    const clientText = job.client_name || job.project_name || 'Unknown Location';
                    
                    const driverName = (job.first_name || job.last_name) ? `${job.first_name || ''} ${job.last_name || ''}`.trim() : 'Unassigned';
                    const timeStr = (job.start_time && job.end_time) ? `${job.start_time.substring(0,5)} - ${job.end_time.substring(0,5)}` : 'TBC';
                    
                    const popupHtml = `
                        <div style="color:#0f172a; font-family:'Inter', sans-serif; min-width: 240px;">
                            <div style="font-weight:900; font-size:1.05rem; margin-bottom:2px;">${job.plant_name}</div>
                            <div style="font-size:0.8rem; color:#64748b; margin-bottom:10px; font-weight:600;">${clientText}</div>
                            <div style="font-size:0.85rem; margin-bottom:4px; border-top:1px solid #e2e8f0; padding-top:8px;">
                                <i class="fas fa-hard-hat" style="width:16px; color:#94a3b8;"></i> ${driverName}
                            </div>
                            <div style="font-size:0.85rem; margin-bottom:12px;">
                                <i class="far fa-clock" style="width:16px; color:#94a3b8;"></i> ${job.booking_date} (${timeStr})
                            </div>
                            <div>${badge}</div>
                        </div>
                    `;
                    
                    L.marker([job.location_lat, job.location_lng], { icon: cIcon }).addTo(map).bindPopup(popupHtml);
                }
            });
        });
    }

    

    document.addEventListener('DOMContentLoaded', function() {
        const calendarEl = document.getElementById('director-calendar');
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'listWeek',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'listDay,listWeek,listMonth' },
            buttonText: { listDay: 'Day', listWeek: 'Week', listMonth: 'Month' },
            height: 600,
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            displayEventEnd: true,
            events: 'api/plant_actions.php?action=fetch_bookings',
            
            // STRICT HTML INJECTION TO FIX ALIGNMENT OVERLAPS & TIMES
            eventContent: function(arg) {
                let title = arg.event.title;
                let lowerTitle = title.toLowerCase();
                let icon = 'fa-cogs'; 
                
                if (lowerTitle.includes('boom')) icon = 'fa-truck-pickup';
                else if (lowerTitle.includes('crane')) icon = 'fa-truck-loading';
                else if (lowerTitle.includes('drum cutter') || lowerTitle.includes('drumcutter')) icon = 'fa-cogs';
                else if (lowerTitle.includes('excavator') || lowerTitle.includes('kobelco') || lowerTitle.includes('kato') || lowerTitle.includes('jcb')) icon = 'fa-tractor';
                else if (lowerTitle.includes('truck') || lowerTitle.includes('trailer')) icon = 'fa-truck';
                else if (lowerTitle.includes('piling')) icon = 'fa-hammer';
                else if (lowerTitle.includes('pump') || lowerTitle.includes('concrete')) icon = 'fa-water';
                else if (lowerTitle.includes('rock saw') || lowerTitle.includes('rocksaw')) icon = 'fa-cog';
                else if (lowerTitle.includes('scarifier')) icon = 'fa-road';

                // 1. MANUALLY BUILD THE TIME STRING (Bypasses FullCalendar's empty string quirk)
                let timeStr = 'All Day';
                if (!arg.event.allDay && arg.event.start) {
                    let sH = String(arg.event.start.getHours()).padStart(2, '0');
                    let sM = String(arg.event.start.getMinutes()).padStart(2, '0');
                    timeStr = `${sH}:${sM}`;
                    
                    if (arg.event.end) {
                        let eH = String(arg.event.end.getHours()).padStart(2, '0');
                        let eM = String(arg.event.end.getMinutes()).padStart(2, '0');
                        timeStr += ` - ${eH}:${eM}`;
                    }
                }

                // 2. BUILD STATUS BADGE
                let statusIcon = '';
                let rawTitle = title;
                
                if (rawTitle.includes('🧾')) { 
                    statusIcon = '<span style="background:rgba(16,185,129,0.15); color:#10b981; padding:4px 8px; border-radius:4px; font-size:0.75rem; margin-right:15px; border:1px solid rgba(16,185,129,0.2); width:110px; display:inline-block; text-align:center;"><i class="fas fa-file-invoice"></i> RFP Finalized</span>'; 
                    rawTitle = rawTitle.replace('🧾 ', ''); 
                }
                else if (rawTitle.includes('✅')) { 
                    statusIcon = '<span style="background:rgba(59,130,246,0.15); color:#3b82f6; padding:4px 8px; border-radius:4px; font-size:0.75rem; margin-right:15px; border:1px solid rgba(59,130,246,0.2); width:110px; display:inline-block; text-align:center;"><i class="fas fa-check"></i> Completed</span>'; 
                    rawTitle = rawTitle.replace('✅ ', ''); 
                }
                else if (rawTitle.includes('⏳')) { 
                    statusIcon = '<span style="background:rgba(16,185,129,0.15); color:#10b981; padding:4px 8px; border-radius:4px; font-size:0.75rem; margin-right:15px; border:1px solid rgba(16,185,129,0.2); width:110px; display:inline-block; text-align:center;"><i class="fas fa-play"></i> Active</span>'; 
                    rawTitle = rawTitle.replace('⏳ ', ''); 
                }
                else if (rawTitle.includes('⏸️')) { 
                    statusIcon = '<span style="background:rgba(245,158,11,0.15); color:#f59e0b; padding:4px 8px; border-radius:4px; font-size:0.75rem; margin-right:15px; border:1px solid rgba(245,158,11,0.2); width:110px; display:inline-block; text-align:center;"><i class="fas fa-pause"></i> Paused</span>'; 
                    rawTitle = rawTitle.replace('⏸️ ', ''); 
                }
                else {
                    statusIcon = '<span style="background:rgba(148,163,184,0.15); color:#94a3b8; padding:4px 8px; border-radius:4px; font-size:0.75rem; margin-right:15px; border:1px solid rgba(148,163,184,0.2); width:110px; display:inline-block; text-align:center;"><i class="far fa-clock"></i> Pending</span>'; 
                }

                // 3. INJECT HTML
                return {
                    html: `<div style="display:flex; align-items:center; width:100%;">
                             <div style="width:120px; font-weight:800; color:#94a3b8; text-align:right; margin-right:15px; flex-shrink:0;">${timeStr}</div>
                             <div style="width:30px; text-align:center; color:#38bdf8; font-size:1.1rem; margin-right:5px; flex-shrink:0;"><i class="fas ${icon}"></i></div>
                             <div style="flex-shrink:0;">${statusIcon}</div>
                             <div style="font-weight:600; color:#e2e8f0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${rawTitle.trim()}</div>
                           </div>`
                };
            },
            
            eventClick: function(info) {
                fetch(`api/plant_actions.php?action=get_job&id=${info.event.id}`).then(r => r.json()).then(job => {
                    // If it has an RFP/Invoice, pop the PDF
                    if (job.status === 'Completed' && (parseFloat(job.final_subtotal) > 0 || ['Invoiced','Settled'].includes(job.payment_status))) {
                        window.open(`print_plant_invoice.php?booking_id=${job.id}&readonly=1`, 'rfpPopup', 'width=1000,height=900,scrollbars=yes');
                    } else {
                        // Otherwise, pop the informational unbilled modal!
                        document.getElementById('jobModalTitle').innerHTML = `<i class="fas fa-hard-hat" style="color:#38bdf8; margin-right:8px;"></i> ${job.plant_name || 'Plant Equipment'}`;
                        
                        let driverStr = job.driver_id ? `Assigned` : '<span style="color:#ef4444; font-weight:bold;">Unassigned</span>';
                        let timeStr = (job.start_time && job.end_time) ? `${job.start_time.substring(0,5)} - ${job.end_time.substring(0,5)}` : 'TBC';
                        let statusColor = job.status === 'Completed' ? '#10b981' : (job.status === 'In Progress' ? '#3b82f6' : (job.status === 'Paused' ? '#f59e0b' : '#94a3b8'));

                        document.getElementById('jobModalBody').innerHTML = `
                            <div style="margin-bottom:10px;"><b style="color:#fff;">Date:</b> ${job.booking_date} (${timeStr})</div>
                            <div style="margin-bottom:10px;"><b style="color:#fff;">Status:</b> <span style="color:${statusColor}; font-weight:bold;">${job.status}</span></div>
                            <div style="margin-bottom:10px;"><b style="color:#fff;">Driver:</b> ${driverStr}</div>
                            <div style="margin-bottom:10px;"><b style="color:#fff;">Location:</b> ${job.location_text || 'TBC'}</div>
                            ${job.comments ? `<div style="margin-top: 15px; padding: 12px; background: rgba(255,255,255,0.05); border-left: 3px solid #3b82f6; border-radius: 4px; color:#cbd5e1;"><b>Notes:</b><br>${job.comments}</div>` : ''}
                        `;
                        
                        document.getElementById('jobModalOverlay').style.display = 'flex';
                    }
                });
            },
            
            datesSet: function(info) {
                // Timezone safe calculator to stop the Map from leaking into "tomorrow"
                let startDate = new Date(info.start);
                let endDate = new Date(info.end);
                endDate.setDate(endDate.getDate() - 1); // Subtract 1 day due to FC's exclusive end dates
                
                calStartCache = `${startDate.getFullYear()}-${String(startDate.getMonth() + 1).padStart(2, '0')}-${String(startDate.getDate()).padStart(2, '0')}`;
                calEndCache = `${endDate.getFullYear()}-${String(endDate.getMonth() + 1).padStart(2, '0')}-${String(endDate.getDate()).padStart(2, '0')}`;
                
                if (activeMapMode === 'period') { loadMapTelemetry(); }

                document.getElementById('dynamic-subtitle').innerText = "Viewing Data for " + (info.view.type === 'listDay' ? "this Day" : (info.view.type === 'listMonth' ? "this Month" : "this Week"));
                const fd = new FormData(); fd.append('action', 'get_dashboard_stats'); fd.append('start', calStartCache); fd.append('end', calEndCache);
                
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
                            let cat = p.category || 'General';
                            
                            if (cat !== currentCat) {
                                let icon = 'fa-cogs'; 
                                let lowerCat = cat.toLowerCase();
                                
                                if (lowerCat.includes('booms')) icon = 'fa-truck-pickup';
                                else if (lowerCat.includes('cranes')) icon = 'fa-truck-loading';
                                else if (lowerCat.includes('drum cutter')) icon = 'fa-cogs';
                                else if (lowerCat.includes('excavator')) icon = 'fa-tractor';
                                else if (lowerCat.includes('other trucks')) icon = 'fa-truck';
                                else if (lowerCat.includes('piling')) icon = 'fa-hammer';
                                else if (lowerCat.includes('pumps')) icon = 'fa-water';
                                else if (lowerCat.includes('rock saw')) icon = 'fa-cog';
                                else if (lowerCat.includes('scarifier')) icon = 'fa-road';

                                html += `<tr><td colspan="4" style="background:rgba(0,0,0,0.2); font-weight:900; text-transform:uppercase; font-size:0.85rem; letter-spacing:1px; color:#94a3b8;"><i class="fas ${icon}" style="margin-right: 8px; color: #38bdf8;"></i>${cat}</td></tr>`;
                                currentCat = cat;
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
        
        loadMapTelemetry(); 
        setInterval(loadMapTelemetry, 60000);
    });

    
</script>

<?php include 'footer.php'; ?>
