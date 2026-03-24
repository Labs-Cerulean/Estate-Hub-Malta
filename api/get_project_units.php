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

    // Fetch Media Links for the Sidebar Carousel
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

    // Fetch Units & Assigned Agent Names
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
            $status = trim($u['status']);
            
            $icon = 'fa-home';
            if($u['unit_type'] == 'garage') $icon = 'fa-car';
            if($u['unit_type'] == 'commercial') $icon = 'fa-store';
            
            // --- DYNAMIC COLOR THEME ENGINE ---
            $accentColor = '#10b981'; // Green (Available)
            $badgeBg = '#10b981';
            $badgeText = '#ffffff';

            if (strpos($status, 'Hold') !== false) {
                $accentColor = '#f59e0b'; // Amber
                $badgeBg = '#f59e0b';
                $badgeText = '#000000';
            } elseif (strpos($status, 'Reserved') !== false) {
                $accentColor = '#0ea5e9'; // Cyan
                $badgeBg = '#0ea5e9';
                $badgeText = '#000000';
            } elseif (strpos($status, 'Sold') !== false) {
                $accentColor = '#ef4444'; // Red
                $badgeBg = '#ef4444';
            } elseif (in_array($status, ['Resale', 'BOM'])) {
                $accentColor = '#8b5cf6'; // Purple
                $badgeBg = '#8b5cf6';
            }

            // --- AGENT TAG LOGIC ---
            $agentTag = '';
            if ($u['held_by_agent_id'] && in_array($status, ['On Hold', 'Reserved'])) {
                $verb = ($status === 'Reserved') ? 'Reserved by' : 'Held by';
                $agentTag = "<div class='mt-2 d-inline-flex align-items-center shadow-sm' style='background: rgba(245, 158, 11, 0.15); color: #fcd34d; font-size: 0.75rem; font-weight: 600; padding: 5px 12px; border-radius: 8px; border: 1px solid rgba(245, 158, 11, 0.3);'><i class='fas fa-user-circle me-2' style='font-size: 0.9rem;'></i> {$verb}: {$u['first_name']} {$u['last_name']}</div>";
            }

            $finishState = ($u['finishes_price'] > 0) ? 'Semi-Finished' : 'Shell & Core';

            // --- START BEAUTIFUL CARD (Added data-status for Quick Filters) ---
            $html .= "<div class='card mb-4 shadow unit-card' data-status='{$status}' style='background: #1e1e2d; border: none; border-left: 6px solid {$accentColor}; border-radius: 12px; transition: transform 0.2s;'>";
            $html .= "<div class='card-body p-4'>";
            
            // --- HEADER ---
            $html .= "<div class='d-flex justify-content-between align-items-start'>
                        <div>
                            <h5 class='m-0 fw-bold text-white d-flex align-items-center gap-2' style='letter-spacing: 0.5px;'>
                                <i class='fas {$icon}' style='color: {$accentColor}; opacity: 0.9;'></i> {$u['unit_name']}
                            </h5>
                            <div class='text-muted mt-1' style='font-size: 0.8rem;'><i class='fas fa-layer-group text-secondary'></i> Level {$u['floor_level']} &nbsp;&bull;&nbsp; {$u['description']}</div>
                        </div>
                        <span class='badge shadow-sm' style='background-color: {$badgeBg}; color: {$badgeText}; font-size: 0.75rem; padding: 6px 12px; border-radius: 8px; letter-spacing: 0.5px;'>{$status}</span>
                      </div>";
            
            $html .= $agentTag;

            // --- SPECS PILLS ---
            $html .= "<div class='d-flex gap-2 mt-3'>
                        <div style='background: rgba(255,255,255,0.03); padding: 6px 12px; border-radius: 8px; font-size: 0.75rem; color: #cbd5e1; flex: 1; border: 1px solid rgba(255,255,255,0.05);'>
                            <i class='fas fa-compress-arrows-alt' style='color: #94a3b8; width: 14px;'></i> Int: <b class='text-white'>{$u['internal_sqm']}</b> sqm
                        </div>
                        <div style='background: rgba(255,255,255,0.03); padding: 6px 12px; border-radius: 8px; font-size: 0.75rem; color: #cbd5e1; flex: 1; border: 1px solid rgba(255,255,255,0.05);'>
                            <i class='fas fa-expand-arrows-alt' style='color: #94a3b8; width: 14px;'></i> Ext: <b class='text-white'>{$u['external_sqm']}</b> sqm
                        </div>
                      </div>";

            // --- INSET PRICE BOX ---
            $html .= "<div id='price_disp_{$u['id']}' class='mt-3 p-3 position-relative' style='background: #151521; border-radius: 10px; box-shadow: inset 0 2px 5px rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.03);'>
                        <div class='d-flex justify-content-between align-items-center'>
                            <div>
                                <div style='font-size: 0.75rem; color: #9ca3af; margin-bottom: 3px;'>Shell: <span class='text-white fw-bold'>€" . number_format($u['shell_price'], 0) . "</span></div>
                                <div style='font-size: 0.75rem; color: #9ca3af;'>Finishes: <span class='text-white fw-bold'>€" . number_format($u['finishes_price'], 0) . "</span> <span style='font-size: 0.65rem; color: #64748b;'>({$finishState})</span></div>
                            </div>
                            <div class='text-end'>
                                <div style='font-size: 0.65rem; color: #6b7280; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 2px;'>Total Price</div>
                                <div style='font-size: 1.3rem; font-weight: 800; color: {$accentColor}; line-height: 1;'>€" . number_format($total_price, 0) . "</div>
                            </div>
                        </div>";
            
            if ($is_manager) {
                $html .= "<button class='btn btn-link p-0 mt-2' style='font-size: 0.75rem; text-decoration: none; color: {$accentColor};' onclick='togglePriceEdit({$u['id']})'><i class='fas fa-pen'></i> Modify Pricing</button>";
            }
            $html .= "</div>";

            // --- MANAGER EDIT PRICE FORM ---
            if ($is_manager) {
                $html .= "
                        <div id='price_edit_{$u['id']}' class='mt-3 p-3' style='display:none; background: rgba(14, 165, 233, 0.05); border-radius: 10px; border: 1px solid rgba(14, 165, 233, 0.2);'>
                            <div class='d-flex gap-2 mb-2'>
                                <div class='flex-fill'>
                                    <label class='form-label text-info' style='font-size: 0.65rem; margin-bottom: 2px; text-transform: uppercase; font-weight: 700;'>Shell Price (€)</label>
                                    <input type='number' id='inp_sh_{$u['id']}' class='form-control form-control-sm bg-dark text-light border-info shadow-none' value='{$u['shell_price']}' style='font-size: 0.8rem; padding: 8px;'>
                                </div>
                                <div class='flex-fill'>
                                    <label class='form-label text-info' style='font-size: 0.65rem; margin-bottom: 2px; text-transform: uppercase; font-weight: 700;'>Finishes Price (€)</label>
                                    <input type='number' id='inp_fn_{$u['id']}' class='form-control form-control-sm bg-dark text-light border-info shadow-none' value='{$u['finishes_price']}' style='font-size: 0.8rem; padding: 8px;'>
                                </div>
                            </div>
                            <div class='d-flex gap-2 mt-3'>
                                <button class='btn btn-info btn-sm flex-fill py-2 text-dark fw-bold' style='font-size: 0.8rem; border-radius: 8px;' onclick='savePrice({$u['id']})'><i class='fas fa-save me-1'></i> Save</button>
                                <button class='btn btn-outline-secondary btn-sm py-2 px-4' style='font-size: 0.8rem; border-radius: 8px;' onclick='togglePriceEdit({$u['id']})'><i class='fas fa-times'></i></button>
                            </div>
                        </div>";
            }

            // --- MODERN ACTION BUTTONS (Increased Spacing: gap-3 and padding: 10px) ---
            $planBtn = '';
            $floorLvl = trim($u['floor_level']);
            if (isset($plans[$floorLvl])) {
                $safeUrl = htmlspecialchars($plans[$floorLvl], ENT_QUOTES, 'UTF-8');
                $planBtn = "<button class='btn flex-fill' style='background: rgba(14, 165, 233, 0.1); color: #0ea5e9; border: 1px solid rgba(14, 165, 233, 0.3); border-radius: 8px; font-size: 0.85rem; font-weight: 600; padding: 10px 0;' onclick='openPlanModal(\"{$safeUrl}\")'><i class='fas fa-map me-1'></i> View Plan</button>";
            }

            $html .= "<div class='mt-3 d-flex gap-3 flex-wrap'>"; 
            
            if ($is_manager) {
                $statuses = ['Available', 'On Hold', 'Reserved', 'Sold - POS', 'Sold - Contract', 'Resale', 'BOM'];
                $html .= "<select class='form-select form-select-sm bg-dark text-light border-secondary flex-fill' style='font-size: 0.9rem; border-radius: 8px; padding: 10px 12px; height: auto;' onchange='managerUpdateStatus({$u['id']}, this.value, this)'>";
                foreach ($statuses as $st) {
                    $selected = ($status === $st) ? 'selected' : '';
                    $html .= "<option value='{$st}' {$selected}>{$st}</option>";
                }
                $html .= "</select>";
                if ($planBtn) $html .= "<div class='w-100 d-flex'>{$planBtn}</div>";
            } else {
                if ($status === 'Available') {
                    $html .= "<button class='btn flex-fill' style='background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 8px; font-size: 0.85rem; font-weight: 600; padding: 10px 0;' onclick='holdProperty({$u['id']})'><i class='fas fa-hand-paper me-1'></i> Put on Hold</button>";
                } elseif ($status === 'On Hold' && $u['held_by_agent_id'] == $_SESSION['user_id']) {
                     $html .= "<button class='btn flex-fill' style='background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 8px; font-size: 0.85rem; font-weight: 600; padding: 10px 0;' onclick='requestReserve({$u['id']})'><i class='fas fa-check-circle me-1'></i> Reserve Unit</button>";
                }
                $html .= $planBtn;
            }
            
            $html .= "</div>"; // End Actions Box
            $html .= "</div></div>"; // End Card & Body
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
