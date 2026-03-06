<?php
require_once 'init.php';
require_once 'session-check.php';

$projectId = $_GET['id'] ?? null;
if (!$projectId) { header('Location: dashboard.php'); exit; }

// Fetch Core Data
$project = getProjectWithClient($pdo, $projectId);
if (!$project) { header('Location: dashboard.php'); exit; }

$stage = deriveProjectStage($pdo, $projectId);

// Fetch PA Numbers
$paStmt = $pdo->prepare("SELECT * FROM project_pa_numbers WHERE project_id = ?");
$paStmt->execute([$projectId]);
$paNumbers = $paStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Mobilisation (Failsafe if table is empty)
$mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
$mobStmt->execute([$projectId]);
$mobilisation = $mobStmt->fetch(PDO::FETCH_ASSOC);

// Fetch Block Levels
$blocksStmt = $pdo->prepare("SELECT b.block_name, l.level_name, l.construction_status FROM project_blocks b LEFT JOIN block_levels l ON b.id = l.block_id WHERE b.project_id = ? ORDER BY b.id ASC, l.level_number ASC");
$blocksStmt->execute([$projectId]);
$blockLevels = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

// --- RAG LOGIC & "WHAT'S LEFT" CALCULATIONS ---
$pendingActions = [];
$ragStatus = 'green'; // Default to green, downgrade if issues found

// 1. PA Status Check
$paGreen = ['Approved', 'Decided', 'Endorsed', 'Fee Payment'];
$paRed = ['Refused', 'Revoked/Annulled', 'Suspended', 'Withdrawn'];

foreach ($paNumbers as $pa) {
    $status = $pa['pa_status'] ?? 'Tracking';
    if (in_array($status, $paRed)) {
        $pendingActions[] = "<span class='rag-dot red'></span> PA {$pa['pa_number']} is {$status}";
        $ragStatus = 'red';
    } elseif (!in_array($status, $paGreen)) {
        $pendingActions[] = "<span class='rag-dot amber'></span> Awaiting PA: {$pa['pa_number']} ({$status})";
        if ($ragStatus !== 'red') $ragStatus = 'amber';
    }
}

// 2. Mobilisation Check
$mobFields = [
    'bca_notice' => 'BCA Notice',
    'swd_notice' => 'SWD Notice',
    'hoarding_permit' => 'Hoarding Permit',
    'crane_permit' => 'Crane Permit'
];

$mobItemsMissing = 0;
if ($mobilisation && !in_array($stage, ['Feasibility', 'Tracking', 'Permit'])) {
    foreach ($mobFields as $dbKey => $label) {
        if (array_key_exists($dbKey, $mobilisation)) {
            $val = $mobilisation[$dbKey];
            if (empty($val) || stripos($val, 'Pending') !== false || stripos($val, 'No') !== false) {
                $pendingActions[] = "<span class='rag-dot red'></span> Missing: $label";
                $mobItemsMissing++;
                $ragStatus = 'red';
            }
        }
    }
}

// 3. Execution Check
$totalLevels = count($blockLevels);
$completedLevels = 0;
$inProgressLevels = 0;
foreach ($blockLevels as $level) {
    if ($level['construction_status'] === 'Complete') $completedLevels++;
    if ($level['construction_status'] === 'In Progress') $inProgressLevels++;
}

// Ensure the array has something to show if all is good
if (empty($pendingActions)) {
    if ($completedLevels == $totalLevels && $totalLevels > 0) {
        $pendingActions[] = "<span class='rag-dot green'></span> Project Execution is Fully Complete!";
    } elseif ($inProgressLevels > 0 || $completedLevels > 0) {
        $pendingActions[] = "<span class='rag-dot green'></span> All prep cleared. Site is in active execution.";
    } else {
        $pendingActions[] = "<span class='rag-dot green'></span> No immediate blockers identified.";
    }
}

// Stage Color
$stageColor = '#0ea5e9'; // Blue
if (in_array($stage, ['Handed Over', 'Condominium'])) $stageColor = '#22c55e'; // Green
elseif (in_array($stage, ['Construction', 'Finishes'])) $stageColor = '#eab308'; // Amber
elseif (in_array($stage, ['Withdrawn', 'On-Hold'])) $stageColor = '#ef4444'; // Red

