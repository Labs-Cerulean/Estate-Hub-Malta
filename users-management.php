<?php
require_once 'init.php';
require_once 'session-check.php';

if (!isAdmin()) { header('Location: dashboard.php'); exit; }

$message = ''; $error = '';

// Ensure all users have a capabilities record and support the new Training Docs Access
$pdo->exec("INSERT IGNORE INTO user_capabilities (user_id) SELECT id FROM users");

// Core capability keys (single source of truth)
function umAllCapabilityKeys(): array {
    return [
        'view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_services', 'assign_actions',
        'manage_clients', 'manage_professionals', 'manage_users', 'manage_subcontractors',
        'view_subcontractor_accounts', 'manage_subcontractor_accounts',
        'view_mobilisation', 'view_projects', 'view_ohsa', 'view_works_sales', 'view_documentation', 'view_drawings',
        'view_property_sales', 'view_capital_projects', 'view_nav_subcontractors',
        'view_sales_demo_exc', 'manage_sales_demo_exc', 'view_sales_const', 'manage_sales_const',
        'view_sales_finishes', 'manage_sales_finishes', 'view_sales_ohsa', 'manage_sales_ohsa', 'approve_quotes',
        'view_plant_bookings', 'manage_plant_fleet', 'view_plant_ledger', 'view_all_projects', 'edit_project_schedule',
    ];
}

function umGetRoleDefaultCapabilities(): array {
    return [
        'admin' => ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_project_schedule', 'edit_services', 'assign_actions', 'manage_clients', 'manage_professionals', 'manage_users', 'manage_subcontractors', 'view_subcontractor_accounts', 'manage_subcontractor_accounts', 'view_projects', 'view_all_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_drawings', 'view_works_sales', 'view_property_sales', 'view_capital_projects', 'view_nav_subcontractors', 'view_sales_demo_exc', 'manage_sales_demo_exc', 'view_sales_const', 'manage_sales_const', 'view_sales_finishes', 'manage_sales_finishes', 'view_sales_ohsa', 'manage_sales_ohsa', 'approve_quotes', 'view_plant_bookings', 'manage_plant_fleet', 'view_plant_ledger'],
        'director' => ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_project_schedule', 'edit_services', 'assign_actions', 'manage_professionals', 'manage_subcontractors', 'view_projects', 'view_all_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_drawings', 'view_works_sales', 'view_property_sales', 'view_capital_projects', 'view_sales_demo_exc', 'manage_sales_demo_exc', 'view_sales_const', 'manage_sales_const', 'view_sales_finishes', 'manage_sales_finishes', 'view_sales_ohsa', 'manage_sales_ohsa', 'approve_quotes'],
        'system_manager' => ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_project_schedule', 'edit_services', 'assign_actions', 'manage_professionals', 'manage_subcontractors', 'view_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_drawings', 'view_plant_bookings', 'manage_plant_fleet', 'view_plant_ledger'],
        'project_manager' => ['update_project_status', 'edit_project_schedule', 'assign_actions', 'view_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_drawings'],
        'accountant' => ['assign_actions', 'view_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_works_sales', 'view_capital_projects', 'view_subcontractor_accounts', 'view_nav_subcontractors', 'view_sales_demo_exc', 'view_sales_const', 'view_sales_finishes', 'view_sales_ohsa'],
        'architect' => ['view_tracking', 'assign_actions', 'view_projects', 'view_mobilisation', 'view_drawings', 'view_documentation'],
        'structural_engineer' => ['view_tracking', 'assign_actions', 'view_projects', 'view_mobilisation', 'view_drawings', 'view_documentation'],
        'services_engineer' => ['edit_services', 'assign_actions', 'view_projects', 'view_mobilisation', 'view_drawings', 'view_documentation'],
        'site_technical_officer' => ['assign_actions', 'view_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_drawings'],
        'quality_controller' => ['update_project_status', 'assign_actions', 'view_projects', 'view_mobilisation'],
        'pmo_staff' => ['manage_subcontractors', 'assign_actions', 'view_projects', 'view_mobilisation', 'view_documentation'],
        'ohsa_rep' => ['assign_actions', 'view_projects', 'view_ohsa', 'view_works_sales', 'view_sales_ohsa', 'manage_sales_ohsa'],
        'subcontractor' => ['assign_actions', 'view_projects', 'view_drawings'],
        'sales_manager' => ['view_projects', 'add_project', 'edit_project_details', 'view_property_sales', 'view_sales_demo_exc', 'manage_sales_demo_exc', 'view_sales_const', 'manage_sales_const', 'view_sales_finishes', 'manage_sales_finishes', 'view_sales_ohsa', 'manage_sales_ohsa'],
        'sales_agent' => ['view_property_sales'],
        'external_agent' => ['view_property_sales'],
        'condominium_agent' => [], 'end_customer' => [], 'viewer' => ['view_projects'],
        'legal_representative' => ['view_projects'],
        'plant_manager' => ['view_plant_bookings'],
        'plant_driver' => ['view_plant_bookings'],
    ];
}

function umCapsFromPost(): array {
    $caps = [];
    foreach (umAllCapabilityKeys() as $key) {
        $caps[$key] = isset($_POST[$key]) ? 1 : 0;
    }
    return $caps;
}

function umCapsFromRole(string $role): array {
    $caps = array_fill_keys(umAllCapabilityKeys(), 0);
    foreach (umGetRoleDefaultCapabilities()[$role] ?? [] as $cap) {
        if (array_key_exists($cap, $caps)) {
            $caps[$cap] = 1;
        }
    }
    return $caps;
}

function umBuildCapabilitiesUpsertSql(): string {
    $keys = umAllCapabilityKeys();
    $cols = implode(', ', $keys);
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $updates = implode(', ', array_map(fn($k) => "$k=VALUES($k)", $keys));
    return "INSERT INTO user_capabilities (user_id, $cols) VALUES (?, $placeholders) ON DUPLICATE KEY UPDATE $updates";
}

