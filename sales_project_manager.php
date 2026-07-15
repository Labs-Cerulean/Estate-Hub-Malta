<?php
require_once 'config.php';
require_once 'session-check.php';
require_once __DIR__ . '/includes/nav_config.php';

if (!navCanAccessSalesProjectManager()) {
    header("Location: index.php?error=unauthorized");
    exit;
}

// ---------------------------------------------------------
// AUTO-DEPLOY DATABASE UPDATES (Failsafes)
// ---------------------------------------------------------
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ALTER TABLE sales_properties ADD COLUMN block VARCHAR(50) DEFAULT ''");
    $pdo->exec("ALTER TABLE project_documents ADD COLUMN sort_order INT DEFAULT 0");
} catch(PDOException $e) { /* Ignore if columns exist */ }

// ---------------------------------------------------------
// AJAX ENDPOINTS
// ---------------------------------------------------------
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        if ($action === 'load_project') {
            $pid = (int)$_POST['project_id'];
            if (!hasSalesProjectAccess($pdo, $pid)) {
                salesDenyJsonAccess();
            }
            
            // Strictly using real DB columns: floor_level and unit_type
            $stmtUnits = $pdo->prepare("
                SELECT * FROM sales_properties 
                WHERE project_id = ? 
                ORDER BY 
                    block ASC, 
                    CAST(floor_level AS SIGNED) ASC, 
                    FIELD(unit_type, 'garage', 'parking space', 'commercial', 'maisonette', 'apartment', 'penthouse', 'villa', 'house'), 
                    unit_name ASC
            ");
            $stmtUnits->execute([$pid]);
            $units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);

            $stmtMedia = $pdo->prepare("SELECT * FROM project_documents WHERE project_id = ? AND category = 'Sales' ORDER BY sort_order ASC, id ASC");
            $stmtMedia->execute([$pid]);
            $mediaRaw = $stmtMedia->fetchAll(PDO::FETCH_ASSOC);

            // --- FIX: CLOUDFLARE/S3 PRESIGNED URL GENERATOR ---
            $media = [];
            $s3Loaded = false;
            $s3 = null;

            foreach ($mediaRaw as $m) {
                $path = $m['file_path'];
                
                // If the path is an internal S3/Cloudflare key (doesn't start with http)
                if (!empty($path) && strpos($path, 'http') === false) {
                    if (!$s3Loaded) {
                        require_once 'S3FileManager.php';
                        $s3 = new S3FileManager();
                        $s3Loaded = true;
                    }
                    try {
                        // Generate a secure viewing URL valid for 2 hours
                        $m['file_path'] = $s3->getPresignedUrl($path, '+120 minutes');
                    } catch (Exception $e) {
                        // Fallback to original path if Cloudflare fails
                    }
                }
                $media[] = $m;
            }

            if (salesVisibilityColumnsAvailable($pdo)) {
                $stmtVisibility = $pdo->prepare('SELECT show_for_sale, show_for_sale_external FROM projects WHERE id = ?');
                $stmtVisibility->execute([$pid]);
                $visibilityRow = $stmtVisibility->fetch(PDO::FETCH_ASSOC) ?: [];
                $visibility = [
                    'columns_available' => true,
                    'show_for_sale' => (int)($visibilityRow['show_for_sale'] ?? 1),
                    'show_for_sale_external' => (int)($visibilityRow['show_for_sale_external'] ?? 0),
                ];
            } else {
                $visibility = [
                    'columns_available' => false,
                    'show_for_sale' => 1,
                    'show_for_sale_external' => 0,
                ];
            }

            echo json_encode(['success' => true, 'units' => $units, 'media' => $media, 'visibility' => $visibility]);
            exit;
        }

        if ($action === 'update_sale_visibility') {
            $pid = (int)$_POST['project_id'];
            salesAssertProjectAccess($pdo, $pid);

            $allowedVisibilityRoles = ['admin', 'sales_manager', 'director'];
            if (!in_array($_SESSION['role'], $allowedVisibilityRoles, true)) {
                salesDenyJsonAccess('Unauthorized.');
            }

            if (!salesVisibilityColumnsAvailable($pdo)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Sales visibility requires sql/2026-07-15_sales_visibility_flags.sql to be run in phpMyAdmin first.',
                ]);
                exit;
            }

            $showForSale = !empty($_POST['show_for_sale']) ? 1 : 0;
            $showExternal = !empty($_POST['show_for_sale_external']) ? 1 : 0;

            $stmt = $pdo->prepare('UPDATE projects SET show_for_sale = ?, show_for_sale_external = ? WHERE id = ?');
            $stmt->execute([$showForSale, $showExternal, $pid]);

            echo json_encode([
                'success' => true,
                'visibility' => [
                    'show_for_sale' => $showForSale,
                    'show_for_sale_external' => $showExternal,
                ],
            ]);
            exit;
        }

        if ($action === 'save_frame') {
            $pid = (int)$_POST['project_id'];
            salesAssertProjectAccess($pdo, $pid);
            $unitsData = json_decode($_POST['units'], true);
            $userId = $_SESSION['user_id'];
            
            $pdo->beginTransaction();
            $stmtUpdate = $pdo->prepare("UPDATE sales_properties SET unit_name=?, block=?, floor_level=?, unit_type=?, description=?, internal_sqm=?, external_sqm=?, shell_price=?, finishes_price=? WHERE id=? AND project_id=?");
            $stmtInsert = $pdo->prepare("INSERT INTO sales_properties (project_id, unit_name, block, floor_level, unit_type, description, internal_sqm, external_sqm, shell_price, finishes_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available')");
            
            $stmtGetOld = $pdo->prepare("SELECT * FROM sales_properties WHERE id = ?");
            $stmtLog = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
            
            foreach ($unitsData as $u) {
                if (!empty($u['id']) && $u['id'] > 0) {
                    $stmtGetOld->execute([$u['id']]);
                    $oldUnit = $stmtGetOld->fetch(PDO::FETCH_ASSOC);

                    $stmtUpdate->execute([
                        $u['unit_name'], $u['block'], $u['floor_level'], $u['unit_type'], $u['description'], 
                        (float)$u['internal_sqm'], (float)$u['external_sqm'], (float)$u['shell_price'], (float)$u['finishes_price'], 
                        $u['id'], $pid
                    ]);

                    // --- 100% BULLETPROOF AUDIT CHECKER ---
                    if ($oldUnit) {
                        $changes = [];
                        $fields_to_check = [
                            'unit_name' => 'Name', 'block' => 'Block', 'floor_level' => 'Level',
                            'unit_type' => 'Type', 'description' => 'Desc', 
                            'internal_sqm' => 'IntSQM', 'external_sqm' => 'ExtSQM',
                            'shell_price' => 'Shell', 'finishes_price' => 'Finishes'
                        ];

                        foreach ($fields_to_check as $col => $label) {
                            $old_val = $oldUnit[$col];
                            $new_val = $u[$col];
                            
                            // Strict math comparison for numbers to prevent "150000.00 -> 150000" triggering a false log
                            if (in_array($col, ['internal_sqm', 'external_sqm', 'shell_price', 'finishes_price'])) {
                                if ((float)$old_val !== (float)$new_val) {
                                    $changes[] = "{$label}: " . (float)$old_val . " -> " . (float)$new_val;
                                }
                            } else {
                                if ((string)$old_val !== (string)$new_val) {
                                    $changes[] = "{$label}: '{$old_val}' -> '{$new_val}'";
                                }
                            }
                        }
                        
                        if (!empty($changes)) {
                            // If a user changed 9 things at once, truncate safely so it doesn't break the DB insert
                            $justification = "Bulk Edit: " . implode(' | ', $changes);
                            $stmtLog->execute([
                                $u['id'], 
                                $userId, 
                                'Project Manager Edit', 
                                $oldUnit['status'], 
                                $oldUnit['status'], 
                                substr($justification, 0, 255) 
                            ]);
                        }
                    }
                } else {
                    if (trim($u['unit_name']) !== '') {
                        $stmtInsert->execute([
                            $pid, $u['unit_name'], $u['block'], $u['floor_level'], $u['unit_type'], $u['description'], 
                            (float)$u['internal_sqm'], (float)$u['external_sqm'], (float)$u['shell_price'], (float)$u['finishes_price']
                        ]);
                        
                        $stmtLog->execute([
                            $pdo->lastInsertId(), $userId, 'Unit Created', 'New', 'Available', 'Created via Manager Frame Editor'
                        ]);
                    }
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'delete_unit') {
            $unit_id = (int)$_POST['unit_id'];
            salesAssertPropertyAccess($pdo, $unit_id);
            $stmt = $pdo->prepare("SELECT unit_name, status FROM sales_properties WHERE id = ?");
            $stmt->execute([$unit_id]);
            $unit = $stmt->fetch();
            
            if ($unit) {
                $pdo->prepare("DELETE FROM sales_properties WHERE id = ?")->execute([$unit_id]);
                $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$unit_id, $_SESSION['user_id'], 'Unit Deleted', $unit['status'], 'Deleted', "Permanently deleted unit: " . $unit['unit_name']]);
            }
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'delete_media') {
            $media_id = (int)$_POST['media_id'];
            $stmt = $pdo->prepare("SELECT title, project_id FROM project_documents WHERE id = ?");
            $stmt->execute([$media_id]);
            $media = $stmt->fetch();
            
            if ($media) {
                if (!hasSalesProjectAccess($pdo, (int)$media['project_id'])) {
                    salesDenyJsonAccess();
                }
                $pdo->prepare("DELETE FROM project_documents WHERE id = ?")->execute([$media_id]);
                $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([0, $_SESSION['user_id'], 'Media Deleted', 'Active', 'Deleted', "[Project " . $media['project_id'] . " Media] Deleted file: " . $media['title']]);
            }
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'update_media_order') {
            $sortData = json_decode($_POST['sort_data'], true);
            $docIds = array_map(static function ($item) {
                return (int)$item['id'];
            }, $sortData ?? []);

            if (!empty($docIds)) {
                $placeholders = implode(',', array_fill(0, count($docIds), '?'));
                $stmtDocs = $pdo->prepare("SELECT DISTINCT project_id FROM project_documents WHERE id IN ($placeholders)");
                $stmtDocs->execute($docIds);
                $projectIds = $stmtDocs->fetchAll(PDO::FETCH_COLUMN);
                foreach ($projectIds as $docProjectId) {
                    if ((int)$docProjectId <= 0 || !hasSalesProjectAccess($pdo, (int)$docProjectId)) {
                        salesDenyJsonAccess();
                    }
                }
            }

            $stmt = $pdo->prepare("UPDATE project_documents SET sort_order = ? WHERE id = ?");
            foreach ($sortData as $item) {
                $stmt->execute([(int)$item['order'], (int)$item['id']]);
            }
            $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([0, $_SESSION['user_id'], 'Media Reordered', 'N/A', 'N/A', "User updated the display order of the media gallery"]);
            echo json_encode(['success' => true]);
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$pageTitle = 'Sales Project Manager';
require_once 'header.php';
require_once 'S3FileManager.php';

// Projects with units (main grid) and empty projects (frame CSV upload only)
$projectsRaw = salesGetAccessibleProjectsWithUnits($pdo);
$projectsByCity = [];
foreach ($projectsRaw as $p) {
    $city = trim($p['city']) ? trim($p['city']) : 'Uncategorized Locations';
    $projectsByCity[$city][] = $p;
}

$projectThumbUrls = [];
$emptyFrameProjects = [];
$emptyFrameThumbUrls = [];

try {
    $accessibleProjects = salesGetAccessibleProjects($pdo);
    if (!empty($accessibleProjects)) {
        $candidateIds = array_map('intval', array_column($accessibleProjects, 'id'));
        $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $unitStmt = $pdo->prepare("SELECT DISTINCT project_id FROM sales_properties WHERE project_id IN ($placeholders)");
        $unitStmt->execute($candidateIds);
        $projectsWithUnits = array_flip(array_map('intval', $unitStmt->fetchAll(PDO::FETCH_COLUMN)));

        $withUnitIds = [];
        foreach ($accessibleProjects as $projectRow) {
            $pid = (int)$projectRow['id'];
            if (isset($projectsWithUnits[$pid])) {
                $withUnitIds[] = $pid;
            } else {
                $emptyFrameProjects[] = $projectRow;
            }
        }

        $fetchThumbUrls = function (array $projectIds) use ($pdo): array {
            if (empty($projectIds)) {
                return [];
            }
            $thumbPlaceholders = implode(',', array_fill(0, count($projectIds), '?'));
            $thumbStmt = $pdo->prepare("
                SELECT pd.project_id, pd.file_path
                FROM project_documents pd
                INNER JOIN (
                    SELECT project_id, MIN(id) AS min_id
                    FROM project_documents
                    WHERE category = 'Sales'
                      AND sub_category = 'Render (Image)'
                      AND project_id IN ($thumbPlaceholders)
                    GROUP BY project_id
                ) first_render ON pd.id = first_render.min_id
            ");
            $thumbStmt->execute($projectIds);
            $urls = [];
            $s3 = new S3FileManager();
            foreach ($thumbStmt->fetchAll(PDO::FETCH_ASSOC) as $thumbRow) {
                try {
                    $urls[(int)$thumbRow['project_id']] = $s3->getPresignedUrl($thumbRow['file_path'], '+120 minutes');
                } catch (Exception $e) {
                    // Skip broken media keys
                }
            }
            return $urls;
        };

        $projectThumbUrls = $fetchThumbUrls($withUnitIds);
        if (!empty($emptyFrameProjects)) {
            $emptyIds = array_map('intval', array_column($emptyFrameProjects, 'id'));
            $emptyFrameThumbUrls = $fetchThumbUrls($emptyIds);
        }
    }
} catch (Exception $e) {
    $projectThumbUrls = [];
    $emptyFrameProjects = [];
    $emptyFrameThumbUrls = [];
}
?>

<style>
    /* Dark Mode Theme aligned with Sales Hub */
    :root {
        --pm-bg-base: #0f172a;
        --pm-bg-panel: #1e293b;
        --pm-border: #334155;
        --pm-border-light: rgba(255,255,255,0.1);
        --pm-text-main: #f8fafc;
        --pm-text-muted: #94a3b8;
        --pm-accent: #3b82f6;
        --pm-avail: #10b981;
        --pm-proc: #f59e0b;
        --pm-danger: #ef4444;
    }

    /* Wrap the entire page in the dark theme */
    .manager-wrapper { 
        background-color: var(--pm-bg-base); 
        min-height: 100vh; 
        padding-top: 20px; 
        padding-bottom: 50px;
    }

    .manager-container { 
        max-width: 1500px; 
        margin: 0 auto; 
        padding: 0 20px; 
        font-family: 'Inter', sans-serif; 
        color: var(--pm-text-main); 
    }
    
    /* Strict Overrides to kill global CSS conflicts */
    .manager-container input, .manager-container select { 
        background-color: var(--pm-bg-base) !important; 
        color: var(--pm-text-main) !important; 
        border: 1px solid var(--pm-border) !important; 
    }

    .header-bar { 
        display: flex; 
        justify-content: space-between; 
        align-items: flex-start; 
        flex-wrap: wrap;
        gap: 20px;
        background: var(--pm-bg-panel); 
        padding: 20px; 
        border-radius: 12px; 
        box-shadow: 0 10px 30px rgba(0,0,0,0.5); 
        margin-bottom: 20px; 
        border: 1px solid var(--pm-border); 
    }
    .header-bar h2 { color: var(--pm-text-main) !important; }
    .pm-toolbar { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; }

    .pm-project-section {
        background: var(--pm-bg-panel);
        padding: 20px;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        margin-bottom: 20px;
        border: 1px solid var(--pm-border);
    }
    .pm-project-section h3 { margin: 0 0 15px 0; color: var(--pm-text-main); font-size: 1rem; font-weight: 800; }

    .pm-project-picker-city { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--pm-text-muted); margin: 12px 0 8px; }
    .pm-project-picker-city:first-child { margin-top: 0; }
    .pm-project-picker-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; }
    .pm-project-picker-card {
        background: var(--pm-bg-base); border: 2px solid var(--pm-border); border-radius: 10px;
        padding: 8px; cursor: pointer; text-align: center; transition: 0.2s; color: #fff;
    }
    .pm-project-picker-card:hover { border-color: var(--pm-accent); transform: translateY(-1px); }
    .pm-project-picker-card.selected { border-color: var(--pm-avail); box-shadow: 0 0 0 1px var(--pm-avail); background: rgba(16,185,129,0.08); }
    .pm-project-picker-card.pm-add-card { border-style: dashed; border-color: var(--pm-accent); }
    .pm-project-picker-card.pm-add-card:hover { background: rgba(59,130,246,0.08); }
    .pm-project-picker-thumb {
        width: 100%; aspect-ratio: 4 / 3; border-radius: 6px; overflow: hidden;
        background: rgba(0,0,0,0.35); display: flex; align-items: center; justify-content: center; margin-bottom: 8px;
    }
    .pm-project-picker-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .pm-project-picker-thumb i { font-size: 1.6rem; color: var(--pm-text-muted); }
    .pm-project-picker-thumb .pm-add-icon { font-size: 2.4rem; color: var(--pm-accent); font-weight: 300; line-height: 1; }
    .pm-project-picker-name { font-size: 0.72rem; font-weight: 700; line-height: 1.25; min-height: 2.4em; display: flex; align-items: center; justify-content: center; }

    .pm-modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.9); backdrop-filter: blur(8px); }
    .pm-modal-content { background: var(--pm-bg-panel); margin: 5vh auto; padding: 25px; border: 1px solid var(--pm-border); border-radius: 16px; width: 90%; max-width: 520px; box-shadow: 0 25px 50px rgba(0,0,0,0.5); color: #fff; }
    .pm-modal-content.large { max-width: 1200px; height: 85vh; display: flex; flex-direction: column; }
    .pm-close { float: right; font-size: 1.5rem; color: var(--pm-text-muted); cursor: pointer; line-height: 1; }
    .pm-close:hover { color: #fff; }
    .pm-label { display: block; font-size: 0.75rem; font-weight: 800; color: var(--pm-text-muted); margin-bottom: 8px; text-transform: uppercase; }
    .pm-select { width: 100%; padding: 10px 15px; border-radius: 8px; margin-bottom: 15px; outline: none; }
    .pm-drop-zone { border: 2px dashed var(--pm-border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: 0.2s; background: rgba(0,0,0,0.2); margin-bottom: 15px; }
    .pm-drop-zone:hover { border-color: var(--pm-avail); background: rgba(16,185,129,0.1); }
    #pm-toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 10000; display: flex; flex-direction: column; gap: 10px; }
    .pm-toast { padding: 15px 20px; border-radius: 8px; color: #fff; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.3); transition: opacity 0.3s; display: flex; align-items: center; gap: 10px; }
    
    .tabs { display: flex; gap: 10px; margin-bottom: 20px; }
    .tab { padding: 12px 25px; background: var(--pm-bg-base); color: var(--pm-text-muted); border-radius: 8px; cursor: pointer; font-weight: 700; transition: 0.2s; border: 1px solid var(--pm-border); }
    .tab.active { background: rgba(59, 130, 246, 0.1); color: var(--pm-accent); border-color: var(--pm-accent); }
    
    .tab-content { display: none; background: var(--pm-bg-panel); padding: 25px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid var(--pm-border); }
    .tab-content.active { display: block; }
    .tab-content h4 { color: var(--pm-text-main); }

    /* Frame Editor Table */
    .frame-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .frame-table th { background: var(--pm-bg-base); padding: 12px; text-align: left; color: var(--pm-text-muted); font-weight: 800; border-bottom: 2px solid var(--pm-border); }
    .frame-table td { padding: 8px; border-bottom: 1px solid var(--pm-border); }
    
    .frame-input { width: 100%; padding: 8px; border-radius: 6px; outline: none; font-size: 0.85rem; }
    .frame-input:focus { border-color: var(--pm-accent) !important; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
    .frame-select { width: 100%; padding: 8px; border-radius: 6px; outline: none; font-size: 0.85rem; }

    /* Buttons */
    .btn-heavy { padding: 10px 20px; border-radius: 8px; font-weight: 700; border: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
    .btn-blue { background: rgba(59, 130, 246, 0.1); color: var(--pm-accent); border: 1px solid rgba(59, 130, 246, 0.3); } .btn-blue:hover { background: var(--pm-accent); color:#fff; }
    .btn-green { background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); } .btn-green:hover { background: #10b981; color:#fff; }
    .btn-red { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); } .btn-red:hover { background: #ef4444; color:#fff; }
    .btn-gray { background: var(--pm-bg-base); color: var(--pm-text-muted); border: 1px solid var(--pm-border); } .btn-gray:hover { background: var(--pm-border); color: #fff;}

    /* Media Grid */
    .media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
    .media-card { border: 1px solid var(--pm-border); border-radius: 12px; padding: 10px; background: var(--pm-bg-base); text-align: center; position: relative; }
    .media-card img, .media-card video { width: 100%; height: 140px; object-fit: cover; border-radius: 8px; margin-bottom: 10px; background: var(--pm-bg-panel); }
    .media-title { font-size: 0.8rem; font-weight: 700; color: var(--pm-text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 5px; }
    .media-order { width: 60px; padding: 4px; text-align: center; border-radius: 4px; margin-bottom: 10px; }
    
    .loader { display: none; text-align: center; padding: 40px; color: var(--pm-accent); font-size: 1.2rem; font-weight: bold; }
</style>

<div class="manager-wrapper">
    <div class="manager-container">
        <div class="header-bar">
            <div style="flex: 1; min-width: 280px;">
                <a href="sales_hub.php" style="color: var(--pm-text-muted); text-decoration: none; font-size: 0.9rem; font-weight: bold;">&larr; Back to Sales Hub</a>
                <h2 style="margin: 5px 0 0 0; font-weight: 900;"><i class="fas fa-tools text-blue-500"></i> Sales Project Manager</h2>
                <p style="margin: 5px 0 0 0; color: var(--pm-text-muted); font-size: 0.9rem;">Manage frames, media, daily CSV sync, and sales visibility. Select a project below, or upload a new frame with the (+) tile.</p>
                <div class="pm-toolbar">
                    <button type="button" class="btn-heavy btn-blue" onclick="document.getElementById('dailySyncInput').click()">
                        <i class="fas fa-sync-alt"></i> 1-Click Daily Sync
                    </button>
                    <input type="file" id="dailySyncInput" accept=".csv" style="display:none;" onchange="processDailySync(this)">
                    <button type="button" class="btn-heavy btn-red" onclick="openIgnoredLedger()">
                        <i class="fas fa-eye-slash"></i> Manage Ignored CSV Rows
                    </button>
                    <button type="button" class="btn-heavy btn-green" onclick="openUploadMediaModal()">
                        <i class="fas fa-cloud-upload-alt"></i> Upload Media
                    </button>
                </div>
            </div>
        </div>

        <div class="pm-project-section">
            <h3><i class="fas fa-th-large"></i> Select Project</h3>
            <input type="hidden" id="projectSelect" value="">
            <div id="pmProjectGrid">
                <?php if (empty($projectsByCity)): ?>
                    <p style="color: var(--pm-text-muted); font-size: 0.85rem; margin: 0;">No projects with units yet. Use the (+) tile to upload a frame CSV for a new project.</p>
                <?php else: ?>
                    <?php
                    foreach ($projectsByCity as $city => $projs):
                        echo '<div class="pm-project-picker-city">' . htmlspecialchars($city, ENT_QUOTES, 'UTF-8') . '</div>';
                        echo '<div class="pm-project-picker-grid">';
                        foreach ($projs as $p):
                            $pid = (int)$p['id'];
                            $thumbUrl = $projectThumbUrls[$pid] ?? '';
                    ?>
                        <button type="button" class="pm-project-picker-card" data-project-id="<?= $pid ?>" onclick="selectProjectFromGrid(this)">
                            <div class="pm-project-picker-thumb">
                                <?php if ($thumbUrl): ?>
                                    <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                <?php else: ?>
                                    <i class="fas fa-building" aria-hidden="true"></i>
                                <?php endif; ?>
                            </div>
                            <div class="pm-project-picker-name"><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        </button>
                    <?php
                        endforeach;
                        echo '</div>';
                    endforeach;
                    ?>
                <?php endif; ?>
                <div class="pm-project-picker-grid" style="margin-top: 12px;">
                    <button type="button" class="pm-project-picker-card pm-add-card" onclick="openUploadFrameModal()" title="Upload frame CSV for a new project">
                        <div class="pm-project-picker-thumb">
                            <span class="pm-add-icon" aria-hidden="true">+</span>
                        </div>
                        <div class="pm-project-picker-name">New Project Frame</div>
                    </button>
                </div>
            </div>
        </div>

        <div id="workspace" style="display: none;">
            <div id="saleVisibilityPanel" style="background: var(--pm-bg-panel); border: 1px solid var(--pm-border); border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 12px 0; color: var(--pm-text-main);"><i class="fas fa-eye"></i> Sales Visibility</h4>
                <p style="margin: 0 0 15px 0; color: var(--pm-text-muted); font-size: 0.85rem;">Control whether this project appears in the in-house Sales Hub and the external agent library.</p>
                <p id="saleVisibilityMigrationNote" style="display:none; margin: 0 0 15px 0; color: #f59e0b; font-size: 0.85rem; font-weight: 600;">
                    Run sql/2026-07-15_sales_visibility_flags.sql in phpMyAdmin to enable visibility toggles. Until then, all accessible projects remain visible in Sales Hub.
                </p>
                <div id="saleVisibilityControls" style="display: flex; flex-wrap: wrap; gap: 20px; align-items: center;">
                    <label style="display: flex; align-items: center; gap: 8px; color: var(--pm-text-main); font-weight: 600; cursor: pointer;">
                        <input type="checkbox" id="showForSaleToggle"> Show for sale (in-house Sales Hub)
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; color: var(--pm-text-main); font-weight: 600; cursor: pointer;">
                        <input type="checkbox" id="showForSaleExternalToggle"> Show for sale — external agents
                    </label>
                    <button type="button" class="btn-heavy btn-blue" onclick="saveSaleVisibility()"><i class="fas fa-save"></i> Save Visibility</button>
                </div>
            </div>

            <div class="tabs">
                <div class="tab active" onclick="switchTab('frameTab', this)"><i class="fas fa-table"></i> Live Frame Editor</div>
                <div class="tab" onclick="switchTab('mediaTab', this)"><i class="fas fa-images"></i> Media & Renders</div>
                <div class="tab" onclick="switchTab('floorTab', this)"><i class="fas fa-map"></i> Floor Plans</div>
                <div class="tab" onclick="switchTab('projectPlansTab', this)"><i class="fas fa-drafting-compass"></i> Project Plans</div>
            </div>

            <div id="loader" class="loader"><i class="fas fa-spinner fa-spin"></i> Loading Project Data...</div>

            <div id="frameTab" class="tab-content active">
                <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                    <button class="btn-heavy btn-gray" onclick="addNewRow()"><i class="fas fa-plus"></i> Add New Unit</button>
                    <button class="btn-heavy btn-green" onclick="saveFrame()"><i class="fas fa-save"></i> Save All Frame Changes</button>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="frame-table" id="frameTable">
                        <thead>
                            <tr>
                                <th style="min-width: 120px;">Unit Name</th>
                                <th style="width: 70px;">Block</th>
                                <th style="width: 70px;">Level</th>
                                <th style="width: 140px;">Property Type</th>
                                <th style="min-width: 160px;">Description</th>
                                <th style="width: 90px;">Int SQM</th>
                                <th style="width: 90px;">Ext SQM</th>
                                <th style="width: 120px;">Shell Price (€)</th>
                                <th style="width: 120px;">Finishes (€)</th>
                                <th style="width: 60px; text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="frameBody">
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="mediaTab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h4 style="margin: 0;">Sales Gallery Media</h4>
                    <button class="btn-heavy btn-blue" onclick="saveMediaOrder()"><i class="fas fa-sort-numeric-down"></i> Save Display Order</button>
                </div>
                <div class="media-grid" id="mediaGrid"></div>
            </div>

            <div id="floorTab" class="tab-content">
                <h4 style="margin: 0 0 20px 0;">Attached Floor Plans</h4>
                <div id="floorPlanList"></div>
            </div>

            <div id="projectPlansTab" class="tab-content">
                <h4 style="margin: 0 0 20px 0;">Full Project Plans Set</h4>
                <p style="color: var(--pm-text-muted); font-size: 0.85rem; margin: 0 0 15px 0;">Upload via <strong>Upload Media</strong> above → <strong>Project Plans — Full Set</strong>. External agents see these only (not floor-by-floor plans).</p>
                <div id="projectPlanList"></div>
            </div>
        </div>
    </div>
</div>

<div id="pm-toast-container"></div>

<div id="uploadFrameModal" class="pm-modal">
    <div class="pm-modal-content">
        <span class="pm-close" onclick="document.getElementById('uploadFrameModal').style.display='none'">&times;</span>
        <h4 style="margin: 0 0 20px 0;">Upload Project Frame (CSV)</h4>
        <form id="uploadFrameForm">
            <label class="pm-label">Select empty project</label>
            <input type="hidden" name="project_id" id="frameProjectId" value="">
            <div class="pm-project-picker" id="frameProjectPicker" style="max-height: 280px; overflow-y: auto; margin-bottom: 20px;">
                <?php if (empty($emptyFrameProjects)): ?>
                    <p style="color: var(--pm-text-muted); font-size: 0.85rem; margin: 0;">
                        No empty projects available. Frame upload is only for projects without units yet — ask an admin to create a project first.
                    </p>
                <?php else: ?>
                    <?php
                    $pickerCity = '';
                    foreach ($emptyFrameProjects as $row):
                        $city = trim($row['city'] ?? '') !== '' ? trim($row['city']) : 'Uncategorized';
                        if ($city !== $pickerCity):
                            if ($pickerCity !== '') echo '</div>';
                            $pickerCity = $city;
                            echo '<div class="pm-project-picker-city">' . htmlspecialchars($pickerCity, ENT_QUOTES, 'UTF-8') . '</div><div class="pm-project-picker-grid">';
                        endif;
                        $pid = (int)$row['id'];
                        $thumbUrl = $emptyFrameThumbUrls[$pid] ?? '';
                    ?>
                        <button type="button" class="pm-project-picker-card" data-project-id="<?= $pid ?>" onclick="selectFrameProject(this)">
                            <div class="pm-project-picker-thumb">
                                <?php if ($thumbUrl): ?>
                                    <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                <?php else: ?>
                                    <i class="fas fa-building" aria-hidden="true"></i>
                                <?php endif; ?>
                            </div>
                            <div class="pm-project-picker-name"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        </button>
                    <?php endforeach; ?>
                    <?php if ($pickerCity !== '') echo '</div>'; ?>
                <?php endif; ?>
            </div>
            <label class="pm-label">CSV File</label>
            <input type="file" name="frame_csv" accept=".csv" required style="width: 100%; background: var(--pm-bg-base); border: 1px solid var(--pm-border); padding: 10px; border-radius: 8px; color: #fff; margin-bottom: 20px;">
            <button type="submit" class="btn-heavy btn-blue">Upload & Import</button>
        </form>
    </div>
</div>

<div id="uploadMediaModal" class="pm-modal">
    <div class="pm-modal-content">
        <span class="pm-close" onclick="document.getElementById('uploadMediaModal').style.display='none'">&times;</span>
        <h4 style="margin: 0 0 20px 0;">Upload Project Media</h4>
        <form id="uploadMediaForm">
            <label class="pm-label">Select Project</label>
            <select class="pm-select" name="project_id" required>
                <option value="">-- Choose Project --</option>
                <?php
                $current_city = '';
                foreach ($projectsRaw as $row) {
                    $city = trim($row['city']) ? trim($row['city']) : 'Uncategorized';
                    if ($city !== $current_city) {
                        if ($current_city !== '') echo '</optgroup>';
                        echo '<optgroup label="' . htmlspecialchars($city, ENT_QUOTES, 'UTF-8') . '">';
                        $current_city = $city;
                    }
                    echo '<option value="' . (int)$row['id'] . '">' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                }
                if ($current_city !== '') echo '</optgroup>';
                ?>
            </select>
            <label class="pm-label">Media Type</label>
            <select class="pm-select" name="media_type" id="mediaTypeSelect" required onchange="toggleFloorInput()">
                <option value="Render (Image)">Render (Image)</option>
                <option value="Render (Video)">Render (Video)</option>
                <option value="Floor Plan">Floor Plan (PDF/Img)</option>
                <option value="Project Plans">Project Plans — Full Set (PDF/Img)</option>
                <option disabled>--- Pricelist Document Pages ---</option>
                <option value="Pricelist - Front Cover">Pricelist - Front Cover</option>
                <option value="Pricelist - Timeframes & Terms">Pricelist - Timeframes & Terms</option>
                <option value="Pricelist - Spec Sheet">Pricelist - Spec Sheet (Multi-page PDF supported)</option>
                <option value="Pricelist - Back Cover">Pricelist - Back Cover</option>
            </select>
            <div id="floorInputGroup" style="display:none;">
                <label class="pm-label">Floor Level (Matches CSV)</label>
                <input type="text" name="floor_level" placeholder="e.g. -1, 0, 1, 2" class="frame-input" style="margin-bottom: 15px;">
            </div>
            <label class="pm-label">Media Files</label>
            <div class="pm-drop-zone" id="drop-zone">
                <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: var(--pm-text-muted); margin-bottom: 10px;"></i>
                <div style="font-weight: bold; color: #fff;">Drag & Drop media here</div>
                <div style="font-size: 0.8rem; color: var(--pm-text-muted);">or click to browse</div>
                <input type="file" name="media_file[]" id="mediaFileInput" multiple required style="display:none;">
            </div>
            <div id="file-list" style="margin: 15px 0; font-size: 0.8rem; color: var(--pm-avail); max-height: 100px; overflow-y: auto;"></div>
            <button type="submit" class="btn-heavy btn-green">Upload to Cloudflare</button>
        </form>
    </div>
</div>

<div id="ignoredLedgerModal" class="pm-modal">
    <div class="pm-modal-content large" style="max-width: 800px; height: 80vh; display: flex; flex-direction: column;">
        <div style="text-align: right; margin-bottom: 10px;">
            <span class="pm-close" onclick="document.getElementById('ignoredLedgerModal').style.display='none'">&times;</span>
        </div>
        <div id="ignoredLedgerContent" style="flex: 1; overflow-y: auto; padding-right: 15px;"></div>
    </div>
</div>

<script src="js/sales_pm_tools.js"></script>
<script>
    // Enum mapping matching your database strictly
    const unitTypes = [
        { val: 'apartment', label: 'Apartment' },
        { val: 'penthouse', label: 'Penthouse' },
        { val: 'maisonette', label: 'Maisonette' },
        { val: 'house', label: 'House' },
        { val: 'villa', label: 'Villa' },
        { val: 'commercial', label: 'Commercial' },
        { val: 'garage', label: 'Garage' },
        { val: 'parking space', label: 'Parking Space' }
    ];

    let currentUnits = [];
    let currentMedia = [];
    let currentVisibility = { show_for_sale: 1, show_for_sale_external: 0 };

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function mediaDisplayFileName(filePath) {
        if (!filePath) return '';
        return filePath.split('?')[0].split('/').pop() || '';
    }

    function renderVisibilityPanel() {
        const migrationNote = document.getElementById('saleVisibilityMigrationNote');
        const controls = document.getElementById('saleVisibilityControls');
        const columnsReady = currentVisibility.columns_available !== false;

        if (migrationNote) migrationNote.style.display = columnsReady ? 'none' : 'block';
        if (controls) controls.style.display = columnsReady ? 'flex' : 'none';

        document.getElementById('showForSaleToggle').checked = !!currentVisibility.show_for_sale;
        document.getElementById('showForSaleExternalToggle').checked = !!currentVisibility.show_for_sale_external;
    }

    function saveSaleVisibility() {
        const pid = document.getElementById('projectSelect').value;
        if (!pid) return;

        const fd = new FormData();
        fd.append('action', 'update_sale_visibility');
        fd.append('project_id', pid);
        if (document.getElementById('showForSaleToggle').checked) fd.append('show_for_sale', '1');
        if (document.getElementById('showForSaleExternalToggle').checked) fd.append('show_for_sale_external', '1');

        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                currentVisibility = data.visibility || currentVisibility;
                alert('Sales visibility updated.');
            } else {
                alert(data.message || 'Could not update visibility.');
            }
        });
    }

    function switchTab(tabId, el) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        el.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    }

    function loadProjectData() {
        const pid = document.getElementById('projectSelect').value;
        if (!pid) { document.getElementById('workspace').style.display = 'none'; return; }

        document.getElementById('workspace').style.display = 'block';
        document.getElementById('loader').style.display = 'block';
        document.getElementById('frameTab').style.opacity = '0.3';
        document.getElementById('mediaTab').style.opacity = '0.3';

        const fd = new FormData();
        fd.append('action', 'load_project');
        fd.append('project_id', pid);

        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            currentUnits = data.units || [];
            currentMedia = data.media || [];
            currentVisibility = data.visibility || { show_for_sale: 1, show_for_sale_external: 0 };
            renderVisibilityPanel();
            renderFrameTable();
            renderMediaManagers();
            document.getElementById('loader').style.display = 'none';
            document.getElementById('frameTab').style.opacity = '1';
            document.getElementById('mediaTab').style.opacity = '1';
        });
    }

    function renderFrameTable() {
        const tbody = document.getElementById('frameBody');
        tbody.innerHTML = '';
        currentUnits.forEach((u, index) => { tbody.appendChild(createRow(u, index)); });
        if(currentUnits.length === 0) addNewRow();
    }

    function createRow(u, index) {
        const tr = document.createElement('tr');
        tr.setAttribute('data-id', u.id || 0);
        
        let typeOpts = unitTypes.map(t => `<option value="${t.val}" ${u.unit_type === t.val ? 'selected' : ''}>${t.label}</option>`).join('');

        tr.innerHTML = `
            <td><input type="text" class="frame-input inp-name" value="${u.unit_name || ''}" placeholder="Apt 1"></td>
            <td><input type="text" class="frame-input inp-block" value="${u.block || ''}" placeholder="A"></td>
            <td><input type="text" class="frame-input inp-level" value="${u.floor_level || ''}" placeholder="1"></td>
            <td><select class="frame-select inp-type">${typeOpts}</select></td>
            <td><input type="text" class="frame-input inp-desc" value="${u.description || ''}" placeholder="e.g. 1 BED & STUDY"></td>
            <td><input type="number" step="0.01" class="frame-input inp-int" value="${u.internal_sqm || 0}"></td>
            <td><input type="number" step="0.01" class="frame-input inp-ext" value="${u.external_sqm || 0}"></td>
            <td><input type="number" step="0.01" class="frame-input inp-shell" value="${u.shell_price || 0}"></td>
            <td><input type="number" step="0.01" class="frame-input inp-fin" value="${u.finishes_price || 0}"></td>
            <td style="text-align:center;">
                <button class="btn-heavy btn-red" style="padding: 6px 10px;" onclick="deleteUnit(${u.id || 0}, this)"><i class="fas fa-trash"></i></button>
            </td>
        `;
        return tr;
    }

    function addNewRow() { 
        document.getElementById('frameBody').appendChild(createRow({}, currentUnits.length)); 
    }

    function deleteUnit(id, btnEl) {
        if (!confirm("Are you sure you want to delete this unit? This will permanently remove it from the Sales Hub.")) return;
        if (id === 0) { btnEl.closest('tr').remove(); return; }

        const fd = new FormData(); 
        fd.append('action', 'delete_unit'); 
        fd.append('unit_id', id);
        
        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if(data.success) { 
                btnEl.closest('tr').remove(); 
            } else { 
                alert("Error deleting unit."); 
            }
        });
    }

    function saveFrame() {
        const pid = document.getElementById('projectSelect').value;
        const rows = document.querySelectorAll('#frameBody tr');
        let payload = [];

        rows.forEach(row => {
            payload.push({
                id: row.getAttribute('data-id'),
                unit_name: row.querySelector('.inp-name').value,
                block: row.querySelector('.inp-block').value,
                floor_level: row.querySelector('.inp-level').value,
                unit_type: row.querySelector('.inp-type').value,
                description: row.querySelector('.inp-desc').value,
                internal_sqm: row.querySelector('.inp-int').value,
                external_sqm: row.querySelector('.inp-ext').value,
                shell_price: row.querySelector('.inp-shell').value,
                finishes_price: row.querySelector('.inp-fin').value,
            });
        });

        const btn = event.target; 
        const ogText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; 
        btn.disabled = true;

        const fd = new FormData(); 
        fd.append('action', 'save_frame'); 
        fd.append('project_id', pid); 
        fd.append('units', JSON.stringify(payload));

        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.innerHTML = ogText; btn.disabled = false;
            if(data.success) { 
                alert("Frame updated successfully!"); 
                loadProjectData(); 
            } else { 
                alert("Error saving frame: " + data.message); 
            }
        });
    }

    function renderMediaManagers() {
        const mediaGrid = document.getElementById('mediaGrid');
        const floorList = document.getElementById('floorPlanList');
        const projectPlanList = document.getElementById('projectPlanList');
        
        mediaGrid.innerHTML = '';
        let floorHtml = '';
        let projectPlanHtml = '';
        let hasFloors = false;
        let hasProjectPlans = false;

        currentMedia.forEach(m => {
            if (m.sub_category === 'Project Plans') {
                hasProjectPlans = true;
                const safeTitle = escapeHtml(m.title || 'Project Plans Set');
                const cleanFileName = escapeHtml(mediaDisplayFileName(m.file_path));
                const safeFilePath = escapeHtml(m.file_path);
                projectPlanHtml += `
                    <div style="display:flex; justify-content:space-between; align-items:center; background:var(--pm-bg-base); padding:15px; border:1px solid var(--pm-border); border-radius:8px; margin-bottom:10px;">
                        <div>
                            <strong style="color: var(--pm-text-main);"><i class="fas fa-drafting-compass text-blue-500"></i> ${safeTitle}</strong>
                            <div style="font-size:0.8rem; color:var(--pm-text-muted); margin-top:4px;">File: ${cleanFileName}</div>
                        </div>
                        <div>
                            <a href="${safeFilePath}" target="_blank" rel="noopener noreferrer" class="btn-heavy btn-blue" style="padding:6px 12px; font-size:0.85rem;"><i class="fas fa-eye"></i> View</a>
                            <button class="btn-heavy btn-red" style="padding:6px 12px; font-size:0.85rem;" onclick="deleteMedia(${parseInt(m.id, 10)})"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>`;
            } else if (m.sub_category.includes('Floor Plan')) {
                hasFloors = true;
                const safeLevel = escapeHtml((m.title || '').replace('Floor Plan - ', ''));
                const cleanFileName = escapeHtml(mediaDisplayFileName(m.file_path));
                const safeFilePath = escapeHtml(m.file_path);
                floorHtml += `
                    <div style="display:flex; justify-content:space-between; align-items:center; background:var(--pm-bg-base); padding:15px; border:1px solid var(--pm-border); border-radius:8px; margin-bottom:10px;">
                        <div>
                            <strong style="color: var(--pm-text-main);"><i class="fas fa-layer-group text-blue-500"></i> Level: ${safeLevel}</strong>
                            <div style="font-size:0.8rem; color:var(--pm-text-muted); margin-top:4px;">File: ${cleanFileName}</div>
                        </div>
                        <div>
                            <a href="${safeFilePath}" target="_blank" rel="noopener noreferrer" class="btn-heavy btn-blue" style="padding:6px 12px; font-size:0.85rem;"><i class="fas fa-eye"></i> View</a>
                            <button class="btn-heavy btn-red" style="padding:6px 12px; font-size:0.85rem;" onclick="deleteMedia(${parseInt(m.id, 10)})"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>`;
            } else if (m.sub_category.includes('Render') || m.sub_category.includes('Video')) {
                const safeCategory = escapeHtml(m.sub_category);
                const safeFilePath = escapeHtml(m.file_path);
                const mediaEl = m.sub_category.includes('Video')
                    ? `<video src="${safeFilePath}" controls></video>`
                    : `<img src="${safeFilePath}" alt="">`;
                mediaGrid.innerHTML += `
                    <div class="media-card" data-id="${parseInt(m.id, 10)}">
                        ${mediaEl}
                        <div class="media-title">${safeCategory}</div>
                        <label style="font-size:0.7rem; color:var(--pm-text-muted);">Display Order:</label><br>
                        <input type="number" class="media-order inp-sort" value="${parseInt(m.sort_order, 10) || 0}">
                        <br>
                        <button class="btn-heavy btn-red" style="width:100%; padding:6px; font-size:0.8rem;" onclick="deleteMedia(${parseInt(m.id, 10)})"><i class="fas fa-trash"></i> Delete</button>
                    </div>`;
            }
        });

        if (!hasFloors) {
            floorHtml = '<div style="padding:20px; text-align:center; color:var(--pm-text-muted); background:var(--pm-bg-base); border-radius:8px; border:1px solid var(--pm-border);">No floor plans uploaded for this project yet. Use <strong>Upload Media</strong> above.</div>';
        }
        floorList.innerHTML = floorHtml;

        if (!hasProjectPlans) {
            projectPlanHtml = '<div style="padding:20px; text-align:center; color:var(--pm-text-muted); background:var(--pm-bg-base); border-radius:8px; border:1px solid var(--pm-border);">No full project plans uploaded yet. Use <strong>Upload Media</strong> → Project Plans — Full Set.</div>';
        }
        projectPlanList.innerHTML = projectPlanHtml;
        
        if (mediaGrid.innerHTML === '') {
            mediaGrid.innerHTML = '<div style="grid-column: 1/-1; padding:20px; text-align:center; color:var(--pm-text-muted); background:var(--pm-bg-base); border-radius:8px; border:1px solid var(--pm-border);">No visual media uploaded yet. Use <strong>Upload Media</strong> above to add renders and videos.</div>';
        }
    }

    function deleteMedia(id) {
        if(!confirm("Are you sure you want to permanently delete this file?")) return;
        const fd = new FormData(); 
        fd.append('action', 'delete_media'); 
        fd.append('media_id', id);
        
        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { 
            if(data.success) loadProjectData(); 
        });
    }

    function saveMediaOrder() {
        let sortData = [];
        document.querySelectorAll('.media-card').forEach(card => { 
            sortData.push({ 
                id: card.getAttribute('data-id'), 
                order: card.querySelector('.inp-sort').value 
            }); 
        });

        const btn = event.target; 
        const og = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; 
        btn.disabled = true;

        const fd = new FormData(); 
        fd.append('action', 'update_media_order'); 
        fd.append('sort_data', JSON.stringify(sortData));
        
        fetch('sales_project_manager.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.innerHTML = og; btn.disabled = false;
            if(data.success) { 
                alert("Display order saved successfully!"); 
                loadProjectData(); 
            }
        });
    }

    window.loadProjectData = loadProjectData;
</script>

<?php require_once 'footer.php'; ?>
