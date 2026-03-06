<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities (Assumes you have a 'view_services' capability, or Admin)
if (!hasPermission('view_services') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$userId = getCurrentUserId();
$projects = getAccessibleProjects($pdo, $userId);

// Fetch aggregated engineering data
$projectIds = array_column($projects, 'id');
$engineeringData = [];

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    
    // Get Temp Services from Mobilisation
    $mobStmt = $pdo->prepare("SELECT project_id, temporary_water, temporary_electricity FROM project_mobilisation WHERE project_id IN ($placeholders)");
    $mobStmt->execute($projectIds);
    while ($row = $mobStmt->fetch(PDO::FETCH_ASSOC)) {
        $engineeringData[$row['project_id']]['temp_water'] = $row['temporary_water'];
        $engineeringData[$row['project_id']]['temp_elec'] = $row['temporary_electricity'];
    }

    // Get Compliance info from Blocks
    $blockStmt = $pdo->prepare("SELECT project_id, COUNT(id) as total_blocks, SUM(IF(compliance_certified='Yes', 1, 0)) as cert_blocks, SUM(IF(cp_meters_installed='Yes', 1, 0)) as cp_meters FROM project_blocks WHERE project_id IN ($placeholders) GROUP BY project_id");
    $blockStmt->execute($projectIds);
    while ($row = $blockStmt->fetch(PDO::FETCH_ASSOC)) {
        $engineeringData[$row['project_id']]['blocks'] = $row;
    }

    // Get ARMS Meters counts
    $armsStmt = $pdo->prepare("SELECT project_id, COUNT(id) as total_meters FROM project_arms_meters WHERE project_id IN ($placeholders) GROUP BY project_id");
    $armsStmt->execute($projectIds);
    while ($row = $armsStmt->fetch(PDO::FETCH_ASSOC)) {
        $engineeringData[$row['project_id']]['meters'] = $row['total_meters'];
    }
}

$pageTitle = 'Engineering & Services';
require_once 'header.php';
?>

<div class="main-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h1 class="page-title" style="margin: 0;">Engineering, Services & Utilities</h1>
    </div>

    <div class="dashboard-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th class="min-w-150">Project Name</th>
                    <th>Locality</th>
                    <th>Temp Water</th>
                    <th>Temp Electricity</th>
                    <th>Block Compliance</th>
                    <th>ARMS Meters</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $project): 
                    if (in_array($project['project_status'] ?? '', ['Withdrawn', 'On-Hold'])) continue;
                    $pId = $project['id'];
                    $eng = $engineeringData[$pId] ?? [];
                    
                    $tWater = $eng['temp_water'] ?? 'Pending';
                    $tElec = $eng['temp_elec'] ?? 'Pending';
                    $wColor = in_array($tWater, ['Yes', 'Connected', 'Complete']) ? 'var(--success)' : (in_array($tWater, ['In Process']) ? 'var(--warning)' : 'var(--danger)');
                    $eColor = in_array($tElec, ['Yes', 'Connected', 'Complete']) ? 'var(--success)' : (in_array($tElec, ['In Process']) ? 'var(--warning)' : 'var(--danger)');
                    
                    $bTotal = $eng['blocks']['total_blocks'] ?? 0;
                    $bCert = $eng['blocks']['cert_blocks'] ?? 0;
                    
                    $meters = $eng['meters'] ?? 0;
                ?>
                <tr>
                    <td><?= $pId ?></td>
                    <td style="font-weight: bold;"><?= htmlspecialchars($project['name']) ?></td>
                    <td><?= htmlspecialchars($project['city']) ?></td>
                    <td style="color: <?= $wColor ?>; font-weight: 500;"><?= htmlspecialchars($tWater) ?></td>
                    <td style="color: <?= $eColor ?>; font-weight: 500;"><?= htmlspecialchars($tElec) ?></td>
                    <td>
                        <?php if($bTotal > 0): ?>
                            <span style="color: <?= $bCert == $bTotal ? 'var(--success)' : 'var(--warning)' ?>;">
                                <?= $bCert ?> / <?= $bTotal ?> Certified
                            </span>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">No Blocks</span>
                        <?php endif; ?>
                    </td>
                    <td><span style="background: rgba(14, 165, 233, 0.1); color: #0ea5e9; padding: 0.25rem 0.75rem; border-radius: 50px; font-weight: bold; font-size: 0.85rem;"><?= $meters ?> Tracked</span></td>
                    <td>
                        <button type="button" onclick="openEngineeringModal(<?= $pId ?>, '<?= htmlspecialchars($project['name'], ENT_QUOTES) ?>')" class="btn btn-sm btn-primary">Update Services</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="engineeringModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); backdrop-filter: blur(5px);">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 95%; max-width: 1200px; height: 90vh; background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border-glass); display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.6);">
        <div style="padding: 1rem 1.5rem; background: #1e1e2d; border-bottom: 1px solid var(--border-glass); display: flex; justify-content: space-between; align-items: center;">
            <h2 id="modalTitle" style="margin: 0; color: #0ea5e9; font-size: 1.25rem;">Update Engineering Details</h2>
            <span onclick="closeEngineeringModal()" style="color: var(--text-muted); font-size: 1.75rem; font-weight: bold; cursor: pointer;">&times;</span>
        </div>
        <iframe id="engineeringIframe" src="" style="flex-grow: 1; width: 100%; height: 100%; border: none; background: transparent;"></iframe>
    </div>
</div>

<script>
function openEngineeringModal(id, name) {
    document.getElementById('modalTitle').innerText = 'Engineering & Services: ' + name;
    document.getElementById('engineeringIframe').src = 'edit-engineering.php?id=' + id;
    document.getElementById('engineeringModal').style.display = 'block';
}

function closeEngineeringModal() {
    document.getElementById('engineeringModal').style.display = 'none';
    document.getElementById('engineeringIframe').src = '';
}

window.addEventListener('message', function(event) {
    if (event.data === 'engineeringUpdated') {
        window.location.reload();
    }
});
</script>

<?php require_once 'footer.php'; ?>