function umExecuteCapabilitiesUpsert(PDO $pdo, int $userId, array $caps): void {
    $stmt = $pdo->prepare(umBuildCapabilitiesUpsertSql());
    $params = array_values($caps);
    array_unshift($params, $userId);
    $stmt->execute($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');

        if (empty($username) || empty($password)) {
            $error = 'Username and password are required';
        } else {
            $policyError = validatePasswordStrength($password, [
                'username' => $username,
                'email' => $email,
            ]);
            if ($policyError !== null) {
                $error = $policyError;
            } else {
            try {
                $pdo->beginTransaction();
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, ?, 'Yes')");
                $stmt->execute([$first_name, $last_name, $username, $email, $hash, $role]);
                $newId = $pdo->lastInsertId();

                $caps = umCapsFromRole($role);
                umExecuteCapabilitiesUpsert($pdo, (int)$newId, $caps);

                $pdo->commit();
                $message = 'User created successfully! Select them from the list to fine-tune permissions and project access.';
            } catch (PDOException $e) { 
                $pdo->rollBack(); 
                $error = 'Error: ' . $e->getMessage(); 
            }
            }
        }
    }
    
    elseif ($action === 'update_user') {
        $userId = $_POST['user_id'];
        $role = $_POST['role'] ?? 'viewer';
        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            $error = 'Username is required.';
        } else {
        $architectFirmId = !empty($_POST['architect_firm_id']) ? $_POST['architect_firm_id'] : null;
        $structuralFirmId = !empty($_POST['structural_firm_id']) ? $_POST['structural_firm_id'] : null;

        // 4-Tier Document Vault Permissions (Now Including Training)
        $doc_bca = isset($_POST['doc_bca']) ? (int)$_POST['doc_bca'] : 0;
        $doc_ohsa = isset($_POST['doc_ohsa']) ? (int)$_POST['doc_ohsa'] : 0;
        $doc_drawings = isset($_POST['doc_drawings']) ? (int)$_POST['doc_drawings'] : 0;
        $doc_engineering = isset($_POST['doc_engineering']) ? (int)$_POST['doc_engineering'] : 0;
        $doc_commercial = isset($_POST['doc_commercial']) ? (int)$_POST['doc_commercial'] : 0;
        $doc_sales = isset($_POST['doc_sales']) ? (int)$_POST['doc_sales'] : 0;
        $doc_training = isset($_POST['doc_training']) ? (int)$_POST['doc_training'] : 0;

        $caps = umCapsFromPost();

        try {
            $pdo->beginTransaction();
            $stmt1 = $pdo->prepare("UPDATE users SET username=?, email=?, first_name=?, last_name=?, phone=?, role=?, is_active=?, assigned_architect_firm_id=?, assigned_structural_firm_id=?, doc_bca=?, doc_ohsa=?, doc_drawings=?, doc_engineering=?, doc_commercial=?, doc_sales=?, doc_training=? WHERE id=?");
            $stmt1->execute([$username, $_POST['email'], $_POST['first_name'], $_POST['last_name'], $_POST['phone'], $role, $_POST['is_active'], $architectFirmId, $structuralFirmId, $doc_bca, $doc_ohsa, $doc_drawings, $doc_engineering, $doc_commercial, $doc_sales, $doc_training, $userId]);
            
            if (!empty($_POST['new_password'])) {
                $hashStmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $hashStmt->execute([(int)$userId]);
                $existingHash = $hashStmt->fetchColumn();
                $policyError = validatePasswordStrength((string)$_POST['new_password'], [
                    'username' => $username,
                    'email' => trim((string)($_POST['email'] ?? '')),
                    'current_hash' => is_string($existingHash) ? $existingHash : null,
                ]);
                if ($policyError !== null) {
                    throw new InvalidArgumentException($policyError);
                }
                $pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$pass, $userId]);
            }

            umExecuteCapabilitiesUpsert($pdo, (int)$userId, $caps);
            
            $pdo->commit();
            $message = 'User profile & permissions updated successfully!';
        } catch (InvalidArgumentException $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        } catch (PDOException $e) { 
            $pdo->rollBack(); 
            $error = 'Update Error: ' . $e->getMessage(); 
        }
        }
    }

    // Access Logic Level 2 & 3
    elseif ($action === 'assign_client') { if ($_POST['user_id'] && $_POST['client_id']) if (assignUserToClient($pdo, $_POST['user_id'], $_POST['client_id'])) $message = 'Assigned!'; }
    elseif ($action === 'assign_all_clients') { if ($_POST['user_id']) { $all = $pdo->query("SELECT id FROM clients")->fetchAll(PDO::FETCH_COLUMN); foreach ($all as $cid) assignUserToClient($pdo, $_POST['user_id'], $cid); $message = "Assigned all clients."; } }
    elseif ($action === 'remove_all_clients') { if ($_POST['user_id']) { $pdo->prepare("DELETE FROM user_client_access WHERE user_id = ?")->execute([$_POST['user_id']]); $message = "Removed all."; } }
    elseif ($action === 'remove_client') { if ($_POST['user_id'] && $_POST['client_id']) { removeUserFromClient($pdo, $_POST['user_id'], $_POST['client_id']); $message = 'Removed.'; } }
    elseif ($action === 'exclude_project') { if ($_POST['user_id'] && $_POST['project_id']) { excludeProjectFromUser($pdo, $_POST['user_id'], $_POST['project_id']); $message = 'Excluded.'; } }
    elseif ($action === 'restore_project') { if ($_POST['user_id'] && $_POST['project_id']) { removeProjectExclusion($pdo, $_POST['user_id'], $_POST['project_id']); $message = 'Restored.'; } }
    elseif ($action === 'assign_project') { if ($_POST['user_id'] && $_POST['project_id']) { assignUserToProject($pdo, $_POST['user_id'], $_POST['project_id']); $message = 'Assigned explicitly.'; } }
    elseif ($action === 'remove_assigned_project') { if ($_POST['user_id'] && $_POST['project_id']) { removeUserFromProject($pdo, $_POST['user_id'], $_POST['project_id']); $message = 'Access removed.'; } }
}

