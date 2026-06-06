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
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ALTER TABLE sales_properties ADD COLUMN resale_price DECIMAL(10,2) DEFAULT NULL");
    $pdo->exec("ALTER TABLE sales_properties ADD COLUMN held_by_agent_id INT DEFAULT NULL");
    $pdo->exec("ALTER TABLE sales_properties ADD COLUMN hold_expiry DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE sales_properties ADD COLUMN shell_price DECIMAL(10,2) DEFAULT NULL");
    $pdo->exec("ALTER TABLE sales_properties ADD COLUMN finishes_price DECIMAL(10,2) DEFAULT NULL");
} catch(PDOException $e) { /* Silently ignore if columns already exist */ }
require_once 'header.php';
?>

<script src='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js'></script>
<link href='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css' rel='stylesheet' />

<script src='https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-draw/v1.4.3/mapbox-gl-draw.js'></script>
<link rel='stylesheet' href='https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-draw/v1.4.3/mapbox-gl-draw.css' type='text/css' />
<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>

<style>
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

    footer, .footer, #footer, .navbar { display: none !important; }
    .container-fluid.main-content, .main-panel { padding: 0 !important; margin: 0 !important; background: var(--sh-bg-base) !important; }
    
    #sh-wrapper { position: relative; height: 100vh; width: 100%; overflow: hidden; font-family: 'Inter', sans-serif; color: var(--sh-text-main); }
    #sales-map { position: absolute; top: 0; bottom: 0; width: 100%; left: 0; }
    
    .mapboxgl-ctrl-top-right { top: 20px; left: 50% !important; right: auto !important; transform: translateX(-50%); z-index: 20; display: flex; }
    .mapboxgl-ctrl-group { display: flex !important; flex-direction: row !important; background: var(--sh-bg-panel); border: 2px solid var(--sh-avail); border-radius: 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.6); overflow: hidden; }
    .mapboxgl-ctrl-group button { filter: invert(1); width: 45px; height: 45px; transition: 0.2s; }
    .mapboxgl-ctrl-group button:hover { background: rgba(255,255,255,0.1); }
    
    .sh-overlay {
        position: absolute; top: 20px; left: 20px; z-index: 10; width: 340px;
        background: var(--sh-bg-glass); backdrop-filter: blur(12px);
        border: 1px solid var(--sh-border-light); border-radius: 16px;
        padding: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        max-height: 90vh; overflow-y: auto;
    }
    .sh-overlay::-webkit-scrollbar { width: 4px; }
    .sh-overlay::-webkit-scrollbar-thumb { background: var(--sh-border); }

    .sh-overlay-title { font-size: 1.25rem; font-weight: 800; color: #fff; margin: 0 0 15px 0; display: flex; align-items: center; justify-content: space-between; }
    
    .sh-label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--sh-text-muted); text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px; }
    .sh-select { width: 100%; background: var(--sh-bg-base); color: #fff; border: 1px solid var(--sh-border); padding: 10px 12px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 15px; outline: none; cursor: pointer; }
    .sh-input { width: 100%; background: var(--sh-bg-base); color: #fff; border: 1px solid var(--sh-border); padding: 10px 12px; border-radius: 8px; font-size: 0.9rem; outline: none; }
    
    .sh-btn { width: 100%; padding: 10px; border-radius: 8px; border: none; font-weight: 700; font-size: 0.85rem; cursor: pointer; display: flex; justify-content: center; align-items: center; gap: 8px; transition: 0.2s; margin-bottom: 10px; }
    .sh-btn-warning { background: rgba(245, 158, 11, 0.1); color: var(--sh-proc); border: 1px solid rgba(245, 158, 11, 0.3); }
    .sh-btn-warning:hover, .sh-btn-warning.active { background: var(--sh-proc); color: #fff; }
    .sh-btn-info { background: rgba(59, 130, 246, 0.1); color: var(--sh-sold); border: 1px solid rgba(59, 130, 246, 0.3); }
    .sh-btn-info:hover { background: var(--sh-sold); color: #fff; }
    .sh-btn-success { background: rgba(16, 185, 129, 0.1); color: var(--sh-avail); border: 1px solid rgba(16, 185, 129, 0.3); }
    .sh-btn-success:hover { background: var(--sh-avail); color: #fff; }
    .sh-btn-danger { background: rgba(239, 68, 68, 0.1); color: var(--sh-danger); border: 1px solid rgba(239, 68, 68, 0.3); }
    .sh-btn-danger:hover { background: var(--sh-danger); color: #fff; }
    
    .sh-sidebar {
        position: fixed; top: 0; right: -500px; width: 500px; height: 100vh;
        background-color: var(--sh-bg-panel); color: var(--sh-text-main);
        box-shadow: -10px 0 30px rgba(0,0,0,0.6); transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1050; display: flex; flex-direction: column; border-left: 1px solid var(--sh-border);
    }
    .sh-sidebar.open { right: 0; }
    
    .sh-side-header { padding: 20px; background: var(--sh-bg-base); border-bottom: 1px solid var(--sh-border); display: flex; justify-content: space-between; align-items: center; }
    .sh-side-title { font-size: 1.25rem; font-weight: 800; margin: 0; color: #fff; }
    .sh-side-close { background: none; border: none; color: var(--sh-text-muted); font-size: 2rem; cursor: pointer; line-height: 1; padding: 0; transition: 0.2s; }
    .sh-side-close:hover { color: #fff; }
    
    .sh-side-body { flex: 1; overflow-y: auto; padding: 0; }
    .sh-side-body::-webkit-scrollbar { width: 6px; }
    .sh-side-body::-webkit-scrollbar-track { background: var(--sh-bg-base); }
    .sh-side-body::-webkit-scrollbar-thumb { background: var(--sh-border); border-radius: 3px; }
    
    .sh-media-box { background: var(--sh-bg-base); border-bottom: 1px solid var(--sh-border); display: flex; align-items: center; justify-content: center; position: relative; }
    
    .sh-kpi-row { display: flex; padding: 15px 20px; background: var(--sh-bg-panel); border-bottom: 1px solid var(--sh-border-light); justify-content: space-between; }
    .sh-kpi { text-align: center; background: rgba(0,0,0,0.2); padding: 8px 15px; border-radius: 8px; flex: 1; margin: 0 5px; border: 1px solid var(--sh-border-light); }
    .sh-kpi-val { font-size: 1.2rem; font-weight: 800; }
    .sh-kpi-lbl { font-size: 0.65rem; text-transform: uppercase; color: var(--sh-text-muted); font-weight: 700; margin-top: 2px; }
    
    .sh-kpi.avail .sh-kpi-val { color: var(--sh-avail); }
    .sh-kpi.hold .sh-kpi-val { color: var(--sh-proc); }
    .sh-kpi.sold .sh-kpi-val { color: var(--sh-sold); }

    .sh-filter-row { padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--sh-border); }
    .sh-tabs { display: flex; gap: 10px; }
    .sh-tab { background: var(--sh-bg-base); color: var(--sh-text-muted); border: 1px solid var(--sh-border); padding: 6px 15px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .sh-tab.active { background: rgba(16, 185, 129, 0.1); color: var(--sh-avail); border-color: var(--sh-avail); }
    .sh-pdf-btn { background: rgba(255,255,255,0.1); color: #fff; border: none; padding: 6px 15px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .sh-pdf-btn:hover { background: #fff; color: var(--sh-bg-base); }

    .sh-units { padding: 20px; display: flex; flex-direction: column; gap: 15px; }
    .sh-card { background: var(--sh-bg-base) !important; border: 1px solid var(--sh-border) !important; border-radius: 12px !important; padding: 15px !important; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.3) !important; color: #fff !important; transition: transform 0.2s; }
    .sh-card h4, .sh-card h5 { color: #fff !important; margin-bottom: 5px; font-weight: 800; }
    .sh-card small { color: var(--sh-text-muted) !important; }
    .sh-card .badge { padding: 5px 10px; border-radius: 6px; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; background: rgba(255,255,255,0.1); color: #fff; }
    .sh-card .text-success { color: var(--sh-avail) !important; font-size: 1.25rem !important; font-weight: 800 !important; }
    
    .sh-project-divider { background: rgba(0,0,0,0.3); padding: 12px 20px; font-weight: 800; color: #fff; font-size: 1.1rem; border-left: 4px solid var(--sh-avail); border-radius: 8px; margin-bottom: 10px; margin-top: 10px;}
    .project-unit-group { display: flex; flex-direction: column; gap: 15px; }
    
    .sh-resale-input { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid var(--sh-resale); background: rgba(168,85,247,0.1); color: #fff; font-weight: bold; margin-bottom: 10px; outline: none; }
    .sh-price-hidden { color: var(--sh-text-muted) !important; font-style: italic !important; font-size: 0.9rem !important; background: rgba(0,0,0,0.2); padding: 4px 8px; border-radius: 4px; }

    .sh-drop-zone { border: 2px dashed var(--sh-border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: 0.2s; background: rgba(0,0,0,0.2); }
    .sh-drop-zone:hover, .sh-drop-zone.dragover { border-color: var(--sh-avail); background: rgba(16,185,129,0.1); }

    .sh-lightbox { display: none; position: fixed; z-index: 3000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); backdrop-filter: blur(10px); flex-direction: column; align-items: center; justify-content: center; }
    .sh-lightbox-close { position: absolute; top: 20px; right: 30px; color: #fff; font-size: 3rem; cursor: pointer; line-height: 1; }
    .sh-lightbox-content { display: flex; align-items: center; justify-content: center; max-width: 90vw; max-height: 80vh; }
    .sh-lightbox-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.1); color: white; border: none; font-size: 2rem; padding: 15px; cursor: pointer; border-radius: 50%; transition: 0.2s; }
    .sh-lightbox-nav:hover { background: rgba(255,255,255,0.3); }
    .sh-lightbox-prev { left: 20px; }
    .sh-lightbox-next { right: 20px; }
    .sh-lightbox-counter { position: absolute; bottom: 30px; color: #fff; font-size: 1.2rem; font-weight: bold; background: rgba(0,0,0,0.5); padding: 5px 15px; border-radius: 20px; }

    .vanilla-modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(8px); }
    .vanilla-modal-content { background: var(--sh-bg-panel); margin: 5vh auto; padding: 25px; border: 1px solid var(--sh-border); border-radius: 16px; width: 90%; max-width: 500px; box-shadow: 0 25px 50px rgba(0,0,0,0.5); color: #fff; }
    .vanilla-modal-content.large { max-width: 1200px; height: 85vh; display: flex; flex-direction: column; }
    .vanilla-close { float: right; font-size: 1.5rem; color: var(--sh-text-muted); cursor: pointer; line-height: 1; }
    .vanilla-close:hover { color: #fff; }

    #sh-toast-container { position: fixed; bottom: 30px; right: 30px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; }
    .sh-toast { padding: 15px 25px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); font-size: 0.95rem; font-weight: 600; display: flex; align-items: center; gap: 12px; color: #fff; animation: shToastIn 0.3s forwards; }
    @keyframes shToastIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div id="holdLedgerModal" class="vanilla-modal">
    <div class="vanilla-modal-content large" style="max-width: 1000px; height: 85vh; display: flex; flex-direction: column;">
        <div style="text-align: right; margin-bottom: 10px;">
            <span class="vanilla-close" onclick="document.getElementById('holdLedgerModal').style.display='none'">&times;</span>
        </div>
        <div id="holdLedgerContent" style="flex: 1; overflow-y: auto; padding-right: 15px;"></div>
    </div>
</div>

<div id="sh-toast-container"></div>

<div id="sh-lightbox" class="sh-lightbox">
    <span class="sh-lightbox-close" onclick="closeGallery()">&times;</span>
    <button class="sh-lightbox-nav sh-lightbox-prev" onclick="changeSlide(-1)">&#10094;</button>
    <div class="sh-lightbox-content" id="sh-lightbox-media"></div>
    <button class="sh-lightbox-nav sh-lightbox-next" onclick="changeSlide(1)">&#10095;</button>
    <div class="sh-lightbox-counter" id="sh-lightbox-counter"></div>
</div>

<div id="sh-wrapper">
    <div id='sales-map'></div>

    <div class="sh-overlay">
        <h5 class="sh-overlay-title">
            <span><i class="fas fa-map-marked-alt text-info"></i> Sales Hub</span>
        </h5>
        
        <label class="sh-label">Jump to Project</label>
        <select class="sh-select" id="projectJumpDropdown" onchange="jumpToSelectedProject(this.value)">
            <option value="">-- Select Map Pin --</option>
        </select>

        <div style="border-top: 1px solid var(--sh-border-light); margin: 15px -20px; padding-top: 15px; padding-left: 20px; padding-right: 20px;">
            <label class="sh-label" style="color: var(--sh-avail);">Advanced Search & Filters</label>
            
            <select class="sh-select" id="typeFilter" onchange="applySidebarFilters()">
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

            <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                <div style="flex: 1;">
                    <label class="sh-label" style="font-size: 0.65rem;">Min Budget (€)</label>
                    <input type="number" id="minBudget" class="sh-input" placeholder="0" oninput="applySidebarFilters()">
                </div>
                <div style="flex: 1;">
                    <label class="sh-label" style="font-size: 0.65rem;">Max Budget (€)</label>
                    <input type="number" id="maxBudget" class="sh-input" placeholder="Any" oninput="applySidebarFilters()">
                </div>
            </div>
            
            <button class="sh-btn" style="margin-bottom: 10px; background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);" onclick="openHoldLedger()">
                <i class="fas fa-list"></i> View Holds Ledger
            </button>
            <button class="sh-btn sh-btn-danger" style="margin-bottom: 15px;" onclick="resetMap()">
                <i class="fas fa-undo-alt"></i> Reset Map & Clear Filters
            </button>

            <div style="text-align: center; color: var(--sh-text-muted); font-size: 0.75rem; margin-bottom: 10px; background: rgba(0,0,0,0.2); padding: 12px; border-radius: 8px;">
                <i class="fas fa-draw-polygon text-info mb-2" style="font-size: 1.5rem;"></i><br>
                Click the Polygon icon top-center to outline an area. <br><b>Double-click</b> to close the shape.
            </div>
        </div>
        
        <?php if(in_array($_SESSION['role'], ['admin', 'system_manager', 'sales_manager', 'director'])): ?>
            <div style="border-top: 1px solid var(--sh-border-light); margin: 15px -20px; padding-top: 15px; padding-left: 20px; padding-right: 20px;">
                
                <?php if(in_array($_SESSION['role'], ['admin', 'sales_manager'])): ?>
                    <button class="sh-btn" style="background: rgba(59, 130, 246, 0.2); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.5); margin-bottom: 15px;" onclick="window.location.href='sales_project_manager.php'">
                        <i class="fas fa-tools"></i> Open Project Manager
                    </button>
                <?php endif; ?>

                <button class="sh-btn" style="background: rgba(168, 85, 247, 0.1); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.3);" onclick="document.getElementById('dailySyncInput').click()">
                    <i class="fas fa-sync-alt"></i> 1-Click Daily Sync
                </button>
                <input type="file" id="dailySyncInput" accept=".csv" style="display:none;" onchange="processDailySync(this)">
                
                <button id="viewToggleBtn" class="sh-btn sh-btn-warning" onclick="toggleViewMode()">
                    <i class="fas fa-eye"></i> View as Agent
                </button>
                
                <button class="sh-btn sh-btn-info" onclick="document.getElementById('uploadFrameModal').style.display='block'">
                    <i class="fas fa-file-csv"></i> Upload Frame (CSV)
                </button>
                
                <button class="sh-btn sh-btn-success" style="margin-bottom: 0;" onclick="document.getElementById('uploadMediaModal').style.display='block'">
                    <i class="fas fa-cloud-upload-alt"></i> Upload Media
                </button>
            </div>
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
        <div style="text-align: center; color: var(--sh-text-muted); padding: 40px;">
            <i class="fas fa-building fa-3x mb-2"></i>
            <div style="font-size: 0.85rem;">Click a map pin to load data</div>
        </div>
    </div>
    
    <div class="sh-kpi-row">
        <div class="sh-kpi avail"><div class="sh-kpi-val" id="sidebarAvail">0</div><div class="sh-kpi-lbl">Avail</div></div>
        <div class="sh-kpi hold"><div class="sh-kpi-val" id="sidebarHold">0</div><div class="sh-kpi-lbl">Hold</div></div>
        <div class="sh-kpi sold"><div class="sh-kpi-val" id="sidebarSold">0</div><div class="sh-kpi-lbl">Sold</div></div>
    </div>
    
    <div class="sh-filter-row" style="flex-wrap: wrap; gap: 10px;">
        <div class="sh-tabs">
            <div class="sh-tab active" id="btnFilterAll" onclick="setStatusTab('All')">All</div>
            <div class="sh-tab" id="btnFilterAvail" onclick="setStatusTab('Available')">Available Only</div>
        </div>
        <div style="display: flex; gap: 8px;">
            <select id="agentSortDropdown" class="sh-select" style="margin: 0; padding: 6px 12px; width: auto; font-size: 0.8rem; border-radius: 20px;" onchange="applySidebarFilters()">
                <option value="default">Standard Order</option>
                <option value="price_asc">Price: Low to High</option>
                <option value="price_desc">Price: High to Low</option>
                <option value="floor_asc">Floor: Low to High</option>
                <option value="floor_desc">Floor: High to Low</option>
            </select>
            <button class="sh-pdf-btn" onclick="generateLivePricelist()">
                <i class="fas fa-file-pdf"></i> Pricelist
            </button>
        </div>
    </div>
    
    <div id="unitListContainer" class="sh-units"></div> 
  </div>
</div>

<div id="viewPlanModal" class="vanilla-modal">
    <div class="vanilla-modal-content large">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--sh-border); padding-bottom: 15px; margin-bottom: 15px;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <h4 style="margin: 0; color: #fff;"><i class="fas fa-map"></i> Floor Plan Viewer</h4>
                <span id="planCountDisplay" style="color: var(--sh-avail); font-weight: 800; font-size: 0.9rem;"></span>
            </div>
            <div style="display: flex; gap: 10px;">
                <button id="planPrevBtn" class="sh-btn sh-btn-warning" style="margin:0; padding: 6px 12px; width:auto; display:none;" onclick="changePlanSlide(-1)"><i class="fas fa-arrow-left"></i> Prev</button>
                <button id="planNextBtn" class="sh-btn sh-btn-warning" style="margin:0; padding: 6px 12px; width:auto; display:none;" onclick="changePlanSlide(1)">Next <i class="fas fa-arrow-right"></i></button>
                
                <button class="sh-btn sh-btn-info" style="margin:0; padding: 6px 12px; width:auto;" onclick="zoomPlan(-0.25)"><i class="fas fa-search-minus"></i></button>
                <button class="sh-btn sh-btn-info" style="margin:0; padding: 6px 12px; width:auto;" onclick="resetPlan()"><i class="fas fa-compress"></i></button>
                <button class="sh-btn sh-btn-info" style="margin:0; padding: 6px 12px; width:auto;" onclick="zoomPlan(0.25)"><i class="fas fa-search-plus"></i></button>
                <span class="vanilla-close" style="margin-left: 15px;" onclick="closePlanModal()">&times;</span>
            </div>
        </div>
        <div style="flex: 1; overflow: hidden; background: #e2e8f0; border-radius: 8px;">
            <div id="planTransformContainer" style="transition: transform 0.3s ease; width: 100%; height: 100%; transform-origin: top left;">
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
                    // Grouped by Locality
                    $stmt = $pdo->query("SELECT id, name, city FROM projects ORDER BY city ASC, name ASC");
                    $current_city = '';
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
                        $city = trim($row['city']) ? trim($row['city']) : 'Uncategorized';
                        if ($city !== $current_city) {
                            if ($current_city !== '') echo '</optgroup>';
                            echo '<optgroup label="' . htmlspecialchars($city) . '">';
                            $current_city = $city;
                        }
                        echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['name']) . '</option>'; 
                    }
                    if ($current_city !== '') echo '</optgroup>';
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
                    // Grouped by Locality
                    $stmt = $pdo->query("SELECT id, name, city FROM projects ORDER BY city ASC, name ASC");
                    $current_city = '';
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
                        $city = trim($row['city']) ? trim($row['city']) : 'Uncategorized';
                        if ($city !== $current_city) {
                            if ($current_city !== '') echo '</optgroup>';
                            echo '<optgroup label="' . htmlspecialchars($city) . '">';
                            $current_city = $city;
                        }
                        echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['name']) . '</option>'; 
                    }
                    if ($current_city !== '') echo '</optgroup>';
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
                <input type="text" name="floor_level" placeholder="e.g. -1, 0, 1, 2" class="sh-input" style="margin-bottom: 15px;">
            </div>
            
            <label class="sh-label">Media Files</label>
            <div class="sh-drop-zone" id="drop-zone">
                <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: var(--sh-text-muted); margin-bottom: 10px;"></i>
                <div style="font-weight: bold; color: #fff;">Drag & Drop media here</div>
                <div style="font-size: 0.8rem; color: var(--sh-text-muted);">or click to browse</div>
                <input type="file" name="media_file[]" id="mediaFileInput" multiple required style="display:none;">
            </div>
            <div id="file-list" style="margin: 15px 0; font-size: 0.8rem; color: var(--sh-avail); max-height: 100px; overflow-y: auto;"></div>

            <button type="submit" class="sh-btn sh-btn-success">Upload to Cloudflare</button>
        </form>
    </div>
</div>

<script>
    function toggleFloorInput() {
        const type = document.getElementById('mediaTypeSelect').value;
        document.getElementById('floorInputGroup').style.display = (type === 'Floor Plan') ? 'block' : 'none';
    }

    const userRole = '<?= $_SESSION['role'] ?>';
    const isManagerUser = ['admin', 'director', 'system_manager', 'sales_manager'].includes(userRole);
    let currentViewMode = isManagerUser ? 'manager' : 'agent';
    let lastLoadedProjects = [];

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
        if (lastLoadedProjects.length > 0) {
            loadMultipleProjects(lastLoadedProjects, false);
        }
    }

    const dropZone = document.getElementById('drop-zone');
    const mediaFileInput = document.getElementById('mediaFileInput');
    const fileList = document.getElementById('file-list');

    dropZone.addEventListener('click', () => mediaFileInput.click());
    dropZone.addEventListener('dragover', (e) => { 
        e.preventDefault(); 
        dropZone.style.borderColor = 'var(--sh-avail)'; 
    });
    dropZone.addEventListener('dragleave', () => { 
        dropZone.style.borderColor = 'var(--sh-border)'; 
    });
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = 'var(--sh-border)';
        mediaFileInput.files = e.dataTransfer.files;
        updateFileList();
    });
    mediaFileInput.addEventListener('change', updateFileList);

    function updateFileList() {
        fileList.innerHTML = Array.from(mediaFileInput.files)
            .map(f => `<div><i class="fas fa-check"></i> ${f.name}</div>`)
            .join('');
    }

    let currentGallery = [];
    let currentGalleryIndex = 0;

    function openGallery(index) {
        if(currentGallery.length === 0) return;
        currentGalleryIndex = index;
        document.getElementById('sh-lightbox').style.display = 'flex';
        renderGallerySlide();
    }

    function closeGallery() {
        document.getElementById('sh-lightbox').style.display = 'none';
        document.getElementById('sh-lightbox-media').innerHTML = ''; 
    }

    function changeSlide(dir) {
        currentGalleryIndex += dir;
        if (currentGalleryIndex < 0) currentGalleryIndex = currentGallery.length - 1;
        if (currentGalleryIndex >= currentGallery.length) currentGalleryIndex = 0;
        renderGallerySlide();
    }

    function renderGallerySlide() {
        const container = document.getElementById('sh-lightbox-media');
        const media = currentGallery[currentGalleryIndex];
        
        if (media.type === 'video') {
            container.innerHTML = `<video src="${media.src}" controls autoplay style="max-width:90vw; max-height:80vh; border-radius:8px; box-shadow: 0 10px 30px rgba(0,0,0,0.8);"></video>`;
        } else {
            container.innerHTML = `<img src="${media.src}" style="max-width:90vw; max-height:80vh; border-radius:8px; object-fit:contain; box-shadow: 0 10px 30px rgba(0,0,0,0.8);">`;
        }
        document.getElementById('sh-lightbox-counter').innerText = `${currentGalleryIndex + 1} / ${currentGallery.length}`;
    }

    function setStatusTab(filterType) {
        document.getElementById('btnFilterAll').className = filterType === 'All' ? 'sh-tab active' : 'sh-tab';
        document.getElementById('btnFilterAvail').className = filterType === 'Available' ? 'sh-tab active' : 'sh-tab';
        applySidebarFilters();
    }

    function applySidebarFilters() {
        const typeFilter = document.getElementById('typeFilter').value.toLowerCase();
        const minPrice = parseFloat(document.getElementById('minBudget').value) || 0;
        const maxPrice = parseFloat(document.getElementById('maxBudget').value) || Infinity;
        const statusFilter = document.getElementById('btnFilterAll').classList.contains('active') ? 'All' : 'Available';
        const sortMode = document.getElementById('agentSortDropdown').value;

        const cards = document.querySelectorAll('.sh-card');
        cards.forEach(card => {
            const rawType = (card.getAttribute('data-type') || '').toLowerCase();
            const cardStatus = card.getAttribute('data-status');
            
            let pureNumericPrice = 0;
            const priceMatch = card.innerText.match(/€[\d,]+/);
            if (priceMatch) {
                pureNumericPrice = parseFloat(priceMatch[0].replace(/[€,]/g, ''));
            }

            let show = true;
            if (typeFilter !== 'all' && !rawType.includes(typeFilter)) show = false;
            if (pureNumericPrice > 0 && (pureNumericPrice < minPrice || pureNumericPrice > maxPrice)) show = false; 
            if (statusFilter === 'Available' && cardStatus !== 'Available' && cardStatus !== 'BOM') show = false;

            card.style.display = show ? 'block' : 'none';
        });

        const groups = document.querySelectorAll('.project-unit-group');
        groups.forEach(group => {
            const groupCards = Array.from(group.querySelectorAll('.sh-card'));
            
            groupCards.sort((a, b) => {
                if (sortMode === 'default') {
                    return parseInt(a.getAttribute('data-index') || 0) - parseInt(b.getAttribute('data-index') || 0);
                }

                if (sortMode === 'price_asc' || sortMode === 'price_desc') {
                    let priceA = 0; let priceB = 0;
                    const m1 = a.innerText.match(/€[\d,]+/); 
                    if(m1) priceA = parseFloat(m1[0].replace(/[€,]/g, ''));
                    
                    const m2 = b.innerText.match(/€[\d,]+/); 
                    if(m2) priceB = parseFloat(m2[0].replace(/[€,]/g, ''));

                    return sortMode === 'price_asc' ? priceA - priceB : priceB - priceA;
                }

                if (sortMode === 'floor_asc' || sortMode === 'floor_desc') {
                    // We extract the numerical floor level directly from the injected HTML attribute
                    let floorAStr = a.getAttribute('data-floor');
                    let floorBStr = b.getAttribute('data-floor');
                    
                    let floorA = floorAStr ? parseInt(floorAStr, 10) : 0;
                    let floorB = floorBStr ? parseInt(floorBStr, 10) : 0;

                    return sortMode === 'floor_asc' ? floorA - floorB : floorB - floorA;
                }
                
                return 0;
            });

            groupCards.forEach(c => group.appendChild(c)); 
        });
    }

    function showToast(message, type = 'success') {
        const container = document.getElementById('sh-toast-container');
        const toast = document.createElement('div');
        toast.className = 'sh-toast';
        toast.style.background = type === 'success' ? '#10B981' : '#EF4444';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        toast.innerHTML = `<i class="fas ${icon} fa-lg"></i> ${message}`;
        container.appendChild(toast);
        setTimeout(() => { 
            toast.style.opacity = '0'; 
            setTimeout(() => toast.remove(), 300); 
        }, 3000);
    }

    // MULTI-PLAN ENGINE UPDATES
    let currentPlanZoom = 1;
    let currentPlans = [];
    let currentPlanIndex = 0;

    function openPlanModal(urlsStr) { 
        currentPlans = urlsStr.split(',');
        currentPlanIndex = 0;
        document.getElementById('viewPlanModal').style.display = 'block'; 
        renderPlanIframe();
    }

    function renderPlanIframe() {
        document.getElementById('planIframe').src = currentPlans[currentPlanIndex];
        resetPlan();
        
        let prevBtn = document.getElementById('planPrevBtn');
        let nextBtn = document.getElementById('planNextBtn');
        let countDisp = document.getElementById('planCountDisplay');
        
        if (currentPlans.length > 1) {
            prevBtn.style.display = 'inline-flex';
            nextBtn.style.display = 'inline-flex';
            countDisp.innerText = `Plan ${currentPlanIndex + 1} of ${currentPlans.length}`;
        } else {
            prevBtn.style.display = 'none';
            nextBtn.style.display = 'none';
            countDisp.innerText = '';
        }
    }

    function changePlanSlide(dir) {
        currentPlanIndex += dir;
        if (currentPlanIndex < 0) currentPlanIndex = currentPlans.length - 1;
        if (currentPlanIndex >= currentPlans.length) currentPlanIndex = 0;
        renderPlanIframe();
    }

    function closePlanModal() { 
        document.getElementById('viewPlanModal').style.display = 'none'; 
        document.getElementById('planIframe').src = ''; 
    }
    
    function zoomPlan(amount) { 
        currentPlanZoom = Math.max(0.25, Math.min(4, currentPlanZoom + amount)); 
        document.getElementById('planTransformContainer').style.transform = `scale(${currentPlanZoom})`; 
    }
    
    function resetPlan() { 
        currentPlanZoom = 1; 
        document.getElementById('planTransformContainer').style.transform = `scale(1)`; 
    }
    
    function closeSidebar() { 
        document.getElementById('custom-sidebar').classList.remove('open'); 
        lastLoadedProjects = []; 
    }

    let mapProjectsData = {};
    const defaultCenter = [14.38, 35.92];
    const defaultZoom = 9.5;
    const defaultPitch = 25;

    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw'; 
    
    const map = new mapboxgl.Map({ 
        container: 'sales-map', 
        style: 'mapbox://styles/mapbox/satellite-streets-v12', 
        center: defaultCenter, 
        zoom: defaultZoom, 
        pitch: defaultPitch, 
        bearing: 0 
    });
    map.addControl(new mapboxgl.NavigationControl(), 'bottom-right');

    const draw = new MapboxDraw({ 
        displayControlsDefault: false, 
        controls: { polygon: true, trash: true }, 
        defaultMode: 'simple_select' 
    });
    map.addControl(draw, 'top-right'); 

    map.on('draw.create', filterMapByPolygon); 
    map.on('draw.delete', filterMapByPolygon); 
    map.on('draw.update', filterMapByPolygon);

    function filterMapByPolygon() {
        const data = draw.getAll();
        if (data.features.length > 0) {
            const polygon = data.features[0];
            let projectsInPolygon = [];
            
            Object.values(mapProjectsData).forEach(project => {
                if (project.markerEl) {
                    const pt = turf.point([project.longitude, project.latitude]);
                    const isInside = turf.booleanPointInPolygon(pt, polygon);
                    project.markerEl.style.display = isInside ? 'block' : 'none';
                    if (isInside) projectsInPolygon.push(project);
                }
            });
            
            if (projectsInPolygon.length > 0) {
                loadMultipleProjects(projectsInPolygon, false);
            } else {
                closeSidebar();
            }
        } else {
            Object.values(mapProjectsData).forEach(p => { 
                if(p.markerEl) p.markerEl.style.display = 'block'; 
            });
            closeSidebar();
        }
    }

    function resetMap() {
        map.flyTo({ center: defaultCenter, zoom: defaultZoom, pitch: defaultPitch, bearing: 0, duration: 1500 });
        if (draw) draw.deleteAll();
        
        document.getElementById('typeFilter').value = 'all';
        document.getElementById('minBudget').value = '';
        document.getElementById('maxBudget').value = '';
        document.getElementById('agentSortDropdown').value = 'default';
        
        setStatusTab('All');
        filterMapByPolygon();
        closeSidebar();
    }

    map.on('load', () => {
        fetch('api/get_sales_map_data.php')
        .then(r => r.json())
        .then(data => {
            if(data.success && data.data) {
                const dropdown = document.getElementById('projectJumpDropdown');
                
                data.data.forEach(project => {
                    if(project.latitude && project.longitude) {
                        mapProjectsData[project.project_id] = project;
                        dropdown.add(new Option(project.project_name, project.project_id));
                        
                        const el = document.createElement('div');
                        el.style.cssText = `background-color: ${project.available_units > 0 ? '#10B981' : '#EF4444'}; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 10px rgba(0,0,0,0.8); cursor: pointer;`;
                        
                        new mapboxgl.Marker(el).setLngLat([project.longitude, project.latitude]).addTo(map);
                        project.markerEl = el; 
                        
                        el.addEventListener('click', () => loadMultipleProjects([project], true));
                    }
                });
            }
        });
    });

    function jumpToSelectedProject(projectId) { 
        if(projectId && mapProjectsData[projectId]) {
            loadMultipleProjects([mapProjectsData[projectId]], true); 
        }
    }

    async function loadMultipleProjects(projects, shouldPan = false) {
        if (projects.length === 0) return;
        lastLoadedProjects = projects;

        if (shouldPan && projects.length === 1) {
            map.panTo([projects[0].longitude, projects[0].latitude], { duration: 1000 });
        }
        
        document.getElementById('sidebarProjectName').innerText = projects.length === 1 ? projects[0].project_name : `Selected Area (${projects.length} Projects)`;
        document.getElementById('sidebarProjectName').setAttribute('data-pid', projects.length === 1 ? projects[0].project_id : 'multi');
        
        document.getElementById('custom-sidebar').classList.add('open');
        document.getElementById('unitListContainer').innerHTML = '<div class="text-center p-4 text-light"><div class="spinner-border text-info"></div><div class="mt-2">Loading units...</div></div>';

        let allHtml = '';
        let totalAvail = 0, totalHold = 0, totalSold = 0;
        let allMedia = { renders: [], videos: [] };

        const promises = projects.map(p => fetch('api/get_project_units.php?project_id=' + p.project_id).then(r => r.json()));
        const results = await Promise.all(promises);

        results.forEach((unitData, index) => {
            if(unitData.success) {
                const p = projects[index];
                totalAvail += parseInt(p.available_units || 0);
                totalHold += parseInt(p.held_units || 0);
                totalSold += parseInt(p.sold_units || 0);

                if (projects.length > 1) {
                    allHtml += `<div class="sh-project-divider"><i class="fas fa-building"></i> ${p.project_name}</div>`;
                }
                
                // Wrap in a group container to preserve multi-project integrity when sorting
                allHtml += `<div class="project-unit-group">` + processUnitHtmlSafely(unitData.html) + `</div>`;

                if (unitData.media) {
                    if (unitData.media.renders) allMedia.renders.push(...unitData.media.renders);
                    if (unitData.media.videos) allMedia.videos.push(...unitData.media.videos);
                }
            }
        });

        document.getElementById('sidebarAvail').innerText = totalAvail;
        document.getElementById('sidebarHold').innerText = totalHold;
        document.getElementById('sidebarSold').innerText = totalSold;

        document.getElementById('unitListContainer').innerHTML = '';
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = allHtml;
        Array.from(tempDiv.children).forEach(child => document.getElementById('unitListContainer').appendChild(child));
        
        applySidebarFilters();

        currentGallery = [];
        if (allMedia.videos) allMedia.videos.forEach(v => currentGallery.push({type: 'video', src: v}));
        if (allMedia.renders) allMedia.renders.forEach(r => currentGallery.push({type: 'image', src: r}));

        let mediaHtml = '';
        if (currentGallery.length > 0) {
            let coverHtml = currentGallery[0].type === 'video' ?
                `<video src="${currentGallery[0].src}" style="width:100%; height:200px; object-fit:cover; border-radius:12px; cursor:pointer; filter: brightness(0.8);" onclick="openGallery(0)"></video>` :
                `<img src="${currentGallery[0].src}" style="width:100%; height:200px; object-fit:cover; border-radius:12px; cursor:pointer; filter: brightness(0.8);" onclick="openGallery(0)">`;

            mediaHtml = `
            <div style="padding:20px; position:relative; width: 100%;">
                ${coverHtml}
                <div style="position:absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); pointer-events: none;">
                    <i class="fas fa-search-plus" style="font-size: 3rem; color: rgba(255,255,255,0.5);"></i>
                </div>
                <div style="position:absolute; bottom:30px; right:30px; background:var(--sh-sold); color:#fff; padding:6px 12px; border-radius:20px; font-size:0.8rem; font-weight:bold; pointer-events:none; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
                    <i class="fas fa-images"></i> ${currentGallery.length} Media
                </div>
            </div>`;
        } else {
            mediaHtml = `
            <div style="text-align:center; padding:40px; color:var(--sh-text-muted);">
                <i class="fas fa-image fa-3x mb-2"></i>
                <div style="font-size:0.85rem;">No media uploaded</div>
            </div>`;
        }
        document.getElementById('sidebarMediaContainer').innerHTML = mediaHtml;
    }

    function processUnitHtmlSafely(rawHtml) {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = rawHtml;
        const cards = tempDiv.querySelectorAll('.card, .unit-card');
        
        let indexCounter = 0;
        
        cards.forEach(card => {
            card.setAttribute('data-index', indexCounter++); // Needed to restore default order
            
            // Extract the floor level dynamically so JS sorting functions properly
            let floorLevel = card.getAttribute('data-floor');
            if (!floorLevel) {
                let textMatch = card.innerText.match(/(Level|Floor)\s*[:-]?\s*([-]?\d+)/i);
                if (textMatch) floorLevel = textMatch[2];
            }
            card.setAttribute('data-floor', floorLevel || '0');
            
            let unitId = card.getAttribute('data-unit-id');
            if (!unitId) {
                const sel = card.querySelector('select');
                if (sel && sel.getAttribute('onchange')) { 
                    const m = sel.getAttribute('onchange').match(/\d+/); 
                    if (m) unitId = m[0]; 
                }
            }
            if (!unitId) unitId = Math.floor(Math.random() * 1000000); 

            let rawStatus = card.getAttribute('data-status') || card.querySelector('.badge')?.innerText || 'Available';
            let status = rawStatus.trim();
            if (status === 'Reserved') status = 'Proceeding';
            if (status === 'Sold POS' || status === 'Sold Contract') status = 'Sold';

            let unitTypeAttr = card.getAttribute('data-type') || '';

            card.className = 'sh-card';
            card.setAttribute('data-status', status);
            card.setAttribute('data-type', unitTypeAttr);
            card.style.marginBottom = '15px';

            if (status.includes('Available') || status === 'BOM') {
                card.style.borderLeft = '4px solid var(--sh-avail)';
            } else if (status.includes('Proceeding')) {
                card.style.borderLeft = '4px solid var(--sh-proc)';
            } else if (status.includes('Sold')) {
                card.style.borderLeft = '4px solid var(--sh-sold)';
            } else if (status === 'Resale') {
                card.style.borderLeft = '4px solid var(--sh-resale)';
            } else {
                card.style.borderLeft = '4px solid var(--sh-hold)';
            }

            if (currentViewMode === 'agent' && status.includes('Sold')) {
                const walker = document.createTreeWalker(card, NodeFilter.SHOW_TEXT, null, false);
                let nodesToReplace = [];
                let node;
                while (node = walker.nextNode()) { 
                    if (node.nodeValue.includes('€') || node.nodeValue.includes('POA')) {
                        nodesToReplace.push(node); 
                    }
                }
                nodesToReplace.forEach(n => {
                    const span = document.createElement('span'); 
                    span.className = 'sh-price-hidden'; 
                    span.innerText = '🔒 Price Confidential';
                    n.parentNode.replaceChild(span, n);
                });
            }

            let resalePrice = card.querySelector('.resale-input')?.value || card.querySelector('input[placeholder*="Resale"]')?.value || '';
            const oldControls = card.querySelector('select[onchange^="managerUpdateStatus"]')?.parentNode || card.querySelector('.action-buttons') || card.querySelector('form');
            card.querySelectorAll('select, input, button[onclick*="togglePriceEdit"], .resale-input').forEach(el => el.remove());

            const controlWrapper = document.createElement('div');
            controlWrapper.style.marginTop = '15px'; 
            controlWrapper.style.paddingTop = '15px'; 
            controlWrapper.style.borderTop = '1px solid var(--sh-border)';

            let controls = `<div style="font-weight:bold; color:var(--sh-text-muted); font-size:0.85rem; text-transform:uppercase; text-align:center; margin-bottom:10px;">Status: <span style="color:#fff;">${status}</span></div>`;

            if (currentViewMode === 'manager') {
                if (status === 'Available' || status === 'BOM') {
                    controls += `
                        <div style="display:flex; gap:10px; margin-bottom:10px;">
                            <button class="sh-btn sh-btn-warning" style="margin:0;" onclick="holdProperty(${unitId})"><i class="fas fa-pause"></i> Place on Hold</button>
                            <button class="sh-btn" style="margin:0; background: rgba(168,85,247,0.1); color: #a855f7; border: 1px solid rgba(168,85,247,0.3);" onclick="markResale(${unitId})"><i class="fas fa-exchange-alt"></i> Resale</button>
                        </div>`;
                } else if (status === 'On Hold' || status === 'Proceeding') {
                     controls += `<button class="sh-btn sh-btn-success" style="margin-bottom:10px;" onclick="releaseHoldFromLedger(${unitId})"><i class="fas fa-unlock"></i> Release Hold</button>`;
                } else if (status === 'Resale') {
                     controls += `
                        <button class="sh-btn sh-btn-success" style="margin-bottom:10px;" onclick="cancelResale(${unitId})"><i class="fas fa-undo"></i> Cancel Resale</button>
                        <input type="number" step="0.01" class="sh-resale-input" id="resale_input_${unitId}" placeholder="Resale Asking Price (€)" value="${resalePrice}" onblur="updateResalePrice(${unitId}, this.value)">`;
                }

                controls += `
                    <button class="sh-btn sh-btn-warning" style="background:transparent; border:1px dashed var(--sh-border);" onclick="togglePriceEdit(${unitId})">✎ Modify Pricing</button>
                    <div id="price_edit_${unitId}" style="display:none; background:rgba(0,0,0,0.2); padding:10px; border-radius:8px; border:1px solid var(--sh-border); margin-top:10px;">
                        <label class="sh-label">Shell Price (€)</label>
                        <input type="number" id="inp_sh_${unitId}" class="sh-input" style="margin-bottom:10px;">
                        <label class="sh-label">Finishes Price (€)</label>
                        <input type="number" id="inp_fn_${unitId}" class="sh-input" style="margin-bottom:10px;">
                        <button class="sh-btn sh-btn-success" onclick="savePrice(${unitId})">Save Prices</button>
                    </div>`;
            } else {
                if (status === 'Available' || status === 'BOM') {
                    controls += `
                    <div style="display:flex; gap:10px;">
                        <button class="sh-btn sh-btn-warning" onclick="holdProperty(${unitId})"><i class="fas fa-pause"></i> Place on Hold</button>
                    </div>`;
                }
            }

            controlWrapper.innerHTML = controls;
            if (oldControls) { 
                oldControls.parentNode.replaceChild(controlWrapper, oldControls); 
            } else { 
                card.appendChild(controlWrapper); 
            }
        });

        return tempDiv.innerHTML;
    }

    function markResale(propertyId) { 
        if(!confirm("Mark this unit as a 3rd Party Resale?")) return; 
        sendStatusToServer(propertyId, 'Resale', null); 
    }
    
    function cancelResale(propertyId) { 
        if(!confirm("Cancel Resale and revert unit to Available?")) return; 
        sendStatusToServer(propertyId, 'Available', null); 
    }
    
    function updateResalePrice(propertyId, price) { 
        if(!price) return; 
        sendStatusToServer(propertyId, 'Resale', price); 
    }

    function sendStatusToServer(propertyId, newStatus, resalePrice = null) {
        let formData = new FormData(); 
        formData.append('property_id', propertyId); 
        formData.append('new_status', newStatus); 
        
        if (resalePrice) formData.append('resale_price', resalePrice);
        
        fetch('api/manager_update_status.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) { 
                showToast(`Status updated successfully!`, 'success'); 
                if (lastLoadedProjects.length > 0) {
                    setTimeout(() => loadMultipleProjects(lastLoadedProjects, false), 500); 
                }
            } else { 
                showToast("Error: " + data.message, 'error'); 
            }
        });
    }

    function togglePriceEdit(id) { 
        const e = document.getElementById('price_edit_' + id); 
        e.style.display = e.style.display === 'none' ? 'block' : 'none'; 
    }
    
    function savePrice(id) {
        const shell = document.getElementById('inp_sh_' + id).value; 
        const fin = document.getElementById('inp_fn_' + id).value;
        
        let formData = new FormData(); 
        formData.append('property_id', id); 
        formData.append('shell_price', shell); 
        formData.append('finishes_price', fin);
        
        fetch('api/update_unit_price.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) { 
                showToast("Price updated!", "success"); 
                if (lastLoadedProjects.length > 0) {
                    setTimeout(() => loadMultipleProjects(lastLoadedProjects, false), 500); 
                }
            } else { 
                showToast("Error: " + data.message, "error"); 
            }
        });
    }

    function generateLivePricelist() { 
        const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid'); 
        if(pid === 'multi') {
            alert("Please select a single project pin to generate a pricelist."); 
        } else if(pid) {
            window.open('print_pricelist.php?project_id=' + pid, '_blank'); 
        }
    }
    
    function holdProperty(propertyId) {
        if(!confirm("Are you sure you want to put this unit on hold?")) return;
        
        let formData = new FormData(); 
        formData.append('action', 'hold_property'); 
        formData.append('property_id', propertyId);
        
        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) { 
                showToast("Put on hold!", "success"); 
                if (lastLoadedProjects.length > 0) {
                    setTimeout(() => loadMultipleProjects(lastLoadedProjects, false), 500); 
                }
            } else { 
                showToast("Error: " + data.message, "error"); 
            }
        })
        .catch(err => showToast("System Error: " + err.message, "error"));
    }

    document.getElementById('uploadFrameForm').addEventListener('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this); 
        this.querySelector('button[type="submit"]').disabled = true;
        
        fetch('api/upload_project_frame.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) { 
                alert(data.message); 
                location.reload(); 
            } else { 
                alert('Error: ' + data.message); 
                this.querySelector('button').disabled = false; 
            }
        });
    });

    document.getElementById('uploadMediaForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        if(mediaFileInput.files.length === 0) { 
            alert("Please select at least one file to upload."); 
            return; 
        }
        
        let btn = this.querySelector('button[type="submit"]'); 
        let originalText = btn.innerHTML; 
        btn.innerHTML = 'Connecting...'; 
        btn.disabled = true;

        try {
            for (let i = 0; i < mediaFileInput.files.length; i++) {
                let file = mediaFileInput.files[i];
                btn.innerHTML = `Uploading (${i+1}/${mediaFileInput.files.length})...`;

                let authData = new FormData(); 
                authData.append('action', 'get_upload_url'); 
                authData.append('filename', file.name); 
                authData.append('mime_type', file.type || 'application/octet-stream');
                
                let authRes = await fetch('api/upload_sales_media.php', { method: 'POST', body: authData });
                let authJson = await authRes.json();
                if(!authJson.success) throw new Error(authJson.message);

                await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest(); 
                    xhr.open('PUT', authJson.url, true); 
                    xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                    
                    xhr.onload = function() { 
                        if (xhr.status >= 200 && xhr.status < 300) { 
                            resolve(); 
                        } else { 
                            reject(new Error('Cloudflare rejected the upload.')); 
                        } 
                    };
                    xhr.onerror = () => reject(new Error('Network Error during upload.')); 
                    xhr.send(file);
                });

                let dbData = new FormData(this); 
                dbData.delete('media_file[]'); 
                dbData.append('action', 'save_record'); 
                dbData.append('file_key', authJson.key); 
                dbData.append('filename', file.name);
                
                let dbRes = await fetch('api/upload_sales_media.php', { method: 'POST', body: dbData });
                let dbJson = await dbRes.json();
                if(!dbJson.success) throw new Error(dbJson.message);
            }
            
            alert(`Successfully uploaded ${mediaFileInput.files.length} file(s)!`); 
            location.reload(); 
            
        } catch (err) { 
            alert('Error: ' + err.message); 
            btn.innerHTML = originalText; 
            btn.disabled = false; 
        }
    });

    function processDailySync(input) {
        if (input.files.length === 0) return;
        const file = input.files[0]; 
        const formData = new FormData(); 
        formData.append('sync_csv', file);
        
        showToast("Syncing data... Please wait.", "success");
        
        fetch('api/sync_daily_report.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) { 
                if (data.not_found && data.not_found.length > 0) { 
                    showTranslationModal(data.message, data.not_found, data.all_db_units); 
                } else { 
                    alert(data.message + "\n\n100% Match Rate!"); 
                    location.reload(); 
                } 
            } else { 
                alert("Error: " + data.message); 
            }
        })
        .catch(err => { 
            console.error(err); 
            alert("A network error occurred during sync."); 
        });
        
        input.value = ""; 
    }

    function showTranslationModal(successMessage, missingItems, dbUnits) {
        let modalHtml = `
        <div id="translatorModal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; display:flex; align-items:center; justify-content:center;">
            <div style="background:var(--sh-card-bg); padding:25px; border-radius:12px; max-width:800px; width:90%; max-height:85vh; overflow-y:auto; border:1px solid var(--sh-border); box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                <h2 style="margin-top:0; color:var(--sh-text);">Sync Complete, but some items need mapping</h2>
                <p style="color:var(--sh-success); font-weight:bold; font-size:1.1rem;">${successMessage}</p>
                <p style="color:var(--sh-text-muted); font-size:0.9rem;">The system couldn't automatically match the items below. Link them to the correct Database Unit, and the system will remember this translation for next time.</p>
                
                <table style="width:100%; border-collapse: collapse; margin-top:15px;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--sh-border); text-align:left;">
                            <th style="padding:10px; color:var(--sh-text);">Unrecognized Name from CSV</th>
                            <th style="padding:10px; color:var(--sh-text);">Link to Database Unit</th>
                            <th style="padding:10px; color:var(--sh-text);">Action</th>
                        </tr>
                    </thead>
                    <tbody id="translatorTableBody">
                    </tbody>
                </table>
                
                <div style="margin-top: 25px; text-align: right;">
                    <button class="sh-btn sh-btn-success" onclick="location.reload();">Done & Refresh Map</button>
                </div>
            </div>
        </div>`;

        let oldModal = document.getElementById('translatorModal'); 
        if (oldModal) oldModal.remove();
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        let optionsHtml = `<option value="">-- Select DB Unit --</option>`;
        dbUnits.forEach(u => { 
            optionsHtml += `<option value="${u.id}">${u.project_name} - ${u.unit_name}</option>`; 
        });

        const tbody = document.getElementById('translatorTableBody');
        missingItems.forEach((item, index) => {
            let tr = document.createElement('tr'); 
            tr.style.borderBottom = "1px solid var(--sh-border)";
            tr.innerHTML = `
                <td id="missing_name_${index}" style="padding:10px; color:var(--sh-danger); font-weight:bold; font-size:0.9rem;">${item.csv_name}</td>
                <td style="padding:10px;">
                    <select id="trans_select_${index}" class="sh-input" style="width:100%; margin:0;">
                        ${optionsHtml}
                    </select>
                </td>
                <td style="padding:10px; white-space: nowrap;">
                    <button class="sh-btn sh-btn-info" style="margin:0; padding: 6px 12px;" onclick="saveTranslation(${index}, this, false)">Save Link</button>
                    <button class="sh-btn sh-btn-warning" style="margin:0 0 0 5px; padding: 6px 12px; background: #6c757d; border:none; color:white;" onclick="saveTranslation(${index}, this, true)">Ignore</button>
                </td>`;
            tbody.appendChild(tr);
        });
    }

    function saveTranslation(index, btnElement, isIgnore) {
        let csvName = document.getElementById(`missing_name_${index}`).innerText;
        let unitId = -1; 
        const selectEl = document.getElementById(`trans_select_${index}`);
        
        if (!isIgnore) { 
            unitId = selectEl.value; 
            if (!unitId) { 
                alert("Please select a unit from the dropdown first."); 
                return; 
            } 
        }

        const originalText = btnElement.innerHTML; 
        btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; 
        btnElement.disabled = true;
        
        const formData = new FormData(); 
        formData.append('action', 'save_translation'); 
        formData.append('csv_name', csvName); 
        formData.append('unit_id', unitId);

        fetch('api/sync_daily_report.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btnElement.className = "sh-btn sh-btn-success"; 
                btnElement.innerHTML = isIgnore ? '<i class="fas fa-eye-slash"></i> Ignored!' : '<i class="fas fa-check"></i> Linked!';
                selectEl.disabled = true; 
                
                const siblingBtn = isIgnore ? btnElement.previousElementSibling : btnElement.nextElementSibling;
                if (siblingBtn) { 
                    siblingBtn.style.opacity = '0.5'; 
                    siblingBtn.disabled = true; 
                }
            } else { 
                alert("Failed to save action."); 
                btnElement.innerHTML = originalText; 
                btnElement.disabled = false; 
            }
        });
    }

    function openHoldLedger() {
        fetch('api/get_holds_ledger.php?_t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (!data.success) { 
                showToast("Error: " + (data.message || "Could not load ledger"), "error"); 
                return; 
            }
            
            let uniqueProjects = [...new Set(data.holds.map(h => h.project_name))].sort();
            let projectOptions = uniqueProjects.map(p => `<option value="${p}">${p}</option>`).join('');

            let html = `
            <div>
                <div style="position: sticky; top: 0; background: var(--sh-bg-panel); padding-bottom: 15px; z-index: 10; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--sh-border); margin-bottom: 15px;">
                    <h3 style="color: #fff; margin: 0;">${data.role === 'sales_agent' ? 'My Active Holds' : 'Global Holds Ledger'}</h3>
                    <select id="ledgerProjectFilter" class="sh-select" style="width: auto; margin: 0; padding: 6px 12px; font-weight: bold; cursor: pointer;" onchange="filterLedgerTable()">
                        <option value="All">All Projects</option>
                        ${projectOptions}
                    </select>
                </div>
                <table class="table" style="width: 100%; text-align: left; border-collapse: collapse; color: #fff;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--sh-border);">
                            <th style="padding: 10px;">Project</th>
                            <th style="padding: 10px;">Unit</th>
                            ${data.role !== 'sales_agent' ? '<th style="padding: 10px;">Agent</th>' : ''}
                            <th style="padding: 10px;">Expires In</th>
                            <th style="padding: 10px;">Exact Expiry Date</th>
                            <th style="padding: 10px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>`;

            if (data.holds.length === 0) { 
                html += `<tr><td colspan="6" style="padding: 15px; text-align:center; color: var(--sh-text-muted);">No properties currently on hold.</td></tr>`; 
            } else {
                data.holds.forEach(hold => {
                    let warningStyle = hold.is_expiring_soon ? 'color: var(--sh-danger); font-weight: bold;' : 'color: var(--sh-text-main);';
                    let warningIcon = hold.is_expiring_soon ? '<i class="fas fa-exclamation-triangle"></i> ' : '';
                    let agentName = hold.is_legacy ? '<span style="color:var(--sh-text-muted); font-style:italic;">Legacy/System</span>' : `${hold.first_name} ${hold.last_name}`;
                    let timeDisplay = hold.is_legacy ? '<span style="background: rgba(168, 85, 247, 0.2); color: #a855f7; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold;"><i class="fas fa-infinity"></i> Legacy</span>' : `<span style="${warningStyle}">${warningIcon}${hold.hours_remaining} Hours</span>`;
                    let expiryDisplay = hold.is_legacy ? '<span style="color:var(--sh-text-muted);">N/A</span>' : new Date(hold.hold_expiry).toLocaleString();

                    html += `
                    <tr class="ledger-row" data-project="${hold.project_name}" style="border-bottom: 1px solid var(--sh-border-light);">
                        <td style="padding: 12px 10px;">${hold.project_name}</td>
                        <td style="padding: 12px 10px;"><strong>${hold.unit_name}</strong></td>
                        ${data.role !== 'sales_agent' ? `<td style="padding: 12px 10px;">${agentName}</td>` : ''}
                        <td style="padding: 12px 10px;">${timeDisplay}</td>
                        <td style="padding: 12px 10px;">${expiryDisplay}</td>
                        <td style="padding: 12px 10px; text-align: right;">
                            <button class="sh-btn sh-btn-success" style="margin: 0; padding: 6px 12px; width: auto; display: inline-block; font-size: 0.8rem;" onclick="releaseHoldFromLedger(${hold.id})"><i class="fas fa-unlock"></i> Release</button>
                        </td>
                    </tr>`;
                });
            }
            
            html += `</tbody></table></div>`;
            document.getElementById('holdLedgerContent').innerHTML = html; 
            document.getElementById('holdLedgerModal').style.display = 'block';
        });
    }

    function filterLedgerTable() { 
        let filter = document.getElementById('ledgerProjectFilter').value; 
        let rows = document.querySelectorAll('.ledger-row'); 
        
        rows.forEach(row => { 
            if (filter === 'All' || row.getAttribute('data-project') === filter) { 
                row.style.display = ''; 
            } else { 
                row.style.display = 'none'; 
            } 
        }); 
    }

    function releaseHoldFromLedger(propertyId) {
        if (!confirm("Are you sure you want to release this hold? The unit will immediately become Available.")) return;
        
        let formData = new FormData(); 
        formData.append('action', 'release_hold'); 
        formData.append('property_id', propertyId);
        
        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => { 
            if(data.success) { 
                showToast("Hold released successfully!", "success"); 
                openHoldLedger(); 
                if (lastLoadedProjects.length > 0) {
                    setTimeout(() => loadMultipleProjects(lastLoadedProjects, false), 500); 
                }
            } else { 
                showToast("Error: " + data.message, "error"); 
            } 
        });
    }
</script>

<?php require_once 'footer.php'; ?>
