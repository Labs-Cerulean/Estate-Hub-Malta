<?php
require_once 'init.php';
require_once 'session-check.php';

$userId = getCurrentUserId();
$isAdmin = isAdmin();

// ==========================================
// AUTO-DEPLOY DATABASE UPDATES
// ==========================================
try {
    // 1. Create Finishes Rates Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_finishes_rates` (
        `id` int NOT NULL AUTO_INCREMENT,
        `item_key` varchar(50) NOT NULL UNIQUE,
        `category` varchar(100) NOT NULL,
        `description` text NOT NULL,
        `unit` varchar(20) NOT NULL,
        `rate` decimal(10,2) DEFAULT '0.00',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $count = $pdo->query("SELECT COUNT(*) FROM sales_finishes_rates")->fetchColumn();
    if ($count == 0) {
        $defaultRates = [
            ['sup_floor', '1 - Tiling', 'Supply of floor tiles', 'sqm', 20.00],
            ['sup_bath_floor', '1 - Tiling', 'Supply of bathroom floor tiles', 'sqm', 20.00],
            ['sup_bath_wall', '1 - Tiling', 'Supply of bathroom wall tiles', 'sqm', 20.00],
            ['sup_sanitary', '1 - Tiling', 'Supply of sanitaryware per bathroom', 'qty', 500.00],
            ['inst_floor', '1 - Tiling', 'Installation of Floor tiles (Inc. sand/cement/grouting)', 'sqm', 25.00],
            ['inst_bath_floor', '1 - Tiling', 'Installation of Bathroom Floor tiles (Inc. sand/cement/grouting)', 'sqm', 25.00],
            ['inst_bath_wall', '1 - Tiling', 'Installation of Bathroom wall tiles (Inc. glue/grouting)', 'sqm', 25.00],
            ['inst_skirt', '1 - Tiling', 'Installation of Skirting (Inc. sand/cement/grouting)', 'lm', 5.00],
            
            ['plast_mono', '2 - Plastering & Paint', 'Plastering Walls/Ceilings Monocote (Including Material)', 'sqm', 12.00],
            ['paint_white', '2 - Plastering & Paint', 'Painting of Walls/Ceilings (white)', 'sqm', 6.00],
            ['gyp_flat_n', '2 - Plastering & Paint', 'Gypsum board flat ceiling supply and install (Normal Board)', 'sqm', 25.00],
            ['gyp_flat_h', '2 - Plastering & Paint', 'Gypsum board flat ceiling supply and install (Humidity Board)', 'sqm', 28.00],
            ['bulk_n', '2 - Plastering & Paint', 'Bulkheads (Normal Board)', 'lm', 20.00],
            ['bulk_h', '2 - Plastering & Paint', 'Bulkheads (Humidity Board)', 'lm', 22.00],
            ['gyp_part', '2 - Plastering & Paint', 'Gypsum Partition Walls', 'sqm', 35.00],
            ['gyp_pocket', '2 - Plastering & Paint', 'Gypsum Partition Walls (for pocket doors only)', 'qty', 150.00],
            
            ['door_hinged', '3 - Internal Doors', 'Internal doors hinged', 'qty', 350.00],
            ['door_sliding', '3 - Internal Doors', 'Internal doors sliding', 'qty', 450.00],
            ['door_pocket', '3 - Internal Doors', 'Internal doors pocket', 'qty', 500.00],
            
            ['elec_1b', '4 - Electrical & Plumbing', 'Electrical (1 Bed)', 'lump_sum', 3000.00],
            ['elec_2b', '4 - Electrical & Plumbing', 'Electrical (2 Bed)', 'lump_sum', 4000.00],
            ['elec_3b', '4 - Electrical & Plumbing', 'Electrical (3+ Bed)', 'lump_sum', 5000.00],
            ['plumb_bath', '4 - Electrical & Plumbing', 'Plumbing Installation with PB of 1x Bath/Shower room', 'qty', 800.00],
            ['plumb_kitch', '4 - Electrical & Plumbing', 'Plumbing Installation with PB of 1x Kitchen', 'qty', 400.00],
            ['shower_inst', '4 - Electrical & Plumbing', '3rd Fix installation of shower cubicles/glass', 'qty', 150.00],
            
            ['gar_plast', '5 - Garage', 'Plaster of ceiling and walls', 'sqm', 10.00],
            ['gar_paint', '5 - Garage', 'Paint of ceiling and walls (white)', 'sqm', 5.00],
            ['gar_elec', '5 - Garage', 'Electrical installation: 1x 8 module DB, 1x switch, 1x tube...', 'lump_sum', 300.00],
            ['gar_door', '5 - Garage', 'Manual up and over garage door', 'lump_sum', 800.00],
            
            ['ap_hinged_win', '6 - Semi Finishes', 'Hinged Window', 'sqm', 250.00],
            ['ap_sliding_win', '6 - Semi Finishes', 'Sliding Window', 'sqm', 200.00],
            ['ap_hinged_door', '6 - Semi Finishes', 'Hinged Door', 'sqm', 300.00],
            ['ap_sliding_door', '6 - Semi Finishes', 'Sliding Door', 'sqm', 250.00],
            ['fire_door', '6 - Semi Finishes', '30 Minute Fire rated door', 'qty', 600.00],
            ['timber_door', '6 - Semi Finishes', 'External Timber Door', 'qty', 800.00],
            ['sills', '6 - Semi Finishes', 'Sills', 'lm', 40.00],
            ['rail_alu', '6 - Semi Finishes', 'Railing (Aluminium vertical)', 'lm', 150.00],
            ['rail_glass', '6 - Semi Finishes', 'Railing (Glass)', 'lm', 250.00],
            ['rail_iron', '6 - Semi Finishes', 'Railing (Wrought iron)', 'lm', 180.00],
            ['balc_tile', '6 - Semi Finishes', 'Balcony/Terrace Tiling including waterproofing', 'sqm', 30.00],
            ['balc_skirt', '6 - Semi Finishes', 'Balcony Skirting', 'lm', 8.00],
            ['main_cable', '6 - Semi Finishes', 'Main cable to apartment and balcony light', 'lump_sum', 400.00],
            ['water_tank', '6 - Semi Finishes', 'Water tank supply, installation and connection', 'lump_sum', 600.00]
        ];
        
        $s = $pdo->prepare("INSERT INTO sales_finishes_rates (item_key, category, description, unit, rate) VALUES (?, ?, ?, ?, ?)");
        foreach ($defaultRates as $r) { $s->execute($r); }
    }
    
   // 2. Add storage column for calculator memory
    $pdo->exec("ALTER TABLE sales_quotes ADD COLUMN finishes_calc_data TEXT DEFAULT NULL");
    
    // 3. Add free-text project reference column
    $pdo->exec("ALTER TABLE sales_quotes ADD COLUMN project_name_free VARCHAR(255) DEFAULT NULL");

    $ohsaCount = (int)$pdo->query("SELECT COUNT(*) FROM sales_standard_items WHERE quote_type = 'OHSA'")->fetchColumn();
    if ($ohsaCount === 0) {
        $ohsaItems = [
            ['1 - Documentation', 'Preparation and submission of initial documentation for construction projects', 'lump_sum', 350.00, 10],
            ['2 - Site Inspections', 'Site inspection and preparation of report — normal size sites (max 45 min)', 'visit', 50.00, 20],
            ['2 - Site Inspections', 'Site inspection and preparation of report — large size sites (max 1 hr; extra at €35/hr)', 'visit', 70.00, 21],
            ['3 - Training', 'General OHS training', 'participant', 45.00, 30],
            ['3 - Training', 'Policies and procedures training to management/workers', 'participant', 45.00, 31],
            ['1 - Documentation', 'Preparation of Company policy and procedures for OHSMS (€250–350 per procedure)', 'procedure', 300.00, 11],
            ['1 - Documentation', 'Preparation of OHSMS documentation — registers, forms, permits, checklists', 'document', 150.00, 12],
            ['1 - Documentation', 'Preparation of evacuation procedure — small to medium premises', 'procedure', 250.00, 13],
            ['1 - Documentation', 'Preparation of evacuation procedure — large premises (€350–500)', 'procedure', 425.00, 14],
            ['4 - Risk Assessments', 'Preparation of a general risk assessment', 'assessment', 250.00, 40],
            ['4 - Risk Assessments', 'Preparation of specific risk assessment / SWMS / RAMS (€350–450)', 'assessment', 400.00, 41],
            ['4 - Risk Assessments', 'General risk assessment — small to medium workplaces', 'assessment', 450.00, 42],
            ['4 - Risk Assessments', 'General risk assessment — large workplaces (€550–850)', 'assessment', 700.00, 43],
            ['4 - Risk Assessments', 'General risk assessment — small to medium Hotels (€550–750)', 'assessment', 650.00, 44],
            ['4 - Risk Assessments', 'General risk assessment — large and complex Hotels (€850–1,250)', 'assessment', 1050.00, 45],
            ['5 - Consultancy', 'General OHS Consultancy', 'hour', 35.00, 50],
        ];
        $insOhsa = $pdo->prepare("INSERT INTO sales_standard_items (quote_type, category, description, unit, default_rate, sort_order, is_active) VALUES ('OHSA', ?, ?, ?, ?, ?, 1)");
        foreach ($ohsaItems as $item) { $insOhsa->execute($item); }
    }
    try {
        $pdo->exec("INSERT IGNORE INTO sales_default_terms (quote_type, terms_text) VALUES ('OHSA', 'All prices quoted are exclusive of VAT. Payment terms: 50% on acceptance, balance on completion unless otherwise agreed.')");
    } catch (PDOException $e) {}
} catch(PDOException $e) {}


// Determine Access Levels
$access = [
    'Demolition_Excavation' => ['view' => hasPermission('view_sales_demo_exc') || $isAdmin, 'manage' => hasPermission('manage_sales_demo_exc') || $isAdmin],
    'Construction' => ['view' => hasPermission('view_sales_const') || $isAdmin, 'manage' => hasPermission('manage_sales_const') || $isAdmin],
    'Finishes' => ['view' => hasPermission('view_sales_finishes') || $isAdmin, 'manage' => hasPermission('manage_sales_finishes') || $isAdmin],
    'OHSA' => ['view' => hasPermission('view_sales_ohsa') || $isAdmin, 'manage' => hasPermission('manage_sales_ohsa') || $isAdmin],
];

$canApproveQuotes = hasPermission('approve_quotes') || $isAdmin;

if (!$access['Demolition_Excavation']['view'] && !$access['Construction']['view'] && !$access['Finishes']['view'] && !$access['OHSA']['view']) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = ''; $error = '';
$allEntities = $isAdmin ? $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll() : getUserClients($pdo, $userId);
$selected_contractor_id = isset($_GET['contractor_id']) ? (int)$_GET['contractor_id'] : null;

// Extract contractor prefix for JS Reference Generation
$contractorPrefix = 'CTR';
if ($selected_contractor_id) {
    foreach($allEntities as $c) { 
        if($c['id'] == $selected_contractor_id) { 
            $contractorPrefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $c['name']), 0, 4));
            break; 
        } 
    }
}

