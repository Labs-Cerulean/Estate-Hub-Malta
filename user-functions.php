<?php
/**
 * user-functions.php - Rev 2.6 Enterprise Logic
 * Contains capability, access, and accurate stage engine.
 */

// ==========================================
// 1. CAPABILITY ENGINE
// ==========================================

function canUsePlantHubApi(): bool {
    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['admin', 'director', 'accountant', 'system_manager', 'plant_manager', 'plant_driver'], true)) {
        return true;
    }
    return hasPermission('view_plant_bookings')
        || hasPermission('manage_plant_fleet')
        || hasPermission('view_plant_ledger');
}

function hasPermission($capability) {
    global $pdo;
    $userId = $_SESSION['user_id'] ?? null;
    $role = $_SESSION['role'] ?? null;

    if (!$userId) return false;
    if ($role === 'admin') return true; 

    // REVISION: Added the new Commercial Sales capabilities to the strict edit block
    $editCapabilities = [
        'add_project', 'edit_project_details', 'update_project_status', 
        'manage_clients', 'manage_professionals', 'manage_users', 'manage_subcontractors',
        'manage_sales_demo_exc', 'manage_sales_const', 'manage_sales_finishes', 'manage_sales_ohsa', 'edit_project_schedule'
    ];
    
    if (in_array($role, ['viewer', 'legal_representative']) && in_array($capability, $editCapabilities)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT $capability FROM user_capabilities WHERE user_id = ?");
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (bool)$val : false;
    } catch (PDOException $e) { return false; }
}

function canEditProjectDetails($pdo, $projectId) {
    return hasPermission('edit_project_details') && hasProjectAccess($pdo, $projectId);
}

function canUpdateStatus($pdo, $projectId) {
    if (getCurrentRole() === 'legal_representative') return false;
    return hasPermission('update_project_status') && hasProjectAccess($pdo, $projectId);
}

function canEditProjectSchedule($pdo, $projectId) {
    if (getCurrentRole() === 'legal_representative') return false;
    return hasPermission('edit_project_schedule') && hasProjectAccess($pdo, $projectId);
}

function isLegalRepresentative() {
    return getCurrentRole() === 'legal_representative';
}

function getUserInitials($firstName, $lastName, $username = '') {
    $f = trim((string)$firstName);
    $l = trim((string)$lastName);
    $u = trim((string)$username) ?: 'U';
    if ($f !== '' && $l !== '') {
        return mb_strtoupper(mb_substr($f, 0, 1, 'UTF-8') . mb_substr($l, 0, 1, 'UTF-8'), 'UTF-8');
    }
    if ($f !== '') {
        return mb_strtoupper(mb_substr($f, 0, 2, 'UTF-8'), 'UTF-8');
    }
    return mb_strtoupper(mb_substr($u, 0, 2, 'UTF-8'), 'UTF-8');
}

/**
 * Validate an uploaded image using finfo (not client-provided MIME/extension).
 * Returns ['mime' => ..., 'ext' => ...] or null if invalid.
 */
function validateUploadedImage($tmpPath) {
    if (!is_uploaded_file($tmpPath)) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return null;
    }
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return null;
    }
    return ['mime' => $mime, 'ext' => $allowed[$mime]];
}

// ==========================================
// 2. STAGE ENGINE (EXCEL LOGIC MAP APPLIED)
// ==========================================

