<?php
require_once 'init.php';
require_once 'session-check.php';

// Ensure user has adequate permissions (Admin, Director, or System Manager)
$userId = getCurrentUserId();
$isAdmin = isAdmin();
$role = $_SESSION['role'] ?? 'viewer';

if (!$isAdmin && !in_array($role, ['director', 'system_manager', 'project_manager'])) {
    die("Unauthorized access. Executive level reporting only.");
}

// Fetch all accessible clients
$clients = $isAdmin ? $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) : getUserClients($pdo, $userId);

$selectedClientId = isset($_GET['client_id']) ? $_GET['client_id'] : null;

$earlyStages = ['Feasibility', 'Tracking', 'Permit'];
$completedStages = ['Handed Over', 'Completed'];

// ==========================================
// DEFINED CLIENT GROUPS
// ==========================================
$clientGroups = [
    'EXCEL' => ['label' => 'ALL EXCEL COMPANIES', 'search' => 'Excel'],
    'BLUE_CLAY' => ['label' => 'ALL BLUE CLAY COMPANIES', 'search' => 'Blue Clay']
];

// ==========================================
// SUPER-FINDER Helper
// Scans multiple tables to find the name of the assigned entity safely
// ==========================================
function getEntityName($pdo, $id) {
    if (empty($id)) return "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>";
    
    // 1. Try Users (For PMs, STOs)
    try {
        $stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if ($name) return htmlspecialchars($name);
    } catch(Exception $e) {}
    
    // 2. Try Professionals (For Periti / Architects / Struct Eng)
    try {
        $stmt = $pdo->prepare("SELECT name FROM professionals WHERE id = ?");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if ($name) return htmlspecialchars($name);
    } catch(Exception $e) {}
    
    // 3. Try Clients (For Contractors like PRAX, PRA, Excel)
    try {
        $stmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if ($name) return htmlspecialchars($name);
    } catch(Exception $e) {}
    
    // 4. Try Subcontractors (For External Contractors)
    try {
        $stmt = $pdo->prepare("SELECT name FROM subcontractors WHERE id = ?");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if ($name) return htmlspecialchars($name);
    } catch(Exception $e) {}
    
    return "<span style='color:var(--text-muted); font-style:italic;'>ID: $id</span>";
}