$pageTitle = 'Status: ' . $project['name'];
require_once 'header.php';
?>

<style>
    /* Ultra-clean UI for Mobile Screenshots */
    .snapshot-container { max-width: 600px; margin: 0 auto; padding: 1rem; }
    .snapshot-card { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    
    .snapshot-header { padding: 1.5rem; border-bottom: 1px solid var(--border-glass); text-align: center; position: relative; }
    .snapshot-title { font-size: 1.5rem; font-weight: 800; color: white; margin: 0 0 0.5rem 0; line-height: 1.2; }
    .snapshot-meta { font-size: 0.9rem; color: var(--text-secondary); display: flex; justify-content: center; gap: 1rem; }
    
    .stage-badge { display: inline-block; padding: 0.5rem 1rem; border-radius: 50px; font-weight: 700; font-size: 0.9rem; letter-spacing: 1px; text-transform: uppercase; margin-top: 1rem; color: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }

    .action-board { background: #1e1e2d; margin: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); }
    .action-board-title { padding: 1rem 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.9rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; }
    .action-list { padding: 1rem 1.5rem; list-style: none; margin: 0; }
    .action-list li { margin-bottom: 0.75rem; font-size: 1.05rem; font-weight: 500; color: #e2e8f0; display: flex; align-items: center; gap: 0.75rem; }
    .action-list li:last-child { margin-bottom: 0; }

    .rag-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; box-shadow: 0 0 8px currentColor; }
    .rag-dot.red { color: #ef4444; background: #ef4444; }
    .rag-dot.amber { color: #f59e0b; background: #f59e0b; }
    .rag-dot.green { color: #22c55e; background: #22c55e; }

    .metrics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding: 0 1.5rem 1.5rem 1.5rem; }
    .metric-box { background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 8px; padding: 1rem; text-align: center; }
    .metric-value { font-size: 1.5rem; font-weight: 700; color: white; margin-bottom: 0.25rem; }
    .metric-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }

    /* Hide UI elements during print/screenshot if needed */
    @media print {
        header, nav, .sidebar, footer, .back-btn { display: none !important; }
        body { background: #151521 !important; }
        .snapshot-container { margin: 0; padding: 0; }
        .snapshot-card { border: none; box-shadow: none; }
    }
</style>

<div class="snapshot-container">
    <div style="margin-bottom: 1rem;">
        <a href="javascript:history.back()" class="btn btn-sm btn-secondary back-btn">← Back</a>
    </div>

    <div class="snapshot-card">
        <div class="snapshot-header" style="border-top: 6px solid <?= $stageColor ?>;">
            <h1 class="snapshot-title"><?= htmlspecialchars($project['name']) ?></h1>
            <div class="snapshot-meta">
                <span>📍 <?= htmlspecialchars($project['city']) ?></span>
                <span>🏢 <?= htmlspecialchars($project['client_name'] ?? 'In-House') ?></span>
            </div>
            <div class="stage-badge" style="background-color: <?= $stageColor ?>;">
                <?= $stage ?>
            </div>
        </div>

        <div class="action-board">
            <div class="action-board-title">Current Status / What's Left to Start</div>
            <ul class="action-list">
                <?php foreach ($pendingActions as $action): ?>
                    <li><?= $action ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="metrics-grid">
            <div class="metric-box">
                <div class="metric-value" style="color: <?= empty($paNumbers) ? '#94a3b8' : '#0ea5e9' ?>;"><?= count($paNumbers) ?></div>
                <div class="metric-label">PA Permits Linked</div>
            </div>
            <div class="metric-box">
                <div class="metric-value" style="color: <?= $completedLevels == $totalLevels && $totalLevels > 0 ? '#22c55e' : '#eab308' ?>;">
                    <?= $completedLevels ?> / <?= $totalLevels ?>
                </div>
                <div class="metric-label">Levels Completed</div>
            </div>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 1rem;">
        <small style="color: var(--text-muted);">Generated on <?= date('d M Y, H:i') ?></small>
    </div>
</div>

<?php require_once 'footer.php'; ?>
