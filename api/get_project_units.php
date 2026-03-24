<?php
require_once '../config.php';
require_once '../session-check.php';
require_once '../S3FileManager.php';

header('Content-Type: application/json');

$project_id = $_GET['project_id'] ?? 0;
$user_role = $_SESSION['role'];
$is_manager = in_array($user_role, ['admin', 'sales_manager', 'system_manager', 'director']);

if (!$project_id) {
    echo json_encode(['success' => false, 'message' => 'Project ID required']);
    exit;
}

try {
    $s3 = new S3FileManager();

    // 1. Fetch Cloudflare Media from Universal Vault
    $docStmt = $pdo->prepare("SELECT sub_category, title, file_path FROM project_documents WHERE project_id = ? AND category = 'Sales' ORDER BY created_at ASC");
    $docStmt->execute([$project_id]);
    $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $renders = [];
    $videos = [];
    $plans = []; 
    
    foreach ($docs as $d) {
        $url = $s3->getPresignedUrl($d['file_path'], '+60 minutes');
        if ($d['sub_category'] === 'Render (Image)') {
            $renders[] = $url;
        } elseif ($d['sub_category'] === 'Render (Video)') {
            $videos[] = $url;
        } elseif ($d['sub_category'] === 'Floor Plan') {
            // Extract the tagged floor level we created in step 1
            if (preg_match('/Level (.*)/i', $d['title'], $matches)) {
                $lvl = trim($matches[1]);
                $plans[$lvl] = $url;
            }
        }
    }

    // 2. Fetch Units
    $stmt = $pdo->prepare("SELECT id, unit_name, unit_type, floor_level, shell_price, finishes_price, internal_sqm, external_sqm, description, status, held_by_agent_id FROM sales_properties WHERE project_id = ? ORDER BY floor_level ASC, unit_type, unit_name");
    $stmt->execute([$project_id]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $html = '';
    if (empty($units)) {
        $html = '<div class="p-4 text-center text-light">No units found for this project.</div>';
    } else {
        $html .= '<div class="table-responsive px-2"><table class="table table-dark table-hover table-sm align-middle text-start" style="font-size: 0.85rem;">';
        $html .= '<thead style="border-bottom: 2px solid #495057;"><tr><th>Unit</th><th>SQM</th><th>Price</th><th class="text-center">Status</th></tr></thead><tbody>';

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
            
           // If Cloudflare has a plan for this specific floor, generate a modal button!
            $planBtn = '';
            $floorLvl = trim($u['floor_level']);
            if (isset($plans[$floorLvl])) {
                // Ensure the URL is safely escaped for Javascript
                $safeUrl = htmlspecialchars($plans[$floorLvl], ENT_QUOTES, 'UTF-8');
                $planBtn = "<button class='btn btn-sm btn-outline-info w-100 py-0 mb-1' style='font-size: 0.75rem;' onclick='openPlanModal(\"{$safeUrl}\")'><i class='fas fa-map'></i> View Plan</button>";
            }

            $html .= "<tr style='border-bottom: 1px solid #343a40;'>";
            $html .= "<td>
                        <div class='fw-bold text-light'><i class='fas {$icon} text-secondary me-1'></i>{$u['unit_name']} <span class='badge bg-secondary ms-1'>Lvl {$u['floor_level']}</span></div>
                        <div class='small text-muted' style='font-size: 0.7rem;'>{$u['description']}</div>
                      </td>";
            $html .= "<td><div class='text-light'>I: {$u['internal_sqm']}</div><div class='text-light'>E: {$u['external_sqm']}</div></td>";
            $html .= "<td><div class='fw-bold text-info'>{$price_str}</div></td>";
            $html .= "<td class='text-center'>{$planBtn}";

            if ($is_manager) {
                $statuses = ['Available', 'On Hold', 'Reserved', 'Sold - POS', 'Sold - Contract', 'Resale', 'BOM'];
                $html .= "<select class='form-select form-select-sm bg-dark text-light border-secondary mb-1' style='font-size: 0.75rem;' onchange='managerUpdateStatus({$u['id']}, this.value)'>";
                foreach ($statuses as $st) {
                    $selected = ($u['status'] === $st) ? 'selected' : '';
                    $html .= "<option value='{$st}' {$selected}>{$st}</option>";
                }
                $html .= "</select>";
            } else {
                $html .= "<div class='mb-1'><span class='badge {$badgeColor} w-100'>{$u['status']}</span></div>";
                if ($u['status'] === 'Available') {
                    $html .= "<button class='btn btn-sm btn-outline-warning w-100 py-0' style='font-size: 0.75rem;' onclick='holdProperty({$u['id']})'>Hold</button>";
                } elseif ($u['status'] === 'On Hold' && $u['held_by_agent_id'] == $_SESSION['user_id']) {
                     $html .= "<button class='btn btn-sm btn-outline-success w-100 py-0' style='font-size: 0.75rem;' onclick='requestReserve({$u['id']})'>Reserve</button>";
                }
            }
            $html .= "</td></tr>";
        }
        $html .= '</tbody></table></div>';
    }
    
    // Return HTML AND the Media links to populate the sidebar!
    echo json_encode([
        'success' => true, 
        'html' => $html,
        'media' => [
            'renders' => $renders,
            'videos' => $videos
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
