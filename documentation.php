<?php
require_once 'init.php';
require_once 'session-check.php';
require_once 'S3FileManager.php'; 

$userId = getCurrentUserId();
$isAdmin = isAdmin();

$s3 = new S3FileManager();
$message = ''; 
$error = '';

// ==========================================
// 1. DETERMINE USER CATEGORY PERMISSIONS
// ==========================================
$stmtPerms = $pdo->prepare("SELECT doc_bca, doc_ohsa, doc_drawings, doc_engineering, doc_commercial, doc_sales FROM users WHERE id = ?");
$stmtPerms->execute([$userId]);
$uPerm = $stmtPerms->fetch(PDO::FETCH_ASSOC);

// Map categories to their integer access level
$docPerms = [];
if ($isAdmin || (int)$uPerm['doc_bca'] > 0) $docPerms['BCA'] = $isAdmin ? 4 : (int)$uPerm['doc_bca'];
if ($isAdmin || (int)$uPerm['doc_ohsa'] > 0) $docPerms['OHSA'] = $isAdmin ? 4 : (int)$uPerm['doc_ohsa'];
if ($isAdmin || (int)$uPerm['doc_drawings'] > 0) $docPerms['Drawings'] = $isAdmin ? 4 : (int)$uPerm['doc_drawings'];
if ($isAdmin || (int)$uPerm['doc_engineering'] > 0) $docPerms['Engineering'] = $isAdmin ? 4 : (int)$uPerm['doc_engineering'];
if ($isAdmin || (int)$uPerm['doc_commercial'] > 0) $docPerms['Commercial'] = $isAdmin ? 4 : (int)$uPerm['doc_commercial'];
if ($isAdmin || (int)$uPerm['doc_sales'] > 0) $docPerms['Sales'] = $isAdmin ? 4 : (int)$uPerm['doc_sales'];

$accessibleCategories = array_keys($docPerms);

// Handle Actions (Upload / Edit / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_document') {
        try {
            $projectId = (int)$_POST['project_id'];
            $category = $_POST['category'];
            
            if (!isset($docPerms[$category]) || $docPerms[$category] < 3) {
                throw new Exception("You do not have permission to upload documents to the '$category' category.");
            }

            $subCategory = trim($_POST['sub_category'] ?? '');
            $title = trim($_POST['title']);
            $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            
            if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                $tmpPath = $_FILES['document_file']['tmp_name'];
                $originalName = $_FILES['document_file']['name'];
                $mimeType = $_FILES['document_file']['type'];
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                $fileKey = $s3->uploadFile($tmpPath, $originalName, $mimeType, strtolower($category));
                
                if ($fileKey) {
                    $stmt = $pdo->prepare("INSERT INTO project_documents (project_id, category, sub_category, title, file_path, file_type, expiry_date, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$projectId, $category, $subCategory, $title, $fileKey, $ext, $expiryDate, $userId]);
                    $message = "Document uploaded successfully!";
                } else {
                    $error = "Failed to upload file to Cloud Storage.";
                }
            } else {
                $error = "No file selected or upload error.";
            }
        } catch (Exception $e) { $error = $e->getMessage(); }
    } 
    elseif ($action === 'edit_document') {
        try {
            $docId = (int)$_POST['document_id'];
            $title = trim($_POST['title']);
            $subCategory = trim($_POST['sub_category']);
            $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            
            $dismissAlarm = isset($_POST['alarm_dismissed']) ? 1 : 0;
            $dismissReason = trim($_POST['alarm_dismissed_reason'] ?? '');

            // Verify Permissions
            $chk = $pdo->prepare("SELECT category FROM project_documents WHERE id = ?");
            $chk->execute([$docId]);
            $dCat = $chk->fetchColumn();
            
            if (!isset($docPerms[$dCat]) || $docPerms[$dCat] < 3) {
                throw new Exception("You do not have permission to edit documents in this category.");
            }

            if ($dismissAlarm) {
                if (empty($dismissReason)) throw new Exception("You must provide a reason to dismiss the expiry alarm.");
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
                if (!isset($docPerms[$doc['category']]) || $docPerms[$doc['category']] < 4) {
                    throw new Exception("You do not have permission to delete documents in this category. (Requires Level 4 Access)");
                }
                
                $s3->deleteFile($doc['file_path']);
                $pdo->prepare("DELETE FROM project_documents WHERE id = ?")->execute([$docId]);
                $message = "Document deleted successfully!";
            }
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
        if ($_GET['action'] === 'download' && $docPerms[$doc['category']] < 2) die("Requires Level 2 Access to download.");

        $url = $s3->getPresignedUrl($doc['file_path'], '+60 minutes');
        if ($url) {
            header("Location: " . $url);
            exit;
        } else {
            $error = "Could not generate secure link.";
        }
    }
}

