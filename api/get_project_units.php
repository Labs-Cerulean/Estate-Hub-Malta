<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

$project_id = $_GET['project_id'] ?? 0;

if (!$project_id) {
    echo json_encode(['success' => false, 'message' => 'Project ID required']);
    exit;
}

try {
    // Fetching the new upgraded columns!
    $stmt = $pdo->prepare("SELECT id, unit_name, unit_type, shell_price, finishes_price, internal_sqm, external_sqm, description, status, held_by_agent_id FROM sales_properties WHERE project_id = ? ORDER BY unit_type, unit_name");
    $stmt->execute([$project_id]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $html = '';
    
    if (empty($units)) {
        $html = '<div class="p-3 text-center text-muted">No units found for this project.</div>';
    } else {
        foreach ($units as $u) {
            // Calculate Total Price
            $total_price = floatval($u['shell_price']) + floatval($u['finishes_price']);
            $price_str = $total_price > 0 ? '€' . number_format($total_price, 2) : 'Price on Request';
            
            // Pick Icon
            $icon = 'fa-home';
            if($u['unit_type'] == 'garage') $icon = 'fa-car';
            if($u['unit_type'] == 'commercial') $icon = 'fa-store';
            
            // Badge Colors
            $badgeColor = 'bg-success';
            if($u['status'] == 'On Hold') $badgeColor = 'bg-warning text-dark';
            if(strpos($u['status'], 'Sold') !== false) $badgeColor = 'bg-danger';
            if(strpos($u['status'], 'Reserved') !== false) $badgeColor = 'bg-info text-dark';
            
            // Format Specifications
            $specs = "<div style='font-size: 0.8rem;'><span class='text-dark fw-bold'>Int:</span> {$u['internal_sqm']} sqm | <span class='text-dark fw-bold'>Ext:</span> {$u['external_sqm']} sqm</div><div class='mt-1 text-muted'>{$u['description']}</div>";

            $html .= "
            <div class='list-group-item p-3'>
                <div class='d-flex justify-content-between align-items-center mb-2'>
                    <h6 class='mb-0 fw-bold'><i class='fas {$icon} text-muted me-2'></i>{$u['unit_name']}</h6>
                    <span class='badge {$badgeColor}'>{$u['status']}</span>
                </div>
                <div class='text-muted small mb-3'>{$specs}</div>
                <div class='d-flex justify-content-between align-items-center'>
                    <span class='fw-bold text-primary'>{$price_str}</span>";
            
            // Action Buttons
            if ($u['status'] === 'Available') {
                $html .= "<button class='btn btn-sm btn-outline-warning rounded-pill px-3' onclick='holdProperty({$u['id']})'>Put on Hold</button>";
            } elseif ($u['status'] === 'On Hold' && $u['held_by_agent_id'] == $_SESSION['user_id']) {
                 $html .= "<button class='btn btn-sm btn-outline-success rounded-pill px-3' onclick='requestReserve({$u['id']})'>Reserve</button>";
            }
            
            $html .= "</div></div>";
        }
    }

    echo json_encode(['success' => true, 'html' => $html]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
