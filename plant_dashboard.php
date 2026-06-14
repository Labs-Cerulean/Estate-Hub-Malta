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

// MICRO-API: Feed Live GPS Data to the Map
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

include 'header.php'; 
?>

<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    /* Theme-Agnostic Structure (Reverted to native Estate Hub styling) */
    .cmd-center { max-width: 1600px; margin: 0 auto; padding: 30px 20px; width: 100%; box-sizing: border-box; }
    
    .header-bar { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
    .page-title { font-size: 2.2rem; font-weight: 900; margin: 0 0 5px 0; opacity: 0.9; }
    .page-subtitle { font-size: 1.1rem; margin: 0; opacity: 0.7; font-weight: 500; }
    .action-btn { padding: 10px 20px; background: #3b82f6; color: white; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; border: none; cursor: pointer; }
    .action-btn:hover { background: #2563eb; }

    /* KPI Cards using native translucent theme */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .kpi-card { background: rgba(128, 128, 128, 0.05); padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.2); border-bottom: 4px solid transparent; }
    .kpi-title { font-size: 0.85rem; text-transform: uppercase; font-weight: 700; opacity: 0.7; letter-spacing: 0.5px; margin-bottom: 8px; }
    .kpi-value { font-size: 2.2rem; font-weight: 900; opacity: 0.9; line-height: 1.2; }

    /* Dashboard Layout */
    .dash-layout { display: grid; grid-template-columns: 2fr 1.5fr; gap: 25px; }
    @media (max-width: 1100px) { .dash-layout { grid-template-columns: 1fr; } }

    /* UI Panels using native translucent theme */
    .panel { background: rgba(128, 128, 128, 0.05); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.2); display: flex; flex-direction: column; overflow: hidden; }
    .panel-header { padding: 15px 20px; border-bottom: 2px solid rgba(128, 128, 128, 0.2); font-size: 1.2rem; font-weight: 800; opacity: 0.9; display: flex; align-items: center; }
    .panel-body { padding: 20px; flex: 1; }

    /* Map Markers */
    .map-marker-pulse { width: 16px; height: 16px; background: #10b981; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); animation: pulse 1.5s infinite; }
    .map-marker-paused { width: 16px; height: 16px; background: #f59e0b; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
    @keyframes pulse { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }

    /* FullCalendar Native Polish */
    .fc { font-size: 0.95rem; --fc-page-bg-color: transparent; --fc-neutral-bg-color: rgba(255, 255, 255, 0.05); --fc-list-event-hover-bg-color: rgba(255, 255, 255, 0.1); --fc-border-color: rgba(128, 128, 128, 0.2); }
    .fc .fc-toolbar-title { font-weight: 800 !important; font-size: 1.5rem !important; opacity: 0.9; }
    .fc .fc-list-day-cushion { background-color: var(--fc-neutral-bg-color) !important; color: inherit !important; opacity: 0.9; font-weight: bold; }
    .fc-theme-standard .fc-list { background: transparent !important; border: 1px solid var(--fc-border-color); }
    .fc-event { cursor: pointer; }
</style>

<div class="cmd-center">
    <div class="header-bar">
        <div>
            <h1 class="page-title">Fleet Command Center</h1>
            <p class="page-subtitle" id="dynamic-subtitle">Analyzing Operations Data...</p>
        </div>
        <a href="plant_bookings.php" target="_blank" class="action-btn">
            <i class="fas fa-external-link-alt"></i> Open Operations Hub
        </a>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card" style="border-bottom-color: #3b82f6;">
            <div class="kpi-title">Completed Bookings</div>
            <div class="kpi-value" id="kpi-completed">0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color: #f59e0b;">
            <div class="kpi-title">Total Hours Executed</div>
            <div class="kpi-value" id="kpi-hours">0.0</div>
        </div>
        <div class="kpi-card" style="border-bottom-color: #10b981;">
            <div class="kpi-title">Revenue Generated</div>
            <div class="kpi-value" id="kpi-revenue" style="color: #10b981;">€0.00</div>
        </div>
        <div class="kpi-card" style="border-bottom-color: #8b5cf6;">
            <div class="kpi-title">ERP Invoiced (Live SysRef)</div>
            <div class="kpi-value" id="kpi-invoiced" style="color: #8b5cf6;">€0.00</div>
        </div>
    </div>

    <div class="dash-layout">
        
        <div style="display: flex; flex-direction: column; gap: 25px;">
            <div class="panel">
                <div class="panel-header">
                    <div><i class="fas fa-satellite-dish" style="color: #3b82f6; margin-right: 8px;"></i> Live Fleet Telemetry</div>
                </div>
                <div id="fleetMap" style="height: 350px; width: 100%; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; z-index: 1;"></div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div><i class="fas fa-calendar-alt" style="color: #8b5cf6; margin-right: 8px;"></i> Master Agenda</div>
                </div>
                <div class="panel-body">
                    <div id="director-calendar"></div>
                </div>
            </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 25px;">
            <div class="panel" style="flex: 1;">
                <div class="panel-header">
                    <div><i class="fas fa-chart-bar" style="color: #10b981; margin-right: 8px;"></i> Plant Performance Breakdown</div>
                </div>
                <div class="panel-body" style="overflow-y: auto;">
                    <div id="plant-breakdown-container">
                        <p style="opacity: 0.7; font-size: 0.9rem; text-align: center;"><i class="fas fa-spinner fa-spin"></i> Loading metrics...</p>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        
        // --- 1. INITIALIZE LIVE MAP ---
        const map = L.map('fleetMap').setView([35.917973, 14.409943], 11);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap &copy; CARTO',
            subdomains: 'abcd',
            maxZoom: 19
        }).addTo(map);

        function loadMapTelemetry() {
            fetch('plant_dashboard.php?action=map_data')
            .then(r => r.json())
            .then(jobs => {
                map.eachLayer((layer) => {
                    if (layer instanceof L.Marker) { layer.remove(); }
                });

                jobs.forEach(job => {
                    if (job.location_lat && job.location_lng) {
                        const isWorking = job.status === 'In Progress';
                        const iconHtml = isWorking ? '<div class="map-marker-pulse"></div>' : '<div class="map-marker-paused"></div>';
                        const customIcon = L.divIcon({ html: iconHtml, className: '', iconSize: [16, 16], iconAnchor: [8, 8] });
                        
                        const clientText = job.client_name ? job.client_name : (job.project_name || 'Unknown Location');
                        const popupHtml = `
                            <div style="font-family:'Inter', sans-serif; color: #000; min-width: 180px;">
                                <div style="font-weight:bold; font-size:1rem; margin-bottom:5px;">${job.plant_name}</div>
                                <div style="font-size:0.85rem; color:#475569; margin-bottom:8px;">${clientText}</div>
                                ${isWorking ? `<span style="background:#10b981; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">Active</span>` : `<span style="background:#f59e0b; color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">Paused</span>`}
                            </div>
                        `;

                        L.marker([job.location_lat, job.location_lng], { icon: customIcon }).addTo(map).bindPopup(popupHtml);
                    }
                });
            });
        }
        
        loadMapTelemetry();
        setInterval(loadMapTelemetry, 60000);

        // --- 2. INITIALIZE CALENDAR & KPIs ---
        const calendarEl = document.getElementById('director-calendar');
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'listWeek',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'listDay,listWeek,listMonth,listYear'
            },
            buttonText: {
                listDay: 'Day',
                listWeek: 'Week',
                listMonth: 'Month',
                listYear: 'Year'
            },
            height: 600,
            events: 'api/plant_actions.php?action=fetch_bookings',
            
            datesSet: function(info) {
                // Determine text based on view type
                let viewText = "this Week";
                if (info.view.type === 'listDay') viewText = "this Day";
                else if (info.view.type === 'listMonth') viewText = "this Month";
                else if (info.view.type === 'listYear') viewText = "this Year";
                
                document.getElementById('dynamic-subtitle').innerText = "Viewing Data for " + viewText;
                
                const fd = new FormData();
                fd.append('action', 'get_dashboard_stats');
                fd.append('start', info.startStr);
                fd.append('end', info.endStr);
                
                fetch('api/plant_actions.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    // Update Top KPIs
                    document.getElementById('kpi-completed').innerText = data.kpi.completed_jobs || 0;
                    document.getElementById('kpi-hours').innerText = parseFloat(data.kpi.total_hours || 0).toFixed(1);
                    document.getElementById('kpi-revenue').innerText = '€' + parseFloat(data.kpi.revenue_generated || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
                    document.getElementById('kpi-invoiced').innerText = '€' + parseFloat(data.kpi.invoiced_revenue || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
                    
                    // Update Plant Performance Breakdown
                    const plantCont = document.getElementById('plant-breakdown-container');
                    if (data.plants.length === 0) {
                        plantCont.innerHTML = '<p style="opacity: 0.7; font-size: 0.9rem; text-align: center; margin-top: 20px;">No completed jobs found for this period.</p>';
                    } else {
                        let pHtml = '';
                        let currentCat = '';
                        
                        data.plants.forEach(p => {
                            let cat = p.category || 'General';
                            
                            // Create a new category header when the category changes
                            if (cat !== currentCat) {
                                if (currentCat !== '') pHtml += '</div>'; // Close previous block
                                pHtml += `
                                <div style="margin-bottom: 25px;">
                                    <h4 style="margin-top:0; margin-bottom:12px; border-bottom:1px solid rgba(128,128,128,0.2); padding-bottom:6px; text-transform:uppercase; font-size:0.85rem; font-weight:800; opacity:0.7; letter-spacing:1px;">
                                        ${cat}
                                    </h4>`;
                                currentCat = cat;
                            }
                            
                            // Plant Row
                            pHtml += `
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; font-size:0.95rem; background:rgba(128,128,128,0.05); padding:10px 15px; border-radius:8px;">
                                    <div style="font-weight:700; opacity:0.9;">${p.plant_name}</div>
                                    <div style="font-size:0.85rem; opacity:0.8;">
                                        Bookings: <b style="color:#3b82f6;">${p.booking_count}</b> &nbsp;|&nbsp; 
                                        Hours: <b style="color:#f59e0b;">${parseFloat(p.total_hours).toFixed(1)}</b> &nbsp;|&nbsp; 
                                        Rev: <b style="color:#10b981;">€${parseFloat(p.total_revenue).toLocaleString(undefined, {minimumFractionDigits: 2})}</b>
                                    </div>
                                </div>`;
                        });
                        if (currentCat !== '') pHtml += '</div>'; // Close final block
                        
                        plantCont.innerHTML = pHtml;
                    }
                });
            }
        });
        
        calendar.render();
    });
</script>

<?php include 'footer.php'; ?>
