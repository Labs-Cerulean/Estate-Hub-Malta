<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config.php';
require_once '../session-check.php';

if (!in_array($_SESSION['role'], ['admin', 'sales_manager', 'director', 'system_manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$action = $_POST['action'] ?? 'analyze';

// =========================================================================
// ACTION 1: COMMIT THE APPROVED MATRIX REPORT
// =========================================================================
if ($action === 'commit') {
    try {
        $payload = json_decode($_POST['payload'], true);
        $pdo->beginTransaction();
        
        $stmtLog = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
        
        if (!empty($payload['translations'])) {
            $stmtTrans = $pdo->prepare("INSERT INTO sync_translations (csv_name, db_unit_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE db_unit_id = ?");
            foreach ($payload['translations'] as $t) {
                $stmtTrans->execute([$t['csv_name'], $t['db_unit_id'], $t['db_unit_id']]);
            }
        }
        
        if (!empty($payload['prices'])) {
            $stmtPrice = $pdo->prepare("UPDATE sales_properties SET shell_price = ?, finishes_price = ? WHERE id = ?");
            $stmtGetOldPrices = $pdo->prepare("SELECT shell_price, finishes_price FROM sales_properties WHERE id = ?");
            
            foreach ($payload['prices'] as $p) {
                $stmtGetOldPrices->execute([$p['id']]);
                $oldPriceData = $stmtGetOldPrices->fetch(PDO::FETCH_ASSOC);
                $stmtPrice->execute([$p['shell'], $p['finishes'], $p['id']]);
                
                $justification = "Sync Matrix: Shell €{$oldPriceData['shell_price']} -> €{$p['shell']} | Fin: €{$oldPriceData['finishes_price']} -> €{$p['finishes']}";
                $stmtLog->execute([$p['id'], $_SESSION['user_id'], 'CSV Sync Price Update', 'Price Override', 'Price Override', substr($justification, 0, 255)]);
            }
        }
        
        if (!empty($payload['statuses'])) {
            $stmtClearAgent = $pdo->prepare("UPDATE sales_properties SET status = ?, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?");
            $stmtKeepAgent = $pdo->prepare("UPDATE sales_properties SET status = ? WHERE id = ?");
            
            foreach ($payload['statuses'] as $s) {
                if (in_array($s['new_status'], ['Available', 'Sold', 'Sold - POS', 'Sold - Contract', 'Resale'])) {
                    $stmtClearAgent->execute([$s['new_status'], $s['id']]);
                } else {
                    $stmtKeepAgent->execute([$s['new_status'], $s['id']]);
                }
                $stmtLog->execute([$s['id'], $_SESSION['user_id'], 'CSV Sync Status Update', $s['old_status'], $s['new_status'], "Updated via Sync Matrix Approval"]);
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => "Database Error: " . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ACTION 2: DRY RUN / ANALYZE CSV (Default)
// =========================================================================
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
    foreach ($dbUnits as $u) { $dbUnitsById[$u['id']] = $u; }

    $stmtTrans = $pdo->query("SELECT csv_name, db_unit_id FROM sync_translations");
    $savedTranslations = [];
    while ($row = $stmtTrans->fetch(PDO::FETCH_ASSOC)) {
        $savedTranslations[strtolower($row['csv_name'])] = $row['db_unit_id'];
    }

    $ignoreWords = ['apt', 'apartment', 'blk', 'block', 'ph', 'penthouse', 'mais', 'maisonette', 'garage', 'car', 'space', 'house', 'pt', 'level', 'lv', 'cs', 'gr', 'residences'];

    // --- HIGH PERFORMANCE PRE-PROCESSING (Prevents Server Crash) ---
    $processedDbUnits = [];
    foreach ($dbUnits as $dbU) {
        $dbProjNameLower = strtolower($dbU['project_name']);
        $projParts = explode(' ', $dbProjNameLower);
        $coreUnitName = preg_replace('/\(.*?\)/', '', strtolower($dbU['unit_name'])); 
        preg_match_all('/[a-zA-Z0-9]+/', $coreUnitName, $matches);
        
        $unitTokens = [];
        foreach ($matches[0] as $t) { 
            if (!in_array($t, $ignoreWords)) $unitTokens[] = $t; 
        }
        
        $processedDbUnits[] = [
            'id' => $dbU['id'],
            'projFirstWord' => $projParts[0] ?? '',
            'unitTokens' => $unitTokens
        ];
    }

    $scannedCount = 0; $matchedCount = 0;
    $status_changes = []; $price_conflicts = []; $not_found = [];
    $colUnit = -1; $colStatus = -1; $colPrice = -1; $colFinishes = -1; 
    $isHeaderFound = false;

    while (($raw_data = fgetcsv($handle, 10000, ",")) !== FALSE) {
        $data = [];
        foreach ($raw_data as $cell) {
            $data[] = function_exists('mb_convert_encoding') 
                ? mb_convert_encoding($cell, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252') 
                : $cell;
        }

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
        if (empty($csvUnitStringRaw) || empty($csvStatus)) continue;
        
        $scannedCount++;
        $csvPriceRaw = isset($data[$colPrice]) ? trim($data[$colPrice]) : '';
        $csvFinishesRaw = isset($data[$colFinishes]) ? trim($data[$colFinishes]) : '';

        $price = floatval(preg_replace('/[^0-9.]/', '', $csvPriceRaw));
        $finishesPrice = floatval(preg_replace('/[^0-9.]/', '', $csvFinishesRaw)); 
        
        // --- MATCHING ENGINE (Now blazing fast) ---
        $searchString = strtolower($csvUnitStringRaw);
        $matchedId = null;

        if (isset($savedTranslations[$searchString])) {
            $matchedId = $savedTranslations[$searchString];
        } else {
            $projectAliases = ['harbeia' => 'harbea', 'tal-gruwa' => 'gruwa'];
            foreach ($projectAliases as $wrong => $right) {
                if (strpos($searchString, $wrong) !== false) $searchString = str_replace($wrong, $right, $searchString);
            }
            preg_match_all('/[a-zA-Z0-9]+/', $searchString, $csvMatches);
            $csvTokens = $csvMatches[0];

            foreach ($processedDbUnits as $pdbU) {
                if (in_array($pdbU['projFirstWord'], $csvTokens) || strpos($searchString, $pdbU['projFirstWord']) !== false) {
                    $allTokensMatch = true;
                    foreach ($pdbU['unitTokens'] as $token) {
                        if (!in_array($token, $csvTokens)) { $allTokensMatch = false; break; }
                    }
                    if ($allTokensMatch && count($pdbU['unitTokens']) > 0) { 
                        $matchedId = $pdbU['id']; 
                        break; 
                    }
                }
            }
        }

        // --- ANALYSIS ENGINE ---
        if ($matchedId && $matchedId > 0) {
            $matchedCount++;
            $oldUnit = $dbUnitsById[$matchedId];
            $currentDbStatus = $oldUnit['status'];

            $dbShell = (float)$oldUnit['shell_price'];
            $dbFin = (float)$oldUnit['finishes_price'];
            if (($price > 0 && $price !== $dbShell) || ($finishesPrice > 0 && $finishesPrice !== $dbFin)) {
                $price_conflicts[] = [
                    'id' => $matchedId,
                    'project_name' => $oldUnit['project_name'],
                    'unit_name' => $oldUnit['unit_name'],
                    'db_shell' => $dbShell, 'db_fin' => $dbFin,
                    'csv_shell' => $price > 0 ? $price : $dbShell, 
                    'csv_fin' => $finishesPrice > 0 ? $finishesPrice : $dbFin
                ];
            }

            if ($currentDbStatus === 'Resale') continue; 

            $dbStatus = 'Available';
            $csvStatusLower = strtolower($csvStatus);
            if (in_array($csvStatusLower, ['pos', 'deal to pos'])) {
                $dbStatus = (strpos($currentDbStatus, 'Sold') !== false) ? $currentDbStatus : 'Sold - POS';
            } elseif (in_array($csvStatusLower, ['contract', 'signed deed'])) {
                $dbStatus = 'Sold - Contract';
            } elseif (in_array($csvStatusLower, ['new', 'in review', 'in progress'])) {
                $dbStatus = (strpos($currentDbStatus, 'Sold') !== false) ? $currentDbStatus : 'Proceeding';
            } elseif (strpos($csvStatusLower, 'stock') !== false) {
                $dbStatus = 'Available';
            }

            if ($currentDbStatus !== $dbStatus) {
                $activeAgentStatuses = ['On Hold', 'Proceeding', 'Proceeding Pending Approval', 'Sold Pending Approval', 'POS Pending Approval', 'Contract Pending Approval'];
                if (!(in_array($currentDbStatus, $activeAgentStatuses) && $dbStatus === 'Available')) {
                    $status_changes[] = [
                        'id' => $matchedId,
                        'project_name' => $oldUnit['project_name'],
                        'unit_name' => $oldUnit['unit_name'],
                        'old_status' => $currentDbStatus,
                        'new_status' => $dbStatus
                    ];
                }
            }

        } elseif ($matchedId != -1) {
            $not_found[] = ['csv_name' => $csvUnitStringRaw, 'status' => $csvStatus];
        }
    }
    fclose($handle);

    $responseArray = [
        'success' => true,
        'stats' => ['scanned' => $scannedCount, 'mapped' => $matchedCount],
        'status_changes' => $status_changes,
        'price_conflicts' => $price_conflicts,
        'not_found' => $not_found,
        'all_db_units' => $dbUnits 
    ];

    $jsonResponse = defined('JSON_INVALID_UTF8_SUBSTITUTE') 
        ? json_encode($responseArray, JSON_INVALID_UTF8_SUBSTITUTE) 
        : json_encode($responseArray);

    if ($jsonResponse === false) {
        echo json_encode(['success' => false, 'message' => "System JSON Encoding Failed. Check for illegal characters in CSV."]);
    } else {
        echo $jsonResponse;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => "Server Logic Error: " . $e->getMessage()]);
}
?>
