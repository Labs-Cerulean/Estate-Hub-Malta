<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

// Ensure only authorized roles can access
$allowed_roles = ['sales_manager', 'sales_agent', 'system_manager', 'director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Fetch projects that have sales properties, along with their coordinates
    // Assuming your `projects` table has latitude/longitude. If they are in `sales_properties`, adjust accordingly.
   $query = "
        SELECT 
            p.id as project_id, 
            p.name as project_name, 
            p.latitude, 
            p.longitude,
            COUNT(sp.id) as total_units,
            SUM(CASE WHEN sp.status = 'Available' THEN 1 ELSE 0 END) as available_units,
            SUM(CASE WHEN sp.status = 'On Hold' THEN 1 ELSE 0 END) as held_units,
            SUM(CASE WHEN sp.status LIKE 'Sold%' THEN 1 ELSE 0 END) as sold_units
        FROM projects p
        JOIN sales_properties sp ON p.id = sp.project_id
        GROUP BY p.id
    ";
    
    $stmt = $pdo->query($query);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $projects]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
