<?php
// Catch Server-level POST overflows gracefully before anything else loads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && $_SERVER['CONTENT_LENGTH'] > 0) {
    $_POST['action'] = 'overflow_error'; 
}

require_once 'init.php';
require_once 'session-check.php';
require_once 'S3FileManager.php'; 

// Ensure database supports the new Client Hub structure safely
try { $pdo->exec("ALTER TABLE project_documents ADD COLUMN client_id INT DEFAULT NULL"); } catch (PDOException $e) { }
// Safely ensure the column exists before we try to select it
try { $pdo->exec("ALTER TABLE users ADD COLUMN doc_training TINYINT(1) DEFAULT 0"); } catch (PDOException $e) { }

$userId = getCurrentUserId();
$isAdmin = isAdmin();
$s3 = new S3FileManager();

$message = ''; 
$error = '';
if (isset($_POST['action']) && $_POST['action'] === 'overflow_error') {
    $error = "The uploaded file exceeded the server limits. Please upload a file smaller than 500MB.";
}

// ==========================================
// 1. DETERMINE USER CATEGORY PERMISSIONS
// ==========================================
$stmtPerms = $pdo->prepare("SELECT doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales, doc_training FROM users WHERE id = ?");
$stmtPerms->execute([$userId]);
$uPerm = $stmtPerms->fetch(PDO::FETCH_ASSOC);

$docPerms = [];
if ($isAdmin || (int)$uPerm['doc_bca'] > 0) $docPerms['BCA'] = $isAdmin ? 4 : (int)$uPerm['doc_bca'];
if ($isAdmin || (int)$uPerm['doc_ohsa'] > 0) $docPerms['OHSA'] = $isAdmin ? 4 : (int)$uPerm['doc_ohsa'];
if ($isAdmin || (int)$uPerm['doc_drawings'] > 0) $docPerms['Drawings'] = $isAdmin ? 4 : (int)$uPerm['doc_drawings'];
if ($isAdmin || (int)$uPerm['doc_engineering'] > 0) $docPerms['Engineering'] = $isAdmin ? 4 : (int)$uPerm['doc_engineering'];
if ($isAdmin || (int)$uPerm['doc_commercial'] > 0) $docPerms['Commercial'] = $isAdmin ? 4 : (int)$uPerm['doc_commercial'];
if ($isAdmin || (int)$uPerm['doc_sales'] > 0) $docPerms['Sales'] = $isAdmin ? 4 : (int)$uPerm['doc_sales'];
if ($isAdmin || (int)$uPerm['doc_training'] > 0) $docPerms['Training'] = $isAdmin ? 4 : (int)$uPerm['doc_training'];

$accessibleCategories = array_keys($docPerms);

