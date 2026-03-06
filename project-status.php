<?php
require_once 'init.php';
require_once 'session-check.php';

$projectId = $_GET['id'] ?? null;
if (!$projectId) { header('Location: dashboard.php'); exit; }

// Fetch Core Data
$project = getProjectWithClient($pdo, $projectId);
if (!$project) { header('Location: dashboard.php'); exit; }

$stage = deriveProjectStage($pdo, $projectId);

// Fetch DB Records
$paStmt = $pdo->prepare("SELECT * FROM project_pa_numbers WHERE project_id = ?");
$paStmt->execute([$projectId]);
$paNumbers = $paStmt->fetchAll(PDO::FETCH_ASSOC);

$mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
$mobStmt->execute([$projectId]);
$mobilisation = $mobStmt->fetch(PDO::FETCH_ASSOC);

$blocksStmt = $pdo->prepare("SELECT b.id AS block_id, b.block_name, b.finishes_overall_status, b.finishes_data, b.compliance_submitted, b.compliance_certified, b.condominium_formed, b.cp_meters_installed, l.level_name, l.construction_status FROM project_blocks b LEFT JOIN block_levels l ON b.id = l.block_id WHERE b.project_id = ? ORDER BY b.id ASC, l.level_number ASC");
$blocksStmt->execute([$projectId]);
$blockLevels = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

// Finishes Label Dictionary
$finLabels = [
    'elec_work' => 'Electrical', 'plumb_work' => 'Plumbing', 'pumps' => 'Pumps/Res.', 'water_tanks' => 'Water Tanks', 
    'lifts' => 'Lifts', 'substation' => 'Substation', 'septic' => 'Septic Tanks', 'garden' => 'Landscaping', 
    'pool' => 'Common Pool', 'fire_det' => 'Fire Detect.', 'fire_fight' => 'Fire Fight.', 'fire_doors' => 'Fire Doors', 
    'intercoms' => 'Intercoms', 'rend_facade' => 'Rend. Façade', 'rend_appogg' => 'Rend. Appogg', 'rend_back' => 'Rend. Back', 
    'rend_cp' => 'Rend. C.P.', 'rend_garage_cp' => 'Rend. Gar. C.P.', 'rend_garages' => 'Rend. Garages', 'marble_cp' => 'Marble C.P.', 
    'marble_sills' => 'Marble Sills', 'waterproof_balc' => 'WP Balconies', 'waterproof_roof' => 'WP Roof', 
    'waterproof_shafts' => 'WP Shafts', 'waterproof_ext' => 'WP Ext.', 'tiling_balc' => 'Tiling Balc.', 
    'gypsum_cp' => 'Gypsum C.P.', 'gypsum_facade' => 'Gypsum Façade', 'fire_apt_doors' => 'Apt Fire Doors', 
    'cp_doors_win' => 'C.P. Doors/Win', 'apt_doors_win' => 'Apt Doors/Win', 'int_railings' => 'Int. Railings', 
    'ext_railings' => 'Ext. Railings', 'terrace_parts' => 'Terrace Parts', 'planters' => 'Planters', 
    'garage_main_door' => 'Gar. Main Door', 'garage_grilles' => 'Gar. Grilles', 'ind_garage_doors' => 'Ind. Gar. Doors', 
    'sewer' => 'Main Sewer', 'other_cladding' => 'Cladding'
];

// --- DYNAMIC RAG ENGINE ---
function getStatusDot($color) {
    return "<span class='rag-dot' style='background-color: var(--{$color}); box-shadow: 0 0 5px var(--{$color});'></span>";
}

$boardTitle = "";
$boardContent = "";
$topMetric1 = ['label' => 'Stage', 'value' => $stage, 'color' => '#ffffff'];
$topMetric2 = ['label' => 'Status', 'value' => $project['project_status'], 'color' => '#ffffff'];

