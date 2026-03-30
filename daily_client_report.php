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

$earlyStages = ['Feasibility', 'Tracking', 'Permit'];
$completedStages = ['Handed Over', 'Completed'];

// Helper function to safely fetch user/professional names
function fetchNameSafely($pdo, $table, $id) {
    if (empty($id)) return "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>";
    try {
        if ($table === 'users') {
            $stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("SELECT name FROM $table WHERE id = ?");
        }
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        return $name ? htmlspecialchars($name) : "<span style='color:var(--text-muted); font-style:italic;'>Unknown</span>";
    } catch (Exception $e) {
        return "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>";
    }
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
        .report-header { text-align: center; margin-bottom: 3rem; padding-bottom: 1.5rem; border-bottom: 2px solid var(--border-glass); }
        .report-header h1 { color: var(--primary-color); margin: 0 0 10px 0; font-size: 2.2rem; }
        .report-header p { color: var(--text-secondary); margin: 0; font-size: 1.1rem; }
        
        .client-section { margin-bottom: 4rem; background: var(--bg-panel); border: 1px solid var(--border-glass); border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .client-header { background: linear-gradient(90deg, rgba(99, 102, 241, 0.2), rgba(139, 92, 246, 0.2)); padding: 1.5rem 2rem; border-bottom: 1px solid var(--border-glass); }
        .client-header h2 { margin: 0; color: #fff; font-size: 1.8rem; display: flex; align-items: center; gap: 10px; }
        
        .project-card { padding: 1.5rem 2rem; border-bottom: 1px solid var(--border-glass); }
        .project-card:last-child { border-bottom: none; }
        .project-card:nth-child(even) { background: rgba(0,0,0,0.1); }
        
        .proj-title-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .proj-title { font-size: 1.4rem; font-weight: 700; color: var(--primary-color); margin: 0 0 5px 0; }
        .proj-loc { font-size: 0.9rem; color: var(--text-muted); display: flex; align-items: center; gap: 5px; }
        
        .stage-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; text-transform: uppercase; background: rgba(14, 165, 233, 0.2); color: #0ea5e9; border: 1px solid #0ea5e9; }
        
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-top: 1rem; }
        .info-box { background: var(--bg-card); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-glass); }
        .info-box h4 { margin: 0 0 10px 0; color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase; border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; }
        .data-row { display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 6px; }
        .data-label { color: var(--text-muted); font-weight: 600; }
        .data-value { color: #fff; text-align: right; }
        
        .alert-box { background: rgba(239, 68, 68, 0.1); border-left: 4px solid #ef4444; padding: 10px 15px; margin-top: 10px; border-radius: 4px; font-size: 0.85rem; }
        .warn-box { background: rgba(245, 158, 11, 0.1); border-left: 4px solid #f59e0b; padding: 10px 15px; margin-top: 10px; border-radius: 4px; font-size: 0.85rem; }
        
        .completed-section { background: rgba(34, 197, 94, 0.05); padding: 1.5rem 2rem; border-top: 1px solid var(--border-glass); }
        .completed-section h3 { color: #22c55e; margin: 0 0 10px 0; font-size: 1.1rem; }
        .completed-list { margin: 0; padding-left: 20px; color: var(--text-muted); font-size: 0.95rem; }
        
        .btn-print { position: fixed; bottom: 30px; right: 30px; background: var(--primary-color); color: white; border: none; padding: 15px 25px; border-radius: 30px; font-weight: bold; font-size: 1.1rem; cursor: pointer; box-shadow: 0 10px 25px rgba(99, 102, 241, 0.5); z-index: 1000; transition: transform 0.2s; }
        .btn-print:hover { transform: scale(1.05); }

        @media print {
            body { background: #fff !important; color: #000 !important; padding: 0; }
            .btn-print, .navbar, .sidebar { display: none !important; }
            .client-section { page-break-inside: avoid; border: 1px solid #ccc; box-shadow: none; margin-bottom: 2rem; }
            .client-header { background: #f3f4f6 !important; border-bottom: 2px solid #000; }
            .client-header h2 { color: #000 !important; }
            .project-card { border-bottom: 1px solid #eee; page-break-inside: avoid; }
            .info-box { background: #fff; border: 1px solid #ddd; }
            .data-value { color: #000; }
            .data-label { color: #555; }
            .proj-title { color: #000; }
            .stage-badge { border: 1px solid #000; color: #000; }
            .alert-box, .warn-box { border: 1px solid #ccc; border-left: 4px solid #000; background: #fff; color: #000; }
        }
    </style>
</head>
<body>

<div class="report-header">
    <h1>🏢 Daily Client Executive Report</h1>
    <p>Generated on <strong><?= date('l, d F Y') ?></strong> at <strong><?= date('H:i') ?></strong></p>
</div>

<button class="btn-print" onclick="window.print()">🖨️ Print / Save PDF</button>

<?php
foreach ($clients as $client) {
    $clientId = $client['id'];
    
    // Fetch all projects for this client
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE clientid = ? ORDER BY name ASC");
    $stmt->execute([$clientId]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($projects)) continue; // Skip empty clients

    $activeProjects = [];
    $completedProjects = [];

    foreach ($projects as $p) {
        $stage = getAccurateProjectStage($pdo, $p['id']);
        
        // Filter out early stages
        if (in_array($stage, $earlyStages)) continue;
        
        if (in_array($stage, $completedStages) || ($p['status'] ?? '') === 'Completed') {
            $completedProjects[] = ['name' => $p['name'], 'stage' => $stage];
        } else {
            $p['calculated_stage'] = $stage;
            $activeProjects[] = $p;
        }
    }

    if (empty($activeProjects) && empty($completedProjects)) continue; // Skip if all projects were filtered out
    ?>

    <div class="client-section">
        <div class="client-header">
            <h2><?= htmlspecialchars($client['name']) ?></h2>
        </div>
        
        <?php foreach ($activeProjects as $p): 
            $pid = $p['id'];
            $stage = $p['calculated_stage'];
            
            // --- FETCH DATA ---
            // 1. PA Numbers
            $paStmt = $pdo->prepare("SELECT pa_number, status FROM project_pa_numbers WHERE project_id = ?");
            $paStmt->execute([$pid]);
            $paNumbers = $paStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 2. Personnel
            $pmName = fetchNameSafely($pdo, 'users', $p['project_manager_id'] ?? null);
            $contractorName = fetchNameSafely($pdo, 'clients', $p['contractor_id'] ?? null);
            
            // Note: Adjust 'architect_id' to whatever your actual projects table column is if different.
            $archName = fetchNameSafely($pdo, 'users', $p['architect_id'] ?? null);
            $structName = fetchNameSafely($pdo, 'users', $p['structural_engineer_id'] ?? null);

            // 3. Mobilisation Status
            $mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
            $mobStmt->execute([$pid]);
            $mob = $mobStmt->fetch(PDO::FETCH_ASSOC);
            
            $pendingMob = [];
            if ($stage === 'Mobilisation') {
                if ($mob) {
                    if (!in_array($mob['geological_test'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Geological Test";
                    if (!in_array($mob['condition_reports'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Condition Reports";
                    if (!in_array($mob['method_statements'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Method Statements";
                    if (!in_array($mob['insurance_status'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Insurance Status";
                    if (!in_array($mob['responsibility_form'] ?? '', ['Complete', 'NA'])) $pendingMob[] = "Responsibility Form";
                    if (($mob['mob_demolition'] ?? 'No') === 'No') $pendingMob[] = "Demolition Clearance";
                    if (($mob['mob_excavation'] ?? 'No') === 'No') $pendingMob[] = "Excavation Clearance";
                    if (($mob['mob_construction'] ?? 'No') === 'No') $pendingMob[] = "Construction Clearance";
                } else {
                    $pendingMob[] = "Mobilisation checklist not started.";
                }
            }

            // 4. Execution Progress
            $progressText = "N/A";
            if (in_array($stage, ['Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance'])) {
                if ($stage === 'Demolition') $progressText = "Demolition is " . ($mob['demo_status'] ?? 'Pending');
                elseif ($stage === 'Excavation') $progressText = "Excavation is " . ($mob['excavation_status'] ?? 'Pending');
                else {
                    $bStmt = $pdo->prepare("SELECT AVG(progress) FROM project_blocks WHERE project_id = ?");
                    $bStmt->execute([$pid]);
                    $avgProg = round((float)$bStmt->fetchColumn(), 1);
                    $progressText = "Block Execution Avg: {$avgProg}% Complete";
                }
            }

            // 5. OHSA Issues
            $ohsaStmt = $pdo->prepare("SELECT safety_status, safety_comments FROM project_ohsa_setup WHERE project_id = ?");
            $ohsaStmt->execute([$pid]);
            $ohsa = $ohsaStmt->fetch(PDO::FETCH_ASSOC);
            
            // 6. Expiring Docs
            $docsStmt = $pdo->prepare("SELECT title, expiry_date FROM project_documents WHERE project_id = ? AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND alarm_dismissed = 0 ORDER BY expiry_date ASC");
            $docsStmt->execute([$pid]);
            $expDocs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        
        <div class="project-card">
            <div class="proj-title-row">
                <div>
                    <h3 class="proj-title"><?= htmlspecialchars($p['name']) ?></h3>
                    <div class="proj-loc">📍 <?= htmlspecialchars($p['locality'] ?? 'Location not specified') ?></div>
                </div>
                <div class="stage-badge"><?= $stage ?></div>
            </div>

            <div class="info-grid">
                
                <div class="info-box">
                    <h4>📋 Core Details</h4>
                    <div class="data-row"><span class="data-label">Project Manager:</span> <span class="data-value"><?= $pmName ?></span></div>
                    <div class="data-row"><span class="data-label">Main Contractor:</span> <span class="data-value"><?= $contractorName ?></span></div>
                    <div class="data-row"><span class="data-label">Architect:</span> <span class="data-value"><?= $archName ?></span></div>
                    <div class="data-row"><span class="data-label">Struct. Engineer:</span> <span class="data-value"><?= $structName ?></span></div>
                    
                    <div style="margin-top: 10px; border-top: 1px dashed var(--border-glass); padding-top: 5px;">
                        <span class="data-label">PA Numbers:</span><br>
                        <?php if (empty($paNumbers)): ?>
                            <span style="color:var(--text-muted); font-size:0.85rem;">None recorded</span>
                        <?php else: foreach($paNumbers as $pa): ?>
                            <div style="font-size:0.85rem; display:flex; justify-content:space-between;">
                                <span style="color:#fff;"><?= htmlspecialchars($pa['pa_number']) ?></span>
                                <span style="color:var(--text-muted);"><?= htmlspecialchars($pa['status']) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="info-box">
                    <h4>🚀 Status & Next Steps</h4>
                    <?php if ($stage === 'Mobilisation'): ?>
                        <div class="data-row"><span class="data-label">Status:</span> <span class="data-value" style="color:#f59e0b;">Awaiting Clearances</span></div>
                        
                        <?php if (!empty($pendingMob)): ?>
                            <div style="margin-top: 10px; font-size: 0.85rem;">
                                <strong style="color:#f59e0b;">Pending Actions to start site:</strong>
                                <ul style="margin: 5px 0 0 0; padding-left: 20px; color: #fff;">
                                    <?php foreach($pendingMob as $req): ?>
                                        <li><?= $req ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div style="margin-top: 10px; font-size: 0.85rem; color:#22c55e;">All Mobilisation requirements met! Ready for Execution.</div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="data-row"><span class="data-label">Execution:</span> <span class="data-value" style="color:#22c55e;"><?= $progressText ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="info-box">
                    <h4>⚠️ Risks & Compliance</h4>
                    
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
        <?php endforeach; ?>

        <?php if (!empty($completedProjects)): ?>
            <div class="completed-section">
                <h3>✅ Handed Over / Completed Projects</h3>
                <ul class="completed-list">
                    <?php foreach ($completedProjects as $cp): ?>
                        <li><strong><?= htmlspecialchars($cp['name']) ?></strong> <i>(<?= $cp['stage'] ?>)</i></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

    </div>

<?php } // End Client Loop ?>

</body>
</html>