// ==========================================
// 2. DATA FETCHING & FILTERING
// ==========================================
$selectedProjectId = (isset($_GET['project_id']) && $_GET['project_id'] !== 'all') ? (int)$_GET['project_id'] : 'all';
$selectedCategory = (isset($_GET['category']) && $_GET['category'] !== 'all') ? $_GET['category'] : 'all';

$projects = getAccessibleProjects($pdo, $userId);
$accessibleProjectIds = array_column($projects, 'id');

$canUploadAnything = false;
foreach ($docPerms as $lvl) { if ($lvl >= 3) { $canUploadAnything = true; break; } }

if (empty($accessibleProjectIds) || empty($accessibleCategories)) {
    $documents = [];
    $expiringDocs = [];
} else {
    $placeholders = implode(',', array_fill(0, count($accessibleProjectIds), '?'));
    $params = $accessibleProjectIds;
    
    $allowedCatString = implode("','", array_map(function($c) { return addslashes($c); }, $accessibleCategories));

    // Expiring Docs (EXCLUDES DISMISSED ALARMS)
    $expStmt = $pdo->prepare("
        SELECT d.*, p.name as project_name 
        FROM project_documents d 
        JOIN projects p ON d.project_id = p.id 
        WHERE d.project_id IN ($placeholders) 
        AND d.category IN ('$allowedCatString')
        AND d.expiry_date IS NOT NULL 
        AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND d.alarm_dismissed = 0
        ORDER BY d.expiry_date ASC
    ");
    $expStmt->execute($params);
    $expiringDocs = $expStmt->fetchAll();

    // Main Doc List (Joins User who uploaded AND User who dismissed alarm)
    $query = "SELECT d.*, p.name as project_name, u.first_name, u.last_name, 
              u2.first_name as dis_fn, u2.last_name as dis_ln
              FROM project_documents d 
              JOIN projects p ON d.project_id = p.id 
              LEFT JOIN users u ON d.uploaded_by = u.id 
              LEFT JOIN users u2 ON d.alarm_dismissed_by = u2.id
              WHERE d.project_id IN ($placeholders)
              AND d.category IN ('$allowedCatString')";
    
    if ($selectedProjectId !== 'all') { $query .= " AND d.project_id = ?"; $params[] = $selectedProjectId; }
    if ($selectedCategory !== 'all' && in_array($selectedCategory, $accessibleCategories)) { $query .= " AND d.category = ?"; $params[] = $selectedCategory; }
    
    $query .= " ORDER BY d.created_at DESC";
    $docStmt = $pdo->prepare($query);
    $docStmt->execute($params);
    $documents = $docStmt->fetchAll();
}

$pageTitle = 'Document Vault';
require_once 'header.php';
?>

<style>
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.7); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }

/* Fullscreen Viewer Modal */
.viewer-modal-content { width: 95%; max-width: 1400px; height: 90vh; margin: 2% auto; padding: 1rem; display: flex; flex-direction: column; }
.viewer-iframe { flex: 1; width: 100%; border: 1px solid var(--border-glass); border-radius: 8px; background: #fff; }

.filter-bar { display: flex; gap: 1rem; margin-bottom: 1.5rem; background: var(--bg-panel); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-glass); align-items: center; }
.filter-bar select { padding: 0.5rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-card); color: var(--text-primary); font-size: 0.9rem; min-width: 200px; }

.cat-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; overflow-x: auto; }
.cat-tab { padding: 0.5rem 1rem; border-radius: 6px; color: var(--text-secondary); text-decoration: none; font-weight: 600; font-size: 0.85rem; white-space: nowrap; transition: 0.2s; }
.cat-tab:hover { background: rgba(255,255,255,0.05); color: var(--text-primary); }
.cat-tab.active { background: rgba(99, 102, 241, 0.1); color: var(--primary-color); border: 1px solid rgba(99, 102, 241, 0.3); }