$users = getAllUsers($pdo);
$clients = $pdo->query("SELECT id, name, type FROM clients ORDER BY name")->fetchAll();
$allProjectsDb = $pdo->query("SELECT p.id, p.name, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id ORDER BY p.name ASC")->fetchAll();
$firms = getAllFirms($pdo);
$architectFirms = []; $structuralFirms = [];
foreach ($firms['architects'] as $firmName) { if ($pid = getProfessionalIdByFirm($pdo, $firmName, 'architect')) $architectFirms[$pid] = $firmName; }
foreach ($firms['structural_engineers'] as $firmName) { if ($pid = getProfessionalIdByFirm($pdo, $firmName, 'structural_engineer')) $structuralFirms[$pid] = $firmName; }

$selectedUser = null; $userClients = []; $userExcludedProjects = []; $userAccessibleProjects = []; $userSpecificallyAssignedProjects = [];
if (!empty($_GET['user_id'])) {
    $stmt = $pdo->prepare("SELECT u.*, uc.* FROM users u LEFT JOIN user_capabilities uc ON u.id = uc.user_id WHERE u.id = ?");
    $stmt->execute([$_GET['user_id']]);
    $selectedUser = $stmt->fetch();
    if ($selectedUser) {
        $userClients = getUserClients($pdo, $selectedUser['id']);
        $userExcludedProjects = getUserExcludedProjects($pdo, $selectedUser['id']);
        $userAccessibleProjects = getAccessibleProjects($pdo, $selectedUser['id']);
        $userSpecificallyAssignedProjects = getUserAssignedProjects($pdo, $selectedUser['id']);
    }
}

$rolesList = [
    'admin', 'director', 'system_manager', 'project_manager', 'accountant', 'architect', 'structural_engineer', 
    'services_engineer', 'quality_controller', 'pmo_staff', 'ohsa_rep', 
    'site_technical_officer', 'subcontractor', 'condominium_agent', 
    'sales_manager', 'sales_agent', 'external_agent', 'end_customer', 'viewer', 'legal_representative',
    'plant_manager', 'plant_driver'
];

$totalUsers = count($users);
$activeUserCount = count(array_filter($users, fn($u) => ($u['is_active'] ?? '') === 'Yes'));
$selectedUserId = (int)($_GET['user_id'] ?? 0);

function umRoleLabel(string $role): string {
    return ucwords(str_replace('_', ' ', $role));
}

function umRoleBadgeClass(string $role): string {
    static $map = [
        'admin' => 'role-admin', 'director' => 'role-director', 'system_manager' => 'role-manager',
        'project_manager' => 'role-manager', 'architect' => 'role-architect', 'structural_engineer' => 'role-architect',
        'services_engineer' => 'role-services_engineer', 'viewer' => 'role-viewer',
    ];
    return $map[$role] ?? 'role-viewer';
}

$capSections = [
    'Action Permissions' => [
        ['view_tracking', 'View Tracking Stage'],
        ['add_project', 'Create New Projects'],
        ['edit_project_details', 'Edit Project Details'],
        ['update_project_status', 'Execution Checklists'],
        ['edit_project_schedule', 'Edit Delivery Schedule'],
        ['view_all_projects', 'View All Project Stages'],
        ['edit_services', 'Services & Utilities'],
        ['assign_actions', 'Assign Actions'],
        ['manage_clients', 'Manage Clients'],
        ['manage_professionals', 'Manage Professionals'],
        ['manage_subcontractors', 'Manage Subcontractors'],
        ['manage_users', 'Manage Users'],
        ['view_subcontractor_accounts', 'View Subcon. Accounts'],
        ['manage_subcontractor_accounts', 'Manage Subcon. Accounts'],
    ],
    'Work Sales & Commercial' => [
        ['view_works_sales', 'Works Sales Hub (menu access)'],
        ['view_sales_demo_exc', 'View Demo & Exc Quotes'],
        ['manage_sales_demo_exc', 'Manage Demo & Exc Quotes', 'manage'],
        ['view_sales_const', 'View Construction Quotes'],
        ['manage_sales_const', 'Manage Construction Quotes', 'manage'],
        ['view_sales_finishes', 'View Finishes Quotes'],
        ['manage_sales_finishes', 'Manage Finishes Quotes', 'manage'],
        ['view_sales_ohsa', 'View OHSA / Health & Safety Quotes', 'ohsa'],
        ['manage_sales_ohsa', 'Manage OHSA / Health & Safety Quotes', 'ohsa-manage'],
        ['approve_quotes', 'Approve Commercial Quotes (Bypass)', 'approve'],
    ],
    'Menu Navigation' => [
        ['view_projects', 'Projects'],
        ['view_mobilisation', 'Mobilisation'],
        ['view_ohsa', 'OHSA'],
        ['view_documentation', 'Documentation'],
        ['view_drawings', 'Drawings'],
        ['view_property_sales', 'Property Sales'],
        ['view_capital_projects', 'Capital Projects'],
        ['view_nav_subcontractors', 'Subcon. Accounts'],
        ['view_plant_bookings', 'Plant Bookings Hub', 'plant'],
        ['manage_plant_fleet', 'Manage Fleet (Admin)', 'plant'],
        ['view_plant_ledger', 'View Ledger (Admin)', 'plant'],
    ],
];

$docVaultFields = [
    ['doc_bca', 'BCA Documents'],
    ['doc_engineering', 'Engineering (ARMS, PA)'],
    ['doc_ohsa', 'OHSA Documents'],
    ['doc_drawings', 'Drawings & Plans'],
    ['doc_commercial', 'Commercial Docs'],
    ['doc_sales', 'Sales Docs (Pricing/Renders)'],
    ['doc_training', 'Training & Company HR Docs', true],
];

$pageTitle = 'User Management';
require_once 'header.php';
?>

<style>
.custom-modal { display: none; position: fixed; z-index: 9999; inset: 0; overflow: auto; background: rgba(0,0,0,0.75); backdrop-filter: blur(4px); }
.custom-modal-content { background: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 520px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); position: relative; }
.custom-close-btn { position: absolute; top: 1.25rem; right: 1.25rem; color: var(--text-muted); font-size: 1.5rem; font-weight: bold; cursor: pointer; line-height: 1; }
.custom-close-btn:hover { color: var(--text-primary); }

