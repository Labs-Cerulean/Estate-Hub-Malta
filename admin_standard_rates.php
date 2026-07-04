<?php
require_once 'init.php';
require_once 'session-check.php';

if (!isAdmin()) { header('Location: dashboard.php?error=unauthorized'); exit; }

// ==========================================
// AUTO-DEPLOY FINISHES RATES DATABASE
// ==========================================
try {
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
            ['sills', '6 - Semi Finishes', 'Sills', 'lm', 40.00],
            ['rail_alu', '6 - Semi Finishes', 'Railing (Aluminium vertical)', 'lm', 150.00],
            ['rail_glass', '6 - Semi Finishes', 'Railing (Glass)', 'lm', 250.00],
            ['rail_iron', '6 - Semi Finishes', 'Railing (Wrought iron)', 'lm', 180.00],
            ['balc_tile', '6 - Semi Finishes', 'Balcony Tiling including waterproofing', 'sqm', 30.00],
            ['balc_skirt', '6 - Semi Finishes', 'Balcony Skirting', 'lm', 8.00],
            ['main_cable', '6 - Semi Finishes', 'Main cable to apartment and balcony light', 'lump_sum', 400.00],
            ['water_tank', '6 - Semi Finishes', 'Water tank supply, installation and connection', 'lump_sum', 600.00]
        ];
        
        $s = $pdo->prepare("INSERT INTO sales_finishes_rates (item_key, category, description, unit, rate) VALUES (?, ?, ?, ?, ?)");
        foreach ($defaultRates as $r) { $s->execute($r); }
    }
} catch(PDOException $e) {}


$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // Handle Standard Boilerplate Items
        if ($action === 'add_item' || $action === 'edit_item') {
            $id = !empty($_POST['item_id']) ? (int)$_POST['item_id'] : null;
            $type = $_POST['quote_type'];
            $cat = $_POST['category'];
            $desc = $_POST['description'];
            $unit = $_POST['unit'];
            $rate = (float)$_POST['default_rate'];
            $sort = (int)$_POST['sort_order'];
            
            if ($id) {
                $stmt = $pdo->prepare("UPDATE sales_standard_items SET quote_type=?, category=?, description=?, unit=?, default_rate=?, sort_order=? WHERE id=?");
                $stmt->execute([$type, $cat, $desc, $unit, $rate, $sort, $id]);
                $message = "Standard item updated.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO sales_standard_items (quote_type, category, description, unit, default_rate, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$type, $cat, $desc, $unit, $rate, $sort]);
                $message = "Standard item added.";
            }
        }
        
        if ($action === 'delete_item') {
            $pdo->prepare("DELETE FROM sales_standard_items WHERE id=?")->execute([$_POST['item_id']]);
            $message = "Item deleted.";
        }

        // Handle Default Terms
        if ($action === 'save_terms') {
            $type = $_POST['quote_type'];
            $terms = $_POST['terms_text'];
            
            $stmt = $pdo->prepare("INSERT INTO sales_default_terms (quote_type, terms_text) VALUES (?, ?) ON DUPLICATE KEY UPDATE terms_text = VALUES(terms_text)");
            $stmt->execute([$type, $terms]);
            $message = "Standard terms updated for $type.";
        }

        // Handle Finishes Calculator Batch Update
        if ($action === 'save_finishes_rates') {
            $rates = $_POST['f_rate'] ?? [];
            $stmt = $pdo->prepare("UPDATE sales_finishes_rates SET rate = ? WHERE id = ?");
            foreach ($rates as $id => $val) {
                $stmt->execute([(float)$val, (int)$id]);
            }
            $message = "All Finishes Calculator variables updated successfully.";
        }

    } catch (Exception $e) { $error = $e->getMessage(); }
}

$items = $pdo->query("SELECT * FROM sales_standard_items ORDER BY quote_type ASC, sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$finishesRatesRaw = $pdo->query("SELECT * FROM sales_finishes_rates ORDER BY category ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$finishesRates = [];
foreach ($finishesRatesRaw as $fr) {
    $finishesRates[$fr['category']][] = $fr;
}

// Fetch current terms
$termsData = $pdo->query("SELECT * FROM sales_default_terms")->fetchAll(PDO::FETCH_ASSOC);
$termsMap = [];
foreach ($termsData as $t) { $termsMap[$t['quote_type']] = $t['terms_text']; }

$pageTitle = 'Manage Standard Quote Rates & Terms';
require_once 'header.php';
?>

<style>
.admin-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.admin-table th { background: rgba(255,255,255,0.05); padding: 10px; text-align: left; border-bottom: 2px solid var(--border-glass); }
.admin-table td { padding: 10px; border-bottom: 1px solid var(--border-glass); vertical-align: top; }

.tab-nav { display: flex; gap: 10px; border-bottom: 2px solid var(--border-glass); margin-bottom: 1.5rem; }
.tab-btn { padding: 10px 20px; color: var(--text-muted); background: transparent; border: none; text-decoration: none; font-weight: bold; border-bottom: 3px solid transparent; transition: 0.2s; cursor: pointer; font-size: 1rem; }
.tab-btn:hover { color: var(--text-primary); }
.tab-btn.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }
.tab-content { display: none; }
.tab-content.active { display: block; }
</style>

