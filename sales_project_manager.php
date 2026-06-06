<?php
require_once 'config.php';
require_once 'session-check.php';

// Strict Access Control: Admin & Sales Manager ONLY (or explicit custom permission)
$allowed_roles = ['sales_manager', 'admin'];
if (!in_array($_SESSION['role'], $allowed_roles) && !hasPermission('manage_sales_frames')) {
    header("Location: index.php?error=unauthorized");
    exit;
}

// ---------------------------------------------------------
// AUTO-DEPLOY DATABASE UPDATES (Failsafes)
// ---------------------------------------------------------
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ALTER TABLE sales_properties ADD COLUMN block VARCHAR(50) DEFAULT ''");
    $pdo->exec("ALTER TABLE project_documents ADD COLUMN sort_order INT DEFAULT 0");
} catch(PDOException $e) { /* Ignore if columns exist */ }

// ---------------------------------------------------------
// AJAX ENDPOINTS
// ---------------------------------------------------------
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        if ($action === 'load_project') {
            $pid = (int)$_POST['project_id'];
            
            // Strictly using real DB columns: floor_level and unit_type
            $stmtUnits = $pdo->prepare("
                SELECT * FROM sales_properties 
                WHERE project_id = ? 
                ORDER BY 
                    block ASC, 
                    CAST(floor_level AS SIGNED) ASC, 
                    FIELD(unit_type, 'garage', 'parking space', 'commercial', 'maisonette', 'apartment', 'penthouse', 'villa', 'house'), 
                    unit_name ASC
            ");
            $stmtUnits->execute([$pid]);
            $units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);

            $stmtMedia = $pdo->prepare("SELECT * FROM project_documents WHERE project_id = ? AND category = 'Sales' ORDER BY sort_order ASC, id ASC");
            $stmtMedia->execute([$pid]);
            $mediaRaw = $stmtMedia->fetchAll(PDO::FETCH_ASSOC);

            // --- FIX: CLOUDFLARE/S3 PRESIGNED URL GENERATOR ---
            $media = [];
            $s3Loaded = false;
            $s3 = null;

            foreach ($mediaRaw as $m) {
                $path = $m['file_path'];
                
                // If the path is an internal S3/Cloudflare key (doesn't start with http)
                if (!empty($path) && strpos($path, 'http') === false) {
                    if (!$s3Loaded) {
                        require_once 'S3FileManager.php';
                        $s3 = new S3FileManager();
                        $s3Loaded = true;
                    }
                    try {
                        // Generate a secure viewing URL valid for 2 hours
                        $m['file_path'] = $s3->getPresignedUrl($path, '+120 minutes');
                    } catch (Exception $e) {
                        // Fallback to original path if Cloudflare fails
                    }
                }
                $media[] = $m;
            }

            echo json_encode(['success' => true, 'units' => $units, 'media' => $media]);
            exit;
        }

        if ($action === 'save_frame') {
            $pid = (int)$_POST['project_id'];
            $unitsData = json_decode($_POST['units'], true);
            $userId = $_SESSION['user_id'];
            
            $pdo->beginTransaction();
            $stmtUpdate = $pdo->prepare("UPDATE sales_properties SET unit_name=?, block=?, floor_level=?, unit_type=?, description=?, internal_sqm=?, external_sqm=?, shell_price=?, finishes_price=? WHERE id=? AND project_id=?");
            $stmtInsert = $pdo->prepare("INSERT INTO sales_properties (project_id, unit_name, block, floor_level, unit_type, description, internal_sqm, external_sqm, shell_price, finishes_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available')");
            
            // Log pre-statements
            $stmtGetOld = $pdo->prepare("SELECT * FROM sales_properties WHERE id = ?");
            $stmtLog = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
            
            foreach ($unitsData as $u) {
                if (!empty($u['id']) && $u['id'] > 0) {
                    // Fetch old state before making the update
                    $stmtGetOld->execute([$u['id']]);
                    $oldUnit = $stmtGetOld->fetch(PDO::FETCH_ASSOC);

                    $stmtUpdate->execute([
                        $u['unit_name'], $u['block'], $u['floor_level'], $u['unit_type'], $u['description'], 
                        (float)$u['internal_sqm'], (float)$u['external_sqm'], (float)$u['shell_price'], (float)$u['finishes_price'], 
                        $u['id'], $pid
                    ]);

                    // Smart Log Comparison
                    if ($oldUnit) {
                        $changes = [];
                        if ((float)$oldUnit['shell_price'] != (float)$u['shell_price']) $changes[] = "Shell: {$oldUnit['shell_price']} -> {$u['shell_price']}";
                        if ((float)$oldUnit['finishes_price'] != (float)$u['finishes_price']) $changes[] = "Finishes: {$oldUnit['finishes_price']} -> {$u['finishes_price']}";
                        if ($oldUnit['floor_level'] != $u['floor_level']) $changes[] = "Floor: {$oldUnit['floor_level']} -> {$u['floor_level']}";
                        if ($oldUnit['unit_name'] != $u['unit_name']) $changes[] = "Name: {$oldUnit['unit_name']} -> {$u['unit_name']}";
                        if ($oldUnit['block'] != $u['block']) $changes[] = "Block: {$oldUnit['block']} -> {$u['block']}";
                        if ((float)$oldUnit['internal_sqm'] != (float)$u['internal_sqm']) $changes[] = "Int SQM: {$oldUnit['internal_sqm']} -> {$u['internal_sqm']}";
                        
                        if (!empty($changes)) {
                            $justification = "Bulk Edit: " . implode(', ', $changes);
                            $stmtLog->execute([
                                $u['id'], 
                                $userId, 
                                'Project Manager Edit', 
                                $oldUnit['status'], 
                                $oldUnit['status'], 
                                substr($justification, 0, 255) // Ensure it fits in the db column
                            ]);
                        }
                    }
                } else {
                    if (trim($u['unit_name']) !== '') {
                        $stmtInsert->execute([
                            $pid, $u['unit_name'], $u['block'], $u['floor_level'], $u['unit_type'], $u['description'], 
                            (float)$u['internal_sqm'], (float)$u['external_sqm'], (float)$u['shell_price'], (float)$u['finishes_price']
                        ]);
                        
                        $newId = $pdo->lastInsertId();
                        $stmtLog->execute([
                            $newId, 
                            $userId, 
                            'Unit Created', 
                            'New', 
                            'Available', 
                            'Unit added via Project Manager frame editor'
                        ]);
                    }
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'delete_unit') {
            $pdo->prepare("DELETE FROM sales_properties WHERE id = ?")->execute([(int)$_POST['unit_id']]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'delete_media') {
            $pdo->prepare("DELETE FROM project_documents WHERE id = ?")->execute([(int)$_POST['media_id']]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'update_media_order') {
            $sortData = json_decode($_POST['sort_data'], true);
            $stmt = $pdo->prepare("UPDATE project_documents SET sort_order = ? WHERE id = ?");
            foreach ($sortData as $item) {
                $stmt->execute([(int)$item['order'], (int)$item['id']]);
            }
            echo json_encode(['success' => true]);
            exit;
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$pageTitle = 'Sales Project Manager';
require_once 'header.php';

$projects = $pdo->query("SELECT id, name FROM projects ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    /* Dark Mode Theme aligned with Sales Hub */
    :root {
        --pm-bg-base: #0f172a;
        --pm-bg-panel: #1e293b;
        --pm-border: #334155;
        --pm-text-main: #f8fafc;
        --pm-text-muted: #94a3b8;
        --pm-accent: #3b82f6;
    }

    /* Wrap the entire page in the dark theme */
    .manager-wrapper { 
        background-color: var(--pm-bg-base); 
        min-height: 100vh; 
        padding-top: 20px; 
        padding-bottom: 50px;
    }

    .manager-container { 
        max-width: 1500px; 
        margin: 0 auto; 
        padding: 0 20px; 
        font-family: 'Inter', sans-serif; 
        color: var(--pm-text-main); 
    }
    
    /* Strict Overrides to kill global CSS conflicts */
    .manager-container input, .manager-container select { 
        background-color: var(--pm-bg-base) !important; 
        color: var(--pm-text-main) !important; 
        border: 1px solid var(--pm-border) !important; 
    }

    .header-bar { 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        background: var(--pm-bg-panel); 
        padding: 20px; 
        border-radius: 12px; 
        box-shadow: 0 10px 30px rgba(0,0,0,0.5); 
        margin-bottom: 20px; 
        border: 1px solid var(--pm-border); 
    }
    .header-bar h2 { color: var(--pm-text-main) !important; }
    .header-bar select { padding: 10px 15px; border-radius: 8px; font-size: 1rem; width: 300px; outline: none; }
    
    .tabs { display: flex; gap: 10px; margin-bottom: 20px; }
    .tab { padding: 12px 25px; background: var(--pm-bg-base); color: var(--pm-text-muted); border-radius: 8px; cursor: pointer; font-weight: 700; transition: 0.2s; border: 1px solid var(--pm-border); }
    .tab.active { background: rgba(59, 130, 246, 0.1); color: var(--pm-accent); border-color: var(--pm-accent); }
    
    .tab-content { display: none; background: var(--pm-bg-panel); padding: 25px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid var(--pm-border); }
    .tab-content.active { display: block; }
    .tab-content h4 { color: var(--pm-text-main); }

    /* Frame Editor Table */
    .frame-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .frame-table th { background: var(--pm-bg-base); padding: 12px; text-align: left; color: var(--pm-text-muted); font-weight: 800; border-bottom: 2px solid var(--pm-border); }
    .frame-table td { padding: 8px; border-bottom: 1px solid var(--pm-border); }
    
    .frame-input { width: 100%; padding: 8px; border-radius: 6px; outline: none; font-size: 0.85rem; }
    .frame-input:focus { border-color: var(--pm-accent) !important; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
    .frame-select { width: 100%; padding: 8px; border-radius: 6px; outline: none; font-size: 0.85rem; }

    /* Buttons */
    .btn-heavy { padding: 10px 20px; border-radius: 8px; font-weight: 700; border: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
    .btn-blue { background: rgba(59, 130, 246, 0.1); color: var(--pm-accent); border: 1px solid rgba(59, 130, 246, 0.3); } .btn-blue:hover { background: var(--pm-accent); color:#fff; }
    .btn-green { background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); } .btn-green:hover { background: #10b981; color:#fff; }
    .btn-red { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); } .btn-red:hover { background: #ef4444; color:#fff; }
    .btn-gray { background: var(--pm-bg-base); color: var(--pm-text-muted); border: 1px solid var(--pm-border); } .btn-gray:hover { background: var(--pm-border); color: #fff;}

    /* Media Grid */
    .media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
    .media-card { border: 1px solid var(--pm-border); border-radius: 12px; padding: 10px; background: var(--pm-bg-base); text-align: center; position: relative; }
    .media-card img, .media-card video { width: 100%; height: 140px; object-fit: cover; border-radius: 8px; margin-bottom: 10px; background: var(--pm-bg-panel); }
    .media-title { font-size: 0.8rem; font-weight: 700; color: var(--pm-text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 5px; }
    .media-order { width: 60px; padding: 4px; text-align: center; border-radius: 4px; margin-bottom: 10px; }
    
    .loader { display: none; text-align: center; padding: 40px; color: var(--pm-accent); font-size: 1.2rem; font-weight: bold; }
</style>

<div class="manager-wrapper">
    <div class="manager-container">
        <div class="header-bar">
            <div>
                <a href="sales_hub.php" style="color: var(--pm-text-muted); text-decoration: none; font-size: 0.9rem; font-weight: bold;">&larr; Back to Sales Hub</a>
                <h2 style="margin: 5px 0 0 0; font-weight: 900;"><i class="fas fa-tools text-blue-500"></i> Project Frame & Media Manager</h2>
                <p style="margin: 5px 0 0 0; color: var(--pm-text-muted); font-size: 0.9rem;">Surgically edit project frames, adjust properties, and organize media.</p>
            </div>
            <div>
                <label style="font-weight: 800; color: var(--pm-text-muted); margin-right: 10px;">Select Project:</label>
                <select id="projectSelect" onchange="loadProjectData()">
                    <option value="">-- Choose a Project --</option>
                    <?php foreach($projects as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="workspace" style="display: none;">
            <div class="tabs">
                <div class="tab active" onclick="switchTab('frameTab', this)"><i class="fas fa-table"></i> Live Frame Editor</div>
                <div class="tab" onclick="switchTab('mediaTab', this)"><i class="fas fa-images"></i> Media & Renders</div>
                <div class="tab" onclick="switchTab('floorTab', this)"><i class="fas fa-map"></i> Floor Plans</div>
            </div>

            <div id="loader" class="loader"><i class="fas fa-spinner fa-spin"></i> Loading Project Data...</div>

            <div id="frameTab" class="tab-content active">
                <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                    <button class="btn-heavy btn-gray" onclick="addNewRow()"><i class="fas fa-plus"></i> Add New Unit</button>
                    <button class="btn-heavy btn-green" onclick="saveFrame()"><i class="fas fa-save"></i> Save All Frame Changes</button>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="frame-table" id="frameTable">
                        <thead>
                            <tr>
                                <th style="min-width: 120px;">Unit Name</th>
                                <th style="width: 70px;">Block</th>
                                <th style="width: 70px;">Level</th>
                                <th style="width: 140px;">Property Type</th>
                                <th style="min-width: 160px;">Description</th>
                                <th style="width: 90px;">Int SQM</th>
                                <th style="width: 90px;">Ext SQM</th>
                                <th style="width: 120px;">Shell Price (€)</th>
                                <th style="width: 120px;">Finishes (€)</th>
                                <th style="width: 60px; text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="frameBody">
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="mediaTab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h4 style="margin: 0;">Sales Gallery Media</h4>
                    <button class="btn-heavy btn-blue" onclick="saveMediaOrder()"><i class="fas fa-sort-numeric-down"></i> Save Display Order</button>
                </div>
                <div class="media-grid" id="mediaGrid"></div>
            </div>

            <div id="floorTab" class="tab-content">
                <h4 style="margin: 0 0 20px 0;">Attached Floor Plans</h4>
                <div id="floorPlanList"></div>
            </div>
        </div>
    </div>
</div>

<script>
    // Enum mapping matching your database strictly
    const unitTypes = [
        { val: 'apartment', label: 'Apartment' },
        { val: 'penthouse', label: 'Penthouse' },
        { val: 'maisonette', label: 'Maisonette' },
        { val: 'house', label: 'House' },
        { val: 'villa', label: 'Villa' },
        { val: 'commercial', label: 'Commercial' },
        { val: 'garage', label: 'Garage' },
        { val: 'parking space', label: 'Parking Space' }
    ];

    let currentUnits = [];
    let currentMedia = [];

    function switchTab(tabId, el) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        el.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    }

    function loadProjectData() {
        const pid = document.getElementById('projectSelect').value;
        if (!pid) { document.getElementById('workspace').style.display = 'none'; return; }

        document.getElementById('workspace').style.display = 'block';
        document.getElementById('loader').style.display = 'block';
        document.getElementById('frameTab').style.opacity = '0.3';
        document.getElementById('mediaTab').style.opacity = '0.3';

        const fd = new FormData();
        fd.append('action', 'load_project');
        fd.append('project_id', pid);

        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            currentUnits = data.units || [];
            currentMedia = data.media || [];
            renderFrameTable();
            renderMediaManagers();
            document.getElementById('loader').style.display = 'none';
            document.getElementById('frameTab').style.opacity = '1';
            document.getElementById('mediaTab').style.opacity = '1';
        });
    }

    function renderFrameTable() {
        const tbody = document.getElementById('frameBody');
        tbody.innerHTML = '';
        currentUnits.forEach((u, index) => { tbody.appendChild(createRow(u, index)); });
        if(currentUnits.length === 0) addNewRow();
    }

    function createRow(u, index) {
        const tr = document.createElement('tr');
        tr.setAttribute('data-id', u.id || 0);
        
        let typeOpts = unitTypes.map(t => `<option value="${t.val}" ${u.unit_type === t.val ? 'selected' : ''}>${t.label}</option>`).join('');

        tr.innerHTML = `
            <td><input type="text" class="frame-input inp-name" value="${u.unit_name || ''}" placeholder="Apt 1"></td>
            <td><input type="text" class="frame-input inp-block" value="${u.block || ''}" placeholder="A"></td>
            <td><input type="text" class="frame-input inp-level" value="${u.floor_level || ''}" placeholder="1"></td>
            <td><select class="frame-select inp-type">${typeOpts}</select></td>
            <td><input type="text" class="frame-input inp-desc" value="${u.description || ''}" placeholder="e.g. 1 BED & STUDY"></td>
            <td><input type="number" step="0.01" class="frame-input inp-int" value="${u.internal_sqm || 0}"></td>
            <td><input type="number" step="0.01" class="frame-input inp-ext" value="${u.external_sqm || 0}"></td>
            <td><input type="number" step="0.01" class="frame-input inp-shell" value="${u.shell_price || 0}"></td>
            <td><input type="number" step="0.01" class="frame-input inp-fin" value="${u.finishes_price || 0}"></td>
            <td style="text-align:center;">
                <button class="btn-heavy btn-red" style="padding: 6px 10px;" onclick="deleteUnit(${u.id || 0}, this)"><i class="fas fa-trash"></i></button>
            </td>
        `;
        return tr;
    }

    function addNewRow() { 
        document.getElementById('frameBody').appendChild(createRow({}, currentUnits.length)); 
    }

    function deleteUnit(id, btnEl) {
        if (!confirm("Are you sure you want to delete this unit? This will permanently remove it from the Sales Hub.")) return;
        if (id === 0) { btnEl.closest('tr').remove(); return; }

        const fd = new FormData(); 
        fd.append('action', 'delete_unit'); 
        fd.append('unit_id', id);
        
        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if(data.success) { 
                btnEl.closest('tr').remove(); 
            } else { 
                alert("Error deleting unit."); 
            }
        });
    }

    function saveFrame() {
        const pid = document.getElementById('projectSelect').value;
        const rows = document.querySelectorAll('#frameBody tr');
        let payload = [];

        rows.forEach(row => {
            payload.push({
                id: row.getAttribute('data-id'),
                unit_name: row.querySelector('.inp-name').value,
                block: row.querySelector('.inp-block').value,
                floor_level: row.querySelector('.inp-level').value,
                unit_type: row.querySelector('.inp-type').value,
                description: row.querySelector('.inp-desc').value,
                internal_sqm: row.querySelector('.inp-int').value,
                external_sqm: row.querySelector('.inp-ext').value,
                shell_price: row.querySelector('.inp-shell').value,
                finishes_price: row.querySelector('.inp-fin').value,
            });
        });

        const btn = event.target; 
        const ogText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; 
        btn.disabled = true;

        const fd = new FormData(); 
        fd.append('action', 'save_frame'); 
        fd.append('project_id', pid); 
        fd.append('units', JSON.stringify(payload));

        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.innerHTML = ogText; btn.disabled = false;
            if(data.success) { 
                alert("Frame updated successfully!"); 
                loadProjectData(); 
            } else { 
                alert("Error saving frame: " + data.message); 
            }
        });
    }

    function renderMediaManagers() {
        const mediaGrid = document.getElementById('mediaGrid');
        const floorList = document.getElementById('floorPlanList');
        
        mediaGrid.innerHTML = '';
        let floorHtml = '';
        let hasFloors = false;

        currentMedia.forEach(m => {
            if (m.sub_category.includes('Floor Plan')) {
                hasFloors = true;
                let lvl = m.title.replace('Floor Plan - ', '');
                floorHtml += `
                    <div style="display:flex; justify-content:space-between; align-items:center; background:var(--pm-bg-base); padding:15px; border:1px solid var(--pm-border); border-radius:8px; margin-bottom:10px;">
                        <div>
                            <strong style="color: var(--pm-text-main);"><i class="fas fa-layer-group text-blue-500"></i> Level: ${lvl}</strong>
                            <div style="font-size:0.8rem; color:var(--pm-text-muted); margin-top:4px;">File: ${m.file_path.split('/').pop()}</div>
                        </div>
                        <div>
                            <a href="${m.file_path}" target="_blank" class="btn-heavy btn-blue" style="padding:6px 12px; font-size:0.85rem;"><i class="fas fa-eye"></i> View</a>
                            <button class="btn-heavy btn-red" style="padding:6px 12px; font-size:0.85rem;" onclick="deleteMedia(${m.id})"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>`;
            } else if (m.sub_category.includes('Render') || m.sub_category.includes('Video')) {
                let mediaEl = m.sub_category.includes('Video') ? `<video src="${m.file_path}" controls></video>` : `<img src="${m.file_path}">`;
                mediaGrid.innerHTML += `
                    <div class="media-card" data-id="${m.id}">
                        ${mediaEl}
                        <div class="media-title">${m.sub_category}</div>
                        <label style="font-size:0.7rem; color:var(--pm-text-muted);">Display Order:</label><br>
                        <input type="number" class="media-order inp-sort" value="${m.sort_order || 0}">
                        <br>
                        <button class="btn-heavy btn-red" style="width:100%; padding:6px; font-size:0.8rem;" onclick="deleteMedia(${m.id})"><i class="fas fa-trash"></i> Delete</button>
                    </div>`;
            }
        });

        if (!hasFloors) {
            floorHtml = '<div style="padding:20px; text-align:center; color:var(--pm-text-muted); background:var(--pm-bg-base); border-radius:8px; border:1px solid var(--pm-border);">No floor plans uploaded for this project yet. Use the Sales Hub Media Uploader to add them.</div>';
        }
        floorList.innerHTML = floorHtml;
        
        if (mediaGrid.innerHTML === '') {
            mediaGrid.innerHTML = '<div style="grid-column: 1/-1; padding:20px; text-align:center; color:var(--pm-text-muted); background:var(--pm-bg-base); border-radius:8px; border:1px solid var(--pm-border);">No visual media uploaded yet. Use the Sales Hub Media Uploader to add renders and videos.</div>';
        }
    }

    function deleteMedia(id) {
        if(!confirm("Are you sure you want to permanently delete this file?")) return;
        const fd = new FormData(); 
        fd.append('action', 'delete_media'); 
        fd.append('media_id', id);
        
        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { 
            if(data.success) loadProjectData(); 
        });
    }

    function saveMediaOrder() {
        let sortData = [];
        document.querySelectorAll('.media-card').forEach(card => { 
            sortData.push({ 
                id: card.getAttribute('data-id'), 
                order: card.querySelector('.inp-sort').value 
            }); 
        });

        const btn = event.target; 
        const og = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; 
        btn.disabled = true;

        const fd = new FormData(); 
        fd.append('action', 'update_media_order'); 
        fd.append('sort_data', JSON.stringify(sortData));
        
        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.innerHTML = og; btn.disabled = false;
            if(data.success) { 
                alert("Display order saved successfully!"); 
                loadProjectData(); 
            }
        });
    }
</script>

<?php require_once 'footer.php'; ?>
