<?php
require_once '../config.php';
require_once '../session-check.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];
$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']);
$canManageFleet = in_array($role, ['admin', 'system_manager', 'plant_manager']);

// 1. Fetch Bookings (FIX: Drivers can now see unassigned jobs)
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

// 2. Create Driver (FIX: Automatically grants the database permission)
if ($action == 'save_driver' && $canManageFleet) {
    $email = trim($_POST['email']);
    $username = explode('@', $email)[0];
    $hashedPassword = password_hash(trim($_POST['pass']), PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
    $stmt->execute([$email, $username]);
    if ($stmt->fetch()) { echo "A user with this email or username already exists."; exit; }

    $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 'plant_driver', 'Yes')");
    $stmt->execute([trim($_POST['first']), trim($_POST['last']), $username, $email, $hashedPassword]);
    
    // NEW: Automatically grant the Plant Booking UI capability
    $newId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO user_capabilities (user_id, view_plant_bookings) VALUES (?, 1)")->execute([$newId]);

    echo "OK";
    exit;
}

// 3. Create Booking
if ($action == 'create_booking' && $isManager) {
    $stmt = $pdo->prepare("INSERT INTO plant_bookings (plant_id, driver_id, booking_type, project_id, client_name, location_lat, location_lng, booking_date, start_time, end_time, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['plant_id'], empty($_POST['driver_id']) ? null : $_POST['driver_id'], $_POST['booking_type'], empty($_POST['project_id']) ? null : $_POST['project_id'], $_POST['client_name'], empty($_POST['loc_lat']) ? null : $_POST['loc_lat'], empty($_POST['loc_lng']) ? null : $_POST['loc_lng'], $_POST['booking_date'], $_POST['start_time'], $_POST['end_time'], $userId]);
    echo "OK"; exit;
}

// 4. Update Existing Booking (NEW: For the Manager Edit feature)
if ($action == 'update_booking' && $isManager) {
    $stmt = $pdo->prepare("UPDATE plant_bookings SET plant_id=?, driver_id=?, booking_type=?, project_id=?, client_name=?, location_lat=?, location_lng=?, booking_date=?, start_time=?, end_time=? WHERE id=?");
    $stmt->execute([$_POST['plant_id'], empty($_POST['driver_id']) ? null : $_POST['driver_id'], $_POST['booking_type'], empty($_POST['project_id']) ? null : $_POST['project_id'], $_POST['client_name'], empty($_POST['loc_lat']) ? null : $_POST['loc_lat'], empty($_POST['loc_lng']) ? null : $_POST['loc_lng'], $_POST['booking_date'], $_POST['start_time'], $_POST['end_time'], $_POST['edit_id']]);
    echo "OK"; exit;
}

// 5. Cancel Booking (NEW: For the Manager Edit feature)
if ($action == 'cancel_booking' && $isManager) {
    $pdo->prepare("DELETE FROM plant_bookings WHERE id=?")->execute([$_POST['id']]);
    echo "OK"; exit;
}

if ($action == 'form_data') {
    $plants = $pdo->query("SELECT id, name, registration_plate FROM plants WHERE status='Active'")->fetchAll(PDO::FETCH_ASSOC);
    $drivers = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='plant_driver'")->fetchAll(PDO::FETCH_ASSOC);
    require_once '../user-functions.php';
    $projects = getAccessibleProjects($pdo, $userId); // <-- Uses your built-in security!
    echo json_encode(['plants' => $plants, 'drivers' => $drivers, 'projects' => $projects]);
    exit;
}

if ($action == 'fetch_bookings') {
    $query = "SELECT pb.*, p.name as plant_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id";
    if (!$isManager) { $query .= " WHERE pb.driver_id = $userId"; } // Drivers only see their own
    
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

if ($action == 'get_job') {
    $stmt = $pdo->prepare("SELECT pb.*, p.name as plant_name, prj.name as project_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id LEFT JOIN projects prj ON pb.project_id = prj.id WHERE pb.id = ?");
    $stmt->execute([$_GET['id']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    $job['location_text'] = $job['booking_type'] == 'in-house' ? "Project: " . $job['project_name'] : "External: " . $job['client_name'];
    echo json_encode($job);
    exit;
}

if ($action == 'punch_in') {
    $pdo->prepare("UPDATE plant_bookings SET status='In Progress', punch_in_time=NOW() WHERE id=?")->execute([$_GET['id']]);
    echo "OK";
    exit;
}

if ($action == 'punch_out_complete') {
    $bookingId = $_POST['id'];
    $stmt = $pdo->prepare("UPDATE plant_bookings SET status='Completed', punch_out_time=NOW(), client_rep_name=?, client_rep_id_card=?, signature_data=? WHERE id=?");
    $stmt->execute([$_POST['rep_name'], $_POST['rep_id'], $_POST['signature'], $bookingId]);

    // Construct Email for Accountant
    $domain = "https://" . $_SERVER['HTTP_HOST'];
    $invoiceLink = $domain . "/print_plant_invoice.php?booking_id=" . $bookingId;
    
    // Attempt to find Accountant user email
    $accStmt = $pdo->query("SELECT email FROM users WHERE role='accountant' LIMIT 1");
    $accEmail = $accStmt->fetchColumn() ?: 'accounts@yourdomain.com';

    $subject = "Plant Job Completed - Invoice Generation Required";
    $message = "A heavy plant job has been punched out and completed by the driver.\n\n";
    $message .= "Please click the link below to generate the automated Payment Request PDF:\n";
    $message .= $invoiceLink;
    $headers = "From: system@yourdomain.com";

    @mail($accEmail, $subject, $message, $headers); // Send email

    echo "OK";
    exit;
}
?>
