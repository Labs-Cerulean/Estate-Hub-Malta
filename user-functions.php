<?php
/**
 * user-functions.php - Rev 2.5 Enterprise Logic
 * Contains capability, access, and accurate stage engine.
 */

// ==========================================
// 1. CAPABILITY ENGINE
// ==========================================

function hasPermission($capability) {
    global $pdo;
    $userId = $_SESSION['user_id'] ?? null;
    $role = $_SESSION['role'] ?? null;

    if (!$userId) return false;
    if ($role === 'admin') return true; 

    $editCapabilities = ['add_project', 'edit_project_details', 'update_project_status', 'manage_clients', 'manage_professionals', 'manage_users', 'manage_subcontractors'];
    if ($role === 'viewer' && in_array($capability, $editCapabilities)) {
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
    return hasPermission('update_project_status') && hasProjectAccess($pdo, $projectId);
}

// ==========================================
// 2. STAGE ENGINE (EXCEL LOGIC MAP APPLIED)
// ==========================================

function getAccurateProjectStage($pdo, $projectId) {
    // Fetch base project data
    $stmt = $pdo->prepare("SELECT type, finishlevel, project_status, is_tracking FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $proj = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proj) return 'Feasibility';

    if (($proj['project_status'] ?? '') === 'Completed') return 'Handed Over';

    $isCapital = in_array(strtolower($proj['type'] ?? ''), ['3rd-party', 'capital', '3rd party']);
    $finishGoal = trim($proj['finishlevel'] ?? 'Shell');
    $isTracking = (int)($proj['is_tracking'] ?? 0) === 1;

    // Fetch PA Numbers (FIXED COLUMN NAME)
    $paStmt = $pdo->prepare("SELECT pa_number, pa_status FROM project_pa_numbers WHERE project_id = ?");
    $paStmt->execute([$projectId]);
    $paData = $paStmt->fetchAll(PDO::FETCH_ASSOC);
    $hasPaNumbers = count($paData) > 0;

    $allTracking = true;
    $hasDecidedEndorsed = false;
    if ($hasPaNumbers) {
        foreach ($paData as $pa) {
            $status = strtolower(trim($pa['pa_status'] ?? ''));
            if ($status !== 'tracking') $allTracking = false;
            // Evaluates to true if Decided, Endorsed, or Approved
            if (strpos($status, 'decided') !== false || strpos($status, 'endorsed') !== false || strpos($status, 'approved') !== false) {
                $hasDecidedEndorsed = true;
            }
        }
    } else {
        $allTracking = false;
    }

    // Fetch Mobilisation & Clearances Data
    $mobStmt = $pdo->prepare("SELECT demo_status, excavation_status, mob_demolition, mob_excavation, mob_construction FROM project_mobilisation WHERE project_id = ?");
    $mobStmt->execute([$projectId]);
    $mob = $mobStmt->fetch(PDO::FETCH_ASSOC);
    
    $demoClearance = ($mob['mob_demolition'] ?? 'No') === 'Yes';
    $excClearance = ($mob['mob_excavation'] ?? 'No') === 'Yes';
    $constClearance = ($mob['mob_construction'] ?? 'No') === 'Yes';
    
    $demoStatus = $mob['demo_status'] ?? 'Pending';
    $excStatus = $mob['excavation_status'] ?? 'Pending';

    $demoComplete = in_array($demoStatus, ['Complete', 'NA']);
    $excComplete = in_array($excStatus, ['Complete', 'NA']);

    // Fetch Blocks & Levels Data
    $bStmt = $pdo->prepare("SELECT id, block_type, finish_level, compliance_submitted, compliance_certified, condominium_formed, cp_meters_installed, finishes_overall_status, progress FROM project_blocks WHERE project_id = ?");
    $bStmt->execute([$projectId]);
    $blocks = $bStmt->fetchAll(PDO::FETCH_ASSOC);

    $allConstComplete = true; $anyConstInProgress = false;
    $allFinComplete = true; 
    $allCompSubmitted = true; $allCompCertified = true;
    $allCondoFormed = true; $allCpMeters = true;
    $needsFinishes = false;

    if (empty($blocks)) {
        $allConstComplete = false;
    } else {
        foreach ($blocks as $b) {
            // Construction Eval
            $lStmt = $pdo->prepare("SELECT construction_status FROM block_levels WHERE block_id = ?");
            $lStmt->execute([$b['id']]);
            $levels = $lStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($levels)) {
                $allConstComplete = false;
            } else {
                foreach ($levels as $l) {
                    if ($l['construction_status'] === 'In Progress') $anyConstInProgress = true;
                    if (!in_array($l['construction_status'], ['Complete', 'NA'])) $allConstComplete = false;
                }
            }

            // Finishes Eval
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

            // Post-Const Eval
            if (!in_array($b['compliance_submitted'], ['Yes', 'NA'])) $allCompSubmitted = false;
            if (!in_array($b['compliance_certified'], ['Yes', 'NA'])) $allCompCertified = false;
            if (!in_array($b['condominium_formed'], ['Yes', 'NA'])) $allCondoFormed = false;
            if (!in_array($b['cp_meters_installed'], ['Yes', 'NA'])) $allCpMeters = false;
        }
    }

    // Capital Projects automatically bypass Compliance & Condo logic
    if ($isCapital) {
        $allCompSubmitted = true; $allCompCertified = true;
        $allCondoFormed = true; $allCpMeters = true;
    }

    // ==========================================
    // EXCEL WATERFALL STAGE DETERMINATION
    // ==========================================
    
    // Safety Override: If a PM manually started execution despite a pending permit.
    $hasPhysicalOverride = $anyConstInProgress || (!empty($blocks) && $allConstComplete) || $constClearance ||
                           $demoStatus === 'In Progress' || $demoStatus === 'Complete' || $demoClearance ||
                           $excStatus === 'In Progress' || $excStatus === 'Complete' || $excClearance;

    // 1. PRE-EXECUTION (If no decided permit, and physical execution hasn't somehow started)
    if (!$hasDecidedEndorsed && !$hasPhysicalOverride) {
        if ($hasPaNumbers) {
            return ($isTracking || $allTracking) ? 'Tracking' : 'Permit';
        }
        return ($isTracking) ? 'Tracking' : 'Feasibility';
    }

    // 2. POST-CONSTRUCTION
    if (!empty($blocks) && $allConstComplete && $hasPhysicalOverride) {
        if ($needsFinishes && !$allFinComplete) return 'Finishes';
        if (!$allCompSubmitted) return $needsFinishes ? 'Finishes' : 'Construction';
        if (!$allCompCertified) return 'Compliance';
        if (!$allCondoFormed || !$allCpMeters) return 'Condominium';
        return 'Handed Over';
    }

    // 3. ACTIVE EXECUTION
    if ($anyConstInProgress || $constClearance) {
        return 'Construction';
    }

    if ($excStatus === 'In Progress' || $excClearance) {
        return $excComplete ? 'Construction' : 'Excavation';
    }

    if ($demoStatus === 'In Progress' || $demoClearance) {
        return $demoComplete ? 'Excavation' : 'Demolition';
    }

    // 4. FALLBACK: MOBILISATION
    // Reaches here if Permit is Decided, but NO clearances or progress are active yet.
    if ($hasDecidedEndorsed) return 'Mobilisation';

    return 'Permit';
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
