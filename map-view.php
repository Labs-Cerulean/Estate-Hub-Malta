<?php
require_once 'init.php';
require_once 'session-check.php';

// Ensure user has access to view projects
if (!hasPermission('view_projects') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// Fetch all accessible projects
$projectsRaw = getAccessibleProjects($pdo, getCurrentUserId());

// Prepare project data for the map
$mapProjects = [];
foreach ($projectsRaw as $p) {
    if (($p['project_status'] ?? 'Active') !== 'Active') continue;
    
    // Add stage and URL
    $p['stage'] = deriveProjectStage($pdo, $p['id']);
    $p['url'] = "mobilisation_detail.php?project_id=" . $p['id'];
    
    $mapProjects[] = $p;
}

$pageTitle = 'Geographical Map View';
require_once 'header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

<style>
    .map-container {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 120px);
        background: var(--bg-card);
        border: 1px solid var(--border-glass);
        border-radius: var(--radius-md);
        overflow: hidden;
        box-shadow: var(--shadow-md);
    }
    .map-header {
        padding: 1rem 1.5rem;
        background: var(--bg-secondary);
        border-bottom: 1px solid var(--border-glass);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    #projectMap {
        flex: 1;
        width: 100%;
        background: #1a1a24; /* Dark background before tiles load */
    }
    
    /* Custom Popup Styles */
    .leaflet-popup-content-wrapper {
        background: var(--bg-card);
        color: var(--text-primary);
        border-radius: 8px;
        border: 1px solid var(--border-glass);
        box-shadow: 0 4px 15px rgba(0,0,0,0.5);
    }
    .leaflet-popup-tip {
        background: var(--bg-card);
        border: 1px solid var(--border-glass);
    }
    .popup-title {
        font-size: 1.1rem;
        font-weight: bold;
        color: var(--primary-color);
        margin-bottom: 0.25rem;
    }
    .popup-meta {
        font-size: 0.85rem;
        color: var(--text-secondary);
        margin-bottom: 0.75rem;
    }
    .popup-btn {
        display: inline-block;
        background: var(--primary-color);
        color: white;
        padding: 0.4rem 0.8rem;
        border-radius: 4px;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.8rem;
        text-align: center;
        width: 100%;
    }
    .popup-btn:hover {
        background: var(--primary-hover);
        color: white;
    }
</style>

<div class="main-container" style="max-width: 100%;">
    <div style="margin-bottom: 1rem;">
        <h1 class="page-title" style="margin-bottom: 0;">Portfolio Map View</h1>
        <p style="color: var(--text-secondary); margin-top: 0.25rem;">Interactive geographical distribution of active projects. Projects in the same locality are clustered.</p>
    </div>

    <div class="map-container">
        <div class="map-header">
            <div style="display: flex; gap: 1rem; align-items: center;">
                <span style="font-weight: 600;">Filter by Stage:</span>
                <select id="stageFilter" style="padding: 0.4rem 1rem; border-radius: 4px; background: var(--bg-primary); border: 1px solid var(--border-glass); color: white;">
                    <option value="all">All Active Stages</option>
                    <option value="Mobilisation">Mobilisation</option>
                    <option value="Demolition">Demolition</option>
                    <option value="Excavation">Excavation</option>
                    <option value="Construction">Construction</option>
                    <option value="Finishes">Finishes</option>
                </select>
            </div>
            <div style="font-size: 0.9rem; color: var(--text-muted);" id="projectCount">
                Showing <?= count($mapProjects) ?> projects
            </div>
        </div>
        <div id="projectMap"></div>
    </div>
</div>