// ==========================================
// WINDOW 1: PRE-EXECUTION & MOBILISATION
// ==========================================
if (in_array($stage, ['Feasibility', 'Tracking', 'Permit', 'Mobilisation'])) {
    $boardTitle = "Mobilisation Checklist (What's Left to Start)";
    
    // Check PA numbers briefly at top
    $paGreen = ['Approved', 'Decided', 'Endorsed', 'Fee Payment'];
    $paRed = ['Refused', 'Revoked/Annulled', 'Suspended', 'Withdrawn'];
    $approvedCount = 0; $pendingPaCount = 0;
    
    foreach ($paNumbers as $pa) {
        if (in_array($pa['pa_status'], $paGreen)) $approvedCount++;
        else $pendingPaCount++;
    }

    if ($pendingPaCount > 0) {
        $boardContent .= "<div class='alert-tight' style='border-left: 3px solid var(--amber);'><strong>Awaiting PA:</strong> $pendingPaCount permit/s are still pending PA approval.</div>";
    }
    
    $mobItems = [];
    if ($project['type'] === 'in-house') { $mobItems['Acquisition Complete'] = $mobilisation['acquisition_complete'] ?? 'No'; }
    $mobItems['Applicant Change'] = $mobilisation['change_of_applicant'] ?? 'NA';
    $mobItems['Geological Test'] = $mobilisation['geological_test'] ?? 'Not Complete';
    $mobItems['Cond. Report Contacts'] = $mobilisation['condition_report_contacts'] ?? 'Not Started';
    $mobItems['Cond. Reports (Exec)'] = $mobilisation['condition_reports'] ?? 'Not Started';
    $mobItems['Method Statements'] = $mobilisation['method_statements'] ?? 'Not Complete';
    $mobItems['Insurance Status'] = $mobilisation['insurance_status'] ?? 'Not Started';
    $mobItems['Pavement Guarantee'] = $mobilisation['pavement_guarantee'] ?? 'Not Started';
    $mobItems['Wellbeing Guarantee'] = $mobilisation['wellbeing_guarantee'] ?? 'Not Started';
    $mobItems['Umbrella Guarantee'] = $mobilisation['umbrella_guarantee'] ?? 'Not Started';
    $mobItems['Responsibility Form'] = $mobilisation['responsibility_form'] ?? 'Not Complete';
    $mobItems['Demolition Clear.'] = $mobilisation['mob_demolition'] ?? 'No';
    $mobItems['Excavation Clear.'] = $mobilisation['mob_excavation'] ?? 'No';
    $mobItems['Construction Clear.'] = $mobilisation['mob_construction'] ?? 'No';
    
    $completedTasks = 0; $totalTasks = count($mobItems); $nextBlocker = "None";

    $boardContent .= "<div class='grid-2col'>";
    foreach ($mobItems as $label => $val) {
        $val = trim($val);
        if ($nextBlocker === "None" && in_array($val, ['No', 'Not Started', 'Not Complete', 'In Process', 'Awaiting Result'])) { $nextBlocker = $label; }

        if ($val === 'NA') {
            $boardContent .= "<div class='action-item'>".getStatusDot('gray')." <div class='txt-grp'><span class='action-label'>{$label}</span><span class='action-value gray'>N/A</span></div></div>";
            $completedTasks++;
        } elseif (in_array($val, ['Yes', 'Complete'])) {
            $boardContent .= "<div class='action-item'>".getStatusDot('green')." <div class='txt-grp'><span class='action-label'>{$label}</span><span class='action-value green'>{$val}</span></div></div>";
            $completedTasks++;
        } elseif (in_array($val, ['In Process', 'Awaiting Result'])) {
            $boardContent .= "<div class='action-item'>".getStatusDot('amber')." <div class='txt-grp'><span class='action-label'>{$label}</span><span class='action-value amber'>{$val}</span></div></div>";
        } else {
            $boardContent .= "<div class='action-item'>".getStatusDot('red')." <div class='txt-grp'><span class='action-label'>{$label}</span><span class='action-value red'>Pending</span></div></div>";
        }
    }
    $boardContent .= "</div>";

    $topMetric1 = ['label' => 'Tasks Cleared', 'value' => "$completedTasks / $totalTasks", 'color' => ($completedTasks == $totalTasks ? '#22c55e' : '#0ea5e9')];
    $topMetric2 = ['label' => 'Current Blocker', 'value' => $nextBlocker, 'color' => ($nextBlocker === 'None' ? '#22c55e' : '#f59e0b')];
} 
// ==========================================
// WINDOW 2: CONSTRUCTION PHASE
// ==========================================
elseif (in_array($stage, ['Demolition', 'Excavation', 'Construction'])) {
    $boardTitle = "Site Execution & Level Progress";
    $blocks = []; $totalLevels = 0; $completedLevels = 0;

    foreach ($blockLevels as $bl) {
        $blocks[$bl['block_name']][] = $bl;
        if (!empty($bl['level_name'])) {
            $totalLevels++;
            if ($bl['construction_status'] === 'Complete') $completedLevels++;
        }
    }

    if (empty($blocks)) {
        $boardContent .= "<div class='action-item'>".getStatusDot('gray')." <span class='action-label'>No Building Blocks Defined</span></div>";
    } else {
        foreach ($blocks as $blockName => $levels) {
            $boardContent .= "<div class='block-header'>🏢 " . htmlspecialchars($blockName) . "</div>";
            $boardContent .= "<div class='grid-2col' style='padding-left: 0.5rem;'>";
            foreach ($levels as $lvl) {
                if (empty($lvl['level_name'])) continue;
                $status = $lvl['construction_status'] ?? 'Pending';
                if ($status === 'Complete') { $color = 'green'; $sText = 'Complete'; }
                elseif ($status === 'In Progress') { $color = 'amber'; $sText = 'Active'; }
                elseif ($status === 'NA') { $color = 'gray'; $sText = 'N/A'; }
                else { $color = 'red'; $sText = 'Pending'; }

                $boardContent .= "<div class='action-item'>".getStatusDot($color)." <div class='txt-grp'><span class='action-label'>{$lvl['level_name']}</span><span class='action-value {$color}'>{$sText}</span></div></div>";
            }
            $boardContent .= "</div>";
        }
    }

    $topMetric1 = ['label' => 'Exec. Stage', 'value' => $stage, 'color' => '#f59e0b'];
    $topMetric2 = ['label' => 'Levels Complete', 'value' => "$completedLevels / $totalLevels", 'color' => ($completedLevels == $totalLevels && $totalLevels > 0 ? '#22c55e' : '#0ea5e9')];
}
// ==========================================
// WINDOW 3: FINISHES PHASE
// ==========================================
elseif ($stage === 'Finishes') {
    $boardTitle = "Finishes & Services Progress";
    $blocks = []; $totalLevels = 0; $completedLevels = 0;
    
    foreach ($blockLevels as $row) {
        $bId = $row['block_id'];
        if (!isset($blocks[$bId])) {
            $blocks[$bId] = ['name' => $row['block_name'], 'status' => $row['finishes_overall_status'] ?? 'Pending', 'data' => $row['finishes_data']];
        }
        if (!empty($row['level_name'])) {
            $totalLevels++; if ($row['construction_status'] === 'Complete') $completedLevels++;
        }
    }

    if (empty($blocks)) {
        $boardContent .= "<div class='action-item'>".getStatusDot('gray')." <span class='action-label'>No Blocks Defined</span></div>";
    } else {
        foreach ($blocks as $b) {
            $sCol = ($b['status'] === 'Complete') ? 'var(--green)' : (($b['status'] === 'In Progress') ? 'var(--amber)' : '#94a3b8');
            $boardContent .= "<div class='block-header' style='margin-bottom:0.25rem;'>🏢 {$b['name']} <span style='font-size:0.7rem; color:{$sCol};'>[" . htmlspecialchars($b['status']) . "]</span></div>";
            
            $finData = json_decode($b['data'] ?? '{}', true) ?: [];
            $inProgress = []; $completedCount = 0; $notStartedCount = 0; $totalValid = 0;
            
            foreach ($finData as $k => $v) {
                if ($v === 'NA' || empty($v)) continue;
                $totalValid++;
                $label = $finLabels[$k] ?? ucwords(str_replace('_', ' ', $k));
                $vLower = strtolower($v);
                
                if (in_array($vLower, ['completed', 'handed over', 'switched on', 'installed'])) $completedCount++;
                elseif (in_array($vLower, ['not started', 'pending'])) $notStartedCount++;
                else $inProgress[] = "<strong>$label</strong> <span class='gray'>($v)</span>";
            }
            
            if ($totalValid === 0) {
                 $boardContent .= "<div class='action-item' style='padding-left: 1rem;'>".getStatusDot('gray')." <span class='action-label gray'>No finishes logged.</span></div>";
            } else {
                if (!empty($inProgress)) {
                    $boardContent .= "<div class='alert-tight' style='border-left: 3px solid var(--amber); margin-left: 1rem;'><strong>Active Works:</strong><br>".implode(", ", $inProgress)."</div>";
                }
                $boardContent .= "<div class='grid-2col' style='padding-left: 1rem;'>";
                if ($completedCount > 0) $boardContent .= "<div class='action-item'>".getStatusDot('green')." <div class='txt-grp'><span class='action-label'>Completed</span><span class='action-value green'>$completedCount items</span></div></div>";
                if ($notStartedCount > 0) $boardContent .= "<div class='action-item'>".getStatusDot('red')." <div class='txt-grp'><span class='action-label'>Pending</span><span class='action-value red'>$notStartedCount items</span></div></div>";
                $boardContent .= "</div>";
            }
        }
    }

    $topMetric1 = ['label' => 'Construction', 'value' => "$completedLevels / $totalLevels Lvls", 'color' => '#22c55e'];
    $topMetric2 = ['label' => 'Current Phase', 'value' => 'Finishes', 'color' => '#f59e0b'];
} 
// ==========================================
// WINDOW 4: COMPLIANCE & HANDOVER
// ==========================================
else {
    $boardTitle = "Compliance & Handover Tasks";
    $blocks = []; 
    foreach ($blockLevels as $row) {
        $bId = $row['block_id'];
        if (!isset($blocks[$bId])) {
            $blocks[$bId] = [
                'name' => $row['block_name'],
                'compliance_submitted' => $row['compliance_submitted'] ?? 'No',
                'compliance_certified' => $row['compliance_certified'] ?? 'No',
                'condominium_formed' => $row['condominium_formed'] ?? 'No',
                'cp_meters_installed' => $row['cp_meters_installed'] ?? 'No'
            ];
        }
    }

    $totalChecks = 0; $completedChecks = 0;

    if (empty($blocks)) {
        $boardContent .= "<div class='action-item'>".getStatusDot('gray')." <span class='action-label'>No Blocks Defined</span></div>";
    } else {
        foreach ($blocks as $b) {
            $boardContent .= "<div class='block-header'>🏢 " . htmlspecialchars($b['name']) . "</div>";
            $boardContent .= "<div class='grid-2col' style='padding-left: 0.5rem;'>";
            
            $tasks = [
                'Compliance Sub.' => $b['compliance_submitted'],
                'Compliance Cert.' => $b['compliance_certified'],
                'Condominium Formed' => $b['condominium_formed'],
                'CP Meters Inst.' => $b['cp_meters_installed']
            ];
            
            foreach ($tasks as $lbl => $v) {
                if ($v !== 'NA') {
                    $totalChecks++;
                    if ($v === 'Yes') $completedChecks++;
                }
                
                $color = ($v === 'Yes') ? 'green' : (($v === 'NA') ? 'gray' : 'red');
                $valText = ($v === 'No') ? 'Pending' : $v;
                
                $boardContent .= "<div class='action-item'>".getStatusDot($color)." <div class='txt-grp'><span class='action-label'>{$lbl}</span><span class='action-value {$color}'>{$valText}</span></div></div>";
            }
            $boardContent .= "</div>";
        }
    }
    
    $topMetric1 = ['label' => 'Current Stage', 'value' => $stage, 'color' => '#22c55e'];
    $topMetric2 = ['label' => 'Handover Tasks', 'value' => "$completedChecks / $totalChecks", 'color' => ($completedChecks == $totalChecks && $totalChecks > 0 ? '#22c55e' : '#f59e0b')];
}

