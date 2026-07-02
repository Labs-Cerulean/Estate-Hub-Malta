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
    
    // --- 1. STRICT ENUM FOR DATA INTEGRITY ---
    $pdo->exec("ALTER TABLE sales_properties MODIFY COLUMN status ENUM(
        'Available', 
        'On Hold', 
        'Proceeding', 
        'Proceeding Pending Approval', 
        'Sold', 
        'Sold - POS', 
        'POS Pending Approval', 
        'Sold - Contract', 
        'Contract Pending Approval', 
        'Sold Pending Approval', 
        'Resale', 
        'BOM'
    ) DEFAULT 'Available'");
    
    // --- 2. VARCHAR FOR SYSTEM LOGS (To support 'Deleted', 'Price Override', etc) ---
    $pdo->exec("ALTER TABLE sales_property_logs MODIFY COLUMN old_status VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE sales_property_logs MODIFY COLUMN new_status VARCHAR(50) DEFAULT NULL");
    
} catch(PDOException $e) { /* Silently ignore if columns already exist */ }
require_once 'header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/3.6.2/maplibre-gl.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/3.6.2/maplibre-gl.css" rel="stylesheet" />

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

   /* =========================================
       MOBILE VIEW OPTIMIZATION
       ========================================= */
    @media (max-width: 768px) {
        /* 1. Hide the main website header completely */
        header, #header, .navbar, .topbar, .top-header { display: none !important; }
        
        /* 2. Hide the map entirely & transform the wrapper */
        #sales-map, .mapboxgl-control-container { display: none !important; }
        
        #sh-wrapper {
            height: auto;
            min-height: 100vh;
            overflow-y: auto;
            background: var(--sh-bg-base);
        }
        
        /* The floating overlay converts into a standard, full-screen dashboard page */
        .sh-overlay {
            position: relative;
            top: 0;
            left: 0;
            width: 100%;
            height: auto;
            max-height: none;
            border-radius: 0;
            background: transparent; /* Blends perfectly into the background */
            box-shadow: none;
            border: none;
            padding: 20px 15px;
        }

        /* Hide map-specific buttons (Reset Map & Polygon hints) */
        .hide-map-controls-mobile { display: none !important; }

        /* 3. Make the 'View Holds Ledger' Modal Mobile Friendly */
        .vanilla-modal-content {
            width: 95% !important;
            margin: 2.5vh auto !important;
            padding: 15px !important;
            max-height: 95vh !important;
        }
        .vanilla-modal-content.large {
            width: 100% !important;
            height: 100vh !important;
            margin: 0 !important;
            border-radius: 0 !important;
            max-height: 100vh !important;
            max-width: 100% !important;
            border: none !important;
        }
        
        /* Ensure the table scrolls horizontally and doesn't get crushed */
        #holdLedgerContent {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        #holdLedgerContent table {
            white-space: nowrap; /* Prevents text from wrapping awkwardly on tiny screens */
            font-size: 0.85rem;
        }
        
        /* Ensure the Sidebar still slides out to 100% width cleanly */
        .sh-sidebar { width: 100%; right: -100%; }
        .sh-overlay-title { font-size: 1.1rem; margin-bottom: 10px; }
        .sh-kpi-row { padding: 10px 15px; }
        .sh-kpi { padding: 6px 10px; margin: 0 4px; }
        .sh-kpi-val { font-size: 1.1rem; }
        .sh-filter-row { flex-direction: column; align-items: flex-start; gap: 15px; }
        .sh-tabs { width: 100%; justify-content: space-between; }
        .sh-tab { flex: 1; text-align: center; padding: 8px 10px; font-size: 0.75rem; }
    }
</style>

<div id="holdLedgerModal" class="vanilla-modal">
    <div class="vanilla-modal-content large" style="max-width: 1000px; height: 85vh; display: flex; flex-direction: column;">
        <div style="text-align: right; margin-bottom: 10px;">
            <span class="vanilla-close" onclick="document.getElementById('holdLedgerModal').style.display='none'">&times;</span>
        </div>
        <div id="holdLedgerContent" style="flex: 1; overflow-y: auto; padding-right: 15px;"></div>
    </div>
