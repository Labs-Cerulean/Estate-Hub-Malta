<?php
require_once 'config.php';
require_once 'session-check.php';

$allowed_roles = ['sales_manager', 'sales_agent', 'system_manager', 'director'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

require_once 'header.php'; // Your standard header
?>

<script src='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js'></script>
<link href='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css' rel='stylesheet' />

<style>
    /* Full height map minus the navbar */
    #sales-map { position: absolute; top: 70px; bottom: 0; width: 100%; left: 0; }
    
    /* Sleek UI Overrides */
    .mapboxgl-popup-content { border-radius: 12px; padding: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
    .status-badge { font-size: 0.8rem; padding: 5px 10px; border-radius: 20px; font-weight: 600; }
    
    /* Ensure the main container doesn't restrict the absolute map */
    .container-fluid.main-content { padding: 0 !important; }
</style>

<div id='sales-map'></div>

<div class="position-absolute top-0 start-0 m-3" style="z-index: 10; margin-top: 85px !important;">
    <div class="card shadow-sm border-0" style="border-radius: 15px; background: rgba(255,255,255,0.9); backdrop-filter: blur(10px);">
        <div class="card-body p-3">
            <h5 class="mb-3 fw-bold">Sales Hub</h5>
            <select class="form-select mb-2 rounded-pill shadow-sm" id="typeFilter">
                <option value="all">All Property Types</option>
                <option value="apartment">Apartments</option>
                <option value="commercial">Commercial</option>
                <option value="garage">Garages</option>
            </select>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="propertySidebar" aria-labelledby="propertySidebarLabel" style="width: 450px; border-left: none; box-shadow: -5px 0 25px rgba(0,0,0,0.1);">
  <div class="offcanvas-header bg-dark text-white">
    <h5 class="offcanvas-title fw-bold" id="sidebarProjectName">Project Details</h5>
    <button type="button" class="btn-close btn-close-white text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-0">
    <div class="bg-light text-center p-5 border-bottom">
        <i class="fas fa-building fa-4x text-muted mb-3"></i>
        <p class="text-muted small">Project renders and videos will appear here.</p>
    </div>
    
    <div class="p-4">
        <div class="d-flex justify-content-between mb-4">
            <span class="badge bg-success status-badge"><span id="sidebarAvail">0</span> Available</span>
            <span class="badge bg-warning text-dark status-badge"><span id="sidebarHold">0</span> On Hold</span>
            <span class="badge bg-danger status-badge"><span id="sidebarSold">0</span> Sold</span>
        </div>

        <h6 class="fw-bold mb-3 text-uppercase text-muted">Available Units</h6>
        
        <div id="unitListContainer" class="list-group list-group-flush border-top border-bottom">
            <div class="text-center p-3 text-muted spinner-border mx-auto d-none" id="unitLoader" role="status"></div>
            </div>
    </div>
  </div>
</div>

<script>
    // 1. Initialize Mapbox (Replace with your free Mapbox Access Token)
    mapboxgl.accessToken = 'YOUR_MAPBOX_PUBLIC_TOKEN_HERE'; 
    const map = new mapboxgl.Map({
        container: 'sales-map',
        style: 'mapbox://styles/mapbox/light-v11', // Sleek, modern light theme
        center: [14.405, 35.937], // Centered on Malta
        zoom: 11,
        pitch: 45, // Adds a slight 3D tilt
    });

    // Add zoom and rotation controls to the map.
    map.addControl(new mapboxgl.NavigationControl(), 'bottom-right');

    // 2. Fetch Data and Add Markers
    map.on('load', () => {
        fetch('api/get_sales_map_data.php')
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    data.data.forEach(project => {
                        if(project.latitude && project.longitude) {
                            // Create a custom HTML marker
                            const el = document.createElement('div');
                            el.className = 'custom-marker';
                            el.style.backgroundColor = project.available_units > 0 ? '#198754' : '#dc3545'; // Green if avail, Red if sold out
                            el.style.width = '24px';
                            el.style.height = '24px';
                            el.style.borderRadius = '50%';
                            el.style.border = '3px solid white';
                            el.style.boxShadow = '0 0 10px rgba(0,0,0,0.3)';
                            el.style.cursor = 'pointer';

                            // Add marker to map
                            const marker = new mapboxgl.Marker(el)
                                .setLngLat([project.longitude, project.latitude])
                                .addTo(map);

                            // 3. Handle Marker Clicks (Open Sidebar)
                            el.addEventListener('click', () => {
                                // Fly to location
                                map.flyTo({ center: [project.longitude, project.latitude], zoom: 15 });
                                
                                // Populate Sidebar Header Data
                                document.getElementById('sidebarProjectName').innerText = project.project_name;
                                document.getElementById('sidebarAvail').innerText = project.available_units;
                                document.getElementById('sidebarHold').innerText = project.held_units;
                                document.getElementById('sidebarSold').innerText = project.sold_units;

                                // Show the offcanvas sidebar
                                const sidebar = new bootstrap.Offcanvas(document.getElementById('propertySidebar'));
                                sidebar.show();
                                
                                // TODO: Add an AJAX call here to fetch the specific units for `project.project_id` 
                                // and inject the HTML rows (with "Hold" / "Request Reserve" buttons) into `#unitListContainer`.
                            });
                        }
                    });
                }
            });
    });
</script>

<?php require_once 'footer.php'; ?>
