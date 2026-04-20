<?php
require_once '../config.php';
require_once '../session-check.php';
require_once '../user-functions.php'; // Required for getAccessibleProjects()

// ==========================================
// 1. SETUP & AUTHORIZATION
// ==========================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Define capability levels
$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']);
$canManageFleet = in_array($role, ['admin', 'system_manager', 'plant_manager']);

// ==========================================
// 2. FLEET & DRIVER MANAGEMENT (Managers Only)
// ==========================================

// Fetch Clients for the Owner Dropdown
if ($action == 'get_clients' && $canManageFleet) {
    $clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($clients);
    exit;
}

// Fetch the Active Fleet List
if ($action == 'get_fleet' && $canManageFleet) {
    $fleet = $pdo->query("
        SELECT p.*, c.name as owner_name 
        FROM plants p 
        LEFT JOIN clients c ON p.developer_client_id = c.id 
        WHERE p.status = 'Active' 
        ORDER BY p.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($fleet);
    exit;
}

// Save a New Plant to the Database
if ($action == 'save_plant' && $canManageFleet) {
    $stmt = $pdo->prepare("INSERT INTO plants (name, registration_plate, developer_client_id, inhouse_rate, external_rate) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['name'],
        $_POST['reg'],
        $_POST['owner_id'],
        empty($_POST['rate_in']) ? 0.00 : $_POST['rate_in'],
        empty($_POST['rate_ext']) ? 0.00 : $_POST['rate_ext']
    ]);
    echo "OK";
    exit;
}

// Fetch Active Drivers for the List
if ($action == 'get_drivers' && $canManageFleet) {
    $drivers = $pdo->query("
        SELECT id, first_name, last_name, email 
        FROM users 
        WHERE role = 'plant_driver' AND is_active = 'Yes'
        ORDER BY first_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($drivers);
    exit;
}

// Create a new Driver User Account
if ($action == 'save_driver' && $canManageFleet) {
    $email = trim($_POST['email']);
    $username = explode('@', $email)[0]; // Generate simple username from email
    $hashedPassword = password_hash(trim($_POST['pass']), PASSWORD_DEFAULT);

    // Check if email or username already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
    $stmt->execute([$email, $username]);
    if ($stmt->fetch()) {
        echo "A user with this email or username already exists.";
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 'plant_driver', 'Yes')");
    $stmt->execute([
        trim($_POST['first']),
        trim($_POST['last']),
        $username,
        $email,
        $hashedPassword
    ]);
    
    // Automatically grant the Plant Booking UI capability
    $newId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO user_capabilities (user_id, view_plant_bookings) VALUES (?, 1)")->execute([$newId]);

    echo "OK";
    exit;
}


// ==========================================
// 3. BOOKING CREATION & CALENDAR
// ==========================================

// Fetch Form Data (Dropdowns for Create Booking)
if ($action == 'form_data') {
    $plants = $pdo->query("SELECT id, name, registration_plate FROM plants WHERE status='Active'")->fetchAll(PDO::FETCH_ASSOC);
    $drivers = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='plant_driver'")->fetchAll(PDO::FETCH_ASSOC);
    $projects = getAccessibleProjects($pdo, $userId); // Uses the core system security framework
    echo json_encode(['plants' => $plants, 'drivers' => $drivers, 'projects' => $projects]);
    exit;
}

// Fetch Calendar Bookings
if ($action == 'fetch_bookings') {
    $query = "SELECT pb.*, p.name as plant_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id";
    if (!$isManager) { 
        // Drivers see their own jobs OR unassigned jobs
        $query .= " WHERE (pb.driver_id = $userId OR pb.driver_id IS NULL OR pb.driver_id = 0)"; 
    }
    
    $bookings = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    $events = [];
    foreach ($bookings as $b) {
        $color = '#3b82f6'; // Pending (Blue)
        if ($b['status'] == 'In Progress') $color = '#f59e0b'; // Orange
        if ($b['status'] == 'Completed') $color = '#10b981'; // Green
        
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

// Create a New Booking
if ($action == 'create_booking' && $isManager) {
    $stmt = $pdo->prepare("INSERT INTO plant_bookings (plant_id, driver_id, booking_type, project_id, client_name, location_lat, location_lng, booking_date, start_time, end_time, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
        $userId
    ]);
    echo "OK";
    exit;
}

// Update Existing Booking
if ($action == 'update_booking' && $isManager) {
    $stmt = $pdo->prepare("UPDATE plant_bookings SET plant_id=?, driver_id=?, booking_type=?, project_id=?, client_name=?, location_lat=?, location_lng=?, booking_date=?, start_time=?, end_time=? WHERE id=?");
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
        $_POST['edit_id']
    ]);
    echo "OK"; 
    exit;
}