.um-page-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; }
.um-page-header h1 { margin: 0 0 0.25rem; }
.um-page-header p { margin: 0; color: var(--text-secondary); font-size: 0.9rem; }
.um-stats { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.um-stat-chip { padding: 0.35rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--text-secondary); }
.um-stat-chip strong { color: var(--primary-color); }

.um-layout { display: grid; grid-template-columns: 320px 1fr; gap: 1rem; align-items: start; min-height: calc(100vh - 220px); }
.um-sidebar, .um-main { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); overflow: hidden; display: flex; flex-direction: column; }
.um-sidebar { max-height: calc(100vh - 200px); position: sticky; top: 1rem; }
.um-main { max-height: calc(100vh - 200px); display: flex; flex-direction: column; }
.um-body { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
.um-main form { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.um-form-scroll { flex: 1; min-height: 0; overflow-y: auto; }
#um-panel-access { flex: 1; min-height: 0; overflow-y: auto; }

.um-sidebar-head { padding: 1rem; border-bottom: 1px solid var(--border-glass); background: rgba(255,255,255,0.02); }
.um-sidebar-head h2 { margin: 0 0 0.75rem; font-size: 1rem; }
.um-search { width: 100%; box-sizing: border-box; padding: 0.55rem 0.75rem; border-radius: 8px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary); font-size: 0.85rem; margin-bottom: 0.5rem; }
.um-filter-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
.um-filter-row select { width: 100%; padding: 0.45rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary); font-size: 0.78rem; }
.um-list-meta { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.5rem; }

.um-user-list { overflow-y: auto; flex: 1; padding: 0.5rem; }
.um-user-card { display: flex; align-items: center; gap: 0.65rem; padding: 0.65rem 0.75rem; border-radius: 8px; text-decoration: none; color: inherit; border: 1px solid transparent; margin-bottom: 4px; transition: background 0.15s, border-color 0.15s; }
.um-user-card:hover { background: rgba(99, 102, 241, 0.08); border-color: rgba(99, 102, 241, 0.2); }
.um-user-card.active { background: rgba(99, 102, 241, 0.15); border-color: var(--primary-color); }
.um-user-card.hidden { display: none; }
.um-avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), #8b5cf6); color: #fff; font-size: 0.72rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.um-user-info { flex: 1; min-width: 0; }
.um-user-name { font-weight: 600; font-size: 0.85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.um-user-login { font-size: 0.72rem; color: var(--text-muted); display: block; }
.um-user-card .role-badge { font-size: 0.62rem; margin-top: 3px; padding: 2px 6px; }
.um-status-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.um-status-dot.active { background: #10b981; box-shadow: 0 0 6px rgba(16,185,129,0.5); }
.um-status-dot.inactive { background: #ef4444; }

.um-empty-main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 3rem; text-align: center; color: var(--text-muted); }
.um-empty-main svg { opacity: 0.3; margin-bottom: 1rem; }

.um-main-head { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-glass); display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem; background: rgba(255,255,255,0.02); }
.um-main-identity { display: flex; align-items: center; gap: 1rem; }
.um-main-identity .um-avatar { width: 52px; height: 52px; font-size: 0.95rem; }
.um-main-title { margin: 0; font-size: 1.15rem; color: var(--text-primary); }
.um-main-sub { margin: 0.15rem 0 0; font-size: 0.8rem; color: var(--text-muted); }

.um-tabs { display: flex; gap: 0; border-bottom: 1px solid var(--border-glass); padding: 0 1rem; background: rgba(0,0,0,0.1); }
.um-tab { padding: 0.85rem 1.1rem; border: none; background: transparent; color: var(--text-muted); font-weight: 600; font-size: 0.85rem; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color 0.15s; }
.um-tab:hover { color: var(--text-primary); }
.um-tab.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }

.um-panel { display: none; padding: 1.25rem 1.5rem; }
.um-panel.active { display: block; }

