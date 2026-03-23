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
    
    /* Dark Theme Filter Overlay */
    .filter-overlay {
        position: absolute; top: 15px; left: 15px; z-index: 10; width: 280px;
        border-radius: 15px; 
        background: rgba(33, 37, 41, 0.85); /* Dark Grey */
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.1);
        color: #f8f9fa;
    }
    
    /* Dark Theme Sidebar */
    #custom-sidebar {
        position: fixed; top: 70px; right: -450px; width: 450px; height: calc(100vh - 70px);
        background-color: #212529; /* Dark Grey Panel */
        color: #f8f9fa;
        box-shadow: -5px 0 25px rgba(0,0,0,0.5);
        transition: right 0.3s ease-in-out; z-index: 1050; overflow-y: auto;
    }
    #custom-sidebar.show-sidebar { right: 0; }
   .sidebar-header { 
        position: sticky; /* Locks it to the top */
        top: 0; 
        z-index: 1060; /* Keeps it layered above the scrolling text */
        background-color: #1a1d20; 
        color: white; 
        padding: 15px 20px; 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        border-bottom: 1px solid rgba(255,255,255,0.05); 
    }
    .status-badge { font-size: 0.8rem; padding: 5px 10px; border-radius: 20px; font-weight: 600; display: inline-block;}
    
    /* Custom Scrollbar for sidebar */
    #custom-sidebar::-webkit-scrollbar { width: 6px; }
    #custom-sidebar::-webkit-scrollbar-track { background: #212529; }
    #custom-sidebar::-webkit-scrollbar-thumb { background: #495057; border-radius: 3px; }
</style>

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
                <option value="commercial">Commercial</option>
                <option value="garage">Garages</option>
            </select>
            
            <?php if(in_array($_SESSION['role'], ['admin', 'system_manager', 'sales_manager', 'director'])): ?>
                <hr style="border-color: rgba(255,255,255,0.2);">
                <button class="btn btn-outline-info btn-sm btn-block w-100" style="border-radius: 20px;" onclick="openUploadModal()">
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
    <div class="text-center p-5 border-bottom" style="background-color: #2c3034; border-color: #343a40 !important;">
        <i class="fas fa-building fa-4x text-secondary mb-3"></i>
        <p class="text-secondary small m-0">Project renders and videos will appear here.</p>
    </div>
    <div class="p-3">
        <div class="d-flex justify-content-between mb-4 px-2">
            <span class="badge badge-success bg-success status-badge"><span id="sidebarAvail">0</span> Avail</span>
            <span class="badge badge-warning bg-warning text-dark status-badge"><span id="sidebarHold">0</span> Hold</span>
            <span class="badge badge-danger bg-danger status-badge"><span id="sidebarSold">0</span> Sold</span>
        </div>
        <h6 class="font-weight-bold fw-bold mb-3 text-uppercase text-light px-2">Available Units</h6>
        <div id="unitListContainer"></div>
    </div>
  </div>
</div>

<div class="modal fade" id="uploadFrameModal" tabindex="-1" role="dialog" style="display: none; transition: opacity 0.3s linear;">
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
                <small class="form-text text-secondary">Ensure file is saved as a CSV matching the 8-column template.</small>
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
    function closeSidebar() { document.getElementById('custom-sidebar').classList.remove('show-sidebar'); }

    let mapProjectsData = {};

    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw'; 
    const map = new mapboxgl.Map({
        container: 'sales-map',
        style: 'mapbox://styles/mapbox/satellite-streets-v12', 
        center: [14.405, 35.937], 
        zoom: 12,
        pitch: 40, // More natural tilt
        bearing: 0 // Straight North orientation
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
        document.getElementById('sidebarAvail').innerText = project.available_units;
        document.getElementById('sidebarHold').innerText = project.held_units;
        document.getElementById('sidebarSold').innerText = project.sold_units;

        document.getElementById('custom-sidebar').classList.add('show-sidebar');
        document.getElementById('unitListContainer').innerHTML = '<div class="text-center p-4 text-light"><div class="spinner-border text-info" role="status"></div><div class="mt-2">Loading units...</div></div>';

        fetch('api/get_project_units.php?project_id=' + project.project_id)
            .then(response => response.json())
            .then(unitData => {
                if(unitData.success) document.getElementById('unitListContainer').innerHTML = unitData.html;
                else document.getElementById('unitListContainer').innerHTML = '<div class="p-3 text-center text-danger">Error loading units.</div>';
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
                            el.style.backgroundColor = project.available_units > 0 ? '#10B981' : '#EF4444'; // Adjusted to match your specific green/red
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