<div class="main-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin: 0;">Standard Quote Rates & Terms</h1>
            <p style="color: var(--text-secondary); margin-top: 0.25rem;">Manage boilerplate text, standard packages, and Finishes Calculator variables.</p>
        </div>
        <a href="work_sales.php" class="btn btn-secondary">← Back to Commercial Hub</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="section-card" style="margin-bottom: 2rem;">
        <h3>Default Terms & Conditions</h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">These terms will automatically be appended to the bottom of any newly created quote of the respective type.</p>
        <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
            <?php foreach(['Demolition_Excavation', 'Construction', 'Finishes', 'OHSA'] as $qt): ?>
            <form method="POST" style="background: rgba(255,255,255,0.02); padding: 1rem; border: 1px solid var(--border-glass); border-radius: 8px;">
                <input type="hidden" name="action" value="save_terms">
                <input type="hidden" name="quote_type" value="<?= $qt ?>">
                <h4 style="margin-top: 0; color: var(--primary-color);"><?= str_replace('_', ' & ', $qt) ?></h4>
                <textarea name="terms_text" rows="6" style="width: 100%; margin-bottom: 10px; font-size: 0.8rem; padding: 8px; border-radius: 4px; border: 1px solid var(--border-glass); background: #1e1e2d; color: #fff;"><?= htmlspecialchars($termsMap[$qt] ?? '') ?></textarea>
                <button type="submit" class="btn btn-sm btn-primary" style="width: 100%;">Save Terms</button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="tab-nav">
        <button type="button" class="tab-btn active" onclick="switchTab('standard_tab', this)">Boilerplate Items (Demo/Const)</button>
        <button type="button" class="tab-btn" onclick="switchTab('finishes_tab', this)">⚡ Finishes Calculator Variables</button>
    </div>

    <div id="standard_tab" class="tab-content active">
        <div class="two-column-layout" style="grid-template-columns: 1fr 2fr;">
            <div class="section-card">
                <h3 style="margin-top: 0;">Add / Edit Standard Item</h3>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 15px;">These items are injected automatically with a <strong>qty of 0.00</strong> when creating a Demo, Const, or OHSA quote. OHSA uses service units (visit, participant, hour, etc.) — manage rates here or run <code>sql/ohsa_standard_rates.sql</code> in phpMyAdmin.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="add_item" id="form_action">
                    <input type="hidden" name="item_id" id="form_item_id">
                    
                    <div class="form-group">
                        <label>Applies To</label>
                        <select name="quote_type" id="form_quote_type" required>
                            <option value="Demolition_Excavation">Demolition & Excavation</option>
                            <option value="Construction">Construction</option>
                            <option value="Finishes">Finishes</option>
                            <option value="OHSA">OHSA / Health & Safety</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Category</label><input type="text" name="category" id="form_category" value="General" required></div>
                    <div class="form-group"><label>Description</label><textarea name="description" id="form_description" rows="4" required></textarea></div>
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label>Unit</label>
                            <select name="unit" id="form_unit">
                                <optgroup label="Construction / General" id="unit_group_general">
                                    <option value="lump_sum">Lump Sum</option>
                                    <option value="sqm">sq.m (Area)</option>
                                    <option value="lm">lm (Linear)</option>
                                    <option value="cum">cu.m</option>
                                    <option value="cu.yd">cu.yd</option>
                                    <option value="hrs">Hours</option>
                                    <option value="qty">Qty / Pcs</option>
                                </optgroup>
                                <optgroup label="OHSA Services" id="unit_group_ohsa">
                                    <option value="visit">Visit</option>
                                    <option value="participant">Participant</option>
                                    <option value="procedure">Procedure</option>
                                    <option value="document">Document</option>
                                    <option value="assessment">Assessment</option>
                                    <option value="hour">Hour</option>
                                    <option value="lump_sum">Lump Sum</option>
                                    <option value="qty">Qty / Pcs</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="form-group"><label>Default Rate (€)</label><input type="number" step="0.01" name="default_rate" id="form_rate" value="0.00" required></div>
                    </div>
                    <div class="form-group"><label>Sort Order (1 = Top)</label><input type="number" name="sort_order" id="form_sort" value="10"></div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;" id="form_submit_btn">Save Item</button>
                        <button type="button" class="btn btn-secondary" onclick="resetForm()">Reset</button>
                    </div>
                </form>
            </div>

            <div class="section-card" style="overflow-x: auto;">
                <table class="admin-table">
                    <thead><tr><th>Type</th><th>Category & Desc</th><th>Unit</th><th>Rate</th><th>Ord</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach($items as $i): ?>
                        <tr>
                            <td style="font-weight: bold;"><?= str_replace('_', ' & ', $i['quote_type']) ?></td>
                            <td>
                                <span style="color: var(--primary-color); font-weight: bold;"><?= htmlspecialchars($i['category']) ?></span><br>
                                <?= nl2br(htmlspecialchars($i['description'])) ?>
                            </td>
                            <td><?= htmlspecialchars($i['unit']) ?></td>
                            <td style="font-weight: bold;">€<?= number_format($i['default_rate'], 2) ?></td>
                            <td><?= $i['sort_order'] ?></td>
                            <td style="text-align: right; min-width: 80px;">
                                <button class="btn btn-sm btn-secondary" style="padding: 4px;" onclick='editItem(<?= json_encode($i, JSON_HEX_APOS) ?>)'>✎</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete template item?');">
                                    <input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="<?= $i['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding: 4px;">X</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="finishes_tab" class="tab-content">
        <div class="section-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <h3 style="margin: 0; color: #8b5cf6;">⚡ Finishes Calculator Variables</h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">These rates are used by the math engine to automatically generate Finishes quotes. Modifying these values will immediately affect all future Finishes calculations.</p>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save_finishes_rates">
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
                    <?php foreach ($finishesRates as $cat => $rates): ?>
                        <div style="background: rgba(0,0,0,0.2); border: 1px solid var(--border-glass); border-radius: 8px; overflow: hidden;">
                            <div style="background: rgba(139, 92, 246, 0.1); padding: 10px 15px; border-bottom: 1px solid rgba(139, 92, 246, 0.2); font-weight: bold; color: #a5b4fc;">
                                <?= htmlspecialchars($cat) ?>
                            </div>
                            <table class="admin-table" style="margin: 0;">
                                <tbody>
                                    <?php foreach ($rates as $r): ?>
                                    <tr>
                                        <td style="width: 70%; padding: 8px 15px;">
                                            <div style="font-size: 0.85rem;"><?= htmlspecialchars($r['description']) ?></div>
                                            <div style="font-size: 0.7rem; color: var(--text-muted); font-family: monospace;">[<?= htmlspecialchars($r['item_key']) ?>] • <?= htmlspecialchars($r['unit']) ?></div>
                                        </td>
                                        <td style="width: 30%; padding: 8px 15px; vertical-align: middle;">
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <span style="color: var(--text-muted);">€</span>
                                                <input type="number" step="0.01" name="f_rate[<?= $r['id'] ?>]" value="<?= (float)$r['rate'] ?>" style="width: 100%; padding: 6px; border-radius: 4px; background: #1e1e2d; color: #fff; border: 1px solid var(--border-glass);">
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 20px; text-align: right;">
                    <button type="submit" class="btn" style="background: #8b5cf6; color: white; border: none; padding: 12px 30px; font-weight: bold; font-size: 1.1rem;">Save All Finishes Rates</button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    
    document.getElementById(tabId).classList.add('active');
    btn.classList.add('active');
}

