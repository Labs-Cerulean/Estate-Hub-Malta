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
        position: relative;
    }
    .map-header {
        padding: 1rem 1.5rem;
        background: var(--bg-secondary);
        border-bottom: 1px solid var(--border-glass);
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 1000;
    }
    #projectMap {
        flex: 1;
        width: 100%;
        background: #1a1a24; 
        z-index: 1;
    }
    
    /* Custom Popup Styles */
    .leaflet-popup-content-wrapper { background: var(--bg-card); color: var(--text-primary); border-radius: 8px; border: 1px solid var(--border-glass); box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
    .leaflet-popup-tip { background: var(--bg-card); border: 1px solid var(--border-glass); }
    .popup-title { font-size: 1.1rem; font-weight: bold; color: var(--primary-color); margin-bottom: 0.25rem; }
    .popup-meta { font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.75rem; }
    .popup-btn { display: inline-block; background: var(--primary-color); color: white; padding: 0.4rem 0.8rem; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.8rem; text-align: center; width: 100%; }
    .popup-btn:hover { background: var(--primary-hover); color: white; }

    /* Custom Marker Pin */
    .custom-pin {
        display: flex; align-items: center; justify-content: center;
    }
    .custom-pin-inner {
        width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.6);
    }

    /* Floating Legend */
    .map-legend {
        position: absolute; bottom: 30px; left: 15px; z-index: 999;
        background: rgba(30, 30, 45, 0.9); backdrop-filter: blur(5px);
        border: 1px solid var(--border-glass); border-radius: 8px;
        padding: 1rem; box-shadow: 0 4px 15px rgba(0,0,0,0.5);
        max-height: 300px; overflow-y: auto; min-width: 200px;
    }
    .legend-title { font-weight: bold; font-size: 0.85rem; color: var(--text-primary); margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.25rem; }
    .legend-item { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.4rem; font-size: 0.85rem; color: var(--text-secondary); }
    .legend-color { width: 14px; height: 14px; border-radius: 50%; border: 2px solid white; box-shadow: 0 1px 3px rgba(0,0,0,0.4); }
</style>

<div class="main-container" style="max-width: 100%;">
    <div style="margin-bottom: 1rem;">
        <h1 class="page-title" style="margin-bottom: 0;">Portfolio Map View</h1>
        <p style="color: var(--text-secondary); margin-top: 0.25rem;">Interactive geographical distribution. Exact coordinates are used if available, otherwise clustered by locality.</p>
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
        
        <div class="map-legend" id="mapLegend" style="display: none;">
            <div class="legend-title">Developers / Clients</div>
            <div id="legendContent"></div>
        </div>
    </div>
</div>

<script>
// 1. Hardcoded Locality Fallbacks
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

// 2. Dynamic Color Palette for Developers
const colorPalette = [
    '#ef4444', // Red
    '#3b82f6', // Blue
    '#10b981', // Green
    '#f59e0b', // Amber
    '#a855f7', // Purple
    '#ec4899', // Pink
    '#06b6d4', // Cyan
    '#f97316', // Orange
    '#8b5cf6', // Violet
    '#14b8a6'  // Teal
];

let clientColors = {};
let colorIndex = 0;

function getClientColor(clientName) {
    let name = clientName ? clientName.trim() : 'In-House (Internal)';
    if (!clientColors[name]) {
        clientColors[name] = colorPalette[colorIndex % colorPalette.length];
        colorIndex++;
    }
    return clientColors[name];
}

// Load Data
const projectsData = <?= json_encode($mapProjects, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// 3. Initialize Map (Dark theme)
const map = L.map('projectMap').setView([35.91, 14.4], 11);
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; OpenStreetMap',
    subdomains: 'abcd',
    maxZoom: 19
}).addTo(map);

let markersGroup = L.markerClusterGroup({
    maxClusterRadius: 35,
    spiderfyOnMaxZoom: true,
    showCoverageOnHover: false,
    zoomToBoundsOnClick: true
});

function renderMarkers(filterStage = 'all') {
    markersGroup.clearLayers();
    let visibleCount = 0;
    
    // Track which clients are visible to build the legend
    let visibleClients = new Set();

    projectsData.forEach(p => {
        if (filterStage !== 'all' && p.stage !== filterStage) return;

        // EXACT COORDS VS FALLBACK LOGIC
        let coords;
        if (p.latitude && p.longitude && p.latitude !== '' && p.longitude !== '') {
            coords = [parseFloat(p.latitude), parseFloat(p.longitude)];
        } else {
            coords = localityCoords[p.city] || [35.91, 14.4];
        }

        const clientName = p.client_name || 'In-House (Internal)';
        const pinColor = getClientColor(clientName);
        visibleClients.add(clientName);

        // Create Custom HTML Pin
        const customIcon = L.divIcon({
            className: 'custom-pin',
            html: `<div class="custom-pin-inner" style="background-color: ${pinColor};"></div>`,
            iconSize: [26, 26],
            iconAnchor: [13, 13]
        });
        
        const marker = L.marker(coords, { icon: customIcon });
        
        const popupContent = `
            <div style="min-width: 200px;">
                <div class="popup-title">${p.name}</div>
                <div class="popup-meta">
                    <strong>Developer:</strong> <span style="color: ${pinColor}; font-weight: bold;">${clientName}</span><br>
                    <strong>Location:</strong> ${p.city}<br>
                    <strong>Stage:</strong> <span style="color: #fff;">${p.stage}</span><br>
                    <span style="font-size: 0.75rem; color: #6b7280; font-style: italic;">
                        ${(p.latitude && p.longitude) ? '📍 Exact Coordinates' : '📍 Locality Approximation'}
                    </span>
                </div>
                <a href="${p.url}" class="popup-btn">Open Project Dashboard</a>
            </div>
        `;
        
        marker.bindPopup(popupContent);
        markersGroup.addLayer(marker);
        visibleCount++;
    });

    map.addLayer(markersGroup);
    document.getElementById('projectCount').textContent = `Showing ${visibleCount} projects`;

    // Render Legend
    const legendContainer = document.getElementById('mapLegend');
    const legendContent = document.getElementById('legendContent');
    legendContent.innerHTML = '';
    
    if (visibleClients.size > 0) {
        legendContainer.style.display = 'block';
        Array.from(visibleClients).sort().forEach(client => {
            const color = getClientColor(client);
            legendContent.innerHTML += `
                <div class="legend-item">
                    <div class="legend-color" style="background-color: ${color};"></div>
                    <span>${client}</span>
                </div>
            `;
        });
    } else {
        legendContainer.style.display = 'none';
    }
}

// Initial Render
renderMarkers();

// Filtering
document.getElementById('stageFilter').addEventListener('change', function(e) {
    renderMarkers(e.target.value);
});
</script>

<?php require_once 'footer.php'; ?>
