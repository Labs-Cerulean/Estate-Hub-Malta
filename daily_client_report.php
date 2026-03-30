<?php
require_once 'init.php';
require_once 'session-check.php';

// Ensure user has adequate permissions
$userId = getCurrentUserId();
$isAdmin = isAdmin();
$role = $_SESSION['role'] ?? 'viewer';

if (!$isAdmin && !in_array($role, ['director', 'system_manager', 'project_manager'])) {
    die("Unauthorized access. Executive level reporting only.");
}

// AUTO-DEPLOY DATABASE UPDATES FOR ESCALATION BLOCKER
try {
    $pdo->exec("ALTER TABLE projects ADD COLUMN is_blocked TINYINT(1) DEFAULT 0");
    $pdo->exec("ALTER TABLE projects ADD COLUMN blocked_reason TEXT");
    $pdo->exec("ALTER TABLE projects ADD COLUMN blocked_resolution TEXT");
    $pdo->exec("ALTER TABLE projects ADD COLUMN blocker_status VARCHAR(20) DEFAULT 'None'");
    // Seamlessly migrate existing checkbox data
    $pdo->exec("UPDATE projects SET blocker_status = 'Active' WHERE is_blocked = 1 AND blocker_status = 'None'");
} catch(PDOException $e) {}

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

$gozoLocalities = ['Nadur', 'Xaghra', 'Victoria', 'Rabat (Gozo)', 'Qala', 'Zebbug', 'Xewkija', 'Ghajnsielem', 'Gharb', 'Kercem', 'Munxar', 'San Lawrenz', 'Sannat', 'Fontana', 'Ghasri', 'Marsalforn', 'Xlendi', 'Mgarr'];

// ==========================================
// SUPER-FINDER Helper
// ==========================================
function getEntityName($pdo, $id, $hint = 'auto') {
    if (empty($id)) return "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>";
    
    $queries = [
        "SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?",
        "SELECT name FROM professionals WHERE id = ?",
        "SELECT name FROM subcontractors WHERE id = ?",
        "SELECT name FROM clients WHERE id = ?"
    ];

    if ($hint === 'user') array_unshift($queries, $queries[0]);
    if ($hint === 'professional') array_unshift($queries, $queries[1]);

    foreach ($queries as $q) {
        try {
            $stmt = $pdo->prepare($q);
            $stmt->execute([$id]);
            if ($name = $stmt->fetchColumn()) return htmlspecialchars($name);
        } catch (Exception $e) {}
    }
    return "<span style='color:var(--text-muted); font-style:italic;'>Unassigned (ID: $id)</span>";
}