function computeAccurateProjectStage(array $proj, array $paData, ?array $mob, array $blocks, array $levelsByBlockId) {
    if (empty($proj)) return 'Feasibility';
    if (($proj['project_status'] ?? '') === 'Completed') return 'Handed Over';

    $isCapital = in_array(strtolower($proj['type'] ?? ''), ['3rd-party', 'capital', '3rd party']);
    $finishGoal = trim($proj['finishlevel'] ?? 'Shell');
    $isTracking = (int)($proj['is_tracking'] ?? 0) === 1;

    $hasPaNumbers = count($paData) > 0;
    $allTracking = true;
    $hasDecidedEndorsed = false;
    if ($hasPaNumbers) {
        foreach ($paData as $pa) {
            $status = strtolower(trim($pa['pa_status'] ?? ''));
            if ($status !== 'tracking') $allTracking = false;
            if (strpos($status, 'decided') !== false || strpos($status, 'endorsed') !== false || strpos($status, 'approved') !== false) {
                $hasDecidedEndorsed = true;
            }
        }
    } else {
        $allTracking = false;
    }

    $mob = $mob ?? [];
    $demoClearance = ($mob['mob_demolition'] ?? 'No') === 'Yes';
    $excClearance = ($mob['mob_excavation'] ?? 'No') === 'Yes';
    $constClearance = ($mob['mob_construction'] ?? 'No') === 'Yes';
    $demoStatus = $mob['demo_status'] ?? 'Pending';
    $excStatus = $mob['excavation_status'] ?? 'Pending';
    $demoComplete = in_array($demoStatus, ['Complete', 'NA']);
    $excComplete = in_array($excStatus, ['Complete', 'NA']);

    $allConstComplete = true;
    $anyConstInProgress = false;
    $allFinComplete = true;
    $allCompSubmitted = true;
    $allCompCertified = true;
    $allCondoFormed = true;
    $allCpMeters = true;
    $needsFinishes = false;

    if (empty($blocks)) {
        $allConstComplete = false;
    } else {
        foreach ($blocks as $b) {
            $levels = $levelsByBlockId[(int)$b['id']] ?? [];
            if (empty($levels)) {
                $allConstComplete = false;
            } else {
                foreach ($levels as $l) {
                    if ($l['construction_status'] === 'In Progress') $anyConstInProgress = true;
                    if (!in_array($l['construction_status'], ['Complete', 'NA'])) $allConstComplete = false;
                }
            }

            $bFinGoal = trim(!empty($b['finish_level']) ? $b['finish_level'] : $finishGoal);
            if ($bFinGoal === 'Semi-Finished') $bFinGoal = 'Semi Finished';
            $isBlockShell = in_array($bFinGoal, ['Shell', 'Shell (No Finishes)', 'NA', '']);

            if (!$isBlockShell) {
                $needsFinishes = true;
                $bFinComplete = false;
                if (in_array($b['finishes_overall_status'], ['Complete', 'NA'])) {
                    $bFinComplete = true;
                } elseif (isset($b['progress']) && $b['progress'] >= 100) {
                    $bFinComplete = true;
                }
                if (!$bFinComplete) $allFinComplete = false;
            }

            if (!in_array($b['compliance_submitted'], ['Yes', 'NA'])) $allCompSubmitted = false;
            if (!in_array($b['compliance_certified'], ['Yes', 'NA'])) $allCompCertified = false;
            if (!in_array($b['condominium_formed'], ['Yes', 'NA'])) $allCondoFormed = false;
            if (!in_array($b['cp_meters_installed'], ['Yes', 'NA'])) $allCpMeters = false;
        }
    }

    if ($isCapital) {
        $allCompSubmitted = true;
        $allCompCertified = true;
        $allCondoFormed = true;
        $allCpMeters = true;
    }

    $hasPhysicalOverride = $anyConstInProgress || (!empty($blocks) && $allConstComplete) || $constClearance ||
                           $demoStatus === 'In Progress' || $demoStatus === 'Complete' || $demoClearance ||
                           $excStatus === 'In Progress' || $excStatus === 'Complete' || $excClearance;

    if (!$hasDecidedEndorsed && !$hasPhysicalOverride) {
        if ($hasPaNumbers) { return ($isTracking || $allTracking) ? 'Tracking' : 'Permit'; }
        return ($isTracking) ? 'Tracking' : 'Feasibility';
    }

    if (!empty($blocks) && $allConstComplete && $hasPhysicalOverride) {
        if ($needsFinishes && !$allFinComplete) return 'Finishes';
        if (!$allCompSubmitted) return $needsFinishes ? 'Finishes' : 'Construction';
        if (!$allCompCertified) return 'Compliance';
        if (!$allCondoFormed || !$allCpMeters) return 'Condominium';
        return 'Handed Over';
    }

    if ($anyConstInProgress || $constClearance) return 'Construction';
    if ($excStatus === 'In Progress' || $excClearance) return $excComplete ? 'Construction' : 'Excavation';
    if ($demoStatus === 'In Progress' || $demoClearance) return $demoComplete ? 'Excavation' : 'Demolition';
    if ($hasDecidedEndorsed) return 'Mobilisation';

    return 'Permit';
}