// ==========================================
// FORM ACTION HANDLING
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // 1. Create New Quote
        if ($action === 'create_quote') {
            $type = $_POST['quote_type'];
            if (!$access[$type]['manage']) throw new Exception("Unauthorized.");
            
            $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
            $project_name_free = !empty($_POST['project_name_free']) ? trim($_POST['project_name_free']) : null;
            
            if (!$project_id && !$project_name_free && !in_array($type, ['Demolition_Excavation'])) {
                throw new Exception("You must select an existing project OR enter a free-text project reference.");
            }
            
            $contractor_id = (int)$_POST['contractor_id'];
            $client_id = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null;
            $client_name_free = !empty($_POST['client_name_free']) ? trim($_POST['client_name_free']) : null;
            if (!$client_id && !$client_name_free) throw new Exception("You must select an existing client OR enter a free-text client name.");
            
            $termStmt = $pdo->prepare("SELECT terms_text FROM sales_default_terms WHERE quote_type = ?");
            $termStmt->execute([$type]);
            $defTerms = $termStmt->fetchColumn();
            
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO sales_quotes (contractor_id, client_id, client_name_free, project_id, project_name_free, quote_type, reference_number, vat_rate, created_by, terms_conditions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([ $contractor_id, $client_id, $client_name_free, $project_id, $project_name_free, $type, trim($_POST['reference_number']), $_POST['vat_rate'] ?? 18.00, $userId, $defTerms ?: '' ]);

            
            $newQuoteId = $pdo->lastInsertId();
            
            if ($type !== 'Finishes') { 
                $stmtStd = $pdo->prepare("SELECT * FROM sales_standard_items WHERE quote_type = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC");
                $stmtStd->execute([$type]);
                $stdItems = $stmtStd->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($stdItems)) {
                    $stmtItem = $pdo->prepare("INSERT INTO sales_quote_items (quote_id, category, description, unit, estimated_qty, unit_rate, sort_order) VALUES (?, ?, ?, ?, 0.00, ?, ?)");
                    foreach ($stdItems as $item) { $stmtItem->execute([$newQuoteId, $item['category'], $item['description'], $item['unit'], $item['default_rate'], $item['sort_order']]); }
                }
            }
            
            $pdo->commit();
            header("Location: work_sales.php?contractor_id=$contractor_id&quote_id=" . $newQuoteId . "&msg=created");
            exit;
        }

        // 2A. SEMI-FINISHES CALCULATOR ENGINE
        elseif ($action === 'generate_semi_finishes_boq') {
            $qId = (int)$_POST['quote_id'];
            if (!$access['Finishes']['manage']) throw new Exception("Unauthorized.");
            
            $pdo->beginTransaction();
            
            // 1. Clear existing BoQ
            $pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id = ?")->execute([$qId]);
            
            // 2. Fetch Rates Dictionary
            $ratesDb = $pdo->query("SELECT item_key, rate FROM sales_finishes_rates")->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // 3. Read Inputs
            $propType = $_POST['sf_prop_type'];
            $extSqm = (float)$_POST['sf_ext_sqm'];
            $sillsLm = (float)$_POST['sf_sills_lm'];
            $railType = $_POST['sf_rail_type'];
            $railLm = (float)$_POST['sf_rail_lm'];
            
            $sortIdx = 10;
            $runningTotalExc = 0;
            
            $insertItem = $pdo->prepare("INSERT INTO sales_quote_items (quote_id, category, description, unit, estimated_qty, unit_rate, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $add = function($cat, $desc, $unit, $qty, $rateKey) use ($insertItem, $qId, &$sortIdx, &$runningTotalExc, $ratesDb) {
                $rate = isset($ratesDb[$rateKey]) ? (float)$ratesDb[$rateKey] : 0;
                if ($unit === 'lump_sum') { $qty = 1; }
                $insertItem->execute([$qId, $cat, $desc, $unit, $qty, $rate, $sortIdx]);
                $sortIdx += 10;
                $runningTotalExc += ($qty * $rate);
            };

            // Apertures
            if (isset($_POST['ap_type'])) {
                for($i=0; $i < count($_POST['ap_type']); $i++) {
                    $apT = $_POST['ap_type'][$i];
                    $apW = (float)$_POST['ap_w'][$i];
                    $apH = (float)$_POST['ap_h'][$i];
                    $apSqm = $apW * $apH;
                    
                    if ($apSqm > 0) {
                        $typeText = str_replace(['ap_', '_'], ['', ' '], $apT);
                        $add('6 - Semi Finishes', "Aperture: ".ucwords($typeText)." ({$apW}m x {$apH}m)", 'sqm', $apSqm, $apT);
                    }
                }
            }

            // Main Door
            if ($propType === 'maisonette') {
                $add('6 - Semi Finishes', 'External Timber Door', 'qty', 1, 'timber_door'); 
            } else {
                $add('6 - Semi Finishes', '30 Minute Fire rated door', 'qty', 1, 'fire_door');
            }

            // Sills
            if ($sillsLm > 0) {
                $add('6 - Semi Finishes', 'Sills', 'lm', $sillsLm, 'sills');
            }

            // Railing
            if ($railLm > 0 && $railType !== 'none') {
                $railDesc = [
                    'rail_alu' => 'Railing (Aluminium vertical)',
                    'rail_glass' => 'Railing (Glass)',
                    'rail_iron' => 'Railing (Wrought iron)'
                ][$railType] ?? 'Railing';
                $add('6 - Semi Finishes', $railDesc, 'lm', $railLm, $railType);
            }

            // External Terraces/Balconies (Tiling & Waterproofing)
            if ($extSqm > 0) {
                $add('6 - Semi Finishes', 'Balcony/Terrace Tiling including waterproofing', 'sqm', $extSqm, 'balc_tile');
            }

            // Always include Water tank and main cable
            $add('6 - Semi Finishes', 'Main cable to apartment and balcony light', 'lump_sum', 1, 'main_cable');
            $add('6 - Semi Finishes', 'Water tank supply, installation and connection', 'lump_sum', 1, 'water_tank');

            // Apply 17-Point Semi-Finishes T&Cs
            $semiTerms = "1. Payment terms strictly 30 days from invoice.
2. Price is based on standard finishes. Bespoke finishes will be charged at a premium.
3. Excludes specific light fittings and loose furniture.
4. Final measurements will be verified on site.
5. Any additional structural modifications are subject to a separate quote.
6. All works are to be completed during standard working hours.
7. Client is responsible for providing access to the site.
8. Water and Electricity application fees are to be paid by the client.
9. Electricity works exclude the installation of specific light fittings.
10. Tile laying is on torba, not screed.
11. Shower glass enclosures are to be supplied by the client.
12. Gypsum or bulkheads can be added upon bespoke design request. Bespoke design is NOT included in this price and a design fee may apply.
13. Internal doors are assumed to be hinged and of standard size (800mm x 900mm) unless specific sizes, sliding, or pocket doors are specified in the quote.
14. This quote is against a standard design. If bespoke design is required, the quote must be amended.
15. The \"Contribution for Supply\" line item covers unit tiles, bathroom tiles, and bathroom sanitaryware/accessories (inclusive of VAT) from PRA selected suppliers.
16. When semi-finishes are included, specifications are to be annexed to this quote.
17. If water supply is required for the garage. It will be charged at €68.00 per linear metre.";

            $pdo->prepare("UPDATE sales_quotes SET terms_conditions = ? WHERE id = ?")->execute([$semiTerms, $qId]);

            // Finalize Master Totals
            $pdo->exec("UPDATE sales_quotes SET total_exc_vat = (SELECT COALESCE(SUM(estimated_qty * unit_rate), 0) FROM sales_quote_items WHERE quote_id = $qId) WHERE id = $qId");
            $pdo->exec("UPDATE sales_quotes SET total_inc_vat = total_exc_vat + (total_exc_vat * (vat_rate/100)) WHERE id = $qId");

            $pdo->commit();
            $message = "Semi-Finishes BoQ generated successfully!";
        }

        // 2B. FULL FINISHES CALCULATOR ENGINE
        elseif ($action === 'generate_finishes_boq') {
            $qId = (int)$_POST['quote_id'];
            if (!$access['Finishes']['manage']) throw new Exception("Unauthorized.");
            
            $pdo->beginTransaction();
            
            // Save Memory Payload
            $memoryPayload = [
                'fc_state' => $_POST['fc_state'] ?? 'semi_finished',
                'fc_garage' => isset($_POST['fc_garage']) ? 1 : 0,
                'fc_area_int' => $_POST['fc_area_int'] ?? '',
                'fc_area_ext' => $_POST['fc_area_ext'] ?? '',
                'fc_height' => $_POST['fc_height'] ?? '2.65',
                'fc_beds' => $_POST['fc_beds'] ?? '1',
                'fc_skirting' => $_POST['fc_skirting'] ?? '',
                'fc_balcony_perim' => $_POST['fc_balcony_perim'] ?? '',
                'fc_bath_shower' => $_POST['fc_bath_shower'] ?? '1',
                'fc_bath_bath' => $_POST['fc_bath_bath'] ?? '0',
                'fc_bath_sqm' => $_POST['fc_bath_sqm'] ?? '',
                'fc_bath_perim' => $_POST['fc_bath_perim'] ?? '',
                'fc_door_hinged' => $_POST['fc_door_hinged'] ?? '2',
                'fc_door_sliding' => $_POST['fc_door_sliding'] ?? '0',
                'fc_door_pocket' => $_POST['fc_door_pocket'] ?? '0',
                'fc_garage_sqm' => $_POST['fc_garage_sqm'] ?? '',
                'fc_rail_alu' => $_POST['fc_rail_alu'] ?? '0',
                'fc_rail_glass' => $_POST['fc_rail_glass'] ?? '0',
                'fc_rail_iron' => $_POST['fc_rail_iron'] ?? '0',
                'fc_pm_pct' => $_POST['fc_pm_pct'] ?? '15.00',
                'fc_cleaning_fee' => $_POST['fc_cleaning_fee'] ?? '400.00',
                'fc_discount' => $_POST['fc_discount'] ?? '0.00',
                'ap_type' => $_POST['ap_type'] ?? [],
                'ap_w' => $_POST['ap_w'] ?? [],
                'ap_h' => $_POST['ap_h'] ?? []
            ];
            
            $pdo->prepare("UPDATE sales_quotes SET finishes_calc_data = ? WHERE id = ?")->execute([json_encode($memoryPayload), $qId]);

            // Fetch Quote VAT Rate to reverse engineer the Inc VAT contribution
            $qStmt = $pdo->prepare("SELECT vat_rate FROM sales_quotes WHERE id = ?");
            $qStmt->execute([$qId]);
            $qVatRate = (float)$qStmt->fetchColumn();
            $vatMult = 1 + ($qVatRate / 100);
            
            // 1. Clear existing BoQ
            $pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id = ?")->execute([$qId]);
            
            // 2. Fetch Rates Dictionary
            $ratesDb = $pdo->query("SELECT item_key, rate FROM sales_finishes_rates")->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // 3. Read Inputs
            $state = $_POST['fc_state'];
            $hasGarage = isset($_POST['fc_garage']) ? true : false;
            $A = (float)$_POST['fc_area_int'];
            $B = (float)$_POST['fc_area_ext'];
            $C = (float)($_POST['fc_height'] ?: 2.65);
            $D = (int)$_POST['fc_beds'];
            $E = (float)$_POST['fc_skirting'];
            $F = (int)$_POST['fc_bath_shower'];
            $G = (int)$_POST['fc_bath_bath'];
            $H = (float)$_POST['fc_bath_sqm'];
            $I = (float)$_POST['fc_bath_perim'];
            $X1 = (int)$_POST['fc_door_hinged'];
            $X2 = (int)$_POST['fc_door_sliding'];
            $X3 = (int)$_POST['fc_door_pocket'];
            
            $pmPct = (float)$_POST['fc_pm_pct'];
            $cleaningFee = (float)($_POST['fc_cleaning_fee'] ?? 400);
            
            // Convert Inputted Inc VAT Discount to Exc VAT for calculation
            $discountIncVat = (float)$_POST['fc_discount'];
            $discountExcVat = $discountIncVat / $vatMult;
            
            $sortIdx = 10;
            $runningTotalExc = 0;
            
            $insertItem = $pdo->prepare("INSERT INTO sales_quote_items (quote_id, category, description, unit, estimated_qty, unit_rate, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $add = function($cat, $desc, $unit, $qty, $rateKey) use ($insertItem, $qId, &$sortIdx, &$runningTotalExc, $ratesDb) {
                $rate = isset($ratesDb[$rateKey]) ? (float)$ratesDb[$rateKey] : 0;
                if ($unit === 'lump_sum') { $qty = 1; }
                $insertItem->execute([$qId, $cat, $desc, $unit, $qty, $rate, $sortIdx]);
                $sortIdx += 10;
                $runningTotalExc += ($qty * $rate);
            };

            // Re-apply Default Full Terms
            $termStmt = $pdo->prepare("SELECT terms_text FROM sales_default_terms WHERE quote_type = 'Finishes'");
            $termStmt->execute();
            $defTerms = $termStmt->fetchColumn();
            if ($defTerms) {
                $pdo->prepare("UPDATE sales_quotes SET terms_conditions = ? WHERE id = ?")->execute([$defTerms, $qId]);
            }

            // --- CATEGORY 1: TILING ---
            $K = max(0, $A - $H); // Net floor area
            $L = $H; // Bath floor area
            $M = $I * $C; // Bath wall area
            
            // Calculate Supply Quantities (Include 10% breakage contingency + Skirting material where 1lm = 0.1sqm)
            $K_supply = ($K + ($E * 0.1)) * 1.10;
            $L_supply = $L * 1.10;
            $M_supply = $M * 1.10;
            
            // Calc Supply Lump Sum (Unrounded for quote logic, Rounded for client display text)
            $rawSupVal = ($K_supply * $ratesDb['sup_floor']) + ($L_supply * $ratesDb['sup_bath_floor']) + ($M_supply * $ratesDb['sup_bath_wall']) + (($F+$G) * $ratesDb['sup_sanitary']);
            $supValRoundedIncVat = floor($rawSupVal / 250) * 250;
            
            // Add internal €6/lm skirting supply contribution to the actual DB value (Does not affect client allowance text)
            $rawSupVal += ($E * 6.00);
            
            $supDesc = "Contribution for supply of unit tiles, bathroom tiles and bathroom sanitaryware (Total Client Allowance: €" . number_format($supValRoundedIncVat, 2) . " Inc. VAT)";
            
            $insertItem->execute([$qId, '1 - Tiling', $supDesc, 'lump_sum', 1, $rawSupVal, $sortIdx]);
            $sortIdx += 10; 
            $runningTotalExc += $rawSupVal;
            
            $add('1 - Tiling', 'Installation of Floor tiles (Inc. sand/cement/grouting)', 'sqm', $K, 'inst_floor');
            $add('1 - Tiling', 'Installation of Bathroom Floor tiles (Inc. sand/cement/grouting)', 'sqm', $L, 'inst_bath_floor');
            $add('1 - Tiling', 'Installation of Bathroom wall tiles (Inc. glue/grouting)', 'sqm', $M, 'inst_bath_wall');
            $add('1 - Tiling', 'Installation of Skirting (Inc. sand/cement/grouting)', 'lm', $E, 'inst_skirt');

            // --- CATEGORY 2: PLASTERING & PAINT ---
            $plasterVol = $A + ($C * $E);
            $add('2 - Plastering & Paint', 'Plastering Walls/Ceilings Monocote (Including Material)', 'sqm', $plasterVol, 'plast_mono');
            $add('2 - Plastering & Paint', 'Painting of Walls/Ceilings (white)', 'sqm', $plasterVol, 'paint_white');
            $add('2 - Plastering & Paint', 'Gypsum board flat ceiling supply and install (Normal Board) - if needed', 'sqm', 0, 'gyp_flat_n');
            $add('2 - Plastering & Paint', 'Gypsum board flat ceiling supply and install (Humidity Board) - if needed', 'sqm', 0, 'gyp_flat_h');
            $add('2 - Plastering & Paint', 'Bulkheads (Normal Board) - if needed', 'lm', 0, 'bulk_n');
            $add('2 - Plastering & Paint', 'Bulkheads (Humidity Board) - if needed', 'lm', 0, 'bulk_h');
            $add('2 - Plastering & Paint', 'Gypsum Partition Walls (if indicated on plan)', 'sqm', 0, 'gyp_part');
            if ($X3 > 0) $add('2 - Plastering & Paint', 'Gypsum Partition Walls (for pocket doors only)', 'qty', $X3, 'gyp_pocket');

            // --- CATEGORY 3: DOORS ---
            $add('3 - Internal Doors', 'Internal doors hinged', 'qty', $X1, 'door_hinged');
            $add('3 - Internal Doors', 'Internal doors sliding', 'qty', $X2, 'door_sliding');
            $add('3 - Internal Doors', 'Internal doors pocket', 'qty', $X3, 'door_pocket');

            // --- CATEGORY 4: ELEC & PLUMBING ---
            if ($D == 1) { $eKey = 'elec_1b'; $eDesc = "Sub-DB with OVR, 1x 10 way consumer unit, 12x double sockets, 3 x 2 way ceiling light switch, 3x 1 way ceiling light switch, 1x Water heater point 1 x electric oven point, 1x electric hob point, 1x microwave point, 1x hood point, 1x cooker unit, 1x W/Machine point, 1x TV point with draw wire and 1x Tel. Point with draw wire, 2x AC points with double pole switches including drain but excluding copper pipes, bathroom wall lights"; }
            elseif ($D == 2) { $eKey = 'elec_2b'; $eDesc = "Sub-DB with OVR, 1x 12 way consumer unit, 16x double sockets, 4 x 2 way ceiling light switch, 3x 1 way ceiling light switch, 1x Water heater point, 1 x electric oven point, 1x electric hob point, 1x microwave point, 1x hood point, 1x cooker unit, 1x W/Machine point, 2x TV point with draw wire and 2x Tel. Point with draw wire, 3x AC points with double pole switches including drain but excluding copper pipes, bathroom wall lights"; }
            else { $eKey = 'elec_3b'; $eDesc = "Sub-DB with OVR, 1x 12-way consumer unit, 20x double sockets, 4 x 2-way ceiling light switch, 3x 1 way ceiling light switch, 1x Water heater point, 1 x electric oven point, 1x electric hob point, 1x microwave point, 1x hood point, 1x cooker unit, 1x W/Machine point, 3x TV point with draw wire and 3x Tel. Point with draw wire, 4x AC points with double pole switches including drain but excluding copper pipes, bathroom wall lights"; }
            
            $add('4 - Electrical & Plumbing', $eDesc, 'lump_sum', 1, $eKey);
            $add('4 - Electrical & Plumbing', 'Plumbing Installation with PB of 1x Bath/Shower room: 7x wall plates plus 1/2" angle valves, 12 meters PB 15mm2 pipes, 6 meters of 50mm drain pipe and fittings... PER BATHROOM', 'qty', $F+$G, 'plumb_bath');
            $add('4 - Electrical & Plumbing', 'Plumbing Installation with PB of 1x Kitchen: 4x wall plates plus 1/2" angle valves, 12 meters of PB 15mm2 pipes, 6 meters of 50mm drain pipe... ', 'qty', 1, 'plumb_kitch');
            $add('4 - Electrical & Plumbing', '3rd Fix installation of shower cubicles/glass', 'qty', $F, 'shower_inst');

            // --- CATEGORY 5: GARAGE ---
            if ($hasGarage) {
                $gSqm = (float)$_POST['fc_garage_sqm'];
                $add('5 - Garage', 'Plaster of ceiling and walls', 'sqm', $gSqm, 'gar_plast');
                $add('5 - Garage', 'Paint of ceiling and walls (white)', 'sqm', $gSqm, 'gar_paint');
                $add('5 - Garage', 'Electrical installation: 1x 8 module DB, 1x light switch, 1x neon tube...', 'lump_sum', 1, 'gar_elec');
                if ($state === 'common_parts') { $add('5 - Garage', 'Manual up and over garage door', 'lump_sum', 1, 'gar_door'); }
                
                $insertItem->execute([$qId, '5 - Garage', 'Note: no flooring included in garage finishes.', 'lump_sum', 0, 0, $sortIdx]);
                $sortIdx += 10;
            }

            // --- CATEGORY 6: SEMI FINISHES ---
            if ($state === 'common_parts') {
                $balcPerim = (float)$_POST['fc_balcony_perim'];
                $sills = $balcPerim;
                
                if (isset($_POST['ap_type'])) {
                    for($i=0; $i < count($_POST['ap_type']); $i++) {
                        $apT = $_POST['ap_type'][$i];
                        $apW = (float)$_POST['ap_w'][$i];
                        $apH = (float)$_POST['ap_h'][$i];
                        $apSqm = $apW * $apH;
                        
                        if ($apSqm > 0) {
                            $sills += $apW;
                            $typeText = str_replace(['ap_', '_'], ['', ' '], $apT);
                            $add('6 - Semi Finishes', "Aperture: ".ucwords($typeText)." ({$apW}m x {$apH}m)", 'sqm', $apSqm, $apT);
                        }
                    }
                }
                
                $add('6 - Semi Finishes', '30 Minute Fire rated door', 'qty', 1, 'fire_door');
                $add('6 - Semi Finishes', 'Sills', 'lm', $sills, 'sills');
                
                if ((float)$_POST['fc_rail_alu'] > 0) $add('6 - Semi Finishes', 'Railing (Aluminium vertical)', 'lm', (float)$_POST['fc_rail_alu'], 'rail_alu');
                if ((float)$_POST['fc_rail_glass'] > 0) $add('6 - Semi Finishes', 'Railing (Glass)', 'lm', (float)$_POST['fc_rail_glass'], 'rail_glass');
                if ((float)$_POST['fc_rail_iron'] > 0) $add('6 - Semi Finishes', 'Railing (Wrought iron)', 'lm', (float)$_POST['fc_rail_iron'], 'rail_iron');
                
                $add('6 - Semi Finishes', 'Balcony/Terrace Tiling including waterproofing', 'sqm', $B, 'balc_tile');
                $add('6 - Semi Finishes', 'Balcony Skirting', 'lm', $balcPerim, 'balc_skirt');
                $add('6 - Semi Finishes', 'Main cable to apartment and balcony light', 'lump_sum', 1, 'main_cable');
                $add('6 - Semi Finishes', 'Water tank supply, installation and connection', 'lump_sum', 1, 'water_tank');
            }

            // --- CATEGORY 7: PM & LOGISTICS ---
            $pmFee = $runningTotalExc * ($pmPct / 100);
            $pmFee += $cleaningFee; // Add dynamic cleaning/logistics fee
            
            $insertItem->execute([$qId, '7 - Project Management', 'Project Management, Coordination & Site Logistics', 'lump_sum', 1, $pmFee, $sortIdx]);
            $sortIdx += 10; 
            
            if ($discountExcVat > 0) {
                $insertItem->execute([$qId, '8 - Discounts', 'Senior Management Discount', 'lump_sum', 1, -$discountExcVat, $sortIdx]);
            }
            
            // Finalize Master Totals
            $pdo->exec("UPDATE sales_quotes SET total_exc_vat = (SELECT COALESCE(SUM(estimated_qty * unit_rate), 0) FROM sales_quote_items WHERE quote_id = $qId) WHERE id = $qId");
            $pdo->exec("UPDATE sales_quotes SET total_inc_vat = total_exc_vat + (total_exc_vat * (vat_rate/100)) WHERE id = $qId");
            
            $pdo->commit();
            $message = "Finishes Calculator applied successfully! Review the BoQ below.";
        }

        // 3. Change Status (Workflow)
        elseif ($action === 'change_status') {
            $qId = (int)$_POST['quote_id'];
            $newStatus = $_POST['new_status'];
            
            $oldStatusStmt = $pdo->prepare("SELECT status FROM sales_quotes WHERE id = ?");
            $oldStatusStmt->execute([$qId]);
            $oldQuote = $oldStatusStmt->fetch();
            
            if ($newStatus === 'Rejected') {
                if (!$canApproveQuotes) throw new Exception("You are not authorized to reject quotes.");
                $pdo->prepare("UPDATE sales_quotes SET status = 'Rejected' WHERE id = ?")->execute([$qId]);
                $message = "Quote Rejected. It is now unlocked for editing.";
            } elseif ($newStatus === 'Approved') {
                if (!$canApproveQuotes) throw new Exception("You are not authorized to approve quotes.");
                $pdo->prepare("UPDATE sales_quotes SET status = 'Approved', approver_id = ?, approved_at = NOW() WHERE id = ?")->execute([$userId, $qId]);
                $message = "Quote Approved! It can now be printed and sent.";
            } elseif (in_array($newStatus, ['Sent', 'Accepted', 'Declined', 'Completed'])) {
                $stmt = $pdo->prepare("UPDATE sales_quotes SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $qId]);
                $message = "Status updated to $newStatus.";
                
                if ($newStatus === 'Accepted' && $oldQuote['status'] !== 'Accepted') {
                    $qStmt = $pdo->prepare("SELECT * FROM sales_quotes WHERE id = ?");
                    $qStmt->execute([$qId]);
                    $quoteData = $qStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!empty($quoteData['client_id'])) {
                        $cStmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
                        $cStmt->execute([$quoteData['contractor_id']]);
                        $contractorName = $cStmt->fetchColumn();
                        
                        if ($contractorName) {
                            $subStmt = $pdo->prepare("SELECT id FROM subcontractors WHERE name = ?");
                            $subStmt->execute([$contractorName]);
                            $subId = $subStmt->fetchColumn();
                            
                            if (!$subId) {
                                $pdo->prepare("INSERT INTO subcontractors (name) VALUES (?)")->execute([$contractorName]);
                                $subId = $pdo->lastInsertId();
                            }
                            
                            $checkWo = $pdo->prepare("SELECT id FROM subcontractor_works WHERE subcontractor_id = ? AND client_id = ? AND work_reference = ?");
                            $checkWo->execute([$subId, $quoteData['client_id'], $quoteData['reference_number']]);
                            if (!$checkWo->fetchColumn()) {
                                $woStmt = $pdo->prepare("INSERT INTO subcontractor_works (subcontractor_id, client_id, project_id, is_measured, work_reference, po_reference, vat_rate, responsible, total_exc_vat, total_inc_vat, notes) VALUES (?, ?, ?, 1, ?, '', ?, 'System Auto-Link', ?, ?, 'Auto-generated from Accepted Sales Quote')");
                                $woStmt->execute([
                                    $subId, 
                                    $quoteData['client_id'], 
                                    $quoteData['project_id'], 
                                    $quoteData['reference_number'],
                                    $quoteData['vat_rate'],
                                    $quoteData['total_exc_vat'],
                                    $quoteData['total_inc_vat']
                                ]);
                                $newWoId = $pdo->lastInsertId();
                                
                                $itemsStmt = $pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id = ?");
                                $itemsStmt->execute([$qId]);
                                $quoteItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                $boqStmt = $pdo->prepare("INSERT INTO subcontractor_boq (work_id, block_level_id, description, qty, rate, total_exc) VALUES (?, NULL, ?, ?, ?, ?)");
                                foreach ($quoteItems as $qi) {
                                    $desc = $qi['category'] . ' - ' . $qi['description'];
                                    $totExc = $qi['estimated_qty'] * $qi['unit_rate'];
                                    $boqStmt->execute([$newWoId, $desc, $qi['estimated_qty'], $qi['unit_rate'], $totExc]);
                                }
                                $message .= " Also auto-generated a Subcontractor Work Order for the receiving Client!";
                            }
                        }
                    }
                }
            } elseif ($newStatus === 'Pending Approval') {
                $stmt = $pdo->prepare("UPDATE sales_quotes SET status = 'Pending Approval' WHERE id = ?");
                $stmt->execute([$qId]);
                $message = "Quote submitted for approval.";
            }
        }
        
        // 3B. Unlock Quote (Revert to Draft)
        elseif ($action === 'unlock_quote') {
            $qId = (int)$_POST['quote_id'];
            if (!$canApproveQuotes) throw new Exception("Unauthorized to unlock quotes.");
            
            $pdo->prepare("UPDATE sales_quotes SET status = 'Draft' WHERE id = ?")->execute([$qId]);
            $message = "Quote unlocked and reverted to Draft status for editing.";
        }
        
        // 4. Update Quote Settings
        elseif ($action === 'update_quote_settings') {
            $qId = (int)$_POST['quote_id'];
            $type = $_POST['quote_type'];
            if (!$access[$type]['manage']) throw new Exception("Unauthorized.");
            
            $stmt = $pdo->prepare("UPDATE sales_quotes SET terms_conditions = ?, vat_rate = ? WHERE id = ?");
            $stmt->execute([$_POST['terms_conditions'], $_POST['vat_rate'], $qId]);
            $message = "Quote settings updated.";
            $pdo->exec("UPDATE sales_quotes SET total_inc_vat = total_exc_vat + (total_exc_vat * (vat_rate/100)) WHERE id = $qId");
        }
        
        // 5. Save BoQ Item (WITH DYNAMIC PM HOOK)
        elseif ($action === 'save_item') {
            $qId = (int)$_POST['quote_id'];
            $type = $_POST['quote_type'];
            if (!$access[$type]['manage']) throw new Exception("Unauthorized.");
            
            $itemId = !empty($_POST['item_id']) ? (int)$_POST['item_id'] : null;
            $qty = (float)$_POST['estimated_qty'];
            $rate = (float)$_POST['unit_rate'];
            $sort = (int)($_POST['sort_order'] ?? 99);
            
            if ($itemId) {
                $stmt = $pdo->prepare("UPDATE sales_quote_items SET category=?, description=?, unit=?, estimated_qty=?, unit_rate=?, sort_order=? WHERE id=?");
                $stmt->execute([$_POST['category'], $_POST['description'], $_POST['unit'], $qty, $rate, $sort, $itemId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO sales_quote_items (quote_id, category, description, unit, estimated_qty, unit_rate, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$qId, $_POST['category'], $_POST['description'], $_POST['unit'], $qty, $rate, $sort]);
            }
            
            // DYNAMIC PM RECALCULATION
            if ($type === 'Finishes') {
                $stmtQ = $pdo->prepare("SELECT finishes_calc_data FROM sales_quotes WHERE id = ?");
                $stmtQ->execute([$qId]);
                $qData = $stmtQ->fetchColumn();
                
                if ($qData) {
                    $fcDataParsed = json_decode($qData, true);
                    if (isset($fcDataParsed['fc_pm_pct'])) {
                        $pmPct = (float)$fcDataParsed['fc_pm_pct'];
                        $cleaningFee = isset($fcDataParsed['fc_cleaning_fee']) ? (float)$fcDataParsed['fc_cleaning_fee'] : 400.00; 

                        $subStmt = $pdo->prepare("SELECT COALESCE(SUM(estimated_qty * unit_rate), 0) FROM sales_quote_items WHERE quote_id = ? AND category NOT IN ('7 - Project Management', '8 - Discounts')");
                        $subStmt->execute([$qId]);
                        $subTotal = (float)$subStmt->fetchColumn();

                        $newPmFee = ($subTotal * ($pmPct / 100)) + $cleaningFee;
                        $pdo->prepare("UPDATE sales_quote_items SET unit_rate = ?, estimated_qty = 1 WHERE quote_id = ? AND category = '7 - Project Management'")->execute([$newPmFee, $qId]);
                    }
                }
            }
            
            $pdo->exec("UPDATE sales_quotes SET total_exc_vat = (SELECT COALESCE(SUM(estimated_qty * unit_rate), 0) FROM sales_quote_items WHERE quote_id = $qId) WHERE id = $qId");
            $pdo->exec("UPDATE sales_quotes SET total_inc_vat = total_exc_vat + (total_exc_vat * (vat_rate/100)) WHERE id = $qId");
            $message = "Item saved and quote totals recalculated.";
        }
        
        // 6. Delete BoQ Item (WITH DYNAMIC PM HOOK)
        elseif ($action === 'delete_item') {
            $qId = (int)$_POST['quote_id'];
            $type = $_POST['quote_type'];
            
            $pdo->prepare("DELETE FROM sales_quote_items WHERE id=?")->execute([$_POST['item_id']]);
            
            // DYNAMIC PM RECALCULATION
            if ($type === 'Finishes') {
                $stmtQ = $pdo->prepare("SELECT finishes_calc_data FROM sales_quotes WHERE id = ?");
                $stmtQ->execute([$qId]);
                $qData = $stmtQ->fetchColumn();
                
                if ($qData) {
                    $fcDataParsed = json_decode($qData, true);
                    if (isset($fcDataParsed['fc_pm_pct'])) {
                        $pmPct = (float)$fcDataParsed['fc_pm_pct'];
                        $cleaningFee = isset($fcDataParsed['fc_cleaning_fee']) ? (float)$fcDataParsed['fc_cleaning_fee'] : 400.00; 

                        $subStmt = $pdo->prepare("SELECT COALESCE(SUM(estimated_qty * unit_rate), 0) FROM sales_quote_items WHERE quote_id = ? AND category NOT IN ('7 - Project Management', '8 - Discounts')");
                        $subStmt->execute([$qId]);
                        $subTotal = (float)$subStmt->fetchColumn();

                        $newPmFee = ($subTotal * ($pmPct / 100)) + $cleaningFee;
                        $pdo->prepare("UPDATE sales_quote_items SET unit_rate = ?, estimated_qty = 1 WHERE quote_id = ? AND category = '7 - Project Management'")->execute([$newPmFee, $qId]);
                    }
                }
            }

            $pdo->exec("UPDATE sales_quotes SET total_exc_vat = (SELECT COALESCE(SUM(estimated_qty * unit_rate), 0) FROM sales_quote_items WHERE quote_id = $qId) WHERE id = $qId");
            $pdo->exec("UPDATE sales_quotes SET total_inc_vat = total_exc_vat + (total_exc_vat * (vat_rate/100)) WHERE id = $qId");
            $message = "Item deleted.";
        }
        
        // 7. Save or Update Claim
        elseif ($action === 'save_claim') {
            $qId = (int)$_POST['quote_id'];
            $type = $_POST['quote_type'];
            if (!$access[$type]['manage']) throw new Exception("Unauthorized.");
            
            $claimId = !empty($_POST['claim_id']) ? (int)$_POST['claim_id'] : null;
            
            $qStmt = $pdo->prepare("SELECT total_inc_vat, vat_rate FROM sales_quotes WHERE id = ?");
            $qStmt->execute([$qId]);
            $qData = $qStmt->fetch(PDO::FETCH_ASSOC);
            $totalQuoteIncVat = (float)$qData['total_inc_vat'];
            $vatRate = (float)$qData['vat_rate'];
            
            $claimMethod = $_POST['claim_method'] ?? 'fixed';
            $claimValue = (float)$_POST['claim_value'];
            
            if ($claimMethod === 'percent') {
                $inc = $totalQuoteIncVat * ($claimValue / 100);
            } else {
                $inc = $claimValue;
            }
            
            $exc = $inc / (1 + ($vatRate / 100));
            
            if ($claimId) {
                $stmt = $pdo->prepare("UPDATE sales_claims SET claim_type = ?, description = ?, amount_exc_vat = ?, amount_inc_vat = ?, status = ? WHERE id = ?");
                $stmt->execute([$_POST['claim_type'], $_POST['description'], $exc, $inc, $_POST['status'], $claimId]);
                $message = "Claim updated successfully.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO sales_claims (quote_id, claim_type, description, amount_exc_vat, amount_inc_vat, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$qId, $_POST['claim_type'], $_POST['description'], $exc, $inc, $_POST['status']]);
                $message = "Claim issued successfully.";
            }
        }
        
        // 8. Update Claim Status Directly
        elseif ($action === 'update_claim_status') {
            $qId = (int)$_POST['quote_id'];
            $date = $_POST['status'] === 'Paid' ? date('Y-m-d') : null;
            $stmt = $pdo->prepare("UPDATE sales_claims SET status = ?, paid_on = ? WHERE id = ?");
            $stmt->execute([$_POST['status'], $date, $_POST['claim_id']]);
            $message = "Claim status updated.";
        }

        // 9. Delete Claim
        elseif ($action === 'delete_claim') {
            $qId = (int)$_POST['quote_id'];
            $type = $_POST['quote_type'];
            if (!$access[$type]['manage']) throw new Exception("Unauthorized.");
            
            $pdo->prepare("DELETE FROM sales_claims WHERE id=?")->execute([$_POST['claim_id']]);
            $message = "Claim deleted securely.";
        }

        // 10. OHSA — reload full service catalogue onto quote
        elseif ($action === 'reload_ohsa_catalogue') {
            $qId = (int)$_POST['quote_id'];
            if (!$access['OHSA']['manage']) throw new Exception("Unauthorized.");

            $qCheck = $pdo->prepare("SELECT quote_type, status FROM sales_quotes WHERE id = ?");
            $qCheck->execute([$qId]);
            $qRow = $qCheck->fetch(PDO::FETCH_ASSOC);
            if (!$qRow || $qRow['quote_type'] !== 'OHSA') throw new Exception("Not an OHSA quote.");
            if (!in_array($qRow['status'], ['Draft', 'Rejected'])) throw new Exception("Quote is locked.");

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id = ?")->execute([$qId]);
            $stmtStd = $pdo->prepare("SELECT * FROM sales_standard_items WHERE quote_type = 'OHSA' AND is_active = 1 ORDER BY sort_order ASC, id ASC");
            $stmtStd->execute();
            $stdItems = $stmtStd->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($stdItems)) {
                $stmtItem = $pdo->prepare("INSERT INTO sales_quote_items (quote_id, category, description, unit, estimated_qty, unit_rate, sort_order) VALUES (?, ?, ?, ?, 0.00, ?, ?)");
                foreach ($stdItems as $item) {
                    $stmtItem->execute([$qId, $item['category'], $item['description'], $item['unit'], $item['default_rate'], $item['sort_order']]);
                }
            }
            $pdo->prepare("UPDATE sales_quotes SET total_exc_vat = (SELECT COALESCE(SUM(estimated_qty * unit_rate), 0) FROM sales_quote_items WHERE quote_id = ?) WHERE id = ?")->execute([$qId, $qId]);
            $pdo->prepare("UPDATE sales_quotes SET total_inc_vat = total_exc_vat + (total_exc_vat * (vat_rate/100)) WHERE id = ?")->execute([$qId]);
            $pdo->commit();
            $message = empty($stdItems) ? "No OHSA services found in catalogue — ask admin to seed rates." : "OHSA service catalogue reloaded.";
        }

        // 11. OHSA — add single service from catalogue
        elseif ($action === 'add_catalogue_item') {
            $qId = (int)$_POST['quote_id'];
            if (!$access['OHSA']['manage']) throw new Exception("Unauthorized.");

            $stdId = (int)$_POST['standard_item_id'];
            $stmt = $pdo->prepare("SELECT * FROM sales_standard_items WHERE id = ? AND quote_type = 'OHSA' AND is_active = 1");
            $stmt->execute([$stdId]);
            $std = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$std) throw new Exception("Service not found in catalogue.");

            $stmt = $pdo->prepare("INSERT INTO sales_quote_items (quote_id, category, description, unit, estimated_qty, unit_rate, sort_order) VALUES (?, ?, ?, ?, 0.00, ?, ?)");
            $stmt->execute([$qId, $std['category'], $std['description'], $std['unit'], $std['default_rate'], $std['sort_order']]);
            $pdo->prepare("UPDATE sales_quotes SET total_exc_vat = (SELECT COALESCE(SUM(estimated_qty * unit_rate), 0) FROM sales_quote_items WHERE quote_id = ?) WHERE id = ?")->execute([$qId, $qId]);
            $pdo->prepare("UPDATE sales_quotes SET total_inc_vat = total_exc_vat + (total_exc_vat * (vat_rate/100)) WHERE id = ?")->execute([$qId]);
            $message = "Service added from catalogue.";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ==========================================
