<?php
require_once 'config.php';
require_once 'session-check.php';
require_once 'user-functions.php';

$role = $_SESSION['role'];

// 1. Check Baseline Access
$isPlantUser = in_array($role, ['plant_manager', 'plant_driver']);
$hasAccess = hasPermission('view_plant_bookings') || $isPlantUser;

if (!$hasAccess) {
    die("Unauthorized Access.");
}

// 2. Define App Capabilities
$isManager = hasPermission('view_plant_bookings') || $role === 'plant_manager'; // Can create bookings
$canManageFleet = in_array($role, ['admin', 'system_manager', 'plant_manager']); // Strict fleet control
$userId = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Plant Bookings</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js'></script>
    <link href='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css' rel='stylesheet' />
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>

    <style>
        body { font-family: 'Inter', sans-serif; background: #e2e8f0; color: #1e293b; margin: 0; padding: 0; overscroll-behavior-y: none; }
        .app-container { max-width: 600px; margin: 0 auto; background: #fff; min-height: 100vh; display: flex; flex-direction: column; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .header { background: #0f172a; color: #fff; padding: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 10; position: sticky; top: 0; }
        .header h2 { margin: 0; font-weight: 900; font-size: 1.5rem; }
        .content { flex: 1; padding: 20px; overflow-y: auto; }
        
        .btn-heavy { padding: 18px 20px; font-size: 1.25rem; font-weight: 900; border-radius: 12px; width: 100%; margin-bottom: 15px; border: none; cursor: pointer; text-transform: uppercase; letter-spacing: 1px; color: #fff; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .btn-green { background: #10b981; } .btn-green:active { background: #059669; }
        .btn-blue { background: #3b82f6; } .btn-blue:active { background: #2563eb; }
        .btn-gray { background: #94a3b8; } .btn-gray:active { background: #64748b; }
        .btn-red { background: #ef4444; } .btn-red:active { background: #dc2626; }

        .input-heavy { width: 100%; padding: 15px; font-size: 1.1rem; border-radius: 10px; border: 2px solid #cbd5e1; margin-bottom: 15px; box-sizing: border-box; background: #f8fafc; color: #1e293b; }
        .input-heavy:focus { border-color: #3b82f6; outline: none; background: #fff; }
        
        label { font-weight: 700; color: #64748b; margin-bottom: 5px; display: block; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        
        #signature-pad { border: 2px dashed #94a3b8; border-radius: 12px; width: 100%; height: 250px; background: #f8fafc; touch-action: none; margin-bottom: 15px; }
        .view { display: none; animation: fadeIn 0.3s ease; }
        .view.active { display: block; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .fc-toolbar-title { font-size: 1.2rem !important; font-weight: 900 !important; }
        .fc-button { padding: 10px !important; border-radius: 8px !important; text-transform: capitalize !important; font-weight: bold !important; }
        .fc-event { cursor: pointer; border-radius: 6px !important; padding: 2px; }
    </style>
</head>
<body>

<div class="app-container">
    <div class="header">
        <h2 onclick="showView('view-calendar')" style="cursor:pointer;"><i class="fas fa-tractor"></i> Plant Hub</h2>
        <div style="display: flex; gap: 10px;">
            <?php if ($canManageFleet): ?>
                <button class="btn-heavy btn-gray" style="padding: 10px 15px; margin: 0; font-size: 1rem;" onclick="loadFleetView()"><i class="fas fa-truck-monster"></i></button>
            <?php endif; ?>
            
            <?php if ($isManager): ?>
                <button class="btn-heavy btn-blue" style="padding: 10px 15px; margin: 0; font-size: 1rem;" onclick="showView('view-create')"><i class="fas fa-plus"></i></button>
            <?php endif; ?>
            
            <?php if (!in_array($_SESSION['role'], ['admin', 'director', 'system_manager'])): ?>
                <a href="api/logout.php" class="btn-heavy btn-red" style="padding: 10px 15px; margin: 0; font-size: 1rem; text-decoration: none;"><i class="fas fa-sign-out-alt"></i></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="content">
        <div id="view-calendar" class="view active">
            <div id="calendar"></div>
        </div>

        <?php if ($canManageFleet): ?>
        <div id="view-fleet" class="view">
            <h3 style="margin-top:0; font-weight:900; font-size: 1.5rem;"><i class="fas fa-truck-monster text-gray-500"></i> Fleet Management</h3>
            
            <div style="background: #f1f5f9; padding: 20px; border-radius: 12px; margin-bottom: 25px; border: 2px dashed #cbd5e1;">
                <h4 style="margin-top:0; color: #3b82f6; margin-bottom: 15px;">Register New Machinery</h4>
                
                <label>Plant Name / Description</label>
                <input type="text" id="new_plant_name" class="input-heavy" placeholder="e.g. JCB Excavator 3CX" required>
                
                <label>Registration Plate (Optional)</label>
                <input type="text" id="new_plant_reg" class="input-heavy" placeholder="e.g. ABC 123">
                
                <label>Owned By (Developer / Client)</label>
                <select id="new_plant_owner" class="input-heavy" required></select>
                
                <div style="display: flex; gap: 10px;">
                    <div style="flex:1;">
                        <label>In-House Rate (€/hr)</label>
                        <input type="number" id="new_plant_rate_in" class="input-heavy" placeholder="0.00" value="0.00" step="0.01" required>
                    </div>
                    <div style="flex:1;">
                        <label>External Rate (€/hr)</label>
                        <input type="number" id="new_plant_rate_ext" class="input-heavy" placeholder="0.00" value="0.00" step="0.01" required>
                    </div>
                </div>
                
                <button type="button" class="btn-heavy btn-blue" onclick="saveNewPlant()"><i class="fas fa-save"></i> Save to Fleet</button>
            </div>

            <h4 style="color: #64748b; text-transform: uppercase;">Active Fleet</h4>
            <div id="fleet-list"></div>
            
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')" style="margin-top: 20px;"><i class="fas fa-arrow-left"></i> Back to Calendar</button>
        </div>
        <?php endif; ?>

        <?php if ($isManager): ?>
        <div id="view-create" class="view">
            <h3 style="margin-top:0; font-weight:900; font-size: 1.5rem;"><i class="fas fa-calendar-plus text-blue-500"></i> Create Booking</h3>
            <form id="createBookingForm">
                <label>Plant / Machinery</label>
                <select id="plant_id" class="input-heavy" required></select>

                <label>Assigned Driver (Optional)</label>
                <select id="driver_id" class="input-heavy"></select>

                <label>Job Type</label>
                <select id="booking_type" class="input-heavy" onchange="toggleJobType()">
                    <option value="in-house">In-House Project</option>
                    <option value="external">External / 3rd Party</option>
                </select>

                <div id="inhouse-fields">
                    <label>Select Project</label>
                    <select id="project_id" class="input-heavy"></select>
                </div>

                <div id="external-fields" style="display: none;">
                    <label>Client Name</label>
                    <input type="text" id="client_name" class="input-heavy" placeholder="Company or Individual Name">
                    
                    <label>Location (Tap Map to Pin)</label>
                    <div id="map" style="width: 100%; height: 250px; border-radius: 12px; margin-bottom: 15px; border: 2px solid #cbd5e1;"></div>
                    <input type="hidden" id="loc_lat">
                    <input type="hidden" id="loc_lng">
                </div>

                <label>Booking Date</label>
                <input type="date" id="booking_date" class="input-heavy" required>

                <div style="display: flex; gap: 10px;">
                    <div style="flex:1;">
                        <label>Start Time</label>
                        <input type="time" id="start_time" class="input-heavy" value="08:00" required>
                    </div>
                    <div style="flex:1;">
                        <label>End Time</label>
                        <input type="time" id="end_time" class="input-heavy" value="17:00" required>
                    </div>
                </div>

                <button type="button" class="btn-heavy btn-green" onclick="submitBooking()"><i class="fas fa-check"></i> Save Booking</button>
                <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')">Cancel</button>
            </form>
        </div>
        <?php endif; ?>

        <div id="view-job" class="view">
            <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #cbd5e1; margin-bottom: 20px;">
                <h3 id="job-title" style="margin:0 0 10px 0; font-weight:900; font-size:1.8rem; color: #1e293b;"></h3>
                <div id="job-details" style="font-size: 1.1rem; color: #475569; line-height: 1.6;"></div>
            </div>
            
            <div id="punch-controls"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')"><i class="fas fa-arrow-left"></i> Back to Calendar</button>
        </div>

        <div id="view-punch-out" class="view">
            <h3 style="margin-top:0; color:#ef4444; font-weight: 900;"><i class="fas fa-flag-checkered"></i> Job Completion</h3>
            <p style="color: #64748b; font-size: 1.1rem; margin-bottom: 20px;">Please complete the Delivery Note to conclude this job.</p>
            
            <input type="hidden" id="punchout_booking_id">
            
            <label>Client Representative Name</label>
            <input type="text" id="rep_name" class="input-heavy" placeholder="e.g. John Doe" required>
            
            <label>Client ID Card Number</label>
            <input type="text" id="rep_id" class="input-heavy" placeholder="e.g. 1234567M" required>
            
            <label>Client Signature</label>
            <canvas id="signature-pad"></canvas>
            <button type="button" class="btn-heavy btn-gray" onclick="signaturePad.clear()" style="font-size:1rem; padding: 12px; background: #cbd5e1; color: #475569;"><i class="fas fa-eraser"></i> Clear Signature</button>

            <button type="button" class="btn-heavy btn-red" onclick="submitPunchOut()"><i class="fas fa-check-circle"></i> Punch Out & Finalize</button>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-job')">Cancel</button>
        </div>
    </div>
</div>

<script>
    let calendar, mapboxMap, marker, signaturePad;
    const isManager = <?= $isManager ? 'true' : 'false' ?>;
    const canManageFleet = <?= $canManageFleet ? 'true' : 'false' ?>;
    
    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw';

    document.addEventListener('DOMContentLoaded', () => {
        initCalendar();
        signaturePad = new SignaturePad(document.getElementById('signature-pad'), {
            penColor: "rgb(15, 23, 42)"
        });
        if (isManager) loadFormData();
    });

    function showView(id) {
        document.querySelectorAll('.view').forEach(el => el.classList.remove('active'));
        document.getElementById(id).classList.add('active');
        window.scrollTo(0, 0); // Reset scroll position
        
        if (id === 'view-calendar' && calendar) calendar.render();
        if (id === 'view-create') setTimeout(initMap, 200); // Allow DOM to paint before mapping
        if (id === 'view-punch-out') setTimeout(resizeCanvas, 100);
    }

    function initCalendar() {
        const calEl = document.getElementById('calendar');
        calendar = new FullCalendar.Calendar(calEl, {
            initialView: isManager ? 'timeGridWeek' : 'listDay',
            headerToolbar: { left: 'prev,next today', center: 'title', right: isManager ? 'dayGridMonth,timeGridWeek,timeGridDay' : '' },
            slotMinTime: '06:00:00',
            slotMaxTime: '20:00:00',
            allDaySlot: false,
            events: 'api/plant_actions.php?action=fetch_bookings',
            eventClick: (info) => loadJob(info.event.id)
        });
        calendar.render();
    }

    function initMap() {
        if (mapboxMap) {
            mapboxMap.resize();
            return;
        }
        mapboxMap = new mapboxgl.Map({ container: 'map', style: 'mapbox://styles/mapbox/streets-v12', center: [14.38, 35.92], zoom: 10 });
        mapboxMap.on('click', (e) => {
            if (marker) marker.remove();
            marker = new mapboxgl.Marker({color: '#ef4444'}).setLngLat(e.lngLat).addTo(mapboxMap);
            document.getElementById('loc_lat').value = e.lngLat.lat;
            document.getElementById('loc_lng').value = e.lngLat.lng;
        });
    }

    function toggleJobType() {
        const type = document.getElementById('booking_type').value;
        document.getElementById('inhouse-fields').style.display = type === 'in-house' ? 'block' : 'none';
        document.getElementById('external-fields').style.display = type === 'external' ? 'block' : 'none';
        if (type === 'external') setTimeout(() => mapboxMap.resize(), 100);
    }

    function resizeCanvas() {
        const canvas = document.getElementById('signature-pad');
        const ratio =  Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext("2d").scale(ratio, ratio);
        signaturePad.clear();
    }

    // --- FORM DATA & BOOKING (Managers) ---
    function loadFormData() {
        fetch('api/plant_actions.php?action=form_data').then(r=>r.json()).then(d => {
            document.getElementById('plant_id').innerHTML = d.plants.map(p => `<option value="${p.id}">${p.name} (${p.registration_plate || 'N/A'})</option>`).join('');
            document.getElementById('driver_id').innerHTML = '<option value="">-- Unassigned (Driver can claim) --</option>' + d.drivers.map(drv => `<option value="${drv.id}">${drv.first_name} ${drv.last_name}</option>`).join('');
            document.getElementById('project_id').innerHTML = d.projects.map(prj => `<option value="${prj.id}">${prj.name}</option>`).join('');
        });
    }

    function submitBooking() {
        const btn = event.target.closest('button');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        const fd = new FormData();
        fd.append('action', 'create_booking');
        fd.append('plant_id', document.getElementById('plant_id').value);
        fd.append('driver_id', document.getElementById('driver_id').value);
        fd.append('booking_type', document.getElementById('booking_type').value);
        fd.append('project_id', document.getElementById('project_id').value);
        fd.append('client_name', document.getElementById('client_name').value);
        fd.append('loc_lat', document.getElementById('loc_lat').value);
        fd.append('loc_lng', document.getElementById('loc_lng').value);
        fd.append('booking_date', document.getElementById('booking_date').value);
        fd.append('start_time', document.getElementById('start_time').value);
        fd.append('end_time', document.getElementById('end_time').value);

        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') { alert("Booking Created successfully!"); calendar.refetchEvents(); showView('view-calendar'); }
            else { alert("Error: " + res); }
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Save Booking';
        });
    }

    // --- FLEET MANAGEMENT (Strict Access) ---
    function loadFleetView() {
        if (!canManageFleet) return;
        
        fetch('api/plant_actions.php?action=get_clients').then(r=>r.json()).then(clients => {
            let opts = '<option value="">-- Select Owner --</option>';
            clients.forEach(c => { opts += `<option value="${c.id}">${c.name}</option>`; });
            document.getElementById('new_plant_owner').innerHTML = opts;
        });

        fetch('api/plant_actions.php?action=get_fleet').then(r=>r.json()).then(fleet => {
            let html = '';
            if (fleet.length === 0) { html = '<p style="color:#64748b; font-style:italic;">No machinery registered yet.</p>'; }
            fleet.forEach(p => {
                html += `
                <div style="background: #fff; border: 2px solid #cbd5e1; border-radius: 12px; padding: 15px; margin-bottom: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                    <div style="font-weight:900; font-size:1.2rem; color:#0f172a; margin-bottom:5px;">${p.name}</div>
                    <div style="color:#64748b; font-size:0.95rem; margin-bottom: 8px;">
                        <i class="fas fa-barcode"></i> Reg: <span style="color:#1e293b; font-weight:bold;">${p.registration_plate || 'N/A'}</span>
                    </div>
                    <div style="display:flex; gap:10px; font-size:0.85rem; margin-bottom: 8px;">
                        <span style="background:#d1fae5; color:#059669; padding:4px 8px; border-radius:6px; font-weight:bold;">IN: €${p.inhouse_rate}/hr</span>
                        <span style="background:#e0e7ff; color:#2563eb; padding:4px 8px; border-radius:6px; font-weight:bold;">EXT: €${p.external_rate}/hr</span>
                    </div>
                    <div style="color:#475569; font-size:0.85rem; font-weight:bold; border-top: 1px solid #e2e8f0; padding-top: 8px;">
                        <i class="fas fa-building"></i> Owned By: ${p.owner_name}
                    </div>
                </div>`;
            });
            document.getElementById('fleet-list').innerHTML = html;
        });

        showView('view-fleet');
    }

    function saveNewPlant() {
        const name = document.getElementById('new_plant_name').value;
        const owner = document.getElementById('new_plant_owner').value;
        
        if (!name || !owner) { alert("Please provide a Plant Name and select an Owner."); return; }

        const btn = event.target.closest('button');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        const fd = new FormData();
        fd.append('action', 'save_plant');
        fd.append('name', name);
        fd.append('reg', document.getElementById('new_plant_reg').value);
        fd.append('owner_id', owner);
        fd.append('rate_in', document.getElementById('new_plant_rate_in').value);
        fd.append('rate_ext', document.getElementById('new_plant_rate_ext').value);

        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') {
                alert("Machinery added to fleet!");
                document.getElementById('new_plant_name').value = '';
                document.getElementById('new_plant_reg').value = '';
                document.getElementById('new_plant_rate_in').value = '0.00';
                document.getElementById('new_plant_rate_ext').value = '0.00';
                loadFormData(); 
                loadFleetView(); 
            } else alert("Error: " + res);
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save to Fleet';
        });
    }

    // --- JOB EXECUTION (Drivers) ---
    function loadJob(id) {
        fetch(`api/plant_actions.php?action=get_job&id=${id}`).then(r=>r.json()).then(job => {
            document.getElementById('job-title').innerHTML = `<i class="fas fa-truck-pickup text-blue-500"></i> ${job.plant_name}`;
            
            let statusColor = '#3b82f6';
            if (job.status === 'In Progress') statusColor = '#f59e0b';
            if (job.status === 'Completed') statusColor = '#10b981';

            let details = `
                <div style="margin-bottom:10px;"><i class="fas fa-calendar-day" style="width:25px;"></i> <b>Date:</b> ${job.booking_date} (${job.start_time.substring(0,5)} - ${job.end_time.substring(0,5)})</div>
                <div style="margin-bottom:10px;"><i class="fas fa-tag" style="width:25px;"></i> <b>Type:</b> ${job.booking_type.toUpperCase()}</div>
                <div style="margin-bottom:10px;"><i class="fas fa-info-circle" style="width:25px;"></i> <b>Status:</b> <span style="color:${statusColor}; font-weight:bold; background:rgba(0,0,0,0.05); padding:2px 8px; border-radius:4px;">${job.status}</span></div>
                <hr style="border: 1px solid #e2e8f0; margin: 15px 0;">
                <div style="font-weight:bold; color: #1e293b; margin-bottom: 5px;"><i class="fas fa-map-marker-alt text-red-500"></i> Destination:</div>
                <div style="background: #e2e8f0; padding: 10px; border-radius: 8px; font-weight: bold;">${job.location_text}</div>
            `;
            document.getElementById('job-details').innerHTML = details;

            let controlsHtml = '';
            let today = new Date().toISOString().split('T')[0];

            if (!isManager && job.booking_date === today) {
                if (job.status === 'Pending') {
                    controlsHtml = `<button class="btn-heavy btn-green" onclick="punchJob(${job.id}, 'in')"><i class="fas fa-play"></i> Punch In (Start Job)</button>`;
                } else if (job.status === 'In Progress') {
                    controlsHtml = `<button class="btn-heavy btn-red" onclick="startPunchOut(${job.id})"><i class="fas fa-stop"></i> Punch Out (End Job)</button>`;
                }
            } else if (!isManager && job.booking_date !== today && job.status !== 'Completed') {
                controlsHtml = `<div style="background: #fee2e2; border: 1px solid #fca5a5; color:#ef4444; padding: 15px; border-radius: 10px; font-weight:bold; text-align:center; margin-bottom: 15px;"><i class="fas fa-exclamation-triangle"></i> You can only punch into jobs on the scheduled day.</div>`;
            }

            document.getElementById('punch-controls').innerHTML = controlsHtml;
            showView('view-job');
        });
    }

    function punchJob(id, direction) {
        if (!confirm("Are you sure you want to Punch In to this job? Your time will begin tracking immediately.")) return;
        
        fetch(`api/plant_actions.php?action=punch_${direction}&id=${id}`).then(r=>r.text()).then(res => {
            if (res === 'OK') { alert("Punched In Successfully!"); loadJob(id); calendar.refetchEvents(); }
            else alert("Error: " + res);
        });
    }

    function startPunchOut(id) {
        document.getElementById('punchout_booking_id').value = id;
        showView('view-punch-out');
    }

    function submitPunchOut() {
        if (signaturePad.isEmpty()) { alert("Please ask the client representative to sign the pad."); return; }
        if (!document.getElementById('rep_name').value || !document.getElementById('rep_id').value) { alert("Please fill in the client's Name and ID."); return; }

        if (!confirm("Are you sure you want to finalize this job? This will punch you out and generate the official delivery note.")) return;

        const btn = event.target.closest('button');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        const fd = new FormData();
        fd.append('action', 'punch_out_complete');
        fd.append('id', document.getElementById('punchout_booking_id').value);
        fd.append('rep_name', document.getElementById('rep_name').value);
        fd.append('rep_id', document.getElementById('rep_id').value);
        fd.append('signature', signaturePad.toDataURL());

        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') { 
                alert("Job Successfully Completed! Delivery Note generated and sent to accounts."); 
                calendar.refetchEvents(); showView('view-calendar'); 
            } else {
                alert("Error: " + res);
                btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle"></i> Punch Out & Finalize';
            }
        });
    }
</script>
</body>
</html>
