<?php
/**
 * Shared CSV analyze engine for daily sync (upload + inbox pending files).
 *
 * @return array{success: bool, stats?: array, status_changes?: array, price_conflicts?: array, not_found?: array, all_db_units?: array, message?: string}
 */
function salesAnalyzeDailySyncCsv(PDO $pdo, string $csvFilePath): array
{
    $handle = fopen($csvFilePath, 'r');
    if (!$handle) {
        return ['success' => false, 'message' => 'Could not read the CSV file.'];
    }

    try {
        $dbUnits = salesGetAccessibleUnits($pdo);

        $dbUnitsById = [];
        foreach ($dbUnits as $u) {
            $dbUnitsById[$u['id']] = $u;
        }

        $stmtTrans = $pdo->query('SELECT csv_name, db_unit_id FROM sync_translations');
        $savedTranslations = [];
        while ($row = $stmtTrans->fetch(PDO::FETCH_ASSOC)) {
            $savedTranslations[strtolower($row['csv_name'])] = $row['db_unit_id'];
        }

        $processedDbUnits = [];
        foreach ($dbUnits as $dbU) {
            $cleanProj = preg_replace('/\s+/', ' ', strtolower(trim($dbU['project_name'])));
            $projParts = explode(' ', $cleanProj);

            $cleanUnit = preg_replace('/\s+/', ' ', strtolower(trim($dbU['unit_name'])));
            $unitRegex = '/\b' . preg_quote($cleanUnit, '/') . '\b/';

            $processedDbUnits[] = [
                'id' => $dbU['id'],
                'projFirstWord' => $projParts[0] ?? '',
                'unitRegex' => $unitRegex,
            ];
        }

        $scannedCount = 0;
        $matchedCount = 0;
        $status_changes = [];
        $price_conflicts = [];
        $not_found = [];
        $colUnit = -1;
        $colStatus = -1;
        $colPrice = -1;
        $colFinishes = -1;
        $isHeaderFound = false;
        $processed_mapped_ids = [];

        while (($raw_data = fgetcsv($handle, 10000, ',')) !== false) {
            $data = [];
            foreach ($raw_data as $cell) {
                $data[] = function_exists('mb_convert_encoding')
                    ? mb_convert_encoding($cell, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252')
                    : $cell;
            }

            if (!$isHeaderFound) {
                foreach ($data as $index => $val) {
                    $valStr = strtolower(trim($val));
                    if (strpos($valStr, 'apartment no') !== false || strpos($valStr, 'project') !== false) {
                        $colUnit = $index;
                    }
                    if ($valStr === 'status') {
                        $colStatus = $index;
                    }
                    if (strpos($valStr, 'stock value') !== false && strpos($valStr, 'c/p') === false) {
                        $colPrice = $index;
                    }
                    if (strpos($valStr, 'stock c/p value') !== false || strpos($valStr, 'c/p value') !== false) {
                        $colFinishes = $index;
                    }
                }
                if ($colUnit !== -1 && $colStatus !== -1) {
                    $isHeaderFound = true;
                }
                continue;
            }

            if (!isset($data[$colUnit]) || !isset($data[$colStatus])) {
                continue;
            }

            $csvUnitStringRaw = trim($data[$colUnit]);
            $csvStatus = trim($data[$colStatus]);
            if ($csvUnitStringRaw === '' || $csvStatus === '') {
                continue;
            }

            $scannedCount++;
            $csvPriceRaw = isset($data[$colPrice]) ? trim($data[$colPrice]) : '';
            $csvFinishesRaw = isset($data[$colFinishes]) ? trim($data[$colFinishes]) : '';

            $price = floatval(preg_replace('/[^0-9.]/', '', $csvPriceRaw));
            $finishesPrice = floatval(preg_replace('/[^0-9.]/', '', $csvFinishesRaw));

            $searchString = preg_replace('/\s+/', ' ', strtolower($csvUnitStringRaw));
            $matchedId = null;

            if (isset($savedTranslations[$searchString])) {
                $matchedId = $savedTranslations[$searchString];
                if ((int)$matchedId === -1) {
                    continue;
                }
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

            if ($matchedId && $matchedId > 0) {
                if (isset($processed_mapped_ids[$matchedId])) {
                    continue;
                }
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
                        'db_shell' => $dbShell,
                        'db_fin' => $dbFin,
                        'csv_shell' => $price > 0 ? $price : $dbShell,
                        'csv_fin' => $finishesPrice > 0 ? $finishesPrice : $dbFin,
                    ];
                }

                if ($currentDbStatus === 'Resale') {
                    continue;
                }

                $dbStatus = 'Available';
                $csvStatusLower = strtolower($csvStatus);
                if (in_array($csvStatusLower, ['pos'], true)) {
                    $dbStatus = 'Sold - POS';
                } elseif (in_array($csvStatusLower, ['contract', 'signed deed'], true)) {
                    $dbStatus = 'Sold - Contract';
                } elseif (in_array($csvStatusLower, ['deal to pos', 'in progress', 'new', 'in review'], true)) {
                    $dbStatus = 'Proceeding';
                } elseif (strpos($csvStatusLower, 'stock') !== false || $csvStatusLower === 'available') {
                    $dbStatus = 'Available';
                } else {
                    $dbStatus = $currentDbStatus;
                }

                if ($currentDbStatus !== $dbStatus) {
                    $activeAgentStatuses = ['On Hold', 'Proceeding', 'Proceeding Pending Approval', 'Sold Pending Approval', 'POS Pending Approval', 'Contract Pending Approval'];
                    if (!(in_array($currentDbStatus, $activeAgentStatuses, true) && $dbStatus === 'Available')) {
                        $status_changes[] = [
                            'id' => $matchedId,
                            'csv_source_name' => $csvUnitStringRaw,
                            'project_name' => $oldUnit['project_name'],
                            'unit_name' => $oldUnit['unit_name'],
                            'old_status' => $currentDbStatus,
                            'new_status' => $dbStatus,
                        ];
                    }
                }
            } else {
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
                    'recommended_full_name' => $recommendedId
                        ? $dbUnitsById[$recommendedId]['project_name'] . ' - ' . $dbUnitsById[$recommendedId]['unit_name']
                        : '',
                ];
            }
        }

        fclose($handle);

        return [
            'success' => true,
            'stats' => ['scanned' => $scannedCount, 'mapped' => $matchedCount],
            'status_changes' => $status_changes,
            'price_conflicts' => $price_conflicts,
            'not_found' => $not_found,
            'all_db_units' => $dbUnits,
        ];
    } catch (Throwable $e) {
        fclose($handle);
        return ['success' => false, 'message' => 'Server Logic Error: ' . $e->getMessage()];
    }
}