// ==========================================
// 2. DIRECT-TO-CLOUD AJAX HANDLERS
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['ajax_action'] === 'get_upload_url') {
            $cat = $_POST['category'];
            if (!isset($docPerms[$cat]) || $docPerms[$cat] < 3) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']); exit;
            }
            $urlData = $s3->getPresignedUploadUrl($_POST['filename'], $_POST['mime_type'], strtolower($cat));
            if ($urlData) {
                echo json_encode(['success' => true, 'url' => $urlData['url'], 'key' => $urlData['key']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to generate secure upload link.']);
            }
            exit;
        }

        if ($_POST['ajax_action'] === 'save_document_record') {
            $contextId = $_POST['project_id'];
            $projectId = null;
            $clientId = null;
            
            if (strpos($contextId, 'client_') === 0) {
                $clientId = (int)str_replace('client_', '', $contextId);
            } else {
                $projectId = (int)$contextId;
            }

            $category = $_POST['category'];
            if (!isset($docPerms[$category]) || $docPerms[$category] < 3) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']); exit;
            }

            $subCategory = trim($_POST['sub_category'] ?? '');
            $title = trim($_POST['title']);
            $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $fileKey = $_POST['file_key'];
            $ext = strtolower(pathinfo($_POST['filename'], PATHINFO_EXTENSION));

            $stmt = $pdo->prepare("INSERT INTO project_documents (project_id, client_id, category, sub_category, title, file_path, file_type, expiry_date, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$projectId, $clientId, $category, $subCategory, $title, $fileKey, $ext, $expiryDate, $userId]);
            
            echo json_encode(['success' => true]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ==========================================
// 3. STANDARD FORM ACTIONS (Edit / Delete / Rename)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action']) && $_POST['action'] !== 'overflow_error') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_document') {
        try {
            $docId = (int)$_POST['document_id'];
            $title = trim($_POST['title']);
            $subCategory = trim($_POST['sub_category']);
            $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $dismissAlarm = isset($_POST['alarm_dismissed']) ? 1 : 0;
            $dismissReason = trim($_POST['alarm_dismissed_reason'] ?? '');

            $chk = $pdo->prepare("SELECT category FROM project_documents WHERE id = ?");
            $chk->execute([$docId]);
            $dCat = $chk->fetchColumn();
            
            if (!isset($docPerms[$dCat]) || $docPerms[$dCat] < 3) throw new Exception("Permission denied.");

            if ($dismissAlarm) {
                if (empty($dismissReason)) throw new Exception("You must provide a reason to dismiss the alarm.");
                $stmt = $pdo->prepare("UPDATE project_documents SET title=?, sub_category=?, expiry_date=?, alarm_dismissed=1, alarm_dismissed_reason=?, alarm_dismissed_by=?, alarm_dismissed_at=NOW() WHERE id=?");
                $stmt->execute([$title, $subCategory, $expiryDate, $dismissReason, $userId, $docId]);
            } else {
                $stmt = $pdo->prepare("UPDATE project_documents SET title=?, sub_category=?, expiry_date=?, alarm_dismissed=0, alarm_dismissed_reason=NULL, alarm_dismissed_by=NULL, alarm_dismissed_at=NULL WHERE id=?");
                $stmt->execute([$title, $subCategory, $expiryDate, $docId]);
            }
            $message = "Document updated successfully.";
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
    elseif ($action === 'delete_document') {
        try {
            $docId = (int)$_POST['document_id'];
            $stmt = $pdo->prepare("SELECT file_path, category FROM project_documents WHERE id = ?");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();
            
            if ($doc) {
                if (!isset($docPerms[$doc['category']]) || $docPerms[$doc['category']] < 4) throw new Exception("Permission denied.");
                
                $s3->deleteFile($doc['file_path']);
                $pdo->prepare("UPDATE subcontractor_transactions SET document_path = NULL WHERE document_path = ?")->execute([$doc['file_path']]);
                $pdo->prepare("DELETE FROM project_documents WHERE id = ?")->execute([$docId]);
                
                $message = "Document deleted successfully!";
            }
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
    elseif ($action === 'rename_subfolder') {
        try {
            if (!$isAdmin) throw new Exception("Permission denied. Only admins can merge or rename folders.");
            
            $contextId = $_POST['project_id'];
            $cat = $_POST['category'];
            $oldSub = trim($_POST['old_sub_category']);
            $newSub = trim($_POST['new_sub_category']);
            
            if (empty($newSub)) throw new Exception("New subfolder name cannot be empty.");
            
            if (strpos($contextId, 'client_') === 0) {
                $cId = (int)str_replace('client_', '', $contextId);
                $stmt = $pdo->prepare("UPDATE project_documents SET sub_category = ? WHERE client_id = ? AND category = ? AND sub_category = ?");
                $stmt->execute([$newSub, $cId, $cat, $oldSub]);
            } else {
                $pId = (int)$contextId;
                $stmt = $pdo->prepare("UPDATE project_documents SET sub_category = ? WHERE project_id = ? AND category = ? AND sub_category = ?");
                $stmt->execute([$newSub, $pId, $cat, $oldSub]);
            }
            $message = "Subfolder successfully merged/renamed!";
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
}

// Handle Secure Viewing / Download via GET
if (isset($_GET['action']) && in_array($_GET['action'], ['view', 'download']) && isset($_GET['id'])) {
    $docId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT file_path, category FROM project_documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    
    if ($doc) {
        if (!isset($docPerms[$doc['category']])) die("Unauthorized access.");
        if ($_GET['action'] === 'download' && $docPerms[$doc['category']] < 2) die("Level 2 Access required.");
        
        $url = $s3->getPresignedUrl($doc['file_path'], '+60 minutes');
        if ($url) { header("Location: " . $url); exit; } 
        else { $error = "Could not generate link."; }
    }
}

// ==========================================
// 4. DATA FETCHING & TREE BUILDING
// ==========================================
$selectedProjectId = (isset($_GET['project_id']) && $_GET['project_id'] !== 'all') ? $_GET['project_id'] : 'all';
$selectedCategory = (isset($_GET['category']) && $_GET['category'] !== 'all') ? $_GET['category'] : 'all';

$projects = getAccessibleProjects($pdo, $userId);
$accessibleProjectIds = array_column($projects, 'id');

// FIX: If admin, fetch all clients. Otherwise, fetch assigned clients.
if ($isAdmin) {
    $clients = $pdo->query("SELECT id, name, type FROM clients ORDER BY name ASC")->fetchAll();
} else {
    $clients = getUserClients($pdo, $userId);
}
$accessibleClientIds = array_column($clients, 'id');

$canUploadAnything = false;
foreach ($docPerms as $lvl) { if ($lvl >= 3) { $canUploadAnything = true; break; } }

$documents = []; 
$expiringDocs = [];
$tree = []; 
$dynamicSubcats = []; 

// PRE-FILL THE TREE WITH CLIENT HUBS AND TRAINING FOLDERS
foreach ($clients as $c) {
    $pId = 'client_' . $c['id'];
    $pName = '🏢 ' . $c['name'] . ' Hub';
    $tree[$pId] = ['name' => $pName, 'categories' => []];
    
    if (isset($docPerms['Training']) && $docPerms['Training'] > 0) {
        $tree[$pId]['categories']['Training'] = ['subcats' => [], 'loose' => []];
    }
}

if ((!empty($accessibleProjectIds) || !empty($accessibleClientIds)) && !empty($accessibleCategories)) {
    $placeholdersP = !empty($accessibleProjectIds) ? implode(',', array_fill(0, count($accessibleProjectIds), '?')) : '0';
    $placeholdersC = !empty($accessibleClientIds) ? implode(',', array_fill(0, count($accessibleClientIds), '?')) : '0';
    
    $baseParams = array_merge($accessibleProjectIds, $accessibleClientIds);
    $allowedCatString = implode("','", array_map(function($c) { return addslashes($c); }, $accessibleCategories));

    // Fetch Expiring Documents
    $expStmt = $pdo->prepare("
        SELECT d.*, 
               CASE WHEN d.client_id IS NOT NULL THEN CONCAT('🏢 ', c.name, ' Hub') ELSE p.name END as project_name 
        FROM project_documents d 
        LEFT JOIN projects p ON d.project_id = p.id 
        LEFT JOIN clients c ON d.client_id = c.id
        WHERE (d.project_id IN ($placeholdersP) OR d.client_id IN ($placeholdersC)) 
        AND d.category IN ('$allowedCatString')
        AND d.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND d.alarm_dismissed = 0
        ORDER BY d.expiry_date ASC
    ");
    $expStmt->execute($baseParams);
    $expiringDocs = $expStmt->fetchAll();

    // Fetch Main Vault Documents
    $query = "SELECT d.*, p.name as project_name, c.name as client_name, u.first_name, u.last_name, u2.first_name as dis_fn, u2.last_name as dis_ln
              FROM project_documents d 
              LEFT JOIN projects p ON d.project_id = p.id 
              LEFT JOIN clients c ON d.client_id = c.id
              LEFT JOIN users u ON d.uploaded_by = u.id 
              LEFT JOIN users u2 ON d.alarm_dismissed_by = u2.id
              WHERE (d.project_id IN ($placeholdersP) OR d.client_id IN ($placeholdersC)) 
              AND d.category IN ('$allowedCatString')";
    
    $mainParams = $baseParams; 
    
    if ($selectedProjectId !== 'all') { 
        if (strpos($selectedProjectId, 'client_') === 0) {
            $query .= " AND d.client_id = ?"; 
            $mainParams[] = (int)str_replace('client_', '', $selectedProjectId); 
        } else {
            $query .= " AND d.project_id = ?"; 
            $mainParams[] = (int)$selectedProjectId; 
        }
    }
    
    if ($selectedCategory !== 'all' && in_array($selectedCategory, $accessibleCategories)) { 
        $query .= " AND d.category = ?"; 
        $mainParams[] = $selectedCategory; 
    }
    $query .= " ORDER BY p.name ASC, c.name ASC, d.category ASC, d.sub_category ASC, d.created_at DESC";
    $docStmt = $pdo->prepare($query);
    $docStmt->execute($mainParams);
    $documents = $docStmt->fetchAll();

    // Build the Tree Array & Dynamic Subcategories
    foreach ($documents as $d) {
        $isClientHub = !empty($d['client_id']);
        $pId = $isClientHub ? 'client_' . $d['client_id'] : $d['project_id'];
        $pName = $isClientHub ? '🏢 ' . $d['client_name'] . ' Hub' : $d['project_name'];
        $cat = $d['category'];
        $subcat = empty($d['sub_category']) ? '' : trim($d['sub_category']);

        if ($subcat !== '') {
            if (!isset($dynamicSubcats[$cat])) $dynamicSubcats[$cat] = [];
            if (!in_array($subcat, $dynamicSubcats[$cat])) $dynamicSubcats[$cat][] = $subcat;
        }

        if (!isset($tree[$pId])) $tree[$pId] = ['name' => $pName, 'categories' => []];
        if (!isset($tree[$pId]['categories'][$cat])) $tree[$pId]['categories'][$cat] = ['subcats' => [], 'loose' => []];

        if ($subcat !== '') {
            if (!isset($tree[$pId]['categories'][$cat]['subcats'][$subcat])) $tree[$pId]['categories'][$cat]['subcats'][$subcat] = [];
            $tree[$pId]['categories'][$cat]['subcats'][$subcat][] = $d;
        } else {
            $tree[$pId]['categories'][$cat]['loose'][] = $d;
        }
    }
}

$pageTitle = 'Document Vault';
require_once __DIR__ . '/includes/entity_select_helpers.php';

$docFilterClientsJson = json_encode(array_map(static function ($c) {
    return [
        'id' => (int)$c['id'],
        'name' => $c['name'],
        'subtitle' => entityClientSubtitle($c),
    ];
}, $clients), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

$docFilterProjectsJson = json_encode(array_map(static function ($p) {
    return [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'subtitle' => entityProjectSubtitle($p),
    ];
}, $projects), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

require_once 'header.php';
?>
<script src="/assets/js/doc-context-filter.js?v=<?= time() ?>" defer></script>

<style>
.cat-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; overflow-x: auto; }
.cat-tab { padding: 0.5rem 1rem; border-radius: 6px; color: var(--text-secondary); text-decoration: none; font-weight: 600; font-size: 0.85rem; white-space: nowrap; transition: 0.2s; }
.cat-tab:hover { background: rgba(255,255,255,0.05); color: var(--text-primary); }
.cat-tab.active { background: rgba(99, 102, 241, 0.1); color: var(--primary-color); border: 1px solid rgba(99, 102, 241, 0.3); }
    
/* FOLDER TREE STYLES */
.vault-container { background: var(--bg-panel); border: 1px solid var(--border-glass); border-radius: 8px; padding: 1.5rem; margin-bottom: 3rem; }
.tree-details { margin-bottom: 0.5rem; }
.tree-details > summary { list-style: none; cursor: pointer; outline: none; padding: 0.75rem 1rem; border-radius: 6px; background: rgba(255,255,255,0.02); font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 10px; transition: background 0.2s; border: 1px solid transparent;}
.tree-details > summary::-webkit-details-marker { display: none; }
.tree-details > summary:hover { background: rgba(255,255,255,0.05); }
.tree-details[open] > summary { border-bottom: 1px solid var(--border-glass); border-bottom-left-radius: 0; border-bottom-right-radius: 0; margin-bottom: 0.5rem;}
.tree-details > summary::before { content: '📁'; font-size: 1.2rem; transition: transform 0.2s; }
.tree-details[open] > summary::before { content: '📂'; }

/* Nested Indentation */
.tree-level-1 { margin-left: 1.5rem; margin-top: 0.5rem; border-left: 2px dashed rgba(255,255,255,0.1); padding-left: 1rem; }
.tree-level-2 { margin-left: 1rem; margin-top: 0.5rem; }
.tree-level-3 { margin-left: 1rem; }

/* File Item Card */
.file-card { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: 6px; margin-bottom: 0.5rem; transition: transform 0.15s, box-shadow 0.15s; }
.file-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); border-color: rgba(99, 102, 241, 0.4); }
.file-info { display: flex; align-items: center; gap: 12px; }
.file-icon { font-size: 1.8rem; line-height: 1; }
.file-meta { font-size: 0.75rem; color: var(--text-muted); display: flex; gap: 15px; margin-top: 4px; }
.file-actions { display: flex; gap: 8px; align-items: center; }

/* Badges */
.badge-expired { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239,68,68,0.5); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
.badge-warning { background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245,158,11,0.5); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
.badge-dismissed { background: rgba(100, 116, 139, 0.2); color: #94a3b8; border: 1px solid rgba(100,116,139,0.5); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; cursor: help; }

/* Modals */
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.7); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; line-height: 1; }
.close-modal:hover { color: var(--text-primary); }

/* Viewer */
.viewer-modal-content { width: 95%; max-width: 1400px; height: 90vh; margin: 2% auto; padding: 1rem; display: flex; flex-direction: column; }
.viewer-iframe { flex: 1; width: 100%; border: 1px solid var(--border-glass); border-radius: 8px; background: #fff; }

/* Uploader Drop Zone */
.drop-zone { border: 2px dashed var(--primary-color); border-radius: 8px; padding: 30px; text-align: center; background: rgba(0,0,0,0.2); color: var(--text-muted); cursor: pointer; transition: all 0.3s ease; position: relative; margin-top: 5px; }
.drop-zone:hover { background: rgba(99, 102, 241, 0.05); }
.drop-zone.dragover { background: rgba(16, 185, 129, 0.1); border-color: #10B981; transform: scale(1.02); }
.drop-zone.error { background: rgba(239, 68, 68, 0.1); border-color: #ef4444; }
.drop-zone input[type="file"] { position: absolute; width: 100%; height: 100%; top: 0; left: 0; opacity: 0; cursor: pointer; }
.drop-zone-text { font-size: 1.1rem; font-weight: 600; pointer-events: none; color: var(--text-primary); transition: color 0.3s ease; }
.drop-zone.dragover .drop-zone-text { color: #10B981; }
.drop-zone.error .drop-zone-text { color: #ef4444; }
.drop-zone-subtext { font-size: 0.8rem; margin-top: 8px; pointer-events: none; }
.progress-container { width: 100%; background: rgba(255,255,255,0.1); border-radius: 6px; height: 10px; margin-top: 15px; overflow: hidden; display: none; }
.progress-fill { background: #10B981; height: 100%; width: 0%; transition: width 0.2s; }

/* Controls Header */
.vault-header-controls { display: flex; gap: 1rem; margin-bottom: 1.5rem; background: var(--bg-panel); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-glass); align-items: center; flex-wrap: wrap; justify-content: space-between; }
.search-input { padding: 0.6rem 1rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-card); color: var(--text-primary); font-size: 0.95rem; min-width: 300px; width: 100%; max-width: 400px; }
</style>

<div class="main-container">

    <?php if (!empty($expiringDocs)): ?>
    <div style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.3); border-left: 4px solid #ef4444; border-radius: 8px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.2);">
        <h3 style="margin-top: 0; color: #ef4444; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">⚠️ Action Required: Documents Expiring Soon</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
            <?php foreach($expiringDocs as $edoc): 
                $now = new DateTime(); $exp = new DateTime($edoc['expiry_date']);
                $now->setTime(0,0,0); $exp->setTime(0,0,0);
                $days = (int)$now->diff($exp)->format('%r%a');
                $catLvl = $docPerms[$edoc['category']] ?? 0;
            ?>
                <div style="background: #1e1e2d; padding: 1rem; border-radius: 6px; border: 1px solid var(--border-glass);">
                    <div style="font-weight: 800; color: var(--primary-color); margin-bottom: 4px;"><?= htmlspecialchars($edoc['project_name']) ?></div>
                    <div style="font-size: 0.85rem; color: #fff; margin-bottom: 8px;"><?= htmlspecialchars($edoc['title']) ?></div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <?php if($days < 0): ?><span class="badge-expired">Expired <?= abs($days) ?>d ago</span>
                        <?php elseif($days === 0): ?><span class="badge-expired">Expires TODAY</span>
                        <?php else: ?><span class="badge-warning">Expires in <?= $days ?>d</span><?php endif; ?>
                        
                        <div style="display: flex; gap: 5px;">
                            <button onclick="openViewer(<?= $edoc['id'] ?>, '<?= htmlspecialchars($edoc['title'], ENT_QUOTES) ?>')" class="btn btn-sm btn-secondary" style="padding: 2px 8px;">View</button>
                            <?php if ($catLvl >= 3): ?>
                                <button onclick='openEditModal(<?= json_encode($edoc, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="btn btn-sm" style="background: rgba(245,158,11,0.2); color: #f59e0b; border: 1px solid #f59e0b; padding: 2px 8px;">Edit</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">Universal Document Vault</h1>
            <p style="color: var(--text-secondary); margin-top: 0.25rem;">Organized, secure, and fully encrypted cloud storage.</p>
        </div>
        <?php if ($canUploadAnything): ?>
            <button onclick="openUploadModal()" class="btn btn-primary">+ Upload Document(s)</button>
        <?php endif; ?>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="vault-header-controls">
        <form method="GET" id="docContextFilter" class="doc-context-filter"
              data-selected="<?= htmlspecialchars((string)$selectedProjectId) ?>"
              data-clients="<?= htmlspecialchars($docFilterClientsJson, ENT_QUOTES) ?>"
              data-projects="<?= htmlspecialchars($docFilterProjectsJson, ENT_QUOTES) ?>">
            <strong style="color: var(--primary-color); align-self: center;">Filter Context:</strong>
            <input type="hidden" name="category" value="<?= htmlspecialchars($selectedCategory) ?>">
            <input type="hidden" name="project_id" value="<?= htmlspecialchars((string)$selectedProjectId) ?>">

            <div class="filter-step">
                <label for="docContextType">Show</label>
                <select name="context_type" id="docContextType" class="entity-select">
                    <option value="all">All folders</option>
                    <option value="client">Client hub</option>
                    <option value="project">Project</option>
                </select>
            </div>

            <div class="filter-step">
                <label for="docContextEntity">Selection</label>
                <select name="context_entity" id="docContextEntity" class="entity-select entity-select-search" data-recent-kind="doc_context">
                    <option value="">All folders</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary" style="align-self: flex-end;">Apply</button>
        </form>
        
        <div style="position: relative;">
            <input type="text" id="docSearch" class="search-input" placeholder="🔍 Search files by name, type, or user..." onkeyup="filterTree()">
        </div>
    </div>

    <div class="cat-tabs">
        <a href="?project_id=<?= $selectedProjectId ?>&category=all" class="cat-tab <?= $selectedCategory === 'all' ? 'active' : '' ?>">All Folders</a>
        <?php foreach($accessibleCategories as $cat): ?>
            <a href="?project_id=<?= $selectedProjectId ?>&category=<?= urlencode($cat) ?>" class="cat-tab <?= $selectedCategory === $cat ? 'active' : '' ?>"><?= htmlspecialchars($cat) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="vault-container">
        <?php if (empty($tree)): ?>
            <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                <h2>📁 Vault is Empty</h2>
                <p>No documents found matching your current filters or access levels.</p>
            </div>
        <?php else: ?>
            <div id="vaultTree">
                <?php foreach ($tree as $pId => $pData): ?>
                    <details class="tree-details project-folder"> <summary><?= htmlspecialchars($pData['name']) ?></summary>
                        <div class="tree-level-1">
                            
                            <?php foreach ($pData['categories'] as $catName => $content): ?>
                                <details class="tree-details category-folder"> 
                                    <summary style="font-size: 0.95rem; color: #a5b4fc; display: flex; justify-content: space-between; align-items: center; width: 100%; padding-right: 15px;">
                                        <span><?= htmlspecialchars($catName) ?></span>
                                        <?php if ($canUploadAnything && isset($docPerms[$catName]) && $docPerms[$catName] >= 3): ?>
                                            <button onclick="openUploadModalPreselected(event, '<?= $pId ?>', '<?= htmlspecialchars($catName, ENT_QUOTES) ?>')" class="btn btn-sm" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #10B981; padding: 2px 8px; font-size: 0.7rem; border-radius: 4px;" title="Upload directly to this folder">📤 Upload Here</button>
                                        <?php endif; ?>
                                    </summary>
                                    <div class="tree-level-2">
                                        
                                        <?php foreach ($content['subcats'] as $subCatName => $files): ?>
                                            <details class="tree-details subcat-folder">
                                                <summary style="font-size: 0.9rem; color: #818cf8; display: flex; justify-content: space-between; align-items: center; width: 100%; padding-right: 15px;">
                                                    <span><?= htmlspecialchars($subCatName) ?></span>
                                                    <?php if ($isAdmin): ?>
                                                        <button onclick="openMergeModal(event, '<?= $pId ?>', '<?= htmlspecialchars($catName, ENT_QUOTES) ?>', '<?= htmlspecialchars($subCatName, ENT_QUOTES) ?>')" class="btn btn-sm" style="background: rgba(255,255,255,0.1); border: none; color: #ccc; padding: 2px 8px; font-size: 0.7rem; border-radius: 4px;" title="Rename this folder or merge it into another one">✏️ Rename/Merge</button>
                                                    <?php endif; ?>
                                                </summary>
                                                <div class="tree-level-3">
                                                    <?php foreach ($files as $f): renderFileCard($f, $docPerms); endforeach; ?>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>

                                        <div class="loose-files" style="margin-top: 0.5rem;">
                                            <?php foreach ($content['loose'] as $f): renderFileCard($f, $docPerms); endforeach; ?>
                                        </div>

                                    </div>
                                </details>
                            <?php endforeach; ?>

                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php 
// Helper function to render a beautiful file row
function renderFileCard($d, $docPerms) {
    $ext = strtolower((string)($d['file_type'] ?? ''));
    $icon = '📄';
    if ($ext === 'pdf') $icon = '📕';
    if (in_array($ext, ['dwg', 'dxf', 'cad'])) $icon = '📐';
    if (in_array($ext, ['jpg', 'png', 'jpeg'])) $icon = '🖼️';
    if (in_array($ext, ['mp4', 'mov', 'avi'])) $icon = '🎬';

    $expText = '';
    if ($d['expiry_date']) {
        $dateStr = date('d M Y', strtotime($d['expiry_date']));
        if ($d['alarm_dismissed']) {
            $tooltip = "Dismissed by " . htmlspecialchars($d['dis_fn'] ?? '') . ": " . htmlspecialchars($d['alarm_dismissed_reason'] ?? '');
            $expText = "<span class='badge-dismissed' title='$tooltip'>Alarm Off ($dateStr)</span>";
        } elseif (strtotime($d['expiry_date']) < time()) {
            $expText = "<span class='badge-expired'>Expired: $dateStr</span>";
        } else {
            $expText = "<span class='badge-warning'>Exp: $dateStr</span>";
        }
    }
    
    $catLvl = $docPerms[$d['category']] ?? 0;
    
    // Create searchable string
    $searchString = htmlspecialchars(strtolower($d['title'] . " " . $d['category'] . " " . $d['sub_category'] . " " . $d['first_name'] . " " . $d['last_name'] . " " . $ext));

    echo "<div class='file-card' data-search='{$searchString}'>
        <div class='file-info'>
            <div class='file-icon'>$icon</div>
            <div>
                <div style='font-weight: 600; color: var(--text-primary); font-size: 0.95rem;'>" . htmlspecialchars($d['title']) . "</div>
                <div class='file-meta'>
                    <span>Uploaded by " . htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) . "</span>
                    <span>" . date('d M Y', strtotime($d['created_at'])) . "</span>
                    $expText
                </div>
            </div>
        </div>
        <div class='file-actions'>
            <button onclick=\"openViewer({$d['id']}, '" . htmlspecialchars($d['title'], ENT_QUOTES) . "')\" class='btn btn-sm btn-secondary' title='View in Screen'>👁️ View</button>";
            if ($catLvl >= 2) echo "<a href='?action=download&id={$d['id']}' target='_blank' class='btn btn-sm btn-primary' title='Download Original'>↓ Download</a>";
            if ($catLvl >= 3) echo "<button onclick='openEditModal(" . json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT) . ")' class='btn btn-sm' style='background: rgba(245,158,11,0.2); color: #f59e0b; border: 1px solid #f59e0b;'>✎ Edit</button>";
            if ($catLvl >= 4) echo "
                <form method='POST' style='display:inline;' onsubmit=\"return confirm('Delete this document permanently?');\">
                    <input type='hidden' name='action' value='delete_document'>
                    <input type='hidden' name='document_id' value='{$d['id']}'>
                    <button type='submit' class='btn btn-sm btn-danger'>X Delete</button>
                </form>";
    echo "</div></div>";
}
?>

<div id="viewerModal" class="modal">
    <div class="modal-content viewer-modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3 id="viewerTitle" style="margin: 0; color: var(--primary-color);">Document Viewer</h3>
            <div>
                <span style="font-size: 0.8rem; color: var(--text-muted); margin-right: 15px;">* If preview fails, your browser may download it instead.</span>
                <span class="close-modal" onclick="closeViewer()" style="float: none; font-size: 2rem;">&times;</span>
            </div>
        </div>
        <iframe id="viewerFrame" class="viewer-iframe" src=""></iframe>
    </div>
</div>

<?php if ($canUploadAnything): ?>
<div id="uploadModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('uploadModal')">&times;</span>
        <h2 style="color: var(--primary-color); margin-top: 0;">Secure Upload to Vault</h2>
        
        <form id="directUploadForm">
            <div class="form-group">
                <label>Folder Location *</label>
                <select name="project_id" required>
                    <option value="">-- Select Destination --</option>
                    
                    <optgroup label="🏢 Company Hubs (Client Level)">
                        <?php foreach($clients as $c): ?>
                            <option value="client_<?= $c['id'] ?>">🏢 <?= htmlspecialchars($c['name']) ?> Hub</option>
                        <?php endforeach; ?>
                    </optgroup>
                    
                    <optgroup label="🏗️ Projects">
                        <?php foreach($projects as $p): ?>
                            <option value="<?= $p['id'] ?>">🏗️ <?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                <div class="form-group">
                    <label>Main Category *</label>
                    <select name="category" required onchange="updateSubCategories(this.value, 'subcat_suggestions')">
                        <option value="">-- Select --</option>
                        <?php foreach($docPerms as $cat => $lvl): ?>
                            <?php if ($lvl >= 3): ?><option value="<?= $cat ?>"><?= $cat ?></option><?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sub Category / Type</label>
                    <input type="text" name="sub_category" placeholder="e.g. Condition Report or Person's Name" list="subcat_suggestions">
                    <datalist id="subcat_suggestions"></datalist>
                </div>
            </div>

            <div class="form-group">
                <label>Base Document Title *</label>
                <input type="text" name="title" required placeholder="e.g. Health & Safety Induction">
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 5px;">If multiple files are selected, the original filename will be appended to this title to distinguish them.</p>
            </div>

            <div class="form-group">
                <label>File(s) to Upload *</label>
                <div class="drop-zone" id="drop_zone">
                    <input type="file" id="document_file" multiple required>
                    <div class="drop-zone-text" id="drop_zone_text">📁 Click to browse or Drag & Drop multiple files here</div>
                    <div class="drop-zone-subtext" id="drop_zone_subtext">Maximum Size per file: 500MB (Direct to Cloudflare R2)</div>
                </div>
                <div class="progress-container" id="uploadProgress">
                    <div class="progress-fill" id="uploadProgressFill"></div>
                </div>
            </div>

            <div class="form-group" style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); padding: 10px; border-radius: 6px;">
                <label style="color: #f59e0b; margin-bottom: 5px;">Expiry Date (Optional - Triggers Alarms)</label>
                <input type="date" name="expiry_date" style="background: var(--bg-panel);">
            </div>
            
            <button type="submit" class="btn btn-primary" id="uploadSubmitBtn" style="width: 100%; margin-top: 10px;">Upload & Encrypt</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
        <h2 style="color: #f59e0b; margin-top: 0;">Edit Document Details</h2>
        <form method="POST">
            <input type="hidden" name="action" value="edit_document">
            <input type="hidden" name="document_id" id="edit_doc_id">
            
            <div class="form-group">
                <label>Document Title *</label>
                <input type="text" name="title" id="edit_title" required>
            </div>
            <div class="form-group">
                <label>Sub Category</label>
                <input type="text" name="sub_category" id="edit_subcat" list="edit_subcat_suggestions">
                <datalist id="edit_subcat_suggestions"></datalist>
            </div>

            <div class="form-group" style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); padding: 10px; border-radius: 6px;">
                <label style="color: #f59e0b; margin-bottom: 5px;">Expiry Date</label>
                <input type="date" name="expiry_date" id="edit_expiry" style="background: var(--bg-panel);">
                
                <div style="margin-top: 15px; border-top: 1px dashed rgba(245, 158, 11, 0.5); padding-top: 10px;">
                    <label class="checkbox-item" style="color: #ef4444; font-weight: bold;">
                        <input type="checkbox" name="alarm_dismissed" id="edit_alarm_check" onchange="toggleReasonField()"> 
                        Dismiss Expiry Alarm (No longer required)
                    </label>
                    <div id="edit_reason_div" style="display: none; margin-top: 10px;">
                        <label>Reason for dismissal (Required) *</label>
                        <textarea name="alarm_dismissed_reason" id="edit_alarm_reason" rows="2" placeholder="e.g. Project completed, insurance no longer needed."></textarea>
                    </div>
                    <div id="edit_dismissed_info" style="display: none; margin-top: 10px; font-size: 0.8rem; color: #94a3b8; font-style: italic;"></div>
                </div>
            </div>
            
            <button type="submit" class="btn btn-secondary" style="width: 100%; margin-top: 10px;">Save Changes</button>
        </form>
    </div>
</div>

<?php if ($isAdmin): ?>
<div id="mergeModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('mergeModal')">&times;</span>
        <h2 style="color: var(--primary-color); margin-top: 0;">Rename / Merge Subfolder</h2>
        <form method="POST">
            <input type="hidden" name="action" value="rename_subfolder">
            <input type="hidden" name="project_id" id="merge_project_id">
            <input type="hidden" name="category" id="merge_category">
            <input type="hidden" name="old_sub_category" id="merge_old_sub">
            
            <div class="form-group">
                <label>Current Subfolder Name</label>
                <input type="text" id="merge_old_display" disabled style="background: rgba(0,0,0,0.2); border: 1px dashed #666; color: var(--text-muted);">
            </div>
            
            <div class="form-group">
                <label>New Subfolder Name *</label>
                <p style="font-size: 0.75rem; color: #94a3b8; margin: 0 0 5px 0;">Select an existing folder from the list to merge them, or type a brand new name to rename.</p>
                <input type="text" name="new_sub_category" id="merge_new_sub" required list="merge_subcat_suggestions">
                <datalist id="merge_subcat_suggestions"></datalist>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Apply Changes</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// ==========================================
// TREE SEARCH ENGINE
// ==========================================
function filterTree() {
    const query = document.getElementById('docSearch').value.toLowerCase();
    const projects = document.querySelectorAll('.project-folder');

    projects.forEach(proj => {
        let projHasVisibleFiles = false;
        const categories = proj.querySelectorAll('.category-folder');

        categories.forEach(cat => {
            let catHasVisibleFiles = false;
            
            const subcats = cat.querySelectorAll('.subcat-folder');
            subcats.forEach(subcat => {
                let subcatHasVisibleFiles = false;
                const files = subcat.querySelectorAll('.file-card');
                
                files.forEach(file => {
                    const searchData = file.getAttribute('data-search');
                    if (searchData.includes(query)) {
                        file.style.display = 'flex';
                        subcatHasVisibleFiles = true;
                        catHasVisibleFiles = true;
                        projHasVisibleFiles = true;
                    } else {
                        file.style.display = 'none';
                    }
                });

                if (query !== '') {
                    subcat.style.display = subcatHasVisibleFiles ? 'block' : 'none';
                    if (subcatHasVisibleFiles) subcat.open = true;
                } else {
                    subcat.style.display = 'block';
                    subcat.open = false; 
                }
            });

            const looseFiles = cat.querySelectorAll('.loose-files .file-card');
            looseFiles.forEach(file => {
                const searchData = file.getAttribute('data-search');
                if (searchData.includes(query)) {
                    file.style.display = 'flex';
                    catHasVisibleFiles = true;
                    projHasVisibleFiles = true;
                } else {
                    file.style.display = 'none';
                }
            });

            if (query !== '') {
                cat.style.display = catHasVisibleFiles ? 'block' : 'none';
                if (catHasVisibleFiles) cat.open = true;
            } else {
                cat.style.display = 'block';
                cat.open = false; // Stay closed by default
            }
        });

        if (query !== '') {
            proj.style.display = projHasVisibleFiles ? 'block' : 'none';
            if (projHasVisibleFiles) proj.open = true;
        } else {
            proj.style.display = 'block';
            proj.open = false; // Stay closed by default
        }
    });
}

// ==========================================
// MODALS & UI
// ==========================================
function openUploadModal() { 
    document.getElementById('uploadModal').style.display = 'block'; 
    document.getElementById('directUploadForm').reset();
    resetDropZoneText();
}

function openUploadModalPreselected(e, projectId, category) {
    e.preventDefault();
    e.stopPropagation();
    openUploadModal();
    
    const projSelect = document.querySelector('#directUploadForm select[name="project_id"]');
    if (projSelect) projSelect.value = projectId;
    
    const catSelect = document.querySelector('#directUploadForm select[name="category"]');
    if (catSelect) {
        catSelect.value = category;
        updateSubCategories(category, 'subcat_suggestions');
    }
}

function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = function(event) {
    if (event.target == document.getElementById('uploadModal')) closeModal('uploadModal');
    if (event.target == document.getElementById('editModal')) closeModal('editModal');
    if (event.target == document.getElementById('viewerModal')) closeViewer();
    if (event.target == document.getElementById('mergeModal')) closeModal('mergeModal');
}

function openViewer(id, title) {
    document.getElementById('viewerTitle').textContent = 'Viewing: ' + title;
    document.getElementById('viewerFrame').src = '?action=view&id=' + id;
    document.getElementById('viewerModal').style.display = 'block';
}
function closeViewer() {
    document.getElementById('viewerModal').style.display = 'none';
    document.getElementById('viewerFrame').src = ''; 
}

function openEditModal(data) {
    document.getElementById('edit_doc_id').value = data.id;
    document.getElementById('edit_title').value = data.title;
    document.getElementById('edit_subcat').value = data.sub_category || '';
    document.getElementById('edit_expiry').value = data.expiry_date || '';
    updateSubCategories(data.category, 'edit_subcat_suggestions');

    const chk = document.getElementById('edit_alarm_check');
    const rsn = document.getElementById('edit_alarm_reason');
    const info = document.getElementById('edit_dismissed_info');
    
    if (data.alarm_dismissed == 1) {
        chk.checked = true;
        rsn.value = data.alarm_dismissed_reason || '';
        info.innerHTML = `Previously dismissed by ${data.dis_fn} ${data.dis_ln} on ${data.alarm_dismissed_at}`;
        info.style.display = 'block';
    } else {
        chk.checked = false; rsn.value = ''; info.style.display = 'none';
    }
    toggleReasonField();
    document.getElementById('editModal').style.display = 'block';
}

function toggleReasonField() {
    const isChecked = document.getElementById('edit_alarm_check').checked;
    const rsnDiv = document.getElementById('edit_reason_div');
    const rsnInput = document.getElementById('edit_alarm_reason');
    if (isChecked) { rsnDiv.style.display = 'block'; rsnInput.required = true; } 
    else { rsnDiv.style.display = 'none'; rsnInput.required = false; }
}

function openMergeModal(e, projectId, category, oldSub) {
    e.preventDefault();
    e.stopPropagation();
    
    document.getElementById('merge_project_id').value = projectId;
    document.getElementById('merge_category').value = category;
    document.getElementById('merge_old_sub').value = oldSub;
    document.getElementById('merge_old_display').value = oldSub;
    document.getElementById('merge_new_sub').value = '';
    
    updateSubCategories(category, 'merge_subcat_suggestions');
    
    document.getElementById('mergeModal').style.display = 'block';
}

// ==========================================
// DYNAMIC SUGGESTIONS
// ==========================================
// Base suggestions + Dynamically populated existing categories from the DB
const dynamicSubcats = <?= json_encode($dynamicSubcats) ?>;
const defaultSuggestions = {
    'BCA': ['Condition Report', 'Method Statement', 'CAR Insurance', 'Bank Guarantee', 'Responsibility Form', 'Clearance Letter'],
    'Engineering': ['ARMS Application', 'Water/Sewerage Application', 'PA Compliance', 'EPC Certificate', 'Lift Certification', 'Fire Safety Comm.'],
    'OHSA': ['TC Certificate', 'Plant Certificate', 'RAMS', 'Safety Report', 'Incident Report'],
    'Training': ['General Safety', 'Induction', 'First Aid', 'Equipment Certification'],
    'Drawings': ['Architectural Plan', 'Structural Plan', 'Services Plan', 'Elevations', 'Sections'],
    'Commercial': ['Quote', 'Contract', 'PO', 'Guarantee', 'Receipt'],
    'Sales': ['Price List', 'Marketing Plan', 'Render (Image)', 'Render (Video)', 'Brochure', 'Floor Plan']
};

function updateSubCategories(category, listId) {
    const dataList = document.getElementById(listId);
    if(!dataList) return;
    dataList.innerHTML = '';
    
    let combined = new Set();
    if (defaultSuggestions[category]) defaultSuggestions[category].forEach(item => combined.add(item));
    if (dynamicSubcats[category]) dynamicSubcats[category].forEach(item => combined.add(item));
    
    combined.forEach(item => {
        const opt = document.createElement('option'); 
        opt.value = item; 
        dataList.appendChild(opt); 
    });
}

// ==========================================
// DIRECT-TO-CLOUD BATCH UPLOAD ENGINE
// ==========================================
const dropZone = document.getElementById('drop_zone');
const dropZoneText = document.getElementById('drop_zone_text');
const dropZoneSubtext = document.getElementById('drop_zone_subtext');
const fileInput = document.getElementById('document_file');
const uploadForm = document.getElementById('directUploadForm');
const submitBtn = document.getElementById('uploadSubmitBtn');
const progressBar = document.getElementById('uploadProgress');
const progressFill = document.getElementById('uploadProgressFill');

const MAX_SIZE_MB = 500;
const MAX_SIZE_BYTES = MAX_SIZE_MB * 1024 * 1024;

if (dropZone) {
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(e => dropZone.addEventListener(e, preventDefaults, false));
    ['dragenter', 'dragover'].forEach(e => dropZone.addEventListener(e, () => { 
        if (!dropZone.classList.contains('error')) {
            dropZone.classList.add('dragover'); 
            dropZoneText.innerHTML = '🔥 Drop it like it\'s hot! 🔥'; 
        }
    }, false));
    ['dragleave', 'drop'].forEach(e => dropZone.addEventListener(e, () => { 
        dropZone.classList.remove('dragover'); 
        if (fileInput.files.length === 0) resetDropZoneText(); 
    }, false));
    dropZone.addEventListener('drop', (e) => { fileInput.files = e.dataTransfer.files; updateFileName(fileInput.files); }, false);
    fileInput.addEventListener('change', function() { updateFileName(this.files); });
}

function preventDefaults(e) { e.preventDefault(); e.stopPropagation(); }

function updateFileName(files) {
    if (files.length > 0) {
        let totalSize = 0;
        let oversized = false;
        
        for (let i = 0; i < files.length; i++) {
            totalSize += files[i].size;
            if (files[i].size > MAX_SIZE_BYTES) oversized = true;
        }

        if (oversized) {
            dropZone.classList.add('error');
            dropZoneText.innerHTML = '❌ One or more files are too large!';
            dropZoneSubtext.innerHTML = `Individual limit per file is ${MAX_SIZE_MB}MB.`;
            fileInput.value = '';
            submitBtn.disabled = true;
            return;
        }

        dropZone.classList.remove('error');
        if (files.length === 1) {
            dropZoneText.innerHTML = '✅ ' + files[0].name;
        } else {
            dropZoneText.innerHTML = '✅ ' + files.length + ' files selected';
        }
        
        dropZoneSubtext.innerHTML = (totalSize / 1024 / 1024).toFixed(2) + ' MB total ready to upload';
        dropZone.style.borderColor = '#10B981';
        submitBtn.disabled = false;
    } else { resetDropZoneText(); }
}

function resetDropZoneText() { 
    dropZone.classList.remove('error');
    dropZoneText.innerHTML = '📁 Click to browse or Drag & Drop multiple files here'; 
    dropZoneSubtext.innerHTML = `Maximum Size per file: ${MAX_SIZE_MB}MB (Direct to Cloudflare R2)`; 
    dropZone.style.borderColor = 'var(--primary-color)'; 
    submitBtn.disabled = false;
}

if (uploadForm) {
    uploadForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const files = fileInput.files;
        if(files.length === 0) { alert("Please select files first."); return; }

        submitBtn.disabled = true;
        progressBar.style.display = 'block';
        
        let uploadedCount = 0;

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            
            let docTitle = uploadForm.title.value;
            if (files.length > 1) {
                docTitle = docTitle + ' - ' + file.name;
            }

            const authData = new FormData();
            authData.append('ajax_action', 'get_upload_url');
            authData.append('category', uploadForm.category.value);
            authData.append('filename', file.name);
            authData.append('mime_type', file.type || 'application/octet-stream');

            try {
                let authRes = await fetch('documentation.php', { method: 'POST', body: authData });
                let authJson = await authRes.json();
                if(!authJson.success) throw new Error(authJson.error);

                await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('PUT', authJson.url, true);
                    xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                    
                    xhr.upload.onprogress = function(e) {
                        if (e.lengthComputable) {
                            const filePercent = (e.loaded / e.total) * 100;
                            const overallPercent = ((i + (filePercent/100)) / files.length) * 100;
                            progressFill.style.width = overallPercent + '%';
                            submitBtn.innerHTML = `Uploading file ${i+1} of ${files.length} (${Math.round(overallPercent)}%)`;
                        }
                    };

                    xhr.onload = async function() {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            const dbData = new FormData(uploadForm);
                            dbData.append('ajax_action', 'save_document_record');
                            dbData.append('file_key', authJson.key);
                            dbData.append('filename', file.name);
                            dbData.set('title', docTitle); 

                            let dbRes = await fetch('documentation.php', { method: 'POST', body: dbData });
                            let dbJson = await dbRes.json();
                            
                            if(dbJson.success) { resolve(); } 
                            else { reject(new Error(dbJson.error)); }
                        } else {
                            reject(new Error('Cloudflare rejected the upload. Check CORS settings.'));
                        }
                    };
                    xhr.onerror = () => reject(new Error('Network Error during upload.'));
                    xhr.send(file);
                });
                
                uploadedCount++;
            } catch (err) {
                alert(`Error uploading ${file.name}: ${err.message}`);
            }
        }

        if (uploadedCount > 0) {
            window.location.reload();
        } else {
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Upload & Encrypt';
            progressBar.style.display = 'none';
        }
    });
}
</script>

<?php require_once 'footer.php'; ?>
