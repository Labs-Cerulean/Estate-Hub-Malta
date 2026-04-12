<?php
require_once '../config.php';
require_once '../session-check.php';

// Only allow Managers/Admins to run the sync
if (!in_array($_SESSION['role'], ['admin', 'sales_manager', 'director', 'system_manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

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

// Fetch all existing units from the database to build our Smart Matching index
$stmt = $pdo->query("SELECT sp.id, sp.unit_name, p.name as project_name FROM sales_properties sp JOIN projects p ON sp.project_id = p.id");
$dbUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Words to ignore when matching to ensure high accuracy (e.g., matching "Apt A01" to "Apartment A01")
$ignoreWords = ['apt', 'apartment', 'blk', 'block', 'ph', 'penthouse', 'mais', 'maisonette', 'garage', 'car', 'space', 'house', 'pt', 'level', 'lv', 'cs', 'gr'];

$updatedCount = 0;
$notFound = [];

$colUnit = -1;
$colStatus = -1;
$colPrice = -1;
$isHeaderFound = false;

// Process the CSV
while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
    
    // 1. Dynamically find the columns (allows for empty rows at the top of the CSV)
    if (!$isHeaderFound) {
        foreach ($data as $index => $val) {
            $valStr = strtolower(trim($val));
            if (strpos($valStr, 'apartment no') !== false || strpos($valStr, 'project') !== false) $colUnit = $index;
            if ($valStr === 'status') $colStatus = $index;
            if (strpos($valStr, 'stock value') !== false || strpos($valStr, 'price') !== false) $colPrice = $index;
        }
        if ($colUnit !== -1 && $colStatus !== -1) $isHeaderFound = true;
        continue;
    }

    // 2. Extract Row Data
    if (!isset($data[$colUnit]) || !isset($data[$colStatus])) continue;
    
    $csvUnitString = trim($data[$colUnit]);
    $csvStatus = trim($data[$colStatus]);
    $csvPriceRaw = isset($data[$colPrice]) ? trim($data[$colPrice]) : '';

    if (empty($csvUnitString) || empty($csvStatus)) continue;

    // Clean price formatting
    $price = floatval(preg_replace('/[^0-9.]/', '', $csvPriceRaw));

    // Map 3rd-Party Statuses to Internal Statuses
    $dbStatus = 'Available';
    $csvStatusLower = strtolower($csvStatus);
    
    if (strpos($csvStatusLower, 'stock') !== false) $dbStatus = 'Available';
    elseif (strpos($csvStatusLower, 'pos') !== false) $dbStatus = 'Sold';
    elseif (strpos($csvStatusLower, 'contract') !== false) $dbStatus = 'Sold';
    elseif (strpos($csvStatusLower, 'progress') !== false) $dbStatus = 'Proceeding';
    elseif (strpos($csvStatusLower, 'hold') !== false) $dbStatus = 'On Hold';

    // 3. Smart Token Matching Engine
    $matchedId = null;
    $searchString = strtolower($csvUnitString);

    foreach ($dbUnits as $dbU) {
        $projParts = explode(' ', $dbU['project_name']);
        $searchProj = strtolower($projParts[0]); // e.g., "Avanti"

        // First, check if the CSV row belongs to this project
        if (strpos($searchString, $searchProj) !== false) {
            
            // Extract the core unit ID (e.g., "M01 - Blk A (Avanti)" -> "M01", "A")
            $coreUnitName = preg_replace('/\(.*?\)/', '', $dbU['unit_name']); 
            preg_match_all('/[a-zA-Z0-9]+/', $coreUnitName, $matches);
            
            $unitTokens = [];
            foreach ($matches[0] as $t) {
                if (!in_array(strtolower($t), $ignoreWords)) $unitTokens[] = strtolower($t);
            }

            // Check if all core identifying tokens exist in the CSV string
            $allTokensMatch = true;
            foreach ($unitTokens as $token) {
                if (strpos($searchString, $token) === false) {
                    $allTokensMatch = false;
                    break;
                }
            }

            if ($allTokensMatch && count($unitTokens) > 0) {
                $matchedId = $dbU['id'];
                break; // Match found!
            }
        }
    }

    // 4. Execute Update
    if ($matchedId) {
        if ($price > 0) {
            // Overwrite Shell Price with Stock Value, and zero out finishes so the Total matches the CSV
            $updateStmt = $pdo->prepare("UPDATE sales_properties SET status = ?, shell_price = ?, finishes_price = 0 WHERE id = ?");
            $updateStmt->execute([$dbStatus, $price, $matchedId]);
        } else {
            // Just update the status if no price is provided
            $updateStmt = $pdo->prepare("UPDATE sales_properties SET status = ? WHERE id = ?");
            $updateStmt->execute([$dbStatus, $matchedId]);
        }
        $updatedCount++;
    } else {
        $notFound[] = $csvUnitString;
    }
}
fclose($handle);

echo json_encode([
    'success' => true,
    'message' => "Sync complete! Successfully updated {$updatedCount} units.",
    'not_found' => $notFound
]);
?>
