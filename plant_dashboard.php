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
    
    /* GLOBAL DATE CONTROLS AT TOP */
    .global-date-bar { display: flex; justify-content: space-between; align-items: center; background: #1e293b; padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.05); flex-wrap: wrap; gap: 15px; }
    .date-nav-btns, .view-toggles { display: flex; }
    .date-nav-btns button, .view-toggles button { background: rgba(255,255,255,0.05); border: none; color: #cbd5e1; padding: 10px 18px; font-weight: 700; cursor: pointer; transition: 0.2s; font-size: 0.9rem; }
    .date-nav-btns button:hover, .view-toggles button:hover { background: rgba(255,255,255,0.15); color: #fff; }
    .date-nav-btns button:first-child { border-radius: 8px 0 0 8px; border-right: 1px solid rgba(0,0,0,0.2); }
    .date-nav-btns button:nth-child(2) { border-radius: 0 8px 8px 0; }
    .date-nav-btns button:last-child { border-radius: 8px; margin-left: 10px; background: rgba(255,255,255,0.1); }
    .view-toggles button { border-right: 1px solid rgba(0,0,0,0.2); }
    .view-toggles button:first-child { border-radius: 8px 0 0 8px; }
    .view-toggles button:last-child { border-radius: 0 8px 8px 0; border-right: none; }
    .view-toggles button.active { background: #3b82f6; color: #fff; box-shadow: inset 0 2px 4px rgba(0,0,0,0.2); }
    .global-date-title { color: #fff; font-size: 1.6rem; font-weight: 900; margin: 0; text-align: center; flex: 1; }

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
    .map-toggle-wrapper { display: flex; background: rgba(0,0,0,0.3); border-radius: 30px; position: relative; padding: 4px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.2); }
    .map-toggle-btn { flex: 1; text-align: center; padding: 6px 16px; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; cursor: pointer; z-index: 2; transition: color 0.3s ease; position: relative; }
    .map-toggle-btn.active { color: #fff; }
    .map-toggle-slider { position: absolute; top: 4px; left: 4px; width: calc(50% - 4px); height: calc(100% - 8px); background: #3b82f6; border-radius: 30px; transition: transform 0.3s cubic-bezier(0.4, 0.0, 0.2, 1), background-color 0.3s; z-index: 1; }
    .map-toggle-wrapper[data-mode="live"] .map-toggle-slider { transform: translateX(100%); background: #10b981; }

    /* Map Markers */
    .custom-leaflet-icon { background: none !important; border: none !important; }
    .marker-active { background: #10b981; animation: pulse 1.5s infinite; }
    .marker-paused { background: #f59e0b; }
    .marker-completed { background: #3b82f6; }
    .marker-pending { background: #94a3b8; }
    @keyframes pulse { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); } 70% { transform: scale(1.1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }

    .breakdown-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .breakdown-table th { background: rgba(0,0,0,0.1); padding: 10px; font-weight: 800; text-transform: uppercase; border: 1px solid rgba(128,128,128,0.2); opacity: 0.8; }
    .breakdown-table td { padding: 10px; border: 1px solid rgba(128,128,128,0.1); opacity: 0.9; text-align: center; }
    .breakdown-table td:first-child { text-align: left; font-weight: 700; }
    
    /* Drilldown Modal */
    #drillModalOverlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    #drillModal { width: 700px; max-width: 95%; max-height: 85vh; background: #1e293b; color: #f8fafc; border-radius: 12px; padding: 25px; display: flex; flex-direction: column; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
    
    /* FULLCALENDAR FIXES */
    .fc { font-size: 0.95rem; --fc-page-bg-color: transparent; --fc-neutral-bg-color: rgba(255, 255, 255, 0.05); --fc-list-event-hover-bg-color: transparent; --fc-border-color: rgba(128, 128, 128, 0.2); }
    .fc-theme-standard .fc-list { background: transparent !important; }
    .fc-list-event-time, .fc-list-event-graphic { display: none !important; }
    .fc-list-event-title { padding: 12px 15px !important; vertical-align: middle !important; width: 100% !important; transition: background 0.2s; }
    .fc-list-event:hover .fc-list-event-title { background-color: rgba(255, 255, 255, 0.05) !important; cursor: pointer; }
</style>

<div class="cmd-center">
    
    <div class="global-date-bar">
        <div class="date-nav-btns">
            <button onclick="calAction('prev')"><i class="fas fa-chevron-left"></i></button>
            <button onclick="calAction('next')"><i class="fas fa-chevron-right"></i></button>
            <button onclick="calAction('today')">Today</button>
        </div>
        
        <h2 class="global-date-title" id="global-date-display">Loading Data...</h2>
        
        <div class="view-toggles">
            <button onclick="calAction('listDay')" id="btn-listDay">Day</button>
            <button onclick="calAction('listWeek')" id="btn-listWeek" class="active">Week</button>
            <button onclick="calAction('listMonth')" id="btn-listMonth">Month</button>
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
            <div>This job has not been billed or finalized yet.</div>
        </div>
        <div id="jobModalBody" style="font-size: 0.95rem; line-height: 1.6; color: #cbd5e1;"></div>
        <div style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 15px; margin-top: 20px; text-align: right;">
            <button onclick="document.getElementById('jobModalOverlay').style.display='none'" style="padding: 10px 24px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;">Close Window</button>
        </div>
    </div>
</div>

<script>
    let calendar;
    let currentDrillData = {};
    let calStartCache = '';
    let calEndCache = '';
    let activeMapMode = 'period'; 

    // Global Date Controls API
    function calAction(action) {
        if (!calendar) return;
        if (['prev', 'next', 'today'].includes(action)) {
            calendar[action]();
        } else {
            calendar.changeView(action);
            document.querySelectorAll('.view-toggles button').forEach(b => b.classList.remove('active'));
            document.getElementById('btn-' + action).classList.add('active');
        }
    }

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

        // 100% Bulletproof Native SVGs (Bypasses FontAwesome entirely)
        const svgs = {
            tractor: '<svg viewBox="0 0 576 512" width="14" height="14" fill="#fff"><path d="M0 112c0-26.5 21.5-48 48-48h96c26.5 0 48 21.5 48 48V208h48v32c0 26.5 21.5 48 48 48h72.5c16.7-22.1 43.1-36.8 73.2-38.7c-5.3-25.4-27.7-44.5-54.8-44.5H352V176h42.1c11.4 0 22.4-4.5 30.5-12.5L462.1 126c27-27 63.6-42.1 101.8-42.1c6.7 0 12.1 5.4 12.1 12.1v48.2c0 4.1-2.1 7.9-5.5 10.2c-15.6 10.6-30.1 22.9-43.2 36.8c18.5 2.5 35.7 9.8 50.7 20.8c5.4 4 13.1 2.3 16.7-3.6c5.7-9.3 9.3-20.2 9.3-31.9v-48.2c0-33.1-26.9-60-60-60C505.7 68 469 83.1 442 110L399.5 152.5c-.8 .8-1.9 1.2-3 1.2H352V112c0-26.5-21.5-48-48-48H208V48c0-26.5-21.5-48-48-48H48C21.5 0 0 21.5 0 48v64H0zm0 128v64c0 17.7 14.3 32 32 32h11.4c-7.3 14.2-11.4 30.1-11.4 46.8C32 460.6 96.6 524.8 174.8 524.8s142.8-64.2 142.8-142.8c0-16.7-4.1-32.6-11.4-46.8H344c26.5 0 48-21.5 48-48V240H0zm174.8 198.8c-30.8 0-55.8-25-55.8-55.8s25-55.8 55.8-55.8s55.8 25 55.8 55.8s-25 55.8-55.8 55.8zm233.5-98.8c-44.2 0-80 35.8-80 80s35.8 80 80 80s80-35.8 80-80s-35.8-80-80-80z"/></svg>',
            truck: '<svg viewBox="0 0 640 512" width="14" height="14" fill="#fff"><path d="M48 0C21.5 0 0 21.5 0 48V368c0 26.5 21.5 48 48 48H64c0 53 43 96 96 96s96-43 96-96H384c0 53 43 96 96 96s96-43 96-96h32c17.7 0 32-14.3 32-32s-14.3-32-32-32V288 256 237.3c0-17-6.7-33.3-18.7-45.3L512 114.7c-12-12-28.3-18.7-45.3-18.7H416V48c0-26.5-21.5-48-48-48H48zM416 160h50.7L544 237.3V256H416V160zM112 416a32 32 0 1 1 64 0 32 32 0 1 1 -64 0zm368-32a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>',
            crane: '<svg viewBox="0 0 640 512" width="14" height="14" fill="#fff"><path d="M256 0c-17.7 0-32 14.3-32 32V64H112c-26.5 0-48 21.5-48 48v80c0 26.5 21.5 48 48 48H224v16H112C50.1 256 0 306.1 0 368s50.1 112 112 112H544c35.3 0 64-28.7 64-64v-8.4c0-3.1-.2-6.1-.6-9.1C589.6 373.1 568 352 544 352H416c-35.3 0-64-28.7-64-64V128H288V32c0-17.7-14.3-32-32-32zM112 416c-26.5 0-48-21.5-48-48s21.5-48 48-48s48 21.5 48 48s-21.5 48-48 48z"/></svg>',
            cog: '<svg viewBox="0 0 512 512" width="14" height="14" fill="#fff"><path d="M495.9 166.6c3.2 8.7 .5 18.4-6.4 24.6l-43.3 39.4c1.1 8.3 1.7 16.8 1.7 25.4s-.6 17.1-1.7 25.4l43.3 39.4c6.9 6.2 9.6 15.9 6.4 24.6c-4.4 11.9-9.7 23.3-15.8 34.3l-4.7 8.1c-6.6 11-14 21.4-22.1 31.2c-5.9 7.2-15.7 9.6-24.5 6.8l-55.7-17.7c-13.4 10.3-28.2 18.9-44 25.4l-12.5 57.1c-2 9.1-9 16.3-18.2 17.8c-13.8 2.3-28 3.5-42.5 3.5s-28.7-1.2-42.5-3.5c-9.2-1.5-16.2-8.7-18.2-17.8l-12.5-57.1c-15.8-6.5-30.6-15.1-44-25.4L83.1 425.9c-8.8 2.8-18.6 .3-24.5-6.8c-8.1-9.8-15.5-20.2-22.1-31.2l-4.7-8.1c-6.1-11-11.4-22.4-15.8-34.3c-3.2-8.7-.5-18.4 6.4-24.6l43.3-39.4C64.6 273.1 64 264.6 64 256s.6-17.1 1.7-25.4L22.4 191.2c-6.9-6.2-9.6-15.9-6.4-24.6c4.4-11.9 9.7-23.3 15.8-34.3l4.7-8.1c6.6-11 14-21.4 22.1-31.2c5.9-7.2 15.7-9.6 24.5-6.8l55.7 17.7c13.4-10.3 28.2-18.9 44-25.4l12.5-57.1c2-9.1 9-16.3 18.2-17.8C227.3 1.2 241.5 0 256 0s28.7 1.2 42.5 3.5c9.2 1.5 16.2 8.7 18.2 17.8l12.5 57.1c15.8 6.5 30.6 15.1 44 25.4l55.7-17.7c8.8-2.8 18.6-.3 24.5 6.8c8.1 9.8 15.5 20.2 22.1 31.2l4.7 8.1c6.1 11 11.4 22.4 15.8 34.3zM256 336a80 80 0 1 0 0-160 80 80 0 1 0 0 160z"/></svg>',
            hammer: '<svg viewBox="0 0 512 512" width="14" height="14" fill="#fff"><path d="M451.7 89.2c-15-15-39.3-15-54.3 0L370.8 116 357.6 102.8c-26-26-64.8-31-95.8-13.6L167.3 183.7c-8 4.5-12.9 12.9-12.9 22.1s4.9 17.6 12.9 22.1l43 24.2L28.1 434.3c-12.5 12.5-12.5 32.8 0 45.3l4.3 4.3c12.5 12.5 32.8 12.5 45.3 0L260.1 301.6l24.2 43c4.5 8 12.9 12.9 22.1 12.9s17.6-4.9 22.1-12.9l94.5-167.3c17.4-31 12.4-69.8-13.6-95.8L396 68.4l26.8-26.8c15-15 15-39.3 0-54.3L451.7 89.2z"/></svg>',
            water: '<svg viewBox="0 0 512 512" width="14" height="14" fill="#fff"><path d="M256 320c-35.3 0-64-28.7-64-64s28.7-64 64-64s64 28.7 64 64s-28.7 64-64 64zm-147.2 64c24.9 0 46.6-14.7 56.9-35.8c11 1.7 22.4 2.6 34.3 2.6s23.3-.9 34.3-2.6c10.3 21 32 35.8 56.9 35.8c30.2 0 55.8-20.4 62.4-48.4c7.6-32.3-17.7-63.6-50.4-63.6H176c-32.7 0-58 31.3-50.4 63.6c6.6 28 32.2 48.4 62.4 48.4z"/></svg>',
            road: '<svg viewBox="0 0 512 512" width="14" height="14" fill="#fff"><path d="M32 32C14.3 32 0 46.3 0 64S14.3 96 32 96H173.3l52.4 212.6c-4.4 7-6.9 15.3-6.9 24.1c0 24.5 18.5 44.6 42.4 47.6L252.7 493c-2.4 10.3 4 20.6 14.3 23s20.6-4 23-14.3L298.5 384h43.1l8.5 116.7c.8 10.6 10 18.5 20.6 17.7s18.5-10 17.7-20.6L378.8 384h35.8c26.5 0 48-21.5 48-48V64c0-17.7-14.3-32-32-32H32zM320 288H192L160 160H320l0 128zM416 160h48v64H416V160zm0 128h48v64H416V288z"/></svg>'
        };

        fetch(url).then(r => r.json()).then(jobs => {
            map.eachLayer(layer => { if (layer instanceof L.Marker) layer.remove(); });
            jobs.forEach(job => {
                if (job.location_lat) {
                    let svgDraw = svgs.cog; 
                    let cat = (job.category || job.plant_name || '').toLowerCase();
                    
                    if (cat.includes('booms') || cat.includes('truck') || cat.includes('other trucks')) svgDraw = svgs.truck;
                    else if (cat.includes('cranes')) svgDraw = svgs.crane;
                    else if (cat.includes('excavator') || cat.includes('kobelco') || cat.includes('kato') || cat.includes('jcb')) svgDraw = svgs.tractor;
                    else if (cat.includes('piling')) svgDraw = svgs.hammer;
                    else if (cat.includes('pumps') || cat.includes('concrete')) svgDraw = svgs.water;
                    else if (cat.includes('scarifier')) svgDraw = svgs.road;

                    let bgColor = '#94a3b8'; 
                    let badge = `<span style="background:#94a3b8; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">${job.status}</span>`;
                    let markerClass = 'marker-pending';
                    
                    if (job.status === 'In Progress') {
                        bgColor = '#10b981'; markerClass = 'marker-active';
                        badge = `<span style="background:#10b981; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">Active</span>`;
                    } else if (job.status === 'Paused') {
                        bgColor = '#f59e0b'; markerClass = 'marker-paused';
                        badge = `<span style="background:#f59e0b; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">Paused</span>`;
                    } else if (job.status === 'Completed') {
                        bgColor = '#3b82f6'; markerClass = 'marker-completed';
                        badge = `<span style="background:#3b82f6; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">Completed</span>`;
                    }

                    let iconHtml = `
                        <div class="${markerClass}" style="background:${bgColor}; width:28px; height:28px; border-radius:50%; border:2px solid #fff; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 5px rgba(0,0,0,0.4);">
                            ${svgDraw}
                        </div>
                    `;
                    
                    const cIcon = L.divIcon({ html: iconHtml, className: 'custom-leaflet-icon', iconSize: [28,28], iconAnchor: [14,14] });
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
        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'listWeek',
            headerToolbar: false, 
            height: 600,
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            displayEventEnd: true,
            events: 'api/plant_actions.php?action=fetch_bookings',
            
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
                    if (job.status === 'Completed' && (parseFloat(job.final_subtotal) > 0 || ['Invoiced','Settled'].includes(job.payment_status))) {
                        window.open(`print_plant_invoice.php?booking_id=${job.id}&readonly=1`, 'rfpPopup', 'width=1000,height=900,scrollbars=yes');
                    } else {
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
                document.getElementById('global-date-display').innerText = info.view.title;
                
                let startDate = new Date(info.start);
                let endDate = new Date(info.end);
                endDate.setDate(endDate.getDate() - 1); 
                
                calStartCache = `${startDate.getFullYear()}-${String(startDate.getMonth() + 1).padStart(2, '0')}-${String(startDate.getDate()).padStart(2, '0')}`;
                calEndCache = `${endDate.getFullYear()}-${String(endDate.getMonth() + 1).padStart(2, '0')}-${String(endDate.getDate()).padStart(2, '0')}`;
                
                if (activeMapMode === 'period') { loadMapTelemetry(); }

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
                        let html = ''; let currentCat = '';
                        data.plants.forEach(p => {
                            let cat = p.category || 'General';
                            if (cat !== currentCat) {
                                let icon = 'fa-cogs'; let lowerCat = cat.toLowerCase();
                                if (lowerCat.includes('booms')) icon = 'fa-truck-pickup';
                                else if (lowerCat.includes('cranes')) icon = 'fa-truck-loading';
                                else if (lowerCat.includes('drum cutter')) icon = 'fa-cogs';
                                else if (lowerCat.includes('excavator') || lowerCat.includes('kobelco') || lowerCat.includes('kato') || lowerCat.includes('jcb')) icon = 'fa-tractor';
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
