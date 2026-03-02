<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('view_projects') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = ''; $error = '';
$canAssignTeam = hasPermission('edit_project_details') || isAdmin();

// Handle Team Assignment Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_team' && $canAssignTeam) {
    try {
        $pId = $_POST['project_id'];
        $stmt = $pdo->prepare("UPDATE projects SET pm_construction_id=?, pm_finishes_id=?, sub_demolition_id=?, sub_excavation_id=?, sub_construction_id=? WHERE id=?");
        $stmt->execute([
            empty($_POST['pm_const']) ? null : $_POST['pm_const'],
            empty($_POST['pm_fin']) ? null : $_POST['pm_fin'],
            empty($_POST['sub_demo']) ? null : $_POST['sub_demo'],
            empty($_POST['sub_exc']) ? null : $_POST['sub_exc'],
            empty($_POST['sub_const']) ? null : $_POST['sub_const'],
            $pId
        ]);
        $message = "Project team updated successfully!";
    } catch (PDOException $e) {
        $error = "Error updating team: " . $e->getMessage();
    }
}

// 1. Fetch available PMs and Subcontractors for the dropdowns
$pms = $pdo->query("SELECT id, first_name, last_name, username FROM users WHERE role = 'project_manager' AND is_active = 'Yes' ORDER BY first_name")->fetchAll();
$subs = $pdo->query("SELECT id, name FROM subcontractors ORDER BY name")->fetchAll();

// 2. Fetch Projects and their Mobilisation statuses
$projectsRaw = getAccessibleProjects($pdo, getCurrentUserId());
$projectIds = array_column($projectsRaw, 'id');

$mobData = [];
$blockData = [];

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    
    // Fetch Execution Clearances
    $mobStmt = $pdo->prepare("SELECT project_id, demo_status, excavation_status FROM project_mobilisation WHERE project_id IN ($placeholders)");
    $mobStmt->execute($projectIds);
    foreach ($mobStmt->fetchAll() as $row) { $mobData[$row['project_id']] = $row; }
    
    // Fetch Block & Floor Execution Statuses to calculate overall status
    $blockStmt = $pdo->prepare("
        SELECT pb.project_id, pb.id as block_id, pb.finishes_overall_status, bl.construction_status 
        FROM project_blocks pb 
        LEFT JOIN block_levels bl ON pb.id = bl.block_id 
        WHERE pb.project_id IN ($placeholders)
    ");
    $blockStmt->execute($projectIds);
    foreach ($blockStmt->fetchAll() as $row) {
        $blockData[$row['project_id']][] = $row;
    }
}

// 3. Filter for Stages 3 to 11 and compute dynamic statuses
$matrixProjects = [];
$allowedStages = ['Permit', 'Mobilisation', 'Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over'];

