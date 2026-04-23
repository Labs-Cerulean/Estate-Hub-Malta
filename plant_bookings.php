<?php
require_once 'config.php';
require_once 'session-check.php';
require_once 'user-functions.php';

$role = $_SESSION['role'];
$isPlantUser = in_array($role, ['plant_manager', 'plant_driver']);
$isAccountant = ($role === 'accountant');
if (!hasPermission('view_plant_bookings') && !$isPlantUser && !$isAccountant) { die("Unauthorized Access."); }

$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']); 
$canManageFleet = in_array($role, ['admin', 'system_manager', 'plant_manager']); 
$canViewLedger = in_array($role, ['admin', 'director', 'system_manager', 'accountant']);
$userId = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Plant Bookings Hub</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js'></script>
    <link href='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css' rel='stylesheet' />
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>

    <style>
        body { font-family: 'Inter', sans-serif; background: #f3f4f6; color: #0f172a; margin: 0; padding: 0; overscroll-behavior-y: none; }
        .app-container { max-width: 600px; margin: 0 auto; background: #f8fafc; min-height: 100vh; display: flex; flex-direction: column; box-shadow: 0 0 40px rgba(0,0,0,0.05); }
        .header { background: #0f172a; color: #fff; padding: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 20px rgba(0,0,0,0.1); z-index: 10; position: sticky; top: 0; }
        .header h2 { margin: 0; font-weight: 900; font-size: 1.5rem; letter-spacing: -0.5px; }
        .content { flex: 1; padding: 20px; overflow-y: auto; }
        
        .btn-heavy { padding: 16px 20px; font-size: 1.15rem; font-weight: 800; border-radius: 12px; width: 100%; margin-bottom: 15px; border: none; cursor: pointer; text-transform: uppercase; letter-spacing: 1px; color: #fff; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .btn-heavy:active { transform: translateY(2px); box-shadow: none; }
        .btn-green { background: linear-gradient(135deg, #14b8a6, #0d9488); } 
        .btn-blue { background: linear-gradient(135deg, #6366f1, #4f46e5); } 
        .btn-gray { background: #64748b; color: #fff; box-shadow: none; } 
        .btn-red { background: linear-gradient(135deg, #f43f5e, #e11d48); } 

        .input-heavy { width: 100%; padding: 15px; font-size: 1.1rem; border-radius: 12px; border: 2px solid #e2e8f0; margin-bottom: 18px; box-sizing: border-box; background: #fff; color: #1e293b; outline: none; }
        .input-heavy:focus { border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); }
        
        label { font-weight: 800; color: #475569; margin-bottom: 6px; display: block; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        #signature-pad { border: 2px dashed #94a3b8; border-radius: 16px; width: 100%; height: 250px; background: #fff; touch-action: none; margin-bottom: 15px; }
        .view { display: none; animation: fadeIn 0.3s ease; }
        .view.active { display: block; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        #calendar { background: #ffffff; padding: 15px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.04); margin-bottom: 20px; border: 1px solid #f1f5f9; }
        .fc .fc-button-primary { background-color: #f1f5f9 !important; border: none !important; color: #475569 !important; font-weight: 700 !important; border-radius: 8px !important; text-transform: capitalize !important; padding: 8px 12px !important; box-shadow: none !important; transition: 0.2s; }
        .fc .fc-button-primary:hover { background-color: #e2e8f0 !important; color: #0f172a !important; }
        .fc .fc-button-primary:not(:disabled).fc-button-active, .fc .fc-button-primary:not(:disabled):active { background-color: #6366f1 !important; color: #fff !important; }
        .fc-theme-standard td, .fc-theme-standard th { border-color: #f1f5f9 !important; }
        .fc-theme-standard .fc-scrollgrid { border: none !important; }
        .fc-col-header-cell-cushion { padding: 12px 0 !important; color: #64748b !important; font-weight: 800 !important; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
        .fc-event { border-radius: 6px !important; border: none !important; padding: 3px 5px !important; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
    </style>
</head>
<body>

<div class="app-container">
    <div class="header">
        <h2 onclick="showView('view-calendar')" style="cursor:pointer;"><i class="fas fa-tractor text-teal-400"></i> Plant Hub</h2>
        <div style="display: flex; gap: 10px;">
            <?php if ($canViewLedger): ?>
                <button class="btn-heavy btn-gray" style="padding: 10px 15px; margin: 0; font-size: 1rem;" onclick="loadLedger()" title="Billing Ledger"><i class="fas fa-file-invoice-dollar"></i></button>
            <?php endif; ?>
            <?php if ($canManageFleet): ?>
                <button class="btn-heavy btn-gray" style="padding: 10px 15px; margin: 0; font-size: 1rem;" onclick="loadFleetView()" title="Fleet & Drivers"><i class="fas fa-truck-monster"></i></button>
            <?php endif; ?>
            <?php if ($isManager): ?>
                <button class="btn-heavy btn-blue" style="padding: 10px 15px; margin: 0; font-size: 1rem;" onclick="openCreateForm()" title="New Booking"><i class="fas fa-plus"></i></button>
            <?php endif; ?>
            <?php if (!in_array($_SESSION['role'], ['admin', 'director', 'system_manager'])): ?>
                <a href="api/logout.php" class="btn-heavy btn-red" style="padding: 10px 15px; margin: 0; font-size: 1rem; text-decoration: none;"><i class="fas fa-sign-out-alt"></i></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="content">
        <div id="view-calendar" class="view active">
            <div style="background: #fff; padding: 15px 20px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 15px; border: 1px solid #e2e8f0;">
                <h3 id="custom-cal-title" style="margin:0; font-weight:900; font-size:1.4rem; color: #0f172a; text-align: center;">Loading...</h3>
            </div>
            <div id="calendar"></div>
        </div>

        <?php if ($canManageFleet): ?>
        <div id="view-fleet" class="view">
            <h3 style="margin-top:0; font-weight:900; font-size: 1.6rem; color: #0f172a;"><i class="fas fa-truck-monster text-indigo-500"></i> Fleet Management</h3>
            
            <div style="background: #fff; padding: 20px; border-radius: 16px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0;">
                <h4 style="margin-top:0; color: #6366f1; margin-bottom: 15px;">Register New Machinery</h4>
                
                <label>Plant Name / Description</label>
                <input type="text" id="new_plant_name" class="input-heavy" placeholder="e.g. JCB Excavator 3CX" required>
                
                <label>Registration Plate</label>
                <input type="text" id="new_plant_reg" class="input-heavy" placeholder="e.g. ABC 123">
                
                <label>Owned By (Developer / Client)</label>
                <select id="new_plant_owner" class="input-heavy" required></select>

                <label>Pricing Model</label>
                <select id="new_plant_pricing" class="input-heavy" onchange="document.getElementById('fixed-price-box').style.display = this.value === 'fixed_then_hourly' ? 'flex' : 'none';">
                    <option value="hourly">Standard Hourly</option>
                    <option value="fixed_then_hourly">Fixed Minimum + Hourly</option>
                </select>

                <div id="fixed-price-box" style="display: none; gap: 10px; background:#fef3c7; padding: 15px; border-radius: 12px; margin-bottom:15px;">
                    <div style="flex:1;">
                        <label style="color:#b45309;">Min Hours</label>
                        <input type="number" id="new_plant_min_hrs" class="input-heavy" style="margin-bottom:0;" placeholder="e.g. 4" value="0">
                    </div>
                    <div style="flex:1;">
                        <label style="color:#b45309;">Fixed Price (€)</label>
                        <input type="number" id="new_plant_min_price" class="input-heavy" style="margin-bottom:0;" placeholder="e.g. 200" value="0">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <div style="flex:1;">
                        <label>In-House Rate (€/hr)</label>
                        <input type="number" id="new_plant_rate_in" class="input-heavy" placeholder="0.00" step="0.01" required>
                    </div>
                    <div style="flex:1;">
                        <label>External Rate (€/hr)</label>
                        <input type="number" id="new_plant_rate_ext" class="input-heavy" placeholder="0.00" step="0.01" required>
                    </div>
                </div>
                
                <button type="button" class="btn-heavy btn-blue" onclick="saveNewPlant()"><i class="fas fa-save"></i> Save to Fleet</button>
            </div>

            <h4 style="color: #64748b; text-transform: uppercase;">Active Fleet</h4>
            <div id="fleet-list" style="margin-bottom: 40px;"></div>

            <hr style="border: 1px solid #e2e8f0; margin: 35px 0;">
            
            <h3 style="margin-top:0; font-weight:900; font-size: 1.6rem; color: #0f172a;"><i class="fas fa-id-card text-teal-500"></i> Driver Management</h3>
            <div style="background: #fff; padding: 20px; border-radius: 16px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0;">
                <div style="display: flex; gap: 10px;">
                    <div style="flex:1;"><label>First Name</label><input type="text" id="new_drv_first" class="input-heavy" required></div>
                    <div style="flex:1;"><label>Last Name</label><input type="text" id="new_drv_last" class="input-heavy" required></div>
                </div>
                <label>Email (Username)</label>
                <input type="email" id="new_drv_email" class="input-heavy" required>
                <label>Temporary Password</label>
                <input type="text" id="new_drv_pass" class="input-heavy" required>
                <button type="button" class="btn-heavy btn-green" onclick="saveNewDriver()"><i class="fas fa-user-plus"></i> Create Driver</button>
            </div>
            
            <h4 style="color: #64748b; text-transform: uppercase;">Active Drivers</h4>
            <div id="driver-list"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')" style="margin-top: 30px;"><i class="fas fa-arrow-left"></i> Back to Calendar</button>
        </div>
        <?php endif; ?>

        <?php if ($canViewLedger): ?>
        <div id="view-ledger" class="view">
            <h3 style="margin-top:0; font-weight:900; font-size: 1.6rem; color: #0f172a;"><i class="fas fa-book text-indigo-500"></i> Billing Ledger</h3>
            <p style="color: #64748b; margin-bottom: 20px;">Track job statuses, view Delivery Notes, and mark invoices as settled.</p>
            <div id="ledger-list"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')" style="margin-top: 30px;"><i class="fas fa-arrow-left"></i> Back to Calendar</button>
        </div>
        <?php endif; ?>

        <?php if ($isManager): ?>
        <div id="view-create" class="view">
            <h3 style="margin-top:0; font-weight:900; font-size: 1.6rem; color: #0f172a;"><i class="fas fa-calendar-alt text-blue-500"></i> Manage Booking</h3>
            <form id="createBookingForm" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                <input type="hidden" id="edit_booking_id" value="">
                
                <label>Plant / Machinery</label>
                <select id="plant_id" class="input-heavy" required></select>

                <label>Assigned Driver</label>
                <select id="driver_id" class="input-heavy"></select>

                <label>Job Type</label>
                <select id="booking_type" class="input-heavy" onchange="toggleJobType()">
                    <option value="in-house">In-House Project</option>
                    <option value="external">External / 3rd Party</option>
                </select>

                <div id="inhouse-fields"><label>Select Project</label><select id="project_id" class="input-heavy" onchange="updateProjectLocation()"></select></div>
                <div id="external-fields" style="display: none;"><label>Client Name</label><input type="text" id="client_name" class="input-heavy" placeholder="Company or Individual"></div>

                <label>Location (Tap Map to Pin)</label>
                <p style="font-size:0.85rem; color:#64748b; margin-top:-5px; margin-bottom:10px;">Drivers will receive a dynamic Google Maps route to this location.</p>
                <div id="map" style="width: 100%; height: 250px; border-radius: 12px; margin-bottom: 15px; border: 2px solid #e2e8f0;"></div>
                <input type="hidden" id="loc_lat"><input type="hidden" id="loc_lng">
                
                <label>Comments / Instructions</label>
                <textarea id="booking_comments" class="input-heavy" rows="2" placeholder="Gate codes, site contact, specific instructions..."></textarea>

                <label>Booking Date</label>
                <input type="date" id="booking_date" class="input-heavy" required>

                <div style="display: flex; gap: 10px;">
                    <div style="flex:1;"><label>Start Time</label><input type="time" id="start_time" class="input-heavy" value="08:00" required></div>
                    <div style="flex:1;"><label>End Time</label><input type="time" id="end_time" class="input-heavy" value="17:00" required></div>
                </div>

                <button type="button" id="submit_booking_btn" class="btn-heavy btn-blue" onclick="submitBooking()"><i class="fas fa-check"></i> Save Booking</button>
                <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')">Cancel</button>
            </form>
        </div>
        <?php endif; ?>

        <div id="view-job" class="view">
            <div style="background: #fff; padding: 25px; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                <h3 id="job-title" style="margin:0 0 15px 0; font-weight:900; font-size:1.8rem; color: #0f172a;"></h3>
                <div id="job-details" style="font-size: 1.1rem; color: #475569; line-height: 1.6;"></div>
            </div>
            <div id="punch-controls"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')"><i class="fas fa-arrow-left"></i> Back to Calendar</button>
        </div>

        <div id="view-punch-out" class="view">
            <h3 style="margin-top:0; color:#e11d48; font-weight: 900; font-size: 1.6rem;"><i class="fas fa-flag-checkered"></i> Job Completion</h3>
            <p style="color: #64748b; font-size: 1.1rem; margin-bottom: 20px;">Please complete the Delivery Note to officially conclude this job.</p>
            
            <div style="background: #fff; padding: 20px; border-radius: 16px; margin-bottom: 30px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                <input type="hidden" id="punchout_booking_id">
                <label>Client Representative Name</label><input type="text" id="rep_name" class="input-heavy" required>
                <label>Client ID Card Number</label><input type="text" id="rep_id" class="input-heavy" required>
                <label>Client Signature</label>
                <canvas id="signature-pad"></canvas>
                <button type="button" class="btn-heavy btn-gray" onclick="signaturePad.clear()" style="font-size:1rem; padding: 12px; background: #e2e8f0; color: #475569;"><i class="fas fa-eraser"></i> Clear Signature</button>
            </div>
            <button type="button" class="btn-heavy btn-red" onclick="submitPunchOut()"><i class="fas fa-check-circle"></i> Complete & Finalize Job</button>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-job')">Cancel</button>
        </div>
    </div>
</div>

<script>
    let calendar, mapboxMap, marker, signaturePad;
    const isManager = <?= $isManager ? 'true' : 'false' ?>;
    const canManageFleet = <?= $canManageFleet ? 'true' : 'false' ?>;
    const canViewLedger = <?= $canViewLedger ? 'true' : 'false' ?>;
    
    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw';

    document.addEventListener('DOMContentLoaded', () => {
        initCalendar();
        signaturePad = new SignaturePad(document.getElementById('signature-pad'), { penColor: "rgb(15, 23, 42)" });
        if (isManager) loadFormData();
    });

    function showView(id) {
        document.querySelectorAll('.view').forEach(el => el.classList.remove('active'));
        document.getElementById(id).classList.add('active');
        window.scrollTo(0, 0); 
        if (id === 'view-calendar' && calendar) calendar.render();
        if (id === 'view-create') setTimeout(initMap, 200); 
        if (id === 'view-punch-out') setTimeout(resizeCanvas, 100);
    }

    function initCalendar() {
        const calEl = document.getElementById('calendar');
        calendar = new FullCalendar.Calendar(calEl, {
            initialView: isManager ? 'timeGridWeek' : 'listDay',
            headerToolbar: { left: 'prev,next today', center: '', right: isManager ? 'dayGridMonth,timeGridWeek,timeGridDay' : '' },
            slotMinTime: '06:00:00', slotMaxTime: '20:00:00', allDaySlot: false, contentHeight: 'auto',
            events: 'api/plant_actions.php?action=fetch_bookings',
            eventClick: (info) => loadJob(info.event.id),
            datesSet: function(info) { document.getElementById('custom-cal-title').innerText = info.view.title; }
        });
        calendar.render();
    }

    function initMap() {
        if (mapboxMap) { mapboxMap.resize(); return; }
        mapboxMap = new mapboxgl.Map({ container: 'map', style: 'mapbox://styles/mapbox/streets-v12', center: [14.38, 35.92], zoom: 10 });
        mapboxMap.on('click', (e) => {
            if (marker) marker.remove();
            marker = new mapboxgl.Marker({color: '#f43f5e'}).setLngLat(e.lngLat).addTo(mapboxMap);
            document.getElementById('loc_lat').value = e.lngLat.lat;
            document.getElementById('loc_lng').value = e.lngLat.lng;
        });
    }

    function toggleJobType() {
        const type = document.getElementById('booking_type').value;
        document.getElementById('inhouse-fields').style.display = type === 'in-house' ? 'block' : 'none';
        document.getElementById('external-fields').style.display = type === 'external' ? 'block' : 'none';
    }

    function resizeCanvas() {
        const canvas = document.getElementById('signature-pad');
        const ratio =  Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext("2d").scale(ratio, ratio);
        signaturePad.clear();
    }

    function loadFormData() {
        fetch('api/plant_actions.php?action=form_data').then(r=>r.json()).then(d => {
            document.getElementById('plant_id').innerHTML = d.plants.map(p => `<option value="${p.id}">${p.name} (${p.registration_plate || 'N/A'})</option>`).join('');
            document.getElementById('driver_id').innerHTML = '<option value="">-- Unassigned (Driver can claim) --</option>' + d.drivers.map(drv => `<option value="${drv.id}">${drv.first_name} ${drv.last_name}</option>`).join('');
            document.getElementById('project_id').innerHTML = d.projects.map(prj => `<option value="${prj.id}">${prj.name}</option>`).join('');
        });
    }

    function openCreateForm() {
        document.getElementById('edit_booking_id').value = '';
        document.getElementById('submit_booking_btn').innerHTML = '<i class="fas fa-check"></i> Save Booking';
        document.getElementById('createBookingForm').reset();
        if(marker) marker.remove();
        toggleJobType(); showView('view-create');
    }

    function submitBooking() {
        const btn = document.getElementById('submit_booking_btn');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        const editId = document.getElementById('edit_booking_id').value;
        const fd = new FormData();
        fd.append('action', editId ? 'update_booking' : 'create_booking');
        if (editId) fd.append('edit_id', editId);
        
        fd.append('plant_id', document.getElementById('plant_id').value);
        fd.append('driver_id', document.getElementById('driver_id').value);
        fd.append('booking_type', document.getElementById('booking_type').value);
        fd.append('project_id', document.getElementById('project_id').value);
        fd.append('client_name', document.getElementById('client_name').value);
        fd.append('loc_lat', document.getElementById('loc_lat').value);
        fd.append('loc_lng', document.getElementById('loc_lng').value);
        fd.append('comments', document.getElementById('booking_comments').value);
        fd.append('booking_date', document.getElementById('booking_date').value);
        fd.append('start_time', document.getElementById('start_time').value);
        fd.append('end_time', document.getElementById('end_time').value);

        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') { alert(editId ? "Booking Updated!" : "Booking Created!"); calendar.refetchEvents(); showView('view-calendar'); }
            else { alert("Error: " + res); }
            btn.disabled = false; btn.innerHTML = editId ? '<i class="fas fa-save"></i> Update Booking' : '<i class="fas fa-check"></i> Save Booking';
        });
    }

    function loadFleetView() {
        if (!canManageFleet) return;
        fetch('api/plant_actions.php?action=get_clients').then(r=>r.json()).then(clients => {
            document.getElementById('new_plant_owner').innerHTML = '<option value="">-- Select Owner --</option>' + clients.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
        });

        fetch('api/plant_actions.php?action=get_fleet').then(r=>r.json()).then(fleet => {
            let html = fleet.length === 0 ? '<p style="color:#64748b; font-style:italic;">No machinery registered yet.</p>' : '';
            fleet.forEach(p => {
                let pBadge = p.pricing_type === 'fixed_then_hourly' 
                    ? `<span style="background:#fef3c7; color:#d97706; padding:4px 8px; border-radius:6px; font-weight:bold;">Min ${p.min_hours}h @ €${p.min_price}</span>` 
                    : `<span style="background:#f1f5f9; color:#475569; padding:4px 8px; border-radius:6px; font-weight:bold;">Standard Hourly</span>`;
                
                html += `
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; margin-bottom: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
                    <div style="font-weight:900; font-size:1.2rem; color:#0f172a; margin-bottom:5px;">${p.name}</div>
                    <div style="color:#64748b; font-size:0.95rem; margin-bottom: 8px;"><i class="fas fa-barcode"></i> Reg: <span style="color:#1e293b; font-weight:bold;">${p.registration_plate || 'N/A'}</span></div>
                    <div style="margin-bottom: 8px;">${pBadge}</div>
                    <div style="display:flex; gap:10px; font-size:0.85rem; margin-bottom: 8px;">
                        <span style="background:#ccfbf1; color:#0f766e; padding:4px 8px; border-radius:6px; font-weight:bold;">IN: €${p.inhouse_rate}/hr</span>
                        <span style="background:#e0e7ff; color:#4f46e5; padding:4px 8px; border-radius:6px; font-weight:bold;">EXT: €${p.external_rate}/hr</span>
                    </div>
                    <div style="color:#475569; font-size:0.85rem; font-weight:bold; border-top: 1px solid #f1f5f9; padding-top: 8px;"><i class="fas fa-building text-blue-500"></i> Owned By: ${p.owner_name}</div>
                </div>`;
            });
            document.getElementById('fleet-list').innerHTML = html;
        });
        loadDriversList(); showView('view-fleet');
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
        fd.append('pricing_type', document.getElementById('new_plant_pricing').value);
        fd.append('min_hours', document.getElementById('new_plant_min_hrs').value);
        fd.append('min_price', document.getElementById('new_plant_min_price').value);
        fd.append('rate_in', document.getElementById('new_plant_rate_in').value);
        fd.append('rate_ext', document.getElementById('new_plant_rate_ext').value);

        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') {
                alert("Machinery added to fleet!");
                document.getElementById('new_plant_name').value = '';
                document.getElementById('new_plant_reg').value = '';
                document.getElementById('new_plant_min_hrs').value = '0';
                document.getElementById('new_plant_min_price').value = '0';
                loadFormData(); loadFleetView(); 
            } else alert("Error: " + res);
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save to Fleet';
        });
    }

    function loadDriversList() {
        fetch('api/plant_actions.php?action=get_drivers').then(r=>r.json()).then(drivers => {
            let html = drivers.length === 0 ? '<p style="color:#64748b; font-style:italic;">No drivers registered yet.</p>' : '';
            drivers.forEach(d => {
                html += `
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 15px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
                    <div><div style="font-weight:bold; font-size: 1.05rem;">${d.first_name} ${d.last_name}</div><div style="color:#64748b; font-size:0.85rem;"><i class="fas fa-envelope"></i> ${d.email}</div></div>
                    <span style="background: #d1fae5; color: #059669; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: bold;">Active</span>
                </div>`;
            });
            document.getElementById('driver-list').innerHTML = html;
        });
    }

    function saveNewDriver() {
        const first = document.getElementById('new_drv_first').value;
        const last = document.getElementById('new_drv_last').value;
        const email = document.getElementById('new_drv_email').value;
        const pass = document.getElementById('new_drv_pass').value;
        if (!first || !last || !email || !pass) { alert("Please fill out all Driver fields."); return; }

        const btn = event.target.closest('button');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';

        const fd = new FormData();
        fd.append('action', 'save_driver');
        fd.append('first', first); fd.append('last', last);
        fd.append('email', email); fd.append('pass', pass);

        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') { alert("Driver Created!"); loadFormData(); loadDriversList(); } 
            else alert("Error: " + res);
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-user-plus"></i> Create Driver';
        });
    }

    function loadJob(id) {
        fetch(`api/plant_actions.php?action=get_job&id=${id}`).then(r=>r.json()).then(job => {
            document.getElementById('job-title').innerHTML = `<i class="fas fa-truck-pickup text-indigo-500"></i> ${job.plant_name}`;
            let statusColor = job.status === 'Completed' ? '#10b981' : (job.status === 'In Progress' ? '#f59e0b' : '#6366f1');

            let mapBtnHtml = '';
            if (job.location_lat && job.location_lng) {
                // Generates a clickable Google Maps link
                mapBtnHtml = `<a href="https://www.google.com/maps/dir/?api=1&destination=${job.location_lat},${job.location_lng}" target="_blank" style="display:inline-block; background:#0f172a; color:#fff; padding:8px 15px; border-radius:8px; font-weight:bold; font-size:0.9rem; text-decoration:none; margin-top:8px;"><i class="fas fa-directions"></i> Get Directions in Maps</a>`;
            }

            let commentsHtml = job.comments ? `<div style="background: #fef3c7; border: 1px solid #fde68a; padding: 15px; border-radius: 10px; margin-bottom:15px; color: #92400e; font-size:0.95rem;"><i class="fas fa-comment-dots text-yellow-600"></i> <b>Comments & Instructions:</b><br>${job.comments.replace(/\n/g, '<br>')}</div>` : '';

            document.getElementById('job-details').innerHTML = `
                <div style="margin-bottom:12px;"><i class="fas fa-calendar-day text-teal-500" style="width:25px;"></i> <b>Date:</b> ${job.booking_date} (${job.start_time.substring(0,5)} - ${job.end_time.substring(0,5)})</div>
                <div style="margin-bottom:12px;"><i class="fas fa-tag text-teal-500" style="width:25px;"></i> <b>Type:</b> ${job.booking_type.toUpperCase()}</div>
                <div style="margin-bottom:12px;"><i class="fas fa-info-circle text-teal-500" style="width:25px;"></i> <b>Status:</b> <span style="color:${statusColor}; font-weight:bold; background:rgba(0,0,0,0.05); padding:4px 10px; border-radius:6px;">${job.status}</span></div>
                ${commentsHtml}
                <hr style="border: 1px solid #e2e8f0; margin: 20px 0;">
                <div style="font-weight:bold; color: #0f172a; margin-bottom: 8px;"><i class="fas fa-map-marker-alt text-rose-500"></i> Destination:</div>
                <div style="background: #f1f5f9; padding: 12px; border-radius: 8px; font-weight: bold; border: 1px solid #e2e8f0;">${job.location_text}<br>${mapBtnHtml}</div>
            `;

            let controlsHtml = '';
            let today = new Date().toISOString().split('T')[0];

            if (!isManager && job.booking_date === today) {
                if (job.status === 'Pending') {
                    controlsHtml = `<button class="btn-heavy btn-green" onclick="punchJob(${job.id}, 'in')"><i class="fas fa-play"></i> Start Job</button>`;
                } else if (job.status === 'In Progress') {
                    controlsHtml = `<button class="btn-heavy btn-red" onclick="startPunchOut(${job.id})"><i class="fas fa-stop"></i> Complete Job</button>`;
                }
            } else if (!isManager && job.booking_date !== today && job.status !== 'Completed') {
                controlsHtml = `<div style="background: #fff1f2; border: 1px solid #fecdd3; color:#e11d48; padding: 15px; border-radius: 12px; font-weight:bold; text-align:center; margin-bottom: 15px;"><i class="fas fa-exclamation-triangle"></i> You can only start jobs on the scheduled day.</div>`;
            }

            if (isManager && job.status !== 'Completed') {
                controlsHtml += `<button class="btn-heavy btn-blue" onclick='editJob(${JSON.stringify(job)})'><i class="fas fa-edit"></i> Edit / Assign Booking</button>`;
                controlsHtml += `<button class="btn-heavy btn-red" onclick="cancelJob(${job.id})"><i class="fas fa-trash-alt"></i> Cancel Booking</button>`;
            }
            if (isManager && job.status === 'Completed') {
                controlsHtml += `<button class="btn-heavy btn-green" onclick="window.open('print_plant_invoice.php?booking_id=${job.id}', '_blank')"><i class="fas fa-file-invoice-dollar"></i> View Delivery Note & RFP</button>`;
            }

            document.getElementById('punch-controls').innerHTML = controlsHtml;
            showView('view-job');
        });
    }

    function editJob(job) {
        document.getElementById('edit_booking_id').value = job.id;
        document.getElementById('plant_id').value = job.plant_id;
        document.getElementById('driver_id').value = job.driver_id || '';
        document.getElementById('booking_type').value = job.booking_type;
        toggleJobType();
        
        if (job.booking_type === 'in-house') { document.getElementById('project_id').value = job.project_id; } 
        else { document.getElementById('client_name').value = job.client_name; }
        
        document.getElementById('loc_lat').value = job.location_lat || '';
        document.getElementById('loc_lng').value = job.location_lng || '';
        
        if(job.location_lat && job.location_lng && mapboxMap) {
            setTimeout(() => {
                if(marker) marker.remove();
                marker = new mapboxgl.Marker({color: '#f43f5e'}).setLngLat([job.location_lng, job.location_lat]).addTo(mapboxMap);
                mapboxMap.flyTo({center: [job.location_lng, job.location_lat], zoom: 14});
            }, 300);
        }

        document.getElementById('booking_comments').value = job.comments || '';
        document.getElementById('booking_date').value = job.booking_date;
        document.getElementById('start_time').value = job.start_time.substring(0,5);
        document.getElementById('end_time').value = job.end_time.substring(0,5);
        document.getElementById('submit_booking_btn').innerHTML = '<i class="fas fa-save"></i> Update Booking';
        showView('view-create');
    }

    function cancelJob(id) {
        if (!confirm("Are you sure you want to permanently delete this booking?")) return;
        const fd = new FormData(); fd.append('action', 'cancel_booking'); fd.append('id', id);
        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') { alert("Booking Cancelled."); calendar.refetchEvents(); showView('view-calendar'); }
        });
    }

    function punchJob(id, direction) {
        if (!confirm("Are you sure you want to Start this job? Your time will begin tracking immediately.")) return;
        fetch(`api/plant_actions.php?action=punch_${direction}&id=${id}`).then(r=>r.text()).then(res => {
            if (res === 'OK') { alert("Job Started Successfully!"); loadJob(id); calendar.refetchEvents(); }
            else alert("Error: " + res);
        });
    }

    function startPunchOut(id) { document.getElementById('punchout_booking_id').value = id; showView('view-punch-out'); }

    function submitPunchOut() {
        if (signaturePad.isEmpty()) { alert("Please ask the client representative to sign the pad."); return; }
        if (!document.getElementById('rep_name').value || !document.getElementById('rep_id').value) { alert("Please fill in the client's Name and ID."); return; }
        if (!confirm("Are you sure you want to finalize this job? This will complete the job and generate the official delivery note.")) return;

        const btn = event.target.closest('button');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        const fd = new FormData();
        fd.append('action', 'punch_out_complete');
        fd.append('id', document.getElementById('punchout_booking_id').value);
        fd.append('rep_name', document.getElementById('rep_name').value);
        fd.append('rep_id', document.getElementById('rep_id').value);
        fd.append('signature', signaturePad.toDataURL());

        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') { alert("Job Successfully Completed! Delivery Note generated."); calendar.refetchEvents(); showView('view-calendar'); } 
            else { alert("Error: " + res); btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle"></i> Complete & Finalize Job'; }
        });
    }

    function loadLedger() {
        if (!canViewLedger) return;
        fetch('api/plant_actions.php?action=get_ledger').then(r=>r.json()).then(jobs => {
            let html = jobs.length === 0 ? '<p style="color:#64748b; font-style:italic;">No bookings found in the ledger.</p>' : '';
            jobs.forEach(j => {
                let statusBadge = j.payment_status === 'Invoiced' ? `<span style="background:#fef08a; color:#854d0e; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">Invoiced</span>` : (j.payment_status === 'Settled' ? `<span style="background:#d1fae5; color:#065f46; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">Settled</span>` : `<span style="background:#e2e8f0; color:#475569; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">${j.payment_status}</span>`);

                let jobYear = j.booking_date.substring(0, 4);
                let formattedId = `PRA-${jobYear}-${String(j.id).padStart(4, '0')}`;
                
                let finTotal = "TBD";
                if (j.final_subtotal !== null) {
                    finTotal = "€" + (parseFloat(j.final_subtotal) * 1.18).toFixed(2);
                }

                html += `
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; margin-bottom: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                        <div><div style="font-weight:900; font-size:1.1rem; color:#0f172a;">${formattedId} - ${j.plant_name}</div>
                        <div style="color:#64748b; font-size:0.85rem;"><i class="fas fa-calendar"></i> ${j.booking_date} | ${j.booking_type === 'in-house' ? j.project_name : j.client_name}</div></div>
                        <div style="text-align:right;"><div style="font-weight:900; font-size:1.2rem; color:#10b981;">${finTotal}</div>${statusBadge}</div>
                    </div>
                    <div style="border-top: 1px solid #f1f5f9; padding-top: 10px; display:flex; gap:10px;">
                        ${j.status === 'Completed' ? `<button onclick="window.open('print_plant_invoice.php?booking_id=${j.id}', '_blank')" style="background:#f1f5f9; color:#3b82f6; border:none; padding:8px 12px; border-radius:8px; font-weight:bold; cursor:pointer; flex:1;"><i class="fas fa-file-pdf"></i> View RFP</button>` : `<span style="color:#94a3b8; font-size:0.85rem; padding-top:5px;"><i>Job Not Yet Completed</i></span>`}
                        ${j.payment_status === 'Invoiced' ? `<button onclick="markSettled(${j.id})" style="background:#10b981; color:#fff; border:none; padding:8px 12px; border-radius:8px; font-weight:bold; cursor:pointer; flex:1;"><i class="fas fa-check-double"></i> Mark Settled</button>` : ''}
                    </div>
                </div>`;
            });
            document.getElementById('ledger-list').innerHTML = html;
        });
        showView('view-ledger');
    }

    function markSettled(id) {
        if (!confirm("Are you sure you want to mark this invoice as Paid/Settled?")) return;
        const fd = new FormData(); fd.append('action', 'mark_settled'); fd.append('id', id);
        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') { loadLedger(); }
        });
    }
</script>
</body>
</html>
