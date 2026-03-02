<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('view_ohsa') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = ''; $error = '';
$canEditOHSA = hasPermission('assign_actions') || isAdmin(); // Assuming OHSA reps have action assignment rights

// ==========================================
// HANDLE POST REQUESTS (Save OHSA Data)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_ohsa' && $canEditOHSA) {
    try {
        $pdo->beginTransaction();
        $pId = $_POST['project_id'];
        
        // 1. Save High-Level Status & Comments
        $stmtStatus = $pdo->prepare("
            INSERT INTO project_ohsa_setup (project_id, safety_status, safety_comments) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE safety_status=VALUES(safety_status), safety_comments=VALUES(safety_comments)
        ");
        $stmtStatus->execute([$pId, $_POST['safety_status'] ?? 'N/A', $_POST['safety_comments'] ?? '']);

        // 2. Save Dynamic Equipment List
        // First, clear existing equipment for this project
        $pdo->prepare("DELETE FROM project_ohsa_equipment WHERE project_id = ?")->execute([$pId]);
        
        // Then, insert the newly submitted list
        if (isset($_POST['eq_name']) && is_array($_POST['eq_name'])) {
            $stmtEq = $pdo->prepare("INSERT INTO project_ohsa_equipment (project_id, equipment_name, details, is_certified, expiry_date) VALUES (?, ?, ?, ?, ?)");
            for ($i = 0; $i < count($_POST['eq_name']); $i++) {
                $eqName = trim($_POST['eq_name'][$i]);
                if (!empty($eqName)) {
                    $expiry = !empty($_POST['eq_expiry'][$i]) ? $_POST['eq_expiry'][$i] : null;
                    $stmtEq->execute([
                        $pId, 
                        $eqName, 
                        trim($_POST['eq_details'][$i] ?? ''), 
                        $_POST['eq_cert'][$i] ?? 'N/A', 
                        $expiry
                    ]);
                }
            }
        }

        $pdo->commit();
        $message = "OHSA safety details updated successfully!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error updating OHSA data: " . $e->getMessage();
    }
}

// ==========================================
// FETCH DATA
// ==========================================
$projectsRaw = getAccessibleProjects($pdo, getCurrentUserId());
$projectIds = array_column($projectsRaw, 'id');

$ohsaSetups = [];
$ohsaEquipment = [];

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    
    // Fetch Statuses
    $statusStmt = $pdo->prepare("SELECT * FROM project_ohsa_setup WHERE project_id IN ($placeholders)");
    $statusStmt->execute($projectIds);
    foreach ($statusStmt->fetchAll() as $row) { $ohsaSetups[$row['project_id']] = $row; }
    
    // Fetch Equipment
    $eqStmt = $pdo->prepare("SELECT * FROM project_ohsa_equipment WHERE project_id IN ($placeholders) ORDER BY id ASC");
    $eqStmt->execute($projectIds);
    foreach ($eqStmt->fetchAll() as $row) { $ohsaEquipment[$row['project_id']][] = $row; }
}

// 3. Filter for active execution stages (Demolition through Handed Over)
$ohsaProjects = [];
$ohsaStages = ['Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over'];

foreach ($projectsRaw as $p) {
    $stage = deriveProjectStage($pdo, $p['id']);
    
    if (in_array($stage, $ohsaStages)) {
        $p['stage'] = $stage;
        $p['safety_status'] = $ohsaSetups[$p['id']]['safety_status'] ?? 'N/A';
        $p['safety_comments'] = $ohsaSetups[$p['id']]['safety_comments'] ?? '';
        
        $p['equipment'] = $ohsaEquipment[$p['id']] ?? [];
        
        // Calculate Equipment Warnings
        $expiredCount = 0;
        $uncertifiedCount = 0;
        $today = new DateTime();
        
        foreach ($p['equipment'] as $eq) {
            if ($eq['is_certified'] === 'No') $uncertifiedCount++;
            if (!empty($eq['expiry_date'])) {
                $expDate = new DateTime($eq['expiry_date']);
                if ($expDate < $today) $expiredCount++;
            }
        }
        $p['expired_count'] = $expiredCount;
        $p['uncertified_count'] = $uncertifiedCount;
        
        $ohsaProjects[] = $p;
    }
}

function renderSafetyBadge($status) {
    $colors = [
        'Green' => 'background: rgba(34, 197, 94, 0.15); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.5);',
        'Yellow' => 'background: rgba(234, 179, 8, 0.15); color: #eab308; border: 1px solid rgba(234, 179, 8, 0.5);',
        'Red' => 'background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.5);',
        'N/A' => 'background: rgba(107, 114, 128, 0.1); color: #9ca3af; border: 1px solid #4b5563;'
    ];
    $style = $colors[$status] ?? $colors['N/A'];
    $icon = $status === 'Red' ? '⚠️ ' : ($status === 'Green' ? '✅ ' : '');
    return "<span style='padding: 0.4rem 0.8rem; border-radius: 20px; font-size: 0.8rem; font-weight: 700; white-space: nowrap; $style'>$icon" . strtoupper($status) . "</span>";
}

