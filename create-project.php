<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('add_project') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create') {
    try {
        $pdo->beginTransaction();

        // 1. INSERT CORE PROJECT DETAILS
        $clientId = $_POST['clientid'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $island = $_POST['island'] ?? '';
        $type = $_POST['type'] ?? '';
        $finishLevel = ($_POST['finishlevel'] ?? '') ?: null;
        $isTracking = isset($_POST['is_tracking']) ? 1 : 0;
        $summerBreak = isset($_POST['summer_break_flag']) ? 1 : 0;
        
        $stmt = $pdo->prepare("INSERT INTO projects (clientid, name, city, island, type, finishlevel, is_tracking, summer_break_flag, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$clientId, $name, $city, $island, $type, $finishLevel, $isTracking, $summerBreak, getCurrentUserId()]);
        $projectId = $pdo->lastInsertId();

        // Initialize Mobilisation Checklist Tracker for this project
        $pdo->prepare("INSERT INTO project_mobilisation (project_id) VALUES (?)")->execute([$projectId]);

        // 2. INSERT PA NUMBERS
        if (isset($_POST['paentries']) && is_array($_POST['paentries'])) {
            $paStmt = $pdo->prepare("INSERT INTO project_pa_numbers (project_id, pa_number, pa_status, architect_id, structural_engineer_id) VALUES (?, ?, ?, ?, ?)");
            foreach ($_POST['paentries'] as $paEntry) {
                if (!empty(trim($paEntry['pa_number']))) {
                    $paStmt->execute([
                        $projectId, trim($paEntry['pa_number']), $paEntry['pa_status'] ?? 'Tracking',
                        !empty($paEntry['architect_id']) ? $paEntry['architect_id'] : null,
                        !empty($paEntry['structural_engineer_id']) ? $paEntry['structural_engineer_id'] : null
                    ]);
                }
            }
        }

        // 3. INSERT BLOCKS & AUTO-GENERATE LEVELS
        $hasBlocks = false;
        if (isset($_POST['blocks']) && is_array($_POST['blocks'])) {
            foreach ($_POST['blocks'] as $b) {
                $bName = trim($b['name'] ?? '');
                $bType = $b['type'] ?? 'Block';
                $bLow = (int)($b['lowest'] ?? 0);
                $bHigh = (int)($b['highest'] ?? 0);

                if (empty($bName)) continue;
                $hasBlocks = true;
                if ($bLow > $bHigh) { $temp = $bLow; $bLow = $bHigh; $bHigh = $temp; } // Swap if backwards

                $pdo->prepare("INSERT INTO project_blocks (project_id, block_name, block_type, lowest_level, highest_level) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$projectId, $bName, $bType, $bLow, $bHigh]);
                $bId = $pdo->lastInsertId();

                // Auto-generate levels for this block
                $levelStmt = $pdo->prepare("INSERT INTO block_levels (block_id, level_number, level_name) VALUES (?, ?, ?)");
                for ($lvl = $bLow; $lvl <= $bHigh; $lvl++) {
                    $lvlName = ($lvl === 0) ? "Level 0 (Ground)" : "Level " . $lvl;
                    $levelStmt->execute([$bId, $lvl, $lvlName]);
                }
            }
        }
        
        // If the user deleted all blocks from the UI, generate a default one
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

// Get data for dropdowns
$clients = isAdmin() ? $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll() : getUserClients($pdo, getCurrentUserId());
$architects = $pdo->query("SELECT id, name, firm_name FROM professionals WHERE role_type = 'architect' ORDER BY name")->fetchAll();
$engineers = $pdo->query("SELECT id, name, firm_name FROM professionals WHERE role_type = 'structural_engineer' ORDER BY name")->fetchAll();

$pageTitle = 'Create New Project';
require_once 'header.php';
?>

<div class="main-container">
    <h1 class="page-title">Create New Project</h1>

    <?php if ($message): ?><div class="message success" style="padding:1rem; background:rgba(34,197,94,0.1); color:var(--success); border:1px solid var(--success); border-radius:8px; margin-bottom:1rem;"><?= htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="message error" style="padding:1rem; background:rgba(239,68,68,0.1); color:var(--danger); border:1px solid var(--danger); border-radius:8px; margin-bottom:1rem;"><?= htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="form-section">
        <form method="POST">
            <input type="hidden" name="action" value="create">

            <div style="margin-bottom: 2rem;">
                <h3 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Core Details</h3>
                
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                    <div class="form-group">
                        <label>Client</label>
                        <select name="clientid" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Project Name</label>
                        <input type="text" name="name" required placeholder="e.g., The Horizon Suites">
                    </div>
                    <div class="form-group">
                        <label>Island</label>
                        <select name="island" id="island" onchange="updateCities()" required>
                            <option value="Malta" selected>Malta</option>
                            <option value="Gozo">Gozo</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>City / Locality</label>
                        <select name="city" id="city-select" required>
                            <option value="">Select City</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Project Type</label>
                        <select name="type" id="project-type" onchange="toggleFinishLevel()" required>
                            <option value="in-house">In-House (Self Funded)</option>
                            <option value="3rd-party">Capital Project (3rd Party)</option>
                        </select>
                    </div>
                    <div class="form-group" id="finish-level-group">
                        <label>Finish Level</label>
                        <select name="finishlevel" id="finish-level">
                            <option value="Shell">Shell</option>
                            <option value="Common Parts Only">Common Parts Only</option>
                            <option value="Semi Finished">Semi Finished</option>
                            <option value="Finished">Finished</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 2rem; margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.02); border-radius: 8px;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--warning);">
                        <input type="checkbox" name="is_tracking" style="width: 18px; height: 18px;">
                        <strong>Project is in Tracking Stage (Early Feasibility)</strong>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--danger);">
                        <input type="checkbox" name="summer_break_flag" style="width: 18px; height: 18px;">
                        <strong>Summer Break Alarm Active (Tourism Area)</strong>
                    </label>
                </div>
            </div>

            <div style="margin-bottom: 3rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                    <h3>PA Numbers & Permits</h3>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addPAEntry()">+ Add PA Number</button>
                </div>
                <div id="pa-entries-container" style="display: grid; gap: 1rem;"></div>
            </div>

            <div style="margin-bottom: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                    <div>
                        <h3 style="margin: 0; color: var(--primary-color);">Building Blocks & Levels</h3>
                        <p style="margin: 0; font-size: 0.85rem; color: var(--text-secondary);">Define the physical blocks and their level ranges for execution tracking.</p>
                    </div>
                    <button type="button" class="btn btn-sm" onclick="addBlockEntry()" style="background: var(--primary-color);">+ Add Block</button>
                </div>
                <div id="block-entries-container" style="display: grid; gap: 1rem;"></div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1.25rem; font-size: 1.1rem;">Create Project</button>
        </form>
    </section>
</div>

<script src="localities.js"></script>

<script>
// --- PA Numbers Logic ---
let paEntryCount = 0;
const architects = <?= json_encode($architects) ?>;
const engineers = <?= json_encode($engineers) ?>;

const paStatuses = [
    "Tracking", "Screening", "Processing", "Awaiting Recommendation",
    "Awaiting Decision", "Approved", "Endorsed", "Refused",
    "Withdrawn", "Suspended", "Appealed", "Revoked"
];

function addPAEntry() {
    const container = document.getElementById('pa-entries-container');
    const div = document.createElement('div');
    div.id = `pa-entry-${paEntryCount}`;
    div.style.cssText = "background: rgba(255,255,255,0.03); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-glass); display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; position: relative;";

    const statusOptions = paStatuses.map(status => `<option value="${status}">${status}</option>`).join('');

    div.innerHTML = `
        <div class="form-group" style="margin:0;"><label>PA Number</label><input type="text" name="paentries[${paEntryCount}][pa_number]" placeholder="e.g. PA/1234/24" required></div>
        <div class="form-group" style="margin:0;"><label>Status</label>
            <select name="paentries[${paEntryCount}][pa_status]">
                ${statusOptions}
            </select>
        </div>
        <div class="form-group" style="margin:0;"><label>Architect</label><select name="paentries[${paEntryCount}][architect_id]">
            <option value="">Select Architect</option>
            ${architects.map(a => `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('')}
        </select></div>
        <div class="form-group" style="margin:0;"><label>Engineer</label><select name="paentries[${paEntryCount}][structural_engineer_id]">
            <option value="">Select Engineer</option>
            ${engineers.map(e => `<option value="${e.id}">${escapeHtml(e.name)}</option>`).join('')}
        </select></div>
        <button type="button" onclick="document.getElementById('pa-entry-${paEntryCount}').remove()" class="btn btn-sm btn-danger" style="position: absolute; top: -10px; right: -10px; border-radius: 50%; width: 30px; height: 30px; padding: 0;">X</button>
    `;
    container.appendChild(div);
    paEntryCount++;
}

// --- Blocks Logic ---
let blockEntryCount = 0;

function addBlockEntry() {
    const container = document.getElementById('block-entries-container');
    const div = document.createElement('div');
    div.id = `block-entry-${blockEntryCount}`;
    div.style.cssText = "background: rgba(99, 102, 241, 0.05); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--primary-color); display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 1rem; align-items: end; position: relative;";

    div.innerHTML = `
        <div class="form-group" style="margin:0;"><label>Block Name</label><input type="text" name="blocks[${blockEntryCount}][name]" placeholder="e.g. Block A or Garage Complex" required></div>
        <div class="form-group" style="margin:0;"><label>Type</label><select name="blocks[${blockEntryCount}][type]">
            <option value="Block">Block</option>
            <option value="Garage Complex">Garage Complex</option>
            <option value="Villa">Villa</option>
            <option value="Commercial">Commercial</option>
        </select></div>
        <div class="form-group" style="margin:0;"><label>Lowest Level (e.g. -2)</label><input type="number" name="blocks[${blockEntryCount}][lowest]" value="0" required></div>
        <div class="form-group" style="margin:0;"><label>Highest Level (e.g. 5)</label><input type="number" name="blocks[${blockEntryCount}][highest]" value="0" required></div>
        
        <button type="button" onclick="document.getElementById('block-entry-${blockEntryCount}').remove()" class="btn btn-sm btn-danger" style="position: absolute; top: -10px; right: -10px; border-radius: 50%; width: 30px; height: 30px; padding: 0;">X</button>
    `;
    container.appendChild(div);
    blockEntryCount++;
}

function escapeHtml(text) { return text ? String(text).replace(/[&<>"'`=\/]/g, function(s){return entityMap[s];}) : ''; }
const entityMap = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'};

// --- Cities Link Logic ---
function updateCities() {
    const islandSelect = document.getElementById('island');
    const citySelect = document.getElementById('city-select');
    
    citySelect.innerHTML = '<option value="">Select City</option>';
    
    if (typeof locations !== 'undefined' && locations[islandSelect.value]) {
        locations[islandSelect.value].forEach(city => {
            const opt = document.createElement('option'); 
            opt.value = city; 
            opt.textContent = city;
            citySelect.appendChild(opt);
        });
    } else {
        console.warn("localities.js not found. Could not load cities.");
    }
}

function toggleFinishLevel() { document.getElementById('finish-level-group').style.display = document.getElementById('project-type').value === 'in-house' ? 'block' : 'none'; }

document.addEventListener('DOMContentLoaded', function() {
    // Load 1 empty row of each by default for convenience
    addPAEntry();
    addBlockEntry(); 
    
    // Populate dropdown with default Malta cities on load
    updateCities();
});
</script>

<?php require_once 'footer.php'; ?>
