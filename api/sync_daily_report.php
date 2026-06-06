<?php
require_once '../config.php';
require_once '../session-check.php';

// Only allow Managers/Admins to run the sync
if (!in_array($_SESSION['role'], ['admin', 'sales_manager', 'director', 'system_manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ---------------------------------------------------------
// ACTION: RESOLVE PRICE CONFLICT (NEW FOR ITEM 5)
// ---------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'resolve_price_conflict') {
    try {
        $unitId = (int)$_POST['unit_id'];
        $shell = (float)$_POST['shell_price'];
        $finishes = (float)$_POST['finishes_price'];
        
        $stmtGetOld = $pdo->prepare("SELECT shell_price, finishes_price FROM sales_properties WHERE id = ?");
        $stmtGetOld->execute([$unitId]);
        $oldUnit = $stmtGetOld->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("UPDATE sales_properties SET shell_price = ?, finishes_price = ? WHERE id = ?");
        $stmt->execute([$shell, $finishes, $unitId]);
        
        $log = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
        $log->execute([
            $unitId, 
            $_SESSION['user_id'], 
            'CSV Sync Price Resolution', 
            "Shell: {$oldUnit['shell_price']}, Fin: {$oldUnit['finishes_price']}", 
            "Shell: {$shell}, Fin: {$finishes}", 
            "Manager resolved a price conflict during CSV Sync."
        ]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------
// ACTION: SAVE NEW TRANSLATION
// ---------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'save_translation') {
    try {
        $csvName = trim($_POST['csv_name'] ?? '');
        $dbUnitId = isset($_POST['unit_id']) ? intval($_POST['unit_id']) : null;

        if ($csvName && $dbUnitId !== null) {
            $stmt = $pdo->prepare("INSERT INTO sync_translations (csv_name, db_unit_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE db_unit_id = ?");
            $stmt->execute([$csvName, $dbUnitId, $dbUnitId]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid data']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------
// ACTION: PROCESS CSV UPLOAD
// ---------------------------------------------------------
if (!isset($_FILES['sync_csv']) || $_FILES['sync_csv']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
    exit;
}

try {
    $file = $_FILES['sync_csv']['tmp_name'];
    $handle = fopen($file, "r");
    if (!$handle) throw new Exception('Could not read the CSV file.');

    $stmt = $pdo->query("SELECT sp.id, sp.unit_name, sp.status, sp.shell_price, sp.finishes_price, p.name as project_name FROM sales_properties sp JOIN projects p ON sp.project_id = p.id ORDER BY p.name ASC, sp.unit_name ASC");
    $dbUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dbUnitsById = [];
    foreach ($dbUnits as $u) {
        $dbUnitsById[$u['id']] = $u;
    }

    $stmtTrans = $pdo->query("SELECT csv_name, db_unit_id FROM sync_translations");
    $savedTranslations = [];
    while ($row = $stmtTrans->fetch(PDO::FETCH_ASSOC)) {
        $savedTranslations[strtolower($row['csv_name'])] = $row['db_unit_id'];
    }

    $ignoreWords = ['apt', 'apartment', 'blk', 'block', 'ph', 'penthouse', 'mais', 'maisonette', 'garage', 'car', 'space', 'house', 'pt', 'level', 'lv', 'cs', 'gr', 'residences'];

    $updatedCount = 0;
    $notFound = [];
    $priceConflicts = []; // NEW FOR ITEM 5
    $colUnit = -1; $colStatus = -1; $colPrice = -1; $colFinishes = -1; 
    $isHeaderFound = false;

    $pdo->beginTransaction();
    
    $stmtLog = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
    $userId = $_SESSION['user_id'];

    while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
        if (!$isHeaderFound) {
            foreach ($data as $index => $val) {
                $valStr = strtolower(trim($val));
                if (strpos($valStr, 'apartment no') !== false || strpos($valStr, 'project') !== false) $colUnit = $index;
                if ($valStr === 'status') $colStatus = $index;
                if (strpos($valStr, 'stock value') !== false && strpos($valStr, 'c/p') === false) $colPrice = $index;
                if (strpos($valStr, 'stock c/p value') !== false || strpos($valStr, 'c/p value') !== false) $colFinishes = $index;
            }
            if ($colUnit !== -1 && $colStatus !== -1) $isHeaderFound = true;
            continue;
        }

        if (!isset($data[$colUnit]) || !isset($data[$colStatus])) continue;
        
        $csvUnitStringRaw = trim($data[$colUnit]);
        $csvStatus = trim($data[$colStatus]);
        $csvPriceRaw = isset($data[$colPrice]) ? trim($data[$colPrice]) : '';
        $csvFinishesRaw = isset($data[$colFinishes]) ? trim($data[$colFinishes]) : '';

        if (empty($csvUnitStringRaw) || empty($csvStatus)) continue;

        $price = floatval(preg_replace('/[^0-9.]/', '', $csvPriceRaw));
        $finishesPrice = floatval(preg_replace('/[^0-9.]/', '', $csvFinishesRaw)); 
        
        $dbStatus = 'Available';
        $csvStatusLower = strtolower($csvStatus);
        $soldStatuses = ['new', 'in review', 'pos', 'in progress', 'deal to pos', 'signed deed'];
        if (in_array($csvStatusLower, $soldStatuses) || strpos($csvStatusLower, 'pos') !== false || strpos($csvStatusLower, 'contract') !== false) {
            $dbStatus = 'Sold';
        } elseif (strpos($csvStatusLower, 'stock') !== false) {
            $dbStatus = 'Available';
        }

        $searchString = strtolower($csvUnitStringRaw);
        $matchedId = null;

        if (isset($savedTranslations[$searchString])) {
            $matchedId = $savedTranslations[$searchString];
        } else {
            $projectAliases = ['harbeia' => 'harbea', 'tal-gruwa' => 'gruwa'];
            foreach ($projectAliases as $wrong => $right) {
                if (strpos($searchString, $wrong) !== false) {
                    $searchString = str_replace($wrong, $right, $searchString);
                }
            }

            preg_match_all('/[a-zA-Z0-9]+/', $searchString, $csvMatches);
            $csvTokens = $csvMatches[0];

            foreach ($dbUnits as $dbU) {
                $dbProjNameLower = strtolower($dbU['project_name']);
                $projParts = explode(' ', $dbProjNameLower);
                $searchProj = $projParts[0]; 

                if (in_array($searchProj, $csvTokens) || strpos($searchString, $searchProj) !== false) {
                    $coreUnitName = preg_replace('/\(.*?\)/', '', strtolower($dbU['unit_name'])); 
                    preg_match_all('/[a-zA-Z0-9]+/', $coreUnitName, $matches);
                    
                    $unitTokens = [];
                    foreach ($matches[0] as $t) {
                        if (!in_array($t, $ignoreWords)) $unitTokens[] = $t;
                    }

                    $allTokensMatch = true;
                    foreach ($unitTokens as $token) {
                        if (!in_array($token, $csvTokens)) { 
                            $allTokensMatch = false; 
                            break; 
                        }
                    }

                    if ($allTokensMatch && count($unitTokens) > 0) { 
                        $matchedId = $dbU['id']; 
                        break; 
                    }
                }
            }
        }

        // --- EXECUTION LAYER ---
        if ($matchedId && $matchedId > 0) {
            $oldUnit = $dbUnitsById[$matchedId];
            $currentDbStatus = $oldUnit['status'];

            if ($currentDbStatus === 'Resale') continue; 

            $activeAgentStatuses = ['On Hold', 'Proceeding', 'Proceeding Pending Approval', 'Sold Pending Approval', 'POS Pending Approval', 'Contract Pending Approval'];
            if (in_array($currentDbStatus, $activeAgentStatuses) && $dbStatus === 'Available') {
                $dbStatus = $currentDbStatus; 
            }

            // --- ITEM 5: PRICE MISMATCH DETECTION ---
            $hasPriceConflict = false;
            $dbShell = (float)$oldUnit['shell_price'];
            $dbFin = (float)$oldUnit['finishes_price'];
            
            // Only check if CSV actually provided a price (>0) and it's different from the DB
            if (($price > 0 && $price !== $dbShell) || ($finishesPrice > 0 && $finishesPrice !== $dbFin)) {
                $hasPriceConflict = true;
                $priceConflicts[] = [
                    'id' => $matchedId,
                    'project_name' => $oldUnit['project_name'],
                    'unit_name' => $oldUnit['unit_name'],
                    'db_shell' => $dbShell,
                    'db_fin' => $dbFin,
                    'csv_shell' => $price > 0 ? $price : $dbShell,
                    'csv_fin' => $finishesPrice > 0 ? $finishesPrice : $dbFin
                ];
            }

            // Execute Status change ONLY (Prices are skipped if there's a conflict)
            if ($oldUnit['status'] !== $dbStatus) {
                $updateStmt = $pdo->prepare("UPDATE sales_properties SET status = ? WHERE id = ?");
                $updateStmt->execute([$dbStatus, $matchedId]);
                
                $stmtLog->execute([
                    $matchedId, $userId, 'CSV Sync Update', $oldUnit['status'], $dbStatus, "Daily CSV Sync: Status updated"
                ]);
                $updatedCount++;
            }
            
            // If there's no conflict, but prices were updated cleanly (this catches rare cases, but mostly handled by the prompt now)
            if (!$hasPriceConflict && ($price > 0 || $finishesPrice > 0) && ($price !== $dbShell || $finishesPrice !== $dbFin)) {
                $updateStmt = $pdo->prepare("UPDATE sales_properties SET shell_price = ?, finishes_price = ? WHERE id = ?");
                $updateStmt->execute([$price, $finishesPrice, $matchedId]);
            }

        } elseif ($matchedId != -1) {
            $notFound[] = ['csv_name' => $csvUnitStringRaw, 'status' => $csvStatus, 'price' => $price];
        }
    }
    
    $pdo->commit();
    fclose($handle);

    echo json_encode([
        'success' => true,
        'message' => "Successfully updated {$updatedCount} statuses.",
        'not_found' => $notFound,
        'price_conflicts' => $priceConflicts, // Return conflicts to the frontend
        'all_db_units' => $dbUnits 
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => "Database Error: " . $e->getMessage()]);
}
?>
