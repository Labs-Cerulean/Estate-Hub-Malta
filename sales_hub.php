<?php
require_once 'config.php';
require_once 'session-check.php';

$allowed_roles = ['sales_manager', 'sales_agent', 'admin', 'director', 'system_manager'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// ==========================================
// AUTO-DEPLOY DATABASE UPDATES
// ==========================================
try {
    $pdo->exec("ALTER TABLE project_units ADD COLUMN resale_price DECIMAL(10,2) DEFAULT NULL");
} catch(PDOException $e) {}

require_once 'header.php';
?>

<script src='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js'></script>
<link href='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css' rel='stylesheet' />

<style>
    /* ==========================================
       SCOPED SALES HUB CSS
       ========================================== */
    :root {
        --sh-bg-base: #0f172a;
        --sh-bg-panel: #1e293b;
        --sh-bg-glass: rgba(30, 41, 59, 0.85);
        --sh-border: #334155;
        --sh-border-light: rgba(255,255,255,0.1);
        
        --sh-text-main: #f8fafc;
        --sh-text-muted: #94a3b8;
        
        --sh-avail: #10b981;
        --sh-proc: #f59e0b;
        --sh-sold: #3b82f6;
        --sh-resale: #a855f7;
        --sh-hold: #64748b;
        --sh-danger: #ef4444;
    }

    footer, .footer, #footer { display: none !important; }
    .container-fluid.main-content, .main-panel { padding: 0 !important; margin: 0 !important; background: var(--sh-bg-base) !important; }
    
    #sh-wrapper { position: relative; height: calc(100vh - 70px); width: 100%; overflow: hidden; font-family: 'Inter', sans-serif; color: var(--sh-text-main); }
    #sales-map { position: absolute; top: 0; bottom: 0; width: 100%; left: 0; }
    
    /* Overlay Controls */
    .sh-overlay {
        position: absolute; top: 20px; left: 20px; z-index: 10; width: 300px;
        background: var(--sh-bg-glass); backdrop-filter: blur(12px);
        border: 1px solid var(--sh-border-light); border-radius: 16px;
        padding: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    }
    .sh-overlay-title { font-size: 1.1rem; font-weight: 800; color: #fff; margin: 0 0 15px 0; display: flex; align-items: center; gap: 8px; }
    .sh-label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--sh-text-muted); text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px; }
    .sh-select { width: 100%; background: var(--sh-bg-base); color: #fff; border: 1px solid var(--sh-border); padding: 10px 12px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 15px; outline: none; cursor: pointer; }
    
    .sh-btn { width: 100%; padding: 10px; border-radius: 8px; border: none; font-weight: 700; font-size: 0.85rem; cursor: pointer; display: flex; justify-content: center; align-items: center; gap: 8px; transition: 0.2s; margin-bottom: 10px; }
    .sh-btn-warning { background: rgba(245, 158, 11, 0.1); color: var(--sh-proc); border: 1px solid rgba(245, 158, 11, 0.3); }
    .sh-btn-warning:hover { background: var(--sh-proc); color: #fff; }
    .sh-btn-warning.active { background: var(--sh-proc); color: #fff; }
    .sh-btn-info { background: rgba(59, 130, 246, 0.1); color: var(--sh-sold); border: 1px solid rgba(59, 130, 246, 0.3); }
    .sh-btn-info:hover { background: var(--sh-sold); color: #fff; }
    .sh-btn-success { background: rgba(16, 185, 129, 0.1); color: var(--sh-avail); border: 1px solid rgba(16, 185, 129, 0.3); }
    .sh-btn-success:hover { background: var(--sh-avail); color: #fff; }
    
    /* Sidebar */
    .sh-sidebar {
        position: fixed; top: 70px; right: -500px; width: 500px; height: calc(100vh - 70px);
        background-color: var(--sh-bg-panel); color: var(--sh-text-main);
        box-shadow: -10px 0 30px rgba(0,0,0,0.6); transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1050; display: flex; flex-direction: column; border-left: 1px solid var(--sh-border);
    }
    .sh-sidebar.open { right: 0; }
    
    .sh-side-header { padding: 20px; background: var(--sh-bg-base); border-bottom: 1px solid var(--sh-border); display: flex; justify-content: space-between; align-items: center; }
    .sh-side-title { font-size: 1.25rem; font-weight: 800; margin: 0; color: #fff; }
    .sh-side-close { background: none; border: none; color: var(--sh-text-muted); font-size: 1.5rem; cursor: pointer; line-height: 1; padding: 0; transition: 0.2s; }
    .sh-side-close:hover { color: #fff; }
    
    .sh-side-body { flex: 1; overflow-y: auto; padding: 0; }
    .sh-side-body::-webkit-scrollbar { width: 6px; }
    .sh-side-body::-webkit-scrollbar-track { background: var(--sh-bg-base); }
    .sh-side-body::-webkit-scrollbar-thumb { background: var(--sh-border); border-radius: 3px; }
    
    .sh-media-box { background: var(--sh-bg-base); border-bottom: 1px solid var(--sh-border); min-height: 250px; display: flex; align-items: center; justify-content: center; }
    
    /* KPI Stats */
    .sh-kpi-row { display: flex; padding: 15px 20px; background: var(--sh-bg-panel); border-bottom: 1px solid var(--sh-border-light); justify-content: space-between; }
    .sh-kpi { text-align: center; background: rgba(0,0,0,0.2); padding: 8px 15px; border-radius: 8px; flex: 1; margin: 0 5px; border: 1px solid var(--sh-border-light); }
    .sh-kpi-val { font-size: 1.2rem; font-weight: 800; }
    .sh-kpi-lbl { font-size: 0.65rem; text-transform: uppercase; color: var(--sh-text-muted); font-weight: 700; margin-top: 2px; }
    
    .sh-kpi.avail .sh-kpi-val { color: var(--sh-avail); }
    .sh-kpi.hold .sh-kpi-val { color: var(--sh-proc); }
    .sh-kpi.sold .sh-kpi-val { color: var(--sh-sold); }

    /* Unit Filters */
    .sh-filter-row { padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--sh-border); }
    .sh-tabs { display: flex; gap: 10px; }
    .sh-tab { background: var(--sh-bg-base); color: var(--sh-text-muted); border: 1px solid var(--sh-border); padding: 6px 15px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .sh-tab.active { background: rgba(16, 185, 129, 0.1); color: var(--sh-avail); border-color: var(--sh-avail); }
    .sh-pdf-btn { background: rgba(255,255,255,0.1); color: #fff; border: none; padding: 6px 15px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .sh-pdf-btn:hover { background: #fff; color: var(--sh-bg-base); }

    /* Clean Unit Cards */
    .sh-units { padding: 20px; display: flex; flex-direction: column; gap: 15px; }
    .sh-card { background: var(--sh-bg-base) !important; border: 1px solid var(--sh-border) !important; border-radius: 12px !important; padding: 15px !important; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.3) !important; color: #fff; }
    
    /* Control overrides */
    .sh-status-select { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid var(--sh-border); background: var(--sh-bg-panel); color: #fff; font-weight: bold; cursor: pointer; font-size: 0.85rem; margin-bottom: 10px; outline: none; }
    .sh-resale-input { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid var(--sh-resale); background: rgba(168,85,247,0.1); color: #fff; font-weight: bold; margin-bottom: 10px; outline: none; }
    
    /* Toast */
    #sh-toast-container { position: fixed; bottom: 30px; right: 30px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; }
    .sh-toast { padding: 15px 25px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); font-size: 0.95rem; font-weight: 600; display: flex; align-items: center; gap: 12px; color: #fff; animation: shToastIn 0.3s forwards; }
    @keyframes shToastIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    
    /* Vanilla Modals */
    .vanilla-modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(8px); }
    .vanilla-modal-content { background: var(--sh-bg-panel); margin: 5vh auto; padding: 25px; border: 1px solid var(--sh-border); border-radius: 16px; width: 90%; max-width: 500px; box-shadow: 0 25px 50px rgba(0,0,0,0.5); color: #fff; }
    .vanilla-modal-content.large { max-width: 1200px; height: 85vh; display: flex; flex-direction: column; }
    .vanilla-close { float: right; font-size: 1.5rem; color: var(--sh-text-muted); cursor: pointer; line-height: 1; }
    .vanilla-close:hover { color: #fff; }
</style>

<div id="sh-toast-container"></div>

<div id="sh-wrapper">
    <div id='sales-map'></div>

    <div class="sh-overlay">
        <h5 class="sh-overlay-title"><i class="fas fa-map-marked-alt text-info"></i> Sales Hub</h5>
        
        <label class="sh-label">Jump to Project</label>
        <select class="sh-select" id="projectJumpDropdown" onchange="jumpToSelectedProject(this.value)">
            <option value="">-- Select Map Pin --</option>
        </select>

        <label class="sh-label">Filter Type</label>
        <select class="sh-select" id="typeFilter">
            <option value="all">All Property Types</option>
            <option value="apartment">Apartments</option>
            <option value="penthouse">Penthouses</option>
            <option value="maisonette">Maisonettes</option>
            <option value="house">Houses</option>
            <option value="villa">Villas</option>
            <option value="commercial">Commercial</option>
            <option value="garage">Garages</option>
            <option value="parking space">Car Spaces</option>
        </select>
        
        <div style="text-align: center; color: var(--sh-text-muted); font-size: 0.7rem; margin-bottom: 20px;">
            <i class="fas fa-mouse"></i> Right-Click & Drag to Rotate 3D Map
        </div>
        
        <?php if(in_array($_SESSION['role'], ['admin', 'system_manager', 'sales_manager', 'director'])): ?>
            <div style="border-top: 1px solid var(--sh-border-light); margin: 15px -20px; padding-top: 15px; px-20px;"></div>
            
            <button id="viewToggleBtn" class="sh-btn sh-btn-warning" onclick="toggleViewMode()">
                <i class="fas fa-eye"></i> View as Agent
            </button>
            <button class="sh-btn sh-btn-info" onclick="document.getElementById('uploadFrameModal').style.display='block'">
                <i class="fas fa-file-csv"></i> Upload Frame (CSV)
            </button>
            <button class="sh-btn sh-btn-success" style="margin-bottom: 0;" onclick="document.getElementById('uploadMediaModal').style.display='block'">
                <i class="fas fa-cloud-upload-alt"></i> Upload Media
            </button>
        <?php endif; ?>
    </div>
</div>

<div id="custom-sidebar" class="sh-sidebar">
  <div class="sh-side-header">
    <h5 class="sh-side-title" id="sidebarProjectName">Project Details</h5>
    <button class="sh-side-close" onclick="closeSidebar()">&times;</button>
  </div>
  
  <div class="sh-side-body">
    <div id="sidebarMediaContainer" class="sh-media-box">
        <div style="text-align: center; color: var(--sh-text-muted);">
            <i class="fas fa-building fa-3x mb-2"></i>
            <div style="font-size: 0.85rem;">Click a map pin to load data</div>
        </div>
    </div>
    
    <div class="sh-kpi-row">
        <div class="sh-kpi avail"><div class="sh-kpi-val" id="sidebarAvail">0</div><div class="sh-kpi-lbl">Avail</div></div>
        <div class="sh-kpi hold"><div class="sh-kpi-val" id="sidebarHold">0</div><div class="sh-kpi-lbl">Hold</div></div>
        <div class="sh-kpi sold"><div class="sh-kpi-val" id="sidebarSold">0</div><div class="sh-kpi-lbl">Sold</div></div>
    </div>
    
    <div class="sh-filter-row">
        <div class="sh-tabs">
            <div class="sh-tab active" id="btnFilterAll" onclick="setFilter('All')">All</div>
            <div class="sh-tab" id="btnFilterAvail" onclick="setFilter('Available')">Available Only</div>
        </div>
        <button class="sh-pdf-btn" onclick="generateLivePricelist()"><i class="fas fa-file-pdf"></i> Pricelist</button>
    </div>
    
    <div id="unitListContainer" class="sh-units"></div> 
  </div>
</div>

<div id="viewPlanModal" class="vanilla-modal">
    <div class="vanilla-modal-content large">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--sh-border); padding-bottom: 15px; margin-bottom: 15px;">
            <h4 style="margin: 0; color: #fff;"><i class="fas fa-map"></i> Floor Plan Viewer</h4>
            <div style="display: flex; gap: 10px;">
                <button class="sh-btn sh-btn-info" style="margin:0; padding: 6px 12px; width:auto;" onclick="zoomPlan(-0.25)"><i class="fas fa-search-minus"></i></button>
                <button class="sh-btn sh-btn-info" style="margin:0; padding: 6px 12px; width:auto;" onclick="resetPlan()"><i class="fas fa-compress"></i></button>
                <button class="sh-btn sh-btn-info" style="margin:0; padding: 6px 12px; width:auto;" onclick="zoomPlan(0.25)"><i class="fas fa-search-plus"></i></button>
                <span class="vanilla-close" style="margin-left: 15px;" onclick="document.getElementById('viewPlanModal').style.display='none'">&times;</span>
            </div>
        </div>
        <div style="flex: 1; overflow: hidden; background: #e2e8f0; border-radius: 8px;">
            <div id="planTransformContainer" style="transition: transform 0.3s ease; width: 100%; height: 100%;">
                <iframe id="planIframe" src="" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
        </div>
    </div>
</div>

<div id="uploadFrameModal" class="vanilla-modal">
    <div class="vanilla-modal-content">
        <span class="vanilla-close" onclick="document.getElementById('uploadFrameModal').style.display='none'">&times;</span>
        <h4 style="margin: 0 0 20px 0;">Upload Project Frame (CSV)</h4>
        <form id="uploadFrameForm">
            <label class="sh-label">Select Project</label>
            <select class="sh-select" name="project_id" required>
                <option value="">-- Choose Project --</option>
                <?php
                try {
                    $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name ASC");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['name']) . '</option>'; }
                } catch (Exception $e) {}
                ?>
            </select>
            <label class="sh-label">CSV File</label>
            <input type="file" name="frame_csv" accept=".csv" required style="width: 100%; background: var(--sh-bg-base); border: 1px solid var(--sh-border); padding: 10px; border-radius: 8px; color: #fff; margin-bottom: 20px;">
            <button type="submit" class="sh-btn sh-btn-info">Upload & Import</button>
        </form>
    </div>
</div>

<div id="uploadMediaModal" class="vanilla-modal">
    <div class="vanilla-modal-content">
        <span class="vanilla-close" onclick="document.getElementById('uploadMediaModal').style.display='none'">&times;</span>
        <h4 style="margin: 0 0 20px 0;">Upload Project Media</h4>
        <form id="uploadMediaForm">
            <label class="sh-label">Select Project</label>
            <select class="sh-select" name="project_id" required>
                <option value="">-- Choose Project --</option>
                <?php
                try {
                    $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name ASC");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['name']) . '</option>'; }
                } catch (Exception $e) {}
                ?>
            </select>
            <label class="sh-label">Media Type</label>
            <select class="sh-select" name="media_type" id="mediaTypeSelect" required onchange="toggleFloorInput()">
                <option value="Render (Image)">Render (Image)</option>
                <option value="Render (Video)">Render (Video)</option>
                <option value="Floor Plan">Floor Plan (PDF/Img)</option>
                <option disabled>--- Pricelist Document Pages ---</option>
                <option value="Pricelist - Front Cover">Pricelist - Front Cover</option>
                <option value="Pricelist - Timeframes & Terms">Pricelist - Timeframes & Terms</option>
                <option value="Pricelist - Spec Sheet">Pricelist - Spec Sheet (Multi-page PDF supported)</option>
                <option value="Pricelist - Back Cover">Pricelist - Back Cover</option>
            </select>
            <div id="floorInputGroup" style="display:none;">
                <label class="sh-label">Floor Level (Matches CSV)</label>
                <input type="text" name="floor_level" placeholder="e.g. -1, 0, 1, 2" class="sh-select">
            </div>
            <label class="sh-label">File</label>
            <input type="file" name="media_file" required style="width: 100%; background: var(--sh-bg-base); border: 1px solid var(--sh-border); padding: 10px; border-radius: 8px; color: #fff; margin-bottom: 20px;">
            <button type="submit" class="sh-btn sh-btn-success">Upload to Cloudflare</button>
        </form>
    </div>
</div>

<script>
    // ========================================================
    // MANAGER vs AGENT VIEW MODE ENGINE
    // ========================================================
    const userRole = '<?= $_SESSION['role'] ?>';
    const isManagerUser = ['admin', 'director', 'system_manager', 'sales_manager'].includes(userRole);
    let currentViewMode = isManagerUser ? 'manager' : 'agent';

    function toggleViewMode() {
        const btn = document.getElementById('viewToggleBtn');
        if (currentViewMode === 'manager') {
            currentViewMode = 'agent';
            btn.innerHTML = '<i class="fas fa-user-tie"></i> Revert to Manager View';
            btn.classList.replace('sh-btn-warning', 'sh-btn-success');
            btn.classList.add('active');
        } else {
            currentViewMode = 'manager';
            btn.innerHTML = '<i class="fas fa-eye"></i> View as Agent';
            btn.classList.replace('sh-btn-success', 'sh-btn-warning');
            btn.classList.remove('active');
        }
        
        const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid');
        if (pid && mapProjectsData[pid]) openProjectSidebar(mapProjectsData[pid]);
    }

    // ========================================================
    // STANDARD UI FUNCTIONS
    // ========================================================
    function showToast(message, type = 'success') {
        const container = document.getElementById('sh-toast-container');
        const toast = document.createElement('div');
        toast.className = 'sh-toast';
        toast.style.background = type === 'success' ? '#10B981' : '#EF4444';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        toast.innerHTML = `<i class="fas ${icon} fa-lg"></i> ${message}`;
        container.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
    }

    function setFilter(filterType) {
        document.getElementById('btnFilterAll').classList.toggle('active', filterType === 'All');
        document.getElementById('btnFilterAvail').classList.toggle('active', filterType === 'Available');

        const cards = document.querySelectorAll('.sh-card');
        cards.forEach(card => {
            if (filterType === 'All') {
                card.style.display = 'block'; 
            } else if (filterType === 'Available') {
                card.style.display = (card.getAttribute('data-status') === 'Available') ? 'block' : 'none';
            }
        });
    }

    let currentPlanZoom = 1;
    function zoomPlan(amount) { currentPlanZoom = Math.max(0.25, Math.min(4, currentPlanZoom + amount)); applyPlanTransform(); }
    function resetPlan() { currentPlanZoom = 1; applyPlanTransform(); }
    function applyPlanTransform() { document.getElementById('planTransformContainer').style.transform = `scale(${currentPlanZoom})`; }

    function toggleFloorInput() {
        const type = document.getElementById('mediaTypeSelect').value;
        const grp = document.getElementById('floorInputGroup');
        grp.style.display = (type === 'Floor Plan') ? 'block' : 'none';
        grp.querySelector('input').required = (type === 'Floor Plan');
    }

    function closeSidebar() { document.getElementById('custom-sidebar').classList.remove('open'); }

    // Recursive function to hide price text nodes securely without breaking HTML structure
    function hidePricesInDOM(element) {
        if (element.hasChildNodes()) {
            Array.from(element.childNodes).forEach(child => {
                if (child.nodeType === Node.TEXT_NODE) {
                    if (child.nodeValue.includes('€')) {
                        let span = document.createElement('span');
                        span.innerHTML = ' <span style="color:var(--sh-text-muted); font-style:italic; font-size:0.85rem;">🔒 Price Confidential</span> ';
                        element.replaceChild(span, child);
                    }
                } else if (child.nodeType === Node.ELEMENT_NODE) {
                    // Do not parse input/select tags to avoid breaking inputs
                    if (child.tagName !== 'INPUT' && child.tagName !== 'SELECT' && child.tagName !== 'TEXTAREA') {
                        hidePricesInDOM(child);
                    }
                }
            });
        }
    }

    // ========================================================
    // MAPBOX INTEGRATION
    // ========================================================
    let mapProjectsData = {};
    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw'; 
    const map = new mapboxgl.Map({
        container: 'sales-map',
        style: 'mapbox://styles/mapbox/satellite-streets-v12', 
        center: [14.38, 35.92], 
        zoom: 9.5, 
        pitch: 25, 
        bearing: 0 
    });

    map.addControl(new mapboxgl.NavigationControl(), 'bottom-right');

    map.on('style.load', () => {
        const layers = map.getStyle().layers;
        const labelLayerId = layers.find((layer) => layer.type === 'symbol' && layer.layout['text-field']).id;
        map.addLayer({
            'id': 'add-3d-buildings',
            'source': 'composite',
            'source-layer': 'building',
            'filter': ['==', 'extrude', 'true'],
            'type': 'fill-extrusion',
            'minzoom': 15,
            'paint': {
                'fill-extrusion-color': '#0f172a',
                'fill-extrusion-height': ['interpolate', ['linear'], ['zoom'], 15, 0, 15.05, ['get', 'height']],
                'fill-extrusion-base': ['interpolate', ['linear'], ['zoom'], 15, 0, 15.05, ['get', 'min_height']],
                'fill-extrusion-opacity': 0.7
            }
        }, labelLayerId);
    });

    // ========================================================
    // DATA FETCHING & DYNAMIC INTERCEPTOR (THE FIX)
    // ========================================================
    function openProjectSidebar(project) {
        map.flyTo({ center: [project.longitude, project.latitude], zoom: 17, pitch: 50, essential: true });
        
        document.getElementById('sidebarProjectName').innerText = project.project_name;
        document.getElementById('sidebarProjectName').setAttribute('data-pid', project.project_id);
        
        document.getElementById('sidebarAvail').innerText = project.available_units;
        document.getElementById('sidebarHold').innerText = project.held_units;
        document.getElementById('sidebarSold').innerText = project.sold_units;

        setFilter('All');

        document.getElementById('custom-sidebar').classList.add('open');
        document.getElementById('unitListContainer').innerHTML = '<div style="text-align:center; padding: 40px; color: var(--sh-text-muted);">Loading units...</div>';
        document.getElementById('sidebarMediaContainer').innerHTML = '<div style="text-align:center; padding: 40px; color: var(--sh-text-muted);">Loading media...</div>';

        fetch('api/get_project_units.php?project_id=' + project.project_id)
            .then(response => response.json())
            .then(unitData => {
                if(unitData.success) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = unitData.html;
                    
                    const unitCards = tempDiv.querySelectorAll('.card, .unit-card'); // Support original API output
                    
                    unitCards.forEach(card => {
                        const unitId = card.getAttribute('data-unit-id') || card.querySelector('[data-unit-id]')?.getAttribute('data-unit-id');
                        if (!unitId) return;

                        let status = card.getAttribute('data-status') || 'Available';
                        
                        // Clean Statuses
                        if (status === 'Reserved') status = 'Proceeding';
                        if (status === 'Sold POS' || status === 'Sold Contract') status = 'Sold';
                        if (status === 'BOM') status = 'Available';
                        
                        // Convert to new Scoped CSS Structure
                        card.className = `sh-card`;
                        card.setAttribute('data-status', status);
                        card.style.marginBottom = '15px';
                        
                        // Color borders
                        if (status.includes('Available')) card.style.borderLeft = '4px solid var(--sh-avail)';
                        else if (status.includes('Proceeding')) card.style.borderLeft = '4px solid var(--sh-proc)';
                        else if (status.includes('Sold')) card.style.borderLeft = '4px solid var(--sh-sold)';
                        else if (status === 'Resale') card.style.borderLeft = '4px solid var(--sh-resale)';
                        else card.style.borderLeft = '4px solid var(--sh-hold)';

                        // 1. Determine Agent view overrides
                        if (currentViewMode === 'agent') {
                            if (status.includes('Sold')) {
                                hidePricesInDOM(card);
                            }
                        }

                        // 2. Identify old control areas from original API html and replace with proper controls
                        const oldControls = card.querySelector('select[onchange^="managerUpdateStatus"]')?.parentNode 
                                         || card.querySelector('.action-buttons') 
                                         || card.querySelector('form');

                        const controlWrapper = document.createElement('div');
                        controlWrapper.style.marginTop = '15px';
                        controlWrapper.style.paddingTop = '15px';
                        controlWrapper.style.borderTop = '1px solid var(--sh-border)';

                        // Extract resale if it exists
                        let resalePrice = card.querySelector('.resale-input')?.value || '';

                        if (currentViewMode === 'manager') {
                            controlWrapper.innerHTML = `
                                <label class="sh-label">Update Status</label>
                                <select class="sh-status-select" id="status-${unitId}" onchange="handleStatusChange(${unitId}, this)">
                                    <option value="Available" ${status === 'Available' ? 'selected' : ''}>Available</option>
                                    <option value="On Hold" ${status === 'On Hold' ? 'selected' : ''}>On Hold</option>
                                    <option value="Resale" ${status === 'Resale' ? 'selected' : ''}>Resale</option>
                                    <option value="BOM" ${status === 'BOM' ? 'selected' : ''}>BOM</option>
                                    <option value="Proceeding" ${status === 'Proceeding' ? 'selected' : ''}>Proceeding</option>
                                    <option value="Proceeding Pending Approval" ${status === 'Proceeding Pending Approval' ? 'selected' : ''}>Proceeding Pending Approval</option>
                                    <option value="Sold" ${status === 'Sold' ? 'selected' : ''}>Sold</option>
                                    <option value="Sold Pending Approval" ${status === 'Sold Pending Approval' ? 'selected' : ''}>Sold Pending Approval</option>
                                </select>
                                <input type="number" step="0.01" class="sh-resale-input" id="resale_input_${unitId}" placeholder="Resale Asking Price (€)" value="${resalePrice}" style="display: ${status === 'Resale' ? 'block' : 'none'};">
                                
                                <button class="sh-btn sh-btn-warning" style="background:transparent; border:1px dashed var(--sh-border);" onclick="togglePriceEdit(${unitId})">✎ Modify Pricing</button>
                                
                                <div id="price_edit_${unitId}" style="display:none; background:rgba(0,0,0,0.2); padding:10px; border-radius:8px; border:1px solid var(--sh-border); margin-top:10px;">
                                    <label class="sh-label">Shell Price (€)</label>
                                    <input type="number" id="inp_sh_${unitId}" class="sh-select" style="margin-bottom:10px; padding:6px;">
                                    <label class="sh-label">Finishes Price (€)</label>
                                    <input type="number" id="inp_fn_${unitId}" class="sh-select" style="margin-bottom:10px; padding:6px;">
                                    <button class="sh-btn sh-btn-success" onclick="savePrice(${unitId})">Save Prices</button>
                                </div>
                            `;
                        } else {
                            // Agent View
                            if (status === 'Available' || status === 'BOM') {
                                controlWrapper.innerHTML = `
                                    <div style="display:flex; gap:10px;">
                                        <button class="sh-btn sh-btn-warning" onclick="holdProperty(${unitId})"><i class="fas fa-pause"></i> Hold</button>
                                        <button class="sh-btn sh-btn-success" onclick="requestReserve(${unitId})"><i class="fas fa-check"></i> Proceed</button>
                                    </div>
                                `;
                            } else {
                                controlWrapper.innerHTML = `
                                    <div style="font-weight:bold; color:var(--sh-text-muted); font-size:0.85rem; text-transform:uppercase; text-align:center;">
                                        Current Status: <span style="color:#fff;">${status}</span>
                                    </div>
                                `;
                            }
                        }

                        // Inject the new controls
                        if (oldControls) {
                            oldControls.parentNode.replaceChild(controlWrapper, oldControls);
                        } else {
                            card.appendChild(controlWrapper);
                        }
                    });

                    document.getElementById('unitListContainer').innerHTML = '';
                    unitCards.forEach(c => document.getElementById('unitListContainer').appendChild(c));
                    
                    // Render Media
                    let slides = [];
                    if (unitData.media && unitData.media.videos) {
                        unitData.media.videos.forEach(v => { slides.push(`<video src="${v}" controls style="width:100%; height:250px; object-fit:cover; border-radius:8px;"></video>`); });
                    }
                    if (unitData.media && unitData.media.renders) {
                        unitData.media.renders.forEach(r => { slides.push(`<img src="${r}" style="width:100%; height:250px; object-fit:cover; border-radius:8px;">`); });
                    }
                    
                    let mediaHtml = '';
                    if (slides.length > 0) {
                        if (slides.length === 1) {
                            mediaHtml = `<div style="padding:15px;">${slides[0]}</div>`;
                        } else {
                            let inner = '';
                            slides.forEach((s, i) => { inner += `<div class="carousel-item ${i===0?'active':''}">${s}</div>`; });
                            mediaHtml = `
                            <div style="padding:15px;">
                                <div id="projectCarousel" class="carousel slide" data-bs-ride="carousel">
                                  <div class="carousel-inner">${inner}</div>
                                  <button class="carousel-control-prev" type="button" data-bs-target="#projectCarousel" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
                                  <button class="carousel-control-next" type="button" data-bs-target="#projectCarousel" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
                                </div>
                            </div>`;
                        }
                    } else {
                        mediaHtml = `<div class="sh-media-box"><div style="text-align:center; color:var(--sh-text-muted);"><i class="fas fa-image fa-3x mb-2"></i><div style="font-size:0.85rem;">No media uploaded yet</div></div></div>`;
                    }
                    document.getElementById('sidebarMediaContainer').innerHTML = mediaHtml;

                } else {
                    document.getElementById('unitListContainer').innerHTML = '<div style="padding:20px; color:var(--sh-danger); text-align:center;">Error loading units.</div>';
                }
            });
    }

    function handleStatusChange(unitId, selectElement) {
        const newStatus = selectElement.value;
        const resaleInput = document.getElementById('resale_input_' + unitId);
        
        if (newStatus === 'Resale') {
            resaleInput.style.display = 'block';
            resaleInput.focus();
            resaleInput.onblur = () => {
                if(resaleInput.value) managerUpdateStatusWithResale(unitId, newStatus, selectElement, resaleInput.value);
            };
        } else {
            resaleInput.style.display = 'none';
            managerUpdateStatusWithResale(unitId, newStatus, selectElement, null);
        }
    }

    function managerUpdateStatusWithResale(propertyId, newStatus, selectElement, resalePrice) {
        selectElement.disabled = true;
        selectElement.style.opacity = '0.5';

        fetch('sales_hub.php', { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=update_unit_status&unit_id=${propertyId}&status=${encodeURIComponent(newStatus)}&resale_price=${resalePrice || ''}`
        })
        .then(r => r.text()).then(data => {
            selectElement.disabled = false;
            selectElement.style.opacity = '1';
            if(data === 'OK') { 
                showToast(`Status updated to ${newStatus}`, 'success');
                const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid');
                if(pid && mapProjectsData[pid]) setTimeout(() => openProjectSidebar(mapProjectsData[pid]), 500);
            } else { 
                showToast("Error updating status.", 'error');
            }
        });
    }

    function jumpToSelectedProject(projectId) {
        if(projectId && mapProjectsData[projectId]) openProjectSidebar(mapProjectsData[projectId]);
    }

    map.on('load', () => {
        fetch('api/get_sales_map_data.php')
            .then(response => response.json())
            .then(data => {
                if(data.success && data.data) {
                    const dropdown = document.getElementById('projectJumpDropdown');
                    data.data.forEach(project => {
                        if(project.latitude && project.longitude) {
                            mapProjectsData[project.project_id] = project;
                            const opt = document.createElement('option');
                            opt.value = project.project_id;
                            opt.innerHTML = project.project_name;
                            dropdown.appendChild(opt);

                            const el = document.createElement('div');
                            el.style.backgroundColor = project.available_units > 0 ? '#10B981' : '#EF4444'; 
                            el.style.width = '20px'; el.style.height = '20px';
                            el.style.borderRadius = '50%'; el.style.border = '2px solid white';
                            el.style.boxShadow = '0 0 10px rgba(0,0,0,0.8)'; el.style.cursor = 'pointer';

                            new mapboxgl.Marker(el).setLngLat([project.longitude, project.latitude]).addTo(map);
                            el.addEventListener('click', () => { openProjectSidebar(project); });
                        }
                    });
                }
            });
    });

    function togglePriceEdit(id) {
        const editBox = document.getElementById('price_edit_' + id);
        editBox.style.display = editBox.style.display === 'none' ? 'block' : 'none';
    }

    function savePrice(id) {
        const shell = document.getElementById('inp_sh_' + id).value;
        const fin = document.getElementById('inp_fn_' + id).value;
        
        let formData = new FormData();
        formData.append('property_id', id);
        formData.append('shell_price', shell);
        formData.append('finishes_price', fin);

        fetch('api/update_unit_price.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) { 
                showToast("Price updated successfully!", "success"); 
                const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid');
                if(pid && mapProjectsData[pid]) setTimeout(() => openProjectSidebar(mapProjectsData[pid]), 500);
            } else { 
                showToast("Error: " + data.message, "error"); 
            }
        });
    }

    function generateLivePricelist() {
        const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid');
        if(!pid) { alert("Project ID not found."); return; }
        window.open('print_pricelist.php?project_id=' + pid, '_blank');
    }
    
    // Fallback bindings for API actions
    function holdProperty(propertyId) {
        if(!confirm("Are you sure you want to put this unit on hold? You will have 7 days to finalize.")) return;
        let formData = new FormData(); formData.append('action', 'hold_property'); formData.append('property_id', propertyId);
        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) { showToast("Property put on hold!", "success"); const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid'); if(pid && mapProjectsData[pid]) setTimeout(() => openProjectSidebar(mapProjectsData[pid]), 500); } 
            else { showToast("Error: " + data.message, "error"); }
        });
    }

    function requestReserve(propertyId) {
        if(!confirm("Are you sure you want to transition this unit to Proceeding?")) return;
        let formData = new FormData(); formData.append('action', 'request_reserved'); formData.append('property_id', propertyId);
        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) { showToast("Status updated to Proceeding!", "success"); const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid'); if(pid && mapProjectsData[pid]) setTimeout(() => openProjectSidebar(mapProjectsData[pid]), 500); } 
            else { showToast("Error: " + data.message, "error"); }
        });
    }

    document.getElementById('uploadFrameForm').addEventListener('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this); let btn = this.querySelector('button[type="submit"]');
        btn.innerHTML = 'Uploading...'; btn.disabled = true;
        fetch('api/upload_project_frame.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) { alert(data.message); location.reload(); } else { alert('Error: ' + data.message); btn.innerHTML = 'Upload & Import'; btn.disabled = false; }
        });
    });

    document.getElementById('uploadMediaForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        let fileInput = this.querySelector('input[type="file"]');
        if(fileInput.files.length === 0) { alert("Please select a file to upload."); return; }
        
        let file = fileInput.files[0];
        let btn = this.querySelector('button[type="submit"]');
        btn.innerHTML = 'Connecting to Cloudflare...'; btn.disabled = true;

        try {
            let authData = new FormData(); authData.append('action', 'get_upload_url'); authData.append('filename', file.name); authData.append('mime_type', file.type || 'application/octet-stream');
            let authRes = await fetch('api/upload_sales_media.php', { method: 'POST', body: authData });
            let authJson = await authRes.json();
            if(!authJson.success) throw new Error(authJson.message);

            btn.innerHTML = 'Uploading file...';
            await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest(); xhr.open('PUT', authJson.url, true); xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                xhr.onload = function() { if (xhr.status >= 200 && xhr.status < 300) { resolve(); } else { reject(new Error('Cloudflare rejected the upload.')); } };
                xhr.onerror = () => reject(new Error('Network Error during upload.')); xhr.send(file);
            });

            btn.innerHTML = 'Saving Data...';
            let dbData = new FormData(this); dbData.append('action', 'save_record'); dbData.append('file_key', authJson.key); dbData.append('filename', file.name);
            let dbRes = await fetch('api/upload_sales_media.php', { method: 'POST', body: dbData });
            let dbJson = await dbRes.json();
            
            if(dbJson.success) { alert(dbJson.message); location.reload(); } else { throw new Error(dbJson.message); }
        } catch (err) {
            alert('Error: ' + err.message); btn.innerHTML = 'Upload to Cloudflare'; btn.disabled = false;
        }
    });

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_unit_status') {
        if (!in_array($_SESSION['role'], ['admin', 'system_manager', 'sales_manager', 'director'])) { http_response_code(403); exit; }
        
        $unitId = (int)$_POST['unit_id'];
        $status = $_POST['status'];
        $resale = !empty($_POST['resale_price']) ? (float)$_POST['resale_price'] : null;
        if ($status !== 'Resale') $resale = null;
        
        $pdo->prepare("UPDATE project_units SET status = ?, resale_price = ? WHERE id = ?")->execute([$status, $resale, $unitId]);
        echo "OK";
        exit;
    }
    ?>
</script>

<?php require_once 'footer.php'; ?>
