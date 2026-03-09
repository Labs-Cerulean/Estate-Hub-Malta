<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('view_capital_projects') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = ''; $error = '';
$projectId = $_GET['project_id'] ?? null;
// Ensure accountants and authorized users can edit
$canEdit = hasPermission('view_capital_projects') || hasPermission('edit_project_details') || isAdmin();

// ==========================================
// HANDLE POST REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && $projectId) {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_financials') {
            $stmt = $pdo->prepare("
                INSERT INTO project_capital_financials 
                (project_id, actual_client, ct_reference, award_date, commencement_date, completion_target, order_value, 
                 retention_percentage, retention_release_date, retention_released, ret_split_1_pct, ret_split_2_pct, retention_release_date_2, retention_released_2) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                actual_client=VALUES(actual_client), ct_reference=VALUES(ct_reference), award_date=VALUES(award_date), commencement_date=VALUES(commencement_date), 
                completion_target=VALUES(completion_target), order_value=VALUES(order_value), 
                retention_percentage=VALUES(retention_percentage), retention_release_date=VALUES(retention_release_date), retention_released=VALUES(retention_released),
                ret_split_1_pct=VALUES(ret_split_1_pct), ret_split_2_pct=VALUES(ret_split_2_pct), retention_release_date_2=VALUES(retention_release_date_2), retention_released_2=VALUES(retention_released_2)
            ");
            $stmt->execute([
                $projectId, $_POST['actual_client'] ?? null, $_POST['ct_reference'], $_POST['award_date'] ?: null, $_POST['commencement_date'] ?: null, 
                $_POST['completion_target'] ?: null, $_POST['order_value'] ?: 0, 
                $_POST['retention_percentage'] ?: 5, $_POST['retention_release_date'] ?: null, $_POST['retention_released'] ?: 0,
                $_POST['ret_split_1_pct'] ?: 100, $_POST['ret_split_2_pct'] ?: 0, $_POST['retention_release_date_2'] ?: null, $_POST['retention_released_2'] ?: 0
            ]);
            $message = "Financial base details updated!";
        }
        
        // Variations
        elseif ($action === 'add_variation') {
            $stmt = $pdo->prepare("INSERT INTO project_capital_variations (project_id, variation_ref, description, amount, status, submitted_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$projectId, $_POST['variation_ref'], $_POST['description'], $_POST['amount'], $_POST['status'], $_POST['submitted_date'] ?: null]);
            $message = "Variation added successfully!";
        }
        elseif ($action === 'edit_variation') {
            $stmt = $pdo->prepare("UPDATE project_capital_variations SET variation_ref=?, description=?, amount=?, status=?, submitted_date=? WHERE id=? AND project_id=?");
            $stmt->execute([$_POST['variation_ref'], $_POST['description'], $_POST['amount'], $_POST['status'], $_POST['submitted_date'] ?: null, $_POST['id'], $projectId]);
            $message = "Variation updated!";
        }
        elseif ($action === 'delete_variation') {
            $pdo->prepare("DELETE FROM project_capital_variations WHERE id=? AND project_id=?")->execute([$_POST['id'], $projectId]);
            $message = "Variation deleted.";
        }

        // Extension of Time (EoT)
        elseif ($action === 'add_eot') {
            $stmt = $pdo->prepare("INSERT INTO project_capital_eot (project_id, eot_ref, description, days_extended, status, submitted_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$projectId, $_POST['eot_ref'], $_POST['description'], $_POST['days_extended'], $_POST['status'], $_POST['submitted_date'] ?: null]);
            $message = "Extension of Time logged!";
        }
        elseif ($action === 'edit_eot') {
            $stmt = $pdo->prepare("UPDATE project_capital_eot SET eot_ref=?, description=?, days_extended=?, status=?, submitted_date=? WHERE id=? AND project_id=?");
            $stmt->execute([$_POST['eot_ref'], $_POST['description'], $_POST['days_extended'], $_POST['status'], $_POST['submitted_date'] ?: null, $_POST['id'], $projectId]);
            $message = "Extension of Time updated!";
        }
        elseif ($action === 'delete_eot') {
            $pdo->prepare("DELETE FROM project_capital_eot WHERE id=? AND project_id=?")->execute([$_POST['id'], $projectId]);
            $message = "Extension of Time deleted.";
        }

        // Deductions
        elseif ($action === 'add_deduction') {
            $stmt = $pdo->prepare("INSERT INTO project_capital_deductions (project_id, deduction_ref, description, amount, status, date_logged) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$projectId, $_POST['deduction_ref'], $_POST['description'], $_POST['amount'], $_POST['status'], $_POST['date_logged'] ?: null]);
            $message = "Deduction added successfully!";
        }
        elseif ($action === 'edit_deduction') {
            $stmt = $pdo->prepare("UPDATE project_capital_deductions SET deduction_ref=?, description=?, amount=?, status=?, date_logged=? WHERE id=? AND project_id=?");
            $stmt->execute([$_POST['deduction_ref'], $_POST['description'], $_POST['amount'], $_POST['status'], $_POST['date_logged'] ?: null, $_POST['id'], $projectId]);
            $message = "Deduction updated!";
        }
        elseif ($action === 'delete_deduction') {
            $pdo->prepare("DELETE FROM project_capital_deductions WHERE id=? AND project_id=?")->execute([$_POST['id'], $projectId]);
            $message = "Deduction deleted.";
        }

        // Claims
        elseif ($action === 'add_claim') {
            $stmt = $pdo->prepare("INSERT INTO project_capital_claims (project_id, ipc_reference, amount_claimed, amount_approved, submission_date, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$projectId, $_POST['ipc_reference'], $_POST['amount_claimed'], $_POST['amount_approved'], $_POST['submission_date'] ?: null, $_POST['status']]);
            $message = "IPC Claim added successfully!";
        }
        elseif ($action === 'edit_claim') {
            $stmt = $pdo->prepare("UPDATE project_capital_claims SET ipc_reference=?, amount_claimed=?, amount_approved=?, submission_date=?, status=? WHERE id=? AND project_id=?");
            $stmt->execute([$_POST['ipc_reference'], $_POST['amount_claimed'], $_POST['amount_approved'], $_POST['submission_date'] ?: null, $_POST['status'], $_POST['id'], $projectId]);
            $message = "IPC Claim updated!";
        }
        elseif ($action === 'delete_claim') {
            $pdo->prepare("DELETE FROM project_capital_claims WHERE id=? AND project_id=?")->execute([$_POST['id'], $projectId]);
            $message = "Claim deleted.";
        }

    } catch (PDOException $e) {
        $error = "Database Error: " . $e->getMessage();
    }
}

// ==========================================
// DATA FETCHING & CALCULATIONS
// ==========================================
function calculateCapitalKPIs($fin, $variations, $claims, $deductions, $eots) {
    $baseValue = (float)($fin['order_value'] ?? 0);
    $appVars = 0; $penVars = 0;
    
    foreach ($variations as $v) {
        if ($v['status'] === 'Approved') $appVars += (float)$v['amount'];
        if ($v['status'] === 'Pending') $penVars += (float)$v['amount'];
    }
    
    $totalContractVal = $baseValue + $appVars;
    
    $totDeductions = 0;
    foreach ($deductions as $d) {
        if ($d['status'] === 'Applied') $totDeductions += (float)$d['amount'];
    }

    $totClaimed = 0; $totApproved = 0; $latestIpc = 'None'; $latestIpcDate = '';
    foreach ($claims as $c) {
        $totClaimed += (float)$c['amount_claimed'];
        $totApproved += (float)$c['amount_approved'];
        if (empty($latestIpcDate) || $c['submission_date'] >= $latestIpcDate) {
            $latestIpc = $c['ipc_reference'];
            $latestIpcDate = $c['submission_date'];
        }
    }
    
    // Retention Math (Calculated on Approved Value)
    $retPct = (float)($fin['retention_percentage'] ?? 5) / 100;
    $retAmount = $totApproved * $retPct;
    $retReleased = (float)($fin['retention_released'] ?? 0) + (float)($fin['retention_released_2'] ?? 0);
    $retCurrentlyHeld = max(0, $retAmount - $retReleased);

    // Extension of Time Math
    $approvedEotDays = 0;
    foreach ($eots as $e) {
        if ($e['status'] === 'Approved') $approvedEotDays += (int)$e['days_extended'];
    }

    // Time calculations
    $daysLeft = 0; $timeElapsed = 0;
    $completionTarget = $fin['completion_target'] ?? null;
    $revisedCompletion = $completionTarget;
    
    // Recalculate Completion Date if EoT is approved
    if ($completionTarget && $approvedEotDays > 0) {
        $revisedCompletion = date('Y-m-d', strtotime($completionTarget . " + $approvedEotDays days"));
    }

    if (!empty($fin['commencement_date']) && !empty($revisedCompletion)) {
        $start = new DateTime($fin['commencement_date']);
        $end = new DateTime($revisedCompletion);
        $now = new DateTime();
        
        if ($now < $end) {
            $daysLeft = $now->diff($end)->days;
        } elseif ($now > $end) {
            // Negative days left indicates Overdue
            $daysLeft = -$now->diff($end)->days;
        }
        
        $totalDays = $start->diff($end)->days;
        $daysPassed = $start->diff($now)->days;
        
        if ($now < $start) $timeElapsed = 0;
        elseif ($now > $end) $timeElapsed = 100;
        else $timeElapsed = $totalDays > 0 ? round(($daysPassed / $totalDays) * 100, 1) : 0;
    }

    return [
        'base_value' => $baseValue, 'app_vars' => $appVars, 'pen_vars' => $penVars, 'total_value' => $totalContractVal,
        'tot_deductions' => $totDeductions, 'net_contract' => $totalContractVal - $totDeductions,
        'tot_claimed' => $totClaimed, 'tot_approved' => $totApproved, 'latest_ipc' => $latestIpc,
        'pct_claimed' => $totalContractVal > 0 ? round(($totClaimed / $totalContractVal) * 100, 1) : 0,
        'pct_approved' => $totClaimed > 0 ? round(($totApproved / $totClaimed) * 100, 1) : 0,
        'retention_amount' => $retAmount, 'retention_held' => $retCurrentlyHeld,
        'days_left' => $daysLeft, 'time_elapsed' => $timeElapsed, 
        'approved_eot_days' => $approvedEotDays, 'revised_completion' => $revisedCompletion
    ];
}

// ------------------------------------------
// VIEW MODE: SINGLE PROJECT DETAIL
// ------------------------------------------
if ($projectId) {
    $project = getProjectWithClient($pdo, $projectId);
    if (!$project || $project['type'] !== '3rd-party') { header('Location: capital_projects.php'); exit; }

    $finStmt = $pdo->prepare("SELECT * FROM project_capital_financials WHERE project_id = ?");
    $finStmt->execute([$projectId]);
    $fin = $finStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $varStmt = $pdo->prepare("SELECT * FROM project_capital_variations WHERE project_id = ? ORDER BY submitted_date DESC");
    $varStmt->execute([$projectId]);
    $variations = $varStmt->fetchAll(PDO::FETCH_ASSOC);

    $eotStmt = $pdo->prepare("SELECT * FROM project_capital_eot WHERE project_id = ? ORDER BY submitted_date DESC");
    $eotStmt->execute([$projectId]);
    $eots = $eotStmt->fetchAll(PDO::FETCH_ASSOC);

    $dedStmt = $pdo->prepare("SELECT * FROM project_capital_deductions WHERE project_id = ? ORDER BY date_logged DESC");
    $dedStmt->execute([$projectId]);
    $deductions = $dedStmt->fetchAll(PDO::FETCH_ASSOC);

    $claimStmt = $pdo->prepare("SELECT * FROM project_capital_claims WHERE project_id = ? ORDER BY submission_date DESC");
    $claimStmt->execute([$projectId]);
    $claims = $claimStmt->fetchAll(PDO::FETCH_ASSOC);

    $kpis = calculateCapitalKPIs($fin, $variations, $claims, $deductions, $eots);
    $pageTitle = 'Capital Financials - ' . $project['name'];
} 
// ------------------------------------------
// VIEW MODE: GLOBAL SUMMARY MATRIX
// ------------------------------------------
else {
    $stmt = $pdo->query("SELECT p.id, p.name, p.clientid, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id WHERE p.type = '3rd-party' AND p.project_status = 'Active' ORDER BY p.name ASC");
    $allProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $allFin = $pdo->query("SELECT * FROM project_capital_financials")->fetchAll(PDO::FETCH_ASSOC);
    $allVars = $pdo->query("SELECT * FROM project_capital_variations")->fetchAll(PDO::FETCH_ASSOC);
    $allEots = $pdo->query("SELECT * FROM project_capital_eot")->fetchAll(PDO::FETCH_ASSOC);
    $allDeds = $pdo->query("SELECT * FROM project_capital_deductions")->fetchAll(PDO::FETCH_ASSOC);
    $allClaims = $pdo->query("SELECT * FROM project_capital_claims")->fetchAll(PDO::FETCH_ASSOC);

    $finMap = []; foreach($allFin as $f) $finMap[$f['project_id']] = $f;
    $varMap = []; foreach($allVars as $v) $varMap[$v['project_id']][] = $v;
    $eotMap = []; foreach($allEots as $e) $eotMap[$e['project_id']][] = $e;
    $dedMap = []; foreach($allDeds as $d) $dedMap[$d['project_id']][] = $d;
    $claimMap = []; foreach($allClaims as $c) $claimMap[$c['project_id']][] = $c;

    $matrixData = [];
    foreach ($allProjects as $p) {
        $f = $finMap[$p['id']] ?? [];
        $v = $varMap[$p['id']] ?? [];
        $e = $eotMap[$p['id']] ?? [];
        $d = $dedMap[$p['id']] ?? [];
        $c = $claimMap[$p['id']] ?? [];
        
        $k = calculateCapitalKPIs($f, $v, $c, $d, $e);
        $p['fin'] = $f; 
        $p['vars'] = $v;
        $p['kpis'] = $k;
        $matrixData[] = $p;
    }
    $pageTitle = 'Tender & Capital Summary';
}

require_once 'header.php';
?>

<style>
/* Base Styles */
.currency { font-variant-numeric: tabular-nums; text-align: right; }
.badge { padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
.badge-green { background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); }
.badge-yellow { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
.badge-red { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
.badge-gray { background: rgba(107, 114, 128, 0.1); color: #9ca3af; border: 1px solid rgba(107, 114, 128, 0.3); }

/* Global Matrix Frozen Table */
.matrix-wrapper { position: relative; width: 100%; max-height: calc(100vh - 180px); overflow: auto; background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid var(--border-glass); }
.matrix-table { width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.85rem; }
.matrix-table th { position: sticky; top: 0; background: #1e1e2d; z-index: 10; padding: 1rem; font-weight: 600; color: var(--text-primary); border-bottom: 2px solid var(--border-glass); white-space: nowrap; }
.matrix-table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-glass); vertical-align: middle; color: var(--text-secondary); white-space: nowrap; }
.matrix-table thead th:first-child { position: sticky; left: 0; z-index: 20; border-right: 2px solid var(--border-glass); }
.matrix-table tbody tr.main-row td:first-child { position: sticky; left: 0; background: #1e1e2d; z-index: 5; border-right: 2px solid var(--border-glass); font-weight: 700; }
.matrix-table tbody tr.main-row:hover td { background: rgba(255,255,255,0.03); }
.matrix-table tbody tr.main-row:hover td:first-child { background: #2a2a3b; z-index: 60; }
.matrix-table tbody tr.main-row:hover td:last-child { background: #2a2a3b; z-index: 60; }

/* Detail View Accordions & Modals */
.custom-accordion { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
.custom-accordion summary { padding: 1.25rem 1.5rem; font-size: 1.1rem; font-weight: 600; color: var(--text-primary); cursor: pointer; background: rgba(255,255,255,0.02); list-style: none; display: flex; justify-content: space-between; align-items: center; border-radius: var(--radius-md); user-select: none; }
.custom-accordion summary::after { content: '▼'; font-size: 0.9rem; color: var(--primary-color); transition: transform 0.3s ease; }
.custom-accordion[open] summary::after { transform: rotate(180deg); }
.custom-accordion[open] summary { border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom: 1px solid var(--border-glass); }
.accordion-content { padding: 1.5rem; }

.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }

.btn-expand { background: none; border: 1px solid var(--border-glass); color: var(--text-primary); border-radius: 4px; cursor: pointer; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; margin-right: 0.5rem; font-weight: bold; transition: all 0.2s; }
.btn-expand:hover { background: rgba(255,255,255,0.1); }

/* Action Dropdown Menu */
.action-dropdown { position: relative; display: inline-block; }
.action-dropdown-content { display: none; position: absolute; right: 0; top: 100%; margin-top: 4px; background-color: #1e1e2d; min-width: 170px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.5); z-index: 999; border: 1px solid var(--border-glass); border-radius: 6px; overflow: hidden; }
.action-dropdown-content a { color: var(--text-primary); padding: 10px 12px; text-decoration: none; display: block; font-size: 0.8rem; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.02); transition: 0.2s; }
.action-dropdown-content a:last-child { border-bottom: none; }
.action-dropdown-content a:hover { background-color: rgba(255,255,255,0.05); color: var(--primary-color); }
.action-dropdown:hover .action-dropdown-content { display: block; }
</style>

<div class="main-container" style="max-width: <?= $projectId ? '1200px' : '100%' ?>; padding: 1.5rem;">
    
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">
                <?= $projectId ? 'Contract Financials: ' . htmlspecialchars($project['name']) : 'Tender & Capital Summary' ?>
            </h1>
            <p style="color: var(--text-secondary); margin-top: 0.25rem;">
                <strong style="color: var(--primary-color);">Note: All financial values shown are Excluding VAT.</strong>
            </p>
        </div>
        <?php if ($projectId): ?>
            <a href="capital_projects.php" class="btn btn-secondary">← Back to Summary</a>
        <?php endif; ?>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$projectId): ?>
        <div class="matrix-wrapper">
            <table class="matrix-table">
                <thead>
                    <tr>
                        <th>Project Name</th>
                        <th>Winning Co.</th>
                        <th>Actual Client / Authority</th>
                        <th>Ref (CT No)</th>
                        <th>Award Date</th>
                        <th>Target Date</th>
                        <th style="text-align: center;">Days Left</th>
                        <th class="currency" style="border-left: 2px solid var(--border-glass);">Order Val (€)</th>
                        <th class="currency">Appr. Vars (€)</th>
                        <th class="currency" style="font-weight:800; color:#10B981;">Total Contract (€)</th>
                        <th class="currency" style="color: #ef4444;">Deductions (€)</th>
                        <th class="currency" style="color: #f59e0b;">Pend. Vars (€)</th>
                        <th class="currency" style="border-left: 2px solid var(--border-glass);">Claimed (€)</th>
                        <th class="currency">Approved (€)</th>
                        <th style="text-align: center;">Latest IPC</th>
                        <th style="text-align: center;">% Claimed</th>
                        <th style="text-align: center; border-left: 2px solid var(--border-glass);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($matrixData)): ?>
                        <tr><td colspan="17" style="text-align: center; padding: 2rem;">No active capital projects found.</td></tr>
                    <?php else: ?>
                        <?php foreach($matrixData as $row): $f = $row['fin']; $k = $row['kpis']; $vars = $row['vars']; ?>
                            <tr class="main-row">
                                <td>
                                    <div style="display: flex; align-items: center;">
                                        <button class="btn-expand" onclick="toggleVars(<?= $row['id'] ?>)" title="View Variations">⏬</button>
                                        <a href="capital_projects.php?project_id=<?= $row['id'] ?>" style="color: var(--primary-color); text-decoration: none;"><?= htmlspecialchars($row['name']) ?></a>
                                    </div>
                                </td>
                                <td><span style="color: #0ea5e9; font-weight: 500;"><?= htmlspecialchars($row['client_name'] ?? 'N/A') ?></span></td>
                                <td><?= htmlspecialchars($f['actual_client'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($f['ct_reference'] ?? '-') ?></td>
                                <td><?= !empty($f['award_date']) ? date('d M y', strtotime($f['award_date'])) : '-' ?></td>
                                
                                <td>
                                    <?= !empty($f['completion_target']) ? date('d M y', strtotime($f['completion_target'])) : '-' ?>
                                    <?php if ($k['approved_eot_days'] > 0): ?>
                                        <br><span class="badge badge-yellow" style="font-size: 0.65rem; margin-top: 2px;">Rev: <?= date('d M y', strtotime($k['revised_completion'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                
                                <td style="text-align: center; font-weight: 600; color: <?= $k['days_left'] < 0 ? '#ef4444' : ($k['days_left'] < 30 ? '#f59e0b' : 'inherit') ?>;">
                                    <?= $k['days_left'] < 0 ? 'Overdue (' . abs($k['days_left']) . ')' : $k['days_left'] ?>
                                </td>
                                
                                <td class="currency" style="border-left: 2px solid var(--border-glass);"><?= number_format($k['base_value'], 2) ?></td>
                                <td class="currency"><?= number_format($k['app_vars'], 2) ?></td>
                                <td class="currency" style="font-weight: 800; color: #10B981;"><?= number_format($k['total_value'], 2) ?></td>
                                <td class="currency" style="color: #ef4444;"><?= number_format($k['tot_deductions'], 2) ?></td>
                                <td class="currency" style="color: #f59e0b;"><?= number_format($k['pen_vars'], 2) ?></td>
                                
                                <td class="currency" style="border-left: 2px solid var(--border-glass);"><?= number_format($k['tot_claimed'], 2) ?></td>
                                <td class="currency"><?= number_format($k['tot_approved'], 2) ?></td>
                                <td style="text-align: center;"><span class="badge badge-gray"><?= htmlspecialchars($k['latest_ipc']) ?></span></td>
                                <td style="text-align: center; font-weight: 600;"><?= $k['pct_claimed'] ?>%</td>
                                
                                <td style="text-align: center; border-left: 2px solid var(--border-glass); overflow: visible;">
                                    <div class="action-dropdown">
                                        <button class="btn btn-sm btn-primary" style="margin: 0; padding: 0.3rem 0.75rem;">Manage ▾</button>
                                        <div class="action-dropdown-content">
                                            <a href="capital_projects.php?project_id=<?= $row['id'] ?>">📝 Edit Details</a>
                                            <a href="capital_projects.php?project_id=<?= $row['id'] ?>&open=var">➕ Add Variation</a>
                                            <a href="capital_projects.php?project_id=<?= $row['id'] ?>&open=ded">📉 Add Deduction</a>
                                            <a href="capital_projects.php?project_id=<?= $row['id'] ?>&open=eot">⏳ Add EoT</a>
                                            <a href="capital_projects.php?project_id=<?= $row['id'] ?>&open=ipc">🧾 Add IPC Claim</a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            
                            <tr id="vars-row-<?= $row['id'] ?>" class="sub-row" style="display: none;">
                                <td colspan="17" style="padding: 0;">
                                    <div style="padding: 1rem 3rem; margin-left: 40px;">
                                        <h4 style="margin-bottom: 0.5rem; color: #10b981; border-bottom: 1px solid rgba(16, 185, 129, 0.3); padding-bottom: 0.25rem; display: inline-block;">Itemised Variations</h4>
                                        <?php if (empty($vars)): ?>
                                            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">No variations logged yet.</p>
                                        <?php else: ?>
                                            <table style="width: auto; background: transparent; font-size: 0.8rem; border-collapse: collapse; margin-top: 0.5rem;">
                                                <tbody>
                                                    <?php foreach ($vars as $v): ?>
                                                        <tr>
                                                            <td style="padding: 0.25rem 1rem 0.25rem 0; font-weight: 600;"><?= htmlspecialchars($v['variation_ref']) ?></td>
                                                            <td style="padding: 0.25rem 1rem; color: var(--text-secondary); max-width: 300px; white-space: normal;"><?= htmlspecialchars($v['description']) ?></td>
                                                            <td style="padding: 0.25rem 1rem; text-align: right; font-weight: bold; font-variant-numeric: tabular-nums;">€<?= number_format($v['amount'], 2) ?></td>
                                                            <td style="padding: 0.25rem 0 0.25rem 1rem;">
                                                                <span class="badge <?= $v['status'] === 'Approved' ? 'badge-green' : ($v['status'] === 'Pending' ? 'badge-yellow' : 'badge-red') ?>" style="font-size: 0.65rem;"><?= $v['status'] ?></span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <script>
        function toggleVars(id) {
            const row = document.getElementById('vars-row-' + id);
            if (row.style.display === 'none') {
                row.style.display = 'table-row';
            } else {
                row.style.display = 'none';
            }
        }
        </script>

    <?php else: ?>
        
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card"><div class="stat-number" style="font-size: 1.5rem;">€<?= number_format($kpis['total_value'], 2) ?></div><div class="stat-label">Total Contract Value</div></div>
            <div class="stat-card"><div class="stat-number" style="font-size: 1.5rem; color: #ef4444;">€<?= number_format($kpis['tot_deductions'], 2) ?></div><div class="stat-label">Itemised Deductions/Fines</div></div>
            <div class="stat-card"><div class="stat-number" style="font-size: 1.5rem; color: #10B981;">€<?= number_format($kpis['tot_claimed'], 2) ?></div><div class="stat-label">Total Claimed (<?= $kpis['pct_claimed'] ?>%)</div></div>
            
            <div class="stat-card" style="border-left: 4px solid #3b82f6;">
                <div class="stat-number" style="font-size: 1.5rem; color: #3b82f6;">€<?= number_format($kpis['retention_held'], 2) ?></div>
                <div class="stat-label">Retention Currently Held</div>
            </div>
            
            <div class="stat-card" style="border-left: 4px solid #8b5cf6;">
                <div class="stat-number" style="font-size: 1.5rem; color: #8b5cf6; display: flex; align-items: baseline; gap: 4px;">
                    <?= $kpis['days_left'] < 0 ? abs($kpis['days_left']) : $kpis['days_left'] ?> 
                    <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-muted);">Days <?= $kpis['days_left'] < 0 ? 'Overdue' : 'Left' ?></span>
                </div>
                <div class="stat-label">
                    Target: <?= !empty($kpis['revised_completion']) ? date('d M Y', strtotime($kpis['revised_completion'])) : '-' ?> 
                    <?php if($kpis['approved_eot_days'] > 0): ?> <span style="color:#f59e0b;">(+<?= $kpis['approved_eot_days'] ?>d)</span><?php endif; ?>
                </div>
            </div>
        </div>

        <details class="custom-accordion" open>
            <summary>📄 Base Contract & Retention Structure (Excl. VAT)</summary>
            <div class="accordion-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_financials">
                    <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Actual Client / Authority</label>
                            <input type="text" name="actual_client" value="<?= htmlspecialchars($fin['actual_client'] ?? '') ?>" placeholder="e.g. Infrastructure Malta" <?= $canEdit ? '' : 'disabled' ?>>
                        </div>
                        <div class="form-group"><label>CT Reference</label><input type="text" name="ct_reference" value="<?= htmlspecialchars($fin['ct_reference'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                        <div class="form-group"><label>Base Order Value (€)</label><input type="number" step="0.01" name="order_value" value="<?= $fin['order_value'] ?? '0.00' ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                        
                        <div class="form-group"><label>Award Date</label><input type="date" name="award_date" value="<?= $fin['award_date'] ?? '' ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                        <div class="form-group"><label>Commencement Date</label><input type="date" name="commencement_date" value="<?= $fin['commencement_date'] ?? '' ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                        <div class="form-group"><label>Completion Target</label><input type="date" name="completion_target" value="<?= $fin['completion_target'] ?? '' ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                    </div>

                    <div style="background: rgba(0,0,0,0.2); border: 1px solid var(--border-glass); border-radius: 8px; padding: 1.5rem;">
                        <h4 style="margin-top: 0; color: var(--primary-color); border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem;">Retention Release Structure</h4>
                        
                        <div class="form-group" style="max-width: 300px; margin-bottom: 1.5rem;">
                            <label>Global Retention Percentage (%)</label>
                            <input type="number" step="0.01" name="retention_percentage" value="<?= $fin['retention_percentage'] ?? '5.00' ?>" <?= $canEdit ? '' : 'disabled' ?>>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                            <div style="background: rgba(14, 165, 233, 0.05); padding: 1rem; border-radius: 6px; border-left: 3px solid #0ea5e9;">
                                <h5 style="margin-top: 0; color: #0ea5e9;">Release 1</h5>
                                <div class="form-group"><label>Split % (e.g. 50%)</label><input type="number" step="0.01" name="ret_split_1_pct" value="<?= $fin['ret_split_1_pct'] ?? '100.00' ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                                <div class="form-group"><label>Release Date</label><input type="date" name="retention_release_date" value="<?= $fin['retention_release_date'] ?? '' ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                                <div class="form-group"><label>Amount Released (€)</label><input type="number" step="0.01" name="retention_released" value="<?= $fin['retention_released'] ?? '0.00' ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                            </div>
                            
                            <div style="background: rgba(168, 85, 247, 0.05); padding: 1rem; border-radius: 6px; border-left: 3px solid #a855f7;">
                                <h5 style="margin-top: 0; color: #a855f7;">Release 2</h5>
                                <div class="form-group"><label>Split % (e.g. 50%)</label><input type="number" step="0.01" name="ret_split_2_pct" value="<?= $fin['ret_split_2_pct'] ?? '0.00' ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                                <div class="form-group"><label>Release Date</label><input type="date" name="retention_release_date_2" value="<?= $fin['retention_release_date_2'] ?? '' ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                                <div class="form-group"><label>Amount Released (€)</label><input type="number" step="0.01" name="retention_released_2" value="<?= $fin['retention_released_2'] ?? '0.00' ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
                            </div>
                        </div>
                    </div>
                    <?php if ($canEdit): ?><button type="submit" class="btn btn-primary" style="margin-top: 1rem;">Save Base Financials & Retentions</button><?php endif; ?>
                </form>
            </div>
        </details>

        <details class="custom-accordion" open>
            <summary>⏳ Extensions of Time (EoT) Log</summary>
            <div class="accordion-content">
                <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="openEotModal('add')" style="margin-bottom: 1rem;">+ Add Extension of Time</button>
                <?php endif; ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>EoT Ref</th>
                                <th>Reason / Description</th>
                                <th>Submission Date</th>
                                <th style="text-align: center;">Days Extended</th>
                                <th style="text-align: center;">Status</th>
                                <?php if ($canEdit): ?><th style="text-align: right;">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($eots)): ?><tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No Extensions of Time logged.</td></tr><?php else: ?>
                                <?php foreach ($eots as $e): ?>
                                    <tr>
                                        <td style="font-weight: 600; color: #8b5cf6;"><?= htmlspecialchars($e['eot_ref']) ?></td>
                                        <td><?= htmlspecialchars($e['description']) ?></td>
                                        <td><?= !empty($e['submitted_date']) ? date('d M Y', strtotime($e['submitted_date'])) : '-' ?></td>
                                        <td style="text-align: center; font-weight: bold;">+<?= (int)$e['days_extended'] ?> Days</td>
                                        <td style="text-align: center;">
                                            <span class="badge <?= $e['status'] === 'Approved' ? 'badge-green' : ($e['status'] === 'Pending' ? 'badge-yellow' : 'badge-red') ?>"><?= $e['status'] ?></span>
                                        </td>
                                        <?php if ($canEdit): ?>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn btn-sm btn-secondary" style="margin: 0;" onclick='openEotModal("edit", <?= json_encode($e, JSON_HEX_APOS) ?>)'>Edit</button>
                                            <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Delete this Extension of Time?');">
                                                <input type="hidden" name="action" value="delete_eot">
                                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" style="margin: 0;">Del</button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <details class="custom-accordion" open>
            <summary>🔄 Variations Log (Excl. VAT)</summary>
            <div class="accordion-content">
                <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="openVarModal('add')" style="margin-bottom: 1rem;">+ Add Variation</button>
                <?php endif; ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Ref No.</th>
                                <th>Description</th>
                                <th>Date Submitted</th>
                                <th style="text-align: right;">Amount (€)</th>
                                <th style="text-align: center;">Status</th>
                                <?php if ($canEdit): ?><th style="text-align: right;">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($variations)): ?><tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No variations logged.</td></tr><?php else: ?>
                                <?php foreach ($variations as $v): ?>
                                    <tr>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($v['variation_ref']) ?></td>
                                        <td><?= htmlspecialchars($v['description']) ?></td>
                                        <td><?= !empty($v['submitted_date']) ? date('d M Y', strtotime($v['submitted_date'])) : '-' ?></td>
                                        <td style="text-align: right; font-weight: 600;"><?= number_format($v['amount'], 2) ?></td>
                                        <td style="text-align: center;">
                                            <span class="badge <?= $v['status'] === 'Approved' ? 'badge-green' : ($v['status'] === 'Pending' ? 'badge-yellow' : 'badge-red') ?>"><?= $v['status'] ?></span>
                                        </td>
                                        <?php if ($canEdit): ?>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn btn-sm btn-secondary" style="margin: 0;" onclick='openVarModal("edit", <?= json_encode($v, JSON_HEX_APOS) ?>)'>Edit</button>
                                            <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Delete this variation?');">
                                                <input type="hidden" name="action" value="delete_variation">
                                                <input type="hidden" name="id" value="<?= $v['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" style="margin: 0;">Del</button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <details class="custom-accordion" open>
            <summary>📉 Itemised Deductions & Fines (Excl. VAT)</summary>
            <div class="accordion-content">
                <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-sm" style="background: var(--danger); color: white; margin-bottom: 1rem;" onclick="openDedModal('add')">+ Add Deduction</button>
                <?php endif; ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Ref No.</th>
                                <th>Reason / Description</th>
                                <th>Date Logged</th>
                                <th style="text-align: right;">Amount Deducted (€)</th>
                                <th style="text-align: center;">Status</th>
                                <?php if ($canEdit): ?><th style="text-align: right;">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($deductions)): ?><tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No deductions logged.</td></tr><?php else: ?>
                                <?php foreach ($deductions as $d): ?>
                                    <tr>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($d['deduction_ref']) ?></td>
                                        <td><?= htmlspecialchars($d['description']) ?></td>
                                        <td><?= !empty($d['date_logged']) ? date('d M Y', strtotime($d['date_logged'])) : '-' ?></td>
                                        <td style="text-align: right; font-weight: 600; color: var(--danger);">-<?= number_format($d['amount'], 2) ?></td>
                                        <td style="text-align: center;">
                                            <span class="badge <?= $d['status'] === 'Applied' ? 'badge-red' : 'badge-gray' ?>"><?= $d['status'] ?></span>
                                        </td>
                                        <?php if ($canEdit): ?>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn btn-sm btn-secondary" style="margin: 0;" onclick='openDedModal("edit", <?= json_encode($d, JSON_HEX_APOS) ?>)'>Edit</button>
                                            <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Delete this deduction?');">
                                                <input type="hidden" name="action" value="delete_deduction">
                                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" style="margin: 0;">Del</button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <details class="custom-accordion" open>
            <summary>🧾 IPC Claims Log (Excl. VAT)</summary>
            <div class="accordion-content">
                <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="openClaimModal('add')" style="margin-bottom: 1rem;">+ Add IPC Claim</button>
                <?php endif; ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>IPC Ref</th>
                                <th>Submission Date</th>
                                <th style="text-align: right;">Amount Claimed (€)</th>
                                <th style="text-align: right;">Amount Approved (€)</th>
                                <th style="text-align: center;">Status</th>
                                <?php if ($canEdit): ?><th style="text-align: right;">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($claims)): ?><tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No claims logged.</td></tr><?php else: ?>
                                <?php foreach ($claims as $c): ?>
                                    <tr>
                                        <td style="font-weight: 600; color: var(--primary-color);"><?= htmlspecialchars($c['ipc_reference']) ?></td>
                                        <td><?= !empty($c['submission_date']) ? date('d M Y', strtotime($c['submission_date'])) : '-' ?></td>
                                        <td style="text-align: right;"><?= number_format($c['amount_claimed'], 2) ?></td>
                                        <td style="text-align: right; font-weight: 600; color: #10B981;"><?= number_format($c['amount_approved'], 2) ?></td>
                                        <td style="text-align: center;">
                                            <span class="badge <?= $c['status'] === 'Paid' ? 'badge-green' : ($c['status'] === 'Pending' ? 'badge-yellow' : 'badge-gray') ?>"><?= $c['status'] ?></span>
                                        </td>
                                        <?php if ($canEdit): ?>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn btn-sm btn-secondary" style="margin: 0;" onclick='openClaimModal("edit", <?= json_encode($c, JSON_HEX_APOS) ?>)'>Edit</button>
                                            <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Delete this claim?');">
                                                <input type="hidden" name="action" value="delete_claim">
                                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" style="margin: 0;">Del</button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <?php if ($canEdit): ?>
        
        <div id="varModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('varModal')">&times;</span>
                <h2 id="varModalTitle" style="margin-bottom: 1rem; color: var(--primary-color);">Add Variation</h2>
                <form method="POST">
                    <input type="hidden" name="action" id="varAction" value="add_variation">
                    <input type="hidden" name="id" id="varId" value="">
                    <div class="form-group"><label>Variation Ref</label><input type="text" name="variation_ref" id="varRef" required></div>
                    <div class="form-group"><label>Description</label><textarea name="description" id="varDesc" rows="2"></textarea></div>
                    <div class="form-group"><label>Amount (€) Excl VAT</label><input type="number" step="0.01" name="amount" id="varAmount" required></div>
                    <div class="form-group"><label>Submission Date</label><input type="date" name="submitted_date" id="varDate"></div>
                    <div class="form-group"><label>Status</label><select name="status" id="varStatus"><option value="Pending">Pending</option><option value="Approved">Approved</option><option value="Rejected">Rejected</option></select></div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Save Variation</button>
                </form>
            </div>
        </div>

        <div id="eotModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('eotModal')">&times;</span>
                <h2 id="eotModalTitle" style="margin-bottom: 1rem; color: #8b5cf6;">Add Extension of Time</h2>
                <form method="POST">
                    <input type="hidden" name="action" id="eotAction" value="add_eot">
                    <input type="hidden" name="id" id="eotId" value="">
                    <div class="form-group"><label>EoT Ref</label><input type="text" name="eot_ref" id="eotRef" required></div>
                    <div class="form-group"><label>Reason for Extension</label><textarea name="description" id="eotDesc" rows="2"></textarea></div>
                    <div class="form-group"><label>Days Extended (Number)</label><input type="number" name="days_extended" id="eotDays" required></div>
                    <div class="form-group"><label>Date Logged / Submitted</label><input type="date" name="submitted_date" id="eotDate"></div>
                    <div class="form-group"><label>Status</label><select name="status" id="eotStatus"><option value="Pending">Pending</option><option value="Approved">Approved</option><option value="Rejected">Rejected</option></select></div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; background: #8b5cf6; border-color: #8b5cf6;">Save Extension of Time</button>
                </form>
            </div>
        </div>

        <div id="dedModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('dedModal')">&times;</span>
                <h2 id="dedModalTitle" style="margin-bottom: 1rem; color: var(--danger);">Add Deduction</h2>
                <form method="POST">
                    <input type="hidden" name="action" id="dedAction" value="add_deduction">
                    <input type="hidden" name="id" id="dedId" value="">
                    <div class="form-group"><label>Deduction Ref / Fine No.</label><input type="text" name="deduction_ref" id="dedRef" required></div>
                    <div class="form-group"><label>Reason</label><textarea name="description" id="dedDesc" rows="2"></textarea></div>
                    <div class="form-group"><label>Amount Deducted (€) Excl VAT</label><input type="number" step="0.01" name="amount" id="dedAmount" required></div>
                    <div class="form-group"><label>Date Logged</label><input type="date" name="date_logged" id="dedDate"></div>
                    <div class="form-group"><label>Status</label><select name="status" id="dedStatus"><option value="Applied">Applied</option><option value="Pending">Pending</option><option value="Reversed">Reversed</option></select></div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; background: var(--danger); border-color: var(--danger);">Save Deduction</button>
                </form>
            </div>
        </div>

        <div id="claimModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('claimModal')">&times;</span>
                <h2 id="claimModalTitle" style="margin-bottom: 1rem; color: var(--primary-color);">Add IPC Claim</h2>
                <form method="POST">
                    <input type="hidden" name="action" id="claimAction" value="add_claim">
                    <input type="hidden" name="id" id="claimId" value="">
                    <div class="form-group"><label>IPC Reference</label><input type="text" name="ipc_reference" id="claimRef" required></div>
                    <div class="form-group"><label>Amount Claimed (€) Excl VAT</label><input type="number" step="0.01" name="amount_claimed" id="claimAmt" required></div>
                    <div class="form-group"><label>Amount Approved (€) Excl VAT</label><input type="number" step="0.01" name="amount_approved" id="claimApp" value="0.00"></div>
                    <div class="form-group"><label>Submission Date</label><input type="date" name="submission_date" id="claimDate"></div>
                    <div class="form-group"><label>Status</label><select name="status" id="claimStatus"><option value="Pending">Pending</option><option value="Approved">Approved</option><option value="Paid">Paid</option></select></div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Save Claim</button>
                </form>
            </div>
        </div>

        <script>
        function openVarModal(mode, data = null) {
            document.getElementById('varModalTitle').textContent = mode === 'edit' ? 'Edit Variation' : 'Add Variation';
            document.getElementById('varAction').value = mode === 'edit' ? 'edit_variation' : 'add_variation';
            document.getElementById('varId').value = data ? data.id : '';
            document.getElementById('varRef').value = data ? data.variation_ref : '';
            document.getElementById('varDesc').value = data ? data.description : '';
            document.getElementById('varAmount').value = data ? data.amount : '';
            document.getElementById('varDate').value = data ? data.submitted_date : '';
            document.getElementById('varStatus').value = data ? data.status : 'Pending';
            document.getElementById('varModal').style.display = 'block';
        }
        
        function openEotModal(mode, data = null) {
            document.getElementById('eotModalTitle').textContent = mode === 'edit' ? 'Edit Extension of Time' : 'Add Extension of Time';
            document.getElementById('eotAction').value = mode === 'edit' ? 'edit_eot' : 'add_eot';
            document.getElementById('eotId').value = data ? data.id : '';
            document.getElementById('eotRef').value = data ? data.eot_ref : '';
            document.getElementById('eotDesc').value = data ? data.description : '';
            document.getElementById('eotDays').value = data ? data.days_extended : '';
            document.getElementById('eotDate').value = data ? data.submitted_date : '';
            document.getElementById('eotStatus').value = data ? data.status : 'Pending';
            document.getElementById('eotModal').style.display = 'block';
        }

        function openDedModal(mode, data = null) {
            document.getElementById('dedModalTitle').textContent = mode === 'edit' ? 'Edit Deduction' : 'Add Deduction';
            document.getElementById('dedAction').value = mode === 'edit' ? 'edit_deduction' : 'add_deduction';
            document.getElementById('dedId').value = data ? data.id : '';
            document.getElementById('dedRef').value = data ? data.deduction_ref : '';
            document.getElementById('dedDesc').value = data ? data.description : '';
            document.getElementById('dedAmount').value = data ? data.amount : '';
            document.getElementById('dedDate').value = data ? data.date_logged : '';
            document.getElementById('dedStatus').value = data ? data.status : 'Applied';
            document.getElementById('dedModal').style.display = 'block';
        }

        function openClaimModal(mode, data = null) {
            document.getElementById('claimModalTitle').textContent = mode === 'edit' ? 'Edit Claim' : 'Add Claim';
            document.getElementById('claimAction').value = mode === 'edit' ? 'edit_claim' : 'add_claim';
            document.getElementById('claimId').value = data ? data.id : '';
            document.getElementById('claimRef').value = data ? data.ipc_reference : '';
            document.getElementById('claimAmt').value = data ? data.amount_claimed : '';
            document.getElementById('claimApp').value = data ? data.amount_approved : '0.00';
            document.getElementById('claimDate').value = data ? data.submission_date : '';
            document.getElementById('claimStatus').value = data ? data.status : 'Pending';
            document.getElementById('claimModal').style.display = 'block';
        }

        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) event.target.style.display = "none";
        }
        
        // Auto-open modals if requested via URL parameters
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const openModal = urlParams.get('open');
            if (openModal === 'var') openVarModal('add');
            if (openModal === 'ded') openDedModal('add');
            if (openModal === 'eot') openEotModal('add');
            if (openModal === 'ipc') openClaimModal('add');
        });
        </script>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
