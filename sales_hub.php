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
       SCOPED SALES HUB CSS (Native Override)
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

    footer, .footer, #footer, .navbar { display: none !important; }
    .container-fluid.main-content, .main-panel { padding: 0 !important; margin: 0 !important; background: var(--sh-bg-base) !important; }
    
    #sh-wrapper { position: relative; height: 100vh; width: 100%; overflow: hidden; font-family: 'Inter', sans-serif; color: var(--sh-text-main); }
    #sales-map { position: absolute; top: 0; bottom: 0; width: 100%; left: 0; }
    
    /* Overlay Controls */
    .sh-overlay {
        position: absolute; top: 20px; left: 20px; z-index: 10; width: 320px;
        background: var(--sh-bg-glass); backdrop-filter: blur(12px);
        border: 1px solid var(--sh-border-light); border-radius: 16px;
        padding: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    }
    .sh-overlay-title { font-size: 1.25rem; font-weight: 800; color: #fff; margin: 0 0 15px 0; display: flex; align-items: center; justify-content: space-between; }
    
    .sh-label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--sh-text-muted); text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px; }
    .sh-select { width: 100%; background: var(--sh-bg-base); color: #fff; border: 1px solid var(--sh-border); padding: 10px 12px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 15px; outline: none; cursor: pointer; }
    
    .sh-btn { width: 100%; padding: 10px; border-radius: 8px; border: none; font-weight: 700; font-size: 0.85rem; cursor: pointer; display: flex; justify-content: center; align-items: center; gap: 8px; transition: 0.2s; margin-bottom: 10px; }
    .sh-btn-warning { background: rgba(245, 158, 11, 0.1); color: var(--sh-proc); border: 1px solid rgba(245, 158, 11, 0.3); }
    .sh-btn-warning:hover, .sh-btn-warning.active { background: var(--sh-proc); color: #fff; }
    .sh-btn-info { background: rgba(59, 130, 246, 0.1); color: var(--sh-sold); border: 1px solid rgba(59, 130, 246, 0.3); }
    .sh-btn-info:hover { background: var(--sh-sold); color: #fff; }
    .sh-btn-success { background: rgba(16, 185, 129, 0.1); color: var(--sh-avail); border: 1px solid rgba(16, 185, 129, 0.3); }
    .sh-btn-success:hover { background: var(--sh-avail); color: #fff; }
    .sh-btn-icon { background: rgba(255,255,255,0.1); color: #fff; border: none; border-radius: 8px; padding: 8px 12px; cursor: pointer; transition: 0.2s; font-size: 1rem; }
    .sh-btn-icon:hover { background: #fff; color: var(--sh-bg-base); }
    
    /* Sidebar */
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
    .sh-card { background: var(--sh-bg-base); border: 1px solid var(--sh-border); border-radius: 12px; padding: 15px; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.3); color: #fff; }
    
    .sh-card-header { display: flex; justify-content: space-between; margin-bottom: 10px; }
    .sh-card-title { font-size: 1.1rem; font-weight: 800; margin: 0; color: #fff; }
    .sh-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; border: 1px solid; text-align: center; }
    
    .sh-badge.Available, .sh-badge.BOM { background: rgba(16,185,129,0.1); color: var(--sh-avail); border-color: rgba(16,185,129,0.3); }
    .sh-badge.Proceeding, .sh-badge.Proceeding-Pending-Approval { background: rgba(245,158,11,0.1); color: var(--sh-proc); border-color: rgba(245,158,11,0.3); }
    .sh-badge.Sold, .sh-badge.Sold-Pending-Approval { background: rgba(59,130,246,0.1); color: var(--sh-sold); border-color: rgba(59,130,246,0.3); }
    .sh-badge.Resale { background: rgba(168,85,247,0.1); color: var(--sh-resale); border-color: rgba(168,85,247,0.3); }
    .sh-badge.On-Hold { background: rgba(100,116,139,0.1); color: var(--sh-hold); border-color: rgba(100,116,139,0.3); }
    
    .sh-price-row { font-size: 1.25rem; font-weight: 800; color: var(--sh-avail); margin-bottom: 15px; display: flex; align-items: center; justify-content: space-between; }
    
    .sh-status-select { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid var(--sh-border); background: var(--sh-bg-panel); color: #fff; font-weight: bold; cursor: pointer; font-size: 0.85rem; margin-bottom: 10px; outline: none; }
    .sh-resale-input { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid var(--sh-resale); background: rgba(168,85,247,0.1); color: #fff; font-weight: bold; margin-bottom: 10px; outline: none; }
    
    /* Lightbox Gallery */
    .sh-lightbox { display: none; position: fixed; z-index: 3000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); backdrop-filter: blur(10px); flex-direction: column; align-items: center; justify-content: center; }
    .sh-lightbox-close { position: absolute; top: 20px; right: 30px; color: #fff; font-size: 3rem; cursor: pointer; line-height: 1; }
    .sh-lightbox-content { display: flex; align-items: center; justify-content: center; max-width: 90vw; max-height: 80vh; }
    .sh-lightbox-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.1); color: white; border: none; font-size: 2rem; padding: 15px; cursor: pointer; border-radius: 50%; transition: 0.2s; }
    .sh-lightbox-nav:hover { background: rgba(255,255,255,0.3); }
    .sh-lightbox-prev { left: 20px; }
    .sh-lightbox-next { right: 20px; }
    .sh-lightbox-counter { position: absolute; bottom: 30px; color: #fff; font-size: 1.2rem; font-weight: bold; background: rgba(0,0,0,0.5); padding: 5px 15px; border-radius: 20px; }

    /* Vanilla Modals (For Plan/Upload) */
    .vanilla-modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(8px); }
    .vanilla-modal-content { background: var(--sh-bg-panel); margin: 5vh auto; padding: 25px; border: 1px solid var(--sh-border); border-radius: 16px; width: 90%; max-width: 500px; box-shadow: 0 25px 50px rgba(0,0,0,0.5); color: #fff; }
    .vanilla-modal-content.large { max-width: 1200px; height: 85vh; display: flex; flex-direction: column; }
    .vanilla-close { float: right; font-size: 1.5rem; color: var(--sh-text-muted); cursor: pointer; line-height: 1; }
    .vanilla-close:hover { color: #fff; }

    #sh-toast-container { position: fixed; bottom: 30px; right: 30px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; }
    .sh-toast { padding: 15px 25px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); font-size: 0.95rem; font-weight: 600; display: flex; align-items: center; gap: 12px; color: #fff; animation: shToastIn 0.3s forwards; }
    @keyframes shToastIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
</style>

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
            <button class="sh-btn-icon" onclick="resetMap()" title="Reset Map View"><i class="fas fa-globe-europe"></i></button>
        </h5>
        
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
            <div style="border-top: 1px solid var(--sh-border-light); margin: 15px -20px; padding-top: 15px;"></div>
            
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
        if (pid && mapProjectsData[pid]) {
            // Re-fetch without panning to keep experience smooth
            openProjectSidebar(mapProjectsData[pid], false);
        }
    }

    // ========================================================
    // LIGHTBOX GALLERY ENGINE
    // ========================================================
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
        document.getElementById('sh-lightbox-media').innerHTML = ''; // Stops videos playing
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

    // ========================================================
    // STANDARD UI FUNCTIONS
    // ========================================================
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'sh-toast';
        toast.style.background = type === 'success' ? '#10B981' : '#EF4444';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        toast.innerHTML = `<i class="fas ${icon} fa-lg"></i> ${message}`;
        container.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
    }

    function setFilter(filterType) {
        document.getElementById('btnFilterAll').className = filterType === 'All' ? 'sh-tab active' : 'sh-tab';
        document.getElementById('btnFilterAvail').className = filterType === 'Available' ? 'sh-tab active' : 'sh-tab';

        const cards = document.querySelectorAll('.sh-card');
        cards.forEach(card => {
            if (filterType === 'All') card.style.display = 'block'; 
            else card.style.display = card.getAttribute('data-status') === 'Available' ? 'block' : 'none';
        });
    }

    let currentPlanZoom = 1;
    function openPlanModal(url) { document.getElementById('viewPlanModal').style.display = 'block'; document.getElementById('planIframe').src = url; resetPlan(); }
    function closePlanModal() { document.getElementById('viewPlanModal').style.display = 'none'; document.getElementById('planIframe').src = ''; }
    function zoomPlan(amount) { currentPlanZoom = Math.max(0.25, Math.min(4, currentPlanZoom + amount)); document.getElementById('planTransformContainer').style.transform = `scale(${currentPlanZoom})`; }
    function resetPlan() { currentPlanZoom = 1; document.getElementById('planTransformContainer').style.transform = `scale(1)`; }
    function closeSidebar() { document.getElementById('custom-sidebar').classList.remove('open'); }
    function openUploadModal() { document.getElementById('uploadFrameModal').style.display = 'block'; }
    function closeUploadModal() { document.getElementById('uploadFrameModal').style.display = 'none'; }

    // ========================================================
    // MAPBOX INTEGRATION 
    // ========================================================
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

    function resetMap() {
        map.flyTo({ center: defaultCenter, zoom: defaultZoom, pitch: defaultPitch, bearing: 0, duration: 1500 });
        closeSidebar();
    }

    map.on('load', () => {
        fetch('api/get_sales_map_data.php').then(r => r.json()).then(data => {
            if(data.success && data.data) {
                const dropdown = document.getElementById('projectJumpDropdown');
                data.data.forEach(project => {
                    if(project.latitude && project.longitude) {
                        mapProjectsData[project.project_id] = project;
                        dropdown.add(new Option(project.project_name, project.project_id));
                        const el = document.createElement('div');
                        el.style.cssText = `background-color: ${project.available_units > 0 ? '#10B981' : '#EF4444'}; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 10px rgba(0,0,0,0.8); cursor: pointer;`;
                        new mapboxgl.Marker(el).setLngLat([project.longitude, project.latitude]).addTo(map);
                        el.addEventListener('click', () => openProjectSidebar(project, true));
                    }
                });
            }
        });
    });

    // ========================================================
    // SURGICAL HTML INTERCEPTOR (Data Integrity Guaranteed)
    // ========================================================
    function openProjectSidebar(project, shouldPan = true) {
        
        // Gentle Pan (No auto-zoom jump unless specifically requested)
        if (shouldPan) {
            map.panTo([project.longitude, project.latitude], { duration: 1000 });
        }
        
        document.getElementById('sidebarProjectName').innerText = project.project_name;
        document.getElementById('sidebarProjectName').setAttribute('data-pid', project.project_id);
        document.getElementById('sidebarAvail').innerText = project.available_units;
        document.getElementById('sidebarHold').innerText = project.held_units;
        document.getElementById('sidebarSold').innerText = project.sold_units;

        setFilter('All');
        document.getElementById('custom-sidebar').classList.add('open');
        document.getElementById('unitListContainer').innerHTML = '<div class="text-center p-4 text-light"><div class="spinner-border text-info"></div><div class="mt-2">Loading units...</div></div>';

        fetch('api/get_project_units.php?project_id=' + project.project_id)
            .then(r => r.json())
            .then(unitData => {
                if(unitData.success) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = unitData.html;
                    
                    const unitCards = tempDiv.querySelectorAll('.card, .unit-card');
                    unitCards.forEach(card => {
                        
                        let unitId = card.getAttribute('data-unit-id');
                        if (!unitId) {
                            const sel = card.querySelector('select');
                            if (sel && sel.getAttribute('onchange')) {
                                const match = sel.getAttribute('onchange').match(/\d+/);
                                if (match) unitId = match[0];
                            }
                        }

                        let status = card.getAttribute('data-status') || '';
                        if (!status) {
                            const badge = card.querySelector('.badge');
                            if (badge) status = badge.innerText.trim();
                        }
                        
                        // Clean status
                        if (status === 'Reserved') status = 'Proceeding';
                        if (status === 'Sold POS' || status === 'Sold Contract') status = 'Sold';
                        card.setAttribute('data-status', status);

                        const cardBody = card.querySelector('.card-body') || card;

                        // ============================================
                        // VIEW MODE OVERRIDES
                        // ============================================
                        if (currentViewMode === 'agent') {
                            
                            // 1. Hide prices securely via Text Node replacement
                            if (status.includes('Sold')) {
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
                                    span.className = 'text-secondary small';
                                    span.style.fontStyle = 'italic';
                                    span.innerText = '🔒 Price Confidential';
                                    n.parentNode.replaceChild(span, n);
                                });
                            }

                            // 2. Strip out all manager controls without affecting core layout
                            card.querySelectorAll('select, input, textarea').forEach(el => el.remove());
                            card.querySelectorAll('button[onclick*="togglePriceEdit"]').forEach(el => el.remove());
                            card.querySelectorAll('div[id^="price_edit_"]').forEach(el => el.remove());
                            card.querySelectorAll('button[onclick*="managerUpdateStatus"]').forEach(el => el.remove());

                            // 3. Inject Agent Action Buttons
                            const actionWrapper = document.createElement('div');
                            actionWrapper.className = 'mt-3 pt-2';
                            actionWrapper.style.borderTop = '1px solid var(--sh-border)';

                            if (status === 'Available' || status === 'BOM') {
                                actionWrapper.innerHTML = `
                                    <div style="display:flex; gap:10px; margin-top: 15px;">
                                        <button class="sh-btn sh-btn-warning" onclick="holdProperty(${unitId})"><i class="fas fa-pause"></i> Hold</button>
                                        <button class="sh-btn sh-btn-success" onclick="requestReserve(${unitId})"><i class="fas fa-check"></i> Proceed</button>
                                    </div>
                                `;
                            } else {
                                actionWrapper.innerHTML = `
                                    <div style="text-align:center; color:var(--sh-text-muted); font-size:0.8rem; font-weight:bold; text-transform:uppercase; margin-top: 15px;">
                                        Current Status: <span style="color:#fff;">${status}</span>
                                    </div>
                                `;
                            }
                            cardBody.appendChild(actionWrapper);

                        } else {
                            // MANAGER VIEW
                            const selectEl = card.querySelector('select[onchange*="managerUpdateStatus"]');
                            if (selectEl && !card.querySelector('.resale-input')) {
                                
                                // Expand options safely
                                selectEl.innerHTML = `
                                    <option value="Available" ${status === 'Available' ? 'selected' : ''}>Available</option>
                                    <option value="On Hold" ${status === 'On Hold' ? 'selected' : ''}>On Hold</option>
                                    <option value="Resale" ${status === 'Resale' ? 'selected' : ''}>Resale</option>
                                    <option value="BOM" ${status === 'BOM' ? 'selected' : ''}>BOM</option>
                                    <option value="Proceeding" ${status === 'Proceeding' ? 'selected' : ''}>Proceeding</option>
                                    <option value="Proceeding Pending Approval" ${status === 'Proceeding Pending Approval' ? 'selected' : ''}>Proceeding Pending Approval</option>
                                    <option value="Sold" ${status === 'Sold' ? 'selected' : ''}>Sold</option>
                                    <option value="Sold Pending Approval" ${status === 'Sold Pending Approval' ? 'selected' : ''}>Sold Pending Approval</option>
                                `;

                                const resaleInput = document.createElement('input');
                                resaleInput.type = 'number';
                                resaleInput.step = '0.01';
                                resaleInput.className = 'sh-resale-input';
                                resaleInput.id = 'resale_input_' + unitId;
                                resaleInput.placeholder = 'Resale Asking Price (€)';
                                resaleInput.style.display = status === 'Resale' ? 'block' : 'none';
                                selectEl.parentNode.insertBefore(resaleInput, selectEl.nextSibling);
                                
                                selectEl.setAttribute('onchange', `executeStatusUpdate(${unitId}, this)`);
                            }
                        }
                    });

                    document.getElementById('unitListContainer').innerHTML = tempDiv.innerHTML;
                    
                    // ============================================
                    // RENDER MEDIA LIGHTBOX GALLERY
                    // ============================================
                    currentGallery = [];
                    if (unitData.media && unitData.media.videos) {
                        unitData.media.videos.forEach(v => currentGallery.push({type: 'video', src: v}));
                    }
                    if (unitData.media && unitData.media.renders) {
                        unitData.media.renders.forEach(r => currentGallery.push({type: 'image', src: r}));
                    }

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
                        mediaHtml = `<div style="text-align:center; padding:40px; color:var(--sh-text-muted);"><i class="fas fa-image fa-3x mb-2"></i><div style="font-size:0.85rem;">No media uploaded</div></div>`;
                    }
                    document.getElementById('sidebarMediaContainer').innerHTML = mediaHtml;

                } else {
                    document.getElementById('unitListContainer').innerHTML = '<div class="p-3 text-center text-danger">Error loading units.</div>';
                }
            });
    }

    function executeStatusUpdate(unitId, selectElement) {
        const newStatus = selectElement.value;
        const resaleInput = document.getElementById('resale_input_' + unitId);
        
        if (newStatus === 'Resale') {
            resaleInput.style.display = 'block';
            resaleInput.focus();
            resaleInput.onblur = () => {
                if(resaleInput.value) sendStatusToServer(unitId, newStatus, selectElement, resaleInput.value);
            };
        } else {
            resaleInput.style.display = 'none';
            sendStatusToServer(unitId, newStatus, selectElement, null);
        }
    }

    function sendStatusToServer(propertyId, newStatus, selectElement, resalePrice) {
        selectElement.disabled = true;
        let formData = new FormData(); 
        formData.append('action', 'update_unit_status');
        formData.append('unit_id', propertyId); 
        formData.append('status', newStatus);
        if (resalePrice) formData.append('resale_price', resalePrice);

        fetch('sales_hub.php', { method: 'POST', body: formData })
        .then(r => r.text()).then(data => {
            selectElement.disabled = false;
            if(data === 'OK') { 
                showToast(`Status updated to ${newStatus}`, 'success');
                const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid');
                if(pid) setTimeout(() => openProjectSidebar(mapProjectsData[pid], false), 500);
            } else { showToast("Error updating status.", 'error'); }
        });
    }

    function jumpToSelectedProject(projectId) {
        if(projectId && mapProjectsData[projectId]) openProjectSidebar(mapProjectsData[projectId], true);
    }

    function togglePriceEdit(id) {
        const editBox = document.getElementById('price_edit_' + id);
        editBox.style.display = editBox.style.display === 'none' ? 'block' : 'none';
    }

    function savePrice(id) {
        const shell = document.getElementById('inp_sh_' + id).value;
        const fin = document.getElementById('inp_fn_' + id).value;
        let formData = new FormData(); formData.append('property_id', id); formData.append('shell_price', shell); formData.append('finishes_price', fin);
        fetch('api/update_unit_price.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) { showToast("Price updated!", "success"); const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid'); if(pid) setTimeout(() => openProjectSidebar(mapProjectsData[pid], false), 500); } 
            else { showToast("Error: " + data.message, "error"); }
        });
    }

    function generateLivePricelist() {
        const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid');
        if(pid) window.open('print_pricelist.php?project_id=' + pid, '_blank');
    }
    
    // Agent Actions
    function holdProperty(propertyId) {
        if(!confirm("Are you sure you want to put this unit on hold?")) return;
        let formData = new FormData(); formData.append('action', 'hold_property'); formData.append('property_id', propertyId);
        fetch('api/sales_actions.php', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
            if(data.success) { showToast("Put on hold!", "success"); const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid'); if(pid) setTimeout(() => openProjectSidebar(mapProjectsData[pid], false), 500); } 
        });
    }

    function requestReserve(propertyId) {
        if(!confirm("Are you sure you want to transition this unit to Proceeding?")) return;
        let formData = new FormData(); formData.append('action', 'request_reserved'); formData.append('property_id', propertyId);
        fetch('api/sales_actions.php', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
            if(data.success) { showToast("Status updated to Proceeding!", "success"); const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid'); if(pid) setTimeout(() => openProjectSidebar(mapProjectsData[pid], false), 500); } 
        });
    }

    // Framework upload processing
    document.getElementById('uploadFrameForm').addEventListener('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this); this.querySelector('button[type="submit"]').disabled = true;
        fetch('api/upload_project_frame.php', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
            if(data.success) { alert(data.message); location.reload(); } else { alert('Error: ' + data.message); this.querySelector('button').disabled = false; }
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
