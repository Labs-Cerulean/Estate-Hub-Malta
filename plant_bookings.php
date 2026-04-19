<?php
require_once 'config.php';
require_once 'session-check.php';
require_once 'user-functions.php'; // Required to use hasPermission()

$role = $_SESSION['role'];

// Check if they are a dedicated plant user OR have the new permission
$isPlantUser = in_array($role, ['plant_manager', 'plant_driver']);
$hasAccess = hasPermission('view_plant_bookings') || $isPlantUser;

if (!$hasAccess) {
    die("Unauthorized Access.");
}

// Check if they have manager rights within the app (to create bookings)
$isManager = hasPermission('view_plant_bookings') || $role === 'plant_manager';
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
        .app-container { max-width: 600px; margin: 0 auto; background: #fff; min-height: 100vh; display: flex; flex-direction: column; }
        .header { background: #0f172a; color: #fff; padding: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 10; position: sticky; top: 0; }
        .header h2 { margin: 0; font-weight: 900; font-size: 1.5rem; }
        .content { flex: 1; padding: 20px; overflow-y: auto; }
        
        .btn-heavy { padding: 18px 20px; font-size: 1.25rem; font-weight: 900; border-radius: 12px; width: 100%; margin-bottom: 15px; border: none; cursor: pointer; text-transform: uppercase; letter-spacing: 1px; color: #fff; transition: 0.2s; }
        .btn-green { background: #10b981; } .btn-green:active { background: #059669; }
        .btn-blue { background: #3b82f6; } .btn-blue:active { background: #2563eb; }
        .btn-gray { background: #94a3b8; }
        .btn-red { background: #ef4444; }

        .input-heavy { width: 100%; padding: 15px; font-size: 1.1rem; border-radius: 10px; border: 2px solid #cbd5e1; margin-bottom: 15px; box-sizing: border-box; }
        label { font-weight: 700; color: #64748b; margin-bottom: 5px; display: block; font-size: 0.9rem; text-transform: uppercase; }
        
        #signature-pad { border: 2px dashed #94a3b8; border-radius: 12px; width: 100%; height: 250px; background: #f8fafc; touch-action: none; margin-bottom: 15px; }
        .view { display: none; }
        .view.active { display: block; }

        .fc-toolbar-title { font-size: 1.2rem !important; font-weight: 900 !important; }
        .fc-button { padding: 10px !important; border-radius: 8px !important; text-transform: capitalize !important; }
    </style>
</head>
<body>

<div class="app-container">
    <div class="header">
        <h2 onclick="showView('view-calendar')" style="cursor:pointer;"><i class="fas fa-tractor"></i> Plant Hub</h2>
        <div style="display: flex; gap: 10px;">
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

        <div id="view-create" class="view">
            <h3 style="margin-top:0;">Create Booking</h3>
            <form id="createBookingForm">
                <label>Plant / Machinery</label>
                <select id="plant_id" class="input-heavy" required></select>

                <label>Driver (Optional)</label>
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

                <label>Date</label>
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

                <button type="button" class="btn-heavy btn-green" onclick="submitBooking()">Save Booking</button>
                <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')">Cancel</button>
            </form>
        </div>

        <div id="view-job" class="view">
            <h3 id="job-title" style="margin-top:0; font-weight:900; font-size:1.8rem;"></h3>
            <p id="job-details" style="font-size: 1.1rem; color: #475569; line-height: 1.6;"></p>
            <hr style="border: 1px solid #e2e8f0; margin: 25px 0;">
            
            <div id="punch-controls"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')">Back to Calendar</button>
        </div>

        <div id="view-punch-out" class="view">
            <h3 style="margin-top:0; color:#ef4444;">Job Completion / Punch Out</h3>
            <p>Please complete the Delivery Note to conclude this job.</p>
            
            <input type="hidden" id="punchout_booking_id">
            <label>Client Representative Name</label>
            <input type="text" id="rep_name" class="input-heavy" placeholder="John Doe" required>
            
            <label>Client ID Card Number</label>
            <input type="text" id="rep_id" class="input-heavy" placeholder="1234567M" required>
            
            <label>Client Signature</label>
            <canvas id="signature-pad"></canvas>
            <button type="button" class="btn-heavy btn-gray" onclick="signaturePad.clear()" style="font-size:1rem; padding: 10px;">Clear Signature</button>

            <button type="button" class="btn-heavy btn-red" onclick="submitPunchOut()"><i class="fas fa-check-circle"></i> Punch Out & Finalize</button>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-job')">Cancel</button>
        </div>
    </div>
</div>

<script>
    let calendar, mapboxMap, marker, signaturePad;
    const isManager = <?= $isManager ? 'true' : 'false' ?>;
    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw';

    document.addEventListener('DOMContentLoaded', () => {
        initCalendar();
        signaturePad = new SignaturePad(document.getElementById('signature-pad'));
        if (isManager) loadFormData();
    });

    function showView(id) {
        document.querySelectorAll('.view').forEach(el => el.classList.remove('active'));
        document.getElementById(id).classList.add('active');
        if (id === 'view-calendar' && calendar) calendar.render();
        if (id === 'view-create') setTimeout(initMap, 100); // Fix map rendering bug
        if (id === 'view-punch-out') resizeCanvas();
    }

    function initCalendar() {
        const calEl = document.getElementById('calendar');
        calendar = new FullCalendar.Calendar(calEl, {
            initialView: isManager ? 'timeGridWeek' : 'listDay',
            headerToolbar: { left: 'prev,next today', center: 'title', right: isManager ? 'dayGridMonth,timeGridWeek,timeGridDay' : '' },
            events: 'api/plant_actions.php?action=fetch_bookings',
            eventClick: (info) => loadJob(info.event.id)
        });
        calendar.render();
    }

    function initMap() {
        if (mapboxMap) return;
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

    function loadFormData() {
        fetch('api/plant_actions.php?action=form_data').then(r=>r.json()).then(d => {
            document.getElementById('plant_id').innerHTML = d.plants.map(p => `<option value="${p.id}">${p.name} (${p.registration_plate})</option>`).join('');
            document.getElementById('driver_id').innerHTML = '<option value="">-- No Driver Assigned --</option>' + d.drivers.map(drv => `<option value="${drv.id}">${drv.first_name} ${drv.last_name}</option>`).join('');
            document.getElementById('project_id').innerHTML = d.projects.map(prj => `<option value="${prj.id}">${prj.name}</option>`).join('');
        });
    }

    function submitBooking() {
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
            if (res === 'OK') { alert("Booking Created!"); calendar.refetchEvents(); showView('view-calendar'); }
            else alert("Error: " + res);
        });
    }

    function loadJob(id) {
        fetch(`api/plant_actions.php?action=get_job&id=${id}`).then(r=>r.json()).then(job => {
            document.getElementById('job-title').innerText = job.plant_name;
            
            let details = `
                <b>Date:</b> ${job.booking_date} (${job.start_time} - ${job.end_time})<br>
                <b>Type:</b> ${job.booking_type.toUpperCase()}<br>
                <b>Status:</b> <span style="color:#3b82f6; font-weight:bold;">${job.status}</span><br>
                <br><b>Location:</b> ${job.location_text}
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
                controlsHtml = `<p style="color:#ef4444; font-weight:bold; text-align:center;">You can only punch into jobs on the scheduled day.</p>`;
            }

            document.getElementById('punch-controls').innerHTML = controlsHtml;
            showView('view-job');
        });
    }

    function punchJob(id, direction) {
        fetch(`api/plant_actions.php?action=punch_${direction}&id=${id}`).then(r=>r.text()).then(res => {
            if (res === 'OK') { alert("Success!"); loadJob(id); calendar.refetchEvents(); }
            else alert("Error: " + res);
        });
    }

    function startPunchOut(id) {
        document.getElementById('punchout_booking_id').value = id;
        showView('view-punch-out');
    }

    function submitPunchOut() {
        if (signaturePad.isEmpty()) { alert("Please ask the client to sign."); return; }
        if (!document.getElementById('rep_name').value || !document.getElementById('rep_id').value) { alert("Please fill all details."); return; }

        const fd = new FormData();
        fd.append('action', 'punch_out_complete');
        fd.append('id', document.getElementById('punchout_booking_id').value);
        fd.append('rep_name', document.getElementById('rep_name').value);
        fd.append('rep_id', document.getElementById('rep_id').value);
        fd.append('signature', signaturePad.toDataURL());

        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r=>r.text()).then(res => {
            if (res === 'OK') { 
                alert("Job Successfully Completed! Delivery Note generated and email sent to accounts."); 
                calendar.refetchEvents(); showView('view-calendar'); 
            } else alert("Error: " + res);
        });
    }
</script>
</body>
</html>
