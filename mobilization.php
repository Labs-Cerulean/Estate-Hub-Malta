<?php
require_once 'init.php';
require_once 'session-check.php';

// Get accessible projects
$accessibleProjects = getAccessibleProjects($pdo, getCurrentUserId(), getCurrentRole());

// Define the stages that belong in a "Mobilisation Meeting"
// Feasibility is excluded as requested. Once a project hits Stage 7 (Construction), it drops off.
$mobilisationStages = ['Tracking', 'Permit', 'Mobilisation', 'Demolition', 'Excavation'];

$stageColors = [
    'Tracking' => '#0ea5e9', 
    'Permit' => '#3b82f6', 
    'Mobilisation' => '#6366f1', 
    'Demolition' => '#ef4444', 
    'Excavation' => '#f97316'
];

$activeProjects = [];

// Explicitly fetch all PA numbers so they display in the sidebar
$paData = [];
try {
    $paStmt = $pdo->query("SELECT project_id, pa_number FROM project_pa_numbers");
    foreach ($paStmt->fetchAll() as $row) {
        $paData[$row['project_id']] = $row['pa_number'];
    }
} catch(PDOException $e) {} // Failsafe

foreach ($accessibleProjects as $project) {
    // Attach the PA number to the project data
    $project['pa_number'] = $paData[$project['id']] ?? null;
    if (($project['project_status'] ?? 'Active') !== 'Active') continue;
    $stage = deriveProjectStage($pdo, $project['id']);
    
    // STRICT FILTER: Only show projects in Stages 2 through 6
    if (in_array($stage, $mobilisationStages)) {
        
        $mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
        $mobStmt->execute([$project['id']]);
        $mob = $mobStmt->fetch();
        
        $completedSteps = 0;
        $totalSteps = 14; 
        
        if ($mob) {
            // Non-sequential
            if (in_array($mob['archaeologist_assigned'] ?? '', ['Yes', 'NA'])) $completedSteps++;
            if (in_array($mob['change_of_applicant'] ?? '', ['Complete', 'NA'])) $completedSteps++;
            if (in_array($mob['geological_test'] ?? '', ['Complete', 'NA'])) $completedSteps++;
            if (in_array($mob['condition_report_contacts'] ?? '', ['Complete', 'NA'])) $completedSteps++;
            if (in_array($mob['condition_reports'] ?? '', ['Complete', 'NA'])) $completedSteps++;
            
            // Sequential
            if (($mob['method_statements'] ?? '') === 'Complete') $completedSteps++;
            if (($mob['insurance_status'] ?? '') === 'Complete') $completedSteps++;
            if (($mob['pavement_guarantee'] ?? '') === 'Complete') $completedSteps++;
            if (($mob['wellbeing_guarantee'] ?? '') === 'Complete') $completedSteps++;
            if (($mob['umbrella_guarantee'] ?? '') === 'Complete') $completedSteps++;
            
            // Clearances
            if (($mob['responsibility_form'] ?? '') === 'Complete') $completedSteps++;
            if (in_array($mob['mob_demolition'] ?? '', ['Yes', 'NA'])) $completedSteps++;
            if (in_array($mob['mob_excavation'] ?? '', ['Yes', 'NA'])) $completedSteps++;
            if (in_array($mob['mob_construction'] ?? '', ['Yes', 'NA'])) $completedSteps++;
        }
        
        $project['stage'] = $stage;
        $project['progress'] = round(($completedSteps / $totalSteps) * 100);
        $activeProjects[] = $project;
    }
}

// Automatically Sort by Progress (Highest % first, then alphabetical)
usort($activeProjects, function($a, $b) {
    if ($a['progress'] === $b['progress']) {
        return strcasecmp($a['name'], $b['name']);
    }
    return $b['progress'] <=> $a['progress']; // Descending order
});

$pageTitle = 'Mobilisation Meeting Hub';
require_once 'header.php';
?>

<style>
/* Meeting Mode UI Overrides */
.main-content {
    padding: 0 !important;
    margin: 0 !important;
    max-width: 100% !important;
}

.meeting-container {
    display: flex;
    height: calc(100vh - 70px); /* Accounts for header height */
    background: var(--bg-primary);
    overflow: hidden;
}

/* Left Sidebar - Project List */
.meeting-sidebar {
    width: 380px;
    min-width: 380px;
    background: var(--bg-card);
    border-right: 1px solid var(--border-glass);
    display: flex;
    flex-direction: column;
    z-index: 10;
    box-shadow: 2px 0 10px rgba(0,0,0,0.2);
}

.sidebar-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-glass);
    background: rgba(255,255,255,0.02);
}

.sidebar-list {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
}

/* Custom Scrollbar for Sidebar */
.sidebar-list::-webkit-scrollbar { width: 6px; }
.sidebar-list::-webkit-scrollbar-track { background: transparent; }
.sidebar-list::-webkit-scrollbar-thumb { background: var(--border-glass); border-radius: 10px; }

.project-item {
    padding: 1rem;
    border: 1px solid var(--border-glass);
    border-radius: 8px;
    margin-bottom: 0.75rem;
    cursor: pointer;
    transition: all 0.2s ease;
    background: var(--bg-primary);
    position: relative;
}

.project-item:hover {
    border-color: var(--primary-color);
    transform: translateX(4px);
}

.project-item.active {
    border-left: 4px solid var(--primary-color);
    background: rgba(99, 102, 241, 0.1);
    border-top-color: var(--primary-color);
    border-right-color: var(--primary-color);
    border-bottom-color: var(--primary-color);
}