.doc-icon { font-size: 1.5rem; line-height: 1; }
.badge-expired { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239,68,68,0.5); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
.badge-warning { background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245,158,11,0.5); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
.badge-dismissed { background: rgba(100, 116, 139, 0.2); color: #94a3b8; border: 1px solid rgba(100,116,139,0.5); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; cursor: help; }

/* Drag and Drop Zone Styles */
.drop-zone {
    border: 2px dashed var(--primary-color);
    border-radius: 8px;
    padding: 30px;
    text-align: center;
    background: rgba(0,0,0,0.2);
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    margin-top: 5px;
}
.drop-zone:hover {
    background: rgba(99, 102, 241, 0.05);
}
.drop-zone.dragover {
    background: rgba(16, 185, 129, 0.1); /* Soft green background */
    border-color: #10B981; /* Green border */
    transform: scale(1.02);
}
.drop-zone input[type="file"] {
    position: absolute;
    width: 100%;
    height: 100%;
    top: 0;
    left: 0;
    opacity: 0;
    cursor: pointer;
}
.drop-zone-text {
    font-size: 1.1rem;
    font-weight: 600;
    pointer-events: none; /* Let clicks pass through to input */
    color: var(--text-primary);
    transition: color 0.3s ease;
}
.drop-zone.dragover .drop-zone-text {
    color: #10B981;
}
.drop-zone-subtext {
    font-size: 0.8rem;
    margin-top: 8px;
    pointer-events: none;
}
</style>

<div class="main-container">

    <?php if (!empty($expiringDocs)): ?>
    <div style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.3); border-left: 4px solid #ef4444; border-radius: 8px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.2);">
        <h3 style="margin-top: 0; color: #ef4444; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
            ⚠️ Action Required: Documents Expiring Soon
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
            <?php foreach($expiringDocs as $edoc): 
                $now = new DateTime(); $exp = new DateTime($edoc['expiry_date']);
                $now->setTime(0,0,0); $exp->setTime(0,0,0);
                $days = (int)$now->diff($exp)->format('%r%a');
            ?>
                <div style="background: #1e1e2d; padding: 1rem; border-radius: 6px; border: 1px solid var(--border-glass);">
                    <div style="font-weight: 800; color: var(--primary-color); margin-bottom: 4px;"><?= htmlspecialchars($edoc['project_name']) ?></div>
                    <div style="font-size: 0.85rem; color: #fff; margin-bottom: 8px;"><?= htmlspecialchars($edoc['title']) ?></div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <?php if($days < 0): ?><span class="badge-expired">Expired <?= abs($days) ?>d ago</span>
                        <?php elseif($days === 0): ?><span class="badge-expired">Expires TODAY</span>
                        <?php else: ?><span class="badge-warning">Expires in <?= $days ?>d</span><?php endif; ?>
                        
                        <button onclick="openViewer(<?= $edoc['id'] ?>, '<?= htmlspecialchars($edoc['title'], ENT_QUOTES) ?>')" class="btn btn-sm btn-secondary" style="padding: 2px 8px;">View</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">Universal Document Vault</h1>
            <p style="color: var(--text-secondary); margin-top: 0.25rem;">Secure Cloudflare R2 Storage.</p>
        </div>
        <?php if ($canUploadAnything): ?>
            <button onclick="openUploadModal()" class="btn btn-primary">+ Upload Document</button>
        <?php endif; ?>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="filter-bar">
        <strong style="color: var(--primary-color);">Filter Context:</strong>
        <form method="GET" style="margin: 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="category" value="<?= htmlspecialchars($selectedCategory) ?>">
            <select name="project_id" onchange="this.form.submit()">
                <option value="all">-- All Accessible Projects --</option>
                <?php foreach($projects as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $selectedProjectId == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="cat-tabs">
        <a href="?project_id=<?= $selectedProjectId ?>&category=all" class="cat-tab <?= $selectedCategory === 'all' ? 'active' : '' ?>">All Permitted Documents</a>
        <?php foreach($accessibleCategories as $cat): ?>
            <a href="?project_id=<?= $selectedProjectId ?>&category=<?= urlencode($cat) ?>" class="cat-tab <?= $selectedCategory === $cat ? 'active' : '' ?>"><?= htmlspecialchars($cat) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 40px;">Type</th>
                    <th>Document Title</th>
                    <th>Project</th>
                    <th>Category</th>
                    <th>Expiry / Status</th>
                    <th>Uploaded By</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($documents)): ?>
                    <tr><td colspan="7" style="text-align: center; padding: 2rem;">No documents found.</td></tr>
                <?php else: ?>
                    <?php foreach($documents as $d): 
                        $ext = strtolower($d['file_type']);
                        $icon = '📄';
                        if ($ext === 'pdf') $icon = '📕';
                        if (in_array($ext, ['dwg', 'dxf', 'cad'])) $icon = '📐';
                        if (in_array($ext, ['jpg', 'png', 'jpeg'])) $icon = '🖼️';
                        if (in_array($ext, ['mp4', 'mov', 'avi'])) $icon = '🎬';

                        $expText = '-';
                        if ($d['expiry_date']) {
                            $expText = date('d M Y', strtotime($d['expiry_date']));
                            if ($d['alarm_dismissed']) {
                                $tooltip = "Dismissed by " . htmlspecialchars($d['dis_fn']) . ": " . htmlspecialchars($d['alarm_dismissed_reason']);
                                $expText = "<span class='badge-dismissed' title='$tooltip'>$expText (Alarm Off)</span>";
                            } elseif (strtotime($d['expiry_date']) < time()) {
                                $expText = "<span class='badge-expired'>$expText (Expired)</span>";
                            }
                        }
                        $catLvl = $docPerms[$d['category']] ?? 0;
                    ?>
                    <tr>
                        <td class="doc-icon" style="text-align: center;"><?= $icon ?></td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($d['title']) ?></div>
                            <?php if(!empty($d['sub_category'])): ?>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($d['sub_category']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="color: var(--primary-color); font-weight: 500;"><?= htmlspecialchars($d['project_name']) ?></td>
                        <td><span class="badge" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);"><?= htmlspecialchars($d['category']) ?></span></td>
                        <td><?= $expText ?></td>
                        <td style="font-size: 0.8rem; color: var(--text-secondary);">
                            <?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?><br>
                            <span style="color: var(--text-muted);"><?= date('d M y', strtotime($d['created_at'])) ?></span>
                        </td>
                        <td style="text-align: right;">
                            <button onclick="openViewer(<?= $d['id'] ?>, '<?= htmlspecialchars($d['title'], ENT_QUOTES) ?>')" class="btn btn-sm btn-secondary" title="View Document in Screen">👁️</button>
                            
                            <?php if ($catLvl >= 2): ?>
                                <a href="?action=download&id=<?= $d['id'] ?>" target="_blank" class="btn btn-sm btn-primary" title="Download Original File">↓</a>
                            <?php endif; ?>
                            
                            <?php if ($catLvl >= 3): ?>
                                <button onclick='openEditModal(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="btn btn-sm" style="background: rgba(245,158,11,0.2); color: #f59e0b; border: 1px solid #f59e0b;" title="Edit Document & Alarms">✎</button>
                            <?php endif; ?>

                            <?php if ($catLvl >= 4): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete this document from the cloud?');">
                                    <input type="hidden" name="action" value="delete_document">
                                    <input type="hidden" name="document_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete Permanently">X</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

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
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_document">
            
            <div class="form-group">
                <label>Project Context *</label>
                <select name="project_id" required>
                    <option value="">-- Select Project --</option>
                    <?php foreach($projects as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $selectedProjectId == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
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
                    <input type="text" name="sub_category" placeholder="e.g. CAR Insurance" list="subcat_suggestions">
                    <datalist id="subcat_suggestions"></datalist>
                </div>
            </div>

            <div class="form-group">
                <label>Document Title *</label>
                <input type="text" name="title" required>
            </div>

            <div class="form-group">
                <label>File to Upload *</label>
                <div class="drop-zone" id="drop_zone">
                    <input type="file" name="document_file" id="document_file" required>
                    <div class="drop-zone-text" id="drop_zone_text">📁 Click to browse or Drag & Drop here</div>
                    <div class="drop-zone-subtext" id="drop_zone_subtext">PDFs, DWGs, Images, and Videos are securely streamed to R2.</div>
                </div>
            </div>

            <div class="form-group" style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); padding: 10px; border-radius: 6px;">
                <label style="color: #f59e0b; margin-bottom: 5px;">Expiry Date (Optional - Triggers Alarms)</label>
                <input type="date" name="expiry_date" style="background: var(--bg-panel);">
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Upload & Encrypt</button>
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
                    <div id="edit_dismissed_info" style="display: none; margin-top: 10px; font-size: 0.8rem; color: #94a3b8; font-style: italic;">
                        </div>
                </div>
            </div>
            
            <button type="submit" class="btn btn-secondary" style="width: 100%; margin-top: 10px;">Save Changes</button>
        </form>
    </div>