function editItem(data) {
    document.getElementById('form_action').value = 'edit_item';
    document.getElementById('form_item_id').value = data.id;
    document.getElementById('form_quote_type').value = data.quote_type;
    document.getElementById('form_category').value = data.category;
    document.getElementById('form_description').value = data.description;
    document.getElementById('form_unit').value = data.unit;
    document.getElementById('form_rate').value = data.default_rate;
    document.getElementById('form_sort').value = data.sort_order;
    document.getElementById('form_submit_btn').innerText = 'Update Item';
    updateUnitOptions();
}
function resetForm() {
    document.getElementById('form_action').value = 'add_item';
    document.getElementById('form_item_id').value = '';
    document.getElementById('form_description').value = '';
    document.getElementById('form_rate').value = '0.00';
    document.getElementById('form_submit_btn').innerText = 'Save Item';
    updateUnitOptions();
}

function updateUnitOptions() {
    const type = document.getElementById('form_quote_type').value;
    const isOhsa = type === 'OHSA';
    document.getElementById('unit_group_general').style.display = isOhsa ? 'none' : '';
    document.getElementById('unit_group_ohsa').style.display = isOhsa ? '' : 'none';
    if (isOhsa) {
        const sel = document.getElementById('form_unit');
        if (!['visit','participant','procedure','document','assessment','hour','lump_sum','qty'].includes(sel.value)) {
            sel.value = 'visit';
        }
    }
}

document.getElementById('form_quote_type').addEventListener('change', updateUnitOptions);
updateUnitOptions();
</script>

<?php require_once 'footer.php'; ?>