.item-title {
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
    font-size: 1.05rem;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.item-client {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-bottom: 0.75rem;
    display: flex;
    justify-content: space-between;
}

.item-stage-badge {
    font-size: 0.7rem;
    padding: 0.2rem 0.5rem;
    border-radius: 12px;
    font-weight: 700;
    color: white;
}

.item-progress-bg {
    height: 6px;
    background: rgba(255,255,255,0.1);
    border-radius: 3px;
    overflow: hidden;
    margin-top: 0.5rem;
}

.item-progress-fill {
    height: 100%;
    background: var(--primary-color);
}

/* Right Panel - Iframe Content */
.meeting-main {
    flex: 1;
    background: var(--bg-primary);
    position: relative;
}

#detail-iframe {
    width: 100%;
    height: 100%;
    border: none;
    background: var(--bg-primary);
}

.empty-state-meeting {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    color: var(--text-muted);
}
</style>

<div class="meeting-container">
    
    <div class="meeting-sidebar">
        <div class="sidebar-header">
            <h2 style="margin: 0 0 0.5rem 0; font-size: 1.25rem; color: var(--text-primary);">Mobilisation Hub</h2>
            <p style="margin: 0 0 1rem 0; font-size: 0.85rem; color: var(--text-secondary);">Showing <?= count($activeProjects) ?> pre-construction sites.</p>
            
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" id="search-box" placeholder="Search..." 
                       onkeyup="filterProjects()"
                       style="flex: 1; padding: 0.6rem 1rem; border-radius: 20px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary); outline: none;">
                
                <select id="island-filter" onchange="filterProjects()" style="width: 100px; padding: 0.6rem 1rem; border-radius: 20px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary); outline: none; cursor: pointer;">
                    <option value="all">All</option>
                    <option value="Malta">Malta</option>
                    <option value="Gozo">Gozo</option>
                </select>
            </div>
        </div>
        
        <div class="sidebar-list" id="project-list">
            <?php if (empty($activeProjects)): ?>
                <div style="text-align: center; color: var(--text-muted); margin-top: 2rem;">
                    No projects currently require mobilisation.
                </div>
            <?php else: ?>
                <?php foreach ($activeProjects as $project): ?>
                    <div class="project-item" 
                         data-name="<?= strtolower(htmlspecialchars($project['name'] . ' ' . $project['client_name'])) ?>"
                         data-island="<?= htmlspecialchars($project['island'] ?? 'Malta') ?>"
                         onclick="loadProject(<?= $project['id'] ?>, this)">
                        
                        <div class="item-title">
                            <?= htmlspecialchars($project['name']) ?>
                            <span class="item-stage-badge" style="background: <?= $stageColors[$project['stage']] ?>;">
                                <?= $project['stage'] ?>
                            </span>
                        </div>
                        <div class="item-client" style="flex-direction: column; gap: 0.25rem;">
                            <div style="display: flex; justify-content: space-between;">
                                <span><?= htmlspecialchars($project['client_name'] ?? 'N/A') ?></span>
                                <span style="color: var(--primary-color); font-weight: 600;"><?= htmlspecialchars($project['island'] ?? 'Malta') ?></span>
                            </div>
                            <span style="color: var(--primary-color); font-weight: 600; font-size: 0.85em;">
                                PA Ref: <?= !empty($project['pa_number']) ? htmlspecialchars($project['pa_number']) : 'N/A' ?>
                            </span>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--text-secondary);">
                            <span>Clearance Progress</span>
                            <span style="font-weight: 700; color: var(--primary-color);"><?= $project['progress'] ?>%</span>
                        </div>
                        <div class="item-progress-bg">
                            <div class="item-progress-fill" style="width: <?= $project['progress'] ?>%;"></div>
                        </div>
                        
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="meeting-main">
        <div class="empty-state-meeting" id="empty-state">
            <svg style="width: 64px; height: 64px; margin-bottom: 1rem; opacity: 0.5;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
            <h2>Select a project to begin</h2>
            <p>Click a project on the left to instantly load its execution checklist.</p>
        </div>
        
        <iframe id="detail-iframe" style="display: none;"></iframe>
    </div>
    
</div>

<script>
// Search and Island Filter Logic
function filterProjects() {
    const term = document.getElementById('search-box').value.toLowerCase();
    const island = document.getElementById('island-filter').value;
    
    document.querySelectorAll('.project-item').forEach(el => {
        const searchData = el.getAttribute('data-name');
        const projectIsland = el.getAttribute('data-island');
        
        const matchesSearch = searchData.includes(term);
        const matchesIsland = (island === 'all' || projectIsland === island);
        
        if (matchesSearch && matchesIsland) {
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
    });
}

// Split Screen Loader
function loadProject(projectId, element) {
    // 1. Update active state on the left
    document.querySelectorAll('.project-item').forEach(el => el.classList.remove('active'));
    element.classList.add('active');
    
    // 2. Hide empty state, show iframe
    document.getElementById('empty-state').style.display = 'none';
    const iframe = document.getElementById('detail-iframe');
    iframe.style.display = 'block';
    
    // 3. Load the URL
    iframe.src = `mobilisation_detail.php?project_id=${projectId}`;
}

// Automatically hide the duplicate Header inside the iFrame when it loads
document.getElementById('detail-iframe').addEventListener('load', function() {
    try {
        const iframeDoc = this.contentWindow.document;
        
        // Hide the main nav header inside the iframe
        const header = iframeDoc.querySelector('.header');
        if (header) header.style.display = 'none';
        
        // Adjust the main content padding so it touches the top cleanly
        const mainContent = iframeDoc.querySelector('.main-content');
        if (mainContent) {
            mainContent.style.marginTop = '0';
            mainContent.style.paddingTop = '1.5rem';
        }
    } catch(e) {
        console.log("Iframe styling adjustment handled safely.");
    }
});
</script>

<?php require_once 'footer.php'; ?>
