<?php
require_once 'config.php';
require_once 'session-check.php';
require_once 'user-functions.php';

$role = $_SESSION['role'];
$isPlantUser = in_array($role, ['plant_manager', 'plant_driver']);
$isAccountant = ($role === 'accountant');

if (!hasPermission('view_plant_bookings') && !$isPlantUser && !$isAccountant) { 
    die("Unauthorized Access."); 
}

$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']); 
$canManageFleet = in_array($role, ['admin', 'system_manager']) || hasPermission('manage_plant_fleet'); 
$canViewLedger = in_array($role, ['admin', 'director', 'system_manager', 'accountant']) || hasPermission('view_plant_ledger');
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
        
        .fc { 
            --fc-page-bg-color: transparent;
            --fc-neutral-bg-color: #f8fafc;
            --fc-list-event-hover-bg-color: #f1f5f9;
            --fc-border-color: #e2e8f0;
        }
        .fc .fc-button-primary { background-color: #f1f5f9 !important; border: none !important; color: #475569 !important; font-weight: 700 !important; border-radius: 8px !important; text-transform: capitalize !important; padding: 8px 12px !important; box-shadow: none !important; transition: 0.2s; }
        .fc .fc-button-primary:hover { background-color: #e2e8f0 !important; color: #0f172a !important; } 
        .fc .fc-button-primary:not(:disabled).fc-button-active, .fc .fc-button-primary:not(:disabled):active { background-color: #6366f1 !important; color: #fff !important; }
        .fc-theme-standard td, .fc-theme-standard th { border-color: #f1f5f9 !important; } .fc-theme-standard .fc-scrollgrid { border: none !important; }
        .fc-col-header-cell-cushion { padding: 12px 0 !important; color: #64748b !important; font-weight: 800 !important; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
        .fc-event { border-radius: 6px !important; border: none !important; padding: 3px 5px !important; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
        .fc-event-title { white-space: pre-wrap !important; line-height: 1.4; padding-bottom: 2px; }
        
        .fc .fc-list-day-cushion { background-color: var(--fc-neutral-bg-color) !important; color: #0f172a !important; opacity: 0.9; font-weight: bold; }
        .fc-list-day-text, .fc-list-day-side-text, .fc-list-event-time, .fc-list-event-title, .fc-list-empty-cushion { color: #0f172a !important; }

        .step-disabled { opacity: 0.4; pointer-events: none; transition: all 0.3s ease; }
        .step-active { opacity: 1; pointer-events: auto; transition: all 0.3s ease; }
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
        <div id="view-calendar" class="view active">
            <div style="background: #fff; padding: 15px 20px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 15px; border: 1px solid #e2e8f0;">
                <h3 id="custom-cal-title" style="margin:0; font-weight:900; font-size:1.4rem; color: #0f172a; text-align: center;">Loading...</h3>
            </div>
            <div id="calendar"></div>
        </div>

        <?php if ($canManageFleet): ?>
        <div id="view-fleet" class="view">
            <h3 style="margin-top:0; font-weight:900; font-size: 1.6rem; color: #0f172a;"><i class="fas fa-truck-monster text-indigo-500"></i> ERP Fleet Setup</h3>
            
            <form id="fleetForm" style="background: #fff; padding: 20px; border-radius: 16px; margin-bottom: 30px; border: 1px solid #e2e8f0;">
                <h4 id="fleet-form-title" style="margin-top:0; color: #6366f1; margin-bottom: 15px;">Register Machinery</h4>
                <input type="hidden" id="edit_plant_id" value="">
                
                <div style="display:flex; gap:10px;">
                    <div style="flex:1;"><label>Category *</label>
                        <select id="new_plant_cat" class="input-heavy" required onchange="toggleFleetSetupFee()">
                            <option value="">-- Select --</option>
                            <option value="Booms">Booms</option>
                            <option value="Cranes">Cranes</option>
                            <option value="Drum Cutter">Drum Cutter</option>
                            <option value="Excavator">Excavator</option>
                            <option value="Other Trucks">Other Trucks</option>
                            <option value="Piling">Piling</option>
                            <option value="Pumps">Pumps</option>
                            <option value="Rock Saw">Rock Saw</option>
                            <option value="Scarifier">Scarifier</option>
                        </select>
                    </div>
                    <div style="flex:1;"><label>Billing & Ownership *</label>
                        <select id="new_plant_comp" class="input-heavy" required onchange="loadFleetNominals(this.value)">
                            <option value="">-- Select --</option>
                            <option value="24">PRA (PRA Construction)</option>
                            <option value="26">PRAX (PRAX Concrete)</option>
                        </select>
                    </div>
                </div>

                <label>Plant Name / Description *</label>
                <input type="text" id="new_plant_name" class="input-heavy" placeholder="e.g. Concrete Pump 48m" required>
                
                <label>Registration Plate (Optional)</label>
                <input type="text" id="new_plant_reg" class="input-heavy" placeholder="Leave blank if not applicable">

                <div style="display:flex; gap:10px;">
                    <div style="flex:2;"><label>Pricing Model *</label>
                        <select id="new_plant_pricing" class="input-heavy" onchange="togglePricingModel()" required>
                            <option value="hourly">Standard Hourly</option>
                            <option value="fixed_then_hourly">Fixed Minimum + Hourly (Base + Overtime)</option>
                            <option value="per_trip">Per Trip Rate</option>
                            <option value="daily">Daily Flat Rate</option>
                        </select>
                    </div>
                    <div style="flex:1; display:none;" id="min_hrs_box"><label>Min Hours</label><input type="number" id="new_plant_min_hrs" class="input-heavy" value="1" min="1"></div>
                </div>

                <div style="display:flex; gap:10px; margin-bottom: 15px;">
                    <div style="flex:1;"><label id="lbl_nom_fixed" style="color:#b45309;">Nominal Code *</label>
                        <select id="new_nom_fixed" class="input-heavy" style="margin-bottom:0;" required onchange="updateRatesDisplay()"><option value="">Loading ERP...</option></select>
                    </div>
                    <div style="flex:1; display:none;" id="box_nom_var"><label style="color:#b45309;">Variable Nominal Code *</label>
                        <select id="new_nom_var" class="input-heavy" style="margin-bottom:0;" onchange="updateRatesDisplay()"><option value="">Loading ERP...</option></select>
                    </div>
                </div>
                
                <div id="fleet-setup-fee-container" style="display:none; border: 2px dashed #bfdbfe; padding: 15px; border-radius: 12px; background: #eff6ff; margin-bottom: 15px;">
                    <h4 style="margin-top:0; color:#1d4ed8; font-size:0.9rem; margin-bottom:10px;"><i class="fas fa-truck-loading"></i> Optional Setup / Mobilisation Fee</h4>
                    <div style="display:flex; gap:10px; margin-bottom: 0;">
                        <div style="flex:2;">
                            <label style="color:#1e3a8a;">Setup Nominal Code</label>
                            <select id="new_nom_setup" class="input-heavy" style="margin-bottom:0;" onchange="updateSetupFeeValue()"><option value="">Loading ERP...</option></select>
                        </div>
                        <div style="flex:1;">
                            <label style="color:#1e3a8a;">Default Fee (€)</label>
                            <input type="number" id="new_plant_setup_fee" class="input-heavy" style="margin-bottom:0;" value="0.00" step="0.01">
                        </div>
                    </div>
                </div>

                <div style="display:flex; gap:10px; margin-bottom: 15px;">
                    <div style="flex:1;"><label>Operational Mode (Lifecycle & Dispatch) *</label>
                        <select id="new_lifecycle_mode" class="input-heavy" style="margin-bottom:0;" required>
                            <option value="Standard">Standard Shift (Driver Required)</option>
                            <option value="Multi-Day">Multi-Day Continuous (Driver Required)</option>
                            <option value="Auto-Scheduled">Auto-Scheduled / Static (No Driver)</option>
                        </select>
                    </div>
                </div>

                <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:12px; padding:15px; margin-bottom:15px;">
                    <label style="display:flex; align-items:center; gap:10px; margin:0; cursor:pointer; font-size:1.05rem; color:#0f172a; text-transform:none;">
                        <input type="checkbox" id="new_has_configs" style="width:20px; height:20px;" onchange="toggleConfigBuilder()">
                        <b>Enable Advanced Configurations (Modes / Add-ons)</b>
                    </label>
                    <div id="config_builder_container" style="display:none; margin-top:15px; border-top:1px dashed #cbd5e1; padding-top:15px;">
                        <div id="config_list"></div>
                        <button type="button" class="btn-heavy btn-gray" style="padding:8px 15px; font-size:0.9rem; margin-bottom:0; width:auto;" onclick="addConfigRow()"><i class="fas fa-plus"></i> Add Config Rule</button>
                    </div>
                </div>
                
                <label>ERP Pre-Loaded Live Rates</label>
                <div id="rate_display_box" style="background:#f8fafc; padding:15px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:15px; font-size: 0.95rem; color:#475569;">
                    <p style="margin:0; text-align:center;">Select Nominal Codes to view rates.</p>
                </div>
                
                <button type="button" id="save_fleet_btn" class="btn-heavy btn-blue" onclick="saveNewPlant()"><i class="fas fa-save"></i> Save to Fleet</button>
                <button type="button" id="cancel_edit_btn" class="btn-heavy btn-gray" style="display:none;" onclick="resetFleetForm()">Cancel Edit</button>
            </form>

            <h4 style="color:#64748b; text-transform:uppercase;">Active Fleet</h4>
            <div id="fleet-list" style="margin-bottom: 40px;"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')">Back to Calendar</button>
        </div>
        <?php endif; ?>

        <?php if ($canViewLedger): ?>
        <div id="view-ledger" class="view">
            <h3 style="margin-top:0; font-weight:900; font-size: 1.6rem; color: #0f172a;"><i class="fas fa-book text-indigo-500"></i> ERP Billing Ledger</h3>
            
            <div style="background:#eff6ff; padding:15px; border-radius:12px; margin-bottom:15px; border:1px solid #bfdbfe;">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div><label style="color:#1e3a8a;">Date From</label><input type="date" id="filter_start" class="input-heavy" style="margin-bottom:0; padding:10px;"></div>
                    <div><label style="color:#1e3a8a;">Date To</label><input type="date" id="filter_end" class="input-heavy" style="margin-bottom:0; padding:10px;"></div>
                    <div><label style="color:#1e3a8a;">Plant Type</label><select id="filter_plant_type" class="input-heavy" style="margin-bottom:0; padding:10px;"><option value="">All Types</option></select></div>
                    <div><label style="color:#1e3a8a;">Job Status</label><select id="filter_status" class="input-heavy" style="margin-bottom:0; padding:10px;"><option value="">All Statuses</option><option value="Pending">Pending</option><option value="In Progress">In Progress</option><option value="Completed">Completed</option></select></div>
                    <div><label style="color:#1e3a8a;">Payment Status</label><select id="filter_payment" class="input-heavy" style="margin-bottom:0; padding:10px;"><option value="">All Payments</option><option value="Pending">Pending</option><option value="Invoiced">Invoiced</option><option value="Settled">Settled</option></select></div>
                    <div><label style="color:#1e3a8a;">Client Name</label><input type="text" id="filter_client" class="input-heavy" style="margin-bottom:0; padding:10px;" placeholder="Search client..."></div>
                    <div><label style="color:#1e3a8a;">Project</label><select id="filter_project" class="input-heavy" style="margin-bottom:0; padding:10px;"><option value="">All Projects</option></select></div>
                    <div><label style="color:#1e3a8a;">Billing Company</label><select id="filter_company" class="input-heavy" style="margin-bottom:0; padding:10px;"><option value="">All Companies</option><option value="24">PRA (PRA Construction)</option><option value="26">PRAX (PRAX Concrete)</option></select></div>
                </div>
                <button type="button" class="btn-heavy btn-blue" style="margin-top: 15px; margin-bottom:0; padding:10px; font-size:1rem;" onclick="loadLedger()"><i class="fas fa-filter"></i> Apply Filters</button>
            </div>

            <div id="ledger-list"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')" style="margin-top: 30px;"><i class="fas fa-arrow-left"></i> Back</button>
        </div>
        <?php endif; ?>

        <?php if ($isManager): ?>
        <div id="view-create" class="view">
            <h3 id="booking-form-title" style="margin-top:0; font-weight:900; font-size: 1.6rem; color: #0f172a;"><i class="fas fa-calendar-alt text-blue-500"></i> Manage Booking</h3>
            <form id="createBookingForm" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                <input type="hidden" id="edit_booking_id" value="">
                
                <div id="seq-step-1" class="step-active" style="margin-bottom:18px;">
                    <label>1. Select Category</label>
                    <select id="plant_category" class="input-heavy" style="margin-bottom:0;" required onchange="updatePlantDropdown()"></select>
                </div>

                <div id="seq-step-2" class="step-disabled" style="margin-bottom:18px;">
                    <label>2. Select Machinery</label>
                    <select id="plant_id" class="input-heavy" style="margin-bottom:0;" required onchange="onPlantSelected()" disabled></select>
                    
                    <div id="setup-fee-container" style="display:none; background:#eff6ff; padding:15px; border-radius:12px; margin-top:10px; border:1px solid #bfdbfe;">
                        <label style="display:flex; align-items:center; gap:10px; margin:0; cursor:pointer; font-size:1.1rem; color:#1d4ed8; text-transform:none;">
                            <input type="checkbox" id="apply_setup_fee" value="1" style="width:20px; height:20px; cursor:pointer;" disabled>
                            <b style="padding-top:2px;">Apply One-Time Setup Fee (<span id="setup_fee_display_amount">€0.00</span>)</b>
                        </label>
                        <p style="font-size:0.8rem; color:#3b82f6; margin-top:5px; margin-bottom:0; font-weight:normal;">Check this if this is the first deployment day.</p>
                    </div>
                </div>

                <div id="seq-step-3" class="step-disabled" style="margin-bottom:18px;">
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;">
                            <label>3a. Assigned Driver</label>
                            <select id="driver_id" class="input-heavy" style="margin-bottom:0;" disabled></select>
                        </div>
                        <div style="flex:1;">
                            <label>3b. Job Type</label>
                            <select id="booking_type" class="input-heavy" style="margin-bottom:0;" onchange="toggleJobType()" disabled>
                                <option value="in-house">In-House Project</option>
                                <option value="external">External / ERP Client</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="seq-step-4" class="step-disabled" style="margin-bottom:18px; padding:15px; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:12px;">
                    <div id="inhouse-fields" style="position: relative; margin-bottom: 15px;">
                        <label>4a. Search Project (Pre-loads map)</label>
                        <input type="text" id="project_search" class="input-heavy" style="margin-bottom:0;" placeholder="start typing project name..." autocomplete="off" onkeyup="filterProjects(this.value)" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }" disabled>
                        <input type="hidden" id="project_id">
                        <div id="project_search_results" style="display:none; position:absolute; top:70px; left:0; right:0; background:#fff; border:2px solid #6366f1; border-radius:12px; z-index:100; max-height:250px; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.2);"></div>
                    </div>
                    
                    <div id="client-fields" style="position: relative;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 6px;">
                            <label style="margin-bottom: 0;">4b. ERP Client</label>
                            <label style="font-size: 0.95rem; color: #334155; font-weight: bold; cursor: pointer; margin: 0; padding: 4px 8px; background: #f1f5f9; border-radius: 8px; text-transform: none; display: flex; align-items: center; gap: 10px; border: 1px solid #cbd5e1;">
                                <input type="checkbox" id="client_tbc" onchange="toggleTBC()" style="width: 24px; height: 24px; margin: 0; cursor: pointer;" disabled> 
                                Client details TBC
                            </label>
                        </div>
                        <input type="text" id="client_name" class="input-heavy" style="margin-bottom:0;" placeholder="start writing client here" autocomplete="off" onkeyup="filterLocalClients(this.value)" disabled>
                        <input type="hidden" id="client_code">
                        <div id="client_search_results" style="display:none; position:absolute; top:70px; left:0; right:0; background:#fff; border:2px solid #6366f1; border-radius:12px; z-index:100; max-height:250px; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.2);"></div>
                    </div>
                </div>

                <div id="seq-step-5" class="step-disabled">
                    <label>5. Location (Tap Map to Pin manually)</label>
                    <div id="map" style="width: 100%; height: 250px; border-radius: 12px; margin-bottom: 15px; border: 2px solid #e2e8f0;"></div>
                    <input type="hidden" id="loc_lat"><input type="hidden" id="loc_lng">
                    
                    <label>Comments / Instructions</label>
                    <textarea id="booking_comments" class="input-heavy" rows="2" disabled></textarea>
                    
                    <div style="display:flex; gap:10px; margin-bottom:15px;">
                        <div style="flex:1;">
                            <label id="lbl_booking_date">Booking Date</label>
                            <input type="date" id="booking_date" class="input-heavy" style="margin-bottom:0;" required disabled>
                        </div>
                        <div style="flex:1; display:none;" id="box_end_date">
                            <label>End Date</label>
                            <input type="date" id="end_date" class="input-heavy" style="margin-bottom:0;" disabled>
                        </div>
                    </div>
                    
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;"><label>Start Time</label><input type="time" id="start_time" class="input-heavy" value="08:00" required disabled></div>
                        <div style="flex:1;"><label>End Time</label><input type="time" id="end_time" class="input-heavy" value="17:00" required disabled></div>
                    </div>

                    <button type="button" id="submit_booking_btn" class="btn-heavy btn-blue" onclick="submitBooking()" disabled><i class="fas fa-check"></i> Save Booking</button>
                    <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')" disabled>Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div id="view-job" class="view">
            <div style="background: #fff; padding: 25px; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <h3 id="job-title" style="margin:0 0 15px 0; font-weight:900; font-size:1.8rem; color: #0f172a;"></h3>
                <div id="job-details" style="font-size: 1.1rem; color: #475569; line-height: 1.6;"></div>
            </div>
            <div id="punch-controls"></div>
            <button type="button" class="btn-heavy btn-gray" onclick="showView('view-calendar')"><i class="fas fa-arrow-left"></i> Back</button>
        </div>

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
    window.fleetData = []; 
    window.currentActiveJob = null; 

    const isManager = <?= $isManager ? 'true' : 'false' ?>;
    const canManageFleet = <?= $canManageFleet ? 'true' : 'false' ?>;
    const canViewLedger = <?= $canViewLedger ? 'true' : 'false' ?>;
    const isAdmin = <?= ($role === 'admin') ? 'true' : 'false' ?>;
    mapboxgl.accessToken = 'pk.eyJ1IjoibmljaG9sYXN2IiwiYSI6ImNtbjBuemFmeTBscjEycHM5aDl2Y2VraDIifQ.Bk4c7hHHLtE59Ze8hYFFVw';

    document.addEventListener('DOMContentLoaded', () => {
        initCalendar(); 
        signaturePad = new SignaturePad(document.getElementById('signature-pad'), { penColor: "rgb(15, 23, 42)" });
        if (isManager) {
            loadFormData();
        }
    });

    function showView(id) {
        document.querySelectorAll('.view').forEach(el => el.classList.remove('active')); 
        document.getElementById(id).classList.add('active'); 
        window.scrollTo(0, 0); 
        
        if (id === 'view-calendar' && calendar) {
            calendar.render();
        }
        if (id === 'view-create') {
            setTimeout(initMap, 200); 
        }
        if (id === 'view-punch-out') {
            setTimeout(resizeCanvas, 100);
        }
    }

    function initCalendar() {
        calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
            initialView: isManager ? 'listMonth' : 'listDay',
            headerToolbar: { 
                left: 'prev,next today', 
                center: '', 
                right: isManager ? 'listMonth,timeGridWeek,timeGridDay' : 'listMonth,listDay' 
            },
            views: {
                listMonth: { buttonText: 'Month' },
                timeGridWeek: { buttonText: 'Week' },
                timeGridDay: { buttonText: 'Day' }
            },
            slotMinTime: '06:00:00', 
            slotMaxTime: '20:00:00', 
            allDaySlot: false, 
            contentHeight: 'auto',
            events: 'api/plant_actions.php?action=fetch_bookings', 
            eventClick: (info) => loadJob(info.event.id),
            datesSet: (info) => document.getElementById('custom-cal-title').innerText = info.view.title
        }); 
        calendar.render();
    }

    function initMap() {
        if (mapboxMap) { 
            mapboxMap.resize(); 
            return; 
        }
        mapboxMap = new mapboxgl.Map({ 
            container: 'map', 
            style: 'mapbox://styles/mapbox/streets-v12', 
            center: [14.38, 35.92], 
            zoom: 10 
        });
        mapboxMap.on('click', (e) => {
            if (marker) marker.remove(); 
            marker = new mapboxgl.Marker({color: '#f43f5e'}).setLngLat(e.lngLat).addTo(mapboxMap);
            document.getElementById('loc_lat').value = e.lngLat.lat; 
            document.getElementById('loc_lng').value = e.lngLat.lng;
        });
    }

    function updateProjectLocation() {
        const pId = document.getElementById('project_id').value; 
        
        // DEBUG 1: Let's see what ID is actually being grabbed
        console.log("Attempting to fetch map for Project ID:", pId);
        
        if (!pId) {
            console.log("Project ID is empty, stopping fetch.");
            return;
        }
        
        fetch(`api/plant_actions.php?action=get_project_location&project_id=${pId}`)
        .then(r => {
            console.log("Response Status:", r.status); // DEBUG 2: Check if API answers
            return r.json();
        })
        .then(data => {
            console.log("Data received from API:", data); // DEBUG 3: Check API payload

            if (data.error) { alert("Database Error: " + data.error); return; }
            const hasCoords = data && data.latitude && data.longitude && data.latitude !== "" && data.longitude !== "";
            const streetValue = data.street || data.address || data.street_name || "";
            const hasStreet = streetValue !== null && streetValue.trim() !== "";

            if (hasCoords && hasStreet) {
                document.getElementById('loc_lat').value = data.latitude; 
                document.getElementById('loc_lng').value = data.longitude;
                if (mapboxMap) { 
                    if (marker) marker.remove(); 
                    marker = new mapboxgl.Marker({color: '#f43f5e'}).setLngLat([data.longitude, data.latitude]).addTo(mapboxMap); 
                    mapboxMap.flyTo({center: [data.longitude, data.latitude], zoom: 14}); 
                }
            } else {
                document.getElementById('loc_lat').value = ''; document.getElementById('loc_lng').value = '';
                if (marker) marker.remove();
                alert("Location or street data for this project is missing/incomplete. Please select the exact location manually by tapping on the map.");
            }
        })
        .catch(err => {
            // DEBUG 4: This is the most important part. It will tell us EXACTLY why it failed.
            console.error("THE EXACT ERROR IS:", err); 
            
            document.getElementById('loc_lat').value = ''; document.getElementById('loc_lng').value = '';
            if (marker) marker.remove();
            alert("Failed to fetch project location. Please select the location manually by tapping on the map.");
        });
    }

    function resizeCanvas() {
        const cvs = document.getElementById('signature-pad'); 
        const r = Math.max(window.devicePixelRatio || 1, 1);
        cvs.width = cvs.offsetWidth * r; 
        cvs.height = cvs.offsetHeight * r; 
        cvs.getContext("2d").scale(r, r); 
        signaturePad.clear();
    }

    function loadFormData() {
        fetch('api/plant_actions.php?action=form_data')
        .then(r => r.json())
        .then(d => {
            groupedPlants = d.plants;
            document.getElementById('plant_category').innerHTML = '<option value="">-- Category --</option>' + Object.keys(groupedPlants).map(c => `<option value="${c}">${c}</option>`).join('');
            document.getElementById('driver_id').innerHTML = '<option value="">-- Unassigned --</option>' + d.drivers.map(drv => `<option value="${drv.id}">${drv.first_name} ${drv.last_name}</option>`).join('');
            
            if (canManageFleet) {
                const fleetCatSelect = document.getElementById('new_plant_cat');
                Object.keys(groupedPlants).sort().forEach(c => {
                    if(!Array.from(fleetCatSelect.options).some(o => o.value === c)) {
                        fleetCatSelect.insertAdjacentHTML('beforeend', `<option value="${c}">${c}</option>`);
                    }
                });
            }

            window.allProjects = d.projects;
            // Auto-populate ledger filter dropdowns
            if(document.getElementById('filter_plant_type')) {
                document.getElementById('filter_plant_type').innerHTML = '<option value="">All Types</option>' + Object.keys(groupedPlants).map(c => `<option value="${c}">${c}</option>`).join('');
            }
            if(document.getElementById('filter_project')) {
                document.getElementById('filter_project').innerHTML = '<option value="">All Projects</option>' + d.projects.map(prj => `<option value="${prj.id}">${prj.name}</option>`).join('');
            }
            updatePlantDropdown();
        });
    }

    let currentErpClients = [];

    window.erpNominals = [];

    function toggleConfigBuilder() {
        document.getElementById('config_builder_container').style.display = document.getElementById('new_has_configs').checked ? 'block' : 'none';
    }

function addConfigRow(data = {type: 'mode', name: '', price: 0, nom_code: ''}) {
        const list = document.getElementById('config_list');
        const rowId = 'cfg_' + Date.now() + Math.floor(Math.random() * 1000);
        
        let nomOpts = '<option value="">-- ERP Code --</option>';
        if(window.erpNominals && window.erpNominals.length > 0) {
            // Added data-ext and data-in so the dropdown contains the live ERP prices
            nomOpts += window.erpNominals.map(n => `<option value="${n.NCCode.trim()}" data-ext="${n.NCDefSP2}" data-in="${n.NCDefSP1}" ${data.nom_code === n.NCCode.trim() ? 'selected' : ''}>${n.NCCode.trim()} - ${n.NCDesc.trim()}</option>`).join('');
        }

        // The price field is now readonly and visually greyed out, while the nom dropdown triggers the update
        const html = `
        <div id="${rowId}" class="config-row" style="display:flex; gap:10px; margin-bottom:10px; align-items:center; background:#fff; padding:10px; border-radius:8px; border:1px solid #e2e8f0;">
            <select class="cfg-type input-heavy" style="margin:0; padding:8px; flex:1.5; font-size:0.9rem;">
                <option value="mode" ${data.type === 'mode' ? 'selected' : ''}>Mode (Rate/Hr)</option>
                <option value="addon" ${data.type === 'addon' ? 'selected' : ''}>Add-on (Unit Price)</option>
            </select>
            <input type="text" class="cfg-name input-heavy" style="margin:0; padding:8px; flex:2; font-size:0.9rem;" placeholder="Name (e.g. 3m Saw)" value="${data.name}">
            
            <input type="number" class="cfg-price input-heavy" style="margin:0; padding:8px; flex:1; font-size:0.9rem; background:#f1f5f9; color:#94a3b8; cursor:not-allowed;" placeholder="ERP Price" step="0.01" value="${data.price}" readonly>
            
            <select class="cfg-nom input-heavy" style="margin:0; padding:8px; flex:1.5; font-size:0.9rem;" onchange="updateConfigPrice(this)">${nomOpts}</select>
            <button type="button" onclick="document.getElementById('${rowId}').remove()" style="background:#ef4444; color:#fff; border:none; padding:10px; border-radius:8px; cursor:pointer;"><i class="fas fa-trash"></i></button>
        </div>`;
        list.insertAdjacentHTML('beforeend', html);
    }

    function updateConfigPrice(sel) {
        const priceInput = sel.closest('.config-row').querySelector('.cfg-price');
        if(sel.selectedIndex > 0) {
            const opt = sel.options[sel.selectedIndex];
            // Pull the External Commercial Rate from the ERP and set it
            const erpPrice = parseFloat(opt.dataset.ext) || 0;
            priceInput.value = erpPrice.toFixed(2);
        } else {
            priceInput.value = '0.00';
        }
    }

    function buildConfigJson() {
        if(!document.getElementById('new_has_configs').checked) return null;
        const rows = document.querySelectorAll('.config-row');
        let configs = [];
        rows.forEach(r => {
            configs.push({
                type: r.querySelector('.cfg-type').value,
                name: r.querySelector('.cfg-name').value,
                price: parseFloat(r.querySelector('.cfg-price').value) || 0,
                nom_code: r.querySelector('.cfg-nom').value
            });
        });
        return JSON.stringify(configs);
    }

    function toggleFleetSetupFee() {
        const cat = document.getElementById('new_plant_cat').value;
        const container = document.getElementById('fleet-setup-fee-container');
        if (cat === 'Rock Saw') {
            container.style.display = 'block';
        } else {
            container.style.display = 'none';
            document.getElementById('new_nom_setup').value = '';
            document.getElementById('new_plant_setup_fee').value = '0.00';
        }
    }

    function updateSetupFeeValue() {
        const setupSel = document.getElementById('new_nom_setup');
        if(setupSel.selectedIndex > 0) {
            const opt = setupSel.options[setupSel.selectedIndex];
            document.getElementById('new_plant_setup_fee').value = parseFloat(opt.dataset.ext).toFixed(2);
        } else {
            document.getElementById('new_plant_setup_fee').value = '0.00';
        }
    }

    function updatePlantDropdown(keepClientData = false) {
        const cat = document.getElementById('plant_category').value; 
        const pSelect = document.getElementById('plant_id');
        
        if(!cat || !groupedPlants[cat]) { 
            pSelect.innerHTML = '<option value="">-- Select Plant --</option>'; 
            ['seq-step-2', 'seq-step-3', 'seq-step-4', 'seq-step-5'].forEach(id => setStepState(id, false));
            return; 
        } else {
            setStepState('seq-step-2', true);
        }
        
        pSelect.innerHTML = '<option value="">-- Select Machinery --</option>' + groupedPlants[cat].map(p => {
            if (p.is_misconfigured) {
                return `<option value="${p.id}" data-company-id="${p.billing_company_id}" data-missing="true" style="color:#ef4444; background:#fef2f2; font-style:italic; font-weight:bold;">⚠️ ${p.name} (Setup Missing)</option>`;
            } else {
                return `<option value="${p.id}" data-company-id="${p.billing_company_id}" data-req-driver="${p.requires_driver !== null ? p.requires_driver : 1}" data-lifecycle="${p.lifecycle_type || 'Standard'}" data-missing="false">${p.name} (${p.registration_plate||'N/A'})</option>`;
            }
        }).join('');
        
        if (!keepClientData) resetClientSearch();
        document.getElementById('setup-fee-container').style.display = 'none';
        document.getElementById('apply_setup_fee').checked = false;
    }

    function onPlantSelected(keepClientData = false) {
        if (!keepClientData) resetClientSearch();
        
        const pSelect = document.getElementById('plant_id');
        if(pSelect.selectedIndex <= 0 || pSelect.value === '') {
            document.getElementById('setup-fee-container').style.display = 'none';
            document.getElementById('apply_setup_fee').checked = false;
            ['seq-step-3', 'seq-step-4', 'seq-step-5'].forEach(id => setStepState(id, false));
            return;
        } else {
            setStepState('seq-step-3', true);
            setStepState('seq-step-4', true);
        }
        
        const opt = pSelect.options[pSelect.selectedIndex];
        if (opt.dataset.missing === 'true') {
            alert("Billing details missing. Please configure them in the Fleet Setup before booking.");
            pSelect.value = ''; return;
        }

        // --- CAPABILITY 1: Toggle Driver Requirement ---
        const reqDriver = parseInt(opt.getAttribute('data-req-driver'));
        const driverContainer = document.getElementById('driver_id').parentElement;
        
        if (reqDriver === 0) {
            driverContainer.style.display = 'none';
            document.getElementById('driver_id').value = ''; // Ensure no driver is sent
        } else {
            driverContainer.style.display = 'block';
        }

        // --- CAPABILITY 2: Toggle End Date for Multi-Day/Auto ---
        const lifecycle = opt.getAttribute('data-lifecycle');
        const endDateBox = document.getElementById('box_end_date');
        const dateLabel = document.getElementById('lbl_booking_date');
        
        if (lifecycle === 'Multi-Day' || lifecycle === 'Auto-Scheduled') {
            endDateBox.style.display = 'block';
            dateLabel.innerText = 'Start Date';
            document.getElementById('end_date').required = true;
        } else {
            endDateBox.style.display = 'none';
            dateLabel.innerText = 'Booking Date';
            document.getElementById('end_date').required = false;
            document.getElementById('end_date').value = '';
        }

        const selectedPlantId = pSelect.value;
        const selectedCat = document.getElementById('plant_category').value;
        const plantObj = groupedPlants[selectedCat].find(p => p.id == selectedPlantId);
        
        if (plantObj && plantObj.category === 'Rock Saw' && parseFloat(plantObj.setup_fee) > 0) {
            document.getElementById('setup-fee-container').style.display = 'block';
            document.getElementById('setup_fee_display_amount').innerText = '€' + parseFloat(plantObj.setup_fee).toFixed(2);
        } else {
            document.getElementById('setup-fee-container').style.display = 'none';
            document.getElementById('apply_setup_fee').checked = false;
        }

        const compId = opt.getAttribute('data-company-id');
        const clientInput = document.getElementById('client_name');
        
        if (!keepClientData) {
            clientInput.placeholder = "Loading clients from ERP...";
            clientInput.disabled = true;
        }

        fetch(`api/plant_actions.php?action=get_company_clients&company_id=${compId}`)
        .then(r => r.json())
        .then(res => {
            currentErpClients = res; 
            clientInput.placeholder = "start writing client here"; 
            clientInput.disabled = false; 
        }).catch(err => { clientInput.placeholder = "Error loading clients"; });
    }
    function resetClientSearch() { 
        document.getElementById('client_code').value = ''; 
        document.getElementById('client_name').value = ''; 
        document.getElementById('client_name').disabled = true; 
        document.getElementById('client_name').placeholder = "start writing client here";
        document.getElementById('client_search_results').style.display = 'none'; 
        currentErpClients = [];
    }

    function filterLocalClients(query) {
        const resultsDiv = document.getElementById('client_search_results'); 
        if(query.length < 2) { resultsDiv.style.display = 'none'; return; }
        
        const q = query.toLowerCase().trim();
        const filtered = currentErpClients.filter(c => (c.name || '').toLowerCase().includes(q)).slice(0, 20);
        
        if(filtered.length === 0) { 
            resultsDiv.innerHTML = '<div style="padding:15px; color:#ef4444; font-weight:bold;">No client found.</div>'; 
        } else { 
            resultsDiv.innerHTML = filtered.map(c => {
                const safeName = (c.name || '').replace(/'/g, "\\'");
                if (c.status === 1) {
                    return `
                    <div style="padding:15px; cursor:pointer; border-bottom:1px solid #e2e8f0; font-weight:bold; color:#0f172a; background:#fff; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'" onclick="selectClient('${c.code}', '${safeName}')">
                        ${c.name} <br><span style="color:#64748b; font-weight:normal; font-size:0.85rem;">Code: ${c.code}</span>
                    </div>`;
                } else {
                    return `
                    <div style="padding:15px; cursor:not-allowed; border-bottom:1px solid #e2e8f0; background:#f1f5f9; opacity: 0.65;" onclick="alert('Client might have some pending bills, booking not allowed.')">
                        <span style="font-weight:bold; color:#64748b; text-decoration: line-through;">${c.name}</span> <br>
                        <span style="color:#ef4444; font-weight:bold; font-size:0.85rem;"><i class="fas fa-lock"></i> Blocked (Pending Bills)</span>
                    </div>`;
                }
            }).join(''); 
        }
        resultsDiv.style.display = 'block';
    }

    function selectClient(code, name) { 
        document.getElementById('client_code').value = code; document.getElementById('client_name').value = name; 
        document.getElementById('client_search_results').style.display = 'none'; 
        checkStep5(); // Re-evaluate step 5
    }

    function filterProjects(query) {
        const resultsDiv = document.getElementById('project_search_results'); 
        if(query.length < 2) { resultsDiv.style.display = 'none'; return; }
        
        const q = query.toLowerCase().trim();
        const filtered = window.allProjects.filter(p => (p.name || '').toLowerCase().includes(q)).slice(0, 20);
        
        if(filtered.length === 0) { 
            resultsDiv.innerHTML = '<div style="padding:15px; color:#ef4444; font-weight:bold;">No project found.</div>'; 
        } else { 
            resultsDiv.innerHTML = filtered.map(p => {
                // Fixed: Escape BOTH single and double quotes so it doesn't break HTML attributes
                const safeName = (p.name || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
                const loc = (p.locality && p.locality.trim() !== '') ? p.locality : 'General';
                return `
                <div style="padding:15px; cursor:pointer; border-bottom:1px solid #e2e8f0; font-weight:bold; color:#0f172a; background:#fff; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'" onclick="selectProject('${p.id}', '${safeName}')">
                    ${p.name} <br><span style="color:#64748b; font-weight:normal; font-size:0.85rem;">📍 ${loc}</span>
                </div>`;
            }).join(''); 
        }
        resultsDiv.style.display = 'block';
    }

    function selectProject(id, name) { 
        document.getElementById('project_id').value = id; 
        document.getElementById('project_search').value = name; 
        document.getElementById('project_search_results').style.display = 'none'; 
        updateProjectLocation(); 
        
        // 1. Grab the Billing Company of the machinery we selected in Step 2
        const pSelect = document.getElementById('plant_id');
        const compId = pSelect.selectedIndex > 0 ? pSelect.options[pSelect.selectedIndex].getAttribute('data-company-id') : '';

        // 2. Pass BOTH project_id and company_id to the backend
        fetch(`api/plant_actions.php?action=get_last_project_client&project_id=${id}&company_id=${compId}`)
        .then(r => r.json())
        .then(data => {
            let clientAutoFilled = false;
            
            if (data && data.client_code) {
                // Use loose equality (==) just in case the API returns an integer but the DOM has a string
                const validClient = currentErpClients.find(c => c.code == data.client_code);
                
                if (validClient) {
                    selectClient(validClient.code, validClient.name);
                    clientAutoFilled = true;
                    
                    // Flash green to indicate auto-fill
                    const cInput = document.getElementById('client_name');
                    cInput.style.backgroundColor = '#ecfdf5';
                    cInput.style.borderColor = '#10b981';
                    setTimeout(() => { cInput.style.backgroundColor = '#fff'; cInput.style.borderColor = '#e2e8f0'; }, 1500);
                }
            }
            
            // 3. IF NO VALID CLIENT WAS FOUND, EXPLICITLY CLEAR THE FIELD
            if (!clientAutoFilled) {
                document.getElementById('client_code').value = '';
                document.getElementById('client_name').value = '';
            }
            
            checkStep5();
        }).catch(() => {
            // Failsafe clear on error
            document.getElementById('client_code').value = '';
            document.getElementById('client_name').value = '';
            checkStep5();
        });
    }

    function toggleJobType() {
        const type = document.getElementById('booking_type').value;
        const inhouseDiv = document.getElementById('inhouse-fields');
        const projInput = document.getElementById('project_search');
        
        if (type === 'in-house') {
            inhouseDiv.style.opacity = '1';
            inhouseDiv.style.pointerEvents = 'auto';
            projInput.disabled = false;
            projInput.placeholder = "start typing project name...";
        } else {
            inhouseDiv.style.opacity = '0.4';
            inhouseDiv.style.pointerEvents = 'none';
            projInput.disabled = true;
            projInput.value = ''; 
            document.getElementById('project_id').value = '';
            projInput.placeholder = "N/A for External Jobs";
        }
        checkStep5();
    }
    function openCreateForm() {
        document.getElementById('booking-form-title').innerHTML = '<i class="fas fa-calendar-alt text-blue-500"></i> Manage Booking';
        document.getElementById('edit_booking_id').value = ''; 
        document.getElementById('submit_booking_btn').innerHTML = '<i class="fas fa-check"></i> Save Booking';
        document.getElementById('createBookingForm').reset(); 
        document.getElementById('client_tbc').checked = false;
        document.getElementById('client_name').style.backgroundColor = '#fff'; document.getElementById('client_name').style.color = '#1e293b';
        ['seq-step-2', 'seq-step-3', 'seq-step-4', 'seq-step-5'].forEach(id => setStepState(id, false));
        document.getElementById('project_search').value = '';
        document.getElementById('project_search_results').style.display = 'none';
        
        document.getElementById('setup-fee-container').style.display = 'none';
        
        if(marker) marker.remove(); toggleJobType(); resetClientSearch(); showView('view-create');
    }

    function initiateBookingEdit() {
        const j = window.currentActiveJob;
        ['seq-step-2', 'seq-step-3', 'seq-step-4', 'seq-step-5'].forEach(id => setStepState(id, true));
        
        if (!j) return;
        
        document.getElementById('booking-form-title').innerHTML = '<i class="fas fa-edit text-blue-500"></i> Edit Booking';
        document.getElementById('edit_booking_id').value = j.id;
        
        document.getElementById('plant_category').value = j.category;
        updatePlantDropdown(true); 
        
        document.getElementById('plant_id').value = j.plant_id;
        document.getElementById('driver_id').value = j.driver_id || '';
        document.getElementById('booking_type').value = j.booking_type; toggleJobType();
        
        document.getElementById('project_id').value = j.project_id || '';
        if (j.project_id && window.allProjects) {
            const prj = window.allProjects.find(p => p.id == j.project_id);
            document.getElementById('project_search').value = prj ? prj.name : '';
        } else {
            document.getElementById('project_search').value = '';
        }
        document.getElementById('booking_date').value = j.booking_date;
        document.getElementById('start_time').value = j.start_time;
        document.getElementById('end_time').value = j.end_time;
        document.getElementById('booking_comments').value = j.comments || '';
        document.getElementById('loc_lat').value = j.location_lat || '';
        document.getElementById('loc_lng').value = j.location_lng || '';
        
        document.getElementById('client_code').value = j.client_code || ''; 
        document.getElementById('client_name').value = j.client_name || ''; 
        
        if (j.client_code === 'TBC') {
            document.getElementById('client_tbc').checked = true;
            document.getElementById('client_name').disabled = true;
            document.getElementById('client_name').style.backgroundColor = '#f8fafc';
            document.getElementById('client_name').style.color = '#94a3b8';
        } else {
            document.getElementById('client_tbc').checked = false;
            document.getElementById('client_name').style.backgroundColor = '#fff';
            document.getElementById('client_name').style.color = '#1e293b';
        }
        
        onPlantSelected(true); 
        
        setTimeout(() => {
            if (j.category === 'Rock Saw' && parseFloat(j.setup_fee) > 0) {
                document.getElementById('setup-fee-container').style.display = 'block';
                document.getElementById('setup_fee_display_amount').innerText = '€' + parseFloat(j.setup_fee).toFixed(2);
                document.getElementById('apply_setup_fee').checked = (j.apply_setup_fee == 1);
            }
        }, 100);

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
        document.querySelectorAll('#createBookingForm .input-heavy').forEach(el => el.style.borderColor = '#e2e8f0');
        document.getElementById('map').style.borderColor = '#e2e8f0';

        let isValid = true; let firstError = null;

        function showError(elementId) {
            const el = document.getElementById(elementId);
            if (el) { el.style.borderColor = '#ef4444'; isValid = false; if (!firstError) firstError = el; }
        }

        if (!document.getElementById('plant_category').value) showError('plant_category');
        if (!document.getElementById('plant_id').value) showError('plant_id');
        if (!document.getElementById('booking_date').value) showError('booking_date');
        if (!document.getElementById('start_time').value) showError('start_time');
        if (!document.getElementById('end_time').value) showError('end_time');

        const bType = document.getElementById('booking_type').value;
        if (bType === 'in-house' && !document.getElementById('project_id').value) { showError('project_search'); }
        
        // STRICT FIX: Always require ERP client for both internal and external jobs to prevent "No Client"
        if (!document.getElementById('client_code').value) { showError('client_name'); }

        if (!document.getElementById('loc_lat').value || !document.getElementById('loc_lng').value) { showError('map'); }

        if (!isValid) {
            alert("Please fill out all highlighted fields and ensure a location is pinned on the map.");
            if (firstError) { firstError.scrollIntoView({ behavior: 'smooth', block: 'center' }); if(firstError.focus) firstError.focus(); }
            return; 
        }

        const btn = document.getElementById('submit_booking_btn'); 
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        
        const editId = document.getElementById('edit_booking_id').value;
        const applySetup = document.getElementById('apply_setup_fee').checked ? 1 : 0;
        
        const fd = new FormData(); 
        fd.append('action', editId ? 'update_booking' : 'create_booking'); 
        if (editId) fd.append('edit_id', editId);
        
        fd.append('plant_id', document.getElementById('plant_id').value); 
        fd.append('driver_id', document.getElementById('driver_id').value);
        fd.append('booking_type', document.getElementById('booking_type').value); 
        fd.append('project_id', document.getElementById('project_id').value);
        fd.append('client_name', document.getElementById('client_name').value); 
        fd.append('client_code', document.getElementById('client_code').value);
        fd.append('loc_lat', document.getElementById('loc_lat').value); 
        fd.append('loc_lng', document.getElementById('loc_lng').value);
        fd.append('comments', document.getElementById('booking_comments').value); 
        fd.append('booking_date', document.getElementById('booking_date').value);
        fd.append('start_time', document.getElementById('start_time').value); 
        fd.append('end_time', document.getElementById('end_time').value);
        fd.append('apply_setup_fee', applySetup);

        fetch('api/plant_actions.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(res => {
            if (res === 'OK') { 
                alert("Saved!"); calendar.refetchEvents(); showView('view-calendar'); 
            } else if (res === 'ERROR_OVERLAP') {
                alert("Cannot save: The assigned driver already has another job scheduled during these hours.");
            } else { 
                alert("Error: " + res); 
            }
            btn.disabled = false; btn.innerHTML = editId ? '<i class="fas fa-save"></i> Update Booking' : '<i class="fas fa-check"></i> Save Booking';
        });
    }

    function loadFleetNominals(companyId) {
        if(!companyId) {
            document.getElementById('new_nom_fixed').innerHTML = '<option value="">-- Select Company First --</option>';
            document.getElementById('new_nom_var').innerHTML = '<option value="">-- Select Company First --</option>';
            document.getElementById('new_nom_setup').innerHTML = '<option value="">-- Select Company First --</option>';
            window.erpNominals = []; return;
        }
        
        document.getElementById('new_nom_fixed').innerHTML = '<option value="">Loading ERP...</option>';
        document.getElementById('new_nom_var').innerHTML = '<option value="">Loading ERP...</option>';
        document.getElementById('new_nom_setup').innerHTML = '<option value="">Loading ERP...</option>';
    
        fetch(`api/plant_actions.php?action=get_nominals&company_id=${companyId}`)
        .then(r => r.json())
        .then(res => {
            window.erpNominals = res;
            const opts = '<option value="">-- Select Nominal Code --</option>' + res.map(n => `<option value="${n.NCCode.trim()}" data-in="${n.NCDefSP1}" data-ext="${n.NCDefSP2}">${n.NCCode.trim()} - ${n.NCDesc.trim()}</option>`).join('');
            
            document.getElementById('new_nom_fixed').innerHTML = opts;
            document.getElementById('new_nom_var').innerHTML = opts;
            document.getElementById('new_nom_setup').innerHTML = opts;
            
            // Auto-update any configuration builder dropdowns
            document.querySelectorAll('.cfg-nom').forEach(sel => {
                const currentVal = sel.value;
                sel.innerHTML = opts;
                sel.value = currentVal;
            });
            
            updateRatesDisplay();
        });
    }

    function togglePricingModel() {
        const type = document.getElementById('new_plant_pricing').value;
        const minBox = document.getElementById('min_hrs_box');
        const minInput = document.getElementById('new_plant_min_hrs');
        const varNomBox = document.getElementById('box_nom_var');
        const varNomInput = document.getElementById('new_nom_var');
        const lblFixed = document.getElementById('lbl_nom_fixed');

        if (type === 'fixed_then_hourly') {
            minBox.style.display = 'block'; minInput.min = 1; minInput.value = Math.max(1, minInput.value);
            varNomBox.style.display = 'block'; varNomInput.required = true; lblFixed.innerText = "Fixed Nominal Code *";
        } else {
            // This handles 'hourly', 'per_trip', and 'daily'
            minBox.style.display = 'none'; minInput.value = 0;
            varNomBox.style.display = 'none'; varNomInput.required = false; varNomInput.value = ''; 
            lblFixed.innerText = "Nominal Code *";
        }
        updateRatesDisplay();
    }

    function updateRatesDisplay() {
        const type = document.getElementById('new_plant_pricing').value;
        const fixSel = document.getElementById('new_nom_fixed');
        const varSel = document.getElementById('new_nom_var');
        
        let html = '';
        if(fixSel.selectedIndex > 0) {
            const o1 = fixSel.options[fixSel.selectedIndex];
            html += `<div style="display:flex; justify-content:space-between; margin-bottom:5px;"><span>In-House (Fixed/Std):</span> <b>€${parseFloat(o1.dataset.in).toFixed(2)}</b></div>`;
            html += `<div style="display:flex; justify-content:space-between;"><span>External (Fixed/Std):</span> <b>€${parseFloat(o1.dataset.ext).toFixed(2)}</b></div>`;
        }
        if(type === 'fixed_then_hourly' && varSel.selectedIndex > 0) {
            const o2 = varSel.options[varSel.selectedIndex];
            html += `<hr style="border:1px dashed #cbd5e1; margin:10px 0;">`;
            html += `<div style="display:flex; justify-content:space-between; margin-bottom:5px;"><span>In-House (Variable):</span> <b>€${parseFloat(o2.dataset.in).toFixed(2)}</b></div>`;
            html += `<div style="display:flex; justify-content:space-between;"><span>External (Variable):</span> <b>€${parseFloat(o2.dataset.ext).toFixed(2)}</b></div>`;
        }
        document.getElementById('rate_display_box').innerHTML = html || '<p style="margin:0; text-align:center;">Select Nominal Codes to view rates.</p>';
    }

    function loadFleetView() {
        if (!canManageFleet) return;
        fetch('api/plant_actions.php?action=get_fleet')
        .then(r => r.json())
        .then(fleet => {
            window.fleetData = fleet;
            document.getElementById('fleet-list').innerHTML = fleet.length === 0 ? '<p>No machinery.</p>' : fleet.map(p => {
                let setupHtml = parseFloat(p.setup_fee) > 0 ? ` | <span style="color:#1d4ed8;">Setup: €${parseFloat(p.setup_fee).toFixed(2)}</span>` : '';
                return `
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:15px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <div style="font-weight:900; font-size:1.2rem;">${p.name} <span style="font-size:0.8rem; background:#e0e7ff; color:#4f46e5; padding:3px 6px; border-radius:4px;">${p.billing_company_name||'Unknown'}</span></div>
                        <div style="color:#64748b; font-size:0.95rem; margin-bottom: 8px;">Cat: <b>${p.category}</b> | Reg: <b>${p.registration_plate||'N/A'}</b></div>
                        <div style="font-size:0.85rem; color:#b45309;">Fixed Nom: ${p.nom_code_fixed||'N/A'} | Var Nom: ${p.nom_code_variable||'N/A'}${setupHtml}</div>
                    </div>
                    <button onclick="editPlant(${p.id})" style="background:#e2e8f0; color:#475569; border:none; padding:8px 12px; border-radius:8px; font-weight:bold; cursor:pointer;"><i class="fas fa-edit"></i> Edit</button>
                </div>`;
            }).join('');
        }); 
        showView('view-fleet');
    }

    function editPlant(id) {
        const p = window.fleetData.find(x => x.id == id);
        if(!p) return;
        
        document.getElementById('fleet-form-title').innerText = "Edit Machinery";
        document.getElementById('edit_plant_id').value = p.id;
        document.getElementById('new_plant_cat').value = p.category;
        
        toggleFleetSetupFee(); 

        document.getElementById('new_plant_comp').value = p.billing_company_id;
        document.getElementById('new_plant_name').value = p.name;
        document.getElementById('new_plant_reg').value = p.registration_plate;
        document.getElementById('new_plant_pricing').value = p.pricing_type;
        document.getElementById('new_plant_min_hrs').value = p.min_hours;
        
        document.getElementById('new_plant_setup_fee').value = parseFloat(p.setup_fee || 0).toFixed(2);

        document.getElementById('new_lifecycle_mode').value = p.lifecycle_type || 'Standard';
        
        document.getElementById('new_has_configs').checked = (p.has_configurations == 1);
        toggleConfigBuilder();
        document.getElementById('config_list').innerHTML = '';
        if(p.has_configurations == 1 && p.configurations) {
            try {
                const cfgs = JSON.parse(p.configurations);
                cfgs.forEach(c => addConfigRow(c));
            } catch(e){}
        }

        loadFleetNominals(p.billing_company_id);
        setTimeout(() => { 
            document.getElementById('new_nom_fixed').value = p.nom_code_fixed || ''; 
            document.getElementById('new_nom_var').value = p.nom_code_variable || ''; 
            document.getElementById('new_nom_setup').value = p.nom_code_setup || ''; 
            togglePricingModel(); 
        }, 300);
        
        const saveBtn = document.getElementById('save_fleet_btn');
        saveBtn.innerHTML = '<i class="fas fa-save"></i> Update Machinery';
        document.getElementById('cancel_edit_btn').style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function resetFleetForm() {
        document.getElementById('fleetForm').reset();
        document.getElementById('fleet-form-title').innerText = "Register Machinery";
        document.getElementById('edit_plant_id').value = '';

        document.getElementById('new_lifecycle_mode').value = 'Standard';
        document.getElementById('new_has_configs').checked = false;
        toggleConfigBuilder();
        document.getElementById('config_list').innerHTML = '';
        
        toggleFleetSetupFee(); 
        togglePricingModel(); 
        
        document.getElementById('save_fleet_btn').innerHTML = '<i class="fas fa-save"></i> Save to Fleet';
        document.getElementById('cancel_edit_btn').style.display = 'none';
    }

    function saveNewPlant() {
        if(!document.getElementById('new_plant_cat').value) return alert("Category is required.");
        if(!document.getElementById('new_plant_name').value) return alert("Plant Name is required.");
        if(!document.getElementById('new_plant_comp').value) return alert("Billing Company is required.");
        
        const pt = document.getElementById('new_plant_pricing').value;
        if(['fixed_then_hourly', 'per_trip'].includes(pt) && !document.getElementById('new_nom_fixed').value) { return alert("Nominal Code is strictly required for this pricing model."); }

        const editId = document.getElementById('edit_plant_id').value;
        const fd = new FormData(); fd.append('action', editId ? 'update_plant' : 'save_plant');
        if (editId) fd.append('edit_plant_id', editId);
        
        fd.append('category', document.getElementById('new_plant_cat').value); 
        fd.append('name', document.getElementById('new_plant_name').value);
        fd.append('reg', document.getElementById('new_plant_reg').value); 
        fd.append('billing_company_id', document.getElementById('new_plant_comp').value); 
        fd.append('pricing_type', pt); 
        fd.append('min_hours', document.getElementById('new_plant_min_hrs').value); 
        fd.append('nom_code_fixed', document.getElementById('new_nom_fixed').value); 
        fd.append('nom_code_variable', document.getElementById('new_nom_var').value); 
        fd.append('setup_fee', document.getElementById('new_plant_setup_fee').value); 
        fd.append('nom_code_setup', document.getElementById('new_nom_setup').value); 

        const opMode = document.getElementById('new_lifecycle_mode').value;
        const reqDriver = (opMode === 'Auto-Scheduled') ? 0 : 1;
        
        fd.append('requires_driver', reqDriver); 
        fd.append('lifecycle_type', opMode); 
        
        const hasConfigs = document.getElementById('new_has_configs').checked ? 1 : 0;
        fd.append('has_configurations', hasConfigs); 
        if (hasConfigs) { 
            fd.append('configurations', buildConfigJson()); 
        }

        fetch('api/plant_actions.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(res => { 
            if (res === 'OK') { alert(editId ? "Machinery Updated!" : "Machinery Added!"); resetFleetForm(); loadFormData(); loadFleetView(); } 
            else { alert("Error: " + res); }
        });
    }

    function loadJob(id) {
        fetch(`api/plant_actions.php?action=get_job&id=${id}`)
        .then(r => r.json())
        .then(job => {
            window.currentActiveJob = job; 
            document.getElementById('job-title').innerHTML = `<i class="fas fa-truck-pickup text-indigo-500"></i> ${job.plant_name}`;
            
            let statCol = job.status === 'Completed' ? '#10b981' : (job.status === 'In Progress' ? '#f59e0b' : '#6366f1');
            let mapBtn = job.location_lat ? `<a href="https://www.google.com/maps/search/?api=1&query=${job.location_lat},${job.location_lng}" target="_blank" style="display:inline-block; background:#0f172a; color:#fff; padding:8px 15px; border-radius:8px; font-weight:bold; font-size:0.9rem; text-decoration:none; margin-top:12px; margin-bottom:10px;"><i class="fas fa-map-pin"></i> Open Maps</a>` : '';
            let mapPre = job.location_lat ? `<div id="job-preview-map" style="width:100%; height:200px; border-radius:8px; border:1px solid #e2e8f0; margin-top:10px;"></div>` : '';
            let commHtml = job.comments ? `<div style="background:#fef3c7; border:1px solid #fde68a; padding:15px; border-radius:10px; margin-bottom:15px; color:#92400e; font-size:0.95rem;"><b>Notes:</b><br>${job.comments.replace(/\n/g, '<br>')}</div>` : '';

            let driverName = job.driver_first ? `${job.driver_first} ${job.driver_last}` : null;
            let driverHtml = driverName ? `<span style="color:#10b981;">Assigned to ${driverName}</span>` : `<span style="color:#ef4444;">Unassigned</span>`;
            
            let erpStatusHtml = '';
            let isSynced = job.invoice_sysref && job.invoice_sysref !== 'SUCCESS_NO_REF' && job.invoice_sysref !== 'N/A' && job.invoice_sysref !== '';

            if (job.payment_status === 'Invoiced' || job.payment_status === 'Settled') {
                if (!isSynced) {
                    erpStatusHtml = `<div style="background:#fffbeb; color:#b45309; padding:10px; border-radius:8px; margin-bottom:12px; font-weight:bold; border: 1px solid #fde68a;"><i class="fas fa-exclamation-triangle"></i> RFP Finalised - Local Only <div style="font-size:0.85rem; margin-top:4px; font-weight:normal;">Manual ERP Invoice Generation Required.</div></div>`;
                } else {
                    erpStatusHtml = `<div style="background:#ecfdf5; color:#047857; padding:10px; border-radius:8px; margin-bottom:12px; font-weight:bold; border: 1px solid #a7f3d0;"><i class="fas fa-check-circle"></i> Finalized & Synced (Ref: ${job.invoice_sysref})</div>`;
                }
            }
            
            let setupBadgeHtml = job.apply_setup_fee == 1 ? `<span style="background:#dbeafe; color:#1e40af; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold; margin-left:10px;"><i class="fas fa-truck-loading"></i> Setup Fee Active</span>` : '';

            document.getElementById('job-details').innerHTML = `
                ${erpStatusHtml}
                <div style="margin-bottom:12px; font-size: 1.2rem;"><b>Driver:</b> ${driverHtml}</div>
                <div style="margin-bottom:12px;"><b>Date:</b> ${job.booking_date} (${job.start_time.substring(0,5)} - ${job.end_time.substring(0,5)})</div>
                <div style="margin-bottom:12px;"><b>Type:</b> ${job.booking_type.toUpperCase()}</div>
                <div style="margin-bottom:12px;"><b>Status:</b> <span style="color:${statCol}; font-weight:bold;">${job.status}</span> ${setupBadgeHtml}</div>
                ${commHtml}
                <hr style="border: 1px solid #e2e8f0; margin: 20px 0;">
                <div style="background:#f1f5f9; padding:12px; border-radius:8px; font-weight:bold;">${job.location_text}</div>
                ${mapPre}${mapBtn}
            `;

            let controlsHtml = ''; 
            let today = new Date().toISOString().split('T')[0];
            let canInteract = (!isManager && job.booking_date === today) || canManageFleet;
            
            if (!isManager && (!job.driver_id || job.driver_id == 0) && job.status !== 'Completed') {
                controlsHtml += `<button class="btn-heavy btn-blue" onclick="claimJob(${job.id})"><i class="fas fa-hand-paper"></i> Claim Job</button>`;
            }

            if (canInteract) {
                if (job.status === 'Pending' && job.driver_id > 0) {
                    controlsHtml += `<button class="btn-heavy btn-green" onclick="punchJob(${job.id}, 'in')"><i class="fas fa-play"></i> Start Job</button>`;
                } else if (job.status === 'Paused') {
                    controlsHtml += `<button class="btn-heavy btn-green" onclick="punchJob(${job.id}, 'in')"><i class="fas fa-play"></i> Resume Job</button>`;
                } else if (job.status === 'In Progress') {
                    if (job.category === 'Excavator') {
                        controlsHtml += `<button class="btn-heavy btn-blue" onclick="pauseJob(${job.id})"><i class="fas fa-pause"></i> Pause Job (End Day)</button>`;
                    }
                    controlsHtml += `<button class="btn-heavy btn-red" onclick="startPunchOut(${job.id}, '${job.pricing_type}')"><i class="fas fa-stop"></i> Complete Job (Final Signature)</button>`;
                }
            }
            
            if (isManager && job.status === 'Pending') {
                controlsHtml += `<button class="btn-heavy btn-blue" onclick="initiateBookingEdit()"><i class="fas fa-edit"></i> Edit Booking Details</button>`; 
            }
            
            if (isManager && job.status !== 'Completed') { 
                controlsHtml += `<button class="btn-heavy btn-red" onclick="cancelJob(${job.id})"><i class="fas fa-trash-alt"></i> Cancel Booking</button>`; 
            }
            
            if (isManager && job.status === 'Completed') {
                if (job.payment_status === 'Invoiced' || job.payment_status === 'Settled') {
                    if (isAdmin && !isSynced) {
                        controlsHtml += `<button class="btn-heavy btn-gray" onclick="window.open('print_plant_invoice.php?booking_id=${job.id}', '_blank')"><i class="fas fa-file-pdf"></i> View / Edit RFP</button>`;
                    } else {
                        controlsHtml += `<button class="btn-heavy btn-gray" onclick="window.open('print_plant_invoice.php?booking_id=${job.id}', '_blank')"><i class="fas fa-file-pdf"></i> View RFP</button>`;
                    }
                } else {
                    controlsHtml += `<button class="btn-heavy btn-green" onclick="window.open('print_plant_invoice.php?booking_id=${job.id}', '_blank')"><i class="fas fa-file-invoice-dollar"></i> Generate & Sync Invoice</button>`;
                }
            }

            document.getElementById('punch-controls').innerHTML = controlsHtml; showView('view-job');
            
            if (job.location_lat) {
                setTimeout(() => { 
                    const pm = new mapboxgl.Map({ container: 'job-preview-map', style: 'mapbox://styles/mapbox/streets-v12', center: [job.location_lng, job.location_lat], zoom: 13, interactive: false }); 
                    new mapboxgl.Marker({color: '#f43f5e'}).setLngLat([job.location_lng, job.location_lat]).addTo(pm); 
                }, 200);
            }
        });
    }

    function claimJob(id) {
        if (!confirm("Are you sure you want to claim this job?")) return;
        const fd = new FormData(); fd.append('action', 'claim_job'); fd.append('id', id);
        
        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r => r.text()).then(res => {
            if (res === 'OK') { alert("Job claimed successfully!"); loadJob(id); calendar.refetchEvents(); } 
            else if (res === 'ERROR_OVERLAP') { alert("Cannot claim job: You already have another job scheduled during this time."); } 
            else { alert("Error: " + res); }
        });
    }

    function cancelJob(id) {
        if (!confirm("Delete booking?")) return;
        const fd = new FormData(); fd.append('action', 'cancel_booking'); fd.append('id', id);
        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r => r.text()).then(res => { if (res === 'OK') { calendar.refetchEvents(); showView('view-calendar'); } });
    }

    function punchJob(id, direction) {
        if (!confirm("Are you sure you want to " + (direction === 'in' ? "start" : "complete") + " this job?")) return;

        const btn = event.target.closest('button');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        btn.disabled = true;

        // Strictly check if the active user is a driver
        const isDriver = <?= ($_SESSION['role'] === 'plant_driver') ? 'true' : 'false' ?>;

        // If it's a driver clocking IN, request their exact phone location
        if (direction === 'in' && isDriver) {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        // Success: We got the exact coordinates!
                        sendPunchData(id, direction, position.coords.latitude, position.coords.longitude, btn, originalHtml);
                    },
                    function(error) {
                        console.warn("GPS failed or denied. Falling back to standard punch in.");
                        // Fallback: Let them punch in even if GPS fails so they aren't blocked from working
                        sendPunchData(id, direction, null, null, btn, originalHtml);
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 } // Request high-precision GPS
                );
            } else {
                // Browser doesn't support GPS
                sendPunchData(id, direction, null, null, btn, originalHtml);
            }
        } else {
            // Managers, Admins, or clocking OUT bypass the GPS request entirely
            sendPunchData(id, direction, null, null, btn, originalHtml);
        }
    }

    function sendPunchData(id, direction, lat, lng, btn, originalHtml) {
        const fd = new FormData();
        fd.append('action', direction === 'in' ? 'punch_in' : 'punch_out_complete');
        fd.append('id', id);
        
        // Append GPS data if we have it
        if (lat && lng) {
            fd.append('lat', lat);
            fd.append('lng', lng);
        }

        fetch('api/plant_actions.php?id=' + id, { method: 'POST', body: fd })
        .then(r => r.text())
        .then(res => {
            if (res === 'OK') {
                loadJob(id);
                calendar.refetchEvents();
            } else {
                alert("Error: " + res);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }).catch(err => {
            alert("Network error.");
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
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
        
        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r => r.text()).then(res => {
            if (res === 'OK') { alert("Completed!"); calendar.refetchEvents(); showView('view-calendar'); } 
            else { alert("Error: " + res); btn.disabled = false; }
        });
    }

    function loadLedger() {
        if (!canViewLedger) return;

        // Build the query string from the filters
        const qs = new URLSearchParams({
            action: 'get_ledger',
            start: document.getElementById('filter_start')?.value || '',
            end: document.getElementById('filter_end')?.value || '',
            plant_type: document.getElementById('filter_plant_type')?.value || '',
            status: document.getElementById('filter_status')?.value || '',
            payment_status: document.getElementById('filter_payment')?.value || '',
            client: document.getElementById('filter_client')?.value || '',
            project: document.getElementById('filter_project')?.value || '',
            company: document.getElementById('filter_company')?.value || ''
        }).toString();

        document.getElementById('ledger-list').innerHTML = '<p style="text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading ledger...</p>';

        fetch(`api/plant_actions.php?${qs}`)
        .then(r => r.json())
.then(jobs => {
            document.getElementById('ledger-list').innerHTML = jobs.length === 0 ? '<p style="text-align:center; font-weight:bold; color:#ef4444;">No bookings found for these filters.</p>' : jobs.map(j => {
                let badge = '';
                let sysRef = '';
                
                if (j.payment_status === 'Invoiced' || j.payment_status === 'Settled') {
                    if (!j.invoice_sysref || j.invoice_sysref === 'N/A' || j.invoice_sysref === 'SUCCESS_NO_REF') {
                        badge = `<span style="background:#fef08a; color:#854d0e; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">RFP Finalised - Local Only</span>`;
                        sysRef = `<div style="color:#ef4444; font-weight:bold; font-size:0.85rem; margin-top:8px;"><i class="fas fa-exclamation-circle"></i> Manual Invoice Required</div>`;
                    } else {
                        badge = `<span style="background:#ecfdf5; color:#047857; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">Synced & Finalised</span>`;
                        sysRef = `<div style="color:#10b981; font-weight:bold; font-size:0.85rem; margin-top:8px;"><i class="fas fa-check-circle"></i> ERP Ref: ${j.invoice_sysref}</div>`;
                    }
                } else {
                    badge = `<span style="background:#e2e8f0; color:#475569; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">${j.payment_status}</span>`;
                }
                
                let displayClient = j.booking_type === 'in-house' ? j.project_name + ' (' + (j.client_name || 'No ERP Client Selected') + ')' : (j.client_name || 'No ERP Client Selected');
                let setupBadge = (j.apply_setup_fee == 1 || parseFloat(j.final_setup_fee) > 0) ? '<span style="background:#dbeafe; color:#1e40af; padding:2px 6px; border-radius:4px; font-size:0.75rem; margin-left:8px; vertical-align: middle;"><i class="fas fa-truck-loading"></i> Setup Fee</span>' : '';

                // NEW: Value Display
                let valDisplay = '';
                if (parseFloat(j.final_subtotal) > 0) {
                    valDisplay = `<div style="font-weight:900; color:#10b981; font-size:1.3rem; margin-top:8px;">€${parseFloat(j.final_subtotal).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}</div>`;
                } else if (j.status === 'Completed') {
                    valDisplay = `<div style="font-weight:900; color:#f59e0b; font-size:1rem; margin-top:8px;">Pending Pricing</div>`;
                }

                // NEW: Salient Details Bar
                let timeStr = (j.start_time && j.end_time) ? `${j.start_time.substring(0,5)} - ${j.end_time.substring(0,5)}` : 'TBC';
                let actualTimeStr = (j.punch_in_time && j.punch_out_time) ? ` <span style="color:#8b5cf6;">(Actual: ${j.punch_in_time.substring(11,16)} - ${j.punch_out_time.substring(11,16)})</span>` : '';
                let hrsStr = parseFloat(j.final_hours) > 0 ? `<span style="color:#3b82f6; font-weight:800;">${parseFloat(j.final_hours).toFixed(1)} Hrs</span>` : '';
                
                let salientDetails = `
                    <div style="display:flex; flex-wrap:wrap; gap:15px; margin-top:10px; padding-top:10px; border-top:1px dashed #cbd5e1; font-size:0.85rem; color:#475569;">
                        <div><i class="far fa-clock"></i> ${timeStr}${actualTimeStr}</div>
                        ${hrsStr ? `<div><i class="fas fa-stopwatch"></i> ${hrsStr}</div>` : ''}
                        <div><i class="fas fa-building"></i> ${j.billing_company_name || 'Unknown Co.'}</div>
                    </div>
                `;

                // Ledger Button Logic
                let actionButtons = '';
                if (j.status === 'Completed') {
                    if (j.payment_status === 'Invoiced' || j.payment_status === 'Settled') {
                        if (canViewLedger && (!j.invoice_sysref || j.invoice_sysref === 'N/A' || j.invoice_sysref === 'SUCCESS_NO_REF')) {
                            actionButtons = `
                                <button onclick="retryErpSync(${j.id})" class="retry-sync-btn" style="background:#10b981; color:#fff; border:none; padding:8px 12px; border-radius:8px; font-weight:bold; cursor:pointer; flex:1;"><i class="fas fa-sync"></i> Retry ERP Sync</button>
                                <button onclick="window.open('print_plant_invoice.php?booking_id=${j.id}', '_blank')" style="background:#f1f5f9; color:#3b82f6; border:none; padding:8px 12px; border-radius:8px; font-weight:bold; cursor:pointer; flex:1;"><i class="fas fa-edit"></i> Edit Client / RFP</button>
                            `;
                        } else {
                            actionButtons = `<button onclick="window.open('print_plant_invoice.php?booking_id=${j.id}', '_blank')" style="background:#f1f5f9; color:#3b82f6; border:none; padding:8px 12px; border-radius:8px; font-weight:bold; cursor:pointer; flex:1;">View RFP</button>`;
                        }
                    } else {
                        actionButtons = `<button onclick="window.open('print_plant_invoice.php?booking_id=${j.id}', '_blank')" style="background:#3b82f6; color:#fff; border:none; padding:8px 12px; border-radius:8px; font-weight:bold; cursor:pointer; flex:1;"><i class="fas fa-file-invoice-dollar"></i> Finalize RFP & Sync</button>`;
                    }
                } else {
                    actionButtons = `<span style="color:#94a3b8; font-size:0.85rem; padding: 8px 0; display: inline-block;">Pending Completion</span>`;
                }

                return `
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:15px; margin-bottom:15px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div style="flex:1; padding-right:15px;">
                            <div style="font-weight:900; font-size:1.1rem; color:#0f172a; margin-bottom:4px;">${j.billing_company_name && j.billing_company_name.includes('PRAX') ? 'PRAX' : 'PRA'}-${j.booking_date.substring(0,4)}-${String(j.id).padStart(4,'0')} - ${j.plant_name} ${setupBadge}</div>
                            <div style="color:#64748b; font-size:0.9rem;"><b>${j.booking_date}</b> | ${displayClient}</div>
                            ${salientDetails}
                            ${sysRef}
                        </div>
                        <div style="text-align:right; min-width:130px;">
                            ${badge}
                            ${valDisplay}
                        </div>
                    </div>
                    <div style="border-top:1px solid #f1f5f9; padding-top:12px; margin-top:12px; display:flex; gap:10px;">
                        ${actionButtons}
                    </div>
                </div>`;
            }).join('');
        });
        showView('view-ledger');
    }

    function setStepState(stepId, isActive) {
        const step = document.getElementById(stepId);
        if (!step) return;
        
        if (isActive) {
            step.classList.remove('step-disabled');
            step.classList.add('step-active');
            // Enable all inputs inside EXCEPT hidden fields
            step.querySelectorAll('input, select, textarea, button').forEach(el => {
                if (el.type !== 'hidden') el.disabled = false;
            });
            // Fix layout if waking up step 4
            if (stepId === 'seq-step-4') toggleJobType(); 
        } else {
            step.classList.remove('step-active');
            step.classList.add('step-disabled');
            // Disable everything
            step.querySelectorAll('input, select, textarea, button').forEach(el => el.disabled = true);
        }
    }

    function checkStep5() {
        const type = document.getElementById('booking_type').value;
        const pid = document.getElementById('project_id').value;
        const cid = document.getElementById('client_code').value;
        
        if ((type === 'in-house' && pid && cid) || (type === 'external' && cid)) {
            setStepState('seq-step-5', true);
            setTimeout(initMap, 200);
        } else {
            setStepState('seq-step-5', false);
        }
    }

    function toggleTBC() {
        const isTbc = document.getElementById('client_tbc').checked;
        const cInput = document.getElementById('client_name');
        const cCode = document.getElementById('client_code');
        
        if (isTbc) {
            cInput.value = 'Client details TBC';
            cCode.value = 'TBC';
            cInput.disabled = true;
            cInput.style.backgroundColor = '#f8fafc';
            cInput.style.color = '#94a3b8';
            document.getElementById('client_search_results').style.display = 'none';
        } else {
            cInput.value = '';
            cCode.value = '';
            cInput.disabled = false;
            cInput.style.backgroundColor = '#fff';
            cInput.style.color = '#1e293b';
            cInput.focus();
        }
        checkStep5();
    }

    function retryErpSync(bookingId) {
        if (!confirm("Attempt to push this invoice to the ERP again?")) return;
        
        // Disable ALL retry buttons on the page to prevent ERP spamming
        const allRetryBtns = document.querySelectorAll('.retry-sync-btn');
        allRetryBtns.forEach(btn => {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Syncing...';
        });

        const fd = new FormData();
        fd.append('action', 'retry_erp_sync');
        fd.append('booking_id', bookingId);

        fetch('api/plant_actions.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(res => {
            if (res === 'OK') {
                alert("Successfully Synced to ERP!");
                loadLedger(); // Reloads the ledger to show the new SysRef
            } else {
                alert("Sync Failed: " + res);
                // Re-enable buttons if it failed so they can try again later
                allRetryBtns.forEach(btn => {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    btn.innerHTML = '<i class="fas fa-sync"></i> Retry ERP Sync';
                });
            }
        }).catch(err => {
            alert("Network error occurred.");
            loadLedger(); // Reset UI state
        });
    }

    function pauseJob(id) {
        if (!confirm("Pause this job for the day? It will remain active on the calendar and billing clock will stop.")) return;
        
        const btn = event.target.closest('button');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Pausing...';
        btn.disabled = true;

        const fd = new FormData(); fd.append('action', 'pause_job'); fd.append('id', id);
        fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r => r.text()).then(res => { 
            if (res === 'OK') { 
                loadJob(id); 
                calendar.refetchEvents(); 
            } else {
                alert("Error pausing job: " + res);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }).catch(err => {
            alert("Network error occurred.");
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
    }
</script>
</body>
</html>
