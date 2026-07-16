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
// ACTION: GET IGNORED LEDGER
// =========================================================================
if ($action === 'get_ignored_ledger') {
    try {
        $stmt = $pdo->query("SELECT id, csv_name FROM sync_translations WHERE db_unit_id = -1 ORDER BY csv_name ASC");
        $ignored = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'ignored' => $ignored]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ACTION: RESTORE SINGLE IGNORED ROW
// =========================================================================
if ($action === 'restore_ignored_row') {
    try {
        $id = (int)$_POST['translation_id'];
        $stmt = $pdo->prepare("DELETE FROM sync_translations WHERE id = ? AND db_unit_id = -1");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

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
                if ((int)$t['db_unit_id'] > 0) {
                    salesAssertPropertyAccess($pdo, (int)$t['db_unit_id']);
                }
                $stmtTrans->execute([$t['csv_name'], $t['db_unit_id'], $t['db_unit_id']]);
            }
        }
        
        if (!empty($payload['prices'])) {
            $stmtPrice = $pdo->prepare("UPDATE sales_properties SET shell_price = ?, finishes_price = ? WHERE id = ?");
            $stmtGetOldPrices = $pdo->prepare("SELECT shell_price, finishes_price FROM sales_properties WHERE id = ?");
            
            foreach ($payload['prices'] as $p) {
                salesAssertPropertyAccess($pdo, (int)$p['id']);
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
                salesAssertPropertyAccess($pdo, (int)$s['id']);
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
// ACTION 2: DRY RUN / ANALYZE CSV (Strict Integrity Engine)
// =========================================================================
if (!isset($_FILES['sync_csv']) || $_FILES['sync_csv']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
    exit;
}

try {
    $file = $_FILES['sync_csv']['tmp_name'];
    $handle = fopen($file, "r");
    if (!$handle) throw new Exception('Could not read the CSV file.');

    $dbUnits = salesGetAccessibleUnits($pdo);

    $dbUnitsById = [];
    foreach ($dbUnits as $u) { $dbUnitsById[$u['id']] = $u; }

    $stmtTrans = $pdo->query("SELECT csv_name, db_unit_id FROM sync_translations");
    $savedTranslations = [];
    while ($row = $stmtTrans->fetch(PDO::FETCH_ASSOC)) {
        $savedTranslations[strtolower($row['csv_name'])] = $row['db_unit_id'];
    }

    $ignoreWords = ['apt', 'apartment', 'blk', 'block', 'ph', 'penthouse', 'mais', 'maisonette', 'garage', 'car', 'space', 'house', 'pt', 'level', 'lv', 'cs', 'gr', 'residences'];

    // --- ZERO-TOLERANCE STRICT MATCHER PRE-PROCESSING ---
    $processedDbUnits = [];
    foreach ($dbUnits as $dbU) {
        $cleanProj = preg_replace('/\s+/', ' ', strtolower(trim($dbU['project_name'])));
        $projParts = explode(' ', $cleanProj);
        
        $cleanUnit = preg_replace('/\s+/', ' ', strtolower(trim($dbU['unit_name'])));
        $unitRegex = '/\b' . preg_quote($cleanUnit, '/') . '\b/'; // Fixes "Garage 5" vs "House 5"
        
        $processedDbUnits[] = [
            'id' => $dbU['id'],
            'projFirstWord' => $projParts[0] ?? '',
            'unitRegex' => $unitRegex
        ];
    }

    $scannedCount = 0; $matchedCount = 0;
    $status_changes = []; $price_conflicts = []; $not_found = [];
    $colUnit = -1; $colStatus = -1; $colPrice = -1; $colFinishes = -1; 
    $isHeaderFound = false;

    // DEDUPLICATION SHIELD: Prevents 1 DB unit from being mapped to 2 CSV rows
    $processed_mapped_ids = [];

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
        
        // --- STRICT MATCHING EXECUTION ---
        $searchString = preg_replace('/\s+/', ' ', strtolower($csvUnitStringRaw));
        $matchedId = null;

        if (isset($savedTranslations[$searchString])) {
            $matchedId = $savedTranslations[$searchString];
            if ($matchedId == -1) continue; // 🛑 Permanently Ignored! Skip this row instantly.
        } else {
            $matchedIds = [];
            foreach ($processedDbUnits as $pdbU) {
                if (strpos($searchString, $pdbU['projFirstWord']) !== false) {
                    if (preg_match($pdbU['unitRegex'], $searchString)) {
                        $matchedIds[] = $pdbU['id'];
                    }
                }
            }
            if (count($matchedIds) === 1) {
                $matchedId = $matchedIds[0];
            }
        }

        // --- ANALYSIS ENGINE ---
        if ($matchedId && $matchedId > 0) {
            
            // DEDUPLICATION EXECUTION
            if (isset($processed_mapped_ids[$matchedId])) continue;
            $processed_mapped_ids[$matchedId] = true;

            $matchedCount++;
            $oldUnit = $dbUnitsById[$matchedId];
            $currentDbStatus = $oldUnit['status'];

            $dbShell = (float)$oldUnit['shell_price'];
            $dbFin = (float)$oldUnit['finishes_price'];
            
            if (($price > 0 && $price !== $dbShell) || ($finishesPrice > 0 && $finishesPrice !== $dbFin)) {
                $price_conflicts[] = [
                    'id' => $matchedId,
                    'csv_source_name' => $csvUnitStringRaw,
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
            if (in_array($csvStatusLower, ['pos'])) {
                $dbStatus = 'Sold - POS';
            } elseif (in_array($csvStatusLower, ['contract', 'signed deed'])) {
                $dbStatus = 'Sold - Contract';
            } elseif (in_array($csvStatusLower, ['deal to pos', 'in progress', 'new', 'in review'])) {
                $dbStatus = 'Proceeding';
            } elseif (strpos($csvStatusLower, 'stock') !== false || $csvStatusLower === 'available') {
                $dbStatus = 'Available';
            } else {
                $dbStatus = $currentDbStatus;
            }

            if ($currentDbStatus !== $dbStatus) {
                $activeAgentStatuses = ['On Hold', 'Proceeding', 'Proceeding Pending Approval', 'Sold Pending Approval', 'POS Pending Approval', 'Contract Pending Approval'];
                if (!(in_array($currentDbStatus, $activeAgentStatuses) && $dbStatus === 'Available')) {
                    $status_changes[] = [
                        'id' => $matchedId,
                        'csv_source_name' => $csvUnitStringRaw,
                        'project_name' => $oldUnit['project_name'],
                        'unit_name' => $oldUnit['unit_name'],
                        'old_status' => $currentDbStatus,
                        'new_status' => $dbStatus
                    ];
                }
            }

        } else {
            // --- "BEST GUESS" AI ENGINE ---
            $bestMatchId = '';
            $highestPercent = 0;
            $cleanSearch = trim(strtolower($csvUnitStringRaw));
            
            foreach ($dbUnits as $dbU) {
                $cleanDb = trim(strtolower($dbU['project_name'] . ' ' . $dbU['unit_name']));
                similar_text($cleanSearch, $cleanDb, $percent);
                if ($percent > $highestPercent) {
                    $highestPercent = $percent;
                    $bestMatchId = $dbU['id'];
                }
            }
            
            $recommendedId = ($highestPercent > 65) ? $bestMatchId : '';
            
            $not_found[] = [
                'csv_name' => $csvUnitStringRaw, 
                'status' => $csvStatus,
                'recommended_id' => $recommendedId,
                'recommended_full_name' => $recommendedId ? $dbUnitsById[$recommendedId]['project_name'] . ' - ' . $dbUnitsById[$recommendedId]['unit_name'] : ''
            ];
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