.um-card { background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 10px; padding: 1rem 1.15rem; margin-bottom: 1rem; }
.um-card h4 { margin: 0 0 0.85rem; font-size: 0.9rem; color: var(--primary-color); }
.um-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.5rem 1rem; }
.um-card-grid .checkbox-item { font-size: 0.82rem; }
.um-label-manage { color: #f59e0b; }
.um-label-ohsa { color: #14b8a6; }
.um-label-ohsa-manage { color: #0d9488; font-weight: 600; }
.um-label-approve { color: #10b981; font-weight: 600; }
.um-label-plant { color: #ff9800; font-weight: 600; }

.um-accordion { border: 1px solid var(--border-glass); border-radius: 10px; margin-bottom: 0.75rem; overflow: hidden; }
.um-accordion summary { padding: 0.85rem 1rem; cursor: pointer; font-weight: 600; font-size: 0.88rem; color: var(--text-primary); background: rgba(255,255,255,0.03); list-style: none; display: flex; justify-content: space-between; align-items: center; }
.um-accordion summary::-webkit-details-marker { display: none; }
.um-accordion summary::after { content: '▾'; color: var(--text-muted); transition: transform 0.2s; }
.um-accordion[open] summary::after { transform: rotate(180deg); }
.um-accordion-body { padding: 1rem; border-top: 1px solid var(--border-glass); }

.um-role-bar { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 1rem; }
.um-role-bar select { flex: 1; min-width: 180px; }

.um-access-block { margin-bottom: 1.25rem; }
.um-access-block h3 { font-size: 0.95rem; margin: 0 0 0.75rem; }
.um-inline-form { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 0.75rem; }
.um-inline-form select { flex: 1; min-width: 200px; }
.um-mini-table { width: 100%; font-size: 0.82rem; border-collapse: collapse; }
.um-mini-table th, .um-mini-table td { padding: 0.5rem 0.65rem; text-align: left; border-bottom: 1px solid var(--border-glass); }
.um-mini-table th { color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase; }

.um-save-bar { flex-shrink: 0; padding: 0.85rem 1.5rem; border-top: 1px solid var(--border-glass); background: var(--bg-card); display: flex; justify-content: flex-end; gap: 0.75rem; }

@media (max-width: 1100px) {
    .um-layout { grid-template-columns: 1fr; }
    .um-sidebar, .um-main { max-height: none; position: static; }
    .um-sidebar { max-height: 360px; }
}
</style>

<div class="main-container">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="um-page-header">
        <div>
            <h1 class="page-title">System Users</h1>
            <p>Manage accounts, role defaults, module permissions, and project/client access levels.</p>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center;">
            <div class="um-stats">
                <span class="um-stat-chip"><strong><?= $totalUsers ?></strong> total</span>
                <span class="um-stat-chip"><strong><?= $activeUserCount ?></strong> active</span>
            </div>
            <button type="button" onclick="openCreateModal()" class="btn btn-primary">+ Add User</button>
        </div>
    </div>

    <div class="um-layout">
        <aside class="um-sidebar">
            <div class="um-sidebar-head">
                <h2>Directory</h2>
                <input type="search" id="umSearch" class="um-search" placeholder="Search name, username, email…" autocomplete="off">
                <div class="um-filter-row">
                    <select id="umRoleFilter"><option value="">All roles</option><?php foreach ($rolesList as $r): ?><option value="<?= $r ?>"><?= umRoleLabel($r) ?></option><?php endforeach; ?></select>
                    <select id="umStatusFilter"><option value="">All status</option><option value="Yes">Active</option><option value="No">Inactive</option></select>
                </div>
                <div class="um-list-meta"><span id="umVisibleCount"><?= $totalUsers ?></span> shown</div>
            </div>
            <div class="um-user-list" id="umUserList">
                <?php foreach ($users as $u):
                    $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                    $displayName = $fullName !== '' ? $fullName : $u['username'];
                    $searchBlob = strtolower($displayName . ' ' . $u['username'] . ' ' . ($u['email'] ?? '') . ' ' . umRoleLabel($u['role']));
                    $isActive = ($u['is_active'] ?? '') === 'Yes';
                ?>
                <a href="?user_id=<?= (int)$u['id'] ?>" class="um-user-card<?= $selectedUserId === (int)$u['id'] ? ' active' : '' ?>" data-search="<?= htmlspecialchars($searchBlob) ?>" data-role="<?= htmlspecialchars($u['role']) ?>" data-status="<?= htmlspecialchars($u['is_active'] ?? 'Yes') ?>">
                    <div class="um-avatar"><?= htmlspecialchars(getUserInitials($u['first_name'] ?? '', $u['last_name'] ?? '', $u['username'])) ?></div>
                    <div class="um-user-info">
                        <span class="um-user-name"><?= htmlspecialchars($displayName) ?></span>
                        <span class="um-user-login">@<?= htmlspecialchars($u['username']) ?></span>
                        <span class="role-badge <?= umRoleBadgeClass($u['role']) ?>"><?= umRoleLabel($u['role']) ?></span>
                    </div>
                    <span class="um-status-dot <?= $isActive ? 'active' : 'inactive' ?>" title="<?= $isActive ? 'Active' : 'Inactive' ?>"></span>
                </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="um-main">
            <?php if ($selectedUser): ?>
                    <div class="um-main-head">
                        <div class="um-main-identity">
                            <div class="um-avatar"><?= htmlspecialchars(getUserInitials($selectedUser['first_name'] ?? '', $selectedUser['last_name'] ?? '', $selectedUser['username'])) ?></div>
                            <div>
                                <h2 class="um-main-title"><?= htmlspecialchars(trim(($selectedUser['first_name'] ?? '') . ' ' . ($selectedUser['last_name'] ?? '')) ?: $selectedUser['username']) ?></h2>
                                <p class="um-main-sub">@<?= htmlspecialchars($selectedUser['username']) ?> · <?= htmlspecialchars($selectedUser['email'] ?? 'No email') ?></p>
                            </div>
                        </div>
                        <span class="role-badge <?= umRoleBadgeClass($selectedUser['role']) ?>"><?= umRoleLabel($selectedUser['role']) ?></span>
                    </div>

                    <div class="um-tabs" role="tablist">
                        <button type="button" class="um-tab active" data-tab="profile">Profile</button>
                        <button type="button" class="um-tab" data-tab="permissions">Permissions</button>
                        <button type="button" class="um-tab" data-tab="access">Data Access</button>
                    </div>

                    <div class="um-body">
                <form method="POST" id="editUserForm" onsubmit="return umPrepareSubmit(this)">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">

                    <div class="um-form-scroll">
                    <div class="um-panel active" id="um-panel-profile">
                        <div class="um-role-bar">
                            <label style="font-weight:600;font-size:0.85rem;">Role</label>
                            <select name="role" id="editRole" onchange="toggleAccessSections('edit')">
                                <?php foreach ($rolesList as $r): ?>
                                    <option value="<?= $r ?>" <?= $selectedUser['role'] === $r ? 'selected' : '' ?>><?= umRoleLabel($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="applyRoleDefaults('edit')">Load Role Defaults</button>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Username</label><input type="text" name="username" value="<?= htmlspecialchars($selectedUser['username']) ?>" required></div>
                            <div class="form-group"><label>Account Status</label><select name="is_active"><option value="Yes" <?= $selectedUser['is_active'] == 'Yes' ? 'selected' : '' ?>>Active</option><option value="No" <?= $selectedUser['is_active'] == 'No' ? 'selected' : '' ?>>Inactive</option></select></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>First Name</label><input type="text" name="first_name" value="<?= htmlspecialchars($selectedUser['first_name'] ?? '') ?>"></div>
                            <div class="form-group"><label>Last Name</label><input type="text" name="last_name" value="<?= htmlspecialchars($selectedUser['last_name'] ?? '') ?>"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($selectedUser['email']) ?>"></div>
                            <div class="form-group"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($selectedUser['phone'] ?? '') ?>"></div>
                        </div>
                        <div class="form-group"><label>New Password</label><input type="password" name="new_password" placeholder="Leave blank to keep current" minlength="<?= (int)passwordPolicyMinLength() ?>" maxlength="<?= (int)passwordPolicyMaxLength() ?>" autocomplete="new-password"><p style="font-size:0.75rem;color:var(--text-muted);margin:0.35rem 0 0;"><?= htmlspecialchars(passwordPolicyRequirementsText(), ENT_QUOTES, 'UTF-8') ?></p></div>

                        <div id="editLevel1Fields" class="um-card" style="display:none; border-color: rgba(139,92,246,0.35);">
                            <h4 style="color:#c4b5fd;">Firm Assignments (Level 1)</h4>
                            <div class="form-row">
                                <div class="form-group"><label>Architect Firm</label><select name="architect_firm_id"><option value="">— None —</option><?php foreach ($architectFirms as $id => $name): ?><option value="<?= $id ?>" <?= $selectedUser['assigned_architect_firm_id'] == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Structural Engineer Firm</label><select name="structural_firm_id"><option value="">— None —</option><?php foreach ($structuralFirms as $id => $name): ?><option value="<?= $id ?>" <?= $selectedUser['assigned_structural_firm_id'] == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
                            </div>
                        </div>
                    </div>

                    <div class="um-panel" id="um-panel-permissions">
                        <?php foreach ($capSections as $sectionTitle => $items): ?>
                        <details class="um-accordion"<?= $sectionTitle === 'Work Sales & Commercial' ? ' open' : ($sectionTitle === 'Action Permissions' ? ' open' : '') ?>>
                            <summary><?= htmlspecialchars($sectionTitle) ?></summary>
                            <div class="um-accordion-body">
                                <?php if ($sectionTitle === 'Work Sales & Commercial'): ?>
                                <p style="font-size:0.78rem;color:var(--text-muted);margin:0 0 0.75rem;line-height:1.45;">Grant <strong style="color:#14b8a6;">View/Manage OHSA Quotes</strong> for Health &amp; Safety commercial quotes in Works Sales. Users also need <strong>Works Sales Hub</strong> (or any granular quote view) to see the menu link.</p>
                                <?php endif; ?>
                                <div class="um-card-grid">
                                    <?php foreach ($items as $item):
                                        $capKey = $item[0]; $capLabel = $item[1]; $capStyle = $item[2] ?? '';
                                        $labelClass = match ($capStyle) {
                                            'manage' => 'um-label-manage',
                                            'approve' => 'um-label-approve',
                                            'plant' => 'um-label-plant',
                                            'ohsa' => 'um-label-ohsa',
                                            'ohsa-manage' => 'um-label-ohsa-manage',
                                            default => '',
                                        };
                                    ?>
                                    <label class="checkbox-item">
                                        <input type="checkbox" class="cap-check-edit" name="<?= $capKey ?>" id="edit_cap_<?= $capKey ?>" <?= !empty($selectedUser[$capKey]) ? 'checked' : '' ?>>
                                        <span class="<?= $labelClass ?>"><?= htmlspecialchars($capLabel) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </details>
                        <?php endforeach; ?>

                        <details class="um-accordion">
                            <summary>Document Vault Access</summary>
                            <div class="um-accordion-body">
                                <div class="um-card-grid">
                                    <?php foreach ($docVaultFields as $doc):
                                        $docKey = $doc[0]; $docLabel = $doc[1]; $isTraining = !empty($doc[2]);
                                        $val = (int)($selectedUser[$docKey] ?? 0);
                                    ?>
                                    <div class="form-group" style="margin:0;">
                                        <label style="font-size:0.78rem;<?= $isTraining ? 'color:#10b981;' : '' ?>"><?= htmlspecialchars($docLabel) ?></label>
                                        <select name="<?= $docKey ?>" id="edit_cap_<?= $docKey ?>" class="doc-select-edit" style="font-size:0.82rem;padding:0.35rem 0.5rem;">
                                            <?php for ($lvl = 0; $lvl <= 4; $lvl++): ?>
                                            <option value="<?= $lvl ?>" <?= $val === $lvl ? 'selected' : '' ?>><?= $lvl ?>. <?= ['No Access','View Online Only','View & Download','View, Download, Upload','Full Control (Inc. Delete)'][$lvl] ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </details>
                    </div>
                    </div>

                    <div class="um-save-bar" id="umSaveBar">
                        <button type="submit" class="btn btn-primary">Save Profile &amp; Permissions</button>
                    </div>
                </form>

                    <div class="um-panel" id="um-panel-access">
                        <div id="editLevel2Fields" style="display:none;">
                            <div class="um-access-block um-card">
                                <h3>Client Assignments (Level 2)</h3>
                                <div class="bulk-actions" style="display:flex;gap:0.5rem;margin-bottom:0.75rem;">
                                    <form method="POST" style="flex:1;" onsubmit="return confirm('Assign ALL clients?');"><input type="hidden" name="action" value="assign_all_clients"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>"><button type="submit" class="btn btn-primary btn-sm btn-bulk" style="width:100%;">Assign All Clients</button></form>
                                    <form method="POST" style="flex:1;" onsubmit="return confirm('Remove ALL clients?');"><input type="hidden" name="action" value="remove_all_clients"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>"><button type="submit" class="btn btn-danger btn-sm btn-bulk" style="width:100%;">Remove All</button></form>
                                </div>
                                <form method="POST" class="um-inline-form">
                                    <input type="hidden" name="action" value="assign_client"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                    <select name="client_id" required><option value="">Select client…</option><?php foreach ($clients as $client): ?><option value="<?= $client['id'] ?>"><?= htmlspecialchars($client['name']) ?> (<?= htmlspecialchars($client['type']) ?>)</option><?php endforeach; ?></select>
                                    <button type="submit" class="btn btn-sm">Assign</button>
                                </form>
                                <?php if (!empty($userClients)): ?>
                                <table class="um-mini-table"><thead><tr><th>Client</th><th>Type</th><th></th></tr></thead><tbody>
                                    <?php foreach ($userClients as $client): ?><tr><td><?= htmlspecialchars($client['name']) ?></td><td><?= htmlspecialchars($client['type']) ?></td><td style="text-align:right;"><form method="POST" style="display:inline;"><input type="hidden" name="action" value="remove_client"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>"><input type="hidden" name="client_id" value="<?= $client['id'] ?>"><button type="submit" class="btn btn-sm btn-danger">Remove</button></form></td></tr><?php endforeach; ?>
                                </tbody></table>
                                <?php else: ?><p style="color:var(--text-muted);font-size:0.85rem;margin:0;">No clients assigned yet.</p><?php endif; ?>
                            </div>

                            <div class="um-access-block um-card">
                                <h3>Project Exclusions</h3>
                                <form method="POST" class="um-inline-form">
                                    <input type="hidden" name="action" value="exclude_project"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                    <select name="project_id" required><option value="">Select project to exclude…</option><?php foreach ($userAccessibleProjects as $project): ?><option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?> (<?= htmlspecialchars($project['client_name']) ?>)</option><?php endforeach; ?></select>
                                    <button type="submit" class="btn btn-sm btn-warning">Exclude</button>
                                </form>
                                <?php if (!empty($userExcludedProjects)): ?>
                                <table class="um-mini-table"><thead><tr><th>Project</th><th>Client</th><th></th></tr></thead><tbody>
                                    <?php foreach ($userExcludedProjects as $project): ?><tr><td><?= htmlspecialchars($project['name']) ?></td><td><?= htmlspecialchars($project['client_name']) ?></td><td style="text-align:right;"><form method="POST" style="display:inline;"><input type="hidden" name="action" value="restore_project"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>"><input type="hidden" name="project_id" value="<?= $project['id'] ?>"><button type="submit" class="btn btn-sm btn-success">Restore</button></form></td></tr><?php endforeach; ?>
                                </tbody></table>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div id="editLevel3Fields" style="display:none;">
                            <div class="um-access-block um-card" style="border-color:rgba(59,130,246,0.35);">
                                <h3 style="color:var(--info);">Project Inclusions (Level 3)</h3>
                                <form method="POST" class="um-inline-form">
                                    <input type="hidden" name="action" value="assign_project"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                    <select name="project_id" required><option value="">Select project to assign…</option><?php foreach ($allProjectsDb as $project): ?><option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?> (<?= htmlspecialchars($project['client_name']) ?>)</option><?php endforeach; ?></select>
                                    <button type="submit" class="btn btn-sm btn-primary">Assign</button>
                                </form>
                                <?php if (!empty($userSpecificallyAssignedProjects)): ?>
                                <table class="um-mini-table"><thead><tr><th>Project</th><th>Client</th><th></th></tr></thead><tbody>
                                    <?php foreach ($userSpecificallyAssignedProjects as $project): ?><tr><td><?= htmlspecialchars($project['name']) ?></td><td><?= htmlspecialchars($project['client_name']) ?></td><td style="text-align:right;"><form method="POST" style="display:inline;"><input type="hidden" name="action" value="remove_assigned_project"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>"><input type="hidden" name="project_id" value="<?= $project['id'] ?>"><button type="submit" class="btn btn-sm btn-danger">Remove</button></form></td></tr><?php endforeach; ?>
                                </tbody></table>
                                <?php else: ?><p style="color:var(--text-muted);font-size:0.85rem;margin:0;">No explicit project assignments.</p><?php endif; ?>
                            </div>
                        </div>

                        <div id="umAccessPlaceholder" class="um-card" style="text-align:center;color:var(--text-muted);font-size:0.9rem;">
                            Data access controls appear here based on the selected role (client-level, firm-level, or project-level).
                        </div>
                    </div>
                    </div>
            <?php else: ?>
                <div class="um-empty-main">
                    <div>
                        <div style="font-size:3rem;margin-bottom:0.5rem;">👤</div>
                        <h3 style="margin:0 0 0.5rem;color:var(--text-primary);">Select a user</h3>
                        <p style="margin:0;max-width:320px;">Choose someone from the directory to edit their profile, permissions, and data access.</p>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<div id="createModal" class="custom-modal">
    <div class="custom-modal-content">
        <span class="custom-close-btn" onclick="closeCreateModal()">&times;</span>
        <h2 style="margin-top: 0; color: var(--primary-color);">Create New User</h2>
        <p style="color: var(--text-secondary); margin-bottom: 1.5rem; font-size: 0.9rem;">
            Create the basic profile here. Once created, you can edit their specific permissions and project access levels from the list.
        </p>
        
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label>First Name</label>
                    <input type="text" name="first_name" required>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>Last Name</label>
                    <input type="text" name="last_name" required>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label>Username (Login ID)</label>
                <input type="text" name="username" required>
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label>Email Address</label>
                <input type="email" name="email">
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label>System Role</label>
                <select name="role" required>
                    <?php foreach($rolesList as $r): ?>
                        <option value="<?= $r ?>"><?= ucwords(str_replace('_', ' ', $r)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label>Initial Password</label>
                <input type="password" name="password" placeholder="Required" required minlength="<?= (int)passwordPolicyMinLength() ?>" maxlength="<?= (int)passwordPolicyMaxLength() ?>" autocomplete="new-password">
                <p style="font-size:0.75rem;color:var(--text-muted);margin:0.35rem 0 0;"><?= htmlspecialchars(passwordPolicyRequirementsText(), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem;">Create User</button>
        </form>
    </div>
</div>

<script>
function openCreateModal() { document.getElementById('createModal').style.display = 'block'; }
function closeCreateModal() { document.getElementById('createModal').style.display = 'none'; }
window.onclick = function(event) { if (event.target == document.getElementById('createModal')) closeCreateModal(); }

const roleDefaults = <?= json_encode(umGetRoleDefaultCapabilities(), JSON_UNESCAPED_UNICODE) ?>;

const docDefaults = {
    'admin': { doc_bca: 4, doc_ohsa: 4, doc_drawings: 4, doc_engineering: 4, doc_commercial: 4, doc_sales: 4, doc_training: 4 },
    'director': { doc_bca: 4, doc_ohsa: 4, doc_drawings: 4, doc_engineering: 4, doc_commercial: 4, doc_sales: 4, doc_training: 4 },
    'system_manager': { doc_bca: 4, doc_ohsa: 4, doc_drawings: 4, doc_engineering: 4, doc_commercial: 0, doc_sales: 0, doc_training: 4 },
    'project_manager': { doc_bca: 3, doc_ohsa: 3, doc_drawings: 3, doc_engineering: 3, doc_commercial: 0, doc_sales: 0, doc_training: 3 },
    'accountant': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 4, doc_sales: 0, doc_training: 0 },
    'architect': { doc_bca: 2, doc_ohsa: 0, doc_drawings: 3, doc_engineering: 2, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'structural_engineer': { doc_bca: 2, doc_ohsa: 0, doc_drawings: 3, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'services_engineer': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 3, doc_engineering: 3, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'site_technical_officer': { doc_bca: 2, doc_ohsa: 3, doc_drawings: 2, doc_engineering: 2, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'quality_controller': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 2, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'pmo_staff': { doc_bca: 3, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 2 },
    'ohsa_rep': { doc_bca: 0, doc_ohsa: 3, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 3 },
    'subcontractor': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 2, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'sales_manager': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 4, doc_training: 0 },
    'sales_agent': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 2, doc_training: 0 },
    'external_agent': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 2, doc_training: 0 },
    'plant_manager': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'plant_driver': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 0 }
};

function toggleAccessSections(type) {
    const roleSelect = document.getElementById(type + 'Role');
    if (!roleSelect) return;
    const role = roleSelect.value;
    
    const level1Div = document.getElementById(type + 'Level1Fields');
    const level2Div = document.getElementById(type + 'Level2Fields');
    const level3Div = document.getElementById(type + 'Level3Fields');
    const placeholder = document.getElementById('umAccessPlaceholder');
    
    const level1Roles = ['architect', 'structural_engineer', 'site_technical_officer'];
    const level3Roles = ['subcontractor', 'condominium_agent', 'end_customer', 'project_manager'];
    const level0Roles = ['admin'];
    const pureIsolatedRoles = ['plant_driver'];
    
    if (level1Div) level1Div.style.display = level1Roles.includes(role) ? 'block' : 'none';
    
    const showL2 = !(level0Roles.includes(role) || level1Roles.includes(role) || level3Roles.includes(role) || pureIsolatedRoles.includes(role));
    if (level2Div) level2Div.style.display = showL2 ? 'block' : 'none';
    if (level3Div) level3Div.style.display = level3Roles.includes(role) ? 'block' : 'none';
    if (placeholder) placeholder.style.display = (showL2 || level3Roles.includes(role)) ? 'none' : 'block';
}

function umPrepareSubmit(form) {
    if (!form.checkValidity()) {
        const firstInvalid = form.querySelector(':invalid');
        if (firstInvalid) {
            const panel = firstInvalid.closest('.um-panel');
            if (panel) {
                const tabName = panel.id.replace('um-panel-', '');
                const tabButton = document.querySelector('.um-tab[data-tab="' + tabName + '"]');
                if (tabButton) {
                    tabButton.click();
                    setTimeout(() => form.reportValidity(), 50);
                    return false;
                }
            }
        }
        form.reportValidity();
        return false;
    }
    return true;
}

function initUmTabs() {
    function syncTabLayout(tabName) {
        const form = document.getElementById('editUserForm');
        const saveBar = document.getElementById('umSaveBar');
        const isAccess = tabName === 'access';
        if (form) form.style.display = isAccess ? 'none' : 'flex';
        if (saveBar) saveBar.style.display = isAccess ? 'none' : 'flex';
    }
    document.querySelectorAll('.um-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            const target = this.getAttribute('data-tab');
            document.querySelectorAll('.um-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.um-panel').forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            const panel = document.getElementById('um-panel-' + target);
            if (panel) panel.classList.add('active');
            syncTabLayout(target);
        });
    });
    const activeTab = document.querySelector('.um-tab.active');
    if (activeTab) syncTabLayout(activeTab.getAttribute('data-tab'));
}

function filterUmUserList() {
    const q = (document.getElementById('umSearch')?.value || '').toLowerCase().trim();
    const role = document.getElementById('umRoleFilter')?.value || '';
    const status = document.getElementById('umStatusFilter')?.value || '';
    let visible = 0;
    document.querySelectorAll('.um-user-card').forEach(card => {
        const matchSearch = !q || (card.getAttribute('data-search') || '').includes(q);
        const matchRole = !role || card.getAttribute('data-role') === role;
        const matchStatus = !status || card.getAttribute('data-status') === status;
        const show = matchSearch && matchRole && matchStatus;
        card.classList.toggle('hidden', !show);
        if (show) visible++;
    });
    const counter = document.getElementById('umVisibleCount');
    if (counter) counter.textContent = visible;
}

function applyRoleDefaults(type) {
    const roleSelect = document.getElementById(type + 'Role');
    if (!roleSelect) return;
    const role = roleSelect.value;
    
    const defaults = roleDefaults[role] || [];
    document.querySelectorAll('.cap-check-' + type).forEach(box => box.checked = false);
    defaults.forEach(cap => { const box = document.getElementById(type + '_cap_' + cap); if (box) box.checked = true; });

    document.querySelectorAll('.doc-select-' + type).forEach(sel => sel.value = '0');
    if (docDefaults[role]) {
        Object.keys(docDefaults[role]).forEach(key => {
            const sel = document.getElementById(type + '_cap_' + key);
            if (sel) sel.value = docDefaults[role][key];
        });
    }
}

function syncOhsaSalesCaps() {
    const manage = document.getElementById('edit_cap_manage_sales_ohsa');
    const view = document.getElementById('edit_cap_view_sales_ohsa');
    const hub = document.getElementById('edit_cap_view_works_sales');
    if (!manage || !view) return;
    if (manage.checked) {
        view.checked = true;
        if (hub) hub.checked = true;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    toggleAccessSections('edit');
    initUmTabs();
    const searchEl = document.getElementById('umSearch');
    const roleEl = document.getElementById('umRoleFilter');
    const statusEl = document.getElementById('umStatusFilter');
    if (searchEl) searchEl.addEventListener('input', filterUmUserList);
    if (roleEl) roleEl.addEventListener('change', filterUmUserList);
    if (statusEl) statusEl.addEventListener('change', filterUmUserList);
    const manageOhsa = document.getElementById('edit_cap_manage_sales_ohsa');
    if (manageOhsa) manageOhsa.addEventListener('change', syncOhsaSalesCaps);
});
</script>

<?php require_once 'footer.php'; ?>