</div>

<script>
// UI Modals
function openUploadModal() { document.getElementById('uploadModal').style.display = 'block'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = function(event) {
    if (event.target == document.getElementById('uploadModal')) closeModal('uploadModal');
    if (event.target == document.getElementById('editModal')) closeModal('editModal');
    if (event.target == document.getElementById('viewerModal')) closeViewer();
}

// Fullscreen Viewer
function openViewer(id, title) {
    document.getElementById('viewerTitle').textContent = 'Viewing: ' + title;
    document.getElementById('viewerFrame').src = '?action=view&id=' + id;
    document.getElementById('viewerModal').style.display = 'block';
}
function closeViewer() {
    document.getElementById('viewerModal').style.display = 'none';
    document.getElementById('viewerFrame').src = ''; 
}

// Edit Modal Prep
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
        chk.checked = false;
        rsn.value = '';
        info.style.display = 'none';
    }
    
    toggleReasonField();
    document.getElementById('editModal').style.display = 'block';
}

function toggleReasonField() {
    const isChecked = document.getElementById('edit_alarm_check').checked;
    const rsnDiv = document.getElementById('edit_reason_div');
    const rsnInput = document.getElementById('edit_alarm_reason');
    
    if (isChecked) {
        rsnDiv.style.display = 'block';
        rsnInput.required = true;
    } else {
        rsnDiv.style.display = 'none';
        rsnInput.required = false;
    }
}