$pageTitle = 'Daily Client Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        body { background-color: var(--bg-primary); color: var(--text-primary); font-family: 'Inter', sans-serif; padding: 2rem; }
        
        .selection-container { max-width: 600px; margin: 10vh auto; background: var(--bg-panel); padding: 3rem; border-radius: 12px; border: 1px solid var(--border-glass); box-shadow: 0 10px 30px rgba(0,0,0,0.3); text-align: center; }
        .selection-container h1 { color: var(--primary-color); margin-top: 0; }
        .selection-container select { width: 100%; padding: 1rem; border-radius: 8px; border: 1px solid var(--border-glass); background: var(--bg-card); color: var(--text-primary); font-size: 1.1rem; margin-bottom: 1.5rem; cursor: pointer; }
        
        .report-header { text-align: center; margin-bottom: 3rem; padding-bottom: 1.5rem; border-bottom: 2px solid var(--border-glass); position: relative; }
        .report-header h1 { color: var(--primary-color); margin: 0 0 10px 0; font-size: 2.2rem; }
        
        .client-section { margin-bottom: 4rem; background: var(--bg-panel); border: 1px solid var(--border-glass); border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .client-header { background: linear-gradient(90deg, rgba(99, 102, 241, 0.2), rgba(139, 92, 246, 0.2)); padding: 1.5rem 2rem; border-bottom: 1px solid var(--border-glass); }
        .client-header h2 { margin: 0; color: #fff; font-size: 1.8rem; }
        
        .phase-header { background: rgba(0,0,0,0.2); padding: 1rem 2rem; border-bottom: 1px solid var(--border-glass); border-top: 1px solid var(--border-glass); }
        .phase-header h3 { margin: 0; color: #fff; font-size: 1.3rem; display: flex; align-items: center; gap: 10px; }
        .phase-desc { font-size: 0.85rem; color: var(--text-muted); margin-top: 4px; }

        .project-card { padding: 1.5rem 2rem; border-bottom: 1px solid var(--border-glass); }
        .project-card:last-child { border-bottom: none; }
        .project-card:nth-child(even) { background: rgba(255,255,255,0.02); }
        
        .proj-title-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .proj-title { font-size: 1.4rem; font-weight: 700; color: var(--primary-color); margin: 0 0 5px 0; }
        .proj-loc { font-size: 0.9rem; color: var(--text-muted); display: flex; align-items: center; gap: 5px; }
        .proj-client { font-size: 0.8rem; color: #a855f7; font-weight: bold; text-transform: uppercase; margin-top: 5px; }
        
        .stage-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; text-transform: uppercase; background: rgba(14, 165, 233, 0.2); color: #0ea5e9; border: 1px solid #0ea5e9; }
        
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-top: 1rem; }
        .info-box { background: var(--bg-card); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-glass); }
        .info-box h4 { margin: 0 0 10px 0; color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase; border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; }
        .data-row { display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 6px; border-bottom: 1px dashed rgba(255,255,255,0.05); padding-bottom: 4px; }
        .data-label { color: var(--text-muted); font-weight: 600; }
        .data-value { color: #fff; text-align: right; }
        
        .alert-box { background: rgba(239, 68, 68, 0.1); border-left: 4px solid #ef4444; padding: 10px 15px; margin-top: 10px; border-radius: 4px; font-size: 0.85rem; }
        .warn-box { background: rgba(245, 158, 11, 0.1); border-left: 4px solid #f59e0b; padding: 10px 15px; margin-top: 10px; border-radius: 4px; font-size: 0.85rem; }
        
        .completed-section { background: rgba(34, 197, 94, 0.05); padding: 1.5rem 2rem; border-top: 1px solid var(--border-glass); }
        .completed-section h3 { color: #22c55e; margin: 0 0 10px 0; font-size: 1.1rem; }
        .completed-list { margin: 0; padding-left: 20px; color: var(--text-muted); font-size: 0.95rem; }
        
        .btn-print { position: fixed; bottom: 30px; right: 30px; background: var(--primary-color); color: white; border: none; padding: 15px 25px; border-radius: 30px; font-weight: bold; font-size: 1.1rem; cursor: pointer; box-shadow: 0 10px 25px rgba(99, 102, 241, 0.5); z-index: 1000; transition: transform 0.2s; }
        .btn-print:hover { transform: scale(1.05); }
        .btn-back { position: absolute; left: 0; top: 0; background: rgba(255,255,255,0.1); color: var(--text-muted); padding: 8px 15px; border-radius: 6px; text-decoration: none; font-size: 0.9rem; transition: 0.2s; }
        .btn-back:hover { background: rgba(255,255,255,0.2); color: #fff; }

        @media print {
            body { background: #fff !important; color: #000 !important; padding: 0; }
            .btn-print, .btn-back, .navbar, .sidebar { display: none !important; }
            .client-section { page-break-inside: avoid; border: 1px solid #ccc; box-shadow: none; margin-bottom: 2rem; }
            .client-header { background: #f3f4f6 !important; border-bottom: 2px solid #000; }
            .client-header h2 { color: #000 !important; }
            .phase-header { background: #e5e7eb !important; border-bottom: 1px solid #000; border-top: 1px solid #000; }
            .phase-header h3 { color: #000 !important; }
            .project-card { border-bottom: 1px solid #eee; page-break-inside: avoid; }
            .info-box { background: #fff; border: 1px solid #ddd; }
            .data-value { color: #000; }
            .data-label { color: #555; }
            .proj-title { color: #000; }
            .stage-badge { border: 1px solid #000; color: #000; }
            .alert-box, .warn-box { border: 1px solid #ccc; border-left: 4px solid #000; background: #fff; color: #000; }
            .data-row { border-bottom: 1px dashed #eee; }
        }
    </style>
</head>
<body>

<?php if (!$selectedClientId): ?>
    <div class="selection-container">
        <h1>🏢 Daily Executive Report</h1>
        <p>Select a Client (or Combined Group) to generate their dedicated status report.</p>
        
        <form method="GET">
            <select name="client_id" required>
                <option value="">-- Choose a Client / Group --</option>
                
                <optgroup label="🏢 Combined Client Groups">
                    <?php foreach($clientGroups as $key => $group): ?>
                        <option value="group_<?= $key ?>">❖ <?= htmlspecialchars($group['label']) ?></option>
                    <?php endforeach; ?>
                </optgroup>

                <optgroup label="👤 Individual Clients">
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem;">Generate Report</button>
            <div style="margin-top: 15px;"><a href="dashboard.php" style="color: var(--text-muted); text-decoration: none; font-size: 0.9rem;">&larr; Return to Dashboard</a></div>
        </form>
    </div>

<?php else: 
    // REPORT GENERATION SCREEN
    $selectedClientName = "Unknown Client";
    $projects = [];
    $isGroupReport = false;

    // Check if the user selected a combined group
    if (strpos($selectedClientId, 'group_') === 0) {
        $isGroupReport = true;
        $groupKey = str_replace('group_', '', $selectedClientId);
        
        if (isset($clientGroups[$groupKey])) {
            $selectedClientName = $clientGroups[$groupKey]['label'];
            $searchTerm = $clientGroups[$groupKey]['search'];
            
            $matchedClientIds = [];
            foreach ($clients as $c) {
                if (stripos($c['name'], $searchTerm) !== false) {
                    $matchedClientIds[] = $c['id'];
                }
            }
            
            if (!empty($matchedClientIds)) {
                $placeholders = implode(',', array_fill(0, count($matchedClientIds), '?'));
                $stmt = $pdo->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id WHERE p.clientid IN ($placeholders) ORDER BY c.name ASC, p.name ASC");
                $stmt->execute($matchedClientIds);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } else {
        // Normal individual client logic
        $selectedClientId = (int)$selectedClientId;
        foreach ($clients as $c) {
            if ($c['id'] == $selectedClientId) {
                $selectedClientName = $c['name'];
                break;
            }
        }
        $stmt = $pdo->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id WHERE p.clientid = ? ORDER BY p.name ASC");
        $stmt->execute([$selectedClientId]);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Sort logic arrays
    $mobProjects = [];
    $execProjects = [];
    $completedProjects = [];

    $stagesEnum = ['Mobilisation'=>4, 'Demolition'=>5, 'Excavation'=>6, 'Construction'=>7, 'Finishes'=>8, 'Compliance'=>9, 'Condominium'=>10];

    foreach ($projects as $p) {
        $stage = getAccurateProjectStage($pdo, $p['id']);
        
        // Skip Early Stages
        if (in_array($stage, $earlyStages)) continue;
        
        // Handle Completed
        if (in_array($stage, $completedStages) || ($p['status'] ?? '') === 'Completed') {
            $completedProjects[] = ['name' => $p['name'], 'stage' => $stage, 'client_name' => $p['client_name']];
            continue;
        }

        // Fetch Mobilisation Checklists to calculate sorting score
        $mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
        $mobStmt->execute([$p['id']]);
        $mob = $mobStmt->fetch(PDO::FETCH_ASSOC);
        
        $p['calculated_stage'] = $stage;
        $p['mob_data'] = $mob;
        $p['avg_prog'] = 0;

        if ($stage === 'Mobilisation') {
            // Count missing elements for sorting (Least mobilised / Most Missing -> First)
            $missing = 0;
            if (!in_array($mob['geological_test'] ?? '', ['Complete', 'NA'])) $missing++;
            if (!in_array($mob['condition_reports'] ?? '', ['Complete', 'NA'])) $missing++;
            if (!in_array($mob['method_statements'] ?? '', ['Complete', 'NA'])) $missing++;
            if (!in_array($mob['insurance_status'] ?? '', ['Complete', 'NA'])) $missing++;
            if (!in_array($mob['responsibility_form'] ?? '', ['Complete', 'NA'])) $missing++;
            if (($mob['mob_demolition'] ?? 'No') === 'No') $missing++;
            if (($mob['mob_excavation'] ?? 'No') === 'No') $missing++;
            if (($mob['mob_construction'] ?? 'No') === 'No') $missing++;
            
            $p['missing_mob_count'] = $missing;
            $mobProjects[] = $p;
        } else {
            // Calculate Execution Progress for sorting (Least progressed -> First)
            $bStmt = $pdo->prepare("SELECT AVG(progress) FROM project_blocks WHERE project_id = ?");
            $bStmt->execute([$p['id']]);
            $p['avg_prog'] = round((float)$bStmt->fetchColumn(), 1);
            
            $stageScore = $stagesEnum[$stage] ?? 5;
            $p['exec_score'] = ($stageScore * 100) + $p['avg_prog'];
            
            $execProjects[] = $p;
        }
    }

    // Sort Mobilisation: Most Missing tasks first (Least Mobilised)
    usort($mobProjects, function($a, $b) { return $b['missing_mob_count'] <=> $a['missing_mob_count']; });
    
    // Sort Execution: Lowest Score first (Least Progressed)
    usort($execProjects, function($a, $b) { return $a['exec_score'] <=> $b['exec_score']; });

?>

    <div class="report-header">
        <a href="daily_client_report.php" class="btn-back">&larr; Select Different Client</a>
        <h1>🏢 Daily Executive Report</h1>
        <p>Generated on <strong><?= date('l, d F Y') ?></strong> at <strong><?= date('H:i') ?></strong></p>
    </div>

    <button class="btn-print" onclick="window.print()">🖨️ Print / Save PDF</button>

    <?php if (empty($mobProjects) && empty($execProjects) && empty($completedProjects)): ?>
        <div style="text-align: center; padding: 4rem; background: var(--bg-panel); border-radius: 12px; border: 1px solid var(--border-glass);">
            <h2 style="color: var(--text-muted);">No Active Sites Found</h2>
            <p>There are currently no projects in Mobilisation or Execution for <strong><?= htmlspecialchars($selectedClientName) ?></strong>.</p>
        </div>
    <?php else: ?>
        <div class="client-section">
            <div class="client-header">
                <h2><?= htmlspecialchars($selectedClientName) ?></h2>
            </div>
            
            <?php if (!empty($mobProjects)): ?>
            <div class="phase-header">
                <h3>🚧 Phase 1: Pre-Construction & Mobilisation</h3>
                <div class="phase-desc">Sites ordered from least mobilised to most ready. Focus is on resolving bottlenecks to begin physical works.</div>
            </div>
            
            <?php foreach ($mobProjects as $p): 
                $pid = $p['id'];
                $loc = $p['location'] ?? $p['locality'] ?? $p['address'] ?? 'Location not specified';
                
                // Extract safe PA Numbers
                $paStmt = $pdo->prepare("SELECT * FROM project_pa_numbers WHERE project_id = ?");
                $paStmt->execute([$pid]);
                $paRaw = $paStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Periti (Architects/Engineers)
                $archName = getEntityName($pdo, $p['architect_id'] ?? null);
                $structName = getEntityName($pdo, $p['structural_engineer_id'] ?? null);

                // Re-compile missing list for display
                $mob = $p['mob_data'];
                $pendingMob = [];
                if (!in_array($mob['geological_test'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Geological Test";
                if (!in_array($mob['condition_reports'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Condition Reports";
                if (!in_array($mob['method_statements'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Method Statements";
                if (!in_array($mob['insurance_status'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Insurance Status";
                if (!in_array($mob['responsibility_form'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Responsibility Form";
                if (($mob['mob_demolition'] ?? 'No') === 'No') $pendingMob[] = "Demolition Clearance";
                if (($mob['mob_excavation'] ?? 'No') === 'No') $pendingMob[] = "Excavation Clearance";
                if (($mob['mob_construction'] ?? 'No') === 'No') $pendingMob[] = "Construction Clearance";
            ?>
            <div class="project-card">
                <div class="proj-title-row">
                    <div>
                        <h3 class="proj-title"><?= htmlspecialchars($p['name']) ?></h3>
                        <div class="proj-loc">📍 <?= htmlspecialchars($loc) ?></div>
                        <?php if ($isGroupReport): ?><div class="proj-client">🏢 Entity: <?= htmlspecialchars($p['client_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="stage-badge">Mobilisation</div>
                </div>

                <div class="info-grid">
                    <div class="info-box">
                        <h4>📋 Regulatory & Periti</h4>
                        <div class="data-row"><span class="data-label">Architect:</span> <span class="data-value"><?= $archName ?></span></div>
                        <div class="data-row"><span class="data-label">Struct. Engineer:</span> <span class="data-value"><?= $structName ?></span></div>
                        
                        <div style="margin-top: 10px; border-top: 1px dashed var(--border-glass); padding-top: 5px;">
                            <span class="data-label">PA Approvals:</span><br>
                            <?php if (empty($paRaw)): ?>
                                <span style="color:var(--text-muted); font-size:0.85rem;">None recorded</span>
                            <?php else: foreach($paRaw as $pa): 
                                $status = $pa['status'] ?? $pa['pa_status'] ?? 'Issued/Unknown';
                            ?>
                                <div style="font-size:0.85rem; display:flex; justify-content:space-between; margin-bottom: 2px;">
                                    <span style="color:#fff;"><?= htmlspecialchars($pa['pa_number']) ?></span>
                                    <span style="color:var(--text-muted);"><?= htmlspecialchars($status) ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <h4>🚀 Mobilisation Bottlenecks</h4>
                        <?php if (!empty($pendingMob)): ?>
                            <div style="font-size: 0.85rem;">
                                <strong style="color:#f59e0b;">Pending Actions to start site:</strong>
                                <ul style="margin: 5px 0 0 0; padding-left: 20px; color: #fff;">
                                    <?php foreach($pendingMob as $req): ?>
                                        <li><?= $req ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div style="font-size: 0.85rem; color:#22c55e; font-weight:bold;">All requirements met! Ready for Demolition/Excavation.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; endif; ?>

            <?php if (!empty($execProjects)): ?>
            <div class="phase-header">
                <h3>🏗️ Phase 2: Active Site Execution</h3>
                <div class="phase-desc">Sites ordered from newly started to near completion. Includes Project Managers, Contractors, and Safety Status.</div>
            </div>
            
            <?php foreach ($execProjects as $p): 
                $pid = $p['id'];
                $stage = $p['calculated_stage'];
                $loc = $p['location'] ?? $p['locality'] ?? $p['address'] ?? 'Location not specified';
                $mob = $p['mob_data'];

                // Safe Fetch PA Numbers
                $paStmt = $pdo->prepare("SELECT * FROM project_pa_numbers WHERE project_id = ?");
                $paStmt->execute([$pid]);
                $paRaw = $paStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Execution Personnel
                $pmName = getEntityName($pdo, $p['project_manager_id'] ?? $p['pm_id'] ?? null);
                
                // Identify all 3 potential contractors (Fallback logic protects against column name variations)
                $demoContractor = getEntityName($pdo, $p['demo_contractor_id'] ?? $p['contractor_demo_id'] ?? null);
                $civilContractor = getEntityName($pdo, $p['civil_contractor_id'] ?? $p['construction_contractor_id'] ?? $p['contractor_id'] ?? null);
                $finishesContractor = getEntityName($pdo, $p['finishes_contractor_id'] ?? $p['turnkey_contractor_id'] ?? null);
                
                // Progress Logic
                if ($stage === 'Demolition') $progressText = "Demolition is " . ($mob['demo_status'] ?? 'Pending');
                elseif ($stage === 'Excavation') $progressText = "Excavation is " . ($mob['excavation_status'] ?? 'Pending');
                else $progressText = "Block Execution Avg: {$p['avg_prog']}% Complete";

                // OHSA & Docs
                $ohsaStmt = $pdo->prepare("SELECT safety_status, safety_comments FROM project_ohsa_setup WHERE project_id = ?");
                $ohsaStmt->execute([$pid]);
                $ohsa = $ohsaStmt->fetch(PDO::FETCH_ASSOC);
                
                $docsStmt = $pdo->prepare("SELECT title, expiry_date FROM project_documents WHERE project_id = ? AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND alarm_dismissed = 0 ORDER BY expiry_date ASC");
                $docsStmt->execute([$pid]);
                $expDocs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="project-card">
                <div class="proj-title-row">
                    <div>
                        <h3 class="proj-title"><?= htmlspecialchars($p['name']) ?></h3>
                        <div class="proj-loc">📍 <?= htmlspecialchars($loc) ?></div>
                        <?php if ($isGroupReport): ?><div class="proj-client">🏢 Entity: <?= htmlspecialchars($p['client_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="stage-badge"><?= $stage ?></div>
                </div>

                <div class="info-grid">
                    
                    <div class="info-box">
                        <h4>👷 Execution Team</h4>
                        <div class="data-row"><span class="data-label">Project Manager:</span> <span class="data-value"><?= $pmName ?></span></div>
                        <div class="data-row"><span class="data-label">Demo/Excavation:</span> <span class="data-value"><?= $demoContractor ?></span></div>
                        <div class="data-row"><span class="data-label">Civil/Const.:</span> <span class="data-value"><?= $civilContractor ?></span></div>
                        <div class="data-row"><span class="data-label">Finishes/Turnkey:</span> <span class="data-value"><?= $finishesContractor ?></span></div>
                        
                        <div style="margin-top: 10px; border-top: 1px dashed var(--border-glass); padding-top: 5px;">
                            <span class="data-label">PA Approvals:</span><br>
                            <?php if (empty($paRaw)): ?>
                                <span style="color:var(--text-muted); font-size:0.85rem;">None recorded</span>
                            <?php else: foreach($paRaw as $pa): 
                                $status = $pa['status'] ?? $pa['pa_status'] ?? 'Issued/Unknown';
                            ?>
                                <div style="font-size:0.85rem; display:flex; justify-content:space-between; margin-bottom: 2px;">
                                    <span style="color:#fff;"><?= htmlspecialchars($pa['pa_number']) ?></span>
                                    <span style="color:var(--text-muted);"><?= htmlspecialchars($status) ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <h4>📈 Physical Progress</h4>
                        <div class="data-row"><span class="data-label">Stage Status:</span> <span class="data-value" style="color:#22c55e; font-weight:bold;"><?= $progressText ?></span></div>
                        
                        <h4 style="margin-top: 15px;">⚠️ Risks & Compliance</h4>
                        <?php if ($ohsa && in_array($ohsa['safety_status'], ['Red', 'Yellow'])): ?>
                            <div class="alert-box">
                                <strong>H&S Alert (<?= $ohsa['safety_status'] ?>):</strong><br>
                                <?= nl2br(htmlspecialchars($ohsa['safety_comments'] ?? 'No comments provided.')) ?>
                            </div>
                        <?php else: ?>
                            <div class="data-row"><span class="data-label">H&S Status:</span> <span class="data-value" style="color:#22c55e;">Clear / Compliant</span></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($expDocs)): ?>
                            <div class="warn-box" style="margin-top: 10px;">
                                <strong>Expiring Documents:</strong>
                                <ul style="margin: 5px 0 0 0; padding-left: 20px;">
                                    <?php foreach($expDocs as $doc): 
                                        $days = (new DateTime())->diff(new DateTime($doc['expiry_date']))->format('%r%a');
                                        $dText = $days < 0 ? "Expired" : "Exp in {$days}d";
                                    ?>
                                        <li><?= htmlspecialchars($doc['title']) ?> (<i><?= $dText ?></i>)</li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div class="data-row" style="margin-top: 5px;"><span class="data-label">Documentation:</span> <span class="data-value" style="color:#22c55e;">Up to date</span></div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
            <?php endforeach; endif; ?>

            <?php if (!empty($completedProjects)): ?>
                <div class="completed-section">
                    <h3>✅ Handed Over / Completed Projects</h3>
                    <ul class="completed-list">
                        <?php foreach ($completedProjects as $cp): ?>
                            <li>
                                <strong><?= htmlspecialchars($cp['name']) ?></strong> <i>(<?= $cp['stage'] ?>)</i>
                                <?php if ($isGroupReport): ?> <span style="font-size: 0.8rem; color: #a855f7; margin-left: 10px;">[<?= htmlspecialchars($cp['client_name']) ?>]</span> <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

        </div>
    <?php endif; ?>

<?php endif; ?>

</body>
</html>