<script>
// 1. Hardcoded coordinate dictionary for Localities (Zero Data Entry!)
const localityCoords = {
    // Malta
    "Attard": [35.8914, 14.4431], "Balzan": [35.8983, 14.4533], "Birkirkara": [35.8972, 14.4611],
    "Birżebbuġa": [35.8258, 14.5269], "Bormla (Cospicua)": [35.8814, 14.5219], "Dingli": [35.8961, 14.4000],
    "Fgura": [35.8711, 14.5161], "Floriana": [35.8925, 14.5031], "Għargħur": [35.9031, 14.4525],
    "Gżira": [35.9228, 14.4650], "Ħamrun": [35.8847, 14.4844], "Iklin": [35.9081, 14.4542],
    "Isla (Senglea)": [35.8872, 14.5169], "Kalkara": [35.8889, 14.5222], "Kirkop": [35.9042, 14.4608],
    "Lija": [35.9008, 14.4464], "Luqa": [35.8436, 14.4883], "Marsa": [35.8672, 14.4947],
    "Marsaskala": [35.8272, 14.5447], "Marsaxlokk": [35.8617, 14.5683], "Mdina": [35.8833, 14.4022],
    "Mellieħa": [35.9564, 14.3631], "Mġarr": [35.9214, 14.4467], "Mosta": [35.9014, 14.4256],
    "Mqabba": [35.8425, 14.4756], "Msida": [35.9022, 14.4889], "Mtarfa": [35.8906, 14.3986],
    "Naxxar": [35.9133, 14.4444], "Paola": [35.8728, 14.5081], "Pembroke": [35.9325, 14.4853],
    "Pietà": [35.8933, 14.4939], "Qormi": [35.8789, 14.4694], "Qrendi": [35.8372, 14.4586],
    "Rabat": [35.8817, 14.3989], "Safi": [35.8331, 14.4850], "San Ġiljan (St. Julian's)": [35.9184, 14.4885],
    "San Ġwann": [35.9094, 14.4775], "San Pawl il-Baħar": [35.9483, 14.4014], "Santa Luċija": [35.8239, 14.4944],
    "Santa Venera": [35.8683, 14.4775], "Siġġiewi": [35.8336, 14.4372], "Sliema": [35.9122, 14.5042],
    "Swieqi": [35.9222, 14.4789], "Ta' Xbiex": [35.8992, 14.4936], "Tarxien": [35.8653, 14.5125],
    "Valletta": [35.8989, 14.5146], "Xgħajra": [35.8864, 14.5317], "Żabbar": [35.8678, 14.5367],
    "Żebbuġ": [35.8722, 14.4431], "Żejtun": [35.8683, 14.5333], "Żurrieq": [35.8306, 14.4744],
    // Gozo
    "Fontana": [36.0353, 14.2383], "Għajnsielem": [36.0275, 14.2886], "Għarb": [36.0403, 14.2017],
    "Għasri": [36.0583, 14.2153], "Kerċem": [36.0522, 14.2253], "Munxar": [36.0306, 14.2333],
    "Nadur": [36.0378, 14.2944], "Qala": [36.0392, 14.3083], "San Lawrenz": [36.0544, 14.2044],
    "Sannat": [36.0244, 14.2436], "Victoria (Rabat)": [36.0436, 14.2361], "Xagħra": [36.05, 14.2667],
    "Xewkija": [36.0322, 14.2583], "Żebbuġ (Gozo)": [36.0717, 14.2369]
};

// 2. Load Project Data from PHP
const projectsData = <?= json_encode($mapProjects, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// 3. Initialize Map
// Dark theme map tiles for a modern look
const map = L.map('projectMap').setView([35.91, 14.4], 11); // Center on Malta
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    subdomains: 'abcd',
    maxZoom: 19
}).addTo(map);

// 4. Initialize Marker Cluster Group
// This handles the "Spiderfy" bubble expansion automatically
let markersGroup = L.markerClusterGroup({
    maxClusterRadius: 40,
    spiderfyOnMaxZoom: true,
    showCoverageOnHover: false,
    zoomToBoundsOnClick: true
});

function renderMarkers(filterStage = 'all') {
    markersGroup.clearLayers();
    let visibleCount = 0;

    projectsData.forEach(p => {
        if (filterStage !== 'all' && p.stage !== filterStage) return;

        // Try to find the city in our dictionary. Fallback to Malta center if missing.
        let coords = localityCoords[p.city];
        if (!coords) { coords = [35.91, 14.4]; } 

        const marker = L.marker(coords);
        
        // Build the Popup Card
        const popupContent = `
            <div style="min-width: 200px;">
                <div class="popup-title">${p.name}</div>
                <div class="popup-meta">
                    <strong>City:</strong> ${p.city}<br>
                    <strong>Client:</strong> ${p.client_name || 'N/A'}<br>
                    <strong>Stage:</strong> <span style="color: #10b981; font-weight: bold;">${p.stage}</span>
                </div>
                <a href="${p.url}" class="popup-btn">View Execution Dashboard</a>
            </div>
        `;
        
        marker.bindPopup(popupContent);
        markersGroup.addLayer(marker);
        visibleCount++;
    });

    map.addLayer(markersGroup);
    document.getElementById('projectCount').textContent = `Showing ${visibleCount} projects`;
}

// Initial Render
renderMarkers();

// 5. Handle Filtering
document.getElementById('stageFilter').addEventListener('change', function(e) {
    renderMarkers(e.target.value);
});

</script>

<?php require_once 'footer.php'; ?>