// Cancel Booking
if ($action == 'cancel_booking' && $isManager) {
    $pdo->prepare("DELETE FROM plant_bookings WHERE id=?")->execute([$_POST['id']]);
    echo "OK"; 
    exit;
}


// ==========================================
// 4. JOB EXECUTION (Drivers & Managers)
// ==========================================

// Get Individual Job Details (For the popup)
if ($action == 'get_job') {
    $stmt = $pdo->prepare("SELECT pb.*, p.name as plant_name, prj.name as project_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id LEFT JOIN projects prj ON pb.project_id = prj.id WHERE pb.id = ?");
    $stmt->execute([$_GET['id']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    $job['location_text'] = $job['booking_type'] == 'in-house' ? "Project: " . $job['project_name'] : "External: " . $job['client_name'];
    echo json_encode($job);
    exit;
}

// Punch In
// Punch In & Auto-Claim Job
if ($action == 'punch_in') {
    // We add driver_id=? to instantly assign the job to whoever clicked the button
    $pdo->prepare("UPDATE plant_bookings SET status='In Progress', punch_in_time=NOW(), driver_id=? WHERE id=?")->execute([$userId, $_GET['id']]);
    echo "OK";
    exit;
}

// Punch Out & Finalize Job
if ($action == 'punch_out_complete') {
    $bookingId = $_POST['id'];
    $stmt = $pdo->prepare("UPDATE plant_bookings SET status='Completed', punch_out_time=NOW(), client_rep_name=?, client_rep_id_card=?, signature_data=? WHERE id=?");
    $stmt->execute([$_POST['rep_name'], $_POST['rep_id'], $_POST['signature'], $bookingId]);

    // Construct Email for Accountant
    $domain = "https://" . $_SERVER['HTTP_HOST'];
    $invoiceLink = $domain . "/print_plant_invoice.php?booking_id=" . $bookingId;
    
    // Attempt to find Accountant user email
    $accStmt = $pdo->query("SELECT email FROM users WHERE role='accountant' AND is_active='Yes' LIMIT 1");
    $accEmail = $accStmt->fetchColumn() ?: 'accounts@yourdomain.com'; // Change fallback email if needed

    $subject = "Plant Job Completed - Invoice Generation Required";
    $message = "A heavy plant job has been punched out and completed by the driver.\n\n";
    $message .= "Please click the link below to generate the automated Payment Request PDF:\n";
    $message .= $invoiceLink;
    $headers = "From: system@yourdomain.com";

    @mail($accEmail, $subject, $message, $headers); // Fire off email

    echo "OK";
    exit;
}

    // ==========================================
// 5. BILLING & LEDGER (Accounts / Admin)
// ==========================================
$canViewLedger = in_array($role, ['admin', 'director', 'system_manager', 'accountant']);

// Save the Final Invoice Values from the RFP PDF
if ($action == 'save_invoice') {
    $stmt = $pdo->prepare("UPDATE plant_bookings SET final_hours=?, final_rate=?, payment_status='Invoiced' WHERE id=?");
    $stmt->execute([$_POST['hours'], $_POST['rate'], $_POST['booking_id']]);
    echo "OK";
    exit;
}

// Fetch the Billing Ledger
if ($action == 'get_ledger' && $canViewLedger) {
    $query = "
        SELECT pb.*, p.name as plant_name, p.registration_plate, 
               prj.name as project_name, c.name as client_dev_name 
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id
        LEFT JOIN projects prj ON pb.project_id = prj.id
        LEFT JOIN clients c ON p.developer_client_id = c.id
        ORDER BY pb.booking_date DESC
    ";
    $ledger = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($ledger);
    exit;
}

// Mark an Invoice as Settled/Paid
if ($action == 'mark_settled' && $canViewLedger) {
    $pdo->prepare("UPDATE plant_bookings SET payment_status='Settled' WHERE id=?")->execute([$_POST['id']]);
    echo "OK";
    exit;
}
?>