// DETERMINE VIEW
// ==========================================
$viewQuoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : null;

// Prep Finishes Calculator Memory
$fcData = []; 
function fcv($key, $default = '') { global $fcData; return htmlspecialchars($fcData[$key] ?? $default); }

if ($viewQuoteId) {
    // --- DETAILS VIEW ---
    $stmt = $pdo->prepare("
        SELECT sq.*, con.name as contractor_name, c.name as linked_client_name, p.name as project_name 
        FROM sales_quotes sq LEFT JOIN clients con ON sq.contractor_id = con.id LEFT JOIN clients c ON sq.client_id = c.id LEFT JOIN projects p ON sq.project_id = p.id WHERE sq.id = ?
    ");
    $stmt->execute([$viewQuoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quote || !$access[$quote['quote_type']]['view']) { header('Location: work_sales.php?error=unauthorized_quote'); exit; }
    
    // Load Calculator Memory
    if (!empty($quote['finishes_calc_data'])) {
        $fcData = json_decode($quote['finishes_calc_data'], true) ?: [];
    }
    
    $selected_contractor_id = $quote['contractor_id']; 
    $effectiveClientName = !empty($quote['linked_client_name']) ? $quote['linked_client_name'] : $quote['client_name_free'];
    
    $items = $pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id = ? ORDER BY sort_order ASC, category ASC, id ASC");
    $items->execute([$viewQuoteId]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
    
    $claims = $pdo->prepare("SELECT * FROM sales_claims WHERE quote_id = ? ORDER BY created_at DESC");
    $claims->execute([$viewQuoteId]);
    $claims = $claims->fetchAll(PDO::FETCH_ASSOC);
    
    $canManageQuote = $access[$quote['quote_type']]['manage'];
    $isQuoteLocked = !in_array($quote['status'], ['Draft', 'Rejected']);
    $isOhsaQuote = ($quote['quote_type'] === 'OHSA');
    $ohsaCatalogue = [];
    if ($isOhsaQuote) {
        $catStmt = $pdo->query("SELECT * FROM sales_standard_items WHERE quote_type = 'OHSA' AND is_active = 1 ORDER BY sort_order ASC, id ASC");
        $ohsaCatalogue = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $pageTitle = "Quote: " . $quote['reference_number'];
    
} else {
    // --- MASTER LIST VIEW ---
    $currentTab = $_GET['tab'] ?? '';
    if (!isset($access[$currentTab]) || !$access[$currentTab]['view']) {
        foreach ($access as $k => $v) { if ($v['view']) { $currentTab = $k; break; } }
    }
    if (empty($currentTab)) die("No access.");

    $filterStatus = $_GET['filter_status'] ?? '';
    $filterProject = $_GET['filter_project'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'recent';
    
    // Get unique projects for the filter dropdown
    $filterProjectsStmt = $pdo->prepare("SELECT DISTINCT p.id, p.name FROM projects p JOIN sales_quotes sq ON p.id = sq.project_id WHERE sq.contractor_id = ? AND sq.quote_type = ? ORDER BY p.name ASC");
    $filterProjectsStmt->execute([$selected_contractor_id, $currentTab]);
    $availableFilterProjects = $filterProjectsStmt->fetchAll(PDO::FETCH_ASSOC);

    $quotesList = [];
    if ($selected_contractor_id) {
        $query = "
            SELECT sq.*, c.name as linked_client_name, p.name as project_name,
            (SELECT COALESCE(SUM(amount_inc_vat), 0) FROM sales_claims WHERE quote_id = sq.id) as total_claimed,
            (SELECT COALESCE(SUM(amount_inc_vat), 0) FROM sales_claims WHERE quote_id = sq.id AND status = 'Paid') as total_paid,
            (SELECT COALESCE(SUM(amount_inc_vat), 0) FROM sales_claims WHERE quote_id = sq.id AND status = 'Pending') as total_pending
            FROM sales_quotes sq LEFT JOIN clients c ON sq.client_id = c.id LEFT JOIN projects p ON sq.project_id = p.id 
            WHERE sq.quote_type = ? AND sq.contractor_id = ?
        ";
        $params = [$currentTab, $selected_contractor_id];
        
        if ($filterStatus) {
            $query .= " AND sq.status = ?";
            $params[] = $filterStatus;
        }
        if ($filterProject) {
            $query .= " AND sq.project_id = ?";
            $params[] = $filterProject;
        }
        
        if ($sortBy === 'project') {
            $query .= " ORDER BY p.name ASC, sq.created_at DESC";
        } elseif ($sortBy === 'status') {
            $query .= " ORDER BY sq.status ASC, sq.created_at DESC";
        } else {
            $query .= " ORDER BY sq.created_at DESC";
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $quotesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $projectsDb = getAccessibleProjects($pdo, $userId);
    $pageTitle = "Work Sales - Commercial";
}

function displayUnit($u) {
    $m = [
        'lump_sum' => 'Lump Sum', 'sqm' => 'sq.m', 'lm' => 'lm', 'cum' => 'cu.m', 'cu.yd' => 'cu.yd',
        'hrs' => 'Hours', 'hour' => 'Hour', 'qty' => 'Qty / Pcs',
        'visit' => 'Visit', 'participant' => 'Participant', 'procedure' => 'Procedure',
        'document' => 'Document', 'assessment' => 'Assessment',
    ];
    return $m[$u] ?? $u;
}

require_once 'header.php';
?>

<style>
.client-bar { background: rgba(99, 102, 241, 0.1); border: 1px solid var(--primary-color); padding: 1rem 1.5rem; border-radius: 8px; display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem; }
.client-bar select { padding: 0.5rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-card); color: var(--text-primary); font-size: 1rem; min-width: 250px; }
.tab-nav { display: flex; gap: 10px; border-bottom: 2px solid var(--border-glass); margin-bottom: 1.5rem; }
.tab-btn { padding: 10px 20px; color: var(--text-muted); text-decoration: none; font-weight: bold; border-bottom: 3px solid transparent; transition: 0.2s; }
.tab-btn:hover { color: var(--text-primary); }
.tab-btn.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }
.status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; }
.status-Draft { background: rgba(107, 114, 128, 0.2); color: #9ca3af; border: 1px solid #4b5563; }
.status-Pending { background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid #d97706; }
.status-Approved { background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid #059669; }
.status-Sent { background: rgba(59, 130, 246, 0.2); color: #3b82f6; border: 1px solid #2563eb; }
.status-Accepted { background: rgba(34, 197, 94, 0.2); color: #22c55e; border: 1px solid #16a34a; }
.status-Rejected { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid #dc2626; }
.status-Declined { background: rgba(100, 116, 139, 0.2); color: #64748b; border: 1px solid #475569; }
.status-Completed { background: rgba(139, 92, 246, 0.2); color: #8b5cf6; border: 1px solid #7c3aed; }
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 3% auto; padding: 2rem; border-radius: 12px; width: 90%; max-width: 600px; border: 1px solid var(--border-glass); box-shadow: 0 10px 25px rgba(0,0,0,0.5); position: relative; max-height: 90vh; overflow-y: auto;}
.close-modal { position: absolute; top: 15px; right: 20px; font-size: 1.5rem; color: var(--text-muted); cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }
.summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.summary-card { background: rgba(0,0,0,0.2); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--border-glass); text-align: center; }
.summary-card.highlight { background: rgba(14, 165, 233, 0.1); border-color: #0ea5e9; }
.summary-card h4 { margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase;}
.summary-card .value { font-size: 1.5rem; font-weight: bold; color: var(--text-primary); }
.boq-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.boq-table th { background: rgba(255,255,255,0.05); padding: 10px; text-align: left; color: var(--text-muted); font-weight: 600; }
.boq-table td { padding: 10px; border-bottom: 1px solid var(--border-glass); }
.boq-input { width: 100%; background: #1e1e2d; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 6px; border-radius: 4px; font-size: 0.8rem; }
</style>

<div class="main-container">

    <div class="client-bar">
        <strong style="color: var(--primary-color);">Account Context (Contractor):</strong>
        <form method="GET" style="margin: 0; display:flex; gap: 10px; align-items:center;">
            <?php if (isset($_GET['tab'])): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($_GET['tab']) ?>"><?php endif; ?>
            <select name="contractor_id" onchange="this.form.submit()">
                <option value="">-- Select Issuing Contractor --</option>
                <?php foreach($allEntities as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selected_contractor_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$selected_contractor_id): ?>
        <div class="empty-state" style="padding: 4rem 2rem; text-align: center; background: var(--bg-panel); border-radius: 8px;">
            <h2 style="color: var(--text-secondary);">Select a Contractor to Begin</h2>
            <p>Sales Quotes are strictly isolated by the entity issuing them. Please select the Contractor (PRA, PRAX, Excel, etc.) from the dropdown above.</p>
        </div>
    <?php else: ?>

        <?php if (!$viewQuoteId): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <h1 class="page-title" style="margin: 0;">Work Sales & Commercial</h1>
                    <p style="color: var(--text-secondary); margin-top: 0.25rem;">Manage commercial quotes, standard packages, and interim claims.</p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <?php if ($isAdmin): ?>
                        <a href="admin_standard_rates.php" class="btn btn-secondary">⚙️ Standard Rates & Terms</a>
                    <?php endif; ?>
                    <?php if ($access['Demolition_Excavation']['manage']): ?>
                        <button class="btn btn-primary" style="background: #3b82f6; border: none;" onclick="openCreateQuoteModal('Demolition_Excavation')">+ Demo/Exc Quote</button>
                    <?php endif; ?>
                    <?php if ($access['Construction']['manage']): ?>
                        <button class="btn btn-primary" style="background: #f59e0b; border: none;" onclick="openCreateQuoteModal('Construction')">+ Const. Quote</button>
                    <?php endif; ?>
                    <?php if ($access['Finishes']['manage']): ?>
                        <button class="btn btn-primary" style="background: #8b5cf6; border: none;" onclick="openCreateQuoteModal('Finishes')">+ Finishes Quote</button>
                    <?php endif; ?>
                    <?php if ($access['OHSA']['manage']): ?>
                        <button class="btn btn-primary" style="background: #14b8a6; border: none;" onclick="openCreateQuoteModal('OHSA')">+ OHSA Quote</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-nav">
                <?php if ($access['Demolition_Excavation']['view']): ?>
                    <a href="?contractor_id=<?= $selected_contractor_id ?>&tab=Demolition_Excavation" class="tab-btn <?= $currentTab === 'Demolition_Excavation' ? 'active' : '' ?>">Demolition & Excavation</a>
                <?php endif; ?>
                <?php if ($access['Construction']['view']): ?>
                    <a href="?contractor_id=<?= $selected_contractor_id ?>&tab=Construction" class="tab-btn <?= $currentTab === 'Construction' ? 'active' : '' ?>">Construction</a>
                <?php endif; ?>
                <?php if ($access['Finishes']['view']): ?>
                    <a href="?contractor_id=<?= $selected_contractor_id ?>&tab=Finishes" class="tab-btn <?= $currentTab === 'Finishes' ? 'active' : '' ?>">Turnkey & Finishes</a>
                <?php endif; ?>
                <?php if ($access['OHSA']['view']): ?>
                    <a href="?contractor_id=<?= $selected_contractor_id ?>&tab=OHSA" class="tab-btn <?= $currentTab === 'OHSA' ? 'active' : '' ?>">OHSA / Health & Safety</a>
                <?php endif; ?>
            </div>

            <form method="GET" style="display:flex; gap:10px; margin-bottom: 1.5rem; background: var(--bg-panel); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-glass); align-items: flex-end;">
                <input type="hidden" name="contractor_id" value="<?= $selected_contractor_id ?>">
                <input type="hidden" name="tab" value="<?= $currentTab ?>">
                
                <div style="flex:1;">
                    <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:4px;">Quotation Status</label>
                    <select name="filter_status" style="width:100%; padding:0.5rem; border-radius:4px; border:1px solid var(--border-glass); background:var(--bg-card); color:#fff;">
                        <option value="">All Statuses</option>
                        <option value="Draft" <?= $filterStatus==='Draft'?'selected':'' ?>>Draft</option>
                        <option value="Pending Approval" <?= $filterStatus==='Pending Approval'?'selected':'' ?>>Pending Approval</option>
                        <option value="Approved" <?= $filterStatus==='Approved'?'selected':'' ?>>Approved</option>
                        <option value="Sent" <?= $filterStatus==='Sent'?'selected':'' ?>>Sent</option>
                        <option value="Accepted" <?= $filterStatus==='Accepted'?'selected':'' ?>>Accepted</option>
                        <option value="Declined" <?= $filterStatus==='Declined'?'selected':'' ?>>Declined</option>
                        <option value="Completed" <?= $filterStatus==='Completed'?'selected':'' ?>>Completed</option>
                    </select>
                </div>
                
                <div style="flex:2;">
                    <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:4px;">Project Filter</label>
                    <select name="filter_project" style="width:100%; padding:0.5rem; border-radius:4px; border:1px solid var(--border-glass); background:var(--bg-card); color:#fff;">
                        <option value="">All Projects</option>
                        <?php foreach($availableFilterProjects as $afp): ?>
                            <option value="<?= $afp['id'] ?>" <?= $filterProject==$afp['id']?'selected':'' ?>><?= htmlspecialchars($afp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="flex:1;">
                    <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:4px;">Sort By</label>
                    <select name="sort_by" style="width:100%; padding:0.5rem; border-radius:4px; border:1px solid var(--border-glass); background:var(--bg-card); color:#fff;">
                        <option value="recent" <?= $sortBy==='recent'?'selected':'' ?>>Most Recent First</option>
                        <option value="project" <?= $sortBy==='project'?'selected':'' ?>>Project Name (A-Z)</option>
                        <option value="status" <?= $sortBy==='status'?'selected':'' ?>>Quote Status</option>
                    </select>
                </div>
                
                <div>
                    <button type="submit" class="btn btn-secondary" style="margin:0; padding:0.5rem 1.5rem;">Filter Quotes</button>
                    <?php if($filterStatus || $filterProject || $sortBy !== 'recent'): ?>
                        <a href="?contractor_id=<?= $selected_contractor_id ?>&tab=<?= $currentTab ?>" class="btn btn-sm" style="background: rgba(239,68,68,0.2); color: #ef4444; border:none; text-decoration:none; padding:0.5rem 1rem; margin-left: 5px;">Clear Filters</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php
            $global_order = 0; $global_claimed = 0; $global_pending = 0; $global_paid = 0;
            foreach ($quotesList as $q) {
                // Ignore Rejected and Declined quotes in the Pipeline Total
                if (!in_array($q['status'], ['Rejected', 'Declined'])) {
                    $global_order += $q['total_inc_vat'];
                }
                $global_claimed += $q['total_claimed'];
                $global_pending += $q['total_pending'];
                $global_paid += $q['total_paid'];
            }
            $global_pending_works = $global_order - $global_claimed;
            ?>
            <div class="summary-cards">
                <div class="summary-card highlight">
                    <h4 style="color: var(--primary-color);">Total Order (Inc VAT)</h4>
                    <div class="value">€<?= number_format($global_order, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Pending Works</h4>
                    <div class="value" style="color: #a855f7;">€<?= number_format($global_pending_works, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Claimed</h4>
                    <div class="value" style="color: #3b82f6;">€<?= number_format($global_claimed, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Pending Payment</h4>
                    <div class="value" style="color: <?= $global_pending > 0 ? '#f59e0b' : '#9ca3af' ?>;">€<?= number_format($global_pending, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Paid</h4>
                    <div class="value" style="color: #10b981;">€<?= number_format($global_paid, 2) ?></div>
                </div>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Reference</th>
                            <th>Project & Billed Client</th>
                            <th style="text-align: right;">Total (Inc VAT)</th>
                            <th style="text-align: right;">Unclaimed Works</th>
                            <th style="text-align: right;">Claimed</th>
                            <th style="text-align: right;">Pending Pay</th>
                            <th style="text-align: right;">Paid</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($quotesList)): ?>
                            <tr><td colspan="9" style="text-align: center; padding: 2rem;">No quotes found matching your criteria.</td></tr>
                        <?php else: foreach ($quotesList as $q): 
                            $effName = !empty($q['linked_client_name']) ? $q['linked_client_name'] : $q['client_name_free'];
                        ?>
                            <tr>
                                <td>
                                    <?php 
                                        $dispStat = str_replace(' Approval', '', $q['status']); 
                                        $statusText = $q['status'] === 'Approved' ? 'Internally Approved - To Send' : $q['status'];
                                    ?>
                                    <span class="status-badge status-<?= $dispStat ?>"><?= $statusText ?></span>
                                </td>
                                <td style="font-weight: bold; color: var(--text-primary);"><?= htmlspecialchars($q['reference_number']) ?></td>
                                <td>
                                    <div><?= htmlspecialchars($q['project_name'] ?? $q['project_name_free'] ?? 'Unlinked / General') ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">Billed To: <?= htmlspecialchars($effName ?? 'Unknown') ?></div>
                                </td>
                                <td style="text-align: right; font-weight: bold;">€<?= number_format($q['total_inc_vat'], 2) ?></td>
                                <td style="text-align: right; color: #a855f7;">€<?= number_format($q['total_inc_vat'] - $q['total_claimed'], 2) ?></td>
                                <td style="text-align: right; color: #3b82f6;">€<?= number_format($q['total_claimed'], 2) ?></td>
                                <td style="text-align: right; color: #f59e0b;">€<?= number_format($q['total_pending'], 2) ?></td>
                                <td style="text-align: right; color: #10b981;">€<?= number_format($q['total_paid'], 2) ?></td>
                                <td style="text-align: center;">
                                    <a href="?contractor_id=<?= $selected_contractor_id ?>&quote_id=<?= $q['id'] ?>" class="btn btn-sm btn-secondary">Manage</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <?php if (!empty($quotesList)): ?>
                    <tfoot>
                        <tr style="background: rgba(255,255,255,0.05); font-weight: bold;">
                            <td colspan="3">PIPELINE TOTALS</td>
                            <td style="text-align: right;">€<?= number_format($global_order, 2) ?></td>
                            <td style="text-align: right; color: #a855f7;">€<?= number_format($global_pending_works, 2) ?></td>
                            <td style="text-align: right; color: #3b82f6;">€<?= number_format($global_claimed, 2) ?></td>
                            <td style="text-align: right; color: <?= $global_pending > 0 ? '#f59e0b' : '#9ca3af' ?>;">€<?= number_format($global_pending, 2) ?></td>
                            <td style="text-align: right; color: #10b981;">€<?= number_format($global_paid, 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <?php if ($access['Demolition_Excavation']['manage'] || $access['Construction']['manage'] || $access['Finishes']['manage'] || $access['OHSA']['manage']): ?>
            <div id="createQuoteModal" class="modal">
                <div class="modal-content">
                    <span class="close-modal" onclick="document.getElementById('createQuoteModal').style.display='none'">&times;</span>
                    <h2 id="createQuoteModalTitle" style="margin-top: 0; color: var(--primary-color);">Create Quote</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_quote">
                        <input type="hidden" name="quote_type" id="create_quote_type" value="">
                        <input type="hidden" name="contractor_id" value="<?= $selected_contractor_id ?>">
                        <input type="hidden" id="gen_contractor_prefix" value="<?= $contractorPrefix ?>">
                        
                        <div class="form-group" style="background: rgba(255,255,255,0.02); padding: 15px; border-radius: 8px; border: 1px solid var(--border-glass); margin-bottom: 15px;">
                            <label style="color: var(--primary-color);">1. Who is the Client? (Choose ONE option)</label>
                            
                            <div style="margin-top: 10px;">
                                <label style="font-size: 0.8rem; color: var(--text-muted);">Option A: Select Existing Client / Internal Company</label>
                                <select name="client_id" onchange="autoGenRef()">
                                    <option value="">-- Select Existing Client --</option>
                                    <?php foreach($allEntities as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div style="text-align: center; margin: 10px 0; color: var(--text-muted); font-size: 0.8rem;">--- OR ---</div>
                            
                            <div>
                                <label style="font-size: 0.8rem; color: var(--text-muted);">Option B: External Client (Free Text)</label>
                                <input type="text" name="client_name_free" onkeyup="autoGenRef()" placeholder="e.g. John Doe / External Ltd">
                            </div>
                        </div>

                       <div class="form-group" style="background: rgba(255,255,255,0.02); padding: 15px; border-radius: 8px; border: 1px solid var(--border-glass); margin-bottom: 15px;">
                            <label id="create_project_label" style="color: var(--primary-color);">2. Project Reference</label>
                            
                            <div style="margin-top: 10px;">
                                <label style="font-size: 0.8rem; color: var(--text-muted);">Option A: Select Existing Project</label>
                                <select name="project_id" id="create_project_select" onchange="autoGenRef()">
                                    <option value="">-- Select Project --</option>
                                    <?php foreach($projectsDb as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['client_name']) ?>)</option><?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div style="text-align: center; margin: 10px 0; color: var(--text-muted); font-size: 0.8rem;">--- OR ---</div>
                            
                            <div>
                                <label style="font-size: 0.8rem; color: var(--text-muted);">Option B: Free-Text Project Reference</label>
                                <input type="text" name="project_name_free" onkeyup="autoGenRef()" placeholder="e.g. Block A Renovation">
                            </div>
                        </div>
                        <div class="form-grid" style="grid-template-columns: 2fr 1fr; gap: 10px;">
                            <div class="form-group">
                                <label>Quote Reference / Number *</label>
                                <input type="text" name="reference_number" id="create_ref_input" placeholder="e.g. PRAX-2026-04" required>
                            </div>
                            <div class="form-group">
                                <label>VAT Rate %</label>
                                <select name="vat_rate"><option value="18.00">18%</option><option value="0.00">0%</option></select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Create Quote</button>
                        <p style="text-align: center; color: var(--text-muted); font-size: 0.75rem; margin-top: 10px;">Standard BoQ rates & Terms will be auto-populated upon creation with a quantity of 0.</p>
                    </form>
                </div>
            </div>
            
            <script>
           
            function openCreateQuoteModal(type) {
                let title = 'Create Quote';
                let projLabel = document.getElementById('create_project_label');

                if (type === 'Demolition_Excavation') {
                    title = 'Create Demolition & Excavation Quote';
                    projLabel.innerText = '2. Project Reference (Optional)';
                } else {
                    if (type === 'Construction') title = 'Create Construction Quote';
                    if (type === 'Finishes') title = 'Create Turnkey & Finishes Quote';
                    if (type === 'OHSA') title = 'Create OHSA / Health & Safety Quote';
                    projLabel.innerText = '2. Project Reference (Required) *';
                }
                
                document.getElementById('createQuoteModalTitle').innerText = title;
                document.getElementById('create_quote_type').value = type;
                autoGenRef();
                document.getElementById('createQuoteModal').style.display = 'block';
            }
            
            function autoGenRef() {
                let contractor = document.getElementById('gen_contractor_prefix').value;
                
                // Project Ref Generation
                let projSel = document.querySelector('select[name="project_id"]');
                let projText = projSel.options[projSel.selectedIndex]?.text || '';
                let projFree = document.querySelector('input[name="project_name_free"]').value;
                
                let effProj = (projSel.value !== '') ? projText : projFree;
                let projPrefix = effProj.split(' ')[0].replace(/[^A-Za-z0-9]/g, '').substring(0, 4).toUpperCase();
                if (!projPrefix || projPrefix === '-- S') projPrefix = 'PRJ';
                
                // Client Ref Generation
                let clientSel = document.querySelector('select[name="client_id"]');
                let clientText = clientSel.options[clientSel.selectedIndex]?.text || '';
                let clientFree = document.querySelector('input[name="client_name_free"]').value;
                
                let effClient = (clientSel.value !== '') ? clientText : clientFree;
                let clientPrefix = effClient.split(' ')[0].replace(/[^A-Za-z0-9]/g, '').substring(0, 4).toUpperCase();
                if (!clientPrefix || clientPrefix === '-- S') clientPrefix = 'CLI';

                let d = new Date();
                let yr = d.getFullYear();
                let rnd = Math.floor(100 + Math.random() * 900);
                
                document.getElementById('create_ref_input').value = `${contractor}-${clientPrefix}-${projPrefix}-${yr}-${rnd}`;
            }

            window.addEventListener('click', function(event) {
                let modal = document.getElementById('createQuoteModal');
                if (event.target == modal) modal.style.display = "none";
            });
                       
            </script>
            <?php endif; ?>

        <?php else: ?>
            
            <?php 
            $tClaimed = 0; $tPaid = 0; $tPend = 0; 
            foreach($claims as $c) { 
                $tClaimed += $c['amount_inc_vat'];
                if($c['status']==='Paid') $tPaid += $c['amount_inc_vat']; 
                else $tPend += $c['amount_inc_vat']; 
            }
            $tPendingWorks = $quote['total_inc_vat'] - $tClaimed;
            
            $canPrint = in_array($quote['status'], ['Approved', 'Sent', 'Accepted', 'Declined', 'Completed']);
            $dispStat = str_replace(' Approval', '', $quote['status']);
            $statusText = $quote['status'] === 'Approved' ? 'Internally Approved - To Send' : $quote['status'];
            ?>

            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                <div>
                    <a href="work_sales.php?contractor_id=<?= $selected_contractor_id ?>&tab=<?= $quote['quote_type'] ?>" style="color: var(--text-muted); text-decoration: none; font-size: 0.9rem;">&larr; Back to <?= str_replace('_', ' & ', $quote['quote_type']) ?> List</a>
                    <h1 class="page-title" style="margin-bottom: 0.25rem; margin-top: 0.5rem;"><?= htmlspecialchars($quote['reference_number']) ?></h1>
                    <div style="color: var(--text-secondary); font-size: 0.9rem;">
                        <strong>Billed To:</strong> <?= htmlspecialchars($effectiveClientName ?? 'Unknown') ?> | 
                        <strong>Project:</strong> <?= htmlspecialchars($quote['project_name'] ?? $quote['project_name_free'] ?? 'Unlinked / General') ?>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span class="status-badge status-<?= $dispStat ?>" style="font-size: 1rem; padding: 6px 15px;"><?= $statusText ?></span>
                    
                    <?php if ($canPrint): ?>
                        <a href="print_quote.php?quote_id=<?= $quote['id'] ?>" target="_blank" class="btn btn-secondary">📄 View & Print PDF</a>
                    <?php else: ?>
                        <button class="btn btn-secondary" style="opacity: 0.5; cursor: not-allowed;" title="Quote must be Approved before printing.">🔒 Print PDF</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="summary-cards">
                <div class="summary-card highlight">
                    <h4 style="color: var(--primary-color);">Total Order (Inc VAT)</h4>
                    <div class="value">€<?= number_format($quote['total_inc_vat'], 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Pending Works</h4>
                    <div class="value" style="color: #a855f7;">€<?= number_format($tPendingWorks, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Claimed</h4>
                    <div class="value" style="color: #3b82f6;">€<?= number_format($tClaimed, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Pending Payment</h4>
                    <div class="value" style="color: <?= $tPend > 0 ? '#f59e0b' : '#9ca3af' ?>;">€<?= number_format($tPend, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Paid</h4>
                    <div class="value" style="color: #10b981;">€<?= number_format($tPaid, 2) ?></div>
                </div>
            </div>

            <div class="two-column-layout" style="grid-template-columns: 2fr 1fr;">
                
                <div class="section-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                        <h2 style="margin: 0;"><?= $isOhsaQuote ? 'Services Schedule' : 'Bill of Quantities (BoQ)' ?></h2>
                        <?php if ($canManageQuote && !$isQuoteLocked): ?>
                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                <?php if ($quote['quote_type'] === 'Finishes'): ?>
                                    <button class="btn btn-sm" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; border: 1px solid #8b5cf6;" onclick="document.getElementById('fcModal').style.display='block'">⚡ Full Finishes Calc</button>
                                    <button class="btn btn-sm" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid #f59e0b;" onclick="document.getElementById('sfModal').style.display='block'">🔨 Semi-Finishes Quote Only</button>
                                <?php elseif ($isOhsaQuote): ?>
                                    <button class="btn btn-sm" style="background: rgba(20, 184, 166, 0.1); color: #14b8a6; border: 1px solid #14b8a6;" onclick="document.getElementById('ohsaCatalogueModal').style.display='block'">📋 Add from Rate Catalogue</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Replace all line items with the current OHSA rate catalogue?');">
                                        <input type="hidden" name="action" value="reload_ohsa_catalogue">
                                        <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary">↻ Reload Catalogue</button>
                                    </form>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-primary" onclick="openItemModal()"><?= $isOhsaQuote ? '+ Custom Service' : '+ Add Line Item' ?></button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($isOhsaQuote && empty($ohsaCatalogue)): ?>
                        <div class="alert alert-info" style="margin-bottom: 1rem; font-size: 0.85rem;">
                            No OHSA services in the rate catalogue yet. An admin should run <code>sql/ohsa_standard_rates.sql</code> in phpMyAdmin, or add services via <a href="admin_standard_rates.php" style="color: var(--primary-color);">Standard Quote Rates</a>.
                        </div>
                    <?php endif; ?>

                    <table class="boq-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Unit</th>
                                <th style="text-align: right;">Qty</th>
                                <th style="text-align: right;">Rate</th>
                                <th style="text-align: right;">Total (€)</th>
                                <?php if($canManageQuote && !$isQuoteLocked): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($items)): ?>
                                <tr><td colspan="<?= ($canManageQuote && !$isQuoteLocked) ? 7 : 6 ?>" style="text-align: center; color: var(--text-muted);">No items added yet.</td></tr>
                            <?php else: 
                                $currentCat = '';
                                foreach($items as $i): 
                                    if ($i['category'] !== $currentCat) {
                                        echo "<tr><td colspan='".(($canManageQuote && !$isQuoteLocked) ? 7 : 6)."' style='background: rgba(255,255,255,0.02); color: var(--primary-color); font-weight: bold;'>".htmlspecialchars($i['category'])."</td></tr>";
                                        $currentCat = $i['category'];
                                    }
                            ?>
                                <tr>
                                    <td></td>
                                    <td><?= nl2br(htmlspecialchars($i['description'])) ?></td>
                                    <td><?= displayUnit($i['unit']) ?></td>
                                    <td style="text-align: right;"><?= (float)$i['estimated_qty'] ?></td>
                                    <td style="text-align: right;">€<?= number_format($i['unit_rate'], 2) ?></td>
                                    <td style="text-align: right; font-weight: bold;">€<?= number_format($i['estimated_qty'] * $i['unit_rate'], 2) ?></td>
                                    <?php if($canManageQuote && !$isQuoteLocked): ?>
                                        <td style="text-align: right; min-width: 75px;">
                                            <button class="btn btn-sm btn-secondary" style="padding: 2px 6px;" onclick='openItemModal(<?= json_encode($i, JSON_HEX_APOS) ?>)'>✎</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this line item?');">
                                                <input type="hidden" name="action" value="delete_item"><input type="hidden" name="quote_id" value="<?= $quote['id'] ?>"><input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>"><input type="hidden" name="item_id" value="<?= $i['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" style="padding: 2px 6px;">X</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div>
                    <?php if ($canManageQuote): ?>
                    <div class="section-card" style="margin-bottom: 1.5rem; border: 1px solid var(--primary-color);">
                        <h2 style="margin-top: 0; margin-bottom: 1rem; color: var(--primary-color);">Quote Workflow</h2>
                        
                        <?php if ($quote['status'] === 'Draft' || $quote['status'] === 'Rejected'): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                                <input type="hidden" name="new_status" value="Pending Approval">
                                <button type="submit" class="btn btn-primary" style="width:100%;">Submit for Approval</button>
                            </form>
                        
                        <?php elseif ($quote['status'] === 'Pending Approval'): ?>
                            <?php if ($canApproveQuotes): ?>
                                <div style="display: flex; gap: 10px;">
                                    <form method="POST" style="flex:1;">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                                        <input type="hidden" name="new_status" value="Approved">
                                        <button type="submit" class="btn" style="background:#10b981; color:white; width:100%; border:none;">Approve</button>
                                    </form>
                                    <form method="POST" style="flex:1;">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                                        <input type="hidden" name="new_status" value="Rejected">
                                        <button type="submit" class="btn btn-danger" style="width:100%;">Reject</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info" style="margin:0; text-align:center;">Waiting for manager authorization...</div>
                            <?php endif; ?>

                        <?php else: ?>
                            <form method="POST" style="display:flex; gap:10px;">
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                                <select name="new_status" style="flex:1;">
                                    <option value="Approved" <?= $quote['status']=='Approved'?'selected':'' ?>>Internally Approved - To Send</option>
                                    <option value="Sent" <?= $quote['status']=='Sent'?'selected':'' ?>>Sent to Client</option>
                                    <option value="Accepted" <?= $quote['status']=='Accepted'?'selected':'' ?>>Accepted by Client</option>
                                    <option value="Declined" <?= $quote['status']=='Declined'?'selected':'' ?>>Declined by Client (Lost)</option>
                                    <option value="Completed" <?= $quote['status']=='Completed'?'selected':'' ?>>Completed / Billed</option>
                                </select>
                                <button type="submit" class="btn btn-secondary">Update</button>
                            </form>
                            
                            <?php if ($isQuoteLocked && $canApproveQuotes): ?>
                                <form method="POST" style="margin-top: 15px;" onsubmit="return confirm('Are you sure you want to unlock this quote? It will be reverted to Draft status to allow changes.');">
                                    <input type="hidden" name="action" value="unlock_quote">
                                    <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="width:100%; border:none; background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid #ef4444;">🔓 Unlock Quote for Editing (Revert to Draft)</button>
                                </form>
                            <?php endif; ?>
                            
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="section-card" style="margin-bottom: 1.5rem; background: rgba(59, 130, 246, 0.02); border-color: rgba(59, 130, 246, 0.2);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                            <h2 style="margin: 0; color: #3b82f6;">Claims & Invoicing</h2>
                            <?php if ($canManageQuote && in_array($quote['status'], ['Accepted', 'Completed'])): ?>
                                <button class="btn btn-sm" style="background: #3b82f6; color: white; border: none;" onclick="openClaimModal()">+ Issue Claim</button>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!in_array($quote['status'], ['Accepted', 'Completed'])): ?>
                            <div style="font-size: 0.8rem; color: var(--text-muted); text-align: center; padding: 1rem;">Quote must be 'Accepted' to issue claims.</div>
                        <?php elseif (empty($claims)): ?>
                            <div style="font-size: 0.8rem; color: var(--text-muted); text-align: center; padding: 1rem;">No claims issued yet.</div>
                        <?php else: ?>
                            <table style="width: 100%; font-size: 0.8rem; border-collapse: collapse;">
                                <thead>
                                    <tr>
                                        <th style="text-align: left; padding-bottom: 5px; color: var(--text-muted);">Type / Stage</th>
                                        <th style="text-align: right; padding-bottom: 5px; color: var(--text-muted);">Amount</th>
                                        <th style="text-align: center; padding-bottom: 5px; color: var(--text-muted);">Status</th>
                                        <th style="text-align: right; padding-bottom: 5px; color: var(--text-muted);">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach($claims as $c): ?>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid var(--border-glass);">
                                            <strong><?= $c['claim_type'] ?></strong><br>
                                            <span style="color: var(--text-muted);"><?= htmlspecialchars($c['description']) ?></span>
                                        </td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid var(--border-glass); text-align: right; font-weight: bold;">
                                            €<?= number_format($c['amount_inc_vat'], 2) ?>
                                        </td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid var(--border-glass); text-align: center;">
                                            <?php if ($canManageQuote): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_claim_status">
                                                    <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>"><input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>">
                                                    <input type="hidden" name="claim_id" value="<?= $c['id'] ?>">
                                                    <select name="status" onchange="this.form.submit()" style="background: <?= $c['status'] === 'Paid' ? 'rgba(16,185,129,0.1)' : 'rgba(245,158,11,0.1)' ?>; color: <?= $c['status'] === 'Paid' ? '#10b981' : '#f59e0b' ?>; border: 1px solid <?= $c['status'] === 'Paid' ? '#10b981' : '#f59e0b' ?>; border-radius: 4px; padding: 2px; font-size: 0.75rem; font-weight: bold; cursor: pointer;">
                                                        <option value="Pending" <?= $c['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                        <option value="Paid" <?= $c['status'] === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                                    </select>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: <?= $c['status'] === 'Paid' ? '#10b981' : '#f59e0b' ?>; font-weight: bold;"><?= $c['status'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid var(--border-glass); text-align: right;">
                                            <a href="print_claim.php?claim_id=<?= $c['id'] ?>" target="_blank" class="btn btn-sm" style="background: #3B82F6; color: white; padding: 2px 6px;" title="Print RFP">📄 PDF</a>
                                            <?php if ($canManageQuote): ?>
                                                <button onclick='openClaimModal(<?= json_encode($c, JSON_HEX_APOS) ?>)' class="btn btn-sm btn-secondary" style="padding: 2px 6px;" title="Edit">✎</button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this claim?');">
                                                    <input type="hidden" name="action" value="delete_claim">
                                                    <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                                                    <input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>">
                                                    <input type="hidden" name="claim_id" value="<?= $c['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" style="padding: 2px 6px;" title="Delete">X</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <?php if ($canManageQuote): ?>
                    <div class="section-card">
                        <h2 style="margin-top: 0; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Settings & Terms</h2>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_quote_settings">
                            <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                            <input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>">
                            
                            <div class="form-group">
                                <label>VAT Rate %</label>
                                <select name="vat_rate">
                                    <option value="18.00" <?= $quote['vat_rate'] == 18 ? 'selected' : '' ?>>18% Standard</option>
                                    <option value="0.00" <?= $quote['vat_rate'] == 0 ? 'selected' : '' ?>>0% Exempt</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Terms & Conditions</label>
                                <textarea name="terms_conditions" rows="6" style="font-size: 0.8rem;"><?= htmlspecialchars($quote['terms_conditions'] ?? '') ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-secondary" style="width: 100%;">Save Settings</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($canManageQuote && !$isQuoteLocked && $quote['quote_type'] === 'Finishes'): ?>
            <div id="fcModal" class="modal">
                <div class="modal-content" style="max-width: 800px; padding: 0;">
                    <div style="background: rgba(139, 92, 246, 0.1); border-bottom: 1px solid rgba(139, 92, 246, 0.3); padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; border-radius: 12px 12px 0 0;">
                        <h2 style="margin: 0; color: #8b5cf6;">⚡ Full Finishes Calculator Engine</h2>
                        <span class="close-modal" onclick="document.getElementById('fcModal').style.display='none'" style="position: static;">&times;</span>
                    </div>
                    
                    <form method="POST" style="padding: 1.5rem; max-height: 70vh; overflow-y: auto;" id="fcForm">
                        <input type="hidden" name="action" value="generate_finishes_boq">
                        <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                        
                        <div style="background: rgba(239, 68, 68, 0.05); padding: 10px; border-left: 4px solid #ef4444; margin-bottom: 20px; font-size: 0.85rem;">
                            <strong>Warning:</strong> Generating a calculated BoQ will completely overwrite and delete any existing items in this quote.
                        </div>

                        <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px;">1. Project Scope</h4>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label>Apartment State</label>
                                <select name="fc_state" id="fc_state" onchange="toggleFcSections()">
                                    <option value="semi_finished" <?= fcv('fc_state') == 'semi_finished' ? 'selected' : '' ?>>Client Bought Semi-Finished (Internal Only)</option>
                                    <option value="common_parts" <?= fcv('fc_state') == 'common_parts' ? 'selected' : '' ?>>Client Bought Common Parts Only (Includes Semi-Finishes)</option>
                                </select>
                            </div>
                            <div class="form-group" style="display: flex; align-items: center; padding-top: 15px;">
                                <label class="checkbox-item" style="font-weight: bold;">
                                    <input type="checkbox" name="fc_garage" id="fc_garage" onchange="toggleFcSections()" <?= !empty($fcData['fc_garage']) ? 'checked' : '' ?>> Include Garage Finishes
                                </label>
                            </div>
                        </div>

                        <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; margin-top: 20px;">2. General Measurements</h4>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                            <div class="form-group"><label>Internal Area [A] (sqm) *</label><input type="number" step="0.01" name="fc_area_int" value="<?= fcv('fc_area_int') ?>" required></div>
                            <div class="form-group"><label>External Area [B] (sqm) *</label><input type="number" step="0.01" name="fc_area_ext" value="<?= fcv('fc_area_ext') ?>" required></div>
                            <div class="form-group"><label>Floor-to-Ceiling [C] (m) *</label><input type="number" step="0.01" name="fc_height" value="<?= fcv('fc_height', '2.65') ?>" required></div>
                            <div class="form-group"><label>Skirting Length [E] (lm) *</label><input type="number" step="0.01" name="fc_skirting" value="<?= fcv('fc_skirting') ?>" required></div>
                            <div class="form-group" id="fc_balc_perim_group"><label>Balcony Perimeter (lm) *</label><input type="number" step="0.01" name="fc_balcony_perim" id="fc_balcony_perim" value="<?= fcv('fc_balcony_perim') ?>"></div>
                        </div>

                        <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; margin-top: 20px;">3. Rooms & Wet Areas</h4>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group"><label>Total Bedrooms [D] (inc. study/gym) *</label><input type="number" name="fc_beds" id="fc_beds" value="<?= fcv('fc_beds', '1') ?>" required onchange="calcFcDoors()"></div>
                            <div class="form-group"><label>Bathrooms WITH Shower [F] *</label><input type="number" name="fc_bath_shower" id="fc_bath_shower" value="<?= fcv('fc_bath_shower', '1') ?>" required onchange="calcFcDoors()"></div>
                            <div class="form-group"><label>Bathrooms WITH Bath [G] *</label><input type="number" name="fc_bath_bath" id="fc_bath_bath" value="<?= fcv('fc_bath_bath', '0') ?>" required onchange="calcFcDoors()"></div>
                            <div class="form-group"><label>Total Bathrooms Area [H] (sqm) *</label><input type="number" step="0.01" name="fc_bath_sqm" value="<?= fcv('fc_bath_sqm') ?>" required></div>
                            <div class="form-group"><label>Total Bath Walls Perimeter [I] (lm) *</label><input type="number" step="0.01" name="fc_bath_perim" value="<?= fcv('fc_bath_perim') ?>" required></div>
                        </div>

                        <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; margin-top: 20px;">4. Doors Configuration</h4>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 10px;">Total doors naturally required: <span id="fc_door_calc" style="font-weight:bold; color:var(--text-primary);">0</span>. Distribute them below, adding extras for box rooms if needed.</div>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                            <div class="form-group"><label>Hinged Doors [X1]</label><input type="number" name="fc_door_hinged" id="fc_door_hinged" value="<?= fcv('fc_door_hinged', '2') ?>" required></div>
                            <div class="form-group"><label>Sliding Doors [X2]</label><input type="number" name="fc_door_sliding" id="fc_door_sliding" value="<?= fcv('fc_door_sliding', '0') ?>" required></div>
                            <div class="form-group"><label>Pocket Doors [X3]</label><input type="number" name="fc_door_pocket" id="fc_door_pocket" value="<?= fcv('fc_door_pocket', '0') ?>" required></div>
                        </div>

                        <div id="fc_garage_section">
                            <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; margin-top: 20px;">5. Garage Details</h4>
                            <div class="form-group"><label>Garage Plaster/Paint Area (sqm) *</label><input type="number" step="0.01" name="fc_garage_sqm" id="fc_garage_sqm" value="<?= fcv('fc_garage_sqm') ?>"></div>
                        </div>

                        <div id="fc_cp_section">
                            <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; margin-top: 20px; color: #10b981;">6. Semi-Finishes (External Envelope)</h4>
                            <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                                <div class="form-group"><label>Aluminium Railings (lm)</label><input type="number" step="0.01" name="fc_rail_alu" value="<?= fcv('fc_rail_alu', '0') ?>"></div>
                                <div class="form-group"><label>Glass Railings (lm)</label><input type="number" step="0.01" name="fc_rail_glass" value="<?= fcv('fc_rail_glass', '0') ?>"></div>
                                <div class="form-group"><label>Wrought Iron Railings (lm)</label><input type="number" step="0.01" name="fc_rail_iron" value="<?= fcv('fc_rail_iron', '0') ?>"></div>
                            </div>
                            
                            <label style="margin-top: 10px; display: block; font-weight: bold;">External Apertures (Windows & Doors)</label>
                            <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;" id="fc_ap_table">
                                <thead><tr><th style="text-align:left; padding-bottom:5px;">Type</th><th style="text-align:left;">Width (m)</th><th style="text-align:left;">Height (m)</th><th></th></tr></thead>
                                <tbody id="fc_ap_body">
                                    <?php if (!empty($fcData['ap_type'])): ?>
                                        <?php for($i=0; $i < count($fcData['ap_type']); $i++): ?>
                                            <tr>
                                                <td style="padding: 4px 0;"><select name="ap_type[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);"><option value="ap_hinged_win" <?= $fcData['ap_type'][$i]=='ap_hinged_win'?'selected':'' ?>>Hinged Window</option><option value="ap_sliding_win" <?= $fcData['ap_type'][$i]=='ap_sliding_win'?'selected':'' ?>>Sliding Window</option><option value="ap_hinged_door" <?= $fcData['ap_type'][$i]=='ap_hinged_door'?'selected':'' ?>>Hinged Door</option><option value="ap_sliding_door" <?= $fcData['ap_type'][$i]=='ap_sliding_door'?'selected':'' ?>>Sliding Door</option></select></td>
                                                <td style="padding: 4px 5px;"><input type="number" step="0.01" name="ap_w[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);" value="<?= htmlspecialchars($fcData['ap_w'][$i]) ?>" required></td>
                                                <td style="padding: 4px 5px;"><input type="number" step="0.01" name="ap_h[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);" value="<?= htmlspecialchars($fcData['ap_h'][$i]) ?>" required></td>
                                                <td style="padding: 4px 0; text-align:right;"><button type="button" class="btn btn-sm btn-danger" style="padding:4px 8px;" onclick="this.parentElement.parentElement.remove()">X</button></td>
                                            </tr>
                                        <?php endfor; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <button type="button" class="btn btn-sm" style="background: rgba(255,255,255,0.1);" onclick="addFcAperture()">+ Add Aperture</button>
                        </div>

                        <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; margin-top: 20px;">7. Management & Discounts</h4>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                            <div class="form-group"><label>Project Management Fee (%) *</label><input type="number" step="0.01" name="fc_pm_pct" value="<?= fcv('fc_pm_pct', '15.00') ?>" required></div>
                            <div class="form-group"><label>Cleaning / Logistics Fee (€) *</label><input type="number" step="0.01" name="fc_cleaning_fee" value="<?= fcv('fc_cleaning_fee', '400.00') ?>" required></div>
                            <div class="form-group"><label>Special Discount (Inc VAT) (€)</label><input type="number" step="0.01" name="fc_discount" value="<?= fcv('fc_discount', '0.00') ?>" <?= $canApproveQuotes ? '' : 'readonly title="Requires Approval Access"' ?>></div>
                        </div>

                        <button type="submit" class="btn" style="background: #8b5cf6; color: white; border: none; width: 100%; padding: 15px; font-size: 1.1rem; font-weight: bold; margin-top: 20px;">Generate Full BoQ</button>
                    </form>
                </div>
            </div>

            <div id="sfModal" class="modal">
                <div class="modal-content" style="max-width: 800px; padding: 0;">
                    <div style="background: rgba(245, 158, 11, 0.1); border-bottom: 1px solid rgba(245, 158, 11, 0.3); padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; border-radius: 12px 12px 0 0;">
                        <h2 style="margin: 0; color: #f59e0b;">🔨 Semi-Finishes Calculator</h2>
                        <span class="close-modal" onclick="document.getElementById('sfModal').style.display='none'" style="position: static;">&times;</span>
                    </div>

                    <form method="POST" style="padding: 1.5rem; max-height: 70vh; overflow-y: auto;" id="sfForm">
                        <input type="hidden" name="action" value="generate_semi_finishes_boq">
                        <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">

                        <div style="background: rgba(239, 68, 68, 0.05); padding: 10px; border-left: 4px solid #ef4444; margin-bottom: 20px; font-size: 0.85rem;">
                            <strong>Warning:</strong> Generating this BoQ will completely overwrite and delete any existing items in this quote.
                        </div>

                        <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px;">1. Property Type</h4>
                        <div class="form-group">
                            <select name="sf_prop_type" required>
                                <option value="apartment">Apartment / Penthouse (Fire Rated Door)</option>
                                <option value="maisonette">Maisonette (External Timber Door)</option>
                            </select>
                        </div>

                        <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; margin-top: 20px;">2. Measurements & Balconies</h4>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group"><label>External Terraces / Balconies (sqm)</label><input type="number" step="0.01" name="sf_ext_sqm" value="0.00" required></div>
                            <div class="form-group"><label>Window / Door Sills (lm)</label><input type="number" step="0.01" name="sf_sills_lm" value="0.00" required></div>
                        </div>

                        <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; margin-top: 20px;">3. Railings</h4>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label>Railing Type</label>
                                <select name="sf_rail_type" required>
                                    <option value="none">None / Not Applicable</option>
                                    <option value="rail_alu">Aluminium Vertical</option>
                                    <option value="rail_glass">Glass</option>
                                    <option value="rail_iron">Wrought Iron</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Railing Length (lm)</label><input type="number" step="0.01" name="sf_rail_lm" value="0.00" required></div>
                        </div>

                        <h4 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 5px; margin-top: 20px;">4. External Apertures</h4>
                        <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;" id="sf_ap_table">
                            <thead><tr><th style="text-align:left; padding-bottom:5px;">Type</th><th style="text-align:left;">Width (m)</th><th style="text-align:left;">Height (m)</th><th></th></tr></thead>
                            <tbody id="sf_ap_body">
                                <tr>
                                    <td style="padding: 4px 0;"><select name="ap_type[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);"><option value="ap_hinged_win">Hinged Window</option><option value="ap_sliding_win">Sliding Window</option><option value="ap_hinged_door">Hinged Door</option><option value="ap_sliding_door">Sliding Door</option></select></td>
                                    <td style="padding: 4px 5px;"><input type="number" step="0.01" name="ap_w[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);" required></td>
                                    <td style="padding: 4px 5px;"><input type="number" step="0.01" name="ap_h[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);" required></td>
                                    <td style="padding: 4px 0; text-align:right;"><button type="button" class="btn btn-sm btn-danger" style="padding:4px 8px;" onclick="this.parentElement.parentElement.remove()">X</button></td>
                                </tr>
                            </tbody>
                        </table>
                        <button type="button" class="btn btn-sm" style="background: rgba(255,255,255,0.1);" onclick="addSfAperture()">+ Add Aperture</button>

                        <div style="margin-top: 20px; font-size: 0.85rem; color: var(--text-muted);">
                            * Water tank and main cable will be automatically included.
                        </div>

                        <button type="submit" class="btn" style="background: #f59e0b; color: white; border: none; width: 100%; padding: 15px; font-size: 1.1rem; font-weight: bold; margin-top: 20px;">Generate Semi-Finishes BoQ</button>
                    </form>
                </div>
            </div>
            
            <script>
            function toggleFcSections() {
                const state = document.getElementById('fc_state').value;
                const garage = document.getElementById('fc_garage').checked;
                
                document.getElementById('fc_cp_section').style.display = (state === 'common_parts') ? 'block' : 'none';
                document.getElementById('fc_balc_perim_group').style.display = (state === 'common_parts') ? 'block' : 'none';
                document.getElementById('fc_garage_section').style.display = garage ? 'block' : 'none';
                
                document.getElementById('fc_balcony_perim').required = (state === 'common_parts');
                document.getElementById('fc_garage_sqm').required = garage;
            }
            
            function calcFcDoors() {
                const b = parseInt(document.getElementById('fc_beds').value) || 0;
                const s = parseInt(document.getElementById('fc_bath_shower').value) || 0;
                const ba = parseInt(document.getElementById('fc_bath_bath').value) || 0;
                const tot = b + s + ba;
                document.getElementById('fc_door_calc').innerText = tot;
                
                if (document.getElementById('fc_door_hinged').value == '2' && document.getElementById('fc_door_sliding').value == '0' && document.getElementById('fc_door_pocket').value == '0') {
                    document.getElementById('fc_door_hinged').value = tot; 
                }
            }
            
            function addFcAperture() {
                const tb = document.getElementById('fc_ap_body');
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="padding: 4px 0;"><select name="ap_type[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);"><option value="ap_hinged_win">Hinged Window</option><option value="ap_sliding_win">Sliding Window</option><option value="ap_hinged_door">Hinged Door</option><option value="ap_sliding_door">Sliding Door</option></select></td>
                    <td style="padding: 4px 5px;"><input type="number" step="0.01" name="ap_w[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);" required></td>
                    <td style="padding: 4px 5px;"><input type="number" step="0.01" name="ap_h[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);" required></td>
                    <td style="padding: 4px 0; text-align:right;"><button type="button" class="btn btn-sm btn-danger" style="padding:4px 8px;" onclick="this.parentElement.parentElement.remove()">X</button></td>
                `;
                tb.appendChild(tr);
            }

            function addSfAperture() {
                const tb = document.getElementById('sf_ap_body');
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="padding: 4px 0;"><select name="ap_type[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);"><option value="ap_hinged_win">Hinged Window</option><option value="ap_sliding_win">Sliding Window</option><option value="ap_hinged_door">Hinged Door</option><option value="ap_sliding_door">Sliding Door</option></select></td>
                    <td style="padding: 4px 5px;"><input type="number" step="0.01" name="ap_w[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);" required></td>
                    <td style="padding: 4px 5px;"><input type="number" step="0.01" name="ap_h[]" style="width:100%; padding: 6px; border-radius:4px; background:var(--bg-panel); color:white; border:1px solid rgba(255,255,255,0.1);" required></td>
                    <td style="padding: 4px 0; text-align:right;"><button type="button" class="btn btn-sm btn-danger" style="padding:4px 8px;" onclick="this.parentElement.parentElement.remove()">X</button></td>
                `;
                tb.appendChild(tr);
            }
            
            // Initialize calculator UI state safely
            setTimeout(() => { toggleFcSections(); calcFcDoors(); }, 100);
            </script>
            <?php endif; ?>

            <?php if ($canManageQuote && !$isQuoteLocked): ?>
            <?php if ($isOhsaQuote): ?>
            <div id="ohsaCatalogueModal" class="modal">
                <div class="modal-content" style="max-width: 720px; max-height: 85vh; overflow: hidden; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h2 style="margin: 0; color: #14b8a6;">OHSA Rate Catalogue</h2>
                        <span class="close-modal" onclick="document.getElementById('ohsaCatalogueModal').style.display='none'" style="float:none;">&times;</span>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0;">Select a standard service to add to this quote. Quantities start at zero — enter qty on the line item after adding.</p>
                    <?php if (empty($ohsaCatalogue)): ?>
                        <div style="padding: 2rem; text-align: center; color: var(--text-muted);">Catalogue empty — seed rates via admin or sql/ohsa_standard_rates.sql</div>
                    <?php else: ?>
                    <div class="custom-scrollbar" style="overflow-y: auto; flex: 1;">
                        <table class="boq-table" style="font-size: 0.85rem;">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Service</th>
                                    <th>Unit</th>
                                    <th style="text-align: right;">Rate</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ohsaCatalogue as $catItem): ?>
                                <tr>
                                    <td style="color: var(--primary-color); font-weight: 600; white-space: nowrap;"><?= htmlspecialchars($catItem['category']) ?></td>
                                    <td><?= nl2br(htmlspecialchars($catItem['description'])) ?></td>
                                    <td><?= displayUnit($catItem['unit']) ?></td>
                                    <td style="text-align: right; font-weight: bold;">€<?= number_format($catItem['default_rate'], 2) ?></td>
                                    <td style="text-align: right;">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="add_catalogue_item">
                                            <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                                            <input type="hidden" name="standard_item_id" value="<?= (int)$catItem['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-primary" style="padding: 4px 10px;">Add</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <div id="itemModal" class="modal">
                <div class="modal-content" style="max-width: 500px;">
                    <span class="close-modal" onclick="document.getElementById('itemModal').style.display='none'">&times;</span>
                    <h2 id="itemModalTitle" style="color: var(--primary-color); margin-top: 0;"><?= $isOhsaQuote ? 'Add Custom Service' : 'Add Line Item' ?></h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                        <input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>">
                        <input type="hidden" name="item_id" id="mod_item_id">
                        
                        <div class="form-group">
                            <label>Category</label>
                            <input type="text" name="category" id="mod_item_cat" value="General" required>
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" id="mod_item_desc" rows="3" required></textarea>
                        </div>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                            <div class="form-group">
                                <label>Unit</label>
                                <select name="unit" id="mod_item_unit">
                                    <?php if ($isOhsaQuote): ?>
                                    <option value="visit">Visit</option>
                                    <option value="participant">Participant</option>
                                    <option value="procedure">Procedure</option>
                                    <option value="document">Document</option>
                                    <option value="assessment">Assessment</option>
                                    <option value="hour">Hour</option>
                                    <option value="lump_sum">Lump Sum</option>
                                    <option value="qty">Qty / Pcs</option>
                                    <?php else: ?>
                                    <option value="lump_sum">Lump Sum</option>
                                    <option value="sqm">sq.m (Area)</option>
                                    <option value="lm">lm (Linear)</option>
                                    <option value="cum">cu.m (Volume)</option>
                                    <option value="cu.yd">cu.yd (Excavation)</option>
                                    <option value="hrs">Hours</option>
                                    <option value="qty">Qty / Pcs</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Estimated Qty</label>
                                <input type="number" step="0.01" name="estimated_qty" id="mod_item_qty" value="0.00" required>
                            </div>
                            <div class="form-group">
                                <label>Unit Rate (€)</label>
                                <input type="number" step="0.01" name="unit_rate" id="mod_item_rate" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Sort Order (1 = Top, 99 = Bottom)</label>
                            <input type="number" name="sort_order" id="mod_item_sort" value="99" required>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Save Item</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($canManageQuote): ?>
            <div id="claimModal" class="modal">
                <div class="modal-content" style="max-width: 400px;">
                    <span class="close-modal" onclick="document.getElementById('claimModal').style.display='none'">&times;</span>
                    <h2 id="claimModalTitle" style="color: #3b82f6; margin-top: 0;">Issue Claim</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_claim">
                        <input type="hidden" name="quote_id" value="<?= $quote['id'] ?>">
                        <input type="hidden" name="quote_type" value="<?= $quote['quote_type'] ?>">
                        <input type="hidden" name="claim_id" id="mod_claim_id">
                        
                        <div class="form-group">
                            <label>Claim Type</label>
                            <select name="claim_type" id="mod_claim_type" required>
                                <option value="Deposit">Advance Deposit</option>
                                <option value="Interim">Interim Claim (% of Works)</option>
                                <option value="Final Measured">Final Measured Bill</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Description / Stage</label>
                            <input type="text" name="description" id="mod_claim_desc" placeholder="e.g. M&E 1st and 2nd Fix (50%)" required>
                        </div>
                        <div class="form-group">
                            <label>Claim Method</label>
                            <select name="claim_method" id="mod_claim_method" onchange="updateClaimLabel()">
                                <option value="fixed">Fixed Amount (Inc VAT)</option>
                                <option value="percent">Percentage of Total Quote (%)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label id="mod_claim_value_label">Amount to Claim (Inc VAT) €</label>
                            <input type="number" step="0.01" name="claim_value" id="mod_claim_value" required>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">Quote Total (Inc VAT): €<?= number_format($quote['total_inc_vat'], 2) ?></div>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="mod_claim_status">
                                <option value="Pending">Pending Payment</option>
                                <option value="Paid">Already Paid</option>
                            </select>
                        </div>
                        <button type="submit" class="btn" style="background: #3b82f6; color: white; width: 100%; border: none; padding: 10px;">Save Claim</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <script>
            function openItemModal(data = null) {
                if(data) {
                    document.getElementById('itemModalTitle').innerText = <?= $isOhsaQuote ? "'Edit Service'" : "'Edit Line Item'" ?>;
                    document.getElementById('mod_item_id').value = data.id;
                    document.getElementById('mod_item_cat').value = data.category;
                    document.getElementById('mod_item_desc').value = data.description;
                    document.getElementById('mod_item_unit').value = data.unit;
                    document.getElementById('mod_item_qty').value = data.estimated_qty;
                    document.getElementById('mod_item_rate').value = data.unit_rate;
                    document.getElementById('mod_item_sort').value = data.sort_order;
                } else {
                    document.getElementById('itemModalTitle').innerText = <?= $isOhsaQuote ? "'Add Custom Service'" : "'Add Line Item'" ?>;
                    document.getElementById('mod_item_id').value = '';
                    document.getElementById('mod_item_desc').value = '';
                    document.getElementById('mod_item_qty').value = '0.00';
                    document.getElementById('mod_item_rate').value = '';
                    document.getElementById('mod_item_sort').value = '99';
                }
                document.getElementById('itemModal').style.display = 'block';
            }
            
            function openClaimModal(data = null) { 
                if(data) {
                    document.getElementById('claimModalTitle').innerText = 'Edit Claim';
                    document.getElementById('mod_claim_id').value = data.id;
                    document.getElementById('mod_claim_type').value = data.claim_type;
                    document.getElementById('mod_claim_desc').value = data.description;
                    document.getElementById('mod_claim_method').value = 'fixed';
                    document.getElementById('mod_claim_value').value = data.amount_inc_vat;
                    document.getElementById('mod_claim_status').value = data.status;
                } else {
                    document.getElementById('claimModalTitle').innerText = 'Issue Claim';
                    document.getElementById('mod_claim_id').value = '';
                    document.getElementById('mod_claim_type').value = 'Interim';
                    document.getElementById('mod_claim_desc').value = '';
                    document.getElementById('mod_claim_method').value = 'fixed';
                    document.getElementById('mod_claim_value').value = '';
                    document.getElementById('mod_claim_status').value = 'Pending';
                }
                updateClaimLabel();
                document.getElementById('claimModal').style.display = 'block'; 
            }
            
            function updateClaimLabel() {
                const method = document.getElementById('mod_claim_method').value;
                const label = document.getElementById('mod_claim_value_label');
                if (method === 'percent') {
                    label.innerText = 'Percentage to Claim (%)';
                } else {
                    label.innerText = 'Amount to Claim (Inc VAT) €';
                }
            }
            
            window.addEventListener('click', function(event) {
                let iModal = document.getElementById('itemModal');
                let cModal = document.getElementById('claimModal');
                let fcModal = document.getElementById('fcModal');
                let sfModal = document.getElementById('sfModal');
                let ohsaCatModal = document.getElementById('ohsaCatalogueModal');
                if (iModal && event.target == iModal) iModal.style.display = "none";
                if (cModal && event.target == cModal) cModal.style.display = "none";
                if (fcModal && event.target == fcModal) fcModal.style.display = "none";
                if (sfModal && event.target == sfModal) sfModal.style.display = "none";
                if (ohsaCatModal && event.target == ohsaCatModal) ohsaCatModal.style.display = "none";
            });
            </script>

        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
