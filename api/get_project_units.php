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

    $docStmt = $pdo->prepare("SELECT sub_category, title, file_path FROM project_documents WHERE project_id = ? AND category = 'Sales' ORDER BY created_at ASC");
    $docStmt->execute([$project_id]);
    $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $renders = []; $videos = []; $plans = []; 
    foreach ($docs as $d) {
        $url = $s3->getPresignedUrl($d['file_path'], '+60 minutes');
        if ($d['sub_category'] === 'Render (Image)') $renders[] = $url;
        elseif ($d['sub_category'] === 'Render (Video)') $videos[] = $url;
        elseif ($d['sub_category'] === 'Floor Plan') {
            if (preg_match('/Level (.*)/i', $d['title'], $matches)) $plans[trim($matches[1])] = $url;
        }
    }

    $stmt = $pdo->prepare("
        SELECT sp.id, sp.unit_name, sp.unit_type, sp.floor_level, sp.shell_price, sp.finishes_price, sp.internal_sqm, sp.external_sqm, sp.description, sp.status, sp.held_by_agent_id, 
               u.first_name, u.last_name 
        FROM sales_properties sp 
        LEFT JOIN users u ON sp.held_by_agent_id = u.id 
        WHERE sp.project_id = ? 
        ORDER BY sp.floor_level ASC, sp.unit_type, sp.unit_name
    ");
    $stmt->execute([$project_id]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $html = '';
    if (empty($units)) {
        $html = '<div class="p-4 text-center text-light">No units found for this project.</div>';
    } else {
        $html .= '<div class="px-2 pb-4">';

        foreach ($units as $u) {
            $total_price = floatval($u['shell_price']) + floatval($u['finishes_price']);
            
            $icon = 'fa-home';
            if($u['unit_type'] == 'garage') $icon = 'fa-car';
            if($u['unit_type'] == 'commercial') $icon = 'fa-store';
            
            $badgeColor = 'bg-success';
            if($u['status'] == 'On Hold') $badgeColor = 'bg-warning text-dark';
            if(strpos($u['status'], 'Sold') !== false) $badgeColor = 'bg-danger';
            if(strpos($u['status'], 'Reserved') !== false) $badgeColor = 'bg-info text-dark';

            $agentTag = '';
            if ($u['held_by_agent_id'] && in_array($u['status'], ['On Hold', 'Reserved'])) {
                $agentTag = "<div class='text-warning fw-bold' style='font-size: 0.75rem;'><i class='fas fa-user-tag'></i> Agent: {$u['first_name']} {$u['last_name']}</div>";
            }

            $finishState = ($u['finishes_price'] > 0) ? 'Semi-Finished' : 'Shell & Core';

            $planBtn = '';
            $floorLvl = trim($u['floor_level']);
            if (isset($plans[$floorLvl])) {
                $safeUrl = htmlspecialchars($plans[$floorLvl], ENT_QUOTES, 'UTF-8');
                $planBtn = "<button class='btn btn-sm btn-outline-info py-1 px-3' style='font-size: 0.75rem; border-radius: 20px;' onclick='openPlanModal(\"{$safeUrl}\")'><i class='fas fa-map'></i> View Plan</button>";
            }

            // Start Unit Card
            $html .= "<div class='card bg-dark border-secondary mb-3 shadow-sm' style='border-radius: 12px;'>";
            $html .= "<div class='card-body p-3'>";
            
            // Row 1: Title, Level & Status Badge
            $html .= "<div class='d-flex justify-content-between align-items-center mb-2'>
                        <h6 class='m-0 fw-bold text-light'><i class='fas {$icon} text-secondary me-2'></i>{$u['unit_name']} <span class='badge bg-secondary ms-1' style='font-size:0.65rem;'>Lvl {$u['floor_level']}</span></h6>
                        <span class='badge {$badgeColor}' style='font-size:0.75rem;'>{$u['status']}</span>
                      </div>";
            
            // Row 2: Description & Agent
            $html .= "<div class='mb-2'>
                        <div class='text-muted' style='font-size: 0.8rem;'>{$u['description']}</div>
                        {$agentTag}
                      </div>";

            // Row 3: SQM Specs
            $html .= "<div class='d-flex gap-3 mb-3 pb-2 border-bottom border-secondary text-light' style='font-size: 0.8rem;'>
                        <div><i class='fas fa-vector-square text-secondary'></i> Int: <b>{$u['internal_sqm']}</b> sqm</div>
                        <div><i class='fas fa-leaf text-secondary'></i> Ext: <b>{$u['external_sqm']}</b> sqm</div>
                      </div>";

            // Row 4: Pricing & Actions Flexbox
            $html .= "<div class='d-flex justify-content-between align-items-end'>";
            
            // Pricing Block
            $html .= "<div id='price_disp_{$u['id']}'>
                        <div class='fw-bold text-info mb-1' style='font-size: 1.1rem;'>€" . number_format($total_price, 0) . "</div>
                        <div class='text-muted' style='font-size: 0.7rem; line-height: 1.2;'>
                            Shell: €" . number_format($u['shell_price'], 0) . "<br>
                            Finishes: €" . number_format($u['finishes_price'], 0) . " <strong class='text-light'>({$finishState})</strong>
                        </div>";
            if ($is_manager) {
                $html .= "<button class='btn btn-link text-info p-0 mt-1' style='font-size: 0.75rem; text-decoration: none;' onclick='togglePriceEdit({$u['id']})'><i class='fas fa-edit'></i> Edit Price</button>";
            }
            $html .= "</div>";

            // Inline Price Edit Block (Hidden by default)
            if ($is_manager) {
                $html .= "
                        <div id='price_edit_{$u['id']}' style='display:none; width: 140px;'>
                            <input type='number' id='inp_sh_{$u['id']}' class='form-control form-control-sm bg-dark text-light mb-1 border-secondary' value='{$u['shell_price']}' placeholder='Shell €' style='font-size: 0.75rem;'>
                            <input type='number' id='inp_fn_{$u['id']}' class='form-control form-control-sm bg-dark text-light mb-2 border-secondary' value='{$u['finishes_price']}' placeholder='Finishes €' style='font-size: 0.75rem;'>
                            <div class='d-flex gap-1'>
                                <button class='btn btn-success btn-sm w-100 py-1' style='font-size: 0.7rem;' onclick='savePrice({$u['id']})'><i class='fas fa-check'></i> Save</button>
                                <button class='btn btn-outline-secondary btn-sm py-1' style='font-size: 0.7rem;' onclick='togglePriceEdit({$u['id']})'><i class='fas fa-times'></i></button>
                            </div>
                        </div>";
            }

            // Action Buttons Block
            $html .= "<div class='text-end'>";
            if ($is_manager) {
                $statuses = ['Available', 'On Hold', 'Reserved', 'Sold - POS', 'Sold - Contract', 'Resale', 'BOM'];
                $html .= "<select class='form-select form-select-sm bg-dark text-light border-secondary mb-2' style='font-size: 0.75rem; min-width: 120px;' onchange='managerUpdateStatus({$u['id']}, this.value)'>";
                foreach ($statuses as $st) {
                    $selected = ($u['status'] === $st) ? 'selected' : '';
                    $html .= "<option value='{$st}' {$selected}>{$st}</option>";
                }
                $html .= "</select>";
            } else {
                if ($u['status'] === 'Available') {
                    $html .= "<button class='btn btn-sm btn-outline-warning py-1 px-3 mb-2 d-block w-100' style='font-size: 0.75rem; border-radius: 20px;' onclick='holdProperty({$u['id']})'>Put on Hold</button>";
                } elseif ($u['status'] === 'On Hold' && $u['held_by_agent_id'] == $_SESSION['user_id']) {
                     $html .= "<button class='btn btn-sm btn-outline-success py-1 px-3 mb-2 d-block w-100' style='font-size: 0.75rem; border-radius: 20px;' onclick='requestReserve({$u['id']})'>Reserve Unit</button>";
                }
            }
            $html .= $planBtn;
            $html .= "</div>";

            $html .= "</div>"; // End Flexbox
            $html .= "</div></div>"; // End Card
        }
        $html .= '</div>';
    }
    
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
