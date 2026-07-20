<?php
require_once 'config.php';
require_once 'session-check.php';
require_once __DIR__ . '/includes/nav_config.php';

if (!navCanAccessSalesHub()) {
    header('Location: dashboard.php?error=unauthorized_sales_hub');
    exit;
}

if (salesIsExternalAgent()) {
    header('Location: sales_library.php');
    exit;
}

require_once 'header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/maplibre-gl@3.6.2/dist/maplibre-gl.js"></script>
<link href="https://cdn.jsdelivr.net/npm/maplibre-gl@3.6.2/dist/maplibre-gl.css" rel="stylesheet" />

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
    
    /* MapLibre draw controls — scoped to #sales-map */
    #sales-map .maplibregl-ctrl-top-right,
    #sales-map .mapboxgl-ctrl-top-right {
        top: 20px;
        left: 0;
        right: 0;
        margin-left: auto;
        margin-right: auto;
        width: max-content;
        transform: none !important;
        z-index: 25;
        display: flex;
        pointer-events: auto;
    }
    #sales-map .maplibregl-ctrl-group,
    #sales-map .mapboxgl-ctrl-group {
        display: flex !important;
        flex-direction: row !important;
        background: var(--sh-bg-panel);
        border: 2px solid var(--sh-avail);
        border-radius: 12px;
        box-shadow: 0 15px 35px rgba(0,0,0,0.6);
        overflow: hidden;
        pointer-events: auto;
        float: none !important;
    }
    #sales-map .maplibregl-ctrl-group button:not(.sh-satellite-toggle-btn),
    #sales-map .mapboxgl-ctrl-group button:not(.sh-satellite-toggle-btn),
    #sales-map .mapbox-gl-draw_ctrl-draw-btn {
        width: 45px;
        height: 45px;
        cursor: pointer;
        pointer-events: auto;
        box-sizing: border-box;
        border: 0 !important;
        outline: none;
        transform: none !important;
        transition: background-color 0.2s ease;
        /* Sprite icons in mapbox-gl-draw.css are dark — invert to white on dark panel */
        filter: brightness(0) invert(1);
        background-color: transparent !important;
    }
    #sales-map .maplibregl-ctrl-group button + button,
    #sales-map .mapboxgl-ctrl-group button + button {
        border-left: 1px solid rgba(255,255,255,0.15) !important;
        border-top: none !important;
    }
    #sales-map .maplibregl-ctrl-group button:not(.sh-satellite-toggle-btn):hover,
    #sales-map .mapboxgl-ctrl-group button:not(.sh-satellite-toggle-btn):hover,
    #sales-map .mapbox-gl-draw_ctrl-draw-btn:hover {
        transform: none !important;
        filter: brightness(0) invert(1);
        background-color: rgba(255,255,255,0.12) !important;
    }
    #sales-map .maplibregl-ctrl-group button:not(.sh-satellite-toggle-btn).active,
    #sales-map .mapboxgl-ctrl-group button:not(.sh-satellite-toggle-btn).active,
    #sales-map .mapbox-gl-draw_ctrl-draw-btn.active {
        transform: none !important;
        filter: brightness(0) invert(1);
        background-color: rgba(16, 185, 129, 0.35) !important;
    }
    #sales-map .sh-satellite-toggle-btn {
        width: 45px;
        height: 45px;
        cursor: pointer;
        pointer-events: auto;
        box-sizing: border-box;
        border: 0 !important;
        outline: none;
        transform: none !important;
        transition: background-color 0.2s ease;
        background-color: transparent !important;
        /* Dark SVG sprite — same invert treatment as MapboxDraw buttons */
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000000'%3E%3Cpath d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.22.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: center;
        background-size: 20px 20px;
        filter: brightness(0) invert(1);
    }
    #sales-map .sh-satellite-toggle-btn:hover {
        transform: none !important;
        filter: brightness(0) invert(1);
        background-color: rgba(255,255,255,0.12) !important;
    }
    #sales-map .sh-satellite-toggle-btn.active {
        transform: none !important;
        filter: brightness(0) invert(1);
        background-color: rgba(16, 185, 129, 0.35) !important;
    }

    .sh-map-legend { text-align: left; color: var(--sh-text-muted); font-size: 0.75rem; margin-bottom: 10px; background: rgba(0,0,0,0.2); padding: 12px; border-radius: 8px; }
    .sh-legend-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 10px; }
    .sh-legend-item { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; color: var(--sh-text-main); }
    .sh-legend-dot { width: 12px; height: 12px; border-radius: 50%; border: 2px solid #fff; display: inline-block; }
    .sh-legend-dot.avail { background: var(--sh-avail); }
    .sh-legend-dot.none { background: var(--sh-danger); }

    /* MapboxDraw cursor classes → MapLibre canvas */
    .maplibregl-map.mouse-pointer .maplibregl-canvas-container.maplibregl-interactive { cursor: pointer; }
    .maplibregl-map.mouse-move .maplibregl-canvas-container.maplibregl-interactive { cursor: move; }
    .maplibregl-map.mouse-add .maplibregl-canvas-container.maplibregl-interactive { cursor: crosshair; }
    .maplibregl-map.mouse-move.mode-direct_select .maplibregl-canvas-container.maplibregl-interactive { cursor: grab; }
    .maplibregl-map.mode-direct_select.feature-vertex.mouse-move .maplibregl-canvas-container.maplibregl-interactive { cursor: move; }
    .maplibregl-map.mode-direct_select.feature-midpoint.mouse-pointer .maplibregl-canvas-container.maplibregl-interactive { cursor: cell; }
    .maplibregl-map.mode-direct_select.feature-feature.mouse-move .maplibregl-canvas-container.maplibregl-interactive { cursor: move; }
    .maplibregl-map.mode-static.mouse-pointer .maplibregl-canvas-container.maplibregl-interactive { cursor: grab; }
    
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
    .sh-unit-project { display: block; font-size: 0.72rem; font-weight: 700; color: var(--sh-avail); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px; }
    .project-unit-group { display: flex; flex-direction: column; gap: 15px; }
    
    .sh-resale-input { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid var(--sh-resale); background: rgba(168,85,247,0.1); color: #fff; font-weight: bold; margin-bottom: 10px; outline: none; }
    .sh-price-hidden { color: var(--sh-text-muted) !important; font-style: italic !important; font-size: 0.9rem !important; background: rgba(0,0,0,0.2); padding: 4px 8px; border-radius: 4px; }

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
        /* Keep site header visible for hamburger nav + logout (do not hide .site-header) */
        .site-header .header-nav-row { display: none !important; }

        /* Hide the map on small screens — dashboard list mode */
        #sales-map, .maplibregl-control-container, .mapboxgl-control-container { display: none !important; }
        
        #sh-wrapper {
            height: auto;
            min-height: calc(100dvh - 56px);
            overflow-y: auto;
            background: var(--sh-bg-base);
            -webkit-overflow-scrolling: touch;
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
        .sh-sidebar {
            width: 100%;
            right: -100%;
            top: 56px;
            height: calc(100dvh - 56px);
            z-index: 950;
        }
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

<div id="resaleSetupModal" class="vanilla-modal" style="display:none;">
    <div class="vanilla-modal-content" style="max-width: 480px; background: var(--sh-bg-panel); border: 1px solid var(--sh-border); color: #fff;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h4 style="margin:0; font-weight:800;"><i class="fas fa-exchange-alt" style="color:var(--sh-resale);"></i> List for Resale</h4>
            <span class="vanilla-close" onclick="closeResaleModal()" style="cursor:pointer; font-size:1.5rem;">&times;</span>
        </div>
        <p style="color:var(--sh-text-muted); font-size:0.85rem; margin:0 0 16px;">Sold units only. Choose how the resale asking price should be displayed.</p>
        <input type="hidden" id="resaleModalPropertyId" value="">
        <input type="hidden" id="resaleModalMode" value="create">
        <div style="display:flex; gap:10px; margin-bottom:16px;">
            <button type="button" id="resaleModeSingleBtn" class="sh-btn sh-btn-info" style="margin:0; flex:1;" onclick="setResalePricingMode('single')">Single (All-in)</button>
            <button type="button" id="resaleModeSplitBtn" class="sh-btn" style="margin:0; flex:1; background:rgba(168,85,247,0.12); color:#a855f7; border:1px solid rgba(168,85,247,0.35);" onclick="setResalePricingMode('split')">Split (Shell + Works)</button>
        </div>
        <div id="resaleSingleFields" style="display:none; margin-bottom:16px;">
            <label class="sh-label">All-in Asking Price (€)</label>
            <input type="number" step="0.01" min="0" id="resaleSinglePrice" class="sh-input" placeholder="Total asking price">
        </div>
        <div id="resaleSplitFields" style="display:none; margin-bottom:16px;">
            <label class="sh-label">Shell Price (€)</label>
            <input type="number" step="0.01" min="0" id="resaleSplitShell" class="sh-input" style="margin-bottom:10px;" placeholder="Shell component">
            <label class="sh-label">Works / Finishes (€)</label>
            <input type="number" step="0.01" min="0" id="resaleSplitFinishes" class="sh-input" placeholder="CP, semi-finished, etc.">
        </div>
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button type="button" class="sh-btn" style="margin:0; width:auto; background:transparent; border:1px solid var(--sh-border); color:var(--sh-text-muted);" onclick="closeResaleModal()">Cancel</button>
            <button type="button" class="sh-btn sh-btn-success" style="margin:0; width:auto;" onclick="submitResaleModal()">Save Resale Listing</button>
        </div>
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

            <div class="hide-map-controls-mobile sh-map-legend">
                <div class="sh-legend-row">
                    <span class="sh-legend-item"><span class="sh-legend-dot avail"></span> Available units</span>
                    <span class="sh-legend-item"><span class="sh-legend-dot none"></span> None available</span>
                </div>
                <div style="margin-bottom: 10px;">
                    <i class="fas fa-draw-polygon text-info"></i>
                    Draw a search area on the map. <b>Double-click</b> to close the shape.
                </div>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="sh-btn sh-btn-info" style="margin: 0; flex: 1; padding: 8px;" onclick="startPolygonDraw()">
                        <i class="fas fa-draw-polygon"></i> Draw Area
                    </button>
                    <button type="button" class="sh-btn sh-btn-danger" style="margin: 0; flex: 1; padding: 8px;" onclick="clearPolygonDraw()">
                        <i class="fas fa-trash-alt"></i> Clear Area
                    </button>
                </div>
            </div>
        </div>
        
        <?php if(in_array($_SESSION['role'], ['admin', 'system_manager', 'sales_manager', 'director'])): ?>
            <div style="border-top: 1px solid var(--sh-border-light); margin: 15px -20px; padding-top: 15px; padding-left: 20px; padding-right: 20px; display: flex; flex-direction: column; gap: 8px;">
                <button type="button" id="viewToggleBtn" class="sh-btn sh-btn-warning" onclick="toggleViewMode()">
                    <i class="fas fa-eye"></i> View as Internal Agent
                </button>
                <button type="button" id="viewExternalBtn" class="sh-btn sh-btn-info" onclick="openExternalAgentPreview()">
                    <i class="fas fa-external-link-alt"></i> View as External Agent
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

    <div id="sidebarProjectPlansBar" style="display:none; padding: 0 20px 10px 20px;"></div>
    
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
                <h4 id="planModalTitle" style="margin: 0; color: #fff;"><i class="fas fa-map"></i> Floor Plan Viewer</h4>
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

<script>
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
            btn.innerHTML = '<i class="fas fa-eye"></i> View as Internal Agent';
            btn.classList.replace('sh-btn-success', 'sh-btn-warning');
            btn.classList.remove('active');
        }
        if (lastLoadedProjects.length > 0) {
            loadMultipleProjects(lastLoadedProjects, false);
        }
    }

    function openExternalAgentPreview() {
        window.open('sales_library.php?preview_external=1', '_blank');
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

        document.querySelectorAll('.sh-project-divider').forEach(divider => {
            const group = divider.nextElementSibling;
            if (!group || !group.classList.contains('project-unit-group')) return;
            const hasVisible = Array.from(group.querySelectorAll('.sh-card')).some(card => card.style.display !== 'none');
            divider.style.display = hasVisible ? '' : 'none';
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

    function openPlanModal(urlsStr, viewerLabel = 'Floor Plan Viewer') {
        currentPlans = urlsStr.split(',');
        currentPlanIndex = 0;
        const titleEl = document.getElementById('planModalTitle');
        if (titleEl) {
            titleEl.textContent = viewerLabel;
        }
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
        document.getElementById('sidebarProjectPlansBar').innerHTML = '';
        document.getElementById('sidebarProjectPlansBar').style.display = 'none';
        lastLoadedProjects = []; 
    }

    let mapProjectsData = {};
    let draw = null;
    const defaultCenter = [14.38, 35.92];
    const defaultZoom = 9.5;
    const defaultPitch = 25;
    const openFreeMapStyle = 'https://tiles.openfreemap.org/styles/liberty';
    const satelliteMapStyle = {
        version: 8,
        sources: {
            'esri-satellite': {
                type: 'raster',
                tiles: [
                    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'
                ],
                tileSize: 256,
                maxzoom: 19,
                attribution: 'Tiles © Esri'
            }
        },
        layers: [
            { id: 'esri-satellite-layer', type: 'raster', source: 'esri-satellite' }
        ]
    };
    let mapSatelliteActive = false;

    function attachSatelliteToggleButton() {
        const drawGroup = document.querySelector('#sales-map .maplibregl-ctrl-top-right .maplibregl-ctrl-group');
        if (!drawGroup || drawGroup.querySelector('.sh-satellite-toggle-btn')) return;
        const satBtn = document.createElement('button');
        satBtn.type = 'button';
        satBtn.className = 'sh-satellite-toggle-btn';
        satBtn.title = 'Toggle satellite view';
        satBtn.setAttribute('aria-label', 'Toggle satellite view');
        if (mapSatelliteActive) satBtn.classList.add('active');
        satBtn.addEventListener('click', toggleSatelliteView);
        drawGroup.insertBefore(satBtn, drawGroup.firstChild);
    }

    function toggleSatelliteView() {
        const savedDraw = draw ? draw.getAll() : null;
        mapSatelliteActive = !mapSatelliteActive;
        const btn = document.querySelector('.sh-satellite-toggle-btn');
        if (btn) btn.classList.toggle('active', mapSatelliteActive);
        map.once('style.load', () => setupDrawControl(savedDraw));
        map.setStyle(mapSatelliteActive ? satelliteMapStyle : openFreeMapStyle);
    }

    function setupDrawControl(restoreData) {
        if (draw) {
            try { map.removeControl(draw); } catch (e) { /* already removed on style change */ }
            draw = null;
        }
        draw = new MapboxDraw({
            displayControlsDefault: false,
            controls: { polygon: true, trash: true },
            defaultMode: 'simple_select',
            styles: mapDrawStyles
        });
        map.addControl(draw, 'top-right');
        attachSatelliteToggleButton();
        bindDrawTrashButton();
        if (restoreData && restoreData.features && restoreData.features.length > 0) {
            const addedIds = draw.add(restoreData);
            if (addedIds && addedIds.length > 0) {
                setTimeout(() => {
                    if (draw) {
                        draw.changeMode('simple_select', { featureIds: [addedIds[0]] });
                    }
                }, 0);
            }
            filterMapByPolygon();
        }
    }

    // MapboxDraw expects mapboxgl; alias to MapLibre (post-Mapbox migration)
    window.mapboxgl = maplibregl;

    // Required for MapboxDraw + MapLibre 3.x (controls otherwise not clickable)
    MapboxDraw.constants.classes.CANVAS = 'maplibregl-canvas';
    MapboxDraw.constants.classes.CONTROL_BASE = 'maplibregl-ctrl';
    MapboxDraw.constants.classes.CONTROL_PREFIX = 'maplibregl-ctrl-';
    MapboxDraw.constants.classes.CONTROL_GROUP = 'maplibregl-ctrl-group';
    MapboxDraw.constants.classes.ATTRIBUTION = 'maplibregl-ctrl-attrib';

    const mapDrawStyles = [
        {'id':'gl-draw-polygon-fill-inactive','type':'fill','filter':['all',['==','active','false'],['==','$type','Polygon'],['!=','mode','static']],'paint':{'fill-color':'#10b981','fill-outline-color':'#10b981','fill-opacity':0.15}},
        {'id':'gl-draw-polygon-fill-active','type':'fill','filter':['all',['==','active','true'],['==','$type','Polygon']],'paint':{'fill-color':'#f59e0b','fill-outline-color':'#f59e0b','fill-opacity':0.15}},
        {'id':'gl-draw-polygon-stroke-inactive','type':'line','filter':['all',['==','active','false'],['==','$type','Polygon'],['!=','mode','static']],'layout':{'line-cap':'round','line-join':'round'},'paint':{'line-color':'#10b981','line-width':2}},
        {'id':'gl-draw-polygon-stroke-active','type':'line','filter':['all',['==','active','true'],['==','$type','Polygon']],'layout':{'line-cap':'round','line-join':'round'},'paint':{'line-color':'#f59e0b','line-dasharray':[0.2,2],'line-width':2}},
        {'id':'gl-draw-line-inactive','type':'line','filter':['all',['==','active','false'],['==','$type','LineString'],['!=','mode','static']],'layout':{'line-cap':'round','line-join':'round'},'paint':{'line-color':'#10b981','line-width':2}},
        {'id':'gl-draw-line-active','type':'line','filter':['all',['==','$type','LineString'],['==','active','true']],'layout':{'line-cap':'round','line-join':'round'},'paint':{'line-color':'#f59e0b','line-dasharray':[0.2,2],'line-width':2}},
        {'id':'gl-draw-polygon-midpoint','type':'circle','filter':['all',['==','$type','Point'],['==','meta','midpoint']],'paint':{'circle-radius':3,'circle-color':'#f59e0b'}},
        {'id':'gl-draw-polygon-and-line-vertex-stroke-inactive','type':'circle','filter':['all',['==','meta','vertex'],['==','$type','Point'],['!=','mode','static']],'paint':{'circle-radius':5,'circle-color':'#fff'}},
        {'id':'gl-draw-polygon-and-line-vertex-inactive','type':'circle','filter':['all',['==','meta','vertex'],['==','$type','Point'],['!=','mode','static']],'paint':{'circle-radius':3,'circle-color':'#f59e0b'}}
    ];

    const map = new maplibregl.Map({
        container: 'sales-map',
        style: openFreeMapStyle,
        center: defaultCenter,
        zoom: defaultZoom,
        pitch: defaultPitch,
        bearing: 0,
        attributionControl: true
    });
    map.addControl(new maplibregl.NavigationControl(), 'bottom-right');

    map.on('error', (e) => {
        console.error('Sales Hub map error:', e.error || e);
    });

    function onDrawChanged(e) {
        if (e && e.type === 'draw.create' && draw) {
            const all = draw.getAll();
            if (all.features.length > 1) {
                all.features.slice(0, -1).forEach((feature) => draw.delete(feature.id));
            }
            const createdId = e.features && e.features[0] ? e.features[0].id : null;
            if (createdId) {
                setTimeout(() => {
                    if (draw) {
                        draw.changeMode('simple_select', { featureIds: [createdId] });
                    }
                }, 0);
            }
        }
        filterMapByPolygon();
    }

    function bindDrawTrashButton() {
        const trashBtn = document.querySelector('#sales-map .mapbox-gl-draw_trash');
        if (!trashBtn || trashBtn.dataset.shTrashBound) return;
        trashBtn.dataset.shTrashBound = '1';
        trashBtn.addEventListener('click', () => {
            if (!draw) return;
            const hadSelection = draw.getSelectedIds().length > 0;
            setTimeout(() => {
                if (!draw) return;
                if (!hadSelection && draw.getAll().features.length > 0) {
                    draw.deleteAll();
                    filterMapByPolygon();
                }
            }, 0);
        });
    }

    map.on('load', () => {
        if (!map._drawEventsBound) {
            map.on('draw.create', onDrawChanged);
            map.on('draw.delete', onDrawChanged);
            map.on('draw.update', onDrawChanged);
            map._drawEventsBound = true;
        }
        setupDrawControl();
        loadSalesMapProjects();
    });

    function filterMapByPolygon() {
        if (!draw) return;
        const data = draw.getAll();
        if (data.features.length > 0) {
            const polygon = data.features[0];
            let projectsInPolygon = [];
            
            Object.values(mapProjectsData).forEach(project => {
                if (!project.markerEl) return;
                const lng = parseFloat(project.longitude);
                const lat = parseFloat(project.latitude);
                if (!Number.isFinite(lng) || !Number.isFinite(lat)) return;
                const pt = turf.point([lng, lat]);
                const isInside = turf.booleanPointInPolygon(pt, polygon);
                project.markerEl.style.display = isInside ? 'block' : 'none';
                if (isInside) projectsInPolygon.push(project);
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

    function startPolygonDraw() {
        if (!draw) return;
        draw.changeMode('draw_polygon');
    }

    function clearPolygonDraw() {
        if (!draw) return;
        draw.deleteAll();
        filterMapByPolygon();
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

    function clearSalesMapMarkers() {
        Object.values(mapProjectsData).forEach(project => {
            if (project.marker) {
                project.marker.remove();
            }
        });
    }

    function loadSalesMapProjects() {
        fetch('api/get_sales_map_data.php')
            .then(r => r.json())
            .then(data => {
                if (!data.success || !Array.isArray(data.data)) return;

                clearSalesMapMarkers();

                const dropdown = document.getElementById('projectJumpDropdown');
                while (dropdown.options.length > 1) {
                    dropdown.remove(1);
                }
                mapProjectsData = {};

                data.data.forEach(project => {
                    const lng = parseFloat(project.longitude);
                    const lat = parseFloat(project.latitude);
                    if (!Number.isFinite(lng) || !Number.isFinite(lat)) return;

                    dropdown.add(new Option(project.project_name, project.project_id));

                    const availCount = parseInt(project.available_units || 0, 10);
                    const hasAvail = availCount > 0;

                    const el = document.createElement('div');
                    el.className = 'sh-map-pin';
                    el.style.cssText = `background-color: ${hasAvail ? '#10B981' : '#EF4444'}; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 10px rgba(0,0,0,0.8); cursor: pointer;`;
                    el.title = hasAvail
                        ? `${project.project_name} — ${availCount} available`
                        : `${project.project_name} — no available units`;

                    const marker = new maplibregl.Marker({ element: el, anchor: 'center' })
                        .setLngLat([lng, lat])
                        .addTo(map);
                    project.markerEl = el;
                    project.marker = marker;
                    mapProjectsData[project.project_id] = project;

                    el.addEventListener('click', (evt) => {
                        evt.stopPropagation();
                        loadMultipleProjects([project], true);
                    });
                });
            })
            .catch(err => console.error('Sales Hub map data failed:', err));
    }

    function jumpToSelectedProject(projectId) { 
        if(projectId && mapProjectsData[projectId]) {
            loadMultipleProjects([mapProjectsData[projectId]], true); 
        }
    }

    async function loadMultipleProjects(projects, shouldPan = false) {
        if (projects.length === 0) return;
        lastLoadedProjects = projects;

        if (shouldPan && projects.length === 1) {
            const lng = parseFloat(projects[0].longitude);
            const lat = parseFloat(projects[0].latitude);
            if (Number.isFinite(lng) && Number.isFinite(lat)) {
                map.flyTo({ center: [lng, lat], zoom: Math.max(map.getZoom(), 13), duration: 1000 });
            }
        }
        
        document.getElementById('sidebarProjectName').innerText = projects.length === 1 ? projects[0].project_name : `Selected Area (${projects.length} Projects)`;
        document.getElementById('sidebarProjectName').setAttribute('data-pid', projects.length === 1 ? projects[0].project_id : 'multi');
        
        document.getElementById('custom-sidebar').classList.add('open');
        document.getElementById('unitListContainer').innerHTML = '<div class="text-center p-4 text-light"><div class="spinner-border text-info"></div><div class="mt-2">Loading units...</div></div>';

        let allHtml = '';
        let totalAvail = 0, totalHold = 0, totalSold = 0;
        let allMedia = { renders: [], videos: [] };
        let projectPlansHtml = '';

        const promises = projects.map(p => fetch('api/get_project_units.php?project_id=' + p.project_id).then(r => r.json()));
        const results = await Promise.all(promises);

        results.forEach((unitData, index) => {
            if(unitData.success) {
                const p = projects[index];
                totalAvail += parseInt(p.available_units || 0);
                totalHold += parseInt(p.held_units || 0);
                totalSold += parseInt(p.sold_units || 0);

                if (unitData.project_plans && unitData.project_plans.length > 0) {
                    const urlList = escapeHtmlAttr(unitData.project_plans.join(','));
                    const planLabel = unitData.project_plans.length > 1
                        ? `View Full Project Plans (${unitData.project_plans.length})`
                        : 'View Full Project Plans';
                    const projectLabel = projects.length > 1 ? escapeHtmlText(p.project_name) + ' — ' : '';
                    projectPlansHtml += `
                        <button type="button" class="sh-btn sh-btn-info" style="margin: 0 0 8px 0; width: 100%;"
                            data-urls="${urlList}" onclick="openPlanModal(this.getAttribute('data-urls'), 'Project Plans Viewer')">
                            <i class="fas fa-drafting-compass"></i> ${projectLabel}${planLabel}
                        </button>`;
                }

                if (projects.length > 1) {
                    allHtml += `<div class="sh-project-divider"><i class="fas fa-building"></i> ${p.project_name}</div>`;
                }
                
                allHtml += `<div class="project-unit-group" data-project-name="${escapeHtmlAttr(p.project_name)}">` + processUnitHtmlSafely(unitData.html, p.project_name, projects.length > 1) + `</div>`;

                if (unitData.media) {
                    if (unitData.media.renders) allMedia.renders.push(...unitData.media.renders);
                    if (unitData.media.videos) allMedia.videos.push(...unitData.media.videos);
                }
            }
        });

        document.getElementById('sidebarAvail').innerText = totalAvail;
        document.getElementById('sidebarHold').innerText = totalHold;
        document.getElementById('sidebarSold').innerText = totalSold;

        const plansBar = document.getElementById('sidebarProjectPlansBar');
        if (projectPlansHtml) {
            plansBar.innerHTML = projectPlansHtml;
            plansBar.style.display = 'block';
        } else {
            plansBar.innerHTML = '';
            plansBar.style.display = 'none';
        }

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

    function escapeHtmlAttr(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function escapeHtmlText(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function processUnitHtmlSafely(rawHtml, projectName = '', showProjectLabel = false) {
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
            rawStatus = rawStatus.trim();
            let status = rawStatus;
            if (status === 'Reserved') status = 'Proceeding';
            if (status === 'Sold - POS' || status === 'Sold - Contract') status = 'Sold';

            let unitTypeAttr = card.getAttribute('data-type') || '';

            card.className = 'sh-card';
            card.setAttribute('data-status', rawStatus);
            card.setAttribute('data-type', unitTypeAttr);
            if (projectName) card.setAttribute('data-project-name', projectName);
            card.style.marginBottom = '15px';

            if (showProjectLabel && projectName) {
                const existingLabel = card.querySelector('.sh-unit-project');
                if (!existingLabel) {
                    const projectLabel = document.createElement('div');
                    projectLabel.className = 'sh-unit-project';
                    const projectIcon = document.createElement('i');
                    projectIcon.className = 'fas fa-building';
                    projectLabel.appendChild(projectIcon);
                    projectLabel.appendChild(document.createTextNode(' ' + projectName));
                    const cardBody = card.querySelector('.card-body') || card;
                    cardBody.insertBefore(projectLabel, cardBody.firstChild);
                }
            }

            if (status === 'Available' || status === 'BOM') {
                card.style.borderLeft = '4px solid var(--sh-avail)';
            } else if (status.includes('Proceeding')) {
                card.style.borderLeft = '4px solid var(--sh-proc)';
            } else if (rawStatus === 'Resale') {
                card.style.borderLeft = '4px solid var(--sh-resale)';
            } else if (status.includes('Sold') || rawStatus.includes('Sold')) {
                card.style.borderLeft = '4px solid var(--sh-sold)';
            } else {
                card.style.borderLeft = '4px solid var(--sh-hold)';
            }

            if (currentViewMode === 'agent' && (status.includes('Sold') || rawStatus.includes('Sold')) && rawStatus !== 'Resale') {
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

            let resalePrice = card.getAttribute('data-resale-price') || '';
            let resaleMode = card.getAttribute('data-resale-mode') || '';
            let resaleShell = card.getAttribute('data-resale-shell') || '';
            let resaleFinishes = card.getAttribute('data-resale-finishes') || '';
            if (parseFloat(resalePrice) === 0) resalePrice = '';
            const isSoldListing = rawStatus !== 'Resale' && rawStatus.indexOf('Sold') !== -1;

            const oldControls = card.querySelector('select[onchange^="managerUpdateStatus"]')?.parentNode || card.querySelector('.action-buttons') || card.querySelector('form');
            
            card.querySelectorAll('select, input, button[onclick*="holdProperty"], button[onclick*="requestReserve"], button[onclick*="markResale"], button[onclick*="openResaleModal"], button[onclick*="cancelResale"], .resale-input, .sh-resale-input').forEach(el => el.remove());

            const controlWrapper = document.createElement('div');
            controlWrapper.style.marginTop = '15px'; 
            controlWrapper.style.paddingTop = '15px'; 
            controlWrapper.style.borderTop = '1px solid var(--sh-border)';

            let controls = `<div style="font-weight:bold; color:var(--sh-text-muted); font-size:0.85rem; text-transform:uppercase; text-align:center; margin-bottom:10px;">Status: <span style="color:#fff;">${escapeHtmlText(rawStatus)}</span></div>`;

            if (currentViewMode === 'manager') {
                if (status === 'Available' || status === 'BOM') {
                    controls += `<button class="sh-btn sh-btn-warning" style="margin:0;" onclick="holdProperty(${unitId})"><i class="fas fa-pause"></i> Place on Hold</button>`;
                } else if (isSoldListing) {
                    controls += `<button class="sh-btn" style="margin:0; background: rgba(168,85,247,0.1); color: #a855f7; border: 1px solid rgba(168,85,247,0.3);" onclick="openResaleModal(${unitId}, 'create')"><i class="fas fa-exchange-alt"></i> List for Resale</button>`;
                } else if (rawStatus === 'Resale') {
                    controls += `
                        <div style="display:flex; gap:10px; margin-bottom:10px; flex-wrap:wrap;">
                            <button class="sh-btn sh-btn-warning" style="margin:0; flex:1;" onclick="holdProperty(${unitId})"><i class="fas fa-pause"></i> Place on Hold</button>
                            <button class="sh-btn" style="margin:0; flex:1; background:rgba(168,85,247,0.12); color:#a855f7; border:1px solid rgba(168,85,247,0.35);" onclick="openResaleModal(${unitId}, 'edit', '${escapeHtmlAttr(resaleMode)}', '${escapeHtmlAttr(resalePrice)}', '${escapeHtmlAttr(resaleShell)}', '${escapeHtmlAttr(resaleFinishes)}')"><i class="fas fa-pen"></i> Edit Pricing</button>
                        </div>
                        <button class="sh-btn sh-btn-success" style="margin-bottom:10px;" onclick="cancelResale(${unitId})"><i class="fas fa-undo"></i> Cancel Resale</button>`;
                } else if (status === 'On Hold' || status === 'Proceeding') {
                     controls += `<button class="sh-btn sh-btn-success" style="margin-bottom:10px;" onclick="releaseHoldFromLedger(${unitId})"><i class="fas fa-unlock"></i> Release Hold</button>`;
                }
            } else {
                if (status === 'Available' || status === 'BOM' || rawStatus === 'Resale') {
                    controls += `<button class="sh-btn sh-btn-warning" onclick="holdProperty(${unitId})"><i class="fas fa-pause"></i> Place on Hold</button>`;
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

    let resalePricingMode = 'single';

    function unitStatusIsSoldListing(status) {
        if (!status || status === 'Resale') return false;
        return status.indexOf('Sold') !== -1;
    }

    function closeResaleModal() {
        document.getElementById('resaleSetupModal').style.display = 'none';
    }

    function setResalePricingMode(mode) {
        resalePricingMode = mode;
        document.getElementById('resaleSingleFields').style.display = mode === 'single' ? 'block' : 'none';
        document.getElementById('resaleSplitFields').style.display = mode === 'split' ? 'block' : 'none';
        document.getElementById('resaleModeSingleBtn').style.outline = mode === 'single' ? '2px solid var(--sh-sold)' : 'none';
        document.getElementById('resaleModeSplitBtn').style.outline = mode === 'split' ? '2px solid var(--sh-resale)' : 'none';
    }

    function openResaleModal(propertyId, mode, pricingMode, allIn, shell, finishes) {
        document.getElementById('resaleModalPropertyId').value = propertyId;
        document.getElementById('resaleModalMode').value = mode;
        document.getElementById('resaleSinglePrice').value = allIn && parseFloat(allIn) > 0 ? allIn : '';
        document.getElementById('resaleSplitShell').value = shell && parseFloat(shell) > 0 ? shell : '';
        document.getElementById('resaleSplitFinishes').value = finishes && parseFloat(finishes) > 0 ? finishes : '';
        setResalePricingMode(pricingMode === 'split' ? 'split' : 'single');
        document.getElementById('resaleSetupModal').style.display = 'block';
    }

    function submitResaleModal() {
        const propertyId = document.getElementById('resaleModalPropertyId').value;
        const mode = document.getElementById('resaleModalMode').value;
        const formData = new FormData();
        formData.append('property_id', propertyId);
        formData.append('resale_pricing_mode', resalePricingMode);

        if (resalePricingMode === 'single') {
            const allIn = parseFloat(document.getElementById('resaleSinglePrice').value);
            if (!allIn || allIn <= 0) {
                showToast('Enter a valid all-in asking price.', 'error');
                return;
            }
            formData.append('resale_price', allIn);
        } else {
            const shell = parseFloat(document.getElementById('resaleSplitShell').value);
            const finishes = parseFloat(document.getElementById('resaleSplitFinishes').value);
            if (isNaN(shell) || isNaN(finishes) || shell < 0 || finishes < 0 || (shell + finishes) <= 0) {
                showToast('Enter valid shell and finishes prices (sum must be greater than 0).', 'error');
                return;
            }
            formData.append('resale_shell_price', shell);
            formData.append('resale_finishes_price', finishes);
        }

        if (mode === 'edit') {
            formData.append('action', 'update_resale_pricing');
        } else {
            formData.append('new_status', 'Resale');
        }

        fetch('api/manager_update_status.php', { method: 'POST', body: formData })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(response.status === 403 ? 'Access denied — please refresh the page and try again.' : 'Unexpected server response.');
                }
                try {
                    return await response.json();
                } catch (e) {
                    throw new Error('Unexpected server response.');
                }
            })
            .then(data => {
                if (data.success) {
                    closeResaleModal();
                    showToast(data.message || 'Resale saved.', 'success');
                    if (lastLoadedProjects.length > 0) {
                        setTimeout(() => loadMultipleProjects(lastLoadedProjects, false), 500);
                    }
                } else {
                    showToast('Error: ' + (data.message || 'Could not save'), 'error');
                }
            })
            .catch(err => showToast('System Error: ' + err.message, 'error'));
    }

    function cancelResale(propertyId) {
        if (!confirm('Cancel resale listing and restore the prior sold status?')) return;
        const formData = new FormData();
        formData.append('property_id', propertyId);
        formData.append('action', 'cancel_resale');
        fetch('api/manager_update_status.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'Resale cancelled.', 'success');
                    if (lastLoadedProjects.length > 0) {
                        setTimeout(() => loadMultipleProjects(lastLoadedProjects, false), 500);
                    }
                } else {
                    showToast('Error: ' + (data.message || 'Could not cancel'), 'error');
                }
            })
            .catch(err => showToast('System Error: ' + err.message, 'error'));
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
        if (!confirm('Are you sure you want to put this unit on hold?')) return;

        let formData = new FormData();
        formData.append('action', 'hold_property');
        formData.append('property_id', propertyId);

        if (currentViewMode === 'manager' && isManagerUser) {
            const defaultDate = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000);
            const pad = (n) => String(n).padStart(2, '0');
            const defaultStr = `${defaultDate.getFullYear()}-${pad(defaultDate.getMonth() + 1)}-${pad(defaultDate.getDate())} ${pad(defaultDate.getHours())}:${pad(defaultDate.getMinutes())}`;
            const custom = prompt('Hold deadline (YYYY-MM-DD HH:MM, Europe/Malta). Leave blank for 7 days:', defaultStr);
            if (custom === null) return;
            if (custom.trim() !== '') {
                formData.append('hold_expiry', custom.trim().replace(' ', 'T'));
            }
        }

        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Put on hold!', 'success');
                if (lastLoadedProjects.length > 0) {
                    setTimeout(() => loadMultipleProjects(lastLoadedProjects, false), 500);
                }
            } else {
                showToast('Error: ' + data.message, 'error');
            }
        })
        .catch(err => showToast('System Error: ' + err.message, 'error'));
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

    function openHoldLedger() {
        fetch('api/get_holds_ledger.php?_t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showToast('Error: ' + (data.message || 'Could not load ledger'), 'error');
                return;
            }

            const canManage = !!data.can_manage_deadlines;
            const isAgent = data.role === 'sales_agent';
            window._holdExtendMinJustification = parseInt(data.extend_min_justification, 10) || 25;
            const colCount = isAgent ? 5 : (canManage ? 7 : 6);

            const uniqueProjects = [...new Set(data.holds.map(h => h.project_name))].sort();
            const projectOptions = uniqueProjects.map(p => `<option value="${escapeHtmlAttr(p)}">${escapeHtmlText(p)}</option>`).join('');

            let html = `
            <div>
                <div style="position: sticky; top: 0; background: var(--sh-bg-panel); padding-bottom: 15px; z-index: 10; border-bottom: 1px solid var(--sh-border); margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                        <h3 style="color: #fff; margin: 0;">${isAgent ? 'My Active Holds' : 'Global Holds Ledger'}</h3>
                        <select id="ledgerProjectFilter" class="sh-select" style="width: auto; margin: 0; padding: 6px 12px; font-weight: bold; cursor: pointer;" onchange="filterLedgerTable()">
                            <option value="All">All Projects</option>
                            ${projectOptions}
                        </select>
                    </div>
                    <p style="margin: 0; color: var(--sh-text-muted); font-size: 0.8rem;">
                        Holds are <strong>not auto-released</strong> when expired. Managers must release manually or set a new deadline.
                    </p>
                </div>
                <table class="table" style="width: 100%; text-align: left; border-collapse: collapse; color: #fff;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--sh-border);">
                            <th style="padding: 10px;">Project</th>
                            <th style="padding: 10px;">Unit</th>
                            ${!isAgent ? '<th style="padding: 10px;">Agent</th>' : ''}
                            <th style="padding: 10px;">Status</th>
                            <th style="padding: 10px;">Exact Expiry</th>
                            ${canManage ? '<th style="padding: 10px;">Set Deadline</th>' : ''}
                            <th style="padding: 10px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>`;

            if (data.holds.length === 0) {
                html += `<tr><td colspan="${colCount}" style="padding: 15px; text-align:center; color: var(--sh-text-muted);">No properties currently on hold.</td></tr>`;
            } else {
                data.holds.forEach(hold => {
                    const agentName = hold.is_legacy
                        ? '<span style="color:var(--sh-text-muted); font-style:italic;">Legacy/System</span>'
                        : escapeHtmlText(`${hold.first_name || ''} ${hold.last_name || ''}`.trim());

                    let statusHtml;
                    if (hold.is_legacy) {
                        statusHtml = '<span style="background: rgba(168, 85, 247, 0.2); color: #a855f7; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold;"><i class="fas fa-infinity"></i> Legacy</span>';
                    } else if (hold.is_expired) {
                        statusHtml = '<span style="background: rgba(239, 68, 68, 0.2); color: var(--sh-danger); padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold;"><i class="fas fa-exclamation-circle"></i> EXPIRED</span>';
                    } else if (hold.is_expiring_soon) {
                        statusHtml = `<span style="color: var(--sh-danger); font-weight: bold;"><i class="fas fa-exclamation-triangle"></i> ${hold.hours_remaining}h left</span>`;
                    } else {
                        statusHtml = `<span style="color: var(--sh-text-main);">${hold.hours_remaining}h left</span>`;
                    }

                    const expiryDisplay = hold.is_legacy
                        ? '<span style="color:var(--sh-text-muted);">N/A</span>'
                        : escapeHtmlText(new Date(hold.hold_expiry.replace(' ', 'T')).toLocaleString());

                    let deadlineCell = '';
                    if (canManage) {
                        const inputVal = escapeHtmlAttr(hold.hold_expiry_input || '');
                        deadlineCell = `
                            <td style="padding: 12px 10px;">
                                <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                    <input type="datetime-local" id="holdDeadline_${hold.id}" class="sh-input" style="width: auto; margin: 0; padding: 6px 8px; font-size: 0.8rem;" value="${inputVal}">
                                    <button type="button" class="sh-btn sh-btn-info" style="margin:0; padding:6px 10px; width:auto; font-size:0.75rem;" onclick="setHoldDeadlineFromLedger(${hold.id})">Save</button>
                                </div>
                            </td>`;
                    }

                    let actionHtml = `<button type="button" class="sh-btn sh-btn-success" style="margin: 0 0 4px 0; padding: 6px 12px; width: auto; display: inline-block; font-size: 0.8rem;" onclick="releaseHoldFromLedger(${hold.id})"><i class="fas fa-unlock"></i> Release</button>`;
                    if (isAgent && !hold.is_legacy) {
                        if (hold.can_extend_hold) {
                            actionHtml += `<br><button type="button" class="sh-btn sh-btn-warning" style="margin: 0; padding: 6px 12px; width: auto; display: inline-block; font-size: 0.75rem;" onclick="extendHoldFromLedger(${hold.id})"><i class="fas fa-clock"></i> +7 days</button>`;
                        } else {
                            actionHtml += `<br><span style="display:inline-block; margin-top:4px; font-size:0.7rem; color:var(--sh-text-muted);">+7 only within 24h of expiry</span>`;
                        }
                    }

                    html += `
                    <tr class="ledger-row" data-project="${escapeHtmlAttr(hold.project_name)}" style="border-bottom: 1px solid var(--sh-border-light);${hold.is_expired ? ' background: rgba(239,68,68,0.08);' : ''}">
                        <td style="padding: 12px 10px;">${escapeHtmlText(hold.project_name)}</td>
                        <td style="padding: 12px 10px;"><strong>${escapeHtmlText(hold.unit_name)}</strong></td>
                        ${!isAgent ? `<td style="padding: 12px 10px;">${agentName}</td>` : ''}
                        <td style="padding: 12px 10px;">${statusHtml}</td>
                        <td style="padding: 12px 10px;">${expiryDisplay}</td>
                        ${deadlineCell}
                        <td style="padding: 12px 10px; text-align: right;">${actionHtml}</td>
                    </tr>`;
                });
            }

            html += `</tbody></table></div>`;
            document.getElementById('holdLedgerContent').innerHTML = html;
            document.getElementById('holdLedgerModal').style.display = 'block';
        });
    }

    function setHoldDeadlineFromLedger(propertyId) {
        const input = document.getElementById('holdDeadline_' + propertyId);
        if (!input || !input.value) {
            showToast('Please choose a deadline.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'set_hold_deadline');
        formData.append('property_id', propertyId);
        formData.append('hold_expiry', input.value);

        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Hold deadline updated.', 'success');
                openHoldLedger();
                if (lastLoadedProjects.length > 0) {
                    setTimeout(() => loadMultipleProjects(lastLoadedProjects, false), 500);
                }
            } else {
                showToast('Error: ' + data.message, 'error');
            }
        })
        .catch(err => showToast('System Error: ' + err.message, 'error'));
    }

    function extendHoldFromLedger(propertyId) {
        const minChars = window._holdExtendMinJustification || 25;
        const justification = prompt('Justification required to extend this hold by 7 days (min ' + minChars + ' characters):');
        if (justification === null) return;
        const trimmed = justification.trim();
        if (trimmed.length < minChars) {
            showToast('Justification must be at least ' + minChars + ' characters.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'extend_hold');
        formData.append('property_id', propertyId);
        formData.append('justification', trimmed);

        fetch('api/sales_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Hold extended by 7 days.', 'success');
                openHoldLedger();
                if (lastLoadedProjects.length > 0) {
                    setTimeout(() => loadMultipleProjects(lastLoadedProjects, false), 500);
                }
            } else {
                showToast('Error: ' + data.message, 'error');
            }
        })
        .catch(err => showToast('System Error: ' + err.message, 'error'));
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
</script>

<?php require_once 'footer.php'; ?>