// Stage Colorizer for Header
$headerColor = '#0ea5e9'; // Blue
if (in_array($stage, ['Handed Over', 'Condominium', 'Compliance'])) $headerColor = '#22c55e'; // Green
elseif (in_array($stage, ['Construction', 'Finishes', 'Mobilisation', 'Demolition', 'Excavation'])) $headerColor = '#eab308'; // Amber
elseif (in_array($stage, ['Withdrawn', 'On-Hold'])) $headerColor = '#ef4444'; // Red

$pageTitle = 'Status: ' . $project['name'];
require_once 'header.php';
?>

<style>
    :root { --green: #22c55e; --amber: #f59e0b; --red: #ef4444; --gray: #64748b; }
    
    /* Ultra-compact UI to fit on one phone screen for screenshots */
    .snapshot-container { max-width: 420px; margin: 0 auto; padding: 0.5rem; font-family: system-ui, -apple-system, sans-serif; }
    .snapshot-card { background: #1e1e2d; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; overflow: hidden; box-shadow: 0 8px 20px rgba(0,0,0,0.6); }
    
    .snapshot-header { padding: 1rem; text-align: center; position: relative; background: rgba(0,0,0,0.2); }
    .snapshot-title { font-size: 1.3rem; font-weight: 800; color: white; margin: 0 0 0.3rem 0; line-height: 1.1; letter-spacing: -0.5px; }
    .snapshot-meta { font-size: 0.75rem; color: #94a3b8; display: flex; justify-content: center; gap: 0.8rem; }
    
    .metrics-grid { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid rgba(255,255,255,0.05); border-bottom: 1px solid rgba(255,255,255,0.05); background: rgba(0,0,0,0.1); }
    .metric-box { padding: 0.75rem; text-align: center; border-right: 1px solid rgba(255,255,255,0.05); }
    .metric-box:last-child { border-right: none; }
    .metric-value { font-size: 1rem; font-weight: 800; margin-bottom: 0.1rem; }
    .metric-label { font-size: 0.65rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }

    .action-board { padding: 1rem; }
    .action-board-title { font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.75rem; padding-bottom: 0.4rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
    
    .grid-2col { display: grid; grid-template-columns: 1fr 1fr; column-gap: 0.75rem; row-gap: 0.4rem; }
    .action-item { display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.8rem; border: none; padding: 0; }
    .txt-grp { display: flex; flex-direction: column; }
    
    .rag-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-top: 0.3rem; flex-shrink: 0; }
    .action-label { color: #e2e8f0; font-weight: 600; font-size: 0.75rem; }
    .action-value { font-weight: 700; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.2px; }

    .block-header { padding: 0.75rem 0 0.4rem 0; font-weight: 700; color: #0ea5e9; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .alert-tight { padding: 0.5rem; background: rgba(255,255,255,0.03); border-radius: 6px; font-size: 0.75rem; color: #e2e8f0; margin-bottom: 0.75rem; }

    .green { color: var(--green) !important; } .amber { color: var(--amber) !important; } .red { color: var(--red) !important; } .gray { color: var(--gray) !important; }

    @media print {
        header, nav, .sidebar, footer, .back-btn { display: none !important; }
        body { background: #151521 !important; }
        .snapshot-container { margin: 0; padding: 0; max-width: 100%; }
        .snapshot-card { border: none; box-shadow: none; }
    }
</style>

<div class="snapshot-container">
    <div style="margin-bottom: 0.75rem; text-align: left;">
        <a href="dashboard.php" class="btn btn-sm btn-secondary back-btn" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;">← Back</a>
    </div>

    <div class="snapshot-card" style="border-top: 5px solid <?= $headerColor ?>;">
        <div class="snapshot-header">
            <h1 class="snapshot-title"><?= htmlspecialchars($project['name']) ?></h1>
            <div class="snapshot-meta">
                <span>📍 <?= htmlspecialchars($project['city']) ?></span>
                <span>🏢 <?= htmlspecialchars($project['client_name'] ?? 'In-House') ?></span>
            </div>
        </div>

        <div class="metrics-grid">
            <div class="metric-box">
                <div class="metric-value" style="color: <?= $topMetric1['color'] ?>;"><?= $topMetric1['value'] ?></div>
                <div class="metric-label"><?= $topMetric1['label'] ?></div>
            </div>
            <div class="metric-box">
                <div class="metric-value" style="color: <?= $topMetric2['color'] ?>;"><?= $topMetric2['value'] ?></div>
                <div class="metric-label"><?= $topMetric2['label'] ?></div>
            </div>
        </div>

        <div class="action-board">
            <div class="action-board-title"><?= $boardTitle ?></div>
            <?= $boardContent ?>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 0.75rem;">
        <small style="color: #64748b; font-weight: 500; font-size: 0.65rem;">Estate Hub Live Status • <?= date('d M Y, H:i') ?></small>
    </div>
</div>

<?php require_once 'footer.php'; ?>