function getAccurateProjectStage($pdo, $projectId) {
    $stmt = $pdo->prepare("SELECT type, finishlevel, project_status, is_tracking FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $proj = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proj) return 'Feasibility';

    $paStmt = $pdo->prepare("SELECT pa_number, pa_status FROM project_pa_numbers WHERE project_id = ?");
    $paStmt->execute([$projectId]);
    $paData = $paStmt->fetchAll(PDO::FETCH_ASSOC);

    $mobStmt = $pdo->prepare("SELECT demo_status, excavation_status, mob_demolition, mob_excavation, mob_construction FROM project_mobilisation WHERE project_id = ?");
    $mobStmt->execute([$projectId]);
    $mob = $mobStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $bStmt = $pdo->prepare("SELECT id, block_type, finish_level, compliance_submitted, compliance_certified, condominium_formed, cp_meters_installed, finishes_overall_status, progress FROM project_blocks WHERE project_id = ?");
    $bStmt->execute([$projectId]);
    $blocks = $bStmt->fetchAll(PDO::FETCH_ASSOC);

    $levelsByBlockId = [];
    foreach ($blocks as $b) {
        $lStmt = $pdo->prepare("SELECT construction_status FROM block_levels WHERE block_id = ?");
        $lStmt->execute([$b['id']]);
        $levelsByBlockId[(int)$b['id']] = $lStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return computeAccurateProjectStage($proj, $paData, $mob, $blocks, $levelsByBlockId);
}

// ==========================================
// 3. PROJECT ACCESS ENGINE
// ==========================================

function getAccessibleProjects($pdo, $userId = null) {
    if ($userId === null) $userId = getCurrentUserId();
    
    $trackingFilter = "";
    if (!isAdmin() && !hasPermission('view_tracking')) { $trackingFilter = " AND p.is_tracking = 0 "; }

    if (isAdmin()) {
        $stmt = $pdo->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id ORDER BY p.created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    $user = getUserById($pdo, $userId);
    if (!$user) return [];
    
    $level1Roles = ['architect', 'structural_engineer', 'site_technical_officer'];
    if (in_array($user['role'], $level1Roles)) {
        $stmt = $pdo->prepare("SELECT DISTINCT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id LEFT JOIN project_pa_numbers ppn ON p.id = ppn.project_id WHERE ((? IS NOT NULL AND ppn.architect_id IN (SELECT id FROM professionals WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?) AND role_type = 'architect')) OR (? IS NOT NULL AND ppn.structural_engineer_id IN (SELECT id FROM professionals WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?) AND role_type = 'structural_engineer'))) AND p.id NOT IN (SELECT project_id FROM user_project_exclusions WHERE user_id = ?) $trackingFilter ORDER BY p.created_at DESC");
        $stmt->execute([$user['assigned_architect_firm_id'], $user['assigned_architect_firm_id'], $user['assigned_structural_firm_id'], $user['assigned_structural_firm_id'], $userId]);
        return $stmt->fetchAll();
    }
    
    $stmt = $pdo->prepare("SELECT DISTINCT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id LEFT JOIN user_client_access uca ON p.clientid = uca.client_id AND uca.user_id = ? LEFT JOIN user_project_access upa ON p.id = upa.project_id AND upa.user_id = ? WHERE (uca.id IS NOT NULL OR upa.id IS NOT NULL) AND p.id NOT IN (SELECT project_id FROM user_project_exclusions WHERE user_id = ?) $trackingFilter ORDER BY p.created_at DESC");
    $stmt->execute([$userId, $userId, $userId]);
    return $stmt->fetchAll();
}

function hasProjectAccess($pdo, $projectId) {
    $userId = getCurrentUserId();
    if (isAdmin()) return true;
    
    $stmt = $pdo->prepare("SELECT id FROM user_project_exclusions WHERE user_id = ? AND project_id = ?");
    $stmt->execute([$userId, $projectId]);
    if ($stmt->fetch()) return false;

    $user = getUserById($pdo, $userId);
    $level1Roles = ['architect', 'structural_engineer', 'site_technical_officer'];
    
    if (in_array($user['role'], $level1Roles)) {
        $stmt = $pdo->prepare("SELECT p.id FROM projects p LEFT JOIN project_pa_numbers ppn ON p.id = ppn.project_id WHERE p.id = ? AND (ppn.architect_id IN (SELECT id FROM professionals WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?)))");
        $stmt->execute([$projectId, $user['assigned_architect_firm_id']]);
        return $stmt->fetch() !== false;
    }
    
    $stmt = $pdo->prepare("SELECT p.id FROM projects p LEFT JOIN user_client_access uca ON p.clientid = uca.client_id AND uca.user_id = ? LEFT JOIN user_project_access upa ON p.id = upa.project_id AND upa.user_id = ? WHERE p.id = ? AND (uca.id IS NOT NULL OR upa.id IS NOT NULL)");
    $stmt->execute([$userId, $userId, $projectId]);
    return $stmt->fetch() !== false;
}

// ==========================================
// SALES HUB — CLIENT / PROJECT ACCESS
// ==========================================

function salesHubHasGlobalAccess(): bool {
    return isAdmin() || getCurrentRole() === 'director';
}

function hasSalesProjectAccess(PDO $pdo, int $projectId): bool {
    static $cache = [];
    if ($projectId <= 0) {
        return false;
    }
    if (!isset($cache[$projectId])) {
        $cache[$projectId] = hasProjectAccess($pdo, $projectId);
    }
    return $cache[$projectId];
}

function hasSalesPropertyAccess(PDO $pdo, int $propertyId): bool {
    static $cache = [];
    if ($propertyId <= 0) {
        return false;
    }
    if (!isset($cache[$propertyId])) {
        $stmt = $pdo->prepare('SELECT project_id FROM sales_properties WHERE id = ?');
        $stmt->execute([$propertyId]);
        $projectId = $stmt->fetchColumn();
        $cache[$propertyId] = $projectId ? hasSalesProjectAccess($pdo, (int)$projectId) : false;
    }
    return $cache[$propertyId];
}

/**
 * SQL fragment + bind params restricting rows to projects the current user may access.
 *
 * @return array{sql: string, params: array<int, int>}
 */
function salesProjectAccessWhereClause(PDO $pdo, string $projectAlias = 'p'): array {
    if (salesHubHasGlobalAccess()) {
        return ['sql' => '1=1', 'params' => []];
    }

    $userId = (int)getCurrentUserId();
    if ($userId <= 0) {
        return ['sql' => '1=0', 'params' => []];
    }

    $projectAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $projectAlias) ?: 'p';
    $sql = "(
        EXISTS (
            SELECT 1 FROM user_client_access uca
            WHERE uca.user_id = ? AND uca.client_id = {$projectAlias}.clientid
        )
        OR EXISTS (
            SELECT 1 FROM user_project_access upa
            WHERE upa.user_id = ? AND upa.project_id = {$projectAlias}.id
        )
    )
    AND NOT EXISTS (
        SELECT 1 FROM user_project_exclusions upe
        WHERE upe.user_id = ? AND upe.project_id = {$projectAlias}.id
    )";

    return ['sql' => $sql, 'params' => [$userId, $userId, $userId]];
}

function salesDenyJsonAccess(string $message = 'Access denied.'): void {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function salesGetAccessibleProjectsWithUnits(PDO $pdo): array {
    $access = salesProjectAccessWhereClause($pdo, 'p');
    $sql = "SELECT DISTINCT p.id, p.name, p.city
            FROM projects p
            INNER JOIN sales_properties sp ON p.id = sp.project_id
            WHERE {$access['sql']}
            ORDER BY p.city ASC, p.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($access['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function salesGetAccessibleProjects(PDO $pdo): array {
    $access = salesProjectAccessWhereClause($pdo, 'p');
    $sql = "SELECT p.id, p.name, p.city
            FROM projects p
            WHERE {$access['sql']}
            ORDER BY p.city ASC, p.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($access['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function salesGetAccessibleUnits(PDO $pdo): array {
    $access = salesProjectAccessWhereClause($pdo, 'p');
    $sql = "SELECT sp.id, sp.unit_name, sp.status, sp.shell_price, sp.finishes_price, p.name AS project_name
            FROM sales_properties sp
            JOIN projects p ON sp.project_id = p.id
            WHERE {$access['sql']}
            ORDER BY p.name ASC, sp.unit_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($access['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function salesAssertPropertyAccess(PDO $pdo, int $propertyId): void {
    if (!hasSalesPropertyAccess($pdo, $propertyId)) {
        salesDenyJsonAccess();
    }
}

function salesAssertProjectAccess(PDO $pdo, int $projectId): void {
    if (!hasSalesProjectAccess($pdo, $projectId)) {
        salesDenyJsonAccess();
    }
}

// ==========================================
// 4. USER MANAGEMENT UTILITIES
// ==========================================

function getUserById($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function getAllUsers($pdo) { return $pdo->query("SELECT * FROM users ORDER BY username ASC")->fetchAll(); }

function getUserClients($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT c.*, uca.assigned_at FROM clients c INNER JOIN user_client_access uca ON c.id = uca.client_id WHERE uca.user_id = ? ORDER BY c.name ASC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function assignUserToClient($pdo, $userId, $clientId) {
    try {
        $check = $pdo->prepare("SELECT id FROM user_client_access WHERE user_id = ? AND client_id = ?");
        $check->execute([$userId, $clientId]);
        if ($check->fetch()) return true;
        
        $stmt = $pdo->prepare("INSERT INTO user_client_access (user_id, client_id, assigned_by) VALUES (?, ?, ?)");
        return $stmt->execute([$userId, $clientId, getCurrentUserId()]);
    } catch (PDOException $e) { return false; }
}

function removeUserFromClient($pdo, $userId, $clientId) {
    try { return $pdo->prepare("DELETE FROM user_client_access WHERE user_id = ? AND client_id = ?")->execute([$userId, $clientId]); } catch (PDOException $e) { return false; }
}

function excludeProjectFromUser($pdo, $userId, $projectId) {
    try {
        $check = $pdo->prepare("SELECT id FROM user_project_exclusions WHERE user_id = ? AND project_id = ?");
        $check->execute([$userId, $projectId]);
        if ($check->fetch()) return true;
        
        return $pdo->prepare("INSERT INTO user_project_exclusions (user_id, project_id, excluded_by) VALUES (?, ?, ?)")->execute([$userId, $projectId, getCurrentUserId()]);
    } catch (PDOException $e) { return false; }
}

function removeProjectExclusion($pdo, $userId, $projectId) {
    try { return $pdo->prepare("DELETE FROM user_project_exclusions WHERE user_id = ? AND project_id = ?")->execute([$userId, $projectId]); } catch (PDOException $e) { return false; }
}

function getUserExcludedProjects($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT p.*, c.name as client_name, upe.excluded_at FROM projects p LEFT JOIN clients c ON p.clientid = c.id INNER JOIN user_project_exclusions upe ON p.id = upe.project_id WHERE upe.user_id = ? ORDER BY p.name ASC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getUserAssignedProjects($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT p.*, c.name as client_name, upa.assigned_at FROM projects p LEFT JOIN clients c ON p.clientid = c.id INNER JOIN user_project_access upa ON p.id = upa.project_id WHERE upa.user_id = ? ORDER BY p.name ASC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function assignUserToProject($pdo, $userId, $projectId) {
    try {
        $check = $pdo->prepare("SELECT id FROM user_project_access WHERE user_id = ? AND project_id = ?");
        $check->execute([$userId, $projectId]);
        if ($check->fetch()) return true;
        return $pdo->prepare("INSERT INTO user_project_access (user_id, project_id, assigned_by) VALUES (?, ?, ?)")->execute([$userId, $projectId, getCurrentUserId()]);
    } catch (PDOException $e) { return false; }
}

function removeUserFromProject($pdo, $userId, $projectId) {
    try { return $pdo->prepare("DELETE FROM user_project_access WHERE user_id = ? AND project_id = ?")->execute([$userId, $projectId]); } catch (PDOException $e) { return false; }
}

function changePassword($pdo, $userId, $newPassword) {
    try {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        return $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$passwordHash, $userId]);
    } catch (PDOException $e) { return false; }
}

function deleteUser($pdo, $userId) {
    try { return $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]); } catch (PDOException $e) { return false; }
}

// ==========================================
// 5. FIRM / PROFESSIONAL UTILITIES
// ==========================================

function getAllFirms($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT firm_name, role_type FROM professionals ORDER BY firm_name ASC, role_type ASC");
    $allFirms = $stmt->fetchAll();
    
    $firms = ['architects' => [], 'structural_engineers' => []];
    foreach ($allFirms as $firm) {
        if ($firm['role_type'] === 'architect' && !in_array($firm['firm_name'], $firms['architects'])) {
            $firms['architects'][] = $firm['firm_name'];
        }
        if ($firm['role_type'] === 'structural_engineer' && !in_array($firm['firm_name'], $firms['structural_engineers'])) {
            $firms['structural_engineers'][] = $firm['firm_name'];
        }
    }
    return $firms;
}

function getProfessionalIdByFirm($pdo, $firmName, $roleType) {
    $stmt = $pdo->prepare("SELECT id FROM professionals WHERE firm_name = ? AND role_type = ? LIMIT 1");
    $stmt->execute([$firmName, $roleType]);
    $result = $stmt->fetch();
    return $result ? $result['id'] : null;
}

function formatPANumber($pa) {
    $clean = str_replace(['/', ' '], '', $pa);
    if (preg_match('/^([A-Z]{2})(\d{4})(\d{2})$/', $clean, $matches)) { return "{$matches[1]}/{$matches[2]}/{$matches[3]}"; }
    return $pa;
}

function getEAppsUrl($pa) {
    $rawPa = str_replace(['/', ' '], '', $pa);
    if (preg_match('/(PA|PC|DN)(\d+)(\d{2})/', $rawPa, $m)) { return "https://eapps.pa.org.mt/Case/CaseDetails?caseType={$m[1]}&casenumber={$m[2]}&caseYear={$m[3]}"; }
    return "#";
}

/**
 * Converts GPS Coordinates to a physical street address using free OpenStreetMap API
 */
function getAddressFromCoordinates($lat, $lng) {
    if (empty($lat) || empty($lng)) return null;
    
    $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat={$lat}&lon={$lng}&zoom=16";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Nominatim strictly requires a User-Agent header to use their free service
    curl_setopt($ch, CURLOPT_USERAGENT, 'EstateHubMalta/1.0 (nicholas@labscerulean.com)'); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3-second timeout so it never hangs the server
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data['address'])) {
            $street = $data['address']['road'] ?? '';
            $town = $data['address']['village'] ?? $data['address']['town'] ?? $data['address']['city'] ?? $data['address']['municipality'] ?? '';
            
            $addressPieces = array_filter([$street, $town]);
            if (!empty($addressPieces)) {
                return implode(', ', $addressPieces);
            }
        }
    }
    return null; // Fallback if the map server is offline
}

function getPlantJobSessions($pdo, $bookingId) {
    $stmt = $pdo->prepare("SELECT * FROM plant_job_sessions WHERE booking_id = ? ORDER BY punch_in ASC");
    $stmt->execute([(int)$bookingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPlantJobLocationLabel($job) {
    if (($job['booking_type'] ?? '') === 'in-house') {
        return !empty($job['project_name']) ? $job['project_name'] : 'N/A';
    }

    if (!empty($job['location_lat']) && !empty($job['location_lng'])) {
        $address = getAddressFromCoordinates($job['location_lat'], $job['location_lng']);
        if ($address) {
            return $address;
        }
        return 'Lat: ' . round((float)$job['location_lat'], 4) . ', Lng: ' . round((float)$job['location_lng'], 4);
    }

    return 'External Location';
}

function formatPlantJobTimeRange(DateTime $inTime, DateTime $outTime) {
    if ($inTime->format('Y-m-d') !== $outTime->format('Y-m-d')) {
        return $inTime->format('d M, H:i') . ' to ' . $outTime->format('d M, H:i');
    }

    return $inTime->format('H:i') . ' to ' . $outTime->format('H:i');
}

function tryPlantDateTime($value) {
    if ($value === null || $value === '') {
        return null;
    }

    try {
        return new DateTime($value);
    } catch (Exception $e) {
        return null;
    }
}

function tryPlantBookingDateTime($job, $timeField) {
    $bookingDate = $job['booking_date'] ?? null;
    $time = $job[$timeField] ?? null;
    if (empty($bookingDate) || empty($time)) {
        return null;
    }

    return tryPlantDateTime($bookingDate . ' ' . $time);
}

function getPlantJobTimeLogged($pdo, $job, $sessions = null) {
    try {
        $pricingType = $job['pricing_type'] ?? '';
        $lifecycleType = $job['lifecycle_type'] ?? 'Standard';
        $bookingDate = $job['booking_date'] ?? null;

        if ($pricingType === 'daily' || $lifecycleType === 'Auto-Scheduled') {
            if (empty($bookingDate)) {
                return 'N/A';
            }
            $startTs = strtotime($bookingDate);
            if ($startTs === false) {
                return 'N/A';
            }
            $start = date('d M Y', $startTs);
            $endDate = $job['end_date'] ?? null;
            $endTs = !empty($endDate) ? strtotime($endDate) : false;
            $end = ($endTs !== false) ? date('d M Y', $endTs) : $start;
            return $start . ' to ' . $end;
        }

        $bookingId = (int)($job['id'] ?? 0);
        if ($bookingId <= 0) {
            return 'N/A';
        }

        if ($sessions === null) {
            $sessions = getPlantJobSessions($pdo, $bookingId);
        }

        if (count($sessions) > 0) {
            $lines = [];
            foreach ($sessions as $idx => $session) {
                if (empty($session['punch_in'])) {
                    continue;
                }

                $punchInTs = strtotime($session['punch_in']);
                if ($punchInTs === false) {
                    continue;
                }

                $line = 'Day ' . ($idx + 1) . ': ' . date('d M, H:i', $punchInTs);

                if (!empty($session['punch_out'])) {
                    $punchOutTs = strtotime($session['punch_out']);
                    if ($punchOutTs !== false) {
                        $hours = isset($session['hours']) ? (float)$session['hours'] : 0;
                        $line .= ' to ' . date('H:i', $punchOutTs) . ' (' . number_format($hours, 2) . ' hrs)';
                    }
                } else {
                    $line .= ' (In Progress)';
                }

                $lines[] = $line;
            }

            if (($job['status'] ?? '') === 'In Progress' && !empty($job['punch_in_time'])) {
                $activeTs = strtotime($job['punch_in_time']);
                if ($activeTs !== false) {
                    $lines[] = 'Active: since ' . date('d M, H:i', $activeTs);
                }
            }

            if (!empty($lines)) {
                return implode('; ', $lines);
            }
        }

        $inFromPunch = tryPlantDateTime($job['punch_in_time'] ?? null);
        $outFromPunch = tryPlantDateTime($job['punch_out_time'] ?? null);

        if ($inFromPunch && $outFromPunch) {
            return formatPlantJobTimeRange($inFromPunch, $outFromPunch);
        }

        if ($outFromPunch && !$inFromPunch) {
            $inFromSchedule = tryPlantBookingDateTime($job, 'start_time');
            if ($inFromSchedule) {
                return formatPlantJobTimeRange($inFromSchedule, $outFromPunch);
            }
        }

        $inTime = $inFromPunch ?? tryPlantBookingDateTime($job, 'start_time');
        $outTime = $outFromPunch ?? tryPlantBookingDateTime($job, 'end_time');

        if ($inTime && $outTime) {
            return formatPlantJobTimeRange($inTime, $outTime);
        }

        return 'N/A';
    } catch (Exception $e) {
        return 'N/A';
    }
}