$pageTitle = 'OHSA Safety Matrix';
require_once 'header.php';
?>

<style>
.matrix-table-container { overflow-x: auto; background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid var(--border-glass); }
.matrix-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem; }
.matrix-table th { background: rgba(255,255,255,0.02); padding: 1rem; font-weight: 600; color: var(--text-primary); border-bottom: 1px solid var(--border-glass); white-space: nowrap; }
.matrix-table td { padding: 1rem; border-bottom: 1px solid var(--border-glass); vertical-align: middle; color: var(--text-secondary); }
.matrix-table tr:hover { background: rgba(255,255,255,0.02); }

/* Warning Pills */
.warning-pill { display: inline-block; padding: 0.2rem 0.5rem; background: rgba(239, 68, 68, 0.15); color: #ef4444; border-radius: 4px; font-size: 0.75rem; font-weight: 600; margin-top: 0.25rem; border: 1px solid rgba(239, 68, 68, 0.3); }

/* Modal Styles */
.modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 2% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 95%; max-width: 900px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }

.eq-row { display: grid; grid-template-columns: 2fr 3fr 1fr 1.5fr auto; gap: 0.5rem; margin-bottom: 0.75rem; align-items: start; background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 6px; border: 1px solid var(--border-glass); }
.eq-row input, .eq-row select { width: 100%; padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary); font-size: 0.85rem; }
</style>

<div class="main-container" style="max-width: 1400px;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">OHSA Safety Matrix</h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">Monitor safety statuses, comments, and equipment certifications.</p>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="matrix-table-container">
        <table class="matrix-table">
            <thead>
                <tr>
                    <th>Project Name</th>
                    <th>Stage</th>
                    <th style="border-left: 2px solid var(--border-glass); text-align: center;">Safety Status</th>
                    <th style="width: 30%;">Safety Comments</th>
                    <th style="border-left: 2px solid var(--border-glass);">Tracked Equipment</th>
                    <?php if ($canEditOHSA): ?><th style="text-align: right;">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($ohsaProjects)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 2rem;">No active projects in execution stages found.</td></tr>
                <?php else: ?>
                    <?php foreach($ohsaProjects as $p): ?>
                        <tr>
                            <td style="font-weight: 700; color: var(--primary-color); white-space: nowrap;">
                                <?= htmlspecialchars($p['name']) ?><br>
                                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;"><?= htmlspecialchars($p['client_name'] ?? '') ?></span>
                            </td>
                            <td><?= htmlspecialchars($p['stage']) ?></td>
                            
                            <td style="border-left: 2px solid var(--border-glass); text-align: center;">
                                <?= renderSafetyBadge($p['safety_status']) ?>
                            </td>
                            
                            <td>
                                <?php if (!empty($p['safety_comments'])): ?>
                                    <div style="font-size: 0.8rem; color: var(--text-primary); background: rgba(255,255,255,0.03); padding: 0.5rem; border-radius: 4px; border-left: 3px solid var(--primary-color);">
                                        <?= nl2br(htmlspecialchars($p['safety_comments'])) ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-style: italic;">No comments</span>
                                <?php endif; ?>
                            </td>
                            
                            <td style="border-left: 2px solid var(--border-glass);">
                                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">
                                    <?= count($p['equipment']) ?> Items Tracked
                                </div>
                                <?php if ($p['expired_count'] > 0): ?>
                                    <div class="warning-pill">⚠️ <?= $p['expired_count'] ?> Expired Certs</div>
                                <?php endif; ?>
                                <?php if ($p['uncertified_count'] > 0): ?>
                                    <div class="warning-pill">❌ <?= $p['uncertified_count'] ?> Uncertified</div>
                                <?php endif; ?>
                                <?php if ($p['expired_count'] == 0 && $p['uncertified_count'] == 0 && count($p['equipment']) > 0): ?>
                                    <span style="font-size: 0.75rem; color: #22c55e;">✅ All Clear</span>
                                <?php endif; ?>
                            </td>
                            
                            <?php if ($canEditOHSA): ?>
                            <td style="text-align: right;">
                                <button onclick='openOHSAModal(<?= json_encode($p, JSON_HEX_APOS) ?>)' class="btn btn-sm btn-primary">Manage OHSA</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canEditOHSA): ?>
