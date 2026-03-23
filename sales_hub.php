<?php
require_once 'config.php';
require_once 'session-check.php';

$allowed_roles = ['sales_manager', 'sales_agent', 'admin', 'director', 'system_manager'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

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
        border-radius: 15px; background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);
    }
    
    #custom-sidebar {
        position: fixed; top: 70px; right: -450px; width: 450px; height: calc(100vh - 70px);
        background-color: #ffffff; box-shadow: -5px 0 25px rgba(0,0,0,0.15);
        transition: right 0.3s ease-in-out; z-index: 1050; overflow-y: auto;
    }
    #custom-sidebar.show-sidebar { right: 0; }
    .sidebar-header { background-color: #212529; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
    .status-badge { font-size: 0.8rem; padding: 5px 10px; border-radius: 20px; font-weight: 600; display: inline-block;}
</style>

<div id="map-wrapper">
    <div id='sales-map'></div>

    <div class="card shadow-sm border-0 filter-overlay">
        <div class="card-body p-3">
            <h5 class="mb-3 font-weight-bold fw-bold"><i class="fas fa-map-marked-alt text-primary"></i> Sales Hub</h5>
            
            <label class="form-label small font-weight-bold fw-bold text-muted mb-1">Jump to Project</label>
            <select class="form-control form-select mb-3 rounded-pill shadow-sm border-primary" id="projectJumpDropdown" onchange="jumpToSelectedProject(this.value)">
                <option value="">-- Select Project Map Pin --</option>
            </select>

            <select class="form-control form-select mb-3 rounded-pill shadow-sm" id="typeFilter">
                <option value="all">All Property Types</option>
                <option value="apartment">Apartments</option>
                <option value="commercial">Commercial</option>
                <option value="garage">Garages</option>
            </select>
            
            <?php if(in_array($_SESSION['role'], ['admin', 'system_manager', 'sales_manager', 'director'])): ?>
                <hr>
                <button class="btn btn-outline-primary btn-sm btn-block w-100" style="border-radius: 20px;" onclick="openUploadModal()">
                    <i class="fas fa-file-upload"></i> Upload Frame (CSV)
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
    <div class="bg-light text-center p-5 border-bottom">
        <i class="fas fa-building fa-4x text-muted mb-3"></i>
        <p class="text-muted small m-0">Project renders and videos will appear here.</p>
    </div>
    <div class="p-4">
        <div class="d-flex justify-content-between mb-4">
            <span class="badge badge-success bg-success status-badge"><span id="sidebarAvail">0</span> Available</span>
            <span class="badge badge-warning bg-warning text-dark status-badge"><span id="sidebarHold">0</span> On Hold</span>
            <span class="badge badge-danger bg-danger status-badge"><span id="sidebarSold">0</span> Sold</span>
        </div>
        <h6 class="font-weight-bold fw-bold mb-3 text-uppercase text-muted">Available Units</h6>
        <div id="unitListContainer" class="list-group list-group-flush border-top border-bottom"></div>
    </div>
  </div>
</div>

<div class="modal fade" id="uploadFrameModal" tabindex="-1" role="dialog" style="display: none; transition: opacity 0.3s linear;">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Upload Project Frame</h5>
        <button type="button" class="close btn-close" aria-label="Close" onclick="closeUploadModal()" style="background: transparent; border: none; font-size: 1.5rem;"><span aria-hidden="true">&times;</span></button>
      </div>
      <form id="uploadFrameForm">
          <div class="modal-body">
            <div class="form-group mb-3">
                <label class="form-label">Select Project</label>
                <select class="form-control form-select" name="project_id" required>
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
                <label class="form-label">CSV File</label>
                <input class="form-control" type="file" name="frame_csv" accept=".csv" required>
                <small class="form-text text-muted">Ensure file is saved as a CSV matching the 8-column template.</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-primary">Upload & Import</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
    // Bulletproof Modal & Sidebar Functions
    function openUploadModal() {
        const m = document.getElementById('uploadFrameModal');
        m.classList.add('show'); m.style.display = 'block'; m.style.backgroundColor = 'rgba(0,0,0,0.5)';
        setTimeout(() => m.style.opacity = '1', 10);
    }
    function closeUploadModal() {
        const m = document.getElementById('uploadFrameModal');
        m.style.opacity = '0';
        setTimeout(() => { m.classList.remove('show'); m.style.display = 'none'; }, 300);
    }
    function closeSidebar() { document.getElementById('custom-sidebar').classList.remove('show-sidebar'); }

    // Store map projects globally so we can jump to them
    let mapProjectsData = {};

    // Mapbox Initialization (Now with Satellite & 3D Tilt)
    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw'; 
    const map = new mapboxgl.Map({
        container: 'sales-map',
        style: 'mapbox://styles/mapbox/satellite-streets-v12', // Beautiful Satellite view
        center: [14.405, 35.937], 
        zoom: 12,
        pitch: 60, // Deep 3D Pitch
        bearing: -15 // Slight angle rotation
    });

    map.addControl(new mapboxgl.NavigationControl(), 'bottom-right');

    // Add 3D Extruded Buildings layer when style loads
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

    // The function that triggers the sidebar opening and fetching
    function openProjectSidebar(project) {
        map.flyTo({ center: [project.longitude, project.latitude], zoom: 17, essential: true });
        
        document.getElementById('sidebarProjectName').innerText = project.project_name;
        document.getElementById('sidebarAvail').innerText = project.available_units;
        document.getElementById('sidebarHold').innerText = project.held_units;
        document.getElementById('sidebarSold').innerText = project.sold_units;

        document.getElementById('custom-sidebar').classList.add('show-sidebar');
        document.getElementById('unitListContainer').innerHTML = '<div class="text-center p-3 text-muted">Loading units...</div>';

        fetch('api/get_project_units.php?project_id=' + project.project_id)
            .then(response => response.json())
            .then(unitData => {
                if(unitData.success) document.getElementById('unitListContainer').innerHTML = unitData.html;
                else document.getElementById('unitListContainer').innerHTML = '<div class="p-3 text-center text-danger">Error loading units.</div>';
            });
    }

    // Function called by the Dropdown
    function jumpToSelectedProject(projectId) {
        if(projectId && mapProjectsData[projectId]) {
            openProjectSidebar(mapProjectsData[projectId]);
        }
    }

    // Fetch Data and Add Markers
    map.on('load', () => {
        fetch('api/get_sales_map_data.php')
            .then(response => response.json())
            .then(data => {
                if(data.success && data.data) {
                    const dropdown = document.getElementById('projectJumpDropdown');

                    data.data.forEach(project => {
                        if(project.latitude && project.longitude) {
                            
                            // Save to global variable for dropdown
                            mapProjectsData[project.project_id] = project;
                            
                            // Add to dropdown menu
                            const opt = document.createElement('option');
                            opt.value = project.project_id;
                            opt.innerHTML = project.project_name;
                            dropdown.appendChild(opt);

                            // Create Map Marker
                            const el = document.createElement('div');
                            el.className = 'custom-marker';
                            el.style.backgroundColor = project.available_units > 0 ? '#198754' : '#dc3545';
                            el.style.width = '24px'; el.style.height = '24px';
                            el.style.borderRadius = '50%'; el.style.border = '3px solid white';
                            el.style.boxShadow = '0 0 10px rgba(0,0,0,0.5)'; el.style.cursor = 'pointer';

                            new mapboxgl.Marker(el)
                                .setLngLat([project.longitude, project.latitude])
                                .addTo(map);

                            // Handle Marker Click
                            el.addEventListener('click', () => { openProjectSidebar(project); });
                        }
                    });
                }
            });
    });

    // Handle Actions (Hold/Reserve)
    function holdProperty(propertyId) {
        if(!confirm("Are you sure you want to put this unit on hold? You will have 7 days to finalize.")) return;
        let formData = new FormData(); formData.append('action', 'hold_property'); formData.append('property_id', propertyId);
        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) { alert("Property put on hold!"); location.reload(); } else { alert("Error: " + data.message); }
        });
    }

    function requestReserve(propertyId) {
        if(!confirm("Are you sure you want to transition this unit to Reserved?")) return;
        let formData = new FormData(); formData.append('action', 'request_reserved'); formData.append('property_id', propertyId);
        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) { alert("Reservation status updated!"); location.reload(); } else { alert("Error: " + data.message); }
        });
    }

    // CSV Upload
    document.getElementById('uploadFrameForm').addEventListener('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this); let btn = this.querySelector('button[type="submit"]');
        btn.innerHTML = 'Uploading...'; btn.disabled = true;
        fetch('api/upload_project_frame.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) { alert(data.message); location.reload(); } else { alert('Error: ' + data.message); btn.innerHTML = 'Upload & Import'; btn.disabled = false; }
        });
    });
</script>

<?php require_once 'footer.php'; ?>
