<?php
require_once 'init.php';
require_once 'session-check.php';

if (!isAdmin()) { header('Location: dashboard.php?error=unauthorized'); exit; }

$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
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
    } catch (Exception $e) { $error = $e->getMessage(); }
}

$items = $pdo->query("SELECT * FROM sales_standard_items ORDER BY quote_type ASC, sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Standard Quote Rates';
require_once 'header.php';
?>

<style>
.admin-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.admin-table th { background: rgba(255,255,255,0.05); padding: 10px; text-align: left; border-bottom: 2px solid var(--border-glass); }
.admin-table td { padding: 10px; border-bottom: 1px solid var(--border-glass); vertical-align: top; }
</style>

<div class="main-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin: 0;">Standard Quote Rates & Templates</h1>
            <p style="color: var(--text-secondary); margin-top: 0.25rem;">Manage the boilerplate text and default unit rates that auto-populate when a quote is created.</p>
        </div>
        <a href="work_sales.php" class="btn btn-secondary">← Back to Commercial Hub</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="two-column-layout" style="grid-template-columns: 1fr 2fr;">
        <div class="section-card">
            <h3>Add / Edit Standard Item</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_item" id="form_action">
                <input type="hidden" name="item_id" id="form_item_id">
                
                <div class="form-group">
                    <label>Applies To</label>
                    <select name="quote_type" id="form_quote_type" required>
                        <option value="Demolition_Excavation">Demolition & Excavation</option>
                        <option value="Construction">Construction</option>
                        <option value="Finishes">Finishes</option>
                    </select>
                </div>
                <div class="form-group"><label>Category</label><input type="text" name="category" id="form_category" value="General" required></div>
                <div class="form-group"><label>Description</label><textarea name="description" id="form_description" rows="4" required></textarea></div>
                <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Unit</label>
                        <select name="unit" id="form_unit">
                            <option value="lump_sum">Lump Sum</option>
                            <option value="sqm">sq.m (Area)</option>
                            <option value="lm">lm (Linear)</option>
                            <option value="cum">cu.m</option>
                            <option value="cu.yd">cu.yd</option>
                            <option value="hrs">Hours</option>
                            <option value="qty">Qty / Pcs</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Default Rate (€)</label><input type="number" step="0.01" name="default_rate" id="form_rate" value="0.00" required></div>
                </div>
                <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" id="form_sort" value="10"></div>
                
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

<script>
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
}
function resetForm() {
    document.getElementById('form_action').value = 'add_item';
    document.getElementById('form_item_id').value = '';
    document.getElementById('form_description').value = '';
    document.getElementById('form_rate').value = '0.00';
    document.getElementById('form_submit_btn').innerText = 'Save Item';
}
</script>

<?php require_once 'footer.php'; ?>
