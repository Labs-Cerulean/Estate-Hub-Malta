<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('add_project') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$error = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create') {
    try {
        $pdo->beginTransaction();

        // 1. Extract Core Project Details
        $clientId = !empty($_POST['clientid']) ? $_POST['clientid'] : null;
        $name = trim($_POST['name'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $island = $_POST['island'] ?? '';
        $type = $_POST['type'] ?? '';
        $finishLevel = !empty($_POST['finishlevel']) ? $_POST['finishlevel'] : null;
        $isTracking = isset($_POST['is_tracking']) ? 1 : 0;
        $summerBreak = isset($_POST['summer_break_flag']) ? 1 : 0;
        $projectStatus = 'Active'; // Always active on creation
        
        // Map Coordinates
        $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
        $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;

        // 2. Insert Core Project
        $stmt = $pdo->prepare("
            INSERT INTO projects (clientid, name, city, island, type, finishlevel, is_tracking, summer_break_flag, project_status, latitude, longitude, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $clientId, $name, $city, $island, $type, $finishLevel, 
            $isTracking, $summerBreak, $projectStatus, $latitude, $longitude, getCurrentUserId()
        ]);
        
        // CRITICAL FIX: Save the ID with a capital "I"
        $projectId = $pdo->lastInsertId();

        // 3. Initialize Mobilisation Checklist Tracker for this project
        $pdo->prepare("INSERT INTO project_mobilisation (project_id) VALUES (?)")->execute([$projectId]);

        // 4. Insert PA Numbers
        if (isset($_POST['paentries']) && is_array($_POST['paentries'])) {
            $paStmt = $pdo->prepare("INSERT INTO project_pa_numbers (project_id, pa_number, pa_status, architect_id, structural_engineer_id) VALUES (?, ?, ?, ?, ?)");
            foreach ($_POST['paentries'] as $paEntry) {
                if (!empty(trim($paEntry['pa_number']))) {
                    $paStmt->execute([
                        $projectId, 
                        trim($paEntry['pa_number']), 
                        $paEntry['pa_status'] ?? 'Tracking',
                        !empty($paEntry['architect_id']) ? $paEntry['architect_id'] : null,
                        !empty($paEntry['structural_engineer_id']) ? $paEntry['structural_engineer_id'] : null
                    ]);
                }
            }
        }

        // 5. Insert Blocks & Auto-Generate Levels
        $hasBlocks = false;
        if (isset($_POST['blocks']) && is_array($_POST['blocks'])) {
            foreach ($_POST['blocks'] as $b) {
                $bName = trim($b['name'] ?? '');
                $bType = $b['type'] ?? 'Block';
                $bLow = (int)($b['lowest'] ?? 0);
                $bHigh = (int)($b['highest'] ?? 0);

                if (empty($bName)) continue;
                $hasBlocks = true;
                
                // Swap if backwards
                if ($bLow > $bHigh) { $temp = $bLow; $bLow = $bHigh; $bHigh = $temp; } 

                $bStmt = $pdo->prepare("INSERT INTO project_blocks (project_id, block_name, block_type, lowest_level, highest_level) VALUES (?, ?, ?, ?, ?)");
                $bStmt->execute([$projectId, $bName, $bType, $bLow, $bHigh]);
                $bId = $pdo->lastInsertId();

                // Auto-generate levels for this block
                $levelStmt = $pdo->prepare("INSERT INTO block_levels (block_id, level_number, level_name) VALUES (?, ?, ?)");
                for ($lvl = $bLow; $lvl <= $bHigh; $lvl++) {
                    $lvlName = ($lvl === 0) ? "Level 0 (Ground)" : "Level " . $lvl;
                    $levelStmt->execute([$bId, $lvl, $lvlName]);
                }
            }
        }
        
        // Failsafe: If the user deleted all blocks from the UI, generate a default one
        if (!$hasBlocks) {
            $pdo->prepare("INSERT INTO project_blocks (project_id, block_name, block_type, lowest_level, highest_level) VALUES (?, 'Main Building', 'Block', 0, 0)")
                ->execute([$projectId]);
            $bId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO block_levels (block_id, level_number, level_name) VALUES (?, 0, 'Level 0 (Ground)')")->execute([$bId]);
        }

        $pdo->commit();
        
        // Redirect to dashboard on success
        header("Location: dashboard.php"); 
        exit;
        
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = 'Error creating project: ' . $e->getMessage();
    }
}

// Fetch Dropdown Data
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$architects = $pdo->query("SELECT id, name FROM professionals WHERE type = 'Architect' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$engineers = $pdo->query("SELECT id, name FROM professionals WHERE type = 'Structural Engineer' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Create Project';
require_once 'header.php';
?>

<div class="main-container" style="max-width: 900px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h1 class="page-title" style="margin: 0;">Create New Project</h1>
        <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="card" id="createProjectForm">
        <input type="hidden" name="action" value="create">

        <h3 style="margin-bottom: 1rem; color: var(--primary-color); border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Core Details</h3>
        <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="form-group">
                <label>Project Name <span style="color: #ef4444;">*</span></label>
                <input type="text" name="name" required placeholder="e.g., The Horizon Suites">
            </div>
            <div class="form-group">
                <label>Developer / Client</label>
                <select name="clientid">
                    <option value="">-- In-House (Internal) --</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>City / Locality <span style="color: #ef4444;">*</span></label>
                <input type="text" name="city" required placeholder="e.g., Sliema">
            </div>
            <div class="form-group">
                <label>Island <span style="color: #ef4444;">*</span></label>
                <select name="island" required>
                    <option value="Malta">Malta</option>
                    <option value="Gozo">Gozo</option>
                </select>
            </div>

            <div class="form-group">
                <label>Project Type <span style="color: #ef4444;">*</span></label>
                <select name="type" required>
                    <option value="in-house">In-House Development</option>
                    <option value="3rd-party">3rd Party (Capital Project)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Finishes Required</label>
                <select name="finishlevel">
                    <option value="">-- Select Finish Requirement --</option>
                    <option value="Shell">Shell Only</option>
                    <option value="Common Parts Only">Common Parts Only</option>
                    <option value="Semi Finished">Semi Finished</option>
                    <option value="Finished">Finished</option>
                </select>
            </div>
        </div>

        <h3 style="margin-top: 1.5rem; margin-bottom: 1rem; color: var(--primary-color); border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Map Location (Optional)</h3>
        <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="form-group">
                <label>Exact Latitude</label>
                <input type="text" name="latitude" placeholder="e.g. 35.912245">
                <small style="color: var(--text-muted); font-size: 0.75rem;">Leave blank to default to City center on the map.</small>
            </div>
            <div class="form-group">
                <label>Exact Longitude</label>
                <input type="text" name="longitude" placeholder="e.g. 14.504212">
            </div>
        </div>

        <div class="form-grid" style="grid-template-columns: 1fr 1fr; margin-top: 1rem;">
            <div class="checkbox-group" style="padding: 1rem; background: var(--bg-primary); border: 1px solid var(--border-glass); border-radius: 8px;">
                <div class="checkbox-item" style="margin-bottom: 0;">
                    <input type="checkbox" name="is_tracking" id="is_tracking" value="1">
                    <label for="is_tracking" style="font-weight: bold; color: #0ea5e9;">Pre-Execution Phase</label>
                </div>
                <small style="display: block; margin-top: 0.25rem; margin-left: 1.75rem; color: var(--text-muted);">Check this if the project is still in Feasibility or Tracking and hasn't received permits yet.</small>
            </div>

            <div class="checkbox-group" style="padding: 1rem; background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 8px;">
                <div class="checkbox-item" style="margin-bottom: 0;">
                    <input type="checkbox" name="summer_break_flag" id="summer_break_flag" value="1">
                    <label for="summer_break_flag" style="font-weight: bold; color: #f59e0b;">Summer Break Area (June-Sept)</label>
                </div>
                <small style="display: block; margin-top: 0.25rem; margin-left: 1.75rem; color: var(--text-muted);">Check this if the site is located in a MTA designated summer break locality.</small>
            </div>
        </div>

        <div style="margin-top: 2.5rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; margin-bottom: 1rem;">
            <h3 style="margin: 0; color: var(--primary-color);">Planning Authority (PA) Details</h3>
            <button type="button" class="btn btn-sm btn-secondary" onclick="addPARow()">+ Add PA Number</button>
        </div>
        
        <div id="paContainer">
            <div class="pa-row" style="background: var(--bg-primary); padding: 1rem; border: 1px solid var(--border-glass); border-radius: 8px; margin-bottom: 1rem; position: relative;">
                <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="form-group">
                        <label>PA Number</label>
                        <input type="text" name="paentries[0][pa_number]" placeholder="e.g., PA/1234/23">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="paentries[0][pa_status]">
                            <option value="Tracking">Tracking</option>
                            <option value="Approved">Approved</option>
                            <option value="Rejected">Rejected</option>
                            <option value="Withdrawn">Withdrawn</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Architect</label>
                        <select name="paentries[0][architect_id]">
                            <option value="">-- Select Architect --</option>
                            <?php foreach ($architects as $a): ?><option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Structural Engineer</label>
                        <select name="paentries[0][structural_engineer_id]">
                            <option value="">-- Select Engineer --</option>
                            <?php foreach ($engineers as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-top: 2.5rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; margin-bottom: 1rem;">
            <h3 style="margin: 0; color: var(--primary-color);">Building Blocks & Levels</h3>
            <button type="button" class="btn btn-sm btn-secondary" onclick="addBlockRow()">+ Add Block</button>
        </div>
        
        <div id="blocksContainer">
            <div class="block-row" style="background: var(--bg-primary); padding: 1rem; border: 1px solid var(--border-glass); border-radius: 8px; margin-bottom: 1rem; position: relative;">
                <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr;">
                    <div class="form-group">
                        <label>Block Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="blocks[0][name]" value="Main Building" required>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="blocks[0][type]">
                            <option value="Block">Block</option>
                            <option value="Villa">Villa</option>
                            <option value="House">House</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Lowest Level</label>
                        <input type="number" name="blocks[0][lowest]" value="0" required title="Negative numbers for basements (e.g., -2)">
                    </div>
                    <div class="form-group">
                        <label>Highest Level</label>
                        <input type="number" name="blocks[0][highest]" value="4" required>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-top: 2rem;">
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem;">Create Project</button>
        </div>
    </form>
</div>

<script>
const architectsOpts = `<?php foreach ($architects as $a) echo "<option value='{$a['id']}'>" . htmlspecialchars($a['name'], ENT_QUOTES) . "</option>"; ?>`;
const engineersOpts = `<?php foreach ($engineers as $e) echo "<option value='{$e['id']}'>" . htmlspecialchars($e['name'], ENT_QUOTES) . "</option>"; ?>`;

let paCounter = 1;
function addPARow() {
    const container = document.getElementById('paContainer');
    const row = document.createElement('div');
    row.className = 'pa-row';
    row.style.cssText = 'background: var(--bg-primary); padding: 1rem; border: 1px solid var(--border-glass); border-radius: 8px; margin-bottom: 1rem; position: relative;';
    
    row.innerHTML = `
        <button type="button" onclick="this.parentElement.remove()" style="position: absolute; top: 10px; right: 10px; background: none; border: none; color: #ef4444; cursor: pointer; font-size: 1.2rem;" title="Remove Row">&times;</button>
        <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="form-group">
                <label>PA Number</label>
                <input type="text" name="paentries[${paCounter}][pa_number]" placeholder="e.g., PA/5678/23">
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="paentries[${paCounter}][pa_status]">
                    <option value="Tracking">Tracking</option>
                    <option value="Approved">Approved</option>
                    <option value="Rejected">Rejected</option>
                    <option value="Withdrawn">Withdrawn</option>
                </select>
            </div>
            <div class="form-group">
                <label>Architect</label>
                <select name="paentries[${paCounter}][architect_id]">
                    <option value="">-- Select Architect --</option>
                    ${architectsOpts}
                </select>
            </div>
            <div class="form-group">
                <label>Structural Engineer</label>
                <select name="paentries[${paCounter}][structural_engineer_id]">
                    <option value="">-- Select Engineer --</option>
                    ${engineersOpts}
                </select>
            </div>
        </div>
    `;
    container.appendChild(row);
    paCounter++;
}

let blockCounter = 1;
function addBlockRow() {
    const container = document.getElementById('blocksContainer');
    const row = document.createElement('div');
    row.className = 'block-row';
    row.style.cssText = 'background: var(--bg-primary); padding: 1rem; border: 1px solid var(--border-glass); border-radius: 8px; margin-bottom: 1rem; position: relative;';
    
    row.innerHTML = `
        <button type="button" onclick="this.parentElement.remove()" style="position: absolute; top: 10px; right: 10px; background: none; border: none; color: #ef4444; cursor: pointer; font-size: 1.2rem;" title="Remove Block">&times;</button>
        <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr;">
            <div class="form-group">
                <label>Block Name <span style="color: #ef4444;">*</span></label>
                <input type="text" name="blocks[${blockCounter}][name]" required placeholder="e.g., Block B">
            </div>
            <div class="form-group">
                <label>Type</label>
                <select name="blocks[${blockCounter}][type]">
                    <option value="Block">Block</option>
                    <option value="Villa">Villa</option>
                    <option value="House">House</option>
                </select>
            </div>
            <div class="form-group">
                <label>Lowest Level</label>
                <input type="number" name="blocks[${blockCounter}][lowest]" value="0" required>
            </div>
            <div class="form-group">
                <label>Highest Level</label>
                <input type="number" name="blocks[${blockCounter}][highest]" value="4" required>
            </div>
        </div>
    `;
    container.appendChild(row);
    blockCounter++;
}
</script>

<?php require_once 'footer.php'; ?>
