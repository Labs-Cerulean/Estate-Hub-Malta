<?php
require_once 'config.php';
require_once 'session-check.php';

$allowed_roles = ['sales_manager', 'sales_agent', 'admin', 'director', 'system_manager'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

require_once 'header.php'; // Your standard header
?>

<script src='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js'></script>
<link href='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css' rel='stylesheet' />

<style>
    /* Hide the footer on this specific page for a full-screen app experience */
    footer, .footer, #footer { display: none !important; }
    
    /* Ensure the main container doesn't restrict the layout */
    .container-fluid.main-content, .main-panel { padding: 0 !important; margin: 0 !important; }
    
    /* A relative wrapper forces the absolute map & filter to stay together */
    #map-wrapper {
        position: relative;
        height: calc(100vh - 70px); /* Assumes your header is roughly 70px high */
        width: 100%;
        overflow: hidden;
    }
    
    #sales-map { 
        position: absolute; 
        top: 0; bottom: 0; width: 100%; left: 0; 
    }
    
    /* Ensure the filter stays neatly in the top left of the map */
    .filter-overlay {
        position: absolute;
        top: 15px;
        left: 15px;
        z-index: 10;
        width: 250px;
        border-radius: 15px; 
        background: rgba(255,255,255,0.9); 
        backdrop-filter: blur(10px);
    }
    
    /* CUSTOM SLIDING SIDEBAR (Bulletproof CSS) */
    #custom-sidebar {
        position: fixed;
        top: 70px; /* Adjust if your header is taller/shorter */
        right: -450px; /* Hidden off-screen by default */
        width: 450px;
        height: calc(100vh - 70px);
        background-color: #ffffff;
        box-shadow: -5px 0 25px rgba(0,0,0,0.15);
        transition: right 0.3s ease-in-out;
        z-index: 1050;
        overflow-y: auto;
    }
    
    #custom-sidebar.show-sidebar {
        right: 0; /* Slides in */
    }

    .sidebar-header {
        background-color: #212529;
        color: white;
        padding: 15px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* Sleek UI Overrides */
    .mapboxgl-popup-content { border-radius: 12px; padding: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
    .status-badge { font-size: 0.8rem; padding: 5px 10px; border-radius: 20px; font-weight: 600; display: inline-block;}
</style>

<div id="map-wrapper">
    <div id='sales-map'></div>

    <div class="card shadow-sm border-0 filter-overlay">
        <div class="card-body p-3">
            <h5 class="mb-3 font-weight-bold fw-bold"><i class="fas fa-map-marked-alt text-primary"></i> Sales Hub</h5>
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
        
        <div id="unitListContainer" class="list-group list-group-flush border-top border-bottom">
            </div>
    </div>
  </div>
</div>

<div class="modal fade" id="uploadFrameModal" tabindex="-1" role="dialog" style="display: none; transition: opacity 0.3s linear;">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Upload Project Frame</h5>
        <button type="button" class="close btn-close" aria-label="Close" onclick="closeUploadModal()" style="background: transparent; border: none; font-size: 1.5rem;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="uploadFrameForm">
          <div class="modal-body">
            <div class="form-group mb-3">
                <label class="form-label">Select Project</label>
                <select class="form-control form-select" name="project_id" required>
                    <option value="">-- Choose Project --</option>
                    <?php
                    // Fetch all projects from the database dynamically using the correct 'name' column
                    try {
                        $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name ASC");
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['name']) . '</option>';
                        }
                    } catch (Exception $e) {
                        echo '<option value="">Error loading projects: ' . htmlspecialchars($e->getMessage()) . '</option>';
                    }
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
    // --- Bulletproof Modal Functions ---
    function openUploadModal() {
        const modal = document.getElementById('uploadFrameModal');
        modal.classList.add('show');
        modal.style.display = 'block';
        modal.style.backgroundColor = 'rgba(0,0,0,0.5)'; // Creates the dark background overlay
        setTimeout(() => modal.style.opacity = '1', 10);
    }

    function closeUploadModal() {
        const modal = document.getElementById('uploadFrameModal');
        modal.style.opacity = '0';
        setTimeout(() => {
            modal.classList.remove('show');
            modal.style.display = 'none';
        }, 300);
    }

    // Custom Sidebar Functions
    function closeSidebar() {
        document.getElementById('custom-sidebar').classList.remove('show-sidebar');
    }

    // --- Mapbox Initialization ---
    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw'; 
    const map = new mapboxgl.Map({
        container: 'sales-map',
        style: 'mapbox://styles/mapbox/light-v11',
        center: [14.405, 35.937], 
        zoom: 11,
        pitch: 45, 
    });

    map.addControl(new mapboxgl.NavigationControl(), 'bottom-right');

    // Fetch Data and Add Markers
    map.on('load', () => {
        fetch('api/get_sales_map_data.php')
            .then(response => response.json())
            .then(data => {
                if(data.success && data.data) {
                    data.data.forEach(project => {
                        if(project.latitude && project.longitude) {
                            
                            const el = document.createElement('div');
                            el.className = 'custom-marker';
                            el.style.backgroundColor = project.available_units > 0 ? '#198754' : '#dc3545';
                            el.style.width = '24px';
                            el.style.height = '24px';
                            el.style.borderRadius = '50%';
                            el.style.border = '3px solid white';
                            el.style.boxShadow = '0 0 10px rgba(0,0,0,0.3)';
                            el.style.cursor = 'pointer';

                            new mapboxgl.Marker(el)
                                .setLngLat([project.longitude, project.latitude])
                                .addTo(map);

                            // Handle Marker Clicks
                            el.addEventListener('click', () => {
                                map.flyTo({ center: [project.longitude, project.latitude], zoom: 15 });
                                
                                document.getElementById('sidebarProjectName').innerText = project.project_name;
                                document.getElementById('sidebarAvail').innerText = project.available_units;
                                document.getElementById('sidebarHold').innerText = project.held_units;
                                document.getElementById('sidebarSold').innerText = project.sold_units;

                                // Slide in the custom sidebar
                                document.getElementById('custom-sidebar').classList.add('show-sidebar');
                                
                                document.getElementById('unitListContainer').innerHTML = '<div class="text-center p-3 text-muted">Loading units...</div>';

                                fetch('api/get_project_units.php?project_id=' + project.project_id)
                                    .then(response => response.json())
                                    .then(unitData => {
                                        if(unitData.success) {
                                            document.getElementById('unitListContainer').innerHTML = unitData.html;
                                        } else {
                                            document.getElementById('unitListContainer').innerHTML = '<div class="p-3 text-center text-danger">Error loading units.</div>';
                                        }
                                    });
                            });
                        }
                    });
                }
            });
    });

    // --- Handle CSV Upload Form Submission ---
    document.getElementById('uploadFrameForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        let formData = new FormData(this);
        let submitBtn = this.querySelector('button[type="submit"]');
        submitBtn.innerHTML = 'Uploading...';
        submitBtn.disabled = true;
    
        fetch('api/upload_project_frame.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                alert(data.message);
                location.reload(); 
            } else {
                alert('Error: ' + data.message);
                submitBtn.innerHTML = 'Upload & Import';
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            alert('An unexpected error occurred. Check console.');
            console.error(error);
            submitBtn.innerHTML = 'Upload & Import';
            submitBtn.disabled = false;
        });
    });
</script>

<?php require_once 'footer.php'; ?>
