<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('view_subcontractor_accounts') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}
$canManage = hasPermission('manage_subcontractor_accounts') || isAdmin();

$message = ''; $error = '';

// Get Accessible Clients for the User
if (isAdmin()) {
    $accessibleClients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
} else {
    $accessibleClients = getUserClients($pdo, getCurrentUserId());
}

$selected_client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$sub_id = isset($_GET['sub_id']) ? (int)$_GET['sub_id'] : null;

// Handling POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && $selected_client_id) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_work') {
            $post_sub_id = $_POST['subcontractor_id'];
            $project_id = !empty($_POST['project_id']) ? $_POST['project_id'] : null;
            $vat_rate = isset($_POST['vat_rate']) ? (float)$_POST['vat_rate'] : 18.00;
            
            if (empty($_POST['work_id'])) {
                $stmt = $pdo->prepare("INSERT INTO subcontractor_works (subcontractor_id, client_id, project_id, work_reference, po_reference, vat_rate, responsible, total_exc_vat, total_inc_vat, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([ $post_sub_id, $selected_client_id, $project_id, trim($_POST['work_reference']), trim($_POST['po_reference'] ?? ''), $vat_rate, trim($_POST['responsible']), $_POST['total_exc_vat'] ?: 0, $_POST['total_inc_vat'] ?: 0, trim($_POST['notes']) ]);
                $message = "Work Order added successfully!";
            } else {
                $stmt = $pdo->prepare("UPDATE subcontractor_works SET project_id=?, work_reference=?, po_reference=?, vat_rate=?, responsible=?, total_exc_vat=?, total_inc_vat=?, notes=? WHERE id=? AND subcontractor_id=? AND client_id=?");
                $stmt->execute([ $project_id, trim($_POST['work_reference']), trim($_POST['po_reference'] ?? ''), $vat_rate, trim($_POST['responsible']), $_POST['total_exc_vat'] ?: 0, $_POST['total_inc_vat'] ?: 0, trim($_POST['notes']), $_POST['work_id'], $post_sub_id, $selected_client_id ]);
                $message = "Work Order updated successfully!";
            }
        } 
        elseif ($action === 'delete_work') {
            $pdo->prepare("DELETE FROM subcontractor_works WHERE id=? AND client_id=?")->execute([$_POST['work_id'], $selected_client_id]);
            $message = "Work Order deleted.";
        }
        elseif ($action === 'save_transaction') {
            $post_sub_id = $_POST['subcontractor_id'];
            $work_id = !empty($_POST['work_id']) ? $_POST['work_id'] : null;

            if (empty($_POST['transaction_id'])) {
                $stmt = $pdo->prepare("INSERT INTO subcontractor_transactions (subcontractor_id, client_id, work_id, transaction_date, transaction_type, amount, reference, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([ $post_sub_id, $selected_client_id, $work_id, $_POST['transaction_date'], $_POST['transaction_type'], $_POST['amount'] ?: 0, trim($_POST['reference']), trim($_POST['notes']), getCurrentUserId() ]);
                $message = "Activity logged successfully!";
            } else {
                $stmt = $pdo->prepare("UPDATE subcontractor_transactions SET work_id=?, transaction_date=?, transaction_type=?, amount=?, reference=?, notes=? WHERE id=? AND subcontractor_id=? AND client_id=?");
                $stmt->execute([ $work_id, $_POST['transaction_date'], $_POST['transaction_type'], $_POST['amount'] ?: 0, trim($_POST['reference']), trim($_POST['notes']), $_POST['transaction_id'], $post_sub_id, $selected_client_id ]);
                $message = "Activity updated successfully!";
            }
        }
        elseif ($action === 'delete_transaction') {
            $pdo->prepare("DELETE FROM subcontractor_transactions WHERE id=? AND client_id=?")->execute([$_POST['transaction_id'], $selected_client_id]);
            $message = "Activity record deleted.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Fetch global lists for modals
$allSubcontractors = $pdo->query("SELECT id, name FROM subcontractors ORDER BY name ASC")->fetchAll();
$clientProjects = [];
if ($selected_client_id) {
    $cpStmt = $pdo->prepare("SELECT id, name FROM projects WHERE clientid = ? ORDER BY name ASC");
    $cpStmt->execute([$selected_client_id]);
    $clientProjects = $cpStmt->fetchAll();
}

$pageTitle = 'Subcontractor Accounts';
require_once 'header.php';
?>

<style>
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }
.summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.summary-card { background: var(--bg-panel); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--border-glass); text-align: center; }
.summary-card h4 { margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;}
.summary-card .value { font-size: 1.5rem; font-weight: bold; color: var(--text-primary); }
.delta-positive { color: #10B981 !important; } /* Overpaid / Favorable */
.delta-negative { color: #EF4444 !important; } /* Owed / Liability */
.client-bar { background: rgba(99, 102, 241, 0.1); border: 1px solid var(--primary-color); padding: 1rem 1.5rem; border-radius: 8px; display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem; }
.client-bar select { padding: 0.5rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-card); color: var(--text-primary); font-size: 1rem; min-width: 250px; }
.badge { padding: 2px 6px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
.badge-cert { background: rgba(59, 130, 246, 0.2); color: #3B82F6; }
.badge-inv { background: rgba(245, 158, 11, 0.2); color: #F59E0B; }
.badge-pay { background: rgba(16, 185, 129, 0.2); color: #10B981; }
.badge-po { display: inline-block; background: rgba(139, 92, 246, 0.15); color: #8B5CF6; padding: 2px 6px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; margin-top: 4px;}
.data-table th.col-divider, .data-table td.col-divider { border-left: 2px solid rgba(255,255,255,0.05); }

/* Progress Bar Styles */
.progress-wrapper { width: 100%; background-color: rgba(255,255,255,0.1); border-radius: 4px; overflow: hidden; height: 6px; margin-top: 6px; }
.progress-fill { background-color: #3B82F6; height: 100%; transition: width 0.3s ease; }
.progress-fill.over { background-color: #EF4444; } /* Red if > 100% */
</style>

<div class="main-container">
    
    <div class="client-bar">
        <strong style="color: var(--primary-color);">Account Context (Client):</strong>
        <form method="GET" style="margin: 0;">
            <select name="client_id" onchange="this.form.submit()">
                <option value="">-- Select a Client --</option>
                <?php foreach($accessibleClients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selected_client_id == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($sub_id): ?>
                <input type="hidden" name="sub_id" value="<?= $sub_id ?>">
            <?php endif; ?>
        </form>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$selected_client_id): ?>
        <div class="empty-state" style="padding: 4rem 2rem; text-align: center; background: var(--bg-panel); border-radius: 8px;">
            <h2 style="color: var(--text-secondary);">Select a Client to Begin</h2>
            <p>Subcontractor accounts are strictly isolated by client. Please select a client to view their liabilities and payments.</p>
        </div>
    <?php else: ?>

        <?php if (!$sub_id): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <h1 class="page-title" style="margin-bottom: 0;">Master Subcontractor Ledger</h1>
                    <p style="color: var(--text-secondary); margin-top: 0.25rem;">Overview of certified works, invoices, and payments for this client.</p>
                </div>
                <?php if ($canManage): ?>
                    <div style="display: flex; gap: 0.5rem;">
                        <button onclick="openWorkModal()" class="btn btn-primary">+ Create Work Order</button>
                        <button onclick="openTxModal()" class="btn btn-secondary">+ Log Global Payment</button>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            $ledgerStmt = $pdo->prepare("
                SELECT 
                    s.id, s.name, s.contact_person,
                    COALESCE((SELECT SUM(amount) FROM subcontractor_transactions t WHERE t.subcontractor_id = s.id AND t.client_id = ? AND t.transaction_type = 'Certification'), 0) as total_certified,
                    COALESCE((SELECT SUM(amount) FROM subcontractor_transactions t WHERE t.subcontractor_id = s.id AND t.client_id = ? AND t.transaction_type = 'Invoice'), 0) as total_invoiced,
                    COALESCE((SELECT SUM(amount) FROM subcontractor_transactions t WHERE t.subcontractor_id = s.id AND t.transaction_type = 'Payment' AND t.client_id = ?), 0) as total_paid
                FROM subcontractors s
                WHERE EXISTS (SELECT 1 FROM subcontractor_works w WHERE w.subcontractor_id = s.id AND w.client_id = ?)
                   OR EXISTS (SELECT 1 FROM subcontractor_transactions t WHERE t.subcontractor_id = s.id AND t.client_id = ?)
                ORDER BY s.name ASC
            ");
            $ledgerStmt->execute([$selected_client_id, $selected_client_id, $selected_client_id, $selected_client_id, $selected_client_id]);
            $ledger = $ledgerStmt->fetchAll();
            ?>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Subcontractor</th>
                            <th style="text-align: right;">Total Certified</th>
                            <th style="text-align: right;">Total Invoiced</th>
                            <th style="text-align: right;">Paid to Date</th>
                            <th style="text-align: right;" class="col-divider">Due vs Certified (True)</th>
                            <th style="text-align: right;">Due vs Invoiced (Paper)</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ledger)): ?>
                            <tr><td colspan="7" style="text-align: center; padding: 2rem;">No subcontractor accounts found for this client.</td></tr>
                        <?php else: ?>
                            <?php 
                            $sys_cert = 0; $sys_inv = 0; $sys_paid = 0;
                            foreach ($ledger as $l): 
                                $sys_cert += $l['total_certified'];
                                $sys_inv += $l['total_invoiced'];
                                $sys_paid += $l['total_paid'];
                                $due_cert = $l['total_certified'] - $l['total_paid'];
                                $due_inv = $l['total_invoiced'] - $l['total_paid'];
                            ?>
                            <tr>
                                <td style="font-weight: bold; color: var(--primary-color);"><?= htmlspecialchars($l['name']) ?></td>
                                <td style="text-align: right;">€<?= number_format($l['total_certified'], 2) ?></td>
                                <td style="text-align: right;">€<?= number_format($l['total_invoiced'], 2) ?></td>
                                <td style="text-align: right; color: #10B981;">€<?= number_format($l['total_paid'], 2) ?></td>
                                <td style="text-align: right; font-weight: bold;" class="col-divider <?= $due_cert > 0 ? 'delta-negative' : 'delta-positive' ?>">
                                    €<?= number_format($due_cert, 2) ?>
                                </td>
                                <td style="text-align: right; font-weight: bold;" class="<?= $due_inv > 0 ? 'delta-negative' : 'delta-positive' ?>">
                                    €<?= number_format($due_inv, 2) ?>
                                </td>
                                <td style="text-align: center;">
                                    <a href="?client_id=<?= $selected_client_id ?>&sub_id=<?= $l['id'] ?>" class="btn btn-sm btn-secondary">View Account</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($ledger)): ?>
                    <tfoot>
                        <tr style="background: rgba(255,255,255,0.05); font-weight: bold;">
                            <td>CLIENT TOTALS</td>
                            <td style="text-align: right;">€<?= number_format($sys_cert, 2) ?></td>
                            <td style="text-align: right;">€<?= number_format($sys_inv, 2) ?></td>
                            <td style="text-align: right; color: #10B981;">€<?= number_format($sys_paid, 2) ?></td>
                            <td style="text-align: right;" class="col-divider <?= ($sys_cert - $sys_paid) > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($sys_cert - $sys_paid, 2) ?></td>
                            <td style="text-align: right;" class="<?= ($sys_inv - $sys_paid) > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($sys_inv - $sys_paid, 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

        <?php else: ?>
            <?php
            $stmt = $pdo->prepare("SELECT * FROM subcontractors WHERE id = ?");
            $stmt->execute([$sub_id]);
            $sub = $stmt->fetch();
            if (!$sub) die("Subcontractor not found.");

            // Fetch Available Invoices for the "Match Invoice" feature
            $invStmt = $pdo->prepare("SELECT id, reference, amount FROM subcontractor_transactions WHERE subcontractor_id = ? AND client_id = ? AND transaction_type = 'Invoice' ORDER BY transaction_date DESC");
            $invStmt->execute([$sub_id, $selected_client_id]);
            $availableInvoices = $invStmt->fetchAll();

            // Fetch Works & Calculate iteratively from transactions
            $wStmt = $pdo->prepare("
                SELECT w.*, p.name as project_name,
                (SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = w.id AND transaction_type = 'Certification') as cert_total,
                (SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = w.id AND transaction_type = 'Invoice') as inv_total,
                (SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = w.id AND transaction_type = 'Payment') as pay_total
                FROM subcontractor_works w 
                LEFT JOIN projects p ON w.project_id = p.id 
                WHERE w.subcontractor_id = ? AND w.client_id = ? 
                ORDER BY w.id DESC
            ");
            $wStmt->execute([$sub_id, $selected_client_id]);
            $works = $wStmt->fetchAll();

            // Fetch All Transactions
            $tStmt = $pdo->prepare("
                SELECT t.*, w.work_reference 
                FROM subcontractor_transactions t
                LEFT JOIN subcontractor_works w ON t.work_id = w.id
                WHERE t.subcontractor_id = ? AND t.client_id = ? 
                ORDER BY t.transaction_date DESC, t.id DESC
            ");
            $tStmt->execute([$sub_id, $selected_client_id]);
            $transactions = $tStmt->fetchAll();

            // Global Calculations
            $tot_cert = 0; $tot_paid = 0; $tot_inv = 0;
            foreach ($transactions as $t) {
                if ($t['transaction_type'] === 'Certification') $tot_cert += $t['amount'];
                if ($t['transaction_type'] === 'Payment') $tot_paid += $t['amount'];
                if ($t['transaction_type'] === 'Invoice') $tot_inv += $t['amount'];
            }
            $due_cert_global = $tot_cert - $tot_paid;
            $due_inv_global = $tot_inv - $tot_paid;
            ?>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <a href="subcontractor_accounts.php?client_id=<?= $selected_client_id ?>" style="color: var(--text-muted); text-decoration: none; font-size: 0.9rem;">&larr; Back to Client Ledger</a>
                    <h1 class="page-title" style="margin-bottom: 0; margin-top: 0.5rem;">Account: <?= htmlspecialchars($sub['name']) ?></h1>
                </div>
                <?php if ($canManage): ?>
                    <div style="display: flex; gap: 0.5rem;">
                        <button onclick="openWorkModal()" class="btn btn-primary">+ Create Work Order</button>
                        <button onclick="openTxModal()" class="btn btn-secondary">+ Log Global Activity</button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Total Certified (Inc VAT)</h4>
                    <div class="value" style="color: #3B82F6;">€<?= number_format($tot_cert, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Paid to Date</h4>
                    <div class="value" style="color: #10B981;">€<?= number_format($tot_paid, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>True Liability (Due vs Cert)</h4>
                    <div class="value <?= $due_cert_global > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($due_cert_global, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Paper Liability (Due vs Inv)</h4>
                    <div class="value <?= $due_inv_global > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($due_inv_global, 2) ?></div>
                </div>
            </div>

            <h3 style="margin-top: 2rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Work Orders & Progress</h3>
            <div class="table-container" style="margin-bottom: 3rem;">
                <table class="data-table" style="font-size: 0.85rem;">
                    <thead>
                        <tr>
                            <th>Work Ref / Project</th>
                            <th style="text-align: right;">Est. Value</th>
                            <th style="text-align: right;">Certified</th>
                            <th style="text-align: center; width: 100px;">% Progress</th>
                            <th style="text-align: right;">Invoiced</th>
                            <th style="text-align: right;">Paid</th>
                            <th style="text-align: right;" class="col-divider">Pending Inv</th>
                            <th style="text-align: right;">Due vs Cert</th>
                            <th style="text-align: right;">Due vs Inv</th>
                            <?php if($canManage): ?><th style="text-align: center;">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($works)): ?>
                            <tr><td colspan="<?= $canManage ? '10' : '9' ?>" style="text-align: center;">No Work Orders found.</td></tr>
                        <?php else: ?>
                            <?php foreach($works as $w): 
                                $c_tot = $w['cert_total'] ?: 0;
                                $i_tot = $w['inv_total'] ?: 0;
                                $p_tot = $w['pay_total'] ?: 0;
                                
                                $pend_inv = $c_tot - $i_tot;
                                $due_c = $c_tot - $p_tot;
                                $due_i = $i_tot - $p_tot;

                                // Progress Calculation
                                $prog_pct = $w['total_inc_vat'] > 0 ? ($c_tot / $w['total_inc_vat']) * 100 : 0;
                                $prog_class = $prog_pct > 100 ? 'over' : '';
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; font-size: 0.95rem;"><?= htmlspecialchars($w['work_reference']) ?></div>
                                    <div style="color: var(--text-muted);"><?= htmlspecialchars($w['project_name'] ?? 'General') ?></div>
                                    <?php if(!empty($w['po_reference'])): ?>
                                        <div class="badge-po">PO: <?= htmlspecialchars($w['po_reference']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; color: var(--text-secondary);">€<?= number_format($w['total_inc_vat'], 2) ?></td>
                                <td style="text-align: right; font-weight: bold; color: #3B82F6;">€<?= number_format($c_tot, 2) ?></td>
                                
                                <td style="text-align: center; vertical-align: middle;">
                                    <div style="font-size: 0.8rem; font-weight: bold; margin-bottom: 2px; color: <?= $prog_pct > 100 ? '#EF4444' : '#3B82F6' ?>;">
                                        <?= number_format($prog_pct, 1) ?>%
                                    </div>
                                    <div class="progress-wrapper">
                                        <div class="progress-fill <?= $prog_class ?>" style="width: <?= min(100, $prog_pct) ?>%;"></div>
                                    </div>
                                </td>

                                <td style="text-align: right; font-weight: bold; color: #F59E0B;">€<?= number_format($i_tot, 2) ?></td>
                                <td style="text-align: right; font-weight: bold; color: #10B981;">€<?= number_format($p_tot, 2) ?></td>
                                
                                <td style="text-align: right;" class="col-divider <?= $pend_inv > 0 ? 'delta-negative' : '' ?>">€<?= number_format($pend_inv, 2) ?></td>
                                <td style="text-align: right; font-weight: bold;" class="<?= $due_c > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($due_c, 2) ?></td>
                                <td style="text-align: right; font-weight: bold;" class="<?= $due_i > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($due_i, 2) ?></td>
                                
                                <?php if($canManage): ?>
                                <td style="text-align: center;">
                                    <button onclick="openTxModal(null, <?= $w['id'] ?>, 'Certification')" class="btn btn-sm btn-primary" title="Log Activity">+</button>
                                    <button onclick='openWorkModal(<?= json_encode($w, JSON_HEX_APOS) ?>)' class="btn btn-sm btn-secondary" title="Edit">✎</button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h3 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Complete Activity Log</h3>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Linked Work Order</th>
                            <th>Reference</th>
                            <th style="text-align: right;">Amount</th>
                            <th>Notes</th>
                            <?php if($canManage): ?><th style="text-align: right;">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="<?= $canManage ? '7' : '6' ?>" style="text-align: center;">No activity logged yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($transactions as $t): 
                                $badgeClass = 'badge-pay';
                                if ($t['transaction_type'] === 'Certification') $badgeClass = 'badge-cert';
                                if ($t['transaction_type'] === 'Invoice') $badgeClass = 'badge-inv';
                            ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($t['transaction_type']) ?></span></td>
                                <td style="font-size: 0.85rem; color: var(--text-secondary);"><?= htmlspecialchars($t['work_reference'] ?? 'Global / Unlinked') ?></td>
                                <td><?= htmlspecialchars($t['reference']) ?></td>
                                <td style="text-align: right; font-weight: bold;">€<?= number_format($t['amount'], 2) ?></td>
                                <td><span style="font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars($t['notes']) ?></span></td>
                                <?php if($canManage): ?>
                                <td style="text-align: right;">
                                    <button onclick='openTxModal(<?= json_encode($t, JSON_HEX_APOS) ?>)' class="btn btn-sm btn-secondary">Edit</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?');">
                                        <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                                        <input type="hidden" name="action" value="delete_transaction"><input type="hidden" name="transaction_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">X</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; // End Detail View ?>
        
        <?php if ($canManage): ?>
        
        <div id="workModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('workModal')">&times;</span>
                <h2 id="wModalTitle" style="color: var(--primary-color);">Create Work Order</h2>
                <form method="POST">
                    <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                    <input type="hidden" name="action" value="save_work">
                    <input type="hidden" name="work_id" id="w_id">
                    
                    <?php if (!$sub_id): ?>
                        <div class="form-group">
                            <label>Subcontractor *</label>
                            <select name="subcontractor_id" required>
                                <option value="">-- Choose Subcontractor --</option>
                                <?php foreach($allSubcontractors as $subc): ?><option value="<?= $subc['id'] ?>"><?= htmlspecialchars($subc['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="subcontractor_id" value="<?= $sub_id ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Project</label>
                        <select name="project_id" id="w_project_id">
                            <option value="">-- General / No Specific Project --</option>
                            <?php foreach($clientProjects as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-grid" style="grid-template-columns: 2fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label>Work Reference / Description *</label>
                            <input type="text" name="work_reference" id="w_ref" placeholder="e.g. Concrete Works Block A" required>
                        </div>
                        <div class="form-group">
                            <label>PO Reference (Optional)</label>
                            <input type="text" name="po_reference" id="w_po" placeholder="e.g. PO-2026-451">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Responsible Person</label>
                        <input type="text" name="responsible" id="w_resp">
                    </div>
                    
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label>Est. Exc VAT *</label>
                            <input type="number" step="0.01" name="total_exc_vat" id="w_exc" oninput="calculateVat()" required>
                        </div>
                        <div class="form-group">
                            <label>VAT Rate</label>
                            <select name="vat_rate" id="w_vat_rate" onchange="calculateVat()">
                                <option value="18.00">18% (Standard)</option>
                                <option value="0.00">0% (Exempt)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Est. Inc VAT</label>
                            <input type="number" step="0.01" name="total_inc_vat" id="w_inc" readonly style="background: rgba(255,255,255,0.05); cursor: not-allowed;">
                        </div>
                    </div>
                    
                    <div class="form-group"><label>Notes / Contract Details</label><textarea name="notes" id="w_notes" rows="3"></textarea></div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Save Work Order</button>
                </form>
            </div>
        </div>

        <div id="txModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('txModal')">&times;</span>
                <h2 id="tModalTitle" style="color: var(--primary-color);">Log Activity</h2>
                <form method="POST">
                    <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                    <input type="hidden" name="action" value="save_transaction">
                    <input type="hidden" name="transaction_id" id="t_id">
                    
                    <?php if (!$sub_id): ?>
                        <div class="form-group">
                            <label>Subcontractor *</label>
                            <select name="subcontractor_id" required>
                                <option value="">-- Choose Subcontractor --</option>
                                <?php foreach($allSubcontractors as $subc): ?><option value="<?= $subc['id'] ?>"><?= htmlspecialchars($subc['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="subcontractor_id" value="<?= $sub_id ?>">
                        <div class="form-group">
                            <label>Link to Specific Work Order</label>
                            <select name="work_id" id="t_work_id">
                                <option value="">-- Global / Unlinked --</option>
                                <?php if(!empty($works)): foreach($works as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['work_reference']) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group"><label>Date *</label><input type="date" name="transaction_date" id="t_date" required value="<?= date('Y-m-d') ?>"></div>
                        <div class="form-group">
                            <label>Activity Type *</label>
                            <select name="transaction_type" id="t_type" required onchange="toggleInvoiceMatch()">
                                <option value="Certification">1. Certification (Increases Owed)</option>
                                <option value="Invoice">2. Invoice Received (Paper Trail)</option>
                                <option value="Payment">3. Payment Made (Reduces Owed)</option>
                                <option value="Credit Note">Credit Note</option>
                                <option value="Adjustment">Adjustment</option>
                            </select>
                        </div>
                    </div>

                    <?php if ($sub_id && !empty($availableInvoices)): ?>
                    <div class="form-group" id="t_invoice_match_group" style="display:none; background: rgba(59, 130, 246, 0.1); padding: 10px; border-radius: 6px; border: 1px solid rgba(59, 130, 246, 0.3);">
                        <label style="color: #3B82F6;">⚡ Match to Existing Invoice (Auto-fill)</label>
                        <select id="t_invoice_match" onchange="applyInvoiceMatch()">
                            <option value="">-- Select an Invoice to Pay --</option>
                            <?php foreach($availableInvoices as $inv): ?>
                                <option value="<?= $inv['amount'] ?>" data-ref="<?= htmlspecialchars($inv['reference']) ?>">
                                    Inv: <?= htmlspecialchars($inv['reference'] ?: 'No Ref') ?> (€<?= number_format($inv['amount'], 2) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group"><label>Amount (Inc VAT) *</label><input type="number" step="0.01" name="amount" id="t_amount" required></div>
                    <div class="form-group"><label>Reference (e.g. Cert #2, Inv #100, Chq #555)</label><input type="text" name="reference" id="t_ref"></div>
                    <div class="form-group"><label>Notes</label><textarea name="notes" id="t_notes" rows="2"></textarea></div>
                    
                    <button type="submit" class="btn btn-secondary" style="width: 100%; margin-top: 10px;">Save Activity</button>
                </form>
            </div>
        </div>

        <script>
        function calculateVat() {
            let exc = parseFloat(document.getElementById('w_exc').value) || 0;
            let rate = parseFloat(document.getElementById('w_vat_rate').value) || 0;
            let inc = exc + (exc * (rate / 100));
            document.getElementById('w_inc').value = inc.toFixed(2);
        }

        function openWorkModal(data = null) {
            if (data) {
                document.getElementById('wModalTitle').textContent = 'Edit Work Order';
                document.getElementById('w_id').value = data.id;
                document.getElementById('w_project_id').value = data.project_id || '';
                document.getElementById('w_ref').value = data.work_reference;
                document.getElementById('w_po').value = data.po_reference || '';
                document.getElementById('w_resp').value = data.responsible;
                document.getElementById('w_vat_rate').value = data.vat_rate || '18.00';
                document.getElementById('w_exc').value = data.total_exc_vat;
                document.getElementById('w_inc').value = data.total_inc_vat;
                document.getElementById('w_notes').value = data.notes;
            } else {
                document.getElementById('wModalTitle').textContent = 'Create Work Order';
                document.getElementById('w_id').value = '';
                document.getElementById('w_ref').value = '';
                document.getElementById('w_po').value = '';
                document.getElementById('w_vat_rate').value = '18.00';
                document.getElementById('w_exc').value = '';
                document.getElementById('w_inc').value = '';
            }
            document.getElementById('workModal').style.display = 'block';
        }

        function openTxModal(data = null, work_id = null, type = null) {
            if (data) {
                document.getElementById('tModalTitle').textContent = 'Edit Activity';
                document.getElementById('t_id').value = data.id;
                if(document.getElementById('t_work_id')) document.getElementById('t_work_id').value = data.work_id || '';
                document.getElementById('t_date').value = data.transaction_date;
                document.getElementById('t_type').value = data.transaction_type;
                document.getElementById('t_amount').value = data.amount;
                document.getElementById('t_ref').value = data.reference;
                document.getElementById('t_notes').value = data.notes;
            } else {
                document.getElementById('tModalTitle').textContent = 'Log Activity';
                document.getElementById('t_id').value = '';
                document.getElementById('t_amount').value = '';
                document.getElementById('t_ref').value = '';
                
                // Pre-fill from Quick Actions
                if(work_id && document.getElementById('t_work_id')) document.getElementById('t_work_id').value = work_id;
                if(type) document.getElementById('t_type').value = type;
            }
            
            toggleInvoiceMatch();
            document.getElementById('txModal').style.display = 'block';
        }

        function toggleInvoiceMatch() {
            var matchGroup = document.getElementById('t_invoice_match_group');
            var typeSelect = document.getElementById('t_type');
            if(matchGroup && typeSelect) {
                matchGroup.style.display = typeSelect.value === 'Payment' ? 'block' : 'none';
            }
        }

        function applyInvoiceMatch() {
            var sel = document.getElementById('t_invoice_match');
            if(sel && sel.value) {
                var opt = sel.options[sel.selectedIndex];
                document.getElementById('t_amount').value = sel.value;
                document.getElementById('t_ref').value = opt.getAttribute('data-ref') ? 'Paid ' + opt.getAttribute('data-ref') : 'Payment for Invoice';
            }
        }

        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        window.onclick = function(event) {
            if (event.target == document.getElementById('workModal')) closeModal('workModal');
            if (event.target == document.getElementById('txModal')) closeModal('txModal');
        }
        </script>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
