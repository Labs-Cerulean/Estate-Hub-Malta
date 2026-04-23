<?php
require_once '../config.php';
require_once '../session-check.php';
require_once '../user-functions.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

// --- CAPABILITY DEFINITIONS ---
$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']);
$canManageFleet = in_array($role, ['admin', 'system_manager', 'plant_manager']);
$canViewLedger = in_array($role, ['admin', 'director', 'system_manager', 'accountant']);

// ==========================================
// 1. FLEET & DRIVER MANAGEMENT
// ==========================================

if ($action == 'get_clients' && $canManageFleet) {
    echo json_encode($pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC)); 
    exit;
}

if ($action == 'get_fleet' && $canManageFleet) {
    $fleet = $pdo->query("SELECT p.*, c.name as owner_name FROM plants p LEFT JOIN clients c ON p.developer_client_id = c.id WHERE p.status = 'Active' ORDER BY p.name ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($fleet); 
    exit;
}

if ($action == 'save_plant' && $canManageFleet) {
    $stmt = $pdo->prepare("INSERT INTO plants (name, registration_plate, developer_client_id, inhouse_rate, external_rate, pricing_type, min_hours, min_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['name'], 
        $_POST['reg'], 
        $_POST['owner_id'],
        empty($_POST['rate_in']) ? 0.00 : $_POST['rate_in'],
        empty($_POST['rate_ext']) ? 0.00 : $_POST['rate_ext'],
        $_POST['pricing_type'],
        empty($_POST['min_hours']) ? 0 : $_POST['min_hours'],
        empty($_POST['min_price']) ? 0 : $_POST['min_price']
    ]);
    echo "OK"; 
    exit;
}

