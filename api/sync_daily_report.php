<?php
require_once '../config.php';
require_once '../session-check.php';

// Only allow Managers/Admins to run the sync
if (!in_array($_SESSION['role'], ['admin', 'sales_manager', 'director', 'system_manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ---------------------------------------------------------
// ACTION: SAVE NEW TRANSLATION (Called from the popup UI)
// ---------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'save_translation') {
    $csvName = trim($_POST['csv_name'] ?? '');
    $dbUnitId = isset($_POST['unit_id']) ? intval($_POST['unit_id']) : null;

    if ($csvName && $dbUnitId !== null) {
        $stmt = $pdo->prepare("INSERT INTO sync_translations (csv_name, db_unit_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE db_unit_id = ?");
        $stmt->execute([$csvName, $dbUnitId, $dbUnitId]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
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

$file = $_FILES['sync_csv']['tmp_name'];
$handle = fopen($file, "r");
if (!$handle) {
    echo json_encode(['success' => false, 'message' => 'Could not read the CSV file.']);
    exit;
}

// 1. Fetch all DB Units for Smart Matching & UI Dropdowns
$stmt = $pdo->query("SELECT sp.id, sp.unit_name, p.name as project_name FROM sales_properties sp JOIN projects p ON sp.project_id = p.id ORDER BY p.name ASC, sp.unit_name ASC");
$dbUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Saved Translations from Database
$stmtTrans = $pdo->query("SELECT csv_name, db_unit_id FROM sync_translations");
$savedTranslations = [];
while ($row = $stmtTrans->fetch(PDO::FETCH_ASSOC)) {
    $savedTranslations[strtolower($row['csv_name'])] = $row['db_unit_id'];
}

$ignoreWords = ['apt', 'apartment', 'blk', 'block', 'ph', 'penthouse', 'mais', 'maisonette', 'garage', 'car', 'space', 'house', 'pt', 'level', 'lv', 'cs', 'gr', 'residences'];

$updatedCount = 0;
$notFound = [];

$colUnit = -1;
$colStatus = -1;
$colPrice = -1;
$colFinishes = -1; // NEW: Finishes Column
$isHeaderFound = false;

while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
    
    // Find Columns
    if (!$isHeaderFound) {
        foreach ($data as $index => $val) {
            $valStr = strtolower(trim($val));
            if (strpos($valStr, 'apartment no') !== false || strpos($valStr, 'project') !== false) $colUnit = $index;
            if ($valStr === 'status') $colStatus = $index;
            
            // Differentiate between "Stock Value" and "Stock C/P Value"
            if (strpos($valStr, 'stock value') !== false && strpos($valStr, 'c/p') === false) $colPrice = $index;
            if (strpos($valStr, 'stock c/p value') !== false || strpos($valStr, 'c/p value') !== false) $colFinishes = $index;
        }
        if ($colUnit !== -1 && $colStatus !== -1) $isHeaderFound = true;
        continue;
    }

    if (!isset($data[$colUnit]) || !isset($data[$colStatus])) continue;
    
    $csvUnitStringRaw = trim($data[$colUnit]);
    $csvStatus = trim($data[$colStatus]);
    
    // Extract Prices
    $csvPriceRaw = isset($data[$colPrice]) ? trim($data[$colPrice]) : '';
    $csvFinishesRaw = isset($data[$colFinishes]) ? trim($data[$colFinishes]) : '';

    if (empty($csvUnitStringRaw) || empty($csvStatus)) continue;

    $price = floatval(preg_replace('/[^0-9.]/', '', $csvPriceRaw));
    $finishesPrice = floatval(preg_replace('/[^0-9.]/', '', $csvFinishesRaw)); // NEW
    
    $dbStatus = 'Available';
    $csvStatusLower = strtolower($csvStatus);
    
    if (strpos($csvStatusLower, 'stock') !== false) $dbStatus = 'Available';
    elseif (strpos($csvStatusLower, 'pos') !== false) $dbStatus = 'Sold';
    elseif (strpos($csvStatusLower, 'contract') !== false) $dbStatus = 'Sold';
    elseif (strpos($csvStatusLower, 'progress') !== false) $dbStatus = 'Proceeding';
    elseif (strpos($csvStatusLower, 'hold') !== false) $dbStatus = 'On Hold';

    $searchString = strtolower($csvUnitStringRaw);
    $matchedId = null;

    // --- MATCHER LOGIC ---
    if (isset($savedTranslations[$searchString])) {
        $matchedId = $savedTranslations[$searchString];
    } else {
        $projectAliases = [
            'harbeia' => 'harbea',
            'tal-gruwa' => 'gruwa'
        ];
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
                    if (strpos($searchString, $token) === false) {
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

    // --- EXECUTE UPDATE ---
    if ($matchedId && $matchedId > 0) {
        if ($price > 0 || $finishesPrice > 0) {
            // Update Status, Shell Price, AND Finishes Price
            $updateStmt = $pdo->prepare("UPDATE sales_properties SET status = ?, shell_price = ?, finishes_price = ? WHERE id = ?");
            $updateStmt->execute([$dbStatus, $price, $finishesPrice, $matchedId]);
        } else {
            // Just update status if no prices provided
            $updateStmt = $pdo->prepare("UPDATE sales_properties SET status = ? WHERE id = ?");
            $updateStmt->execute([$dbStatus, $matchedId]);
        }
        $updatedCount++;
    } elseif ($matchedId == -1) {
        // EXPLICITLY IGNORED: Skip entirely
        continue;
    } else {
        $notFound[] = [
            'csv_name' => $csvUnitStringRaw,
            'status' => $csvStatus,
            'price' => $price
        ];
    }
}
fclose($handle);

echo json_encode([
    'success' => true,
    'message' => "Successfully updated {$updatedCount} units.",
    'not_found' => $notFound,
    'all_db_units' => $dbUnits 
]);
?>
