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
    $stmt = $pdo->prepare("SELECT id, unit_name, unit_type, shell_price, finishes_price, internal_sqm, external_sqm, description, status, held_by_agent_id FROM sales_properties WHERE project_id = ? ORDER BY unit_type, unit_name");
    $stmt->execute([$project_id]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $html = '';
    
    if (empty($units)) {
        $html = '<div class="p-4 text-center text-light">No units found for this project.</div>';
    } else {
        // Start a responsive, dark table
        $html .= '<div class="table-responsive px-2">';
        $html .= '<table class="table table-dark table-hover table-sm align-middle text-start" style="font-size: 0.85rem;">';
        $html .= '<thead style="border-bottom: 2px solid #495057;"><tr>
                    <th>Unit</th>
                    <th>SQM</th>
                    <th>Price</th>
                    <th class="text-center">Status / Action</th>
                  </tr></thead><tbody>';

        foreach ($units as $u) {
            $total_price = floatval($u['shell_price']) + floatval($u['finishes_price']);
            $price_str = $total_price > 0 ? '€' . number_format($total_price, 0) : 'POA';
            
            $icon = 'fa-home';
            if($u['unit_type'] == 'garage') $icon = 'fa-car';
            if($u['unit_type'] == 'commercial') $icon = 'fa-store';
            
            $badgeColor = 'bg-success';
            if($u['status'] == 'On Hold') $badgeColor = 'bg-warning text-dark';
            if(strpos($u['status'], 'Sold') !== false) $badgeColor = 'bg-danger';
            if(strpos($u['status'], 'Reserved') !== false) $badgeColor = 'bg-info text-dark';
            
            $html .= "<tr style='border-bottom: 1px solid #343a40;'>";
            
            // Col 1: Unit & Desc
            $html .= "<td>
                        <div class='fw-bold text-light'><i class='fas {$icon} text-secondary me-1'></i>{$u['unit_name']}</div>
                        <div class='small text-muted' style='font-size: 0.7rem;'>{$u['description']}</div>
                      </td>";
            
            // Col 2: SQM
            $html .= "<td>
                        <div class='text-light'>I: {$u['internal_sqm']}</div>
                        <div class='text-light'>E: {$u['external_sqm']}</div>
                      </td>";
            
            // Col 3: Price
            $html .= "<td><div class='fw-bold text-info'>{$price_str}</div></td>";
            
            // Col 4: Status Badge & Buttons
            $html .= "<td class='text-center'>
                        <div class='mb-1'><span class='badge {$badgeColor} w-100'>{$u['status']}</span></div>";
            
            if ($u['status'] === 'Available') {
                $html .= "<button class='btn btn-sm btn-outline-warning w-100 py-0' style='font-size: 0.75rem;' onclick='holdProperty({$u['id']})'>Hold</button>";
            } elseif ($u['status'] === 'On Hold' && $u['held_by_agent_id'] == $_SESSION['user_id']) {
                 $html .= "<button class='btn btn-sm btn-outline-success w-100 py-0' style='font-size: 0.75rem;' onclick='requestReserve({$u['id']})'>Reserve</button>";
            }
            
            $html .= "</td></tr>";
        }
        
        $html .= '</tbody></table></div>';
    }

    echo json_encode(['success' => true, 'html' => $html]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
