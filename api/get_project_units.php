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

    // Fetch Media Links
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

    // Fetch Units & Agent Names
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

            // Start Unit Card
            $html .= "<div class='card bg-dark border-secondary mb-3 shadow-sm' style='border-radius: 12px; overflow: hidden;'>";
            $html .= "<div class='card-body p-3'>";
            
            // --- HEADER: Title, Floor, Status ---
            $html .= "<div class='d-flex justify-content-between align-items-start mb-1'>
                        <div>
                            <h6 class='m-0 fw-bold text-light d-flex align-items-center gap-2'>
                                <i class='fas {$icon} text-secondary'></i> {$u['unit_name']}
                                <span class='badge bg-secondary' style='font-size: 0.65rem; font-weight: normal;'>Lvl {$u['floor_level']}</span>
                            </h6>
                            <div class='text-muted mt-1' style='font-size: 0.8rem;'>{$u['description']}</div>
                        </div>
                        <span class='badge {$badgeColor}' style='font-size:0.75rem; letter-spacing: 0.5px;'>{$u['status']}</span>
                      </div>";
            
            // --- AGENT TAG ---
            if ($u['held_by_agent_id'] && in_array($u['status'], ['On Hold', 'Reserved'])) {
                $html .= "<div class='text-warning fw-bold mb-2' style='font-size: 0.75rem;'><i class='fas fa-user-tag'></i> Agent: {$u['first_name']} {$u['last_name']}</div>";
            }

            // --- SPECS: Pill Style ---
            $html .= "<div class='d-flex gap-2 mb-3 mt-2'>
                        <div style='background: rgba(255,255,255,0.05); padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; color: #ccc;'>
                            <i class='fas fa-vector-square text-secondary me-1'></i> Int: <b class='text-light'>{$u['internal_sqm']}</b> sqm
                        </div>
                        <div style='background: rgba(255,255,255,0.05); padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; color: #ccc;'>
                            <i class='fas fa-leaf text-secondary me-1'></i> Ext: <b class='text-light'>{$u['external_sqm']}</b> sqm
                        </div>
                      </div>";

            // --- PRICING BLOCK: Dedicated Inset Box ---
            $finishState = ($u['finishes_price'] > 0) ? 'Semi-Finished' : 'Shell & Core';
            
            $html .= "<div id='price_disp_{$u['id']}' class='p-2 mb-3' style='background: rgba(0,0,0,0.3); border-radius: 8px; border: 1px solid rgba(255,255,255,0.05);'>
                        <div class='d-flex justify-content-between align-items-center'>
                            <div>
                                <div style='font-size: 0.75rem; color: #aaa; margin-bottom: 2px;'>Shell: <span class='text-light'>€" . number_format($u['shell_price'], 0) . "</span></div>
                                <div style='font-size: 0.75rem; color: #aaa;'>Finishes: <span class='text-light'>€" . number_format($u['finishes_price'], 0) . "</span> <span style='font-size: 0.65rem; color: #6c757d;'>({$finishState})</span></div>
                            </div>
                            <div class='text-end'>
                                <div style='font-size: 0.65rem; color: #888; text-transform: uppercase; letter-spacing: 0.5px;'>Total Price</div>
                                <div class='fw-bold text-info' style='font-size: 1.25rem; line-height: 1;'>€" . number_format($total_price, 0) . "</div>
                            </div>
                        </div>";
            
            // Edit Price Trigger (Manager Only)
            if ($is_manager) {
                $html .= "<div class='text-end mt-2 pt-2' style='border-top: 1px dashed rgba(255,255,255,0.1);'>
                            <button class='btn btn-link text-info p-0' style='font-size: 0.7rem; text-decoration: none;' onclick='togglePriceEdit({$u['id']})'><i class='fas fa-edit'></i> Edit Price Breakdown</button>
                          </div>";
            }
            $html .= "</div>";

            // --- PRICING EDIT FORM (Hidden by default) ---
            if ($is_manager) {
                $html .= "
                        <div id='price_edit_{$u['id']}' class='p-2 mb-3' style='display:none; background: rgba(13, 202, 240, 0.05); border-radius: 8px; border: 1px solid rgba(13, 202, 240, 0.2);'>
                            <div class='d-flex gap-2 mb-2'>
                                <div class='w-50'>
                                    <label class='form-label text-info' style='font-size: 0.65rem; margin-bottom: 2px; text-transform: uppercase;'>Shell Price (€)</label>
                                    <input type='number' id='inp_sh_{$u['id']}' class='form-control form-control-sm bg-dark text-light border-info' value='{$u['shell_price']}' style='font-size: 0.8rem;'>
                                </div>
                                <div class='w-50'>
                                    <label class='form-label text-info' style='font-size: 0.65rem; margin-bottom: 2px; text-transform: uppercase;'>Finishes Price (€)</label>
                                    <input type='number' id='inp_fn_{$u['id']}' class='form-control form-control-sm bg-dark text-light border-info' value='{$u['finishes_price']}' style='font-size: 0.8rem;'>
                                </div>
                            </div>
                            <div class='d-flex gap-2'>
                                <button class='btn btn-success btn-sm w-100 py-1' style='font-size: 0.75rem; border-radius: 6px;' onclick='savePrice({$u['id']})'><i class='fas fa-check'></i> Save Changes</button>
                                <button class='btn btn-outline-secondary btn-sm py-1 px-3' style='font-size: 0.75rem; border-radius: 6px;' onclick='togglePriceEdit({$u['id']})'><i class='fas fa-times'></i> Cancel</button>
                            </div>
                        </div>";
            }

            // --- ACTIONS ROW ---
            $planBtn = '';
            $floorLvl = trim($u['floor_level']);
            if (isset($plans[$floorLvl])) {
                $safeUrl = htmlspecialchars($plans[$floorLvl], ENT_QUOTES, 'UTF-8');
                $planBtn = "<button class='btn btn-sm btn-outline-info py-1 px-3 w-100 mt-2' style='font-size: 0.8rem; border-radius: 6px;' onclick='openPlanModal(\"{$safeUrl}\")'><i class='fas fa-map'></i> View Floor Plan</button>";
            }

            $html .= "<div class='actions-container'>";
            if ($is_manager) {
                $statuses = ['Available', 'On Hold', 'Reserved', 'Sold - POS', 'Sold - Contract', 'Resale', 'BOM'];
                $html .= "<select class='form-select form-select-sm bg-dark text-light border-secondary' style='font-size: 0.85rem; border-radius: 6px; padding: 6px 10px;' onchange='managerUpdateStatus({$u['id']}, this.value)'>";
                foreach ($statuses as $st) {
                    $selected = ($u['status'] === $st) ? 'selected' : '';
                    $html .= "<option value='{$st}' {$selected}>{$st}</option>";
                }
                $html .= "</select>";
            } else {
                if ($u['status'] === 'Available') {
                    $html .= "<button class='btn btn-sm btn-outline-warning py-1 px-3 w-100' style='font-size: 0.85rem; border-radius: 6px; padding: 6px 0;' onclick='holdProperty({$u['id']})'>Put on Hold</button>";
                } elseif ($u['status'] === 'On Hold' && $u['held_by_agent_id'] == $_SESSION['user_id']) {
                     $html .= "<button class='btn btn-sm btn-outline-success py-1 px-3 w-100' style='font-size: 0.85rem; border-radius: 6px; padding: 6px 0;' onclick='requestReserve({$u['id']})'>Reserve Unit</button>";
                }
            }
            
            $html .= $planBtn;
            $html .= "</div>"; // End Actions

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
