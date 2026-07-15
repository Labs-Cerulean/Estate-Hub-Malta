<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

$allowed_roles = ['admin', 'sales_manager', 'sales_agent', 'external_agent', 'director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized API Access']);
    exit;
}

try {
    $access = salesProjectAccessWhereClause($pdo, 'p');
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
        WHERE {$access['sql']}
        " . salesListingVisibilitySql($pdo, 'p') . "
        GROUP BY p.id
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($access['params']);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $projects]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
