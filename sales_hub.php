<?php
require_once 'config.php';
require_once 'session-check.php';

$allowed_roles = ['sales_manager', 'sales_agent', 'admin', 'director', 'system_manager'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// ==========================================
// AUTO-DEPLOY DATABASE UPDATES (SALES HUB V2)
// ==========================================
try {
    $pdo->exec("ALTER TABLE project_units ADD COLUMN resale_price DECIMAL(10,2) DEFAULT NULL");
} catch(PDOException $e) {}

try {
    // Safe Migration of old statuses to new director-approved statuses
    $pdo->exec("UPDATE project_units SET status = 'Proceeding' WHERE status = 'Reserved'");
    $pdo->exec("UPDATE project_units SET status = 'Sold' WHERE status IN ('Sold POS', 'Sold Contract')");
    $pdo->exec("UPDATE project_units SET status = 'Available' WHERE status = 'BOM'");
} catch(PDOException $e) {}

require_once 'header.php';
?>

<script src='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js'></script>
<link href='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css' rel='stylesheet' />

<style>
    footer, .footer, #footer { display: none !important; }
    .container-fluid.main-content, .main-panel { padding: 0 !important; margin: 0 !important; }
    #map-wrapper { position: relative; height: calc(100vh - 70px); width: 100%; overflow: hidden; }
    #sales-map { position: absolute; top: 0; bottom: 0; width: 100%; left: 0; }
    
    .filter-overlay {
        position: absolute; top: 15px; left: 15px; z-index: 10; width: 280px;
        border-radius: 15px; 
        background: rgba(33, 37, 41, 0.85);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.1);
        color: #f8f9fa;
    }
    
    #custom-sidebar {
        position: fixed; top: 70px; right: -450px; width: 450px; height: calc(100vh - 70px);
        background-color: #212529; 
        color: #f8f9fa;
        box-shadow: -5px 0 25px rgba(0,0,0,0.5);
        transition: right 0.3s ease-in-out; z-index: 1050; overflow-y: auto;
    }
    #custom-sidebar.show-sidebar { right: 0; }
    
    .sidebar-header { 
        position: sticky; 
        top: 0; 
        z-index: 1060; 
        background-color: #1a1d20; 
        color: white; 
        padding: 15px 20px; 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        border-bottom: 1px solid rgba(255,255,255,0.05); 
    }
    
    .status-badge { font-size: 0.8rem; padding: 5px 10px; border-radius: 20px; font-weight: 600; display: inline-block;}
    
    #custom-sidebar::-webkit-scrollbar { width: 6px; }
    #custom-sidebar::-webkit-scrollbar-track { background: #212529; }
    #custom-sidebar::-webkit-scrollbar-thumb { background: #495057; border-radius: 3px; }

    .vanilla-modal { 
        display: none; 
        position: fixed; 
        z-index: 2000; 
        left: 0; 
        top: 0; 
        width: 100%; 
        height: 100%; 
        background-color: rgba(0,0,0,0.85); 
        backdrop-filter: blur(4px); 
    }
    .vanilla-modal-content { 
        background-color: #212529; 
        margin: 2% auto; 
        padding: 1rem; 
        border: 1px solid #495057; 
        border-radius: 12px; 
        width: 95%; 
        max-width: 1600px; 
        height: 90vh; 
        display: flex; 
        flex-direction: column; 
        box-shadow: 0 15px 35px rgba(0,0,0,0.6); 
    }
    .vanilla-close { 
        color: #adb5bd; 
        font-size: 2.5rem; 
        font-weight: bold; 
        cursor: pointer; 
        line-height: 1; 
        transition: 0.2s; 
    }
    .vanilla-close:hover { color: #fff; }
</style>

<div id="toast-container" style="position: fixed; bottom: 30px; right: 30px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

<div id="map-wrapper">
    <div id='sales-map'></div>

    <div class="card shadow-sm filter-overlay">
        <div class="card-body p-3">
            <h5 class="mb-3 font-weight-bold fw-bold text-light"><i class="fas fa-map-marked-alt text-info"></i> Sales Hub</h5>
            
            <label class="form-label small font-weight-bold fw-bold text-light mb-1">Jump to Project</label>
            <select class="form-control form-select mb-3 rounded-pill shadow-sm bg-dark text-light border-secondary" id="projectJumpDropdown" onchange="jumpToSelectedProject(this.value)">
                <option value="">-- Select Project Map Pin --</option>
            </select>

            <select class="form-control form-select mb-3 rounded-pill shadow-sm bg-dark text-light border-secondary" id="typeFilter">
                <option value="all">All Property Types</option>
                <option value="apartment">Apartments</option>
                <option value="penthouse">Penthouses</option>
                <option value="commercial">Commercial</option>
                <option value="garage">Garages</option>
                <option value="parking space">Car Spaces</option>
                <option value="maisonette">Maisonettes</option>
                <option value="house">Houses</option>
                <option value="villa">Villas</option>
            </select>
            
            <div class="text-center text-secondary small mb-3" style="font-size: 0.75rem;">
                <i class="fas fa-mouse"></i> Right-Click & Drag to Rotate 3D Map
            </div>
            
            <?php if(in_array($_SESSION['role'], ['admin', 'system_manager', 'sales_manager', 'director'])): ?>
                <hr style="border-color: rgba(255,255,255,0.2);">
                <button class="btn btn-outline-info btn-sm btn-block w-100 mb-2" style="border-radius: 20px;" onclick="openUploadModal()">
                    <i class="fas fa-file-csv"></i> Upload Frame (CSV)
                </button>
                <button class="btn btn-outline-success btn-sm btn-block w-100" style="border-radius: 20px;" onclick="openMediaModal()">
                    <i class="fas fa-cloud-upload-alt"></i> Upload Media & Plans
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="custom-sidebar">
  <div class="sidebar-header">
    <h5 class="m-0 font-weight-bold fw-bold" id="sidebarProjectName">Project Details</h5>
    <button type="button" class="close text-white" style="background: transparent; border: none; font-size: 1.5rem; line-height: 1;" onclick="closeSidebar()">&times;</button>
  </div>
  <div class="sidebar-body">
    <div id="sidebarMediaContainer" class="border-bottom" style="background-color: #2c3034; border-color: #343a40 !important;">
        <div class="text-center p-5">
            <i class="fas fa-building fa-4x text-secondary mb-3"></i>
            <p class="text-secondary small m-0">Click a map pin to load project data.</p>
        </div>
    </div>
    
    <div class="p-3">
        <div class="d-flex justify-content-between mb-4 px-2 mt-2">
            <span class="badge badge-success bg-success status-badge"><span id="sidebarAvail">0</span> Avail</span>
            <span class="badge badge-warning bg-warning text-dark status-badge"><span id="sidebarHold">0</span> Hold</span>
            <span class="badge badge-danger bg-danger status-badge"><span id="sidebarSold">0</span> Sold</span>
        </div>
        
        <div class="mb-4 px-2 border-bottom border-secondary" style="padding-bottom: 25px;">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h6 class="font-weight-bold fw-bold text-uppercase text-light m-0">Project Units</h6>
                <button class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm" onclick="generateLivePricelist()"><i class="fas fa-file-pdf"></i> Live Pricelist</button>
            </div>
            
            <div class="row m-0 w-100" style="padding-bottom: 5px;">
                <div class="col-6 p-0" style="padding-right: 6px !important;">
                    <button class="btn btn-info w-100 shadow-sm" id="btnFilterAll" style="border-radius: 8px; padding: 8px; font-weight: 600; font-size: 0.85rem;" onclick="setFilter('All')">Show All</button>
                </div>
                <div class="col-6 p-0" style="padding-left: 6px !important;">
                    <button class="btn btn-outline-success w-100 shadow-sm" id="btnFilterAvail" style="border-radius: 8px; padding: 8px; font-weight: 600; font-size: 0.85rem;" onclick="setFilter('Available')">Available Only</button>
                </div>
            </div>
        </div>
        
        <div id="unitListContainer" class="pt-2"></div> 
    </div>
  </div>
</div>

<div id="viewPlanModal" class="vanilla-modal">
    <div class="vanilla-modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #495057; padding-bottom: 10px;">
            <h4 style="margin: 0; color: #0dcaf0;"><i class="fas fa-map"></i> Floor Plan Viewer</h4>
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-outline-light btn-sm" onclick="zoomPlan(-0.25)" title="Zoom Out"><i class="fas fa-search-minus"></i></button>
                <button type="button" class="btn btn-outline-light btn-sm" onclick="resetPlan()" title="Reset View"><i class="fas fa-compress"></i></button>
                <button type="button" class="btn btn-outline-light btn-sm" onclick="zoomPlan(0.25)" title="Zoom In"><i class="fas fa-search-plus"></i></button>
            </div>
            <span class="vanilla-close" onclick="closePlanModal()">&times;</span>
        </div>
        <div style="flex: 1; overflow: hidden; background-color: #525659; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
            <div id="planTransformContainer" style="transition: transform 0.3s ease; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;">
                <iframe id="planIframe" src="" style="width: 100%; height: 100%; border: none; background: #fff;"></iframe>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="uploadFrameModal" tabindex="-1" role="dialog" style="display: none; transition: opacity 0.3s linear; z-index: 1060;">
  <div class="modal-dialog" role="document">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">Upload Project Frame</h5>
        <button type="button" class="close text-light" aria-label="Close" onclick="closeUploadModal()" style="background: transparent; border: none; font-size: 1.5rem;"><span aria-hidden="true">&times;</span></button>
      </div>
      <form id="uploadFrameForm">
          <div class="modal-body">
            <div class="form-group mb-3">
                <label class="form-label text-light">Select Project</label>
                <select class="form-control form-select bg-dark text-light border-secondary" name="project_id" required>
                    <option value="">-- Choose Project --</option>
                    <?php
                    try {
                        $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name ASC");
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['name']) . '</option>';
                        }
                    } catch (Exception $e) {}
                    ?>
                </select>
            </div>
            <div class="form-group mb-3">
                <label class="form-label text-light">CSV File</label>
                <input class="form-control bg-dark text-light border-secondary" type="file" name="frame_csv" accept=".csv" required>
                <small class="form-text text-secondary">Ensure file is saved as a CSV matching the 9-column template.</small>
            </div>
          </div>
          <div class="modal-footer border-secondary">
            <button type="submit" class="btn btn-primary">Upload & Import</button>
          </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="uploadMediaModal" tabindex="-1" role="dialog" style="display: none; transition: opacity 0.3s linear; z-index: 1060;">
  <div class="modal-dialog" role="document">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">Upload Project Media</h5>
        <button type="button" class="close text-light" aria-label="Close" onclick="closeMediaModal()" style="background: transparent; border: none; font-size: 1.5rem;"><span aria-hidden="true">&times;</span></button>
      </div>
      <form id="uploadMediaForm">
          <div class="modal-body">
            <div class="form-group mb-3">
                <label class="form-label text-light">Select Project</label>
                <select class="form-control form-select bg-dark text-light border-secondary" name="project_id" required>
                    <option value="">-- Choose Project --</option>
                    <?php
                    try {
                        $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name ASC");
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['name']) . '</option>'; }
                    } catch (Exception $e) {}
                    ?>
                </select>
            </div>
            <div class="form-group mb-3">
                <label class="form-label text-light">Media Type</label>
                <select class="form-control form-select bg-dark text-light border-secondary" name="media_type" id="mediaTypeSelect" required onchange="toggleFloorInput()">
                    <option value="Render (Image)">Render (Image)</option>
                    <option value="Render (Video)">Render (Video)</option>
                    <option value="Floor Plan">Floor Plan (PDF/Img)</option>
                    <option disabled>--- Pricelist Document Pages (JPG/PNG/PDF) ---</option>
                    <option value="Pricelist - Front Cover">Pricelist - Front Cover</option>
                    <option value="Pricelist - Timeframes & Terms">Pricelist - Timeframes & Terms</option>
                    <option value="Pricelist - Spec Sheet">Pricelist - Spec Sheet (Multi-page PDF supported!)</option>
                    <option value="Pricelist - Back Cover">Pricelist - Back Cover</option>
                </select>
            </div>
            <div class="form-group mb-3" id="floorInputGroup" style="display:none;">
                <label class="form-label text-light">Floor Level (Matches CSV)</label>
                <input class="form-control bg-dark text-light border-secondary" type="text" name="floor_level" placeholder="e.g. -1, 0, 1, 2">
            </div>
            <div class="form-group mb-3">
                <label class="form-label text-light">File</label>
                <input class="form-control bg-dark text-light border-secondary" type="file" name="media_file" required>
            </div>
          </div>
          <div class="modal-footer border-secondary">
            <button type="submit" class="btn btn-success">Upload to Cloudflare</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? '#10B981' : '#EF4444';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        
        toast.style.cssText = `background: ${bgColor}; color: white; padding: 14px 24px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); font-size: 0.95rem; font-weight: 600; opacity: 0; transform: translateY(20px); transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); display: flex; align-items: center; gap: 10px;`;
        toast.innerHTML = `<i class="fas ${icon} fa-lg"></i> ${message}`;
        
        container.appendChild(toast);
        
        setTimeout(() => { toast.style.opacity = '1'; toast.style.transform = 'translateY(0)'; }, 10);
        setTimeout(() => { 
            toast.style.opacity = '0'; toast.style.transform = 'translateY(20px)'; 
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function setFilter(filterType) {
        const btnAll = document.getElementById('btnFilterAll');
        const btnAvail = document.getElementById('btnFilterAvail');

        if (filterType === 'All') {
            btnAll.className = 'btn btn-info w-100 shadow-sm';
            btnAvail.className = 'btn btn-outline-success w-100 shadow-sm';
        } else {
            btnAll.className = 'btn btn-outline-info w-100 shadow-sm';
            btnAvail.className = 'btn btn-success w-100 shadow-sm';
        }

        const cards = document.querySelectorAll('.unit-card');
        cards.forEach(card => {
            if (filterType === 'All') {
                card.style.display = 'block'; 
            } else if (filterType === 'Available') {
                if (card.getAttribute('data-status') === 'Available') {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            }
        });
    }

    let currentPlanZoom = 1;

    function openPlanModal(url) {
        const m = document.getElementById('viewPlanModal');
        document.getElementById('planIframe').src = url;
        resetPlan(); 
        m.style.display = 'block'; 
    }

    function closePlanModal() {
        const m = document.getElementById('viewPlanModal');
        m.style.display = 'none'; 
        document.getElementById('planIframe').src = ''; 
    }

    window.addEventListener('click', function(event) {
        const m = document.getElementById('viewPlanModal');
        if (event.target == m) closePlanModal();
    });

    function zoomPlan(amount) {
        currentPlanZoom += amount;
        if (currentPlanZoom < 0.25) currentPlanZoom = 0.25; 
        if (currentPlanZoom > 4) currentPlanZoom = 4; 
        applyPlanTransform();
    }

    function resetPlan() {
        currentPlanZoom = 1;
        applyPlanTransform();
    }

    function applyPlanTransform() {
        const container = document.getElementById('planTransformContainer');
        container.style.transform = `scale(${currentPlanZoom})`;
    }

    function openUploadModal() {
        const m = document.getElementById('uploadFrameModal');
        m.classList.add('show'); m.style.display = 'block'; m.style.backgroundColor = 'rgba(0,0,0,0.7)';
        setTimeout(() => m.style.opacity = '1', 10);
    }
    function closeUploadModal() {
        const m = document.getElementById('uploadFrameModal');
        m.style.opacity = '0';
        setTimeout(() => { m.classList.remove('show'); m.style.display = 'none'; }, 300);
    }
    
    function openMediaModal() {
        const m = document.getElementById('uploadMediaModal');
        m.classList.add('show'); m.style.display = 'block'; m.style.backgroundColor = 'rgba(0,0,0,0.7)';
        setTimeout(() => m.style.opacity = '1', 10);
    }
    function closeMediaModal() {
        const m = document.getElementById('uploadMediaModal');
        m.style.opacity = '0';
        setTimeout(() => { m.classList.remove('show'); m.style.display = 'none'; }, 300);
    }

    function toggleFloorInput() {
        const type = document.getElementById('mediaTypeSelect').value;
        const floorGrp = document.getElementById('floorInputGroup');
        if (type === 'Floor Plan') { floorGrp.style.display = 'block'; floorGrp.querySelector('input').required = true; }
        else { floorGrp.style.display = 'none'; floorGrp.querySelector('input').required = false; }
    }

    function closeSidebar() { document.getElementById('custom-sidebar').classList.remove('show-sidebar'); }

    let mapProjectsData = {};

    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw'; 
    const map = new mapboxgl.Map({
        container: 'sales-map',
        style: 'mapbox://styles/mapbox/satellite-streets-v12', 
        center: [14.405, 35.937], 
        zoom: 12,
        pitch: 40, 
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
                'fill-extrusion-color': '#aaa',
                'fill-extrusion-height': ['interpolate', ['linear'], ['zoom'], 15, 0, 15.05, ['get', 'height']],
                'fill-extrusion-base': ['interpolate', ['linear'], ['zoom'], 15, 0, 15.05, ['get', 'min_height']],
                'fill-extrusion-opacity': 0.8
            }
        }, labelLayerId);
    });

    function openProjectSidebar(project) {
        map.flyTo({ center: [project.longitude, project.latitude], zoom: 17, pitch: 50, essential: true });
        
        document.getElementById('sidebarProjectName').innerText = project.project_name;
        document.getElementById('sidebarProjectName').setAttribute('data-pid', project.project_id);
        
        document.getElementById('sidebarAvail').innerText = project.available_units;
        document.getElementById('sidebarHold').innerText = project.held_units;
        document.getElementById('sidebarSold').innerText = project.sold_units;

        setFilter('All');

        document.getElementById('custom-sidebar').classList.add('show-sidebar');
        document.getElementById('unitListContainer').innerHTML = '<div class="text-center p-4 text-light"><div class="spinner-border text-info" role="status"></div><div class="mt-2">Loading units...</div></div>';
        document.getElementById('sidebarMediaContainer').innerHTML = '<div class="text-center p-5"><div class="spinner-border text-secondary mb-2" role="status"></div><div class="small text-secondary">Loading media...</div></div>';

        fetch('api/get_project_units.php?project_id=' + project.project_id)
            .then(response => response.json())
            .then(unitData => {
                if(unitData.success) {
                    // Inject Unit Data with New Manager Update Status Hook logic
                    let generatedHtml = unitData.html;
                    
                    // We modify the API returned HTML dynamically to implement the new "Price Confidential" and "Resale" logic
                    // We do this by creating a temporary DOM element, parsing it, modifying it, and inserting it back.
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = generatedHtml;
                    
                    const unitCards = tempDiv.querySelectorAll('.unit-card');
                    unitCards.forEach(card => {
                        const unitId = card.getAttribute('data-unit-id');
                        const rawStatus = card.getAttribute('data-status');
                        
                        // Clean statuses to director spec
                        let status = rawStatus;
                        if (status === 'Reserved') status = 'Proceeding';
                        if (status === 'Sold POS' || status === 'Sold Contract') status = 'Sold';
                        if (status === 'BOM') status = 'Available';
                        
                        card.setAttribute('data-status', status);
                        
                        // Update Badges & Borders
                        const badge = card.querySelector('.badge');
                        if (badge) {
                            badge.innerText = status;
                            badge.className = 'badge float-right ' + 
                                (status === 'Available' ? 'badge-success bg-success' : 
                                (status === 'Proceeding' ? 'badge-warning bg-warning text-dark' : 
                                (status === 'Resale' ? 'badge-info bg-info text-dark' : 
                                (status === 'On Hold' ? 'badge-secondary bg-secondary' : 'badge-danger bg-danger'))));
                        }
                        
                        card.className = 'card mb-3 shadow-sm border-left-4 unit-card ' + 
                                (status === 'Available' ? 'border-success' : 
                                (status === 'Proceeding' ? 'border-warning' : 
                                (status === 'Resale' ? 'border-info' : 
                                (status === 'On Hold' ? 'border-secondary' : 'border-danger'))));
                                
                        // Modify Manager Dropdown if it exists
                        const managerSelect = card.querySelector('select[onchange^="managerUpdateStatus"]');
                        if (managerSelect) {
                            // Rebuild options
                            managerSelect.innerHTML = `
                                <option value="Available" ${status === 'Available' ? 'selected' : ''}>Available</option>
                                <option value="Proceeding" ${status === 'Proceeding' ? 'selected' : ''}>Proceeding</option>
                                <option value="Sold" ${status === 'Sold' ? 'selected' : ''}>Sold</option>
                                <option value="Resale" ${status === 'Resale' ? 'selected' : ''}>Resale</option>
                                <option value="On Hold" ${status === 'On Hold' ? 'selected' : ''}>On Hold</option>
                            `;
                            
                            // Add Resale Input
                            const resaleInputHtml = `
                                <input type="number" step="0.01" class="form-control form-control-sm mt-2 bg-dark text-info border-info resale-input" 
                                       id="resale_input_${unitId}" placeholder="Resale Asking Price (€)" 
                                       style="display: ${status === 'Resale' ? 'block' : 'none'}; font-weight:bold;">
                            `;
                            managerSelect.insertAdjacentHTML('afterend', resaleInputHtml);
                            
                            // Override the onchange event
                            managerSelect.setAttribute('onchange', `handleStatusChange(${unitId}, this)`);
                        }
                        
                        // Price Confidentiality Logic
                        const priceContainer = card.querySelector('h5.text-success'); // Assuming this is where price lives
                        if (priceContainer) {
                            if (status === 'Sold') {
                                priceContainer.innerHTML = '<span class="text-secondary" style="font-style:italic; font-size:0.9rem;">🔒 Price Confidential</span>';
                            }
                        }
                    });

                    document.getElementById('unitListContainer').innerHTML = tempDiv.innerHTML;
                    
                    let slides = [];
                    if (unitData.media && unitData.media.videos) {
                        unitData.media.videos.forEach(v => { slides.push(`<video src="${v}" controls style="width:100%; height:250px; object-fit:cover;"></video>`); });
                    }
                    if (unitData.media && unitData.media.renders) {
                        unitData.media.renders.forEach(r => { slides.push(`<img src="${r}" style="width:100%; height:250px; object-fit:cover;">`); });
                    }
                    
                    let mediaHtml = '';
                    if (slides.length > 0) {
                        if (slides.length === 1) {
                            mediaHtml = slides[0];
                        } else {
                            let inner = '';
                            slides.forEach((s, i) => { inner += `<div class="carousel-item ${i===0?'active':''}">${s}</div>`; });
                            mediaHtml = `
                            <div id="projectCarousel" class="carousel slide" data-bs-ride="carousel">
                              <div class="carousel-inner">${inner}</div>
                              <button class="carousel-control-prev" type="button" data-bs-target="#projectCarousel" data-bs-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                              </button>
                              <button class="carousel-control-next" type="button" data-bs-target="#projectCarousel" data-bs-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                              </button>
                            </div>`;
                        }
                    } else {
                        mediaHtml = `<div class="text-center p-5"><i class="fas fa-image fa-3x text-secondary mb-2"></i><div class="small text-secondary">No media uploaded yet</div></div>`;
                    }
                    document.getElementById('sidebarMediaContainer').innerHTML = mediaHtml;

                } else {
                    document.getElementById('unitListContainer').innerHTML = '<div class="p-3 text-center text-danger">Error loading units.</div>';
                }
            });
    }

    // New Handlers for the Modded Dropdown
    function handleStatusChange(unitId, selectElement) {
        const newStatus = selectElement.value;
        const resaleInput = document.getElementById('resale_input_' + unitId);
        
        if (newStatus === 'Resale') {
            resaleInput.style.display = 'block';
            resaleInput.focus();
            // Don't auto-save immediately, wait for them to blur/enter price
            resaleInput.onblur = () => {
                if(resaleInput.value) {
                    managerUpdateStatusWithResale(unitId, newStatus, selectElement, resaleInput.value);
                }
            };
        } else {
            resaleInput.style.display = 'none';
            managerUpdateStatusWithResale(unitId, newStatus, selectElement, null);
        }
    }

    function managerUpdateStatusWithResale(propertyId, newStatus, selectElement, resalePrice) {
        let formData = new FormData(); 
        formData.append('property_id', propertyId); 
        formData.append('new_status', newStatus);
        if (resalePrice !== null) {
            formData.append('resale_price', resalePrice);
        }
        
        let originalBg = selectElement.style.backgroundColor;
        let originalColor = selectElement.style.color;
        selectElement.style.backgroundColor = '#374151'; 
        selectElement.style.color = '#9ca3af';
        selectElement.disabled = true;

        // Custom local fetch to handle the new resale price parameter
        fetch('sales_hub.php', { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=update_unit_status&unit_id=${propertyId}&status=${encodeURIComponent(newStatus)}&resale_price=${resalePrice || ''}`
        })
        .then(r => r.text()).then(data => {
            selectElement.disabled = false;
            if(data === 'OK') { 
                selectElement.style.backgroundColor = '#065f46';
                selectElement.style.color = '#fff';
                
                showToast(`Status successfully updated to ${newStatus}`, 'success');
                
                setTimeout(() => {
                    const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid');
                    if(pid && mapProjectsData[pid]) openProjectSidebar(mapProjectsData[pid]);
                }, 800);
            } else { 
                showToast("Error updating status.", 'error');
                selectElement.style.backgroundColor = originalBg;
                selectElement.style.color = originalColor;
            }
        }).catch(err => {
            selectElement.disabled = false;
            showToast("Network error occurred.", 'error');
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
                            el.className = 'custom-marker';
                            el.style.backgroundColor = project.available_units > 0 ? '#10B981' : '#EF4444'; 
                            el.style.width = '24px'; el.style.height = '24px';
                            el.style.borderRadius = '50%'; el.style.border = '3px solid white';
                            el.style.boxShadow = '0 0 10px rgba(0,0,0,0.8)'; el.style.cursor = 'pointer';

                            new mapboxgl.Marker(el)
                                .setLngLat([project.longitude, project.latitude])
                                .addTo(map);

                            el.addEventListener('click', () => { openProjectSidebar(project); });
                        }
                    });
                }
            });
    });

    // Fallback for older api calls
    function managerUpdateStatus(propertyId, newStatus, selectElement) {
        managerUpdateStatusWithResale(propertyId, newStatus, selectElement, null);
    }

    function togglePriceEdit(id) {
        const disp = document.getElementById('price_disp_' + id);
        const edit = document.getElementById('price_edit_' + id);
        if (disp.style.display === 'none') {
            disp.style.display = 'block'; edit.style.display = 'none';
        } else {
            disp.style.display = 'none'; edit.style.block = 'block';
        }
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
                setTimeout(() => {
                    const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid');
                    if(pid && mapProjectsData[pid]) openProjectSidebar(mapProjectsData[pid]);
                }, 800);
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

    function holdProperty(propertyId) {
        if(!confirm("Are you sure you want to put this unit on hold? You will have 7 days to finalize.")) return;
        let formData = new FormData(); formData.append('action', 'hold_property'); formData.append('property_id', propertyId);
        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) { showToast("Property put on hold!", "success"); setTimeout(() => location.reload(), 800); } 
            else { showToast("Error: " + data.message, "error"); }
        });
    }

    function requestReserve(propertyId) {
        if(!confirm("Are you sure you want to transition this unit to Proceeding?")) return;
        let formData = new FormData(); formData.append('action', 'request_reserved'); formData.append('property_id', propertyId);
        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) { showToast("Status updated to Proceeding!", "success"); setTimeout(() => location.reload(), 800); } 
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
        if(fileInput.files.length === 0) {
            alert("Please select a file to upload."); return;
        }
        
        let file = fileInput.files[0];
        let btn = this.querySelector('button[type="submit"]');
        btn.innerHTML = 'Connecting to Cloudflare...'; 
        btn.disabled = true;

        try {
            let authData = new FormData();
            authData.append('action', 'get_upload_url');
            authData.append('filename', file.name);
            authData.append('mime_type', file.type || 'application/octet-stream');

            let authRes = await fetch('api/upload_sales_media.php', { method: 'POST', body: authData });
            let authJson = await authRes.json();
            
            if(!authJson.success) throw new Error(authJson.message);

            btn.innerHTML = 'Uploading file...';
            
            await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('PUT', authJson.url, true);
                xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                
                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 300) { resolve(); } 
                    else { reject(new Error('Cloudflare rejected the upload. Check console.')); }
                };
                xhr.onerror = () => reject(new Error('Network Error during upload.'));
                xhr.send(file);
            });

            btn.innerHTML = 'Saving Data...';
            let dbData = new FormData(this);
            dbData.append('action', 'save_record');
            dbData.append('file_key', authJson.key);
            dbData.append('filename', file.name);

            let dbRes = await fetch('api/upload_sales_media.php', { method: 'POST', body: dbData });
            let dbJson = await dbRes.json();
            
            if(dbJson.success) { 
                alert(dbJson.message); 
                location.reload(); 
            } else { 
                throw new Error(dbJson.message); 
            }

        } catch (err) {
            alert('Error: ' + err.message);
            console.error(err);
            btn.innerHTML = 'Upload to Cloudflare'; 
            btn.disabled = false;
        }
    });

    // Handle AJAX status updates from the dynamic HTML interceptor
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_unit_status') {
        if (!in_array($_SESSION['role'], ['admin', 'system_manager', 'sales_manager', 'director'])) { http_response_code(403); exit; }
        
        $unitId = (int)$_POST['unit_id'];
        $status = $_POST['status'];
        $resale = !empty($_POST['resale_price']) ? (float)$_POST['resale_price'] : null;
        
        if ($status !== 'Resale') {
            $resale = null;
        }
        
        $pdo->prepare("UPDATE project_units SET status = ?, resale_price = ? WHERE id = ?")->execute([$status, $resale, $unitId]);
        echo "OK";
        exit;
    }
    ?>
</script>

<?php require_once 'footer.php'; ?>
