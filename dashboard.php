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

$blocksStmt = $pdo->prepare("SELECT b.block_name, l.level_name, l.construction_status FROM project_blocks b LEFT JOIN block_levels l ON b.id = l.block_id WHERE b.project_id = ? ORDER BY b.id ASC, l.level_number ASC");
$blocksStmt->execute([$projectId]);
$blockLevels = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

// --- DYNAMIC RAG ENGINE ---
// Colors: Green (Complete/Approved), Amber (In Progress/Processing), Red (Missing/Refused), Gray (Not Started)

function getStatusDot($color) {
    return "<span class='rag-dot' style='background-color: var(--{$color}); box-shadow: 0 0 6px var(--{$color});'></span>";
}

$boardTitle = "";
$boardContent = "";
$topMetric1 = ['label' => 'Stage', 'value' => $stage, 'color' => '#ffffff'];
$topMetric2 = ['label' => 'Status', 'value' => $project['project_status'], 'color' => '#ffffff'];

// --- STAGE 1: PRE-EXECUTION (Feasibility, Tracking, Permit) ---
if (in_array($stage, ['Feasibility', 'Tracking', 'Permit'])) {
    $boardTitle = "Planning Authority & Permits Tracker";
    $paGreen = ['Approved', 'Decided', 'Endorsed', 'Fee Payment'];
    $paRed = ['Refused', 'Revoked/Annulled', 'Suspended', 'Withdrawn'];
    
    $approvedCount = 0;
    if (empty($paNumbers)) {
        $boardContent .= "<div class='action-item'>".getStatusDot('gray')." <strong>No PA Numbers Linked Yet</strong></div>";
    } else {
        foreach ($paNumbers as $pa) {
            $status = $pa['pa_status'] ?? 'Tracking';
            if (in_array($status, $paGreen)) {
                $color = 'green'; $approvedCount++;
            } elseif (in_array($status, $paRed)) {
                $color = 'red';
            } else {
                $color = 'amber';
            }
            $boardContent .= "<div class='action-item'>".getStatusDot($color)." <span class='action-label'>{$pa['pa_number']}</span> <span class='action-value'>{$status}</span></div>";
        }
    }
    
    $topMetric1 = ['label' => 'Linked PAs', 'value' => count($paNumbers), 'color' => '#0ea5e9'];
    $topMetric2 = ['label' => 'Approved', 'value' => $approvedCount, 'color' => ($approvedCount > 0 ? '#22c55e' : '#94a3b8')];
} 
// --- STAGE 2: MOBILISATION ---
elseif ($stage === 'Mobilisation') {
    $boardTitle = "Mobilisation Checklist (What's Left to Start)";
    
    $mobChecks = [
        'BCA Notice' => $mobilisation['bca_notice'] ?? '',
        'SWD Notice' => $mobilisation['swd_notice'] ?? '',
        'Hoarding Permit' => $mobilisation['hoarding_permit'] ?? '',
        'Crane Permit' => $mobilisation['crane_permit'] ?? '',
        'Site Hoarding Setup' => $mobilisation['site_hoarding_setup'] ?? '',
        'Temp Water Connected' => $mobilisation['temporary_water'] ?? '',
        'Temp Elec Connected' => $mobilisation['temporary_electricity'] ?? '',
        'Health & Safety Setup' => $mobilisation['health_safety_setup'] ?? ''
    ];
    
    $completedTasks = 0;
    $totalTasks = count($mobChecks);

    foreach ($mobChecks as $label => $val) {
        $v = trim(strtolower($val));
        if (empty($v) || in_array($v, ['no', 'pending', 'missing', 'not started'])) {
            $boardContent .= "<div class='action-item'>".getStatusDot('red')." <span class='action-label'>{$label}</span> <span class='action-value' style='color:var(--red);'>Pending</span></div>";
        } elseif (in_array($v, ['in progress', 'partial', 'submitted'])) {
            $boardContent .= "<div class='action-item'>".getStatusDot('amber')." <span class='action-label'>{$label}</span> <span class='action-value' style='color:var(--amber);'>{$val}</span></div>";
        } else {
            $boardContent .= "<div class='action-item'>".getStatusDot('green')." <span class='action-label'>{$label}</span> <span class='action-value' style='color:var(--green);'>Secured / Done</span></div>";
            $completedTasks++;
        }
    }

    $topMetric1 = ['label' => 'Mob. Tasks', 'value' => "$completedTasks / $totalTasks", 'color' => ($completedTasks == $totalTasks ? '#22c55e' : '#f59e0b')];
    $topMetric2 = ['label' => 'Mobilisation Status', 'value' => $mobilisation['mobilisation_status'] ?? 'In Progress', 'color' => '#0ea5e9'];
} 
// --- STAGE 3: EXECUTION (Demolition, Excavation, Construction, Finishes) ---
elseif (in_array($stage, ['Demolition', 'Excavation', 'Construction', 'Finishes'])) {
    $boardTitle = "Site Execution & Level Progress";
    
    $blocks = [];
    $totalLevels = 0;
    $completedLevels = 0;

    foreach ($blockLevels as $bl) {
        $blocks[$bl['block_name']][] = $bl;
        $totalLevels++;
        if ($bl['construction_status'] === 'Complete') $completedLevels++;
    }

    if (empty($blocks)) {
        $boardContent .= "<div class='action-item'>".getStatusDot('gray')." <strong>No Building Blocks Defined</strong></div>";
    } else {
        foreach ($blocks as $blockName => $levels) {
            $boardContent .= "<div class='block-header'>🏢 " . htmlspecialchars($blockName) . "</div>";
            foreach ($levels as $lvl) {
                $status = $lvl['construction_status'] ?? 'Pending';
                if ($status === 'Complete') { $color = 'green'; $statusText = 'Complete'; }
                elseif ($status === 'In Progress') { $color = 'amber'; $statusText = 'In Progress'; }
                elseif ($status === 'NA') { $color = 'gray'; $statusText = 'N/A'; }
                else { $color = 'gray'; $statusText = 'Pending'; }

                $boardContent .= "<div class='action-item' style='padding-left: 2rem;'>".getStatusDot($color)." <span class='action-label'>{$lvl['level_name']}</span> <span class='action-value' style='color:var(--{$color});'>{$statusText}</span></div>";
            }
        }
    }

    $topMetric1 = ['label' => 'Execution Stage', 'value' => $stage, 'color' => '#f59e0b'];
    $topMetric2 = ['label' => 'Levels Complete', 'value' => "$completedLevels / $totalLevels", 'color' => ($completedLevels == $totalLevels && $totalLevels > 0 ? '#22c55e' : '#0ea5e9')];
} 
// --- STAGE 4: FINALIZATION (Compliance, Condominium, Handed Over) ---
else {
    $boardTitle = "Finalization & Handover";
    $boardContent .= "<div class='action-item' style='justify-content:center; padding: 2rem;'>".getStatusDot('green')." <strong>Project has reached Finalization phases. Major execution works are logged as complete.</strong></div>";
    
    $topMetric1 = ['label' => 'Current Stage', 'value' => $stage, 'color' => '#22c55e'];
    $topMetric2 = ['label' => 'System Status', 'value' => $project['project_status'], 'color' => '#22c55e'];
}