foreach ($projectsRaw as $p) {
    if (($project['project_status'] ?? 'Active') !== 'Active') continue;
    $stage = deriveProjectStage($pdo, $p['id']);
    
    if (in_array($stage, $allowedStages)) {
        // Grab Demolition & Excavation
        $p['demo_status'] = $mobData[$p['id']]['demo_status'] ?? 'Pending';
        $p['exc_status'] = $mobData[$p['id']]['excavation_status'] ?? 'Pending';
        
        // Calculate High-Level Construction Status
        $p['const_status'] = 'Pending';
        $constStatuses = [];
        $finStatuses = [];
        
        if (isset($blockData[$p['id']])) {
            foreach ($blockData[$p['id']] as $bd) {
                if ($bd['construction_status']) $constStatuses[] = $bd['construction_status'];
                if ($bd['finishes_overall_status']) $finStatuses[$bd['block_id']] = $bd['finishes_overall_status']; // Group by block
            }
        }
        
        // Aggregate Construction
        if (!empty($constStatuses)) {
            if (in_array('In Progress', $constStatuses)) { $p['const_status'] = 'In Progress'; }
            elseif (count(array_unique($constStatuses)) === 1 && (end($constStatuses) === 'Complete' || end($constStatuses) === 'NA')) { $p['const_status'] = 'Complete'; }
            elseif (in_array('Complete', $constStatuses)) { $p['const_status'] = 'In Progress'; } // Some complete, some pending
        }
        
        // Aggregate Finishes
        $p['fin_status'] = 'Pending';
        if (in_array($p['finishlevel'], ['Shell', null, ''])) {
            $p['fin_status'] = 'NA';
        } elseif (!empty($finStatuses)) {
            if (in_array('In Progress', $finStatuses)) { $p['fin_status'] = 'In Progress'; }
            elseif (count(array_unique($finStatuses)) === 1 && (end($finStatuses) === 'Complete' || end($finStatuses) === 'NA')) { $p['fin_status'] = 'Complete'; }
            elseif (in_array('Complete', $finStatuses)) { $p['fin_status'] = 'In Progress'; }
        }

        // Map PMs and Subs names
        $p['pm_const_name'] = 'Unassigned';
        $p['pm_fin_name'] = 'Unassigned';
        foreach ($pms as $pm) {
            if ($pm['id'] == $p['pm_construction_id']) $p['pm_const_name'] = $pm['first_name'] . ' ' . $pm['last_name'];
            if ($pm['id'] == $p['pm_finishes_id']) $p['pm_fin_name'] = $pm['first_name'] . ' ' . $pm['last_name'];
        }
        
        $p['sub_demo_name'] = 'Unassigned';
        $p['sub_exc_name'] = 'Unassigned';
        $p['sub_const_name'] = 'Unassigned';
        foreach ($subs as $sub) {
            if ($sub['id'] == $p['sub_demolition_id']) $p['sub_demo_name'] = $sub['name'];
            if ($sub['id'] == $p['sub_excavation_id']) $p['sub_exc_name'] = $sub['name'];
            if ($sub['id'] == $p['sub_construction_id']) $p['sub_const_name'] = $sub['name'];
        }
        
        $p['stage'] = $stage;
        $matrixProjects[] = $p;
    }
}

// Function to render status badges
function renderStatusBadge($status) {
    $colors = [
        'Pending' => 'background: rgba(107, 114, 128, 0.1); color: #9ca3af; border: 1px solid #4b5563;',
        'In Progress' => 'background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid #d97706;',
        'Complete' => 'background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid #16a34a;',
        'NA' => 'background: rgba(255, 255, 255, 0.05); color: #6b7280; border: 1px solid #374151;'
    ];
    $style = $colors[$status] ?? $colors['Pending'];
    return "<span style='padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; white-space: nowrap; $style'>$status</span>";
}

$pageTitle = 'Project Status Matrix';
require_once 'header.php';
?>

<style>
.matrix-table-container { overflow-x: auto; background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid var(--border-glass); }
.matrix-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem; }
.matrix-table th { background: rgba(255,255,255,0.02); padding: 1rem; font-weight: 600; color: var(--text-primary); border-bottom: 1px solid var(--border-glass); white-space: nowrap; }
.matrix-table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-glass); vertical-align: middle; color: var(--text-secondary); }
.matrix-table tr:hover { background: rgba(255,255,255,0.02); }

/* Modal Styles */
.modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }
</style>

