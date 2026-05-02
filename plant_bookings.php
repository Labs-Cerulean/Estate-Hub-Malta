<?php
require_once 'config.php';
require_once 'session-check.php';
require_once 'user-functions.php';

$role = $_SESSION['role'];
$isPlantUser = in_array($role, ['plant_manager', 'plant_driver']);
$isAccountant = ($role === 'accountant');
if (!hasPermission('view_plant_bookings') && !$isPlantUser && !$isAccountant) { die("Unauthorized Access."); }

// Plant Managers can edit bookings, but ONLY Admins/Sys Managers can manage the active fleet inventory and nominal codes
$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']); 
$canManageFleet = in_array($role, ['admin', 'system_manager']); 
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
        .btn-green { background: linear-gradient(135deg, #14b8a6, #0d9488); } .btn-blue { background: linear-gradient(135deg, #6366f1, #4f46e5); } .btn-gray { background: #64748b; color: #fff; box-shadow: none; } .btn-red { background: linear-gradient(135deg, #f43f5e, #e11d48); } 
        .input-heavy { width: 100%; padding: 15px; font-size: 1.1rem; border-radius: 12px; border: 2px solid #e2e8f0; margin-bottom: 18px; box-sizing: border-box; background: #fff; color: #1e293b; outline: none; }
        .input-heavy:focus { border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); }
        .input-heavy:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; border-color: #cbd5e1; }
        label { font-weight: 800; color: #475569; margin-bottom: 6px; display: block; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        optgroup { font-weight: 900; color: #6366f1; font-style: normal; background: #f8fafc; }
        #signature-pad { border: 2px dashed #94a3b8; border-radius: 16px; width: 100%; height: 250px; background: #fff; touch-action: none; margin-bottom: 15px; }
        .view { display: none; animation: fadeIn 0.3s ease; } .view.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        #calendar { background: #ffffff; padding: 15px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.04); margin-bottom: 20px; border: 1px solid #f1f5f9; }
        .fc .fc-button-primary { background-color: #f1f5f9 !important; border: none !important; color: #475569 !important; font-weight: 700 !important; border-radius: 8px !important; text-transform: capitalize !important; padding: 8px 12px !important; box-shadow: none !important; transition: 0.2s; }
        .fc .fc-button-primary:hover { background-color: #e2e8f0 !important; color: #0f172a !important; } .fc .fc-button-primary:not(:disabled).fc-button-active, .fc .fc-button-primary:not(:disabled):active { background-color: #6366f1 !important; color: #fff !important; }
        .fc-theme-standard td, .fc-theme-standard th { border-color: #f1f5f9 !important; } .fc-theme-standard .fc-scrollgrid { border: none !important; }
        .fc-col-header-cell-cushion { padding: 12px 0 !important; color: #64748b !important; font-weight: 800 !important; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
        .fc-event { border-radius: 6px !important; border: none !important; padding: 3px 5px !important; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
    </style>
</head>
<body>

<div class="app-container">
    <div class="header">
        <h2 onclick="showView('view-calendar')" style="cursor:pointer;"><i class="fas fa-tractor text-teal-400"></i> Plant Hub</h2>
        <div style="display: flex; gap: 10px;">
            <?php if ($canViewLedger): ?><button class="btn-heavy btn-gray" style="padding:10px 15px; margin:0; font-size:1rem;" onclick="loadLedger()"><i class="fas fa-file-invoice-dollar"></i></button><?php endif; ?>
            <?php if ($canManageFleet): ?><button class="btn-heavy btn-gray" style="padding:10px 15px; margin:0; font-size:1rem;" onclick="loadFleetView()"><i class="fas fa-truck-monster"></i></button><?php endif; ?>
            <?php if ($isManager): ?><button class="btn-heavy btn-blue" style="padding:10px 15px; margin:0; font-size:1rem;" onclick="openCreateForm()"><i class="fas fa-plus"></i></button><?php endif; ?>
            <?php if (!in_array($_SESSION['role'], ['admin', 'director', 'system_manager'])): ?><a href="api/logout.php" class="btn-heavy btn-red" style="padding:10px 15px; margin:0; font-size:1rem; text-decoration:none;"><i class="fas fa-sign-out-alt"></i></a><?php endif; ?>
        </div>
    </div>

    <div class="content">
        <!-- CALENDAR -->
        <div id="view-calendar" class="view active">
            <div style="background: #fff; padding: 15px 20px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 15px; border: 1px solid #e2e8f0;">
                <h3 id="custom-cal-title" style="margin:0; font-weight:900; font-size:1.4rem; color: #0f172a; text-align: center;">Loading...</h3>
            </div>
            <div id="calendar"></div>
        </div>

        <!-- FLEET MANAGER (ADMIN ONLY) -->
        <?php if ($canManageFleet): ?>
        <div id="view-fleet" class="view">
            <h3 style="margin-top:0; font-weight:900; font-size: 1.6rem; color: #0f172a;"><i class="fas fa-truck-monster text-indigo-500"></i> ERP Fleet Setup</h3>
            
            <form id="fleetForm" style="background: #fff; padding: 20px; border-radius: 16px; margin-bottom: 30px; border: 1px solid #e2e8f0;">
                <h4 id="fleet-form-title" style="margin-top:0; color: #6366f1; margin-bottom: 15px;">Register Machinery</h4>
                <input type="hidden" id="edit_plant_id" value="">
                
                <div style="display:flex; gap:10px;">
                    <div style="flex:1;"><label>Category *</label><input type="text" id="new_plant_cat" class="input-heavy" placeholder="e.g. Pumps" required></div>
                    <div style="flex:1;"><label>Billing Company *</label><select id="new_plant_comp" class="input-heavy" required><option value="">-- Select --</option><option value="24">PRA</option><option value="26">PRAX</option></select></div>
                </div>

                <label>Plant Name / Description *</label><input type="text" id="new_plant_name" class="input-heavy" placeholder="e.g. Concrete Pump 48m" required>
                <label>Registration Plate *</label><input type="text" id="new_plant_reg" class="input-heavy" required>
                <label>Owned By (Developer / Client) *</label><select id="new_plant_owner" class="input-heavy" required></select>

                <div style="display:flex; gap:10px;">
                    <div style="flex:1;"><label>Pricing Model *</label><select id="new_plant_pricing" class="input-heavy" onchange="document.getElementById('fixed-price-box').style.display = ['fixed_then_hourly', 'per_trip'].includes(this.value) ? 'flex' : 'none';" required><option value="hourly">Standard Hourly</option><option value="fixed_then_hourly">Fixed Minimum + Hourly</option><option value="per_trip">Per Trip Rate</option></select></div>
                    <div style="flex:1;"><label>Min / Trip Hrs</label><input type="number" id="new_plant_min_hrs" class="input-heavy" value="0" required></div>
                </div>

                <div id="fixed-price-box" style="display: none; gap: 10px; background:#fef3c7; padding: 15px; border-radius: 12px; margin-bottom:15px;">
                    <div style="flex:1;"><label style="color:#b45309;">ERP Nom. Code (Fixed) *</label><input type="text" id="new_nom_fixed" class="input-heavy" style="margin-bottom:0;" placeholder="e.g. 0001"></div>
                    <div style="flex:1;"><label style="color:#b45309;">ERP Nom. Code (Var)</label><input type="text" id="new_nom_var" class="input-heavy" style="margin-bottom:0;" placeholder="e.g. 0002"></div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <div style="flex:1;"><label>In-House Rate (€)</label><input type="number" id="new_plant_rate_in" class="input-heavy" value="0.00" step="0.01" required></div>
                    <div style="flex:1;"><label>External Rate (€)</label><input type="number" id="new_plant_rate_ext" class="input-heavy" value="0.00" step="0.01" required></div>
                </div>
                
                <button type="button" id="save_fleet_btn" class="btn-heavy btn-blue" onclick="saveNewPlant()"><i class="fas fa-save"></i> Save to Fleet</button>
                <button type="button" id="cancel_edit_btn" class="btn-heavy btn-gray" style="display:none;" onclick="resetFleetForm()">Cancel Edit</button>
            </form>

            <h4 style="color:#64748b; text-transform:uppercase;">Active Fleet</h4>
            <div id="fleet-list" style="margin-bottom: 40px;"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')">Back to Calendar</button>
        </div>
        <?php endif; ?>

        <!-- BILLING LEDGER -->
        <?php if ($canViewLedger): ?>
        <div id="view-ledger" class="view">
            <h3 style="margin-top:0; font-weight:900; font-size: 1.6rem; color: #0f172a;"><i class="fas fa-book text-indigo-500"></i> ERP Billing Ledger</h3>
            <div id="ledger-list"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')" style="margin-top: 30px;"><i class="fas fa-arrow-left"></i> Back</button>
        </div>
        <?php endif; ?>

        <!-- CREATE/EDIT BOOKING -->
        <?php if ($isManager): ?>
        <div id="view-create" class="view">
            <h3 id="booking-form-title" style="margin-top:0; font-weight:900; font-size: 1.6rem; color: #0f172a;"><i class="fas fa-calendar-alt text-blue-500"></i> Manage Booking</h3>
            <form id="createBookingForm" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                <input type="hidden" id="edit_booking_id" value="">
                
                <div style="display:flex; gap:10px;">
                    <div style="flex:1;"><label>Category</label><select id="plant_category" class="input-heavy" required onchange="updatePlantDropdown()"></select></div>
                    <div style="flex:2;"><label>Machinery</label><select id="plant_id" class="input-heavy" required onchange="onPlantSelected()"></select></div>
                </div>

                <label>Assigned Driver</label><select id="driver_id" class="input-heavy"></select>
                <label>Job Type</label><select id="booking_type" class="input-heavy" onchange="toggleJobType()"><option value="in-house">In-House Project</option><option value="external">External / ERP Client</option></select>

                <div id="inhouse-fields"><label>Select Project (Pre-loads map)</label><select id="project_id" class="input-heavy" onchange="updateProjectLocation()"></select></div>
                
                <div id="client-fields" style="position: relative;">
                    <label>ERP Client (Select Vehicle First)</label>
                    <input type="text" id="client_name" class="input-heavy" placeholder="start writing client here" autocomplete="off" onkeyup="filterLocalClients(this.value)" disabled>
                    <input type="hidden" id="client_code">
                    <div id="client_search_results" style="display:none; position:absolute; top:85px; left:0; right:0; background:#fff; border:2px solid #6366f1; border-radius:12px; z-index:100; max-height:250px; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.2);"></div>
                </div>

                <label>Location (Tap Map to Pin manually)</label>
                <div id="map" style="width: 100%; height: 250px; border-radius: 12px; margin-bottom: 15px; border: 2px solid #e2e8f0;"></div>
                <input type="hidden" id="loc_lat"><input type="hidden" id="loc_lng">
                
                <label>Comments / Instructions</label><textarea id="booking_comments" class="input-heavy" rows="2"></textarea>
                <label>Booking Date</label><input type="date" id="booking_date" class="input-heavy" required>
                <div style="display:flex; gap:10px;"><div style="flex:1;"><label>Start</label><input type="time" id="start_time" class="input-heavy" value="08:00" required></div><div style="flex:1;"><label>End</label><input type="time" id="end_time" class="input-heavy" value="17:00" required></div></div>

                <button type="button" id="submit_booking_btn" class="btn-heavy btn-blue" onclick="submitBooking()"><i class="fas fa-check"></i> Save Booking</button>
                <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')">Cancel</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- ACTIVE JOB -->
        <div id="view-job" class="view">
            <div style="background: #fff; padding: 25px; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <h3 id="job-title" style="margin:0 0 15px 0; font-weight:900; font-size:1.8rem; color: #0f172a;"></h3>
                <div id="job-details" style="font-size: 1.1rem; color: #475569; line-height: 1.6;"></div>
            </div>
            <div id="punch-controls"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')"><i class="fas fa-arrow-left"></i> Back</button>
        </div>

        <!-- PUNCH OUT / TRIP COUNTER -->
        <div id="view-punch-out" class="view">
            <h3 style="margin-top:0; color:#e11d48; font-weight: 900; font-size: 1.6rem;"><i class="fas fa-flag-checkered"></i> Job Completion</h3>
            <div style="background: #fff; padding: 20px; border-radius: 16px; margin-bottom: 30px; border: 1px solid #e2e8f0;">
                <input type="hidden" id="punchout_booking_id">
                
                <div id="trip-qty-box" style="display:none; background:#eff6ff; padding:15px; border-radius:12px; margin-bottom:15px; border:1px solid #bfdbfe;">
                    <label style="color:#1d4ed8;">Total Trips Executed</label>
                    <input type="number" id="qty_trips" class="input-heavy" style="margin-bottom:0; font-size:1.5rem; text-align:center;" min="1" value="1">
                    <p style="font-size:0.8rem; color:#3b82f6; margin-top:5px;">This vehicle uses Per Trip billing. Ensure the client verifies this quantity.</p>
                </div>

                <label>Client Representative Name</label><input type="text" id="rep_name" class="input-heavy" required>
                <label>Client ID Card Number</label><input type="text" id="rep_id" class="input-heavy" required>
                <label>Client Signature</label><canvas id="signature-pad"></canvas>
                <button type="button" class="btn-heavy btn-gray" onclick="signaturePad.clear()" style="font-size:1rem; padding: 12px; background: #e2e8f0; color: #475569;"><i class="fas fa-eraser"></i> Clear Signature</button>
            </div>
            <button type="button" class="btn-heavy btn-red" onclick="submitPunchOut()"><i class="fas fa-check-circle"></i> Complete & Finalize Job</button>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-job')">Cancel</button>
        </div>
    </div>
</div>

<script>
    let calendar, mapboxMap, marker, signaturePad, groupedPlants = {};
    window.fleetData = []; // Store fleet data globally for the Edit button
    window.currentActiveJob = null; // Store the currently viewed job globally

    const isManager = <?= $isManager ? 'true' : 'false' ?>;
    const canManageFleet = <?= $canManageFleet ? 'true' : 'false' ?>;
    const canViewLedger = <?= $canViewLedger ? 'true' : 'false' ?>;
    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw';

    document.addEventListener('DOMContentLoaded', () => {
        initCalendar(); signaturePad = new SignaturePad(document.getElementById('signature-pad'), { penColor: "rgb(15, 23, 42)" });
        if (isManager) loadFormData();
    });

    function showView(id) {
        document.querySelectorAll('.view').forEach(el => el.classList.remove('active')); document.getElementById(id).classList.add('active'); window.scrollTo(0, 0); 
        if (id === 'view-calendar' && calendar) calendar.render();
        if (id === 'view-create') setTimeout(initMap, 200); if (id === 'view-punch-out') setTimeout(resizeCanvas, 100);
    }

    function initCalendar() {
        calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
            initialView: isManager ? 'timeGridWeek' : 'listDay',
            headerToolbar: { left: 'prev,next today', center: '', right: isManager ? 'dayGridMonth,timeGridWeek,timeGridDay' : '' },
            slotMinTime: '06:00:00', slotMaxTime: '20:00:00', allDaySlot: false, contentHeight: 'auto',
            events: 'api/plant_actions.php?action=fetch_bookings', eventClick: (info) => loadJob(info.event.id),
            datesSet: (info) => document.getElementById('custom-cal-title').innerText = info.view.title
        }); calendar.render();
    }

    function initMap() {
        if (mapboxMap) { mapboxMap.resize(); return; }
        mapboxMap = new mapboxgl.Map({ container: 'map', style: 'mapbox://styles/mapbox/streets-v12', center: [14.38, 35.92], zoom: 10 });
        mapboxMap.on('click', (e) => {
            if (marker) marker.remove(); marker = new mapboxgl.Marker({color: '#f43f5e'}).setLngLat(e.lngLat).addTo(mapboxMap);
            document.getElementById('loc_lat').value = e.lngLat.lat; document.getElementById('loc_lng').value = e.lngLat.lng;
        });
    }

    function updateProjectLocation() {
        const pId = document.getElementById('project_id').value; if (!pId) return;
        fetch(`api/plant_actions.php?action=get_project_location&project_id=${pId}`).then(r=>r.json()).then(data => {
            if (data && data.latitude && data.longitude) {
                document.getElementById('loc_lat').value = data.latitude; document.getElementById('loc_lng').value = data.longitude;
                if (mapboxMap) { if (marker) marker.remove(); marker = new mapboxgl.Marker({color: '#f43f5e'}).setLngLat([data.longitude, data.latitude]).addTo(mapboxMap); mapboxMap.flyTo({center: [data.longitude, data.latitude], zoom: 14}); }
            }
        });
    }

    function resizeCanvas() {
        const cvs = document.getElementById('signature-pad'); const r = Math.max(window.devicePixelRatio || 1, 1);
        cvs.width = cvs.offsetWidth * r; cvs.height = cvs.offsetHeight * r; cvs.getContext("2d").scale(r, r); signaturePad.clear();
    }

    function loadFormData() {
        fetch('api/plant_actions.php?action=form_data').then(r=>r.json()).then(d => {
            groupedPlants = d.plants;
            document.getElementById('plant_category').innerHTML = '<option value="">-- Category --</option>' + Object.keys(groupedPlants).map(c => `<option value="${c}">${c}</option>`).join('');
            document.getElementById('driver_id').innerHTML = '<option value="">-- Unassigned --</option>' + d.drivers.map(drv => `<option value="${drv.id}">${drv.first_name} ${drv.last_name}</option>`).join('');
            
            let projGroups = {};
            d.projects.forEach(prj => {
                let loc = (prj.locality && prj.locality.trim() !== '') ? prj.locality : 'General / Other Regions';
                if (!projGroups[loc]) projGroups[loc] = [];
                projGroups[loc].push(prj);
            });

            let projHtml = '<option value="">-- Select Project --</option>';
            Object.keys(projGroups).sort().forEach(loc => {
                projHtml += `<optgroup label="📍 ${loc}">`;
                projGroups[loc].forEach(prj => { projHtml += `<option value="${prj.id}">${prj.name}</option>`; });
                projHtml += `</optgroup>`;
            });
            document.getElementById('project_id').innerHTML = projHtml;
            
            updatePlantDropdown();
        });
    }

    let currentErpClients = [];

    function updatePlantDropdown() {
        const cat = document.getElementById('plant_category').value; const pSelect = document.getElementById('plant_id');
        if(!cat || !groupedPlants[cat]) { pSelect.innerHTML = '<option value="">-- Select Plant --</option>'; return; }
        pSelect.innerHTML = '<option value="">-- Select Machinery --</option>' + groupedPlants[cat].map(p => `<option value="${p.id}" data-company-id="${p.billing_company_id}">${p.name} (${p.registration_plate||'N/A'})</option>`).join('');
        resetClientSearch();
    }

    function onPlantSelected() {
        resetClientSearch();
        const pSelect = document.getElementById('plant_id');
        if(pSelect.selectedIndex <= 0 || pSelect.value === '') return;
        const compId = pSelect.options[pSelect.selectedIndex].getAttribute('data-company-id');
        
        const clientInput = document.getElementById('client_name');
        clientInput.placeholder = "Loading clients from ERP...";
        clientInput.disabled = true;

        fetch(`api/plant_actions.php?action=get_company_clients&company_id=${compId}`).then(r => r.json()).then(res => {
            currentErpClients = res; clientInput.placeholder = "start writing client here"; clientInput.disabled = false; 
        }).catch(err => { clientInput.placeholder = "Error loading clients"; });
    }

    function resetClientSearch() { 
        document.getElementById('client_code').value = ''; document.getElementById('client_name').value = ''; 
        document.getElementById('client_name').disabled = true; document.getElementById('client_name').placeholder = "start writing client here";
        document.getElementById('client_search_results').style.display = 'none'; currentErpClients = [];
    }

    function filterLocalClients(query) {
        const resultsDiv = document.getElementById('client_search_results'); 
        if(query.length < 2) { resultsDiv.style.display = 'none'; return; }
        const q = query.toLowerCase().trim();
        const filtered = currentErpClients.filter(c => (c.name || '').toLowerCase().includes(q)).slice(0, 20);
        if(filtered.length === 0) { resultsDiv.innerHTML = '<div style="padding:15px; color:#ef4444;">No client found.</div>'; } 
        else { resultsDiv.innerHTML = filtered.map(c => `<div style="padding:15px; cursor:pointer; border-bottom:1px solid #e2e8f0; font-weight:bold; color:#0f172a;" onclick="selectClient('${c.code}', '${c.name.replace(/'/g, "\\'")}')">${c.name} <br><span style="color:#64748b; font-weight:normal; font-size:0.85rem;">Code: ${c.code}</span></div>`).join(''); }
        resultsDiv.style.display = 'block';
    }

    function selectClient(code, name) { 
        document.getElementById('client_code').value = code; document.getElementById('client_name').value = name; document.getElementById('client_search_results').style.display = 'none'; 
    }

    function toggleJobType() {
        const type = document.getElementById('booking_type').value;
        document.getElementById('inhouse-fields').style.display = type === 'in-house' ? 'block' : 'none';
    }

    function openCreateForm() {
        document.getElementById('booking-form-title').innerHTML = '<i class="fas fa-calendar-alt text-blue-500"></i> Manage Booking';
        document.getElementById('edit_booking_id').value = ''; document.getElementById('submit_booking_btn').innerHTML = '<i class="fas fa-check"></i> Save Booking';
        document.getElementById('createBookingForm').reset(); if(marker) marker.remove(); toggleJobType(); resetClientSearch(); showView('view-create');
    }

    function initiateBookingEdit() {
        const j = window.currentActiveJob;
        if (!j) return;
        
        document.getElementById('booking-form-title').innerHTML = '<i class="fas fa-edit text-blue-500"></i> Edit Booking';
        document.getElementById('edit_booking_id').value = j.id;
        
        // Cascading selects initialization
        document.getElementById('plant_category').value = j.category;
        updatePlantDropdown();
        document.getElementById('plant_id').value = j.plant_id;
        
        document.getElementById('driver_id').value = j.driver_id || '';
        document.getElementById('booking_type').value = j.booking_type; toggleJobType();
        document.getElementById('project_id').value = j.project_id || '';
        document.getElementById('booking_date').value = j.booking_date;
        document.getElementById('start_time').value = j.start_time;
        document.getElementById('end_time').value = j.end_time;
        document.getElementById('booking_comments').value = j.comments || '';
        document.getElementById('loc_lat').value = j.location_lat || '';
        document.getElementById('loc_lng').value = j.location_lng || '';
        
        // Background client fetch
        onPlantSelected();
        setTimeout(() => { document.getElementById('client_code').value = j.client_code || ''; document.getElementById('client_name').value = j.client_name || ''; }, 800);

        document.getElementById('submit_booking_btn').innerHTML = '<i class="fas fa-save"></i> Update Booking';
        showView('view-create');

        if(j.location_lat && j.location_lng) {
            setTimeout(() => {
                if(!mapboxMap) initMap();
                if(marker) marker.remove();
                marker = new mapboxgl.Marker({color: '#f43f5e'}).setLngLat([j.location_lng, j.location_lat]).addTo(mapboxMap);
                mapboxMap.flyTo({center: [j.location_lng, j.location_lat], zoom: 14});
            }, 300);
        }
    }

    function submitBooking() {
        const btn = document.getElementById('submit_booking_btn'); btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        const editId = document.getElementById('edit_booking_id').value;
        const fd = new FormData(); fd.append('action', editId ? 'update_booking' : 'create_booking'); if (editId) fd.append('edit_id', editId);
        fd.append('plant_id', document.getElementById('plant_id').value); fd.append('driver_id', document.getElementById('driver_id').value);
        fd.append('booking_type', document.getElementById('booking_type').value); fd.append('project_id', document.getElementById('project_id').value);
        fd.append('client_name', document.getElementById('client_name').value); fd.append('client_code', document.getElementById('client_code').value);
        fd.append('loc_lat', document.getElementById('loc_lat').value); fd.append('loc_lng', document.getElementById('loc_lng').value);
        fd.append('comments', document.getElementById('booking_comments').value); fd.append('booking_date', document.getElementById('booking_date').value);
        fd.append('start_time', document.getElementById('start_time').value); fd.append('end_time', document.getElementById('end_time').value);

        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') { alert("Saved!"); calendar.refetchEvents(); showView('view-calendar'); } else { alert("Error: " + res); }
            btn.disabled = false; btn.innerHTML = editId ? '<i class="fas fa-save"></i> Update Booking' : '<i class="fas fa-check"></i> Save Booking';
        });
    }

    function loadFleetView() {
        if (!canManageFleet) return;
        fetch('api/plant_actions.php?action=get_clients').then(r=>r.json()).then(clients => document.getElementById('new_plant_owner').innerHTML = '<option value="">-- Select Owner --</option>' + clients.map(c => `<option value="${c.id}">${c.name}</option>`).join(''));
        fetch('api/plant_actions.php?action=get_fleet').then(r=>r.json()).then(fleet => {
            window.fleetData = fleet;
            document.getElementById('fleet-list').innerHTML = fleet.length === 0 ? '<p>No machinery.</p>' : fleet.map(p => `
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:15px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <div style="font-weight:900; font-size:1.2rem;">${p.name} <span style="font-size:0.8rem; background:#e0e7ff; color:#4f46e5; padding:3px 6px; border-radius:4px;">${p.billing_company_name||'Unknown'}</span></div>
                        <div style="color:#64748b; font-size:0.95rem; margin-bottom: 8px;">Cat: <b>${p.category}</b> | Reg: <b>${p.registration_plate||'N/A'}</b></div>
                        <div style="font-size:0.85rem; color:#b45309;">Fixed Nom: ${p.nom_code_fixed||'N/A'} | Var Nom: ${p.nom_code_variable||'N/A'}</div>
                    </div>
                    <button onclick="editPlant(${p.id})" style="background:#e2e8f0; color:#475569; border:none; padding:8px 12px; border-radius:8px; font-weight:bold; cursor:pointer;"><i class="fas fa-edit"></i> Edit</button>
                </div>`).join('');
        }); showView('view-fleet');
    }

    function editPlant(id) {
        const p = window.fleetData.find(x => x.id == id);
        if(!p) return;
        
        document.getElementById('fleet-form-title').innerText = "Edit Machinery";
        document.getElementById('edit_plant_id').value = p.id;
        document.getElementById('new_plant_cat').value = p.category;
        document.getElementById('new_plant_comp').value = p.billing_company_id;
        document.getElementById('new_plant_name').value = p.name;
        document.getElementById('new_plant_reg').value = p.registration_plate;
        document.getElementById('new_plant_owner').value = p.developer_client_id;
        document.getElementById('new_plant_pricing').value = p.pricing_type;
        document.getElementById('new_plant_min_hrs').value = p.min_hours;
        document.getElementById('new_nom_fixed').value = p.nom_code_fixed || '';
        document.getElementById('new_nom_var').value = p.nom_code_variable || '';
        document.getElementById('new_plant_rate_in').value = p.inhouse_rate;
        document.getElementById('new_plant_rate_ext').value = p.external_rate;
        
        document.getElementById('fixed-price-box').style.display = ['fixed_then_hourly', 'per_trip'].includes(p.pricing_type) ? 'flex' : 'none';
        
        const saveBtn = document.getElementById('save_fleet_btn');
        saveBtn.innerHTML = '<i class="fas fa-save"></i> Update Machinery';
        document.getElementById('cancel_edit_btn').style.display = 'block';
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function resetFleetForm() {
        document.getElementById('fleetForm').reset();
        document.getElementById('fleet-form-title').innerText = "Register Machinery";
        document.getElementById('edit_plant_id').value = '';
        document.getElementById('fixed-price-box').style.display = 'none';
        document.getElementById('save_fleet_btn').innerHTML = '<i class="fas fa-save"></i> Save to Fleet';
        document.getElementById('cancel_edit_btn').style.display = 'none';
    }

    function saveNewPlant() {
        // Strict Validation Check
        if(!document.getElementById('new_plant_cat').value) return alert("Category is required.");
        if(!document.getElementById('new_plant_name').value) return alert("Plant Name is required.");
        if(!document.getElementById('new_plant_reg').value) return alert("Registration Plate is required.");
        if(!document.getElementById('new_plant_comp').value) return alert("Billing Company is required.");
        if(!document.getElementById('new_plant_owner').value) return alert("Owner is required.");
        
        const pt = document.getElementById('new_plant_pricing').value;
        if(['fixed_then_hourly', 'per_trip'].includes(pt)) {
            if(!document.getElementById('new_nom_fixed').value) return alert("Fixed Nominal Code is required for this pricing type.");
        }

        const editId = document.getElementById('edit_plant_id').value;
        const fd = new FormData(); 
        fd.append('action', editId ? 'update_plant' : 'save_plant');
        if (editId) fd.append('edit_plant_id', editId);
        
        fd.append('category', document.getElementById('new_plant_cat').value); fd.append('name', document.getElementById('new_plant_name').value);
        fd.append('reg', document.getElementById('new_plant_reg').value); fd.append('owner_id', document.getElementById('new_plant_owner').value);
        fd.append('billing_company_id', document.getElementById('new_plant_comp').value); fd.append('pricing_type', document.getElementById('new_plant_pricing').value);
        fd.append('min_hours', document.getElementById('new_plant_min_hrs').value); fd.append('nom_code_fixed', document.getElementById('new_nom_fixed').value);
        fd.append('nom_code_variable', document.getElementById('new_nom_var').value); fd.append('rate_in', document.getElementById('new_plant_rate_in').value);
        fd.append('rate_ext', document.getElementById('new_plant_rate_ext').value);

        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => { 
            if (res === 'OK') { 
                alert(editId ? "Machinery Updated!" : "Machinery Added!"); 
                resetFleetForm();
                loadFormData(); 
                loadFleetView(); 
            } else alert("Error: " + res); 
        });
    }

    function loadJob(id) {
        fetch(`api/plant_actions.php?action=get_job&id=${id}`).then(r=>r.json()).then(job => {
            window.currentActiveJob = job; // Save for Edit capability
            document.getElementById('job-title').innerHTML = `<i class="fas fa-truck-pickup text-indigo-500"></i> ${job.plant_name}`;
            let statCol = job.status === 'Completed' ? '#10b981' : (job.status === 'In Progress' ? '#f59e0b' : '#6366f1');
            let mapBtn = job.location_lat ? `<a href="https://www.google.com/maps/search/?api=1&query=${job.location_lat},${job.location_lng}" target="_blank" style="display:inline-block; background:#0f172a; color:#fff; padding:8px 15px; border-radius:8px; font-weight:bold; font-size:0.9rem; text-decoration:none; margin-top:12px; margin-bottom:10px;"><i class="fas fa-map-pin"></i> Open Maps</a>` : '';
            let mapPre = job.location_lat ? `<div id="job-preview-map" style="width:100%; height:200px; border-radius:8px; border:1px solid #e2e8f0; margin-top:10px;"></div>` : '';
            let commHtml = job.comments ? `<div style="background:#fef3c7; border:1px solid #fde68a; padding:15px; border-radius:10px; margin-bottom:15px; color:#92400e; font-size:0.95rem;"><b>Notes:</b><br>${job.comments.replace(/\n/g, '<br>')}</div>` : '';

            document.getElementById('job-details').innerHTML = `<div style="margin-bottom:12px;"><b>Date:</b> ${job.booking_date} (${job.start_time.substring(0,5)} - ${job.end_time.substring(0,5)})</div>
                <div style="margin-bottom:12px;"><b>Type:</b> ${job.booking_type.toUpperCase()}</div>
                <div style="margin-bottom:12px;"><b>Status:</b> <span style="color:${statCol}; font-weight:bold;">${job.status}</span></div>
                ${commHtml}<hr style="border: 1px solid #e2e8f0; margin: 20px 0;">
                <div style="background:#f1f5f9; padding:12px; border-radius:8px; font-weight:bold;">${job.location_text}</div>${mapPre}${mapBtn}`;

            let controlsHtml = ''; let today = new Date().toISOString().split('T')[0];
            
            // Driver actions
            if (!isManager && job.booking_date === today) {
                if (job.status === 'Pending') controlsHtml = `<button class="btn-heavy btn-green" onclick="punchJob(${job.id}, 'in')"><i class="fas fa-play"></i> Start Job</button>`;
                else if (job.status === 'In Progress') controlsHtml = `<button class="btn-heavy btn-red" onclick="startPunchOut(${job.id}, '${job.pricing_type}')"><i class="fas fa-stop"></i> Complete Job</button>`;
            }
            
            // Manager Actions
            if (isManager && job.status === 'Pending') {
                controlsHtml += `<button class="btn-heavy btn-blue" onclick="initiateBookingEdit()"><i class="fas fa-edit"></i> Edit Booking</button>`;
            }
            if (isManager && job.status !== 'Completed') {
                controlsHtml += `<button class="btn-heavy btn-red" onclick="cancelJob(${job.id})"><i class="fas fa-trash-alt"></i> Cancel Booking</button>`;
            }
            if (isManager && job.status === 'Completed') {
                controlsHtml += `<button class="btn-heavy btn-green" onclick="window.open('print_plant_invoice.php?booking_id=${job.id}', '_blank')"><i class="fas fa-file-invoice-dollar"></i> Review & Invoice (ERP)</button>`;
            }

            document.getElementById('punch-controls').innerHTML = controlsHtml; showView('view-job');
            if (job.location_lat) setTimeout(() => { const pm = new mapboxgl.Map({ container: 'job-preview-map', style: 'mapbox://styles/mapbox/streets-v12', center: [job.location_lng, job.location_lat], zoom: 13, interactive: false }); new mapboxgl.Marker({color: '#f43f5e'}).setLngLat([job.location_lng, job.location_lat]).addTo(pm); }, 200);
        });
    }

    function cancelJob(id) {
        if (!confirm("Delete booking?")) return;
        const fd = new FormData(); fd.append('action', 'cancel_booking'); fd.append('id', id);
        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => { if (res === 'OK') { calendar.refetchEvents(); showView('view-calendar'); } });
    }

    function punchJob(id, direction) {
        if (!confirm("Start Job?")) return;
        fetch(`api/plant_actions.php?action=punch_${direction}&id=${id}`).then(r=>r.text()).then(res => { if (res === 'OK') { loadJob(id); calendar.refetchEvents(); } });
    }

    function startPunchOut(id, pricingType) { 
        document.getElementById('punchout_booking_id').value = id; 
        const tBox = document.getElementById('trip-qty-box');
        if(pricingType === 'per_trip') { tBox.style.display = 'block'; document.getElementById('qty_trips').required = true; } 
        else { tBox.style.display = 'none'; document.getElementById('qty_trips').required = false; }
        showView('view-punch-out'); 
    }

    function submitPunchOut() {
        if (signaturePad.isEmpty()) { alert("Please obtain client signature."); return; }
        const btn = event.target.closest('button'); btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        const fd = new FormData(); fd.append('action', 'punch_out_complete'); fd.append('id', document.getElementById('punchout_booking_id').value);
        fd.append('qty_trips', document.getElementById('qty_trips').value); fd.append('rep_name', document.getElementById('rep_name').value);
        fd.append('rep_id', document.getElementById('rep_id').value); fd.append('signature', signaturePad.toDataURL());
        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') { alert("Completed!"); calendar.refetchEvents(); showView('view-calendar'); } else { alert("Error: " + res); btn.disabled = false; }
        });
    }

    function loadLedger() {
        if (!canViewLedger) return;
        fetch('api/plant_actions.php?action=get_ledger').then(r=>r.json()).then(jobs => {
            document.getElementById('ledger-list').innerHTML = jobs.length === 0 ? '<p>No bookings.</p>' : jobs.map(j => {
                let badge = j.payment_status === 'Invoiced' ? `<span style="background:#fef08a; color:#854d0e; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">Invoiced</span>` : `<span style="background:#e2e8f0; color:#475569; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">${j.payment_status}</span>`;
                let sysRef = j.invoice_sysref ? `<div style="color:#10b981; font-weight:bold; font-size:0.85rem; margin-top:5px;"><i class="fas fa-check-circle"></i> ERP Ref: ${j.invoice_sysref}</div>` : '';
                return `
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:15px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                        <div><div style="font-weight:900; font-size:1.1rem;">PRA-${j.booking_date.substring(0,4)}-${String(j.id).padStart(4,'0')} - ${j.plant_name}</div>
                        <div style="color:#64748b; font-size:0.85rem;">${j.booking_date} | ${j.booking_type === 'in-house' ? j.project_name + ' (' + (j.client_name || 'No Client') + ')' : j.client_name}</div>${sysRef}</div>
                        <div style="text-align:right;">${badge}</div>
                    </div>
                    <div style="border-top:1px solid #f1f5f9; padding-top:10px; display:flex; gap:10px;">
                        ${j.status === 'Completed' ? `<button onclick="window.open('print_plant_invoice.php?booking_id=${j.id}', '_blank')" style="background:#f1f5f9; color:#3b82f6; border:none; padding:8px 12px; border-radius:8px; font-weight:bold; cursor:pointer; flex:1;">View RFP</button>` : `<span style="color:#94a3b8; font-size:0.85rem;">Pending Completion</span>`}
                    </div>
                </div>`;
            }).join('');
        }); showView('view-ledger');
    }
</script>
</body>
</html>