// Stage Colorizer for Header
$headerColor = '#0ea5e9'; // Blue
if (in_array($stage, ['Handed Over', 'Condominium'])) $headerColor = '#22c55e'; // Green
elseif (in_array($stage, ['Construction', 'Finishes', 'Mobilisation'])) $headerColor = '#eab308'; // Amber
elseif (in_array($stage, ['Withdrawn', 'On-Hold'])) $headerColor = '#ef4444'; // Red

$pageTitle = 'Status: ' . $project['name'];
require_once 'header.php';
?>

<style>
    :root {
        --green: #22c55e;
        --amber: #f59e0b;
        --red: #ef4444;
        --gray: #64748b;
    }
    
    /* Ultra-clean UI for Mobile Screenshots */
    .snapshot-container { max-width: 500px; margin: 0 auto; padding: 1rem; font-family: system-ui, -apple-system, sans-serif; }
    .snapshot-card { background: #1e1e2d; border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.6); }
    
    .snapshot-header { padding: 1.5rem; text-align: center; position: relative; background: rgba(0,0,0,0.2); }
    .snapshot-title { font-size: 1.6rem; font-weight: 800; color: white; margin: 0 0 0.5rem 0; line-height: 1.2; letter-spacing: -0.5px; }
    .snapshot-meta { font-size: 0.9rem; color: #94a3b8; display: flex; justify-content: center; gap: 1rem; margin-bottom: 1rem; }
    
    .metrics-grid { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid rgba(255,255,255,0.05); border-bottom: 1px solid rgba(255,255,255,0.05); background: rgba(0,0,0,0.1); }
    .metric-box { padding: 1rem; text-align: center; border-right: 1px solid rgba(255,255,255,0.05); }
    .metric-box:last-child { border-right: none; }
    .metric-value { font-size: 1.25rem; font-weight: 800; margin-bottom: 0.2rem; }
    .metric-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }

    .action-board { padding: 1.5rem; }
    .action-board-title { font-size: 0.8rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
    
    .action-item { display: flex; align-items: center; padding: 0.6rem 0; border-bottom: 1px dashed rgba(255,255,255,0.05); font-size: 0.95rem; }
    .action-item:last-child { border-bottom: none; }
    
    .rag-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 1rem; flex-shrink: 0; }
    .action-label { flex-grow: 1; color: #e2e8f0; font-weight: 500; }
    .action-value { font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }

    .block-header { padding: 1rem 0 0.25rem 0; font-weight: 700; color: #0ea5e9; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; }

    @media print {
        header, nav, .sidebar, footer, .back-btn { display: none !important; }
        body { background: #151521 !important; }
        .snapshot-container { margin: 0; padding: 0; max-width: 100%; }
        .snapshot-card { border: none; box-shadow: none; }
    }
</style>

<div class="snapshot-container">
    <div style="margin-bottom: 1rem; text-align: left;">
        <a href="javascript:history.back()" class="btn btn-sm btn-secondary back-btn">← Back to Dashboard</a>
    </div>

    <div class="snapshot-card" style="border-top: 6px solid <?= $headerColor ?>;">
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
    
    <div style="text-align: center; margin-top: 1rem;">
        <small style="color: #64748b; font-weight: 500;">Estate Hub Live Status • <?= date('d M Y, H:i') ?></small>
    </div>
</div>

<?php require_once 'footer.php'; ?>
