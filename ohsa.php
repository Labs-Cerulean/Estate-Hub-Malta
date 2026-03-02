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

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    
    $statusStmt = $pdo->prepare("SELECT * FROM project_ohsa_setup WHERE project_id IN ($placeholders)");
    $statusStmt->execute($projectIds);
    foreach ($statusStmt->fetchAll() as $row) { $ohsaSetups[$row['project_id']] = $row; }
    
    $eqStmt = $pdo->prepare("SELECT * FROM project_ohsa_equipment WHERE project_id IN ($placeholders) ORDER BY id ASC");
    $eqStmt->execute($projectIds);
    foreach ($eqStmt->fetchAll() as $row) { $ohsaEquipment[$row['project_id']][] = $row; }
}

// 3. Filter for active execution stages
$ohsaProjects = [];
$ohsaStages = ['Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over'];

foreach ($projectsRaw as $p) {
    // Exclude withdrawn or on-hold projects entirely
    if (($p['project_status'] ?? 'Active') !== 'Active') continue;

    $stage = deriveProjectStage($pdo, $p['id']);
    
    if (in_array($stage, $ohsaStages)) {
        $p['stage'] = $stage;
        $p['cnf_status'] = $ohsaSetups[$p['id']]['cnf_status'] ?? 'Not Submitted';
        $p['pscs_name'] = $ohsaSetups[$p['id']]['pscs_name'] ?? 'Unassigned';
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

/* Modal Styles */
.modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 2% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 95%; max-width: 900px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }

.eq-row { display: grid; grid-template-columns: 2fr 3fr 1fr 1.5fr auto; gap: 0.5rem; margin-bottom: 0.75rem; align-items: start; background: rgba(255,255,255,0.02);
