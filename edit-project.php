<?php
require_once 'init.php';
require_once 'session-check.php';

$projectId = $_GET['id'] ?? null;
if (!$projectId) { header('Location: dashboard.php'); exit; }

if (!canEditProjectDetails($pdo, $projectId)) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$project = getProjectWithClient($pdo, $projectId);
if (!$project) { header('Location: dashboard.php'); exit; }

$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update') {
    try {
        $pdo->beginTransaction();

        // 1. UPDATE CORE PROJECT DETAILS
        $clientId = $_POST['clientid'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $island = $_POST['island'] ?? '';
        $type = $_POST['type'] ?? '';
        $finishLevel = ($_POST['finishlevel'] ?? '') ?: null;
        $isTracking = isset($_POST['is_tracking']) ? 1 : 0;
        $summerBreak = isset($_POST['summer_break_flag']) ? 1 : 0;
        
        $stmt = $pdo->prepare("UPDATE projects SET clientid=?, name=?, city=?, island=?, type=?, finishlevel=?, is_tracking=?, summer_break_flag=? WHERE id=?");
        $stmt->execute([$clientId, $name, $city, $island, $type, $finishLevel, $isTracking, $summerBreak, $projectId]);
        
        // 2. UPDATE PA NUMBERS
        if (isset($_POST['paentries']) && is_array($_POST['paentries'])) {
            $pdo->prepare("DELETE FROM project_pa_numbers WHERE project_id = ?")->execute([$projectId]);
            $paStmt = $pdo->prepare("INSERT INTO project_pa_numbers (project_id, pa_number, pa_status, architect_id, structural_engineer_id) VALUES (?, ?, ?, ?, ?)");
            foreach ($_POST['paentries'] as $paEntry) {
                if (!empty($paEntry['pa_number'])) {
                    $paStmt->execute([
                        $projectId, trim($paEntry['pa_number']), $paEntry['pa_status'] ?? 'Endorsed',
                        !empty($paEntry['architect_id']) ? $paEntry['architect_id'] : null,
                        !empty($paEntry['structural_engineer_id']) ? $paEntry['structural_engineer_id'] : null
                    ]);
                }
            }
        }

        // 3. UPDATE BLOCKS & AUTO-GENERATE LEVELS
        $submittedBlockIds = [];
        if (isset($_POST['blocks']) && is_array($_POST['blocks'])) {
            foreach ($_POST['blocks'] as $b) {
                $bId = !empty($b['id']) ? (int)$b['id'] : null;
                $bName = trim($b['name'] ?? '');
                $bType = $b['type'] ?? 'Block';
                $bLow = (int)($b['lowest'] ?? 0);
                $bHigh = (int)($b['highest'] ?? 0);

                if (empty($bName)) continue;
                if ($bLow > $bHigh) { $temp = $bLow; $bLow = $bHigh; $bHigh = $temp; } // Swap if backwards

                if ($bId) {
                    $pdo->prepare("UPDATE project_blocks SET block_name=?, block_type=?, lowest_level=?, highest_level=? WHERE id=? AND project_id=?")
                        ->execute([$bName, $bType, $bLow, $bHigh, $bId, $projectId]);
                    $submittedBlockIds[] = $bId;
                } else {
                    $pdo->prepare("INSERT INTO project_blocks (project_id, block_name, block_type, lowest_level, highest_level) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$projectId, $bName, $bType, $bLow, $bHigh]);
                    $bId = $pdo->lastInsertId();
                    $submittedBlockIds[] = $bId;
                }

                // Auto-generate levels for this block (INSERT IGNORE preserves existing statuses)
                $levelStmt = $pdo->prepare("INSERT IGNORE INTO block_levels (block_id, level_number, level_name) VALUES (?, ?, ?)");
                for ($lvl = $bLow; $lvl <= $bHigh; $lvl++) {
                    $lvlName = ($lvl === 0) ? "Level 0 (Ground)" : "Level " . $lvl;
                    $levelStmt->execute([$bId, $lvl, $lvlName]);
                }
                
                // Remove floors if the user shrank the building range
                $pdo->prepare("DELETE FROM block_levels WHERE block_id=? AND (level_number < ? OR level_number > ?)")
                    ->execute([$bId, $bLow, $bHigh]);
            }
        }

        // Delete blocks removed from UI
        if (!empty($submittedBlockIds)) {
            $placeholders = implode(',', array_fill(0, count($submittedBlockIds), '?'));
            $params = $submittedBlockIds;
            $params[] = $projectId;
            $pdo->prepare("DELETE FROM project_blocks WHERE id NOT IN ($placeholders) AND project_id=?")->execute($params);
        } else {
            $pdo->prepare("DELETE FROM project_blocks WHERE project_id=?")->execute([$projectId]);
        }

        $pdo->commit();
        $message = 'Project updated successfully!';
        $project = getProjectWithClient($pdo, $projectId); // Refresh
        
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = 'Error updating project: ' . $e->getMessage();
    }
}

// Get data for dropdowns
$clients = isAdmin() ? $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll() : getUserClients($pdo, getCurrentUserId());
$architects = $pdo->query("SELECT id, name, firm_name FROM professionals WHERE role_type = 'architect' ORDER BY name")->fetchAll();
$engineers = $pdo->query("SELECT id, name, firm_name FROM professionals WHERE role_type = 'structural_engineer' ORDER BY name")->fetchAll();

$paNumbers = $pdo->prepare("SELECT * FROM project_pa_numbers WHERE project_id = ? ORDER BY created_at ASC");
$paNumbers->execute([$projectId]);
$paNumbers = $paNumbers->fetchAll();

$blocks = $pdo->prepare("SELECT * FROM project_blocks WHERE project_id = ? ORDER BY id ASC");
$blocks->execute([$projectId]);
$projectBlocks = $blocks->fetchAll();

$pageTitle = 'Edit Project - ' . $project['name'];
require_once 'header.php';
?>

<div class="main-container">
    <h1 class="page-title">Edit Project: <?= htmlspecialchars($project['name']); ?></h1>

    <?php if ($message): ?><div class="message success"><?= htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="message error"><?= htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="form-section">
        <form method="POST">
            <input type="hidden" name="action" value="update">

            <div style="margin-bottom: 2rem;">
                <h3 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Core Details</h3>
                
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                    <div class="form-group">
                        <label>Client</label>
                        <select name="clientid" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($c['id'] == $project['clientid']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Project Name</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($project['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Island</label>
                        <select name="island" id="island" onchange="updateCities()" required>
                            <option value="Malta" <?= $project['island'] === 'Malta' ? 'selected' : '' ?>>Malta</option>
                            <option value="Gozo" <?= $project['island'] === 'Gozo' ? 'selected' : '' ?>>Gozo</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>City / Locality</label>
                        <select name="city" id="city-select" required>
                            <option value="<?= htmlspecialchars($project['city']) ?>" selected><?= htmlspecialchars($project['city']) ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Project Type</label>
                        <select name="type" id="project-type" onchange="toggleFinishLevel()" required>
                            <option value="in-house" <?= $project['type'] == 'in-house' ? 'selected' : '' ?>>In-House</option>
                            <option value="3rd-party" <?= $project['type'] == '3rd-party' ? 'selected' : '' ?>>3rd Party</option>
                        </select>
                    </div>
                    <div class="form-group" id="finish-level-group" style="display: <?= $project['type'] == 'in-house' ? 'block' : 'none' ?>;">
                        <label>Finish Level</label>
                        <select name="finishlevel" id="finish-level">
                            <option value="Shell" <?= $project['finishlevel'] == 'Shell' ? 'selected' : '' ?>>Shell</option>
                            <option value="Common Parts Only" <?= $project['finishlevel'] == 'Common Parts Only' ? 'selected' : '' ?>>Common Parts Only</option>
                            <option value="Semi Finished" <?= $project['finishlevel'] == 'Semi Finished' ? 'selected' : '' ?>>Semi Finished</option>
                            <option value="Finished" <?= $project['finishlevel'] == 'Finished' ? 'selected' : '' ?>>Finished</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 2rem; margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.02); border-radius: 8px;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--warning);">
                        <input type="checkbox" name="is_tracking" <?= $project['is_tracking'] ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                        <strong>Project is in Tracking Stage (Early Feasibility)</strong>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--danger);">
                        <input type="checkbox" name="summer_break_flag" <?= $project['summer_break_flag'] ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                        <strong>Summer Break Alarm Active (Tourism Area)</strong>
                    </label>
                </div>
            </div>

            <div style="margin-bottom: 3rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                    <h3>PA Numbers</h3>
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

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1.25rem; font-size: 1.1rem;">Save Project Details</button>
        </form>
    </section>
</div>

<script>
// --- PA Numbers Logic ---
let paEntryCount = 0;
const architects = <?= json_encode($architects) ?>;
const engineers = <?= json_encode($engineers) ?>;
const existingPANumbers = <?= json_encode($paNumbers) ?>;

function addPAEntry(paData = null) {
    const container = document.getElementById('pa-entries-container');
    const div = document.createElement('div');
    div.id = `pa-entry-${paEntryCount}`;
    div.style.cssText = "background: rgba(255,255,255,0.03); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-glass); display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; position: relative;";

    const paNum = paData ? paData.pa_number : '';
    const paStat = paData ? paData.pa_status : 'Endorsed';
    const aId = paData ? paData.architect_id : '';
    const eId = paData ? paData.structural_engineer_id : '';

    div.innerHTML = `
        <div class="form-group" style="margin:0;"><label>PA Number</label><input type="text" name="paentries[${paEntryCount}][pa_number]" value="${escapeHtml(paNum)}" placeholder="e.g. PA/1234/24" required></div>
        <div class="form-group" style="margin:0;"><label>Status</label><select name="paentries[${paEntryCount}][pa_status]">
            <option value="Endorsed" ${paStat==='Endorsed'?'selected':''}>Endorsed</option>
            <option value="Decided" ${paStat==='Decided'?'selected':''}>Decided</option>
            <option value="Tracking" ${paStat==='Tracking'?'selected':''}>Tracking</option>
        </select></div>
        <div class="form-group" style="margin:0;"><label>Architect</label><select name="paentries[${paEntryCount}][architect_id]">
            <option value="">Select Architect</option>
            ${architects.map(a => `<option value="${a.id}" ${a.id==aId?'selected':''}>${escapeHtml(a.name)}</option>`).join('')}
        </select></div>
        <div class="form-group" style="margin:0;"><label>Engineer</label><select name="paentries[${paEntryCount}][structural_engineer_id]">
            <option value="">Select Engineer</option>
            ${engineers.map(e => `<option value="${e.id}" ${e.id==eId?'selected':''}>${escapeHtml(e.name)}</option>`).join('')}
        </select></div>
        <button type="button" onclick="document.getElementById('pa-entry-${paEntryCount}').remove()" class="btn btn-sm btn-danger" style="position: absolute; top: -10px; right: -10px; border-radius: 50%; width: 30px; height: 30px; padding: 0;">X</button>
    `;
    container.appendChild(div);
    paEntryCount++;
}

// --- Blocks Logic ---
let blockEntryCount = 0;
const existingBlocks = <?= json_encode($projectBlocks) ?>;

function addBlockEntry(blockData = null) {
    const container = document.getElementById('block-entries-container');
    const div = document.createElement('div');
    div.id = `block-entry-${blockEntryCount}`;
    div.style.cssText = "background: rgba(99, 102, 241, 0.05); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--primary-color); display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 1rem; align-items: end; position: relative;";

    const bId = blockData ? blockData.id : '';
    const bName = blockData ? blockData.block_name : '';
    const bType = blockData ? blockData.block_type : 'Block';
    const bLow = blockData ? blockData.lowest_level : 0;
    const bHigh = blockData ? blockData.highest_level : 0;

    div.innerHTML = `
        <input type="hidden" name="blocks[${blockEntryCount}][id]" value="${bId}">
        <div class="form-group" style="margin:0;"><label>Block Name</label><input type="text" name="blocks[${blockEntryCount}][name]" value="${escapeHtml(bName)}" placeholder="e.g. Block A or Garage Complex" required></div>
        <div class="form-group" style="margin:0;"><label>Type</label><select name="blocks[${blockEntryCount}][type]">
            <option value="Block" ${bType==='Block'?'selected':''}>Block</option>
            <option value="Garage Complex" ${bType==='Garage Complex'?'selected':''}>Garage Complex</option>
            <option value="Villa" ${bType==='Villa'?'selected':''}>Villa</option>
            <option value="Commercial" ${bType==='Commercial'?'selected':''}>Commercial</option>
        </select></div>
        <div class="form-group" style="margin:0;"><label>Lowest Level (e.g. -2)</label><input type="number" name="blocks[${blockEntryCount}][lowest]" value="${bLow}" required></div>
        <div class="form-group" style="margin:0;"><label>Highest Level (e.g. 5)</label><input type="number" name="blocks[${blockEntryCount}][highest]" value="${bHigh}" required></div>
        
        <button type="button" onclick="if(confirm('Remove this block and all its floor progress?')) document.getElementById('block-entry-${blockEntryCount}').remove()" class="btn btn-sm btn-danger" style="position: absolute; top: -10px; right: -10px; border-radius: 50%; width: 30px; height: 30px; padding: 0;">X</button>
    `;
    container.appendChild(div);
    blockEntryCount++;
}

function escapeHtml(text) { return text ? String(text).replace(/[&<>"'`=\/]/g, function(s){return entityMap[s];}) : ''; }
const entityMap = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'};

document.addEventListener('DOMContentLoaded', function() {
    if (existingPANumbers && existingPANumbers.length > 0) { existingPANumbers.forEach(pa => addPAEntry(pa)); } else { addPAEntry(); }
    if (existingBlocks && existingBlocks.length > 0) { existingBlocks.forEach(b => addBlockEntry(b)); } else { addBlockEntry({block_name: 'Main Building', block_type: 'Block', lowest_level: 0, highest_level: 0}); }
});

// City Data
const locations = {
    'Malta': [{ label: 'Northern', cities: ['Mellieha', 'Mosta', 'Naxxar'] }, { label: 'Central', cities: ['Sliema', 'St Venera'] }, { label: 'Southern', cities: ['Paola', 'Zejtun'] }],
    'Gozo': [{ label: 'Gozo', cities: ['Rabat Victoria', 'Xaghra'] }]
};

function updateCities() {
    const islandSelect = document.getElementById('island');
    const citySelect = document.getElementById('city-select');
    const currentCity = citySelect.value;
    citySelect.innerHTML = '';
    const defaultOption = document.createElement('option'); defaultOption.value = ''; defaultOption.textContent = 'Select City'; citySelect.appendChild(defaultOption);
    
    if (locations[islandSelect.value]) {
        locations[islandSelect.value].forEach(group => {
            const optgroup = document.createElement('optgroup'); optgroup.label = group.label;
            group.cities.forEach(city => {
                const opt = document.createElement('option'); opt.value = city; opt.textContent = city;
                if (city === currentCity) opt.selected = true;
                optgroup.appendChild(opt);
            });
            citySelect.appendChild(optgroup);
        });
    }
}
function toggleFinishLevel() { document.getElementById('finish-level-group').style.display = document.getElementById('project-type').value === 'in-house' ? 'block' : 'none'; }
</script>

<?php require_once 'footer.php'; ?>