if ($action == 'get_drivers' && $canManageFleet) {
    echo json_encode($pdo->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'plant_driver' AND is_active = 'Yes' ORDER BY first_name ASC")->fetchAll(PDO::FETCH_ASSOC)); 
    exit;
}

if ($action == 'save_driver' && $canManageFleet) {
    $email = trim($_POST['email']);
    $username = explode('@', $email)[0];
    $hashedPassword = password_hash(trim($_POST['pass']), PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
    $stmt->execute([$email, $username]);
    if ($stmt->fetch()) { echo "A user with this email or username already exists."; exit; }

    $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 'plant_driver', 'Yes')");
    $stmt->execute([trim($_POST['first']), trim($_POST['last']), $username, $email, $hashedPassword]);
    
    $newId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO user_capabilities (user_id, view_plant_bookings) VALUES (?, 1)")->execute([$newId]);
    echo "OK"; 
    exit;
}

// ==========================================
// 2. BOOKING CREATION & CALENDAR
// ==========================================

if ($action == 'form_data') {
    $plants = $pdo->query("SELECT id, name, registration_plate FROM plants WHERE status='Active'")->fetchAll(PDO::FETCH_ASSOC);
    $drivers = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='plant_driver'")->fetchAll(PDO::FETCH_ASSOC);
    $projects = getAccessibleProjects($pdo, $userId);
    echo json_encode(['plants' => $plants, 'drivers' => $drivers, 'projects' => $projects]); 
    exit;
}

// Fetch specific project coordinates to auto-update the map
if ($action == 'get_project_location' && $isManager) {
    $stmt = $pdo->prepare("SELECT latitude, longitude FROM projects WHERE id = ?");
    $stmt->execute([$_GET['project_id']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'fetch_bookings') {
    $query = "SELECT pb.*, p.name as plant_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id";
    if (!$isManager) { 
        $query .= " WHERE (pb.driver_id = $userId OR pb.driver_id IS NULL OR pb.driver_id = 0)"; 
    }
    
    $bookings = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    $events = [];
    foreach ($bookings as $b) {
        $color = '#3b82f6';
        if ($b['status'] == 'In Progress') $color = '#f59e0b';
        if ($b['status'] == 'Completed') $color = '#10b981';
        $events[] = [
            'id' => $b['id'], 
            'title' => $b['plant_name'],
            'start' => $b['booking_date'] . 'T' . $b['start_time'], 
            'end' => $b['booking_date'] . 'T' . $b['end_time'],
            'backgroundColor' => $color, 
            'borderColor' => $color
        ];
    }
    echo json_encode($events); 
    exit;
}

if ($action == 'create_booking' && $isManager) {
    $stmt = $pdo->prepare("INSERT INTO plant_bookings (plant_id, driver_id, booking_type, project_id, client_name, location_lat, location_lng, booking_date, start_time, end_time, comments, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['plant_id'], 
        empty($_POST['driver_id']) ? null : $_POST['driver_id'], 
        $_POST['booking_type'], 
        empty($_POST['project_id']) ? null : $_POST['project_id'], 
        $_POST['client_name'], 
        empty($_POST['loc_lat']) ? null : $_POST['loc_lat'], 
        empty($_POST['loc_lng']) ? null : $_POST['loc_lng'], 
        $_POST['booking_date'], 
        $_POST['start_time'], 
        $_POST['end_time'], 
        $_POST['comments'], 
        $userId
    ]);
    echo "OK"; 
    exit;
}

if ($action == 'update_booking' && $isManager) {
    $stmt = $pdo->prepare("UPDATE plant_bookings SET plant_id=?, driver_id=?, booking_type=?, project_id=?, client_name=?, location_lat=?, location_lng=?, booking_date=?, start_time=?, end_time=?, comments=? WHERE id=?");
    $stmt->execute([
        $_POST['plant_id'], 
        empty($_POST['driver_id']) ? null : $_POST['driver_id'], 
        $_POST['booking_type'], 
        empty($_POST['project_id']) ? null : $_POST['project_id'], 
        $_POST['client_name'], 
        empty($_POST['loc_lat']) ? null : $_POST['loc_lat'], 
        empty($_POST['loc_lng']) ? null : $_POST['loc_lng'], 
        $_POST['booking_date'], 
        $_POST['start_time'], 
        $_POST['end_time'], 
        $_POST['comments'], 
        $_POST['edit_id']
    ]);
    echo "OK"; 
    exit;
}

if ($action == 'cancel_booking' && $isManager) {
    $pdo->prepare("DELETE FROM plant_bookings WHERE id=?")->execute([$_POST['id']]);
    echo "OK"; 
    exit;
}

// ==========================================
// 3. JOB EXECUTION
// ==========================================

if ($action == 'get_job') {
    $stmt = $pdo->prepare("SELECT pb.*, p.name as plant_name, prj.name as project_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id LEFT JOIN projects prj ON pb.project_id = prj.id WHERE pb.id = ?");
    $stmt->execute([$_GET['id']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    $job['location_text'] = $job['booking_type'] == 'in-house' ? "Project: " . $job['project_name'] : "External: " . $job['client_name'];
    echo json_encode($job); 
    exit;
}

if ($action == 'punch_in') {
    // Instantly claim the job for the driver who clicked Start Job
    $pdo->prepare("UPDATE plant_bookings SET status='In Progress', punch_in_time=NOW(), driver_id=? WHERE id=?")->execute([$userId, $_GET['id']]);
    echo "OK"; 
    exit;
}

if ($action == 'punch_out_complete') {
    $bookingId = $_POST['id'];
    $stmt = $pdo->prepare("UPDATE plant_bookings SET status='Completed', punch_out_time=NOW(), client_rep_name=?, client_rep_id_card=?, signature_data=? WHERE id=?");
    $stmt->execute([$_POST['rep_name'], $_POST['rep_id'], $_POST['signature'], $bookingId]);

    $domain = "https://" . $_SERVER['HTTP_HOST'];
    $invoiceLink = $domain . "/print_plant_invoice.php?booking_id=" . $bookingId;
    $accEmail = $pdo->query("SELECT email FROM users WHERE role='accountant' AND is_active='Yes' LIMIT 1")->fetchColumn() ?: 'accounts@yourdomain.com';
    @mail($accEmail, "Plant Job Completed", "A heavy plant job has been completed. Review RFP: " . $invoiceLink, "From: system@yourdomain.com");
    echo "OK"; 
    exit;
}

// ==========================================
// 4. BILLING & LEDGER 
// ==========================================

if ($action == 'save_invoice' && $canViewLedger) {
    // Saves the calculated Total Subtotal as well
    $stmt = $pdo->prepare("UPDATE plant_bookings SET final_hours=?, final_rate=?, final_subtotal=?, payment_status='Invoiced' WHERE id=?");
    $stmt->execute([$_POST['hours'], $_POST['rate'], $_POST['subtotal'], $_POST['booking_id']]);
    echo "OK"; 
    exit;
}

if ($action == 'get_ledger' && $canViewLedger) {
    $ledger = $pdo->query("
        SELECT pb.*, p.name as plant_name, prj.name as project_name, c.name as client_dev_name 
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        LEFT JOIN projects prj ON pb.project_id = prj.id 
        LEFT JOIN clients c ON p.developer_client_id = c.id 
        ORDER BY pb.booking_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($ledger); 
    exit;
}

if ($action == 'mark_settled' && $canViewLedger) {
    $pdo->prepare("UPDATE plant_bookings SET payment_status='Settled' WHERE id=?")->execute([$_POST['id']]);
    echo "OK"; 
    exit;
}
?>