// Drag & Drop Functionality
const dropZone = document.getElementById('drop_zone');
const dropZoneText = document.getElementById('drop_zone_text');
const dropZoneSubtext = document.getElementById('drop_zone_subtext');
const fileInput = document.getElementById('document_file');

if (dropZone) {
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
            dropZone.classList.add('dragover');
            dropZoneText.innerHTML = '🔥 Drop it like it\'s hot! 🔥';
            dropZoneSubtext.innerHTML = 'Release to attach file';
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
            dropZone.classList.remove('dragover');
            if (fileInput.files.length === 0) resetDropZoneText();
        }, false);
    });

    dropZone.addEventListener('drop', (e) => {
        let dt = e.dataTransfer;
        let files = dt.files;
        fileInput.files = files; 
        updateFileName(files);
    }, false);
    
    fileInput.addEventListener('change', function() {
        updateFileName(this.files);
    });
}

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

function updateFileName(files) {
    if (files.length > 0) {
        dropZoneText.innerHTML = '✅ ' + files[0].name;
        dropZoneSubtext.innerHTML = (files[0].size / 1024 / 1024).toFixed(2) + ' MB ready to upload';
        dropZone.style.borderColor = '#10B981';
    } else {
        resetDropZoneText();
    }
}

function resetDropZoneText() {
    dropZoneText.innerHTML = '📁 Click to browse or Drag & Drop here';
    dropZoneSubtext.innerHTML = 'PDFs, DWGs, Images, and Videos are securely streamed to R2.';
    dropZone.style.borderColor = 'var(--primary-color)';
}

// Suggestions Dictionary
const suggestions = {
    'BCA': ['Condition Report', 'Method Statement', 'CAR Insurance', 'Bank Guarantee', 'Responsibility Form', 'Clearance Letter'],
    'Engineering': ['ARMS Application', 'Water/Sewerage Application', 'PA Compliance', 'EPC Certificate', 'Lift Certification', 'Fire Safety Comm.'],
    'OHSA': ['TC Certificate', 'Plant Certificate', 'RAMS', 'Safety Report', 'Incident Report'],
    'Drawings': ['Architectural Plan', 'Structural Plan', 'Services Plan', 'Elevations', 'Sections'],
    'Commercial': ['Quote', 'Contract', 'PO', 'Guarantee', 'Receipt'],
    'Sales': ['Price List', 'Marketing Plan', 'Render (Image)', 'Render (Video)', 'Brochure']
};

function updateSubCategories(category, listId) {
    const dataList = document.getElementById(listId);
    if(!dataList) return;
    dataList.innerHTML = '';
    if (suggestions[category]) {
        suggestions[category].forEach(item => {
            const option = document.createElement('option');
            option.value = item;
            dataList.appendChild(option);
        });
    }
}
</script>

<?php require_once 'footer.php'; ?>
