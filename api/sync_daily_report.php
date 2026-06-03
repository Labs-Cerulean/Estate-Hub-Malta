<?php
require_once '../config.php';
require_once '../session-check.php';

// Only allow Managers/Admins to run the sync
if (!in_array($_SESSION['role'], ['admin', 'sales_manager', 'director', 'system_manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
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

    $stmt = $pdo->query("SELECT sp.id, sp.unit_name, sp.status, p.name as project_name FROM sales_properties sp JOIN projects p ON sp.project_id = p.id ORDER BY p.name ASC, sp.unit_name ASC");
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
    $colUnit = -1; $colStatus = -1; $colPrice = -1; $colFinishes = -1; 
    $isHeaderFound = false;

    $pdo->beginTransaction();

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
        
        // --- NEW STATUS MAPPING LOGIC ---
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
                $searchString = str_replace($wrong, $right, $searchString);
            }

            foreach ($dbUnits as $dbU) {
                $dbProjNameLower = strtolower($dbU['project_name']);
                $projParts = explode(' ', $dbProjNameLower);
                $searchProj = $projParts[0]; 

                if (strpos($searchString, $searchProj) !== false) {
                    $coreUnitName = preg_replace('/\(.*?\)/', '', $dbU['unit_name']); 
                    preg_match_all('/[a-zA-Z0-9]+/', $coreUnitName, $matches);
                    
                    $unitTokens = [];
                    foreach ($matches[0] as $t) {
                        if (!in_array(strtolower($t), $ignoreWords)) $unitTokens[] = strtolower($t);
                    }

                    $allTokensMatch = true;
                    foreach ($unitTokens as $token) {
                        if (strpos($searchString, $token) === false) { $allTokensMatch = false; break; }
                    }

                    if ($allTokensMatch && count($unitTokens) > 0) { $matchedId = $dbU['id']; break; }
                }
            }
        }

        // --- EXECUTION WITH IMMUNITY LAYER ---
        if ($matchedId && $matchedId > 0) {
            
            // 1. Identify current DB status using the O(1) lookup table
            $currentDbStatus = isset($dbUnitsById[$matchedId]) ? $dbUnitsById[$matchedId]['status'] : '';

            // 2. Absolute Immunity: Never let internal accounting overwrite a 3rd Party Resale
            if ($currentDbStatus === 'Resale') {
                continue; 
            }

            // 3. Active Agent Protection: 
            // If the CSV says "Stock" (Available) but the DB is currently "On Hold", we protect the hold.
            // If the CSV says "Sold" (New, POS, etc.), it WILL overwrite the hold.
            $activeAgentStatuses = ['On Hold', 'Proceeding', 'Proceeding Pending Approval', 'Sold Pending Approval', 'POS Pending Approval', 'Contract Pending Approval'];
            if (in_array($currentDbStatus, $activeAgentStatuses) && $dbStatus === 'Available') {
                $dbStatus = $currentDbStatus; 
            }

            // 4. Safe Execution
            if ($price > 0 || $finishesPrice > 0) {
                $updateStmt = $pdo->prepare("UPDATE sales_properties SET status = ?, shell_price = ?, finishes_price = ? WHERE id = ?");
                $updateStmt->execute([$dbStatus, $price, $finishesPrice, $matchedId]);
            } else {
                $updateStmt = $pdo->prepare("UPDATE sales_properties SET status = ? WHERE id = ?");
                $updateStmt->execute([$dbStatus, $matchedId]);
            }
            $updatedCount++;
        } elseif ($matchedId != -1) {
            $notFound[] = ['csv_name' => $csvUnitStringRaw, 'status' => $csvStatus, 'price' => $price];
        }
    }
    
    $pdo->commit();
    fclose($handle);

    echo json_encode([
        'success' => true,
        'message' => "Successfully updated {$updatedCount} units.",
        'not_found' => $notFound,
        'all_db_units' => $dbUnits 
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => "Database Error: " . $e->getMessage()]);
}
?>