<div class="main-container" style="max-width: 1600px;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">Project Execution Matrix</h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">Live operational status for projects in Stages 3 through 11.</p>
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
                    <th>Finish Requirement</th>
                    <th style="border-left: 2px solid var(--border-glass);">PM (Construction)</th>
                    <th>PM (Finishes)</th>
                    <th style="border-left: 2px solid var(--border-glass);">Sub (Demolition)</th>
                    <th>Sub (Excavation)</th>
                    <th>Sub (Construction)</th>
                    <th style="border-left: 2px solid var(--border-glass); text-align: center;">Demolition</th>
                    <th style="text-align: center;">Excavation</th>
                    <th style="text-align: center;">Construction</th>
                    <th style="text-align: center;">Finishes</th>
                    <?php if ($canAssignTeam): ?><th style="text-align: right;">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($matrixProjects)): ?>
                    <tr><td colspan="13" style="text-align: center; padding: 2rem;">No active projects in execution stages found.</td></tr>
                <?php else: ?>
                    <?php foreach($matrixProjects as $p): ?>
                        <tr>
                            <td style="font-weight: 700; color: var(--primary-color); white-space: nowrap;">
                                <?= htmlspecialchars($p['name']) ?>
                            </td>
                            <td><?= htmlspecialchars($p['stage']) ?></td>
                            <td><?= htmlspecialchars($p['finishlevel'] ?? 'N/A') ?></td>
                            
                            <td style="border-left: 2px solid var(--border-glass);">
                                <?= $p['pm_const_name'] === 'Unassigned' ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['pm_const_name']) ?>
                            </td>
                            <td>
                                <?= $p['pm_fin_name'] === 'Unassigned' ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['pm_fin_name']) ?>
                            </td>
                            
                            <td style="border-left: 2px solid var(--border-glass);">
                                <?= $p['sub_demo_name'] === 'Unassigned' ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['sub_demo_name']) ?>
                            </td>
                            <td>
                                <?= $p['sub_exc_name'] === 'Unassigned' ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['sub_exc_name']) ?>
                            </td>
                            <td>
                                <?= $p['sub_const_name'] === 'Unassigned' ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['sub_const_name']) ?>
                            </td>

                            <td style="border-left: 2px solid var(--border-glass); text-align: center;"><?= renderStatusBadge($p['demo_status']) ?></td>
                            <td style="text-align: center;"><?= renderStatusBadge($p['exc_status']) ?></td>
                            <td style="text-align: center;"><?= renderStatusBadge($p['const_status']) ?></td>
                            <td style="text-align: center;"><?= renderStatusBadge($p['fin_status']) ?></td>
                            
                            <?php if ($canAssignTeam): ?>
                            <td style="text-align: right;">
                                <button onclick='openAssignModal(<?= json_encode([
                                    "id" => $p["id"], "name" => $p["name"],
                                    "pm_const" => $p["pm_construction_id"], "pm_fin" => $p["pm_finishes_id"],
                                    "sub_demo" => $p["sub_demolition_id"], "sub_exc" => $p["sub_excavation_id"], "sub_const" => $p["sub_construction_id"]
                                ], JSON_HEX_APOS) ?>)' class="btn btn-sm btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Assign Team</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canAssignTeam): ?>
<div id="assignModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 id="modalProjectName" style="margin-bottom: 1.5rem; color: var(--primary-color);">Assign Project Team</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="assign_team">
            <input type="hidden" name="project_id" id="modalProjectId">
            
            <h4 style="margin-bottom: 0.5rem; color: var(--text-secondary); border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Project Managers</h4>
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label>Construction PM</label>
                    <select name="pm_const" id="modalPmConst">
                        <option value="">-- Unassigned --</option>
                        <?php foreach($pms as $pm): ?><option value="<?= $pm['id'] ?>"><?= htmlspecialchars($pm['first_name'] . ' ' . $pm['last_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Finishes PM</label>
                    <select name="pm_fin" id="modalPmFin">
                        <option value="">-- Unassigned --</option>
                        <?php foreach($pms as $pm): ?><option value="<?= $pm['id'] ?>"><?= htmlspecialchars($pm['first_name'] . ' ' . $pm['last_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h4 style="margin-bottom: 0.5rem; color: var(--text-secondary); border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Lead Subcontractors</h4>
            <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
                <div class="form-group">
                    <label>Demolition</label>
                    <select name="sub_demo" id="modalSubDemo">
                        <option value="">-- Unassigned --</option>
                        <?php foreach($subs as $sub): ?><option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Excavation</label>
                    <select name="sub_exc" id="modalSubExc">
                        <option value="">-- Unassigned --</option>
                        <?php foreach($subs as $sub): ?><option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Construction</label>
                    <select name="sub_const" id="modalSubConst">
                        <option value="">-- Unassigned --</option>
                        <?php foreach($subs as $sub): ?><option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%;">Save Assignments</button>
        </form>
    </div>
</div>

<script>
function openAssignModal(data) {
    document.getElementById('modalProjectId').value = data.id;
    document.getElementById('modalProjectName').textContent = 'Assign Team: ' + data.name;
    
    document.getElementById('modalPmConst').value = data.pm_const || '';
    document.getElementById('modalPmFin').value = data.pm_fin || '';
    document.getElementById('modalSubDemo').value = data.sub_demo || '';
    document.getElementById('modalSubExc').value = data.sub_exc || '';
    document.getElementById('modalSubConst').value = data.sub_const || '';
    
    document.getElementById('assignModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('assignModal').style.display = 'none';
}

// Close modal if clicking outside of it
window.onclick = function(event) {
    let modal = document.getElementById('assignModal');
    if (event.target == modal) { modal.style.display = "none"; }
}
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
