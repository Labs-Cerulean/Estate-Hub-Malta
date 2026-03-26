<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('view_ohsa') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = ''; $error = '';
$canEditOHSA = hasPermission('assign_actions') || isAdmin();

// ==========================================
// HANDLE POST REQUESTS (Save OHSA Data)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_ohsa' && $canEditOHSA) {
    try {
        $pdo->beginTransaction();
        $pId = $_POST['project_id'];
        
        // 1. Save High-Level Status & Comments
        $stmtStatus = $pdo->prepare("
            INSERT INTO project_ohsa_setup (project_id, cnf_status, pscs_name, safety_status, safety_comments) 
            VALUES (?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE cnf_status=VALUES(cnf_status), pscs_name=VALUES(pscs_name), safety_status=VALUES(safety_status), safety_comments=VALUES(safety_comments)
        ");
        $stmtStatus->execute([
            $pId, 
            $_POST['cnf_status'] ?? 'Not Submitted',
            trim($_POST['pscs_name'] ?? ''),
            $_POST['safety_status'] ?? 'N/A', 
            $_POST['safety_comments'] ?? ''
        ]);

        // 2. Save Dynamic Equipment List
        $pdo->prepare("DELETE FROM project_ohsa_equipment WHERE project_id = ?")->execute([$pId]);
        
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
// FETCH DATA & GET FILTERS
// ==========================================
$filterSafety = $_GET['filter_safety'] ?? 'all';
$filterCNF = $_GET['filter_cnf'] ?? 'all';
$filterWarnings = $_GET['filter_warnings'] ?? 'all';

$projectsRaw = getAccessibleProjects($pdo, getCurrentUserId());
$projectIds = array_column($projectsRaw, 'id');

$ohsaSetups = [];
$ohsaEquipment = [];
$paNumbers = [];

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    
    // Fetch OHSA Setup
    $statusStmt = $pdo->prepare("SELECT * FROM project_ohsa_setup WHERE project_id IN ($placeholders)");
    $statusStmt->execute($projectIds);
    foreach ($statusStmt->fetchAll() as $row) { $ohsaSetups[$row['project_id']] = $row; }
    
    // Fetch Equipment
    $eqStmt = $pdo->prepare("SELECT * FROM project_ohsa_equipment WHERE project_id IN ($placeholders) ORDER BY id ASC");
    $eqStmt->execute($projectIds);
    foreach ($eqStmt->fetchAll() as $row) { $ohsaEquipment[$row['project_id']][] = $row; }

    // Fetch PA Numbers (for the details modal)
    $paStmt = $pdo->prepare("SELECT project_id, pa_number FROM project_pa_numbers WHERE project_id IN ($placeholders)");
    $paStmt->execute($projectIds);
    foreach ($paStmt->fetchAll() as $row) { $paNumbers[$row['project_id']][] = $row['pa_number']; }
}

// 3. Filter for active execution stages
$ohsaProjects = [];
$ohsaStages = ['Mobilisation', 'Mobilization', 'Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over'];

foreach ($projectsRaw as $p) {
    // FIX 1: We will comment out the strict "Active" filter to ensure 
    // projects labeled as "On Hold" or "Pre-construction" don't magically vanish from safety tracking.
    // if (($p['project_status'] ?? 'Active') !== 'Active') continue;

    // FIX 2: Upgrade to the Enterprise Stage Engine to match the Dashboard
    $stage = getAccurateProjectStage($pdo, $p['id']);
    
    if (in_array($stage, $ohsaStages)) {
        $p['stage'] = $stage;
        $p['cnf_status'] = $ohsaSetups[$p['id']]['cnf_status'] ?? 'Not Submitted';
        $p['pscs_name'] = $ohsaSetups[$p['id']]['pscs_name'] ?? 'Unassigned';
        $p['safety_status'] = $ohsaSetups[$p['id']]['safety_status'] ?? 'N/A';
        $p['safety_comments'] = $ohsaSetups[$p['id']]['safety_comments'] ?? '';;
        
        $p['equipment'] = $ohsaEquipment[$p['id']] ?? [];
        $p['pa_numbers'] = $paNumbers[$p['id']] ?? [];
        
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
        $hasWarnings = ($expiredCount > 0 || $uncertifiedCount > 0);

        // Apply Filters
        if ($filterSafety !== 'all' && $p['safety_status'] !== $filterSafety) continue;
        if ($filterCNF !== 'all' && $p['cnf_status'] !== $filterCNF) continue;
        if ($filterWarnings === 'yes' && !$hasWarnings) continue;
        if ($filterWarnings === 'no' && $hasWarnings) continue;
        
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
    return "<span style='padding: 0.3rem 0.6rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; white-space: nowrap; $style'>$icon" . strtoupper($status) . "</span>";
}

function renderCNFBadge($status) {
    $colors = [
        'Not Submitted' => 'background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);',
        'Submitted' => 'background: rgba(59, 130, 246, 0.1); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3);',
        'Terminated' => 'background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3);',
        'N/A' => 'background: rgba(107, 114, 128, 0.1); color: #9ca3af; border: 1px solid #4b5563;'
    ];
    $style = $colors[$status] ?? $colors['Not Submitted'];
    return "<span style='padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; white-space: nowrap; $style'>$status</span>";
}

$pageTitle = 'OHSA Safety Matrix';
require_once 'header.php';
?>

<style>
/* Frozen Matrix styling */
.matrix-wrapper { position: relative; width: 100%; max-height: calc(100vh - 220px); overflow: auto; background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid var(--border-glass); box-shadow: var(--shadow-sm); }
.matrix-table { width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; font-size: 0.85rem; }
.matrix-table th { position: sticky; top: 0; background: #1e1e2d; z-index: 10; padding: 1rem; font-weight: 600; color: var(--text-primary); border-bottom: 2px solid var(--border-glass); white-space: nowrap; }
.matrix-table td { padding: 1rem; border-bottom: 1px solid var(--border-glass); vertical-align: middle; color: var(--text-secondary); white-space: nowrap; }
.matrix-table thead th:first-child { position: sticky; left: 0; z-index: 20; border-right: 2px solid var(--border-glass); }
.matrix-table tbody td:first-child { position: sticky; left: 0; background: #1e1e2d; z-index: 5; border-right: 2px solid var(--border-glass); }
.matrix-table thead th:last-child { position: sticky; right: 0; z-index: 20; border-left: 2px solid var(--border-glass); text-align: center; }
.matrix-table tbody td:last-child { position: sticky; right: 0; background: #1e1e2d; z-index: 5; border-left: 2px solid var(--border-glass); text-align: center; }
.matrix-table tbody tr:hover td { background: rgba(255,255,255,0.03); }
.matrix-table tbody tr:hover td:first-child, .matrix-table tbody tr:hover td:last-child { background: #2a2a3b; }

/* Elements */
.warning-pill { display: inline-block; padding: 0.2rem 0.5rem; background: rgba(239, 68, 68, 0.15); color: #ef4444; border-radius: 4px; font-size: 0.75rem; font-weight: 600; margin-top: 0.25rem; border: 1px solid rgba(239, 68, 68, 0.3); }
.project-link:hover { text-decoration: underline !important; }

/* Modal Styles */
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 2% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 95%; max-width: 900px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; line-height: 1; }
.close-modal:hover { color: var(--text-primary); }

.eq-row { display: grid; grid-template-columns: 2fr 3fr 1fr 1.5fr auto; gap: 0.5rem; margin-bottom: 0.75rem; align-items: start; background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 6px; border: 1px solid var(--border-glass); }
.eq-row input, .eq-row select { width: 100%; padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary); font-size: 0.85rem; }
</style>

<div class="main-container" style="max-width: 1600px;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">OHSA Safety Matrix</h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">Monitor safety statuses, CNF, PSCS assignments, and equipment certifications.</p>
        </div>
    </div>

    <div class="filters-section" style="margin-bottom: 1.5rem;">
        <form method="GET">
            <div class="filters-grid">
                <div class="filter-group">
                    <label>Overall Safety Status</label>
                    <select name="filter_safety">
                        <option value="all" <?= $filterSafety === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="Green" <?= $filterSafety === 'Green' ? 'selected' : '' ?>>🟢 Green</option>
                        <option value="Yellow" <?= $filterSafety === 'Yellow' ? 'selected' : '' ?>>🟡 Yellow</option>
                        <option value="Red" <?= $filterSafety === 'Red' ? 'selected' : '' ?>>🔴 Red</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>CNF Status</label>
                    <select name="filter_cnf">
                        <option value="all" <?= $filterCNF === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="Not Submitted" <?= $filterCNF === 'Not Submitted' ? 'selected' : '' ?>>Not Submitted</option>
                        <option value="Submitted" <?= $filterCNF === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
                        <option value="Terminated" <?= $filterCNF === 'Terminated' ? 'selected' : '' ?>>Terminated</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Equipment Warnings</label>
                    <select name="filter_warnings">
                        <option value="all" <?= $filterWarnings === 'all' ? 'selected' : '' ?>>All Projects</option>
                        <option value="yes" <?= $filterWarnings === 'yes' ? 'selected' : '' ?>>⚠️ Show Only Warnings (Expired/Uncertified)</option>
                        <option value="no" <?= $filterWarnings === 'no' ? 'selected' : '' ?>>✅ Show Only Compliant</option>
                    </select>
                </div>
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="ohsa.php" class="reset-btn">Reset</a>
            </div>
        </form>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="matrix-wrapper">
        <table class="matrix-table">
            <thead>
                <tr>
                    <th>Project Name</th>
                    <th>Stage</th>
                    <th style="border-left: 2px solid var(--border-glass);">PS H&S (PSCS)</th>
                    <th>CNF Status</th>
                    <th style="border-left: 2px solid var(--border-glass); text-align: center;">Safety Status</th>
                    <th style="min-width: 250px; white-space: normal;">Safety Comments</th>
                    <th style="border-left: 2px solid var(--border-glass);">Tracked Equipment</th>
                    <?php if ($canEditOHSA): ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($ohsaProjects)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 2rem;">No active projects matching criteria found.</td></tr>
                <?php else: ?>
                    <?php foreach($ohsaProjects as $p): ?>
                        <tr>
                            <td style="font-weight: 700;">
                                <a href="javascript:void(0);" onclick='openProjectDetailsModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' style="color: var(--primary-color); text-decoration: none; display: inline-block;" class="project-link" title="Click to view details">
                                    <?= htmlspecialchars($p['name']) ?><br>
                                    <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;"><?= htmlspecialchars($p['client_name'] ?? '') ?></span>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($p['stage']) ?></td>
                            
                            <td style="border-left: 2px solid var(--border-glass);">
                                <?= empty($p['pscs_name']) ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['pscs_name']) ?>
                            </td>
                            <td><?= renderCNFBadge($p['cnf_status']) ?></td>

                            <td style="border-left: 2px solid var(--border-glass); text-align: center;">
                                <?= renderSafetyBadge($p['safety_status']) ?>
                            </td>
                            
                            <td style="white-space: normal;">
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
                                    <div class="warning-pill">⚠️ <?= $p['expired_count'] ?> Expired Certs</div><br>
                                <?php endif; ?>
                                <?php if ($p['uncertified_count'] > 0): ?>
                                    <div class="warning-pill">❌ <?= $p['uncertified_count'] ?> Uncertified</div>
                                <?php endif; ?>
                                <?php if ($p['expired_count'] == 0 && $p['uncertified_count'] == 0 && count($p['equipment']) > 0): ?>
                                    <span style="font-size: 0.75rem; color: #22c55e;">✅ All Clear</span>
                                <?php endif; ?>
                            </td>
                            
                            <?php if ($canEditOHSA): ?>
                            <td>
                                <button onclick='openOHSAModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="btn btn-sm btn-primary" style="margin:0;">Manage OHSA</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="projectDetailsModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <span class="close-modal" onclick="closeProjectDetailsModal()">&times;</span>
        <h2 id="pdModalName" style="color: var(--primary-color); margin-top: 0; margin-bottom: 1.5rem;">Project Name</h2>
        
        <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--border-glass); display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.95rem;">
            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                <span style="color: var(--text-muted);">Client:</span>
                <strong id="pdModalClient" style="color: var(--text-primary);"></strong>
            </div>
            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                <span style="color: var(--text-muted);">Location:</span>
                <strong id="pdModalLocation" style="color: var(--text-primary); text-align: right;"></strong>
            </div>
            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                <span style="color: var(--text-muted);">PA Number(s):</span>
                <strong id="pdModalPANumbers" style="color: var(--primary-color); text-align: right;"></strong>
            </div>
            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                <span style="color: var(--text-muted);">Type:</span>
                <strong id="pdModalType" style="color: var(--text-primary);"></strong>
            </div>
            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">
                <span style="color: var(--text-muted);">Status:</span>
                <strong id="pdModalStatus" style="color: var(--text-primary);"></strong>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span style="color: var(--text-muted);">Finish Req:</span>
                <strong id="pdModalFinish" style="color: var(--text-primary);"></strong>
            </div>
        </div>
    </div>
</div>

<?php if ($canEditOHSA): ?>
<div id="ohsaModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeOHSAModal()">&times;</span>
        <h2 id="modalProjectName" style="margin-bottom: 1.5rem; color: var(--primary-color);">Manage OHSA Details</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="save_ohsa">
            <input type="hidden" name="project_id" id="modalProjectId">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-glass);">
                <div class="form-group" style="margin: 0;">
                    <label style="font-weight: 600;">CNF Status</label>
                    <select name="cnf_status" id="modalCnfStatus" style="width: 100%; padding: 0.75rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary);">
                        <option value="Not Submitted">Not Submitted</option>
                        <option value="Submitted">Submitted</option>
                        <option value="Terminated">Terminated</option>
                        <option value="N/A">N/A</option>
                    </select>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label style="font-weight: 600;">Project Supervisor (PS H&S)</label>
                    <input type="text" name="pscs_name" id="modalPscsName" placeholder="e.g. I. Pulis" style="width: 100%; padding: 0.75rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary);">
                </div>
            </div>

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

                <div id="equipmentContainer"></div>
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
    document.getElementById('modalCnfStatus').value = project.cnf_status || 'Not Submitted';
    document.getElementById('modalPscsName').value = project.pscs_name || '';
    
    const container = document.getElementById('equipmentContainer');
    container.innerHTML = ''; 
    
    if (project.equipment && project.equipment.length > 0) {
        project.equipment.forEach(eq => addEquipmentRow(eq));
    } else {
        addEquipmentRow({equipment_name: 'Tower Crane', details: '', is_certified: 'N/A', expiry_date: ''});
        addEquipmentRow({equipment_name: 'Crane Chains', details: '', is_certified: 'N/A', expiry_date: ''});
    }
    
    document.getElementById('ohsaModal').style.display = 'block';
}

function closeOHSAModal() { document.getElementById('ohsaModal').style.display = 'none'; }
function escapeHtml(text) { return text ? String(text).replace(/[&<>"'`=\/]/g, function(s){return entityMap[s];}) : ''; }
const entityMap = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'};
</script>
<?php endif; ?>

<script>
// Project Details View Logic (Available to all users viewing the page)
function openProjectDetailsModal(project) {
    document.getElementById('pdModalName').textContent = project.name;
    document.getElementById('pdModalClient').textContent = project.client_name || 'N/A';
    
    // Safely combine location fields, dropping empty ones
    let locationParts = [];
    if (project.address) locationParts.push(project.address);
    if (project.city) locationParts.push(project.city);
    if (project.island) locationParts.push(project.island);
    
    document.getElementById('pdModalLocation').textContent = locationParts.length > 0 ? locationParts.join(', ') : 'N/A';
    
    // Add PA Numbers
    let paText = 'N/A';
    if (project.pa_numbers && project.pa_numbers.length > 0) {
        paText = project.pa_numbers.join(', ');
    }
    document.getElementById('pdModalPANumbers').textContent = paText;

    document.getElementById('pdModalType').textContent = project.type || 'N/A';
    document.getElementById('pdModalStatus').textContent = project.project_status || 'Active';
    document.getElementById('pdModalFinish').textContent = project.finishlevel || 'N/A';
    
    document.getElementById('projectDetailsModal').style.display = 'block';
}

function closeProjectDetailsModal() { 
    document.getElementById('projectDetailsModal').style.display = 'none'; 
}

// Global modal closer logic
window.onclick = function(event) { 
    let ohsaModal = document.getElementById('ohsaModal'); 
    let pdModal = document.getElementById('projectDetailsModal');
    
    if (ohsaModal && event.target == ohsaModal) { ohsaModal.style.display = "none"; } 
    if (pdModal && event.target == pdModal) { pdModal.style.display = "none"; } 
}
</script>

<?php require_once 'footer.php'; ?>
