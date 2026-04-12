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
                <option value="maisonette">Maisonettes</option>
                <option value="house">Houses</option>
                <option value="villa">Villas</option>
                <option value="commercial">Commercial</option>
                <option value="garage">Garages</option>
                <option value="parking space">Car Spaces</option>
            </select>
            
            <div class="text-center text-secondary small mb-3" style="font-size: 0.75rem;">
                <i class="fas fa-mouse"></i> Right-Click & Drag to Rotate 3D Map
            </div>
            
            <?php if(in_array($_SESSION['role'], ['admin', 'system_manager', 'sales_manager', 'director'])): ?>
                <hr style="border-color: rgba(255,255,255,0.2);">
                <button id="viewToggleBtn" class="btn btn-outline-warning btn-sm btn-block w-100 mb-3 shadow-sm" style="border-radius: 20px; font-weight: bold;" onclick="toggleViewMode()">
                    <i class="fas fa-eye"></i> View as Agent
                </button>
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
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['name']) . '</option>'; }
                    } catch (Exception $e) {}
                    ?>
                </select>
            </div>
            <div class="form-group mb-3">
                <label class="form-label text-light">CSV File</label>
                <input class="form-control bg-dark text-light border-secondary" type="file" name="frame_csv" accept=".csv" required>
            </div>
          </div>
          <div class="modal-footer border-secondary">
            <button type="submit" class="btn btn-primary">Upload & Import</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
    // ========================================================
    // VIEW MODE ENGINE
    // ========================================================
    const userRole = '<?= $_SESSION['role'] ?>';
    const isManagerUser = ['admin', 'director', 'system_manager', 'sales_manager'].includes(userRole);
    let currentViewMode = isManagerUser ? 'manager' : 'agent';

    function toggleViewMode() {
        const btn = document.getElementById('viewToggleBtn');
        if (currentViewMode === 'manager') {
            currentViewMode = 'agent';
            btn.innerHTML = '<i class="fas fa-user-tie"></i> Revert to Manager View';
            btn.classList.replace('btn-outline-warning', 'btn-warning');
        } else {
            currentViewMode = 'manager';
            btn.innerHTML = '<i class="fas fa-eye"></i> View as Agent';
            btn.classList.replace('btn-warning', 'btn-outline-warning');
        }
        
        const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid');
        if (pid && mapProjectsData[pid]) openProjectSidebar(mapProjectsData[pid]);
    }

    // ========================================================
    // STANDARD UI FUNCTIONS
    // ========================================================
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? '#10B981' : '#EF4444';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        
        toast.style.cssText = `background: ${bgColor}; color: white; padding: 14px 24px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); font-size: 0.95rem; font-weight: 600; opacity: 0; transform: translateY(20px); transition: all 0.3s ease; display: flex; align-items: center; gap: 10px;`;
        toast.innerHTML = `<i class="fas ${icon} fa-lg"></i> ${message}`;
        
        container.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '1'; toast.style.transform = 'translateY(0)'; }, 10);
        setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
    }

    function setFilter(filterType) {
        document.getElementById('btnFilterAll').className = filterType === 'All' ? 'btn btn-info w-100 shadow-sm' : 'btn btn-outline-info w-100 shadow-sm';
        document.getElementById('btnFilterAvail').className = filterType === 'Available' ? 'btn btn-success w-100 shadow-sm' : 'btn btn-outline-success w-100 shadow-sm';

        const cards = document.querySelectorAll('.unit-card');
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
    function closeSidebar() { document.getElementById('custom-sidebar').classList.remove('show-sidebar'); }
    function openUploadModal() { document.getElementById('uploadFrameModal').style.display = 'block'; }
    function closeUploadModal() { document.getElementById('uploadFrameModal').style.display = 'none'; }

    // ========================================================
    // MAPBOX INTEGRATION (Zoomed Out Default)
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
                        el.addEventListener('click', () => openProjectSidebar(project));
                    }
                });
            }
        });
    });

    // ========================================================
    // SURGICAL HTML INTERCEPTOR (Data Integrity Guaranteed)
    // ========================================================
    function openProjectSidebar(project) {
        map.flyTo({ center: [project.longitude, project.latitude], zoom: 17, pitch: 50, essential: true });
        
        document.getElementById('sidebarProjectName').innerText = project.project_name;
        document.getElementById('sidebarProjectName').setAttribute('data-pid', project.project_id);
        document.getElementById('sidebarAvail').innerText = project.available_units;
        document.getElementById('sidebarHold').innerText = project.held_units;
        document.getElementById('sidebarSold').innerText = project.sold_units;

        setFilter('All');
        document.getElementById('custom-sidebar').classList.add('show-sidebar');
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
                            actionWrapper.className = 'mt-3 pt-2 border-top border-secondary';

                            if (status === 'Available' || status === 'BOM') {
                                actionWrapper.innerHTML = `
                                    <div class="d-flex" style="gap: 10px;">
                                        <button class="btn btn-warning btn-sm w-100 font-weight-bold shadow-sm" onclick="holdProperty(${unitId})"><i class="fas fa-pause"></i> Hold</button>
                                        <button class="btn btn-success btn-sm w-100 font-weight-bold shadow-sm" onclick="requestReserve(${unitId})"><i class="fas fa-check"></i> Proceed</button>
                                    </div>
                                `;
                            } else {
                                actionWrapper.innerHTML = `
                                    <div class="text-center text-secondary small font-weight-bold text-uppercase">
                                        Current Status: <span class="text-light">${status}</span>
                                    </div>
                                `;
                            }
                            cardBody.appendChild(actionWrapper);

                        } else {
                            // MANAGER VIEW
                            // The API provides perfect Manager HTML by default. We just safely inject the Resale input if missing.
                            const selectEl = card.querySelector('select[onchange*="managerUpdateStatus"]');
                            if (selectEl && !card.querySelector('.resale-input')) {
                                
                                // Expand options safely
                                selectEl.innerHTML = `
                                    <option value="Available" ${status === 'Available' ? 'selected' : ''}>Available</option>
                                    <option value="On Hold" ${status === 'On Hold' ? 'selected' : ''}>On Hold</option>
                                    <option value="Resale" ${status === 'Resale' ? 'selected' : ''}>Resale</option>
                                    <option value="BOM" ${status === 'BOM' ? 'selected' : ''}>BOM</option>
                                    <option value="Proceeding" ${status === 'Proceeding' ? 'selected' : ''}>Proceeding</option>
                                    <option value="Sold" ${status === 'Sold' ? 'selected' : ''}>Sold</option>
                                `;

                                const resaleInput = document.createElement('input');
                                resaleInput.type = 'number';
                                resaleInput.step = '0.01';
                                resaleInput.className = 'form-control form-control-sm mt-2 bg-dark text-info border-info resale-input';
                                resaleInput.id = 'resale_input_' + unitId;
                                resaleInput.placeholder = 'Resale Asking Price (€)';
                                resaleInput.style.display = status === 'Resale' ? 'block' : 'none';
                                selectEl.parentNode.insertBefore(resaleInput, selectEl.nextSibling);
                                
                                selectEl.setAttribute('onchange', `executeStatusUpdate(${unitId}, this)`);
                            }
                        }
                    });

                    document.getElementById('unitListContainer').innerHTML = tempDiv.innerHTML;
                    
                    // Render Media normally
                    let slides = [];
                    if (unitData.media && unitData.media.videos) { unitData.media.videos.forEach(v => slides.push(`<video src="${v}" controls style="width:100%; height:250px; object-fit:cover; border-radius:8px;"></video>`)); }
                    if (unitData.media && unitData.media.renders) { unitData.media.renders.forEach(r => slides.push(`<img src="${r}" style="width:100%; height:250px; object-fit:cover; border-radius:8px;">`)); }
                    
                    let mediaHtml = slides.length > 0 ? `<div class="p-3">${slides[0]}</div>` : `<div class="text-center p-5"><i class="fas fa-image fa-3x text-secondary mb-2"></i><div class="small text-secondary">No media uploaded</div></div>`;
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
                if(pid) setTimeout(() => openProjectSidebar(mapProjectsData[pid]), 500);
            } else { showToast("Error updating status.", 'error'); }
        });
    }

    function jumpToSelectedProject(projectId) {
        if(projectId && mapProjectsData[projectId]) openProjectSidebar(mapProjectsData[projectId]);
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
            if(data.success) { showToast("Price updated!", "success"); const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid'); if(pid) setTimeout(() => openProjectSidebar(mapProjectsData[pid]), 500); } 
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
            if(data.success) { showToast("Put on hold!", "success"); const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid'); if(pid) setTimeout(() => openProjectSidebar(mapProjectsData[pid]), 500); } 
        });
    }

    function requestReserve(propertyId) {
        if(!confirm("Are you sure you want to transition this unit to Proceeding?")) return;
        let formData = new FormData(); formData.append('action', 'request_reserved'); formData.append('property_id', propertyId);
        fetch('api/sales_actions.php', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
            if(data.success) { showToast("Status updated to Proceeding!", "success"); const pid = document.getElementById('sidebarProjectName').getAttribute('data-pid'); if(pid) setTimeout(() => openProjectSidebar(mapProjectsData[pid]), 500); } 
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