</div>

<div id="ignoredLedgerModal" class="vanilla-modal">
    <div class="vanilla-modal-content large" style="max-width: 800px; height: 80vh; display: flex; flex-direction: column;">
        <div style="text-align: right; margin-bottom: 10px;">
            <span class="vanilla-close" onclick="document.getElementById('ignoredLedgerModal').style.display='none'">&times;</span>
        </div>
        <div id="ignoredLedgerContent" style="flex: 1; overflow-y: auto; padding-right: 15px;"></div>
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
            <button class="sh-btn sh-btn-danger hide-map-controls-mobile" style="margin-bottom: 15px;" onclick="resetMap()">
                <i class="fas fa-undo-alt"></i> Reset Map & Clear Filters
            </button>

            <div class="hide-map-controls-mobile" style="text-align: center; color: var(--sh-text-muted); font-size: 0.75rem; margin-bottom: 10px; background: rgba(0,0,0,0.2); padding: 12px; border-radius: 8px;">
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

                <button class="sh-btn" style="background: rgba(239, 68, 68, 0.1); color: var(--sh-danger); border: 1px dashed rgba(239, 68, 68, 0.3); margin-bottom: 15px;" onclick="openIgnoredLedger()">
                    <i class="fas fa-eye-slash"></i> Manage Ignored CSV Rows
                </button>
                
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
                    // Shows ALL projects so brand new empty ones can be initialized
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
                    // Matches Jump To Project (Only projects with units)
                    $stmt = $pdo->query("SELECT DISTINCT p.id, p.name, p.city FROM projects p INNER JOIN sales_properties sp ON p.id = sp.project_id ORDER BY p.city ASC, p.name ASC");
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

    // Alias mapboxgl to maplibregl so the Draw polygon plugin continues to work flawlessly
    window.mapboxgl = maplibregl;

    // Initialize the 100% free map (Notice: No API Key needed!)
    const map = new maplibregl.Map({ 
        container: 'sales-map', 
        style: {
            'version': 8,
            'sources': {
                'osm': {
                    'type': 'raster',
                    'tiles': ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                    'tileSize': 256,
                    'attribution': '&copy; OpenStreetMap'
                }
            },
            'layers': [{
                'id': 'osm-layer',
                'type': 'raster',
                'source': 'osm',
                'minzoom': 0,
                'maxzoom': 19
            }]
        },
        center: defaultCenter, 
        zoom: defaultZoom, 
        pitch: defaultPitch, 
        bearing: 0 
    });
    map.addControl(new maplibregl.NavigationControl(), 'bottom-right');

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
                        
                        new maplibregl.Marker(el).setLngLat([project.longitude, project.latitude]).addTo(map);
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
            card.setAttribute('data-index', indexCounter++);
            
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
            
            // FULL CLEANUP OF OLD BUTTONS (Fixes duplicate Hold/Reserve buttons - Item 6)
            card.querySelectorAll('select, input, button[onclick*="togglePriceEdit"], button[onclick*="holdProperty"], button[onclick*="requestReserve"], button[onclick*="markResale"], .resale-input').forEach(el => el.remove());

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

    // MISSING RESERVE UNIT FUNCTION ADDED (Fixes Item 7)
    function requestReserve(propertyId) {
        if(!confirm("Are you sure you want to request to reserve this unit?")) return;
        
        let formData = new FormData(); 
        formData.append('action', 'request_reserved'); 
        formData.append('property_id', propertyId);
        
        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) { 
                showToast("Reservation requested successfully!", "success"); 
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

    let currentSyncPayload = { translations: [], prices: [], statuses: [] };

   function processDailySync(input) {
        if (input.files.length === 0) return;
        const file = input.files[0]; 
        const formData = new FormData(); 
        formData.append('sync_csv', file);
        formData.append('action', 'analyze');
        
        showToast("Analyzing CSV... Please wait.", "success");
        
        fetch('api/sync_daily_report.php', { method: 'POST', body: formData })
        .then(async r => {
            const text = await r.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("SERVER CRASH REPORT:", text);
                throw new Error("Server crashed or returned invalid data. Press F12 to check the console.");
            }
        })
        .then(data => {
            input.value = ""; // Moved clearing to AFTER safe dispatch
            if (data.success) { 
                currentSyncPayload.statuses = data.status_changes; 
                showUnifiedMatrixModal(data);
            } else { 
                alert("Error: " + data.message); 
            }
        })
        .catch(err => { 
            input.value = ""; 
            console.error(err); 
            alert("Sync Failed: " + err.message); 
        });
    }

    function showUnifiedMatrixModal(data) {
        // Build Grouped Options by Project
        let optionsHtml = `
            <option value="">-- Skip for today --</option>
            <option value="-1" style="color: var(--sh-danger); font-weight: bold;">🛑 Permanently Ignore</option>
        `;
        let currentProject = '';
        data.all_db_units.forEach(u => { 
            if (u.project_name !== currentProject) {
                if (currentProject !== '') optionsHtml += `</optgroup>`;
                optionsHtml += `<optgroup label="${u.project_name}">`;
                currentProject = u.project_name;
            }
            optionsHtml += `<option value="${u.id}">${u.project_name} - ${u.unit_name}</option>`; 
        });
        if (currentProject !== '') optionsHtml += `</optgroup>`;

        // 1. Build Unmapped Rows HTML
        let notFoundHtml = '';
        if (data.not_found.length > 0) {
            let rowsHtml = '';
            data.not_found.forEach((item, i) => {
                let rowOptionsHtml = optionsHtml;
                let borderColor = 'var(--sh-danger)';
                let badgeHtml = '';
                
                if (item.recommended_id) {
                    rowOptionsHtml = rowOptionsHtml.replace('value="' + item.recommended_id + '"', 'value="' + item.recommended_id + '" selected');
                    borderColor = 'var(--sh-proc)';
                    badgeHtml = `<div style="font-size: 0.75rem; color: var(--sh-proc); font-weight: bold; margin-bottom: 5px;"><i class="fas fa-magic"></i> AI Suggested: ${item.recommended_full_name}</div>`;
                }
                
                let safeCsvName = item.csv_name.replace(/"/g, '&quot;');
                
                rowsHtml += `
                <tr class="unmapped-row" style="border-bottom: 1px solid var(--sh-border-light);">
                    <td style="padding: 12px; color: var(--sh-danger); font-weight: bold;">${item.csv_name}</td>
                    <td style="padding: 12px; border-left: 1px solid var(--sh-border);">
                        ${badgeHtml}
                        <div style="display: flex; gap: 10px;">
                            <select class="sh-select sync-trans-select" data-csv="${safeCsvName}" style="margin:0; flex: 1; border-color: ${borderColor};">${rowOptionsHtml}</select>
                            <button class="sh-btn sh-btn-danger" style="margin:0; width: auto; padding: 0 15px;" title="Permanently Ignore this row" onclick="this.closest('.unmapped-row').style.opacity='0.3'; this.closest('.unmapped-row').querySelector('select').value='-1';"><i class="fas fa-eye-slash"></i> Ignore Forever</button>
                        </div>
                    </td>
                </tr>`;
            });

            notFoundHtml = `
            <div style="margin-bottom: 30px; border: 1px solid var(--sh-danger); border-radius: 8px; overflow: hidden;">
                <div style="background: rgba(239, 68, 68, 0.1); padding: 15px; border-bottom: 1px solid var(--sh-danger); font-weight: bold; color: #fff;">
                    <i class="fas fa-link text-danger"></i> Unmapped CSV Rows (${data.not_found.length})
                    <div style="font-size: 0.8rem; color: var(--sh-text-muted); font-weight: normal; margin-top: 5px;">Link these to a database unit, or click Ignore to safely discard them.</div>
                </div>
                <div style="overflow-x: auto; background: var(--sh-bg-base);">
                    <table style="width: 100%; text-align: left; border-collapse: collapse; color: #fff; font-size: 0.9rem;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--sh-border); background: rgba(0,0,0,0.2);">
                                <th style="padding: 12px; width: 50%;">CSV Upload Data</th>
                                <th style="padding: 12px; width: 50%; border-left: 1px solid var(--sh-border);">Database Link</th>
                            </tr>
                        </thead>
                        <tbody>${rowsHtml}</tbody>
                    </table>
                </div>
            </div>`;
        }

        // 2. Build Price Conflicts HTML
        let conflictsHtml = '';
        if (data.price_conflicts.length > 0) {
            let rowsHtml = '';
            data.price_conflicts.forEach((c, i) => {
                rowsHtml += `
                <tr style="border-bottom: 1px solid var(--sh-border-light);">
                    <td style="padding: 12px;">
                        <strong>${c.csv_source_name}</strong><br>
                        <span style="color:var(--sh-avail);">Sh: €${c.csv_shell} | Fin: €${c.csv_fin}</span>
                    </td>
                    <td style="padding: 12px; border-left: 1px solid var(--sh-border);">
                        <strong>${c.project_name} - ${c.unit_name}</strong><br>
                        <span style="color:var(--sh-text-muted);">Sh: €${c.db_shell} | Fin: €${c.db_fin}</span>
                    </td>
                    <td style="padding: 12px; text-align: right; border-left: 1px solid var(--sh-border); white-space: nowrap;">
                        <label style="margin-right: 15px; cursor: pointer;"><input type="radio" name="price_res_${i}" class="sync-price-radio" value="db" checked> Keep DB</label>
                        <label style="cursor: pointer; color: var(--sh-avail);"><input type="radio" name="price_res_${i}" class="sync-price-radio" value="csv" data-id="${c.id}" data-shell="${c.csv_shell}" data-fin="${c.csv_fin}"> Use CSV</label>
                    </td>
                </tr>`;
            });

            conflictsHtml = `
            <div style="margin-bottom: 30px; border: 1px solid var(--sh-proc); border-radius: 8px; overflow: hidden;">
                <div style="background: rgba(245, 158, 11, 0.1); padding: 15px; border-bottom: 1px solid var(--sh-proc); font-weight: bold; color: #fff;">
                    <i class="fas fa-euro-sign text-warning"></i> Price Mismatches (${data.price_conflicts.length})
                </div>
                <div style="overflow-x: auto; background: var(--sh-bg-base);">
                    <table style="width: 100%; font-size: 0.9rem; border-collapse: collapse; text-align: left; color: #fff;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--sh-border); background: rgba(0,0,0,0.2);">
                                <th style="padding: 12px; width: 35%;">CSV Upload Data</th>
                                <th style="padding: 12px; width: 35%; border-left: 1px solid var(--sh-border);">Database Match</th>
                                <th style="padding: 12px; width: 30%; text-align: right; border-left: 1px solid var(--sh-border);">Resolution</th>
                            </tr>
                        </thead>
                        <tbody>${rowsHtml}</tbody>
                    </table>
                </div>
            </div>`;
        }

        // 3. Build Status Updates HTML
        let statusesHtml = '';
        if (data.status_changes.length > 0) {
            let rowsHtml = '';
            data.status_changes.forEach(s => {
                rowsHtml += `
                <tr style="border-bottom: 1px solid var(--sh-border-light);">
                    <td style="padding: 12px;">
                        <strong>${s.csv_source_name}</strong><br>
                        <span style="color:var(--sh-avail);">New Status: ${s.new_status}</span>
                    </td>
                    <td style="padding: 12px; border-left: 1px solid var(--sh-border);">
                        <strong>${s.project_name} - ${s.unit_name}</strong><br>
                        <span style="color:var(--sh-text-muted);">Current: ${s.old_status}</span>
                    </td>
                    <td style="padding: 12px; text-align: right; border-left: 1px solid var(--sh-border);">
                        <span style="color:var(--sh-avail); font-weight:bold;">Update to ${s.new_status} <i class="fas fa-check"></i></span>
                    </td>
                </tr>`;
            });

            statusesHtml = `
            <div style="border: 1px solid var(--sh-avail); border-radius: 8px; overflow: hidden;">
                <div style="background: rgba(16, 185, 129, 0.1); padding: 15px; border-bottom: 1px solid var(--sh-avail); font-weight: bold; color: #fff;">
                    <i class="fas fa-sync text-success"></i> Status Updates To Apply (${data.status_changes.length})
                </div>
                <div style="overflow-x: auto; background: var(--sh-bg-base);">
                    <table style="width: 100%; font-size: 0.9rem; border-collapse: collapse; text-align: left; color: #fff;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--sh-border); background: rgba(0,0,0,0.2);">
                                <th style="padding: 12px; width: 35%;">CSV Upload Data</th>
                                <th style="padding: 12px; width: 35%; border-left: 1px solid var(--sh-border);">Database Match</th>
                                <th style="padding: 12px; width: 30%; text-align: right; border-left: 1px solid var(--sh-border);">Action</th>
                            </tr>
                        </thead>
                        <tbody>${rowsHtml}</tbody>
                    </table>
                </div>
            </div>`;
        }

        // 4. Build Success HTML (if nothing needs changing)
        let successHtml = '';
        if (data.not_found.length === 0 && data.price_conflicts.length === 0 && data.status_changes.length === 0) {
            successHtml = `
            <div style="text-align: center; padding: 50px; color: var(--sh-avail);">
                <i class="fas fa-check-circle fa-4x mb-3"></i>
                <h3>100% Match! No updates required.</h3>
            </div>`;
        }

        // 5. Combine everything into the final wrapper
        let html = `
        <div id="unifiedMatrixModal" class="vanilla-modal" style="display:block; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.95); z-index:9999;">
            <div class="vanilla-modal-content large" style="width: 95%; max-width: 1200px; height: 90vh; margin: 5vh auto; display: flex; flex-direction: column; padding: 0; overflow: hidden; border-radius: 12px; background: var(--sh-bg-panel); border: 1px solid var(--sh-border);">
                
                <div style="padding: 20px; border-bottom: 1px solid var(--sh-border); display: flex; justify-content: space-between; align-items: center; background: var(--sh-bg-base);">
                    <h3 style="margin: 0; color: #fff;"><i class="fas fa-file-csv text-info"></i> CSV Sync Analysis Report</h3>
                    <div style="font-size: 0.9rem; color: var(--sh-text-muted);">Scanned: ${data.stats.scanned} | Mapped: ${data.stats.mapped}</div>
                </div>

                <div style="flex: 1; overflow-y: auto; padding: 20px;">
                    ${notFoundHtml}
                    ${conflictsHtml}
                    ${statusesHtml}
                    ${successHtml}
                </div>

                <div style="padding: 20px; border-top: 1px solid var(--sh-border); background: var(--sh-bg-base); display: flex; justify-content: flex-end; gap: 15px;">
                    <button class="sh-btn sh-btn-danger" style="width: auto; margin: 0;" onclick="document.getElementById('unifiedMatrixModal').remove()">Cancel</button>
                    <button class="sh-btn sh-btn-success" style="width: auto; margin: 0;" onclick="commitSyncMatrix(this)">Commit Approved Changes</button>
                </div>
            </div>
        </div>`;

        let oldModal = document.getElementById('unifiedMatrixModal'); 
        if (oldModal) oldModal.remove();
        document.body.insertAdjacentHTML('beforeend', html);
    }
                      

    function commitSyncMatrix(btn) {
        // Collect translations
        currentSyncPayload.translations = [];
        document.querySelectorAll('.sync-trans-select').forEach(sel => {
            if (sel.value) {
                currentSyncPayload.translations.push({ csv_name: sel.getAttribute('data-csv'), db_unit_id: sel.value });
            }
        });

        // Collect accepted CSV prices
        currentSyncPayload.prices = [];
        document.querySelectorAll('.sync-price-radio:checked').forEach(rad => {
            if (rad.value === 'csv') {
                currentSyncPayload.prices.push({
                    id: rad.getAttribute('data-id'), shell: rad.getAttribute('data-shell'), finishes: rad.getAttribute('data-fin')
                });
            }
        });

        const formData = new FormData();
        formData.append('action', 'commit');
        formData.append('payload', JSON.stringify(currentSyncPayload));

        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        btn.disabled = true;

        fetch('api/sync_daily_report.php', { method: 'POST', body: formData })
        .then(async r => {
            const text = await r.text();
            try { return JSON.parse(text); } 
            catch (e) { throw new Error("Server crashed during commit. Response: " + text); }
        })
        .then(data => {
            if (data.success) {
                alert("Sync successfully committed!");
                location.reload();
            } else {
                alert("Database Error: " + data.message);
                btn.innerHTML = 'Commit Approved Changes';
                btn.disabled = false;
            }
        })
        .catch(err => { 
            alert("Commit Failed: " + err.message); 
            btn.innerHTML = 'Commit Approved Changes';
            btn.disabled = false;
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

    function openIgnoredLedger() {
        let formData = new FormData();
        formData.append('action', 'get_ignored_ledger');
        
        fetch('api/sync_daily_report.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(!data.success) { showToast("Error loading ledger", "error"); return; }
            
            let html = `
            <div style="position: sticky; top: 0; background: var(--sh-bg-panel); padding-bottom: 15px; z-index: 10; border-bottom: 1px solid var(--sh-border); margin-bottom: 15px;">
                <h3 style="color: #fff; margin: 0;"><i class="fas fa-eye-slash text-danger"></i> Permanently Ignored CSV Rows</h3>
                <p style="color: var(--sh-text-muted); font-size: 0.85rem; margin-top: 5px;">These CSV rows are currently skipped during the Daily Sync. Restore them to map them again.</p>
            </div>
            <table class="table" style="width: 100%; text-align: left; border-collapse: collapse; color: #fff;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--sh-border);">
                        <th style="padding: 10px;">CSV Source Name</th>
                        <th style="padding: 10px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>`;

            if (data.ignored.length === 0) {
                html += `<tr><td colspan="2" style="padding: 20px; text-align:center; color: var(--sh-text-muted);">No ignored rows found.</td></tr>`;
            } else {
                data.ignored.forEach(item => {
                    html += `
                    <tr style="border-bottom: 1px solid var(--sh-border-light);">
                        <td style="padding: 12px 10px; font-weight: bold; color: var(--sh-danger);">${item.csv_name}</td>
                        <td style="padding: 12px 10px; text-align: right;">
                            <button class="sh-btn sh-btn-success" style="margin: 0; padding: 6px 12px; width: auto; display: inline-block; font-size: 0.8rem;" onclick="restoreIgnoredRow(${item.id})"><i class="fas fa-trash-restore"></i> Restore</button>
                        </td>
                    </tr>`;
                });
            }
            html += `</tbody></table>`;
            
            document.getElementById('ignoredLedgerContent').innerHTML = html;
            document.getElementById('ignoredLedgerModal').style.display = 'block';
        });
    }

    function restoreIgnoredRow(id) {
        if(!confirm("Restore this row? It will appear as 'Unmapped' in your next Daily Sync.")) return;
        
        let formData = new FormData();
        formData.append('action', 'restore_ignored_row');
        formData.append('translation_id', id);
        
        fetch('api/sync_daily_report.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                showToast("Row restored successfully!", "success");
                openIgnoredLedger(); 
            } else {
                showToast("Error: " + data.message, "error");
            }
        });
    }
</script>

<?php require_once 'footer.php'; ?>