$pageTitle = 'Executive Daily Report';
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
        
        .report-header { text-align: center; margin-bottom: 2rem; padding-bottom: 1.5rem; position: relative; }
        .report-header h1 { color: var(--primary-color); margin: 0 0 10px 0; font-size: 2.2rem; text-transform: uppercase; letter-spacing: 1px; }
        
        .executive-dash { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 3rem; background: var(--bg-panel); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-glass); box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .kpi-box { text-align: center; padding: 1rem; background: rgba(0,0,0,0.2); border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); }
        .kpi-val { font-size: 2rem; font-weight: 900; color: #fff; margin-bottom: 5px; }
        .kpi-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: bold; letter-spacing: 1px; }

        .client-section { margin-bottom: 4rem; }
        .client-header { background: linear-gradient(90deg, rgba(99, 102, 241, 0.2), rgba(139, 92, 246, 0.2)); padding: 1.5rem 2rem; border-radius: 12px 12px 0 0; border: 1px solid var(--border-glass); border-bottom: none; }
        .client-header h2 { margin: 0; color: #fff; font-size: 1.8rem; text-transform: uppercase; letter-spacing: 1px; }
        
        .island-divider { background: var(--bg-panel); border: 1px solid var(--border-glass); border-top: none; padding: 1rem 2rem; text-align: center; }
        .island-divider h2 { margin: 0; color: var(--primary-color); font-size: 1.8rem; text-transform: uppercase; letter-spacing: 2px; }

        .phase-header { background: rgba(0,0,0,0.3); padding: 1rem 2rem; border-bottom: 1px solid var(--border-glass); border-top: 1px solid var(--border-glass); }
        .phase-header h3 { margin: 0; color: #fff; font-size: 1.2rem; }

        .project-card { background: var(--bg-panel); border: 1px solid var(--border-glass); border-top: none; display: flex; flex-direction: column; }
        .project-card:nth-child(even) { background: rgba(255,255,255,0.02); }
        
        .card-inner { display: flex; flex-wrap: wrap; }
        .card-main { flex: 3; padding: 1.5rem; min-width: 300px; border-right: 1px solid var(--border-glass); }
        .card-action { flex: 1; padding: 1.5rem; min-width: 250px; display: flex; flex-direction: column; justify-content: center; }
        
        .proj-title-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .proj-title { font-size: 1.4rem; font-weight: 800; color: var(--primary-color); margin: 0 0 5px 0; }
        .proj-loc { font-size: 0.85rem; color: var(--text-muted); }
        
        .stage-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; background: rgba(14, 165, 233, 0.1); color: #0ea5e9; border: 1px solid #0ea5e9; }
        
        .data-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem; font-size: 0.85rem; }
        .data-label { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.75rem; display: block; margin-bottom: 2px; }
        .data-value { color: #fff; font-weight: 500; }
        
        .prog-bar-container { background: rgba(0,0,0,0.3); border-radius: 4px; height: 8px; width: 100%; margin-top: 5px; overflow: hidden; }
        .prog-bar-fill { height: 100%; border-radius: 4px; }
        
        .action-box { padding: 1rem; border-radius: 8px; font-size: 0.85rem; }
        .action-red { background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; color: #fca5a5; }
        .action-red strong { color: #ef4444; font-size: 1rem; display: block; margin-bottom: 10px; text-transform: uppercase; }
        .action-green { background: rgba(34, 197, 94, 0.05); border: 1px solid rgba(34, 197, 94, 0.3); color: #22c55e; text-align: center; padding: 2rem 1rem; }
        
        .action-list { margin: 0; padding-left: 20px; }
        .action-list li { margin-bottom: 5px; }

        .list-section { background: var(--bg-panel); padding: 1.5rem 2rem; border: 1px solid var(--border-glass); border-top: none; }
        .list-section h3 { margin: 0 0 15px 0; font-size: 1.1rem; }
        .mini-list { margin: 0; padding-left: 20px; color: var(--text-muted); font-size: 0.9rem; }
        .mini-list li { margin-bottom: 8px; }
        
        .btn-print { position: fixed; bottom: 30px; right: 30px; background: var(--primary-color); color: white; border: none; padding: 15px 25px; border-radius: 30px; font-weight: bold; font-size: 1.1rem; cursor: pointer; box-shadow: 0 10px 25px rgba(99, 102, 241, 0.5); z-index: 1000; transition: transform 0.2s; }
        .btn-print:hover { transform: scale(1.05); }
        .btn-back { position: absolute; left: 0; top: 0; background: rgba(255,255,255,0.1); color: var(--text-muted); padding: 8px 15px; border-radius: 6px; text-decoration: none; font-size: 0.9rem; transition: 0.2s; }
        .btn-back:hover { background: rgba(255,255,255,0.2); color: #fff; }

        @media print {
            body { background: #fff !important; color: #000 !important; padding: 0; }
            .btn-print, .btn-back, .navbar, .sidebar { display: none !important; }
            .executive-dash { box-shadow: none; border: 2px solid #000; background: #f9fafb !important; }
            .kpi-val { color: #000 !important; }
            .kpi-label { color: #555 !important; }
            .client-header { background: #e5e7eb !important; border: 2px solid #000; }
            .client-header h2 { color: #000 !important; }
            .island-divider { background: #fff !important; border-left: 2px solid #000; border-right: 2px solid #000; }
            .phase-header { background: #f3f4f6 !important; border-top: 2px solid #000; border-bottom: 2px solid #000; }
            .phase-header h3 { color: #000 !important; }
            .project-card { border-left: 2px solid #000; border-right: 2px solid #000; border-bottom: 1px solid #000; page-break-inside: avoid; }
            .card-main { border-right: 1px solid #000; }
            .action-red { border: 2px solid #ef4444 !important; background: #fff !important; color: #000 !important; }
            .action-red strong { color: #ef4444 !important; }
            .action-green { border: 1px solid #22c55e !important; color: #22c55e !important; }
            .data-value { color: #000 !important; }
            .data-label { color: #555 !important; }
            .list-section { border: 2px solid #000; border-top: none; }
        }
    </style>
</head>
<body>

<?php if (!$selectedClientId): ?>
    <div class="selection-container">
        <h1>🏢 Executive Daily Report</h1>
        <p>Select a Client (or Combined Group) to generate an actionable status report.</p>
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
    $selectedClientName = "Unknown Client";
    $projects = [];
    $isGroupReport = false;

    if (strpos($selectedClientId, 'group_') === 0) {
        $isGroupReport = true;
        $groupKey = str_replace('group_', '', $selectedClientId);
        if (isset($clientGroups[$groupKey])) {
            $selectedClientName = $clientGroups[$groupKey]['label'];
            $searchTerm = $clientGroups[$groupKey]['search'];
            $matchedClientIds = [];
            foreach ($clients as $c) {
                if (stripos($c['name'], $searchTerm) !== false) $matchedClientIds[] = $c['id'];
            }
            if (!empty($matchedClientIds)) {
                $placeholders = implode(',', array_fill(0, count($matchedClientIds), '?'));
                $stmt = $pdo->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id WHERE p.clientid IN ($placeholders) ORDER BY c.name ASC, p.name ASC");
                $stmt->execute($matchedClientIds);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } else {
        $selectedClientId = (int)$selectedClientId;
        foreach ($clients as $c) {
            if ($c['id'] == $selectedClientId) { $selectedClientName = $c['name']; break; }
        }
        $stmt = $pdo->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id WHERE p.clientid = ? ORDER BY p.name ASC");
        $stmt->execute([$selectedClientId]);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // KPI Counters
    $kpiActive = 0; $kpiMob = 0; $kpiAlerts = 0; $kpiHold = 0; $kpiCompleted = 0; $kpiEscalated = 0;

    $islandData = [
        '🇲🇹 MALTA' => ['mob' => [], 'exec' => [], 'completed' => [], 'on_hold' => []],
        '⛴️ GOZO' => ['mob' => [], 'exec' => [], 'completed' => [], 'on_hold' => []]
    ];

    $stagesEnum = ['Mobilisation'=>4, 'Demolition'=>5, 'Excavation'=>6, 'Construction'=>7, 'Finishes'=>8, 'Compliance'=>9, 'Condominium'=>10];

    foreach ($projects as $p) {
        $status = $p['status'] ?? $p['project_status'] ?? 'Active';
        if ($status === 'Withdrawn') continue;

        $locStr = trim(($p['street_name'] ?? '') . ', ' . ($p['city'] ?? ''), ', ');
        if (empty($locStr)) $locStr = 'Location not specified';
        $p['formatted_location'] = $locStr;

        $isGozo = false;
        $regionStr = $p['region'] ?? $p['island'] ?? '';
        if (stripos($regionStr, 'gozo') !== false) {
            $isGozo = true;
        } else {
            foreach ($gozoLocalities as $gl) {
                if (stripos($locStr, $gl) !== false) { $isGozo = true; break; }
            }
        }
        $targetIsland = $isGozo ? '⛴️ GOZO' : '🇲🇹 MALTA';

        if ($status === 'On-Hold' || $status === 'On Hold') {
            $kpiHold++;
            $islandData[$targetIsland]['on_hold'][] = $p;
            continue;
        }

        $stage = getAccurateProjectStage($pdo, $p['id']);
        
        if (in_array($stage, $completedStages) || $status === 'Completed') {
            $kpiCompleted++;
            $p['calculated_stage'] = $stage;
            $islandData[$targetIsland]['completed'][] = $p;
            continue;
        }

        if (in_array($stage, $earlyStages)) continue; // Skip Pre-execution
        
        if (($p['blocker_status'] ?? 'None') === 'Active') $kpiEscalated++;

        // Base fetch
        $mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
        $mobStmt->execute([$p['id']]);
        $mob = $mobStmt->fetch(PDO::FETCH_ASSOC);
        $p['calculated_stage'] = $stage;
        $p['mob_data'] = $mob;
        
        // Fetch OHSA early for KPI
        $ohsaStmt = $pdo->prepare("SELECT safety_status, safety_comments FROM project_ohsa_setup WHERE project_id = ?");
        $ohsaStmt->execute([$p['id']]);
        $ohsa = $ohsaStmt->fetch(PDO::FETCH_ASSOC);
        $p['ohsa_data'] = $ohsa;
        if ($ohsa && in_array($ohsa['safety_status'], ['Red', 'Yellow'])) $kpiAlerts++;

        if ($stage === 'Mobilisation') {
            $kpiMob++;
            $missing = 0;
            $pendingMob = [];
            if (!in_array($mob['geological_test'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Geological Test";
            if (!in_array($mob['condition_reports'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Condition Reports";
            if (!in_array($mob['method_statements'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Method Statements";
            if (!in_array($mob['insurance_status'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Insurance Status";
            if (!in_array($mob['responsibility_form'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Responsibility Form";
            if (($mob['mob_demolition'] ?? 'No') === 'No') $pendingMob[] = "Demolition Clearance";
            if (($mob['mob_excavation'] ?? 'No') === 'No') $pendingMob[] = "Excavation Clearance";
            if (($mob['mob_construction'] ?? 'No') === 'No') $pendingMob[] = "Construction Clearance";
            
            $p['pending_mob'] = $pendingMob;
            $p['missing_mob_count'] = count($pendingMob);
            $islandData[$targetIsland]['mob'][] = $p;
        } else {
            $kpiActive++;
            $sStmt = $pdo->prepare("SELECT construction_status FROM block_levels bl JOIN project_blocks pb ON bl.block_id = pb.id WHERE pb.project_id = ?");
            $sStmt->execute([$p['id']]);
            $levels = $sStmt->fetchAll(PDO::FETCH_COLUMN);
            $structProg = 0;
            if (count($levels) > 0) {
                $score = 0;
                foreach($levels as $st) {
                    if ($st === 'Complete') $score += 100;
                    elseif (in_array($st, ['In Progress', 'Ongoing CP'])) $score += 50;
                }
                $structProg = round($score / count($levels), 1);
            }
            $p['struct_prog'] = $structProg;

            $fStmt = $pdo->prepare("SELECT AVG(progress) FROM project_blocks WHERE project_id = ?");
            $fStmt->execute([$p['id']]);
            $finProg = $fStmt->fetchColumn();
            $p['fin_prog'] = $finProg !== null ? round((float)$finProg, 1) : 0;

            $stageScore = $stagesEnum[$stage] ?? 5;
            $p['exec_score'] = ($stageScore * 10000) + ($p['struct_prog'] * 100) + $p['fin_prog'];
            
            $islandData[$targetIsland]['exec'][] = $p;
        }
    }

    foreach ($islandData as $islandKey => &$data) {
        usort($data['mob'], function($a, $b) { return $b['missing_mob_count'] <=> $a['missing_mob_count']; }); 
        usort($data['exec'], function($a, $b) { return $a['exec_score'] <=> $b['exec_score']; }); 
    }
    unset($data);
?>

    <div class="report-header">
        <a href="daily_client_report.php" class="btn-back">&larr; Select Different Client</a>
        <h1>Executive Summary: <?= htmlspecialchars($selectedClientName) ?></h1>
        <p>Generated on <strong><?= date('l, d F Y') ?></strong> at <strong><?= date('H:i') ?></strong></p>
    </div>

    <div class="executive-dash">
        <div class="kpi-box" style="border-bottom: 3px solid #3b82f6;">
            <div class="kpi-val"><?= $kpiActive ?></div>
            <div class="kpi-label">Sites in Execution</div>
        </div>
        <div class="kpi-box" style="border-bottom: 3px solid #0ea5e9;">
            <div class="kpi-val"><?= $kpiMob ?></div>
            <div class="kpi-label">Sites in Mobilisation</div>
        </div>
        <div class="kpi-box" style="border-bottom: 3px solid <?= $kpiEscalated > 0 ? '#dc2626' : '#22c55e' ?>;">
            <div class="kpi-val" style="color: <?= $kpiEscalated > 0 ? '#dc2626' : '#22c55e' ?>;"><?= $kpiEscalated ?></div>
            <div class="kpi-label">Escalated Blockers</div>
        </div>
        <div class="kpi-box" style="border-bottom: 3px solid <?= $kpiAlerts > 0 ? '#ef4444' : '#22c55e' ?>;">
            <div class="kpi-val" style="color: <?= $kpiAlerts > 0 ? '#ef4444' : '#22c55e' ?>;"><?= $kpiAlerts ?></div>
            <div class="kpi-label">Safety Alerts</div>
        </div>
        <div class="kpi-box" style="border-bottom: 3px solid #f59e0b;">
            <div class="kpi-val"><?= $kpiHold ?></div>
            <div class="kpi-label">Blocked / On Hold</div>
        </div>
        <div class="kpi-box" style="border-bottom: 3px solid #22c55e;">
            <div class="kpi-val"><?= $kpiCompleted ?></div>
            <div class="kpi-label">Completed</div>
        </div>
    </div>

    <button class="btn-print" onclick="window.print()">🖨️ Print Report</button>

    <?php if ($kpiActive == 0 && $kpiMob == 0 && $kpiHold == 0 && $kpiCompleted == 0): ?>
        <div style="text-align: center; padding: 4rem; background: var(--bg-panel); border-radius: 12px; border: 1px solid var(--border-glass);">
            <h2 style="color: var(--text-muted);">No Activity Found</h2>
            <p>There are currently no tracked projects for <strong><?= htmlspecialchars($selectedClientName) ?></strong>.</p>
        </div>
    <?php else: ?>
        <div class="client-section">
            
            <?php foreach ($islandData as $islandName => $data): 
                if (empty($data['mob']) && empty($data['exec']) && empty($data['completed']) && empty($data['on_hold'])) continue;
            ?>
            
            <div class="island-divider">
                <h2><?= $islandName ?></h2>
            </div>

            <?php if (!empty($data['mob'])): ?>
                <div class="phase-header">
                    <h3>🚧 Phase 1: Mobilisation (<?= count($data['mob']) ?> Sites)</h3>
                    <div class="phase-desc">Sites ordered from most blocked to most ready.</div>
                </div>
                
                <?php foreach ($data['mob'] as $p): 
                    $pid = $p['id'];
                    $paStmt = $pdo->prepare("SELECT * FROM project_pa_numbers WHERE project_id = ?");
                    $paStmt->execute([$pid]);
                    $paRaw = $paStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $archId = null; $structId = null;
                    foreach ($paRaw as $pa) {
                        if (!empty($pa['architect_id']) && !$archId) $archId = $pa['architect_id'];
                        if (!empty($pa['structural_engineer_id']) && !$structId) $structId = $pa['structural_engineer_id'];
                    }
                    $archName = getEntityName($pdo, $archId, 'professional');
                    $structName = getEntityName($pdo, $structId, 'professional');
                    
                    $docsStmt = $pdo->prepare("SELECT title, expiry_date FROM project_documents WHERE project_id = ? AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND alarm_dismissed = 0 ORDER BY expiry_date ASC");
                    $docsStmt->execute([$pid]);
                    $expDocs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $hasBlocker = ($p['blocker_status'] ?? 'None') === 'Active';
                    $blockerCleared = ($p['blocker_status'] ?? 'None') === 'Cleared';
                    $hasActions = !empty($p['pending_mob']) || !empty($expDocs);
                ?>
                <div class="project-card">
                    <div class="card-inner">
                        <div class="card-main">
                            <div class="proj-title-row" style="margin-bottom: 5px;">
                                <div>
                                    <h3 class="proj-title"><?= htmlspecialchars($p['name']) ?></h3>
                                    <div class="proj-loc">📍 <?= htmlspecialchars($p['formatted_location']) ?></div>
                                    <?php if ($isGroupReport): ?><div class="proj-client">🏢 Entity: <?= htmlspecialchars($p['client_name']) ?></div><?php endif; ?>
                                </div>
                                <div class="stage-badge">Mobilisation</div>
                            </div>
                            
                            <div class="data-grid">
                                <div>
                                    <span class="data-label">Periti (Architect & Engineer)</span>
                                    <span class="data-value"><?= $archName ?> | <?= $structName ?></span>
                                </div>
                                <div>
                                    <span class="data-label">PA Numbers</span>
                                    <span class="data-value">
                                        <?php if (empty($paRaw)): ?>None recorded
                                        <?php else: foreach($paRaw as $pa): ?>
                                            <?= htmlspecialchars($pa['pa_number']) ?> (<?= htmlspecialchars($pa['status'] ?? $pa['pa_status'] ?? 'Unknown') ?>)<br>
                                        <?php endforeach; endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-action">
                            <?php if ($hasBlocker): ?>
                                <div class="action-box action-red" style="background: rgba(220, 38, 38, 0.15); border: 2px solid #dc2626; margin-bottom: 10px;">
                                    <strong style="color: #dc2626; font-size: 1.1rem; display:block; margin-bottom:10px;">🚨 ESCALATED BLOCKER</strong>
                                    <div style="margin-bottom: 8px; font-size: 0.85rem;"><span style="color:#fff; font-weight:bold;">Reason:</span><br><span style="color:#fecaca;"><?= nl2br(htmlspecialchars($p['blocked_reason'])) ?></span></div>
                                    <div style="font-size: 0.85rem;"><span style="color:#fff; font-weight:bold;">Resolution:</span><br><span style="color:#fecaca;"><?= nl2br(htmlspecialchars($p['blocked_resolution'])) ?></span></div>
                                </div>
                            <?php elseif ($blockerCleared): ?>
                                <div class="action-box action-green" style="background: rgba(34, 197, 94, 0.1); border: 2px solid #22c55e; margin-bottom: 10px; text-align: left; padding: 1rem;">
                                    <strong style="color: #22c55e; font-size: 1.1rem; display:block; margin-bottom:10px;">✅ BLOCKER CLEARED</strong>
                                    <div style="margin-bottom: 8px; font-size: 0.85rem;"><span style="color:#fff; font-weight:bold;">Reason:</span><br><span style="color:#bbf7d0;"><?= nl2br(htmlspecialchars($p['blocked_reason'])) ?></span></div>
                                    <div style="font-size: 0.85rem;"><span style="color:#fff; font-weight:bold;">Resolution:</span><br><span style="color:#bbf7d0;"><?= nl2br(htmlspecialchars($p['blocked_resolution'])) ?></span></div>
                                </div>
                            <?php endif; ?>

                            <?php if ($hasActions): ?>
                                <div class="action-box action-red">
                                    <strong>Action Required</strong>
                                    <?php if (!empty($p['pending_mob'])): ?>
                                        <ul class="action-list">
                                            <?php foreach($p['pending_mob'] as $req): ?>
                                                <li>Missing: <?= $req ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($expDocs)): ?>
                                        <div style="margin-top: 10px; border-top: 1px solid rgba(239,68,68,0.3); padding-top: 5px;">
                                            Expiring Docs: <?= count($expDocs) ?> items
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (!$hasBlocker): ?>
                                <div class="action-box action-green" style="text-align: center; padding: 2rem 1rem;">
                                    <div style="font-size: 1.5rem; font-weight: 900; letter-spacing: 1px;">🟢 ON TRACK</div>
                                    <div style="font-size: 0.8rem; margin-top: 5px;">Ready to commence.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($data['exec'])): ?>
                <div class="phase-header">
                    <h3>🏗️ Phase 2: Active Execution (<?= count($data['exec']) ?> Sites)</h3>
                    <div class="phase-desc">Ordered from newly started to near completion.</div>
                </div>
                
                <?php foreach ($data['exec'] as $p): 
                    $pid = $p['id'];
                    $stage = $p['calculated_stage'];
                    $ohsa = $p['ohsa_data'];

                    $paStmt = $pdo->prepare("SELECT * FROM project_pa_numbers WHERE project_id = ?");
                    $paStmt->execute([$pid]);
                    $paRaw = $paStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $archId = null; $structId = null;
                    foreach ($paRaw as $pa) {
                        if (!empty($pa['architect_id']) && !$archId) $archId = $pa['architect_id'];
                        if (!empty($pa['structural_engineer_id']) && !$structId) $structId = $pa['structural_engineer_id'];
                    }
                    $archName = getEntityName($pdo, $archId, 'professional');
                    $structName = getEntityName($pdo, $structId, 'professional');
                    
                    // Fetch ALL PMs assigned to project
                    $pmStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM users u JOIN user_project_access upa ON u.id = upa.user_id WHERE upa.project_id = ? AND u.role IN ('project_manager', 'site_technical_officer')");
                    $pmStmt->execute([$pid]);
                    $pms = $pmStmt->fetchAll(PDO::FETCH_COLUMN);
                    $pmName = !empty($pms) ? implode(', ', array_map('htmlspecialchars', $pms)) : getEntityName($pdo, $p['project_manager_id'] ?? null, 'user');

                    // Active Subcontractors
                    $contStmt = $pdo->prepare("SELECT DISTINCT s.name FROM subcontractors s JOIN subcontractor_works w ON s.id = w.subcontractor_id WHERE w.project_id = ?");
                    $contStmt->execute([$pid]);
                    $activeContractors = $contStmt->fetchAll(PDO::FETCH_COLUMN);
                    $contractorStr = !empty($activeContractors) ? htmlspecialchars(implode(', ', $activeContractors)) : "<span style='color:var(--text-muted); font-style:italic;'>No executing contractors</span>";

                    $docsStmt = $pdo->prepare("SELECT title, expiry_date FROM project_documents WHERE project_id = ? AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND alarm_dismissed = 0 ORDER BY expiry_date ASC");
                    $docsStmt->execute([$pid]);
                    $expDocs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

                    $hasBlocker = ($p['blocker_status'] ?? 'None') === 'Active';
                    $blockerCleared = ($p['blocker_status'] ?? 'None') === 'Cleared';
                    $hasRisk = ($ohsa && in_array($ohsa['safety_status'], ['Red', 'Yellow'])) || !empty($expDocs);
                ?>
                <div class="project-card">
                    <div class="card-inner">
                        <div class="card-main">
                            <div class="proj-title-row" style="margin-bottom: 5px;">
                                <div>
                                    <h3 class="proj-title"><?= htmlspecialchars($p['name']) ?></h3>
                                    <div class="proj-loc">📍 <?= htmlspecialchars($p['formatted_location']) ?></div>
                                    <?php if ($isGroupReport): ?><div class="proj-client">🏢 Entity: <?= htmlspecialchars($p['client_name']) ?></div><?php endif; ?>
                                </div>
                                <div class="stage-badge"><?= $stage ?></div>
                            </div>
                            
                            <?php if (in_array($stage, ['Demolition', 'Excavation'])): ?>
                                <div style="margin: 15px 0;">
                                    <span class="data-label" style="display:inline;">Physical Progress:</span> 
                                    <strong style="color:#22c55e;"> <?= $stage ?> is <?= $p['mob_data'][strtolower($stage).'_status'] ?? 'Pending' ?></strong>
                                </div>
                            <?php else: ?>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 15px 0;">
                                    <div>
                                        <div style="display:flex; justify-content:space-between;">
                                            <span class="data-label">Structural Build</span>
                                            <span class="data-value" style="color:#3b82f6;"><?= $p['struct_prog'] ?>%</span>
                                        </div>
                                        <div class="prog-bar-container"><div class="prog-bar-fill" style="width: <?= $p['struct_prog'] ?>%; background: #3b82f6;"></div></div>
                                    </div>
                                    <div>
                                        <div style="display:flex; justify-content:space-between;">
                                            <span class="data-label">Finishes Scope</span>
                                            <span class="data-value" style="color:#a855f7;"><?= $p['fin_prog'] ?>%</span>
                                        </div>
                                        <div class="prog-bar-container"><div class="prog-bar-fill" style="width: <?= $p['fin_prog'] ?>%; background: #a855f7;"></div></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="data-grid" style="margin-top: 15px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 10px;">
                                <div>
                                    <span class="data-label">Project Management</span>
                                    <span class="data-value"><?= $pmName ?></span>
                                </div>
                                <div>
                                    <span class="data-label">Periti Team</span>
                                    <span class="data-value"><?= $archName ?> | <?= $structName ?></span>
                                </div>
                                <div style="grid-column: span 2;">
                                    <span class="data-label">Active Linked Contractors</span>
                                    <span class="data-value"><?= $contractorStr ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-action">
                            <?php if ($hasBlocker): ?>
                                <div class="action-box action-red" style="background: rgba(220, 38, 38, 0.15); border: 2px solid #dc2626; margin-bottom: 10px;">
                                    <strong style="color: #dc2626; font-size: 1.1rem; display:block; margin-bottom:10px;">🚨 ESCALATED BLOCKER</strong>
                                    <div style="margin-bottom: 8px; font-size: 0.85rem;"><span style="color:#fff; font-weight:bold;">Reason:</span><br><span style="color:#fecaca;"><?= nl2br(htmlspecialchars($p['blocked_reason'])) ?></span></div>
                                    <div style="font-size: 0.85rem;"><span style="color:#fff; font-weight:bold;">Resolution:</span><br><span style="color:#fecaca;"><?= nl2br(htmlspecialchars($p['blocked_resolution'])) ?></span></div>
                                </div>
                            <?php elseif ($blockerCleared): ?>
                                <div class="action-box action-green" style="background: rgba(34, 197, 94, 0.1); border: 2px solid #22c55e; margin-bottom: 10px; text-align: left; padding: 1rem;">
                                    <strong style="color: #22c55e; font-size: 1.1rem; display:block; margin-bottom:10px;">✅ BLOCKER CLEARED</strong>
                                    <div style="margin-bottom: 8px; font-size: 0.85rem;"><span style="color:#fff; font-weight:bold;">Reason:</span><br><span style="color:#bbf7d0;"><?= nl2br(htmlspecialchars($p['blocked_reason'])) ?></span></div>
                                    <div style="font-size: 0.85rem;"><span style="color:#fff; font-weight:bold;">Resolution:</span><br><span style="color:#bbf7d0;"><?= nl2br(htmlspecialchars($p['blocked_resolution'])) ?></span></div>
                                </div>
                            <?php endif; ?>

                            <?php if ($hasRisk): ?>
                                <div class="action-box action-red">
                                    <strong>CRITICAL RISK</strong>
                                    <?php if ($ohsa && in_array($ohsa['safety_status'], ['Red', 'Yellow'])): ?>
                                        <div style="margin-bottom: 10px;">
                                            <span style="color:#f59e0b; font-weight:bold;">H&S (<?= $ohsa['safety_status'] ?>):</span><br>
                                            <?= nl2br(htmlspecialchars($ohsa['safety_comments'] ?? 'No comments.')) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($expDocs)): ?>
                                        <div style="border-top: 1px solid rgba(239,68,68,0.3); padding-top: 5px;">
                                            <span style="color:#fca5a5; font-weight:bold;">Expiring Docs:</span>
                                            <ul class="action-list" style="margin-top:2px;">
                                                <?php foreach($expDocs as $doc) echo "<li>" . htmlspecialchars($doc['title']) . "</li>"; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (!$hasBlocker): ?>
                                <div class="action-box action-green" style="text-align: center; padding: 2rem 1rem;">
                                    <div style="font-size: 1.5rem; font-weight: 900; letter-spacing: 1px;">🟢 ON TRACK</div>
                                    <div style="font-size: 0.8rem; margin-top: 5px;">Safety & Docs Clear.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($data['completed'])): ?>
                <div class="list-section">
                    <h3 style="color: #22c55e;">✅ Handed Over / Completed Projects (<?= count($data['completed']) ?>)</h3>
                    <ul class="mini-list">
                        <?php foreach ($data['completed'] as $cp): ?>
                            <li>
                                <strong><?= htmlspecialchars($cp['name']) ?></strong> 
                                <?php if ($isGroupReport): ?> <span style="color: #a855f7;">[<?= htmlspecialchars($cp['client_name']) ?>]</span> <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($data['on_hold'])): ?>
                <div class="list-section" style="border-top: 1px dashed var(--border-glass);">
                    <h3 style="color: #f59e0b;">⏸️ Projects On Hold / Blocked (<?= count($data['on_hold']) ?>)</h3>
                    <ul class="mini-list">
                        <?php foreach ($data['on_hold'] as $hp): ?>
                            <li style="margin-bottom: 10px;">
                                <strong><?= htmlspecialchars($hp['name']) ?></strong>
                                <?php if ($isGroupReport): ?> <span style="color: #a855f7;">[<?= htmlspecialchars($hp['client_name']) ?>]</span> <?php endif; ?>
                                
                                <?php if (($hp['blocker_status'] ?? 'None') === 'Active'): ?>
                                    <div style="margin-top: 4px; font-size: 0.85rem; color: #ef4444; border-left: 2px solid #ef4444; padding-left: 8px;">
                                        <strong>🚨 Escalated Blocker:</strong> <?= htmlspecialchars($hp['blocked_reason']) ?>
                                    </div>
                                <?php elseif (($hp['blocker_status'] ?? 'None') === 'Cleared'): ?>
                                    <div style="margin-top: 4px; font-size: 0.85rem; color: #22c55e; border-left: 2px solid #22c55e; padding-left: 8px;">
                                        <strong>✅ Blocker Cleared:</strong> <?= htmlspecialchars($hp['blocked_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php endforeach; // End Island Loop ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

</body>
</html>
