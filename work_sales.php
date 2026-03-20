<?php
require_once 'init.php';
require_once 'session-check.php';

$userId = getCurrentUserId();
$isAdmin = isAdmin();

// Determine Access Levels
$access = [
    'Demolition_Excavation' => ['view' => hasPermission('view_sales_demo_exc') || $isAdmin, 'manage' => hasPermission('manage_sales_demo_exc') || $isAdmin],
    'Construction' => ['view' => hasPermission('view_sales_const') || $isAdmin, 'manage' => hasPermission('manage_sales_const') || $isAdmin],
    'Finishes' => ['view' => hasPermission('view_sales_finishes') || $isAdmin, 'manage' => hasPermission('manage_sales_finishes') || $isAdmin]
];

$canApproveQuotes = hasPermission('approve_quotes') || $isAdmin;

if (!$access['Demolition_Excavation']['view'] && !$access['Construction']['view'] && !$access['Finishes']['view']) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // 1. Create New Quote
        if ($action === 'create_quote') {
            $type = $_POST['quote_type'];
            if (!$access[$type]['manage']) throw new Exception("Unauthorized.");
            if (empty($_POST['project_id'])) throw new Exception("A project MUST be selected for this quote.");
            
            // Fetch default terms
            $termStmt = $pdo->prepare("SELECT terms_text FROM sales_default_terms WHERE quote_type = ?");
            $termStmt->execute([$type]);
            $defTerms = $termStmt->fetchColumn();
            
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO sales_quotes (client_id, project_id, quote_type, reference_number, vat_rate, created_by, terms_conditions) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['client_id'], 
                $_POST['project_id'], 
                $type, 
                trim($_POST['reference_number']), 
                $_POST['vat_rate'] ?? 18.00, 
                $userId,
                $defTerms ?: ''
            ]);
            $newQuoteId = $pdo->lastInsertId();
            
            // --- DYNAMIC PRE-POPULATION ---
            $stmtStd = $pdo->prepare("SELECT * FROM sales_standard_items WHERE quote_type = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC");
            $stmtStd->execute([$type]);
            $stdItems = $stmtStd->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($stdItems)) {
                // DEFAULT QUANTITY STRICTLY SET TO 0.00
                $stmtItem = $pdo->prepare("INSERT INTO sales_quote_items (quote_id, category, description, unit, estimated_qty, unit_rate, sort_order) VALUES (?, ?, ?, ?, 0.00, ?, ?)");
                foreach ($stdItems as $item) {
                    $stmtItem->execute([$newQuoteId, $item['category'], $item['description'], $item['unit'], $item['default_rate'], $item['sort_order']]);
                }
            }
            
            $pdo->commit();
            header("Location: work_sales.php?quote_id=" . $newQuoteId . "&msg=created");
            exit;
        }

        // 2. Change Status (Workflow)
        if ($action === 'change_status') {
            $qId = (int)$_POST['quote_id'];
            $newStatus = $_POST['new_status'];
            
            if ($newStatus === 'Pending Approval') {
                $stmt = $pdo->prepare("UPDATE sales_quotes SET status = 'Pending Approval' WHERE id = ?");
                $stmt->execute([$qId]);
                $message = "Quote submitted for approval.";
            } elseif (in_array($newStatus, ['Approved', 'Rejected'])) {
                if (!$canApproveQuotes) throw new Exception("You are not authorized to approve quotes.");
                if ($newStatus === 'Approved') {
                    $stmt = $pdo->prepare("UPDATE sales_quotes SET status = 'Approved', approver_id = ?, approved_at = NOW() WHERE id = ?");
                    $stmt->execute([$userId, $qId]);
                    $message = "Quote Approved! It can now be printed and sent.";
                } else {
                    $stmt = $pdo->prepare("UPDATE sales_quotes SET status = 'Rejected' WHERE id = ?");
                    $stmt->execute([$qId]);
                    $message = "Quote Rejected.";
                }
            } elseif (in_array($newStatus, ['Sent', 'Accepted', 'Completed'])) {
                $stmt = $pdo->prepare("UPDATE sales_quotes SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $qId]);
                $message = "Status updated to $newStatus.";
            }
        }
        
        // 3. Update Quote Settings
        if ($action === 'update_quote_settings') {
            $qId = (int)$_POST['quote_id'];
            $type = $_POST['quote_type'];
            if (!$access[$type]['manage']) throw new Exception("Unauthorized.");
            
            $stmt = $pdo->prepare("UPDATE sales_quotes SET terms_conditions = ?, vat_rate = ? WHERE id = ?");
            $stmt->execute([$_POST['terms_conditions'], $_POST['vat_rate'], $qId]);
            $message = "Quote settings updated.";
            $pdo->exec("UPDATE sales_quotes SET total_inc_vat = total_exc_vat + (total_exc_vat * (vat_rate/100)) WHERE id = $qId");
        }
        
        // 4. Save BoQ Item
        if ($action === 'save_item') {
            $qId = (int)$_POST['quote_id'];
            $type = $_POST['quote_type'];
            if (!$access[$type]['manage']) throw new Exception("Unauthorized.");
            
            $itemId = !empty($_POST['item_id']) ? (int)$_POST['item_id'] : null;
            $qty = (float)$_POST['estimated_qty'];
            $rate = (float)$_POST['unit_rate'];
            $sort = (int)($_POST['sort_order'] ?? 99);
            
            if ($itemId) {
                $stmt = $pdo->prepare("UPDATE sales_quote_items SET category=?, description=?, unit=?, estimated_qty=?, unit_rate=?, sort_order=? WHERE id=?");
                $stmt->execute([$_POST['category'], $_POST['description'], $_POST['unit'], $qty, $rate, $sort, $itemId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO sales_quote_items (quote_id, category, description, unit, estimated_qty, unit_rate, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$qId, $_POST['category'], $_POST['description'], $_POST['unit'], $qty, $rate, $sort]);
            }
            
            $pdo->exec("UPDATE sales_quotes SET total_exc_vat = (SELECT COALESCE(SUM(estimated_qty * unit_rate), 0) FROM sales_quote_items WHERE quote_id = $qId) WHERE id = $qId");
            $pdo->exec("UPDATE sales_quotes SET total_inc_vat = total_exc_vat + (total_exc_vat * (vat_rate/100)) WHERE id = $qId");
            $message = "Item saved and quote totals recalculated.";
        }
        
        // 5. Delete BoQ Item
        if ($action === 'delete_item') {
            $qId = (int)$_POST['quote_id'];
            $pdo->prepare("DELETE FROM sales_quote_items WHERE id=?")->execute([$_POST['item_id']]);
            $pdo->exec("UPDATE sales_quotes SET total_exc_vat = (SELECT COALESCE(SUM(estimated_qty * unit_rate), 0) FROM sales_quote_items WHERE quote_id = $qId) WHERE id = $qId");
            $pdo->exec("UPDATE sales_quotes SET total_inc_vat = total_exc_vat + (total_exc_vat * (vat_rate/100)) WHERE id = $qId");
            $message = "Item deleted.";
        }
        
        // 6. Save Claim
        if ($action === 'save_claim') {
            $qId = (int)$_POST['quote_id'];
            $type = $_POST['quote_type'];
            if (!$access[$type]['manage']) throw new Exception("Unauthorized.");
            
            $exc = (float)$_POST['amount_exc_vat'];
            $vat = (float)$_POST['vat_rate'];
            $inc = $exc + ($exc * ($vat/100));
            
            $stmt = $pdo->prepare("INSERT INTO sales_claims (quote_id, claim_type, description, amount_exc_vat, amount_inc_vat, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$qId, $_POST['claim_type'], $_POST['description'], $exc, $inc, $_POST['status']]);
            $message = "Claim issued successfully.";
        }
        
        // 7. Update Claim Status
        if ($action === 'update_claim_status') {
            $qId = (int)$_POST['quote_id'];
            $date = $_POST['status'] === 'Paid' ? date('Y-m-d') : null;
            $stmt = $pdo->prepare("UPDATE sales_claims SET status = ?, paid_on = ? WHERE id = ?");
            $stmt->execute([$_POST['status'], $date, $_POST['claim_id']]);
            $message = "Claim status updated.";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'created') $message = "Quote created! Standard items and terms have been loaded.";

// ==========================================
// DETERMINE VIEW
// ==========================================
$viewQuoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : null;

if ($viewQuoteId) {
    // --- DETAILS VIEW ---
    $stmt = $pdo->prepare("SELECT sq.*, c.name as client_name, p.name as project_name FROM sales_quotes sq LEFT JOIN clients c ON sq.client_id = c.id LEFT JOIN projects p ON sq.project_id = p.id WHERE sq.id = ?");
    $stmt->execute([$viewQuoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quote || !$access[$quote['quote_type']]['view']) {
        header('Location: work_sales.php?error=unauthorized_quote'); exit;
    }
    
    $items = $pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id = ? ORDER BY sort_order ASC, category ASC, id ASC");
    $items->execute([$viewQuoteId]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
    
    $claims = $pdo->prepare("SELECT * FROM sales_claims WHERE quote_id = ? ORDER BY created_at DESC");
    $claims->execute([$viewQuoteId]);
    $claims = $claims->fetchAll(PDO::FETCH_ASSOC);
    
    $canManageQuote = $access[$quote['quote_type']]['manage'];
    
    // Determine if quote is locked (only editable in Draft or Rejected)
    $isQuoteLocked = !in_array($quote['status'], ['Draft', 'Rejected']);
    
    $pageTitle = "Quote: " . $quote['reference_number'];
    
} else {
    // --- MASTER LIST VIEW ---
    $currentTab = $_GET['tab'] ?? '';
    if (!isset($access[$currentTab]) || !$access[$currentTab]['view']) {
        foreach ($access as $k => $v) { if ($v['view']) { $currentTab = $k; break; } }
    }
    if (empty($currentTab)) die("No access.");

    $stmt = $pdo->prepare("
        SELECT sq.*, c.name as client_name, p.name as project_name,
        (SELECT COALESCE(SUM(amount_inc_vat), 0) FROM sales_claims WHERE quote_id = sq.id AND status = 'Paid') as total_paid,
        (SELECT COALESCE(SUM(amount_inc_vat), 0) FROM sales_claims WHERE quote_id = sq.id AND status = 'Pending') as total_pending
        FROM sales_quotes sq 
        LEFT JOIN clients c ON sq.client_id = c.id 
        LEFT JOIN projects p ON sq.project_id = p.id 
        WHERE sq.quote_type = ? 
        ORDER BY sq.created_at DESC
    ");
    $stmt->execute([$currentTab]);
    $quotesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $clientsDb = $isAdmin ? $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll() : getUserClients($pdo, $userId);
    $projectsDb = getAccessibleProjects($pdo, $userId);
    
    $pageTitle = "Work Sales - Commercial";
}

function displayUnit($u) {
    $m = ['lump_sum'=>'Lump Sum', 'sqm'=>'sq.m', 'lm'=>'lm', 'cum'=>'cu.m', 'cu.yd'=>'cu.yd', 'hrs'=>'Hours', 'qty'=>'Qty / Pcs'];
    return $m[$u] ?? $u;
}

require_once 'header.php';
?>

<style>
.tab-nav { display: flex; gap: 10px; border-bottom: 2px solid var(--border-glass); margin-bottom: 1.5rem; }
.tab-btn { padding: 10px 20px; color: var(--text-muted); text-decoration: none; font-weight: bold; border-bottom: 3px solid transparent; transition: 0.2s; }
.tab-btn:hover { color: var(--text-primary); }
.tab-btn.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }

.status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; }
.status-Draft { background: rgba(107, 114, 128, 0.2); color: #9ca3af; border: 1px solid #4b5563; }
.status-Pending { background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid #d97706; }
.status-Approved { background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid #059669; }
.status-Sent { background: rgba(59, 130, 246, 0.2); color: #3b82f6; border: 1px solid #2563eb; }
.status-Accepted { background: rgba(34, 197, 94, 0.2); color: #22c55e; border: 1px solid #16a34a; }
.status-Rejected { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid #dc2626; }
.status-Completed { background: rgba(139, 92, 246, 0.2); color: #8b5cf6; border: 1px solid #7c3aed; }

.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border-radius: 12px; width: 90%; max-width: 600px; border: 1px solid var(--border-glass); box-shadow: 0 10px 25px rgba(0,0,0,0.5); position: relative; }
.close-modal { position: absolute; top: 15px; right: 20px; font-size: 1.5rem; color: var(--text-muted); cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }

.summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.summary-card { background: rgba(0,0,0,0.2); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--border-glass); text-align: center; }
.summary-card.highlight { background: rgba(14, 165, 233, 0.1); border-color: #0ea5e9; }
.summary-card h4 { margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase;}
.summary-card .value { font-size: 1.5rem; font-weight: bold; color: var(--text-primary); }

.boq-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.boq-table th { background: rgba(255,255,255,0.05); padding: 10px; text-align: left; color: var(--text-muted); font-weight: 600; }
.boq-table td { padding: 10px; border-bottom: 1px solid var(--border-glass); }
.boq-input { width: 100%; background: #1e1e2d; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 6px; border-radius: 4px; font-size: 0.8rem; }
</style>

<div class="main-container">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$viewQuoteId): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div>
                <h1 class="page-title" style="margin: 0;">Work Sales & Commercial</h1>
                <p style="color: var(--text-secondary); margin-top: 0.25rem;">Manage commercial quotes, standard packages, and interim claims.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <?php if ($isAdmin): ?>
                    <a href="admin_standard_rates.php" class="btn btn-secondary">⚙️ Standard Rates & Terms</a>
                <?php endif; ?>
                <?php if ($access[$currentTab]['manage']): ?>
                    <button class="btn btn-primary" onclick="document.getElementById('createQuoteModal').style.display='block'">+ Create New Quote</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="tab-nav">
            <?php if ($access['Demolition_Excavation']['view']): ?>
                <a href="?tab=Demolition_Excavation" class="tab-btn <?= $currentTab === 'Demolition_Excavation' ? 'active' : '' ?>">Demolition & Excavation</a>
            <?php endif; ?>
            <?php if ($access['Construction']['view']): ?>
                <a href="?tab=Construction" class="tab-btn <?= $currentTab === 'Construction' ? 'active' : '' ?>">Construction</a>
            <?php endif; ?>
            <?php if ($access['Finishes']['view']): ?>
                <a href="?tab=Finishes" class="tab-btn <?= $currentTab === 'Finishes' ? 'active' : '' ?>">Turnkey & Finishes</a>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Reference</th>
                        <th>Project & Client</th>
                        <th style="text-align: right;">Total (Inc VAT)</th>
                        <th style="text-align: right;">Claimed/Pending</th>
                        <th style="text-align: right;">Paid</th>
                        <th style="text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($quotesList)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 2rem;">No quotes found for this category.</td></tr>
                    <?php else: foreach ($quotesList as $q): ?>
                        <tr>
                            <td>
                                <?php $dispStat = str_replace(' Approval', '', $q['status']); ?>
                                <span class="status-badge status-<?= $dispStat ?>"><?= $q['status'] ?></span>
                            </td>
                            <td style="font-weight: bold; color: var(--text-primary);"><?= htmlspecialchars($q['reference_number']) ?></td>
                            <td>
                                <div><?= htmlspecialchars($q['project_name']) ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($q['client_name'] ?? '') ?></div>
                            </td>
                            <td style="text-align: right; font-weight: bold;">€<?= number_format($q['total_inc_vat'], 2) ?></td>
                            <td style="text-align: right; color: #f59e0b;">€<?= number_format($q['total_pending'], 2) ?></td>
                            <td style="text-align: right; color: #10b981;">€<?= number_format($q['total_paid'], 2) ?></td>
                            <td style="text-align: center;">
                                <a href="?quote_id=<?= $q['id'] ?>" class="btn btn-sm btn-secondary">Manage</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($access[$currentTab]['manage']): ?>
        <div id="createQuoteModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="document.getElementById('createQuoteModal').style.display='none'">&times;</span>
                <h2 style="margin-top: 0; color: var(--primary-color);">Create <?= str_replace('_', ' & ', $currentTab) ?> Quote</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="create_quote">
                    <input type="hidden" name="quote_type" value="<?= $currentTab ?>">
                    
                    <div class="form-group">
                        <label>Client *</label>
                        <select name="client_id" required>
                            <option value="">-- Select Client --</option>
                            <?php foreach($clientsDb as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Project (Required) *</label>
                        <select name="project_id" required>
                            <option value="">-- Select Project --</option>
                            <?php foreach($projectsDb as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['client_name']) ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-grid" style="grid-template-columns: 2fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label>Quote Reference / Number *</label>
                            <input type="text" name="reference_number" placeholder="e.g. PRAX-2026-04" required>
                        </div>
                        <div class="form-group">
                            <label>VAT Rate %</label>
                            <select name="vat_rate"><option value="18.00">18%</option><option value="0.00">0%</option></select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Create Quote</button>
                    <p style="text-align: center; color: var(--text-muted); font-size: 0.75rem; margin-top: 10px;">Standard BoQ rates & Terms will be auto-populated upon creation with a quantity of 0.</p>
                </form>
            </div>
        </div>
        <?php endif; ?>

    <?php else: ?>
        
        <?php 
        $tPaid = 0; $tPend = 0; 
        foreach($claims as $c) { if($c['status']==='Paid') $tPaid += $c['amount_inc_vat']; else $tPend += $c['amount_inc_vat']; }
        $balance = $quote['total_inc_vat'] - $tPaid;
        
        $canPrint = in_array($quote['status'], ['Approved', 'Sent', 'Accepted', 'Completed']);
        $dispStat = str_replace(' Approval', '', $quote['status']);
        ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
            <div>
                <a href="work_sales.php?tab=<?= $quote['quote_type'] ?>" style="color: var(--text-muted); text-decoration: none; font-size: 0.9rem;">&larr; Back to <?= str_replace('_', ' & ', $quote['quote_type']) ?> List</a>
                <h1 class="page-title" style="margin-bottom: 0.25rem; margin-top: 0.5rem;"><?= htmlspecialchars($quote['reference_number']) ?></h1>
                <div style="color: var(--text-secondary); font-size: 0.9rem;">
                    <strong>Client:</strong> <?= htmlspecialchars($quote['client_name'] ?? 'Unknown') ?> | 
                    <strong>Project:</strong> <?= htmlspecialchars($quote['project_name'] ?? 'Unknown') ?>
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <span class="status-badge status-<?= $dispStat ?>" style="font-size: 1rem; padding: 6px 15px;"><?= $quote['status'] ?></span>
                
                <?php if ($canPrint): ?>
                    <a href="print_quote.php?quote_id=<?= $quote['id'] ?>" target="_blank" class="btn btn-secondary">📄 View & Print PDF</a>
                <?php else: ?>
                    <button class="btn btn-secondary" style="opacity: 0.5; cursor: not-allowed;" title="Quote must be Approved before printing.">🔒 Print PDF</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="summary-cards">
            <div class="summary-card">
                <h4>Total Value (Exc VAT)</h4>
                <div class="value">€<?= number_format($quote['total_exc_vat'], 2) ?></div>
            </div>
            <div class="summary-card highlight">
                <h4 style="color: var(--primary-color);">Total Value (Inc VAT)</h4>
                <div class="value">€<?= number_format($quote['total_inc_vat'], 2) ?></div>
            </div>
            <div class="summary-card">
                <h4>Total Claimed / Paid</h4>
                <div class="value" style="color: #10b981;">€<?= number_format($tPaid, 2) ?></div>
            </div>
            <div class="summary-card">
                <h4>Balance Owed</h4>
                <div class="value" style="color: <?= $balance > 0 ? '#ef4444' : '#9ca3af' ?>;">€<?= number_format($balance, 2) ?></div>
            </div>
        </div>

        <div class="two-column-layout" style="grid-template-columns: 2fr 1fr;">
            
            <div class="section-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                    <h2 style="margin: 0;">Bill of Quantities (BoQ)</h2>
                    <?php if ($canManageQuote && !$isQuoteLocked): ?>
                        <div style="display: flex; gap: 5px;">
                            <?php if ($quote['quote_type'] === 'Finishes'): ?>
                                <button class="btn btn-sm" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; border: 1px solid #8b5cf6;" onclick="alert('Excel Calculator Interface will open here.')">⚡ Launch Finishes Calculator</button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-primary" onclick="openItemModal()">+ Add Line Item</button>
                        </div>
                    <?php endif; ?>
                </div>

                <table class="boq-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Unit</th>
                            <th style="text-align: right;">Qty</th>
                            <th style="text-align: right;">Rate</th>
                            <th style="text-align: right;">Total (€)</th>
                            <?php if($canManageQuote && !$isQuoteLocked): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($items)): ?>
                            <tr><td colspan="<?= ($canManageQuote && !$isQuoteLocked) ? 7 : 6 ?>" style="text-align: center; color: var(--text-muted);">No items added yet.</td></tr>
                        <?php else: 
                            $currentCat = '';
                            foreach($items as $i): 
                                if ($i['category'] !== $currentCat) {
                                    echo "<tr><td colspan='".(($canManageQuote && !$isQuoteLocked) ? 7 : 6)."' style='background: rgba(255,255,255,0.02); color: var(--primary-color); font-weight: bold;'>".htmlspecialchars($i['category'])."</td></tr>";
                                    $currentCat = $i['category'];
                                }
                        ?>
                            <tr>
                                <td></td>
                                <td><?= nl2br(htmlspecialchars($i['description'])) ?></td>
                                <td><?= displayUnit($i['unit']) ?></td>
                                <td style="text-align: right;"><?= (float)$i['estimated_qty'] ?></td>
                                <td style="text-align: right;">€<?= number_format($i['unit_rate'], 2) ?></td>
                                <td style="text-align: right; font-weight: bold;">€<?= number_format($i['estimated_qty'] * $i['unit_rate'], 2) ?></td>
                                <?php if($canManageQuote && !$isQuoteLocked): ?>
                                    <td style="text-align: right; min-width: 75px;">
                                        <button class="btn btn-sm btn-secondary" style="padding: 2px 6px;" onclick='openItemModal(<?= json_encode($i, JSON_HEX_APOS) ?>)'>✎</button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this line item?');">
                                            <input type="hidden" name="action" value="delete_item"><input type="hidden" name="quote_id" value="<?= $quote['id'] ?>"><input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>"><input type="hidden" name="item_id" value="<?= $i['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" style="padding: 2px 6px;">X</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div>
                <?php if ($canManageQuote): ?>
                <div class="section-card" style="margin-bottom: 1.5rem; border: 1px solid var(--primary-color);">
                    <h2 style="margin-top: 0; margin-bottom: 1rem; color: var(--primary-color);">Quote Workflow</h2>
                    
                    <?php if ($quote['status'] === 'Draft' || $quote['status'] === 'Rejected'): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                            <input type="hidden" name="new_status" value="Pending Approval">
                            <button type="submit" class="btn btn-primary" style="width:100%;">Submit for Approval</button>
                        </form>
                    
                    <?php elseif ($quote['status'] === 'Pending Approval'): ?>
                        <?php if ($canApproveQuotes): ?>
                            <div style="display: flex; gap: 10px;">
                                <form method="POST" style="flex:1;">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                                    <input type="hidden" name="new_status" value="Approved">
                                    <button type="submit" class="btn" style="background:#10b981; color:white; width:100%; border:none;">Approve</button>
                                </form>
                                <form method="POST" style="flex:1;">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                                    <input type="hidden" name="new_status" value="Rejected">
                                    <button type="submit" class="btn btn-danger" style="width:100%;">Reject</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info" style="margin:0; text-align:center;">Waiting for manager authorization...</div>
                        <?php endif; ?>

                    <?php else: ?>
                        <form method="POST" style="display:flex; gap:10px;">
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                            <select name="new_status" style="flex:1;">
                                <option value="Approved" <?= $quote['status']=='Approved'?'selected':'' ?>>Approved (Unsent)</option>
                                <option value="Sent" <?= $quote['status']=='Sent'?'selected':'' ?>>Sent to Client</option>
                                <option value="Accepted" <?= $quote['status']=='Accepted'?'selected':'' ?>>Accepted by Client</option>
                                <option value="Completed" <?= $quote['status']=='Completed'?'selected':'' ?>>Completed / Billed</option>
                            </select>
                            <button type="submit" class="btn btn-secondary">Update</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="section-card" style="margin-bottom: 1.5rem; background: rgba(59, 130, 246, 0.02); border-color: rgba(59, 130, 246, 0.2);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                        <h2 style="margin: 0; color: #3b82f6;">Claims & Invoicing</h2>
                        <?php if ($canManageQuote && in_array($quote['status'], ['Accepted', 'Completed'])): ?>
                            <button class="btn btn-sm" style="background: #3b82f6; color: white; border: none;" onclick="openClaimModal()">+ Issue Claim</button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!in_array($quote['status'], ['Accepted', 'Completed'])): ?>
                        <div style="font-size: 0.8rem; color: var(--text-muted); text-align: center; padding: 1rem;">Quote must be 'Accepted' to issue claims.</div>
                    <?php elseif (empty($claims)): ?>
                        <div style="font-size: 0.8rem; color: var(--text-muted); text-align: center; padding: 1rem;">No claims issued yet.</div>
                    <?php else: ?>
                        <table style="width: 100%; font-size: 0.8rem; border-collapse: collapse;">
                            <?php foreach($claims as $c): ?>
                                <tr>
                                    <td style="padding: 8px 0; border-bottom: 1px solid var(--border-glass);">
                                        <strong><?= $c['claim_type'] ?></strong><br>
                                        <span style="color: var(--text-muted);"><?= htmlspecialchars($c['description']) ?></span>
                                    </td>
                                    <td style="padding: 8px 0; border-bottom: 1px solid var(--border-glass); text-align: right; font-weight: bold;">
                                        €<?= number_format($c['amount_inc_vat'], 2) ?>
                                    </td>
                                    <td style="padding: 8px 0; border-bottom: 1px solid var(--border-glass); text-align: right;">
                                        <?php if ($canManageQuote): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="update_claim_status">
                                                <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>"><input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>">
                                                <input type="hidden" name="claim_id" value="<?= $c['id'] ?>">
                                                <select name="status" onchange="this.form.submit()" style="background: <?= $c['status'] === 'Paid' ? 'rgba(16,185,129,0.1)' : 'rgba(245,158,11,0.1)' ?>; color: <?= $c['status'] === 'Paid' ? '#10b981' : '#f59e0b' ?>; border: 1px solid <?= $c['status'] === 'Paid' ? '#10b981' : '#f59e0b' ?>; border-radius: 4px; padding: 2px; font-size: 0.75rem; font-weight: bold; cursor: pointer;">
                                                    <option value="Pending" <?= $c['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="Paid" <?= $c['status'] === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                                </select>
                                            </form>
                                        <?php else: ?>
                                            <span style="color: <?= $c['status'] === 'Paid' ? '#10b981' : '#f59e0b' ?>; font-weight: bold;"><?= $c['status'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                </div>

                <?php if ($canManageQuote): ?>
                <div class="section-card">
                    <h2 style="margin-top: 0; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Settings & Terms</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_quote_settings">
                        <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                        <input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>">
                        
                        <div class="form-group">
                            <label>VAT Rate %</label>
                            <select name="vat_rate">
                                <option value="18.00" <?= $quote['vat_rate'] == 18 ? 'selected' : '' ?>>18% Standard</option>
                                <option value="0.00" <?= $quote['vat_rate'] == 0 ? 'selected' : '' ?>>0% Exempt</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Terms & Conditions</label>
                            <textarea name="terms_conditions" rows="6" style="font-size: 0.8rem;"><?= htmlspecialchars($quote['terms_conditions'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-secondary" style="width: 100%;">Save Settings</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canManageQuote && !$isQuoteLocked): ?>
        <div id="itemModal" class="modal">
            <div class="modal-content" style="max-width: 500px;">
                <span class="close-modal" onclick="document.getElementById('itemModal').style.display='none'">&times;</span>
                <h2 id="itemModalTitle" style="color: var(--primary-color); margin-top: 0;">Add Line Item</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="save_item">
                    <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                    <input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>">
                    <input type="hidden" name="item_id" id="mod_item_id">
                    
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category" id="mod_item_cat" value="General" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="mod_item_desc" rows="3" required></textarea>
                    </div>
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label>Unit</label>
                            <select name="unit" id="mod_item_unit">
                                <option value="lump_sum">Lump Sum</option>
                                <option value="sqm">sq.m (Area)</option>
                                <option value="lm">lm (Linear)</option>
                                <option value="cum">cu.m (Volume)</option>
                                <option value="cu.yd">cu.yd (Excavation)</option>
                                <option value="hrs">Hours</option>
                                <option value="qty">Qty / Pcs</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Estimated Qty</label>
                            <input type="number" step="0.01" name="estimated_qty" id="mod_item_qty" value="0.00" required>
                        </div>
                        <div class="form-group">
                            <label>Unit Rate (€)</label>
                            <input type="number" step="0.01" name="unit_rate" id="mod_item_rate" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Sort Order (1 = Top, 99 = Bottom)</label>
                        <input type="number" name="sort_order" id="mod_item_sort" value="99" required>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Save Item</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canManageQuote): ?>
        <div id="claimModal" class="modal">
            <div class="modal-content" style="max-width: 400px;">
                <span class="close-modal" onclick="document.getElementById('claimModal').style.display='none'">&times;</span>
                <h2 style="color: #3b82f6; margin-top: 0;">Issue Claim</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="save_claim">
                    <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                    <input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>">
                    <input type="hidden" name="vat_rate" value="<?= $quote['vat_rate'] ?>">
                    
                    <div class="form-group">
                        <label>Claim Type</label>
                        <select name="claim_type" required>
                            <option value="Deposit">Advance Deposit</option>
                            <option value="Interim">Interim Claim (% of Works)</option>
                            <option value="Final Measured">Final Measured Bill</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Description / Stage</label>
                        <input type="text" name="description" placeholder="e.g. M&E 1st and 2nd Fix (50%)" required>
                    </div>
                    <div class="form-group">
                        <label>Amount to Claim (Exc VAT)</label>
                        <input type="number" step="0.01" name="amount_exc_vat" required>
                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">System will auto-add <?= $quote['vat_rate'] ?>% VAT.</div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="Pending">Pending Payment</option>
                            <option value="Paid">Already Paid</option>
                        </select>
                    </div>
                    <button type="submit" class="btn" style="background: #3b82f6; color: white; width: 100%; border: none; padding: 10px;">Issue Claim</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <script>
        function openItemModal(data = null) {
            if(data) {
                document.getElementById('itemModalTitle').innerText = 'Edit Line Item';
                document.getElementById('mod_item_id').value = data.id;
                document.getElementById('mod_item_cat').value = data.category;
                document.getElementById('mod_item_desc').value = data.description;
                document.getElementById('mod_item_unit').value = data.unit;
                document.getElementById('mod_item_qty').value = data.estimated_qty;
                document.getElementById('mod_item_rate').value = data.unit_rate;
                document.getElementById('mod_item_sort').value = data.sort_order;
            } else {
                document.getElementById('itemModalTitle').innerText = 'Add Line Item';
                document.getElementById('mod_item_id').value = '';
                document.getElementById('mod_item_desc').value = '';
                document.getElementById('mod_item_qty').value = '0.00';
                document.getElementById('mod_item_rate').value = '';
                document.getElementById('mod_item_sort').value = '99';
            }
            document.getElementById('itemModal').style.display = 'block';
        }
        function openClaimModal() { document.getElementById('claimModal').style.display = 'block'; }
        </script>

    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