<div id="ohsaModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 id="modalProjectName" style="margin-bottom: 1.5rem; color: var(--primary-color);">Manage OHSA Details</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="save_ohsa">
            <input type="hidden" name="project_id" id="modalProjectId">
            
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1.5rem; margin-bottom: 2rem;">
                <div class="form-group" style="margin: 0;">
                    <label style="font-weight: 600;">Overall Safety Status</label>
                    <select name="safety_status" id="modalSafetyStatus" style="width: 100%; padding: 0.75rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary); font-size: 1rem;">
                        <option value="N/A">⚪ N/A</option>
                        <option value="Green">🟢 Green (Safe / Compliant)</option>
                        <option value="Yellow">🟡 Yellow (Warning / Minor Issues)</option>
                        <option value="Red">🔴 Red (Stop Notice / Critical Hazard)</option>
                    </select>
                </div>
                
                <div class="form-group" style="margin: 0;">
                    <label style="font-weight: 600;">Safety Comments / Notes</label>
                    <textarea name="safety_comments" id="modalSafetyComments" rows="3" placeholder="Enter notes regarding site safety..." style="width: 100%; padding: 0.75rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary); resize: vertical;"></textarea>
                </div>
            </div>

            <div style="border-top: 1px solid var(--border-glass); padding-top: 1.5rem; margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div>
                        <h3 style="margin: 0; color: var(--text-primary);">Site Equipment Tracker</h3>
                        <p style="margin: 0; font-size: 0.85rem; color: var(--text-secondary);">Add Tower Cranes, Chains, Scaffolding, or any equipment requiring certification.</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addEquipmentRow()">+ Add Equipment</button>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 3fr 1fr 1.5fr auto; gap: 0.5rem; padding: 0 1rem; font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem;">
                    <div>Equipment Name</div>
                    <div>Specific Details / Serial No.</div>
                    <div>Certified?</div>
                    <div>Expiry Date</div>
                    <div style="width: 30px;"></div>
                </div>

                <div id="equipmentContainer">
                    </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem;">Save OHSA Details</button>
        </form>
    </div>
</div>

<script>
let eqCount = 0;

function addEquipmentRow(data = null) {
    const container = document.getElementById('equipmentContainer');
    const div = document.createElement('div');
    div.className = 'eq-row';
    div.id = `eq-row-${eqCount}`;
    
    const name = data ? escapeHtml(data.equipment_name) : '';
    const details = data ? escapeHtml(data.details) : '';
    const cert = data ? data.is_certified : 'N/A';
    const expiry = data && data.expiry_date ? data.expiry_date : '';

    div.innerHTML = `
        <input type="text" name="eq_name[]" value="${name}" placeholder="e.g. Tower Crane" required>
        <input type="text" name="eq_details[]" value="${details}" placeholder="Make, Model, or Notes">
        <select name="eq_cert[]">
            <option value="N/A" ${cert==='N/A'?'selected':''}>N/A</option>
            <option value="Yes" ${cert==='Yes'?'selected':''}>Yes</option>
            <option value="No" ${cert==='No'?'selected':''}>No</option>
        </select>
        <input type="date" name="eq_expiry[]" value="${expiry}">
        <button type="button" onclick="document.getElementById('eq-row-${eqCount}').remove()" class="btn btn-sm btn-danger" style="margin:0; padding:0; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center;">X</button>
    `;
    
    container.appendChild(div);
    eqCount++;
}

function openOHSAModal(project) {
    document.getElementById('modalProjectId').value = project.id;
    document.getElementById('modalProjectName').textContent = 'OHSA Details: ' + project.name;
    document.getElementById('modalSafetyStatus').value = project.safety_status || 'N/A';
    document.getElementById('modalSafetyComments').value = project.safety_comments || '';
    
    const container = document.getElementById('equipmentContainer');
    container.innerHTML = ''; // Clear existing
    
    if (project.equipment && project.equipment.length > 0) {
        project.equipment.forEach(eq => addEquipmentRow(eq));
    } else {
        // Pre-fill two standard blanks to guide the user
        addEquipmentRow({equipment_name: 'Tower Crane', details: '', is_certified: 'N/A', expiry_date: ''});
        addEquipmentRow({equipment_name: 'Crane Chains', details: '', is_certified: 'N/A', expiry_date: ''});
    }
    
    document.getElementById('ohsaModal').style.display = 'block';
}

function closeModal() { document.getElementById('ohsaModal').style.display = 'none'; }
window.onclick = function(event) { let modal = document.getElementById('ohsaModal'); if (event.target == modal) { modal.style.display = "none"; } }

function escapeHtml(text) { return text ? String(text).replace(/[&<>"'`=\/]/g, function(s){return entityMap[s];}) : ''; }
const entityMap = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'};
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
