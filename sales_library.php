<?php
require_once 'config.php';
require_once 'session-check.php';
require_once __DIR__ . '/includes/nav_config.php';

if (!salesIsExternalAgent()) {
    header('Location: sales_hub.php');
    exit;
}

$pageTitle = 'Property Library';
require_once 'header.php';
require_once 'S3FileManager.php';

$projectsRaw = salesGetAccessibleProjectsWithUnits($pdo, true);
$projectsByCity = [];
foreach ($projectsRaw as $p) {
    $city = trim($p['city']) ? trim($p['city']) : 'Uncategorized Locations';
    $projectsByCity[$city][] = $p;
}

$projectThumbUrls = [];
if (!empty($projectsRaw)) {
    $projectIds = array_map('intval', array_column($projectsRaw, 'id'));
    try {
        $thumbPlaceholders = implode(',', array_fill(0, count($projectIds), '?'));
        $thumbStmt = $pdo->prepare("
            SELECT pd.project_id, pd.file_path
            FROM project_documents pd
            INNER JOIN (
                SELECT project_id, MIN(id) AS min_id
                FROM project_documents
                WHERE category = 'Sales'
                  AND sub_category = 'Render (Image)'
                  AND project_id IN ($thumbPlaceholders)
                GROUP BY project_id
            ) first_render ON pd.id = first_render.min_id
        ");
        $thumbStmt->execute($projectIds);
        $s3 = new S3FileManager();
        foreach ($thumbStmt->fetchAll(PDO::FETCH_ASSOC) as $thumbRow) {
            try {
                $projectThumbUrls[(int)$thumbRow['project_id']] = $s3->getPresignedUrl($thumbRow['file_path'], '+120 minutes');
            } catch (Exception $e) {
                // Skip broken media keys
            }
        }
    } catch (Exception $e) {
        $projectThumbUrls = [];
    }
}
?>

<style>
    :root {
        --lib-bg-base: #0f172a;
        --lib-bg-panel: #1e293b;
        --lib-border: #334155;
        --lib-text-main: #f8fafc;
        --lib-text-muted: #94a3b8;
        --lib-accent: #3b82f6;
        --lib-avail: #10b981;
    }
    .library-wrapper { background: var(--lib-bg-base); min-height: 100vh; padding: 20px 0 50px; }
    .library-container { max-width: 100%; margin: 0 auto; padding: 0 24px; color: var(--lib-text-main); font-family: 'Inter', sans-serif; }
    .library-header { background: var(--lib-bg-panel); border: 1px solid var(--lib-border); border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    .library-header h2 { margin: 0; font-weight: 900; color: var(--lib-text-main); }
    .library-section { background: var(--lib-bg-panel); border: 1px solid var(--lib-border); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    .library-section h3 { margin: 0 0 15px; font-size: 1rem; font-weight: 800; }
    .lib-project-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 14px; width: 100%; }
    .lib-project-card {
        background: var(--lib-bg-base); border: 2px solid var(--lib-border); border-radius: 10px;
        padding: 8px; cursor: pointer; text-align: center; color: #fff; width: 100%; transition: 0.2s;
    }
    .lib-project-card:hover { border-color: var(--lib-accent); transform: translateY(-1px); }
    .lib-project-card.selected { border-color: var(--lib-avail); box-shadow: 0 0 0 1px var(--lib-avail); background: rgba(16,185,129,0.08); }
    .lib-project-thumb { width: 100%; aspect-ratio: 4/3; border-radius: 6px; overflow: hidden; background: rgba(0,0,0,0.35); display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
    .lib-project-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .lib-project-thumb i { font-size: 1.6rem; color: var(--lib-text-muted); }
    .lib-project-city { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; color: var(--lib-text-muted); margin-bottom: 4px; }
    .lib-project-name { font-size: 0.72rem; font-weight: 700; line-height: 1.25; min-height: 2.4em; display: flex; align-items: center; justify-content: center; }
    .lib-kpi-row { display: flex; gap: 10px; margin-bottom: 15px; }
    .lib-kpi { flex: 1; text-align: center; padding: 10px; border-radius: 8px; background: var(--lib-bg-base); border: 1px solid var(--lib-border); }
    .lib-kpi-val { font-size: 1.4rem; font-weight: 800; }
    .lib-kpi-lbl { font-size: 0.7rem; color: var(--lib-text-muted); text-transform: uppercase; font-weight: 700; }
    .lib-kpi.avail .lib-kpi-val { color: var(--lib-avail); }
    .lib-kpi.hold .lib-kpi-val { color: #64748b; }
    .lib-kpi.sold .lib-kpi-val { color: #3b82f6; }
    .lib-toolbar { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; }
    .lib-btn { padding: 10px 18px; border-radius: 8px; font-weight: 700; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
    .lib-btn-blue { background: rgba(59,130,246,0.15); color: var(--lib-accent); border: 1px solid rgba(59,130,246,0.35); }
    .lib-btn-blue:hover { background: var(--lib-accent); color: #fff; }
    .lib-btn-amber { background: rgba(245,158,11,0.12); color: #f59e0b; border: 1px solid rgba(245,158,11,0.35); }
    .lib-media-box { border-radius: 12px; overflow: hidden; margin-bottom: 15px; background: rgba(0,0,0,0.25); border: 1px solid var(--lib-border); }
    .lib-tabs { display: flex; gap: 8px; margin-bottom: 15px; }
    .lib-tab { padding: 8px 16px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; cursor: pointer; border: 1px solid var(--lib-border); background: var(--lib-bg-base); color: var(--lib-text-muted); }
    .lib-tab.active { background: rgba(16,185,129,0.12); color: var(--lib-avail); border-color: var(--lib-avail); }
    /* Unit cards: responsive grid so large projects are easier to scan */
    .lib-units > div {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 12px;
        padding: 0 !important;
    }
    .lib-units .unit-card {
        margin-bottom: 0 !important;
        height: 100%;
    }
    .lib-units .unit-card .card-body {
        padding: 14px !important;
    }
    .lib-units .unit-card h5 {
        font-size: 1rem !important;
    }
    .lib-units .unit-card .d-flex.justify-content-between.align-items-start {
        flex-direction: column;
        gap: 8px;
    }
    .lib-units .unit-card .badge {
        align-self: flex-start;
    }
    .lib-units .unit-card .text-muted {
        font-size: 0.75rem !important;
        line-height: 1.35;
    }
    .lib-units .unit-card .d-flex.mt-3 {
        flex-direction: column;
        gap: 6px;
        margin-top: 10px !important;
    }
    .lib-units .unit-card .d-flex.mt-3 > div {
        margin-right: 0 !important;
    }
    .lib-units .unit-card [id^="price_disp_"] {
        margin-bottom: 0 !important;
        padding: 10px !important;
    }
    .lib-units .unit-card [id^="price_disp_"] .d-flex {
        flex-direction: column;
        gap: 6px;
        align-items: flex-start !important;
    }
    .lib-units .unit-card [id^="price_disp_"] .text-end {
        text-align: left !important;
    }
    .lib-units .unit-card [id^="price_disp_"] div[style*="1.4rem"] {
        font-size: 1.15rem !important;
    }
    .lib-loader { display: none; text-align: center; padding: 30px; color: var(--lib-accent); font-weight: 700; }
    .lib-modal { display: none; position: fixed; z-index: 2000; inset: 0; background: rgba(15,23,42,0.95); backdrop-filter: blur(8px); }
    .lib-modal-content { background: var(--lib-bg-panel); margin: 5vh auto; padding: 20px; border: 1px solid var(--lib-border); border-radius: 16px; width: 95%; max-width: 1200px; height: 85vh; display: flex; flex-direction: column; color: #fff; }
    .lib-lightbox { display: none; position: fixed; z-index: 3000; inset: 0; background: rgba(0,0,0,0.95); flex-direction: column; align-items: center; justify-content: center; }
    .lib-lightbox-close { position: absolute; top: 20px; right: 30px; color: #fff; font-size: 2.5rem; cursor: pointer; }
    .lib-lightbox-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.1); color: #fff; border: none; font-size: 2rem; padding: 12px; cursor: pointer; border-radius: 50%; }
    .lib-lightbox-prev { left: 20px; }
    .lib-lightbox-next { right: 20px; }
</style>

<div class="library-wrapper">
    <div class="library-container">
        <div class="library-header">
            <h2><i class="fas fa-book-open text-info"></i> Property Library</h2>
            <p style="margin: 8px 0 0; color: var(--lib-text-muted); font-size: 0.9rem;">Browse available developments, view live pricelists, and download full project plans. Read-only access.</p>
        </div>

        <div class="library-section">
            <h3><i class="fas fa-th-large"></i> Select Project</h3>
            <div id="libProjectGrid" class="lib-project-grid">
                <?php if (empty($projectsByCity)): ?>
                    <p style="color: var(--lib-text-muted); font-size: 0.85rem; margin: 0; grid-column: 1 / -1;">No projects are currently available in your library. Contact your account manager if you believe this is an error.</p>
                <?php else: ?>
                    <?php foreach ($projectsByCity as $city => $projs): ?>
                        <?php foreach ($projs as $p):
                            $pid = (int)$p['id'];
                            $thumbUrl = $projectThumbUrls[$pid] ?? '';
                        ?>
                        <button type="button" class="lib-project-card" data-project-id="<?= $pid ?>" data-project-name="<?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>" onclick="selectLibraryProject(this)">
                            <div class="lib-project-thumb">
                                <?php if ($thumbUrl): ?>
                                    <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                <?php else: ?>
                                    <i class="fas fa-building" aria-hidden="true"></i>
                                <?php endif; ?>
                            </div>
                            <div class="lib-project-city"><?= htmlspecialchars($city, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="lib-project-name"><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        </button>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="libraryWorkspace" style="display: none;">
            <div class="library-section">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                    <h3 id="libraryProjectTitle" style="margin: 0;"><i class="fas fa-building"></i> Project</h3>
                    <button type="button" class="lib-btn lib-btn-blue" style="background: transparent; color: var(--lib-text-muted); border-color: var(--lib-border);" onclick="clearLibrarySelection()">
                        <i class="fas fa-arrow-left"></i> Back to grid
                    </button>
                </div>

                <div id="libraryMediaBox" class="lib-media-box"></div>

                <div class="lib-kpi-row">
                    <div class="lib-kpi avail"><div class="lib-kpi-val" id="libAvail">0</div><div class="lib-kpi-lbl">Available</div></div>
                    <div class="lib-kpi hold"><div class="lib-kpi-val" id="libHold">0</div><div class="lib-kpi-lbl">On Hold</div></div>
                    <div class="lib-kpi sold"><div class="lib-kpi-val" id="libSold">0</div><div class="lib-kpi-lbl">Sold</div></div>
                </div>

                <div class="lib-toolbar">
                    <button type="button" class="lib-btn lib-btn-amber" id="libPricelistBtn" onclick="openLibraryPricelist()">
                        <i class="fas fa-file-pdf"></i> Live Pricelist
                    </button>
                    <button type="button" class="lib-btn lib-btn-blue" id="libPlansBtn" style="display:none;" onclick="openLibraryPlans()">
                        <i class="fas fa-drafting-compass"></i> Full Project Plans
                    </button>
                </div>

                <div class="lib-tabs">
                    <div class="lib-tab active" id="libTabAll" onclick="setLibraryFilter('All')">All Units</div>
                    <div class="lib-tab" id="libTabAvail" onclick="setLibraryFilter('Available')">Available Only</div>
                </div>

                <div id="libraryLoader" class="lib-loader"><i class="fas fa-spinner fa-spin"></i> Loading units...</div>
                <div id="libraryUnits" class="lib-units"></div>
            </div>
        </div>
    </div>
</div>

<div id="libraryPlanModal" class="lib-modal">
    <div class="lib-modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h4 style="margin: 0;"><i class="fas fa-drafting-compass"></i> Project Plans</h4>
            <button type="button" class="lib-btn lib-btn-blue" onclick="document.getElementById('libraryPlanModal').style.display='none'" style="padding: 6px 12px;">&times; Close</button>
        </div>
        <iframe id="libraryPlanIframe" src="" style="flex: 1; width: 100%; border: none; border-radius: 8px; background: #e2e8f0;"></iframe>
    </div>
</div>

<div id="lib-lightbox" class="lib-lightbox">
    <span class="lib-lightbox-close" onclick="closeLibraryGallery()">&times;</span>
    <button type="button" class="lib-lightbox-nav lib-lightbox-prev" onclick="changeLibrarySlide(-1)">&#10094;</button>
    <div id="lib-lightbox-media"></div>
    <button type="button" class="lib-lightbox-nav lib-lightbox-next" onclick="changeLibrarySlide(1)">&#10095;</button>
</div>

<script>
    let selectedProjectId = 0;
    let currentProjectPlans = [];
    let currentGallery = [];
    let currentGalleryIndex = 0;
    let libraryStatusFilter = 'All';

    function selectLibraryProject(cardEl) {
        document.querySelectorAll('.lib-project-card').forEach(c => c.classList.remove('selected'));
        cardEl.classList.add('selected');
        selectedProjectId = parseInt(cardEl.getAttribute('data-project-id'), 10);
        const projectName = cardEl.getAttribute('data-project-name') || 'Project';
        document.getElementById('libraryProjectTitle').innerHTML = '<i class="fas fa-building"></i> ' + escapeHtml(projectName);
        document.getElementById('libraryWorkspace').style.display = 'block';
        document.getElementById('libraryLoader').style.display = 'block';
        document.getElementById('libraryUnits').innerHTML = '';
        loadLibraryProject(selectedProjectId);
        document.getElementById('libraryWorkspace').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function clearLibrarySelection() {
        selectedProjectId = 0;
        currentProjectPlans = [];
        document.querySelectorAll('.lib-project-card').forEach(c => c.classList.remove('selected'));
        document.getElementById('libraryWorkspace').style.display = 'none';
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function loadLibraryProject(projectId) {
        fetch('api/get_project_units.php?project_id=' + projectId)
            .then(r => r.json())
            .then(data => {
                document.getElementById('libraryLoader').style.display = 'none';
                if (!data.success) {
                    document.getElementById('libraryUnits').innerHTML = '<p style="color: var(--lib-text-muted);">Could not load project data.</p>';
                    return;
                }

                currentProjectPlans = data.project_plans || [];
                const plansBtn = document.getElementById('libPlansBtn');
                if (currentProjectPlans.length > 0) {
                    plansBtn.style.display = 'inline-flex';
                    plansBtn.innerHTML = '<i class="fas fa-drafting-compass"></i> Full Project Plans' +
                        (currentProjectPlans.length > 1 ? ' (' + currentProjectPlans.length + ')' : '');
                } else {
                    plansBtn.style.display = 'none';
                }

                document.getElementById('libraryUnits').innerHTML = data.html || '';

                let avail = 0, hold = 0, sold = 0;
                document.querySelectorAll('#libraryUnits .unit-card').forEach(card => {
                    const st = card.getAttribute('data-status') || '';
                    if (st === 'Available' || st === 'BOM') avail++;
                    else if (st.indexOf('Hold') !== -1) hold++;
                    else if (st.indexOf('Sold') !== -1 || st.indexOf('Proceeding') !== -1) sold++;
                });
                document.getElementById('libAvail').innerText = avail;
                document.getElementById('libHold').innerText = hold;
                document.getElementById('libSold').innerText = sold;

                currentGallery = [];
                if (data.media && data.media.videos) {
                    data.media.videos.forEach(v => currentGallery.push({ type: 'video', src: v }));
                }
                if (data.media && data.media.renders) {
                    data.media.renders.forEach(r => currentGallery.push({ type: 'image', src: r }));
                }
                renderLibraryMedia();
                applyLibraryFilter();
            })
            .catch(() => {
                document.getElementById('libraryLoader').style.display = 'none';
                document.getElementById('libraryUnits').innerHTML = '<p style="color: #ef4444;">Failed to load project.</p>';
            });
    }

    function renderLibraryMedia() {
        const box = document.getElementById('libraryMediaBox');
        if (currentGallery.length === 0) {
            box.innerHTML = '<div style="text-align:center; padding:40px; color:var(--lib-text-muted);"><i class="fas fa-image fa-2x"></i><div style="margin-top:8px; font-size:0.85rem;">No gallery media</div></div>';
            return;
        }
        const first = currentGallery[0];
        const cover = first.type === 'video'
            ? '<video src="' + escapeHtml(first.src) + '" style="width:100%;height:220px;object-fit:cover;cursor:pointer;" onclick="openLibraryGallery(0)"></video>'
            : '<img src="' + escapeHtml(first.src) + '" alt="" style="width:100%;height:220px;object-fit:cover;cursor:pointer;" onclick="openLibraryGallery(0)">';
        box.innerHTML = '<div style="position:relative;">' + cover +
            '<div style="position:absolute;bottom:12px;right:12px;background:rgba(59,130,246,0.9);color:#fff;padding:6px 12px;border-radius:20px;font-size:0.75rem;font-weight:700;">' +
            '<i class="fas fa-images"></i> ' + currentGallery.length + ' Media</div></div>';
    }

    function openLibraryGallery(index) {
        currentGalleryIndex = index;
        document.getElementById('lib-lightbox').style.display = 'flex';
        renderLibrarySlide();
    }

    function closeLibraryGallery() {
        document.getElementById('lib-lightbox').style.display = 'none';
        document.getElementById('lib-lightbox-media').innerHTML = '';
    }

    function changeLibrarySlide(dir) {
        currentGalleryIndex += dir;
        if (currentGalleryIndex < 0) currentGalleryIndex = currentGallery.length - 1;
        if (currentGalleryIndex >= currentGallery.length) currentGalleryIndex = 0;
        renderLibrarySlide();
    }

    function renderLibrarySlide() {
        const media = currentGallery[currentGalleryIndex];
        const container = document.getElementById('lib-lightbox-media');
        if (media.type === 'video') {
            container.innerHTML = '<video src="' + escapeHtml(media.src) + '" controls autoplay style="max-width:90vw;max-height:80vh;border-radius:8px;"></video>';
        } else {
            container.innerHTML = '<img src="' + escapeHtml(media.src) + '" alt="" style="max-width:90vw;max-height:80vh;border-radius:8px;object-fit:contain;">';
        }
    }

    function openLibraryPricelist() {
        if (selectedProjectId) {
            window.open('print_pricelist.php?project_id=' + selectedProjectId, '_blank');
        }
    }

    function openLibraryPlans() {
        if (!currentProjectPlans.length) return;
        document.getElementById('libraryPlanIframe').src = currentProjectPlans[0];
        document.getElementById('libraryPlanModal').style.display = 'block';
    }

    function setLibraryFilter(mode) {
        libraryStatusFilter = mode;
        document.getElementById('libTabAll').className = mode === 'All' ? 'lib-tab active' : 'lib-tab';
        document.getElementById('libTabAvail').className = mode === 'Available' ? 'lib-tab active' : 'lib-tab';
        applyLibraryFilter();
    }

    function applyLibraryFilter() {
        document.querySelectorAll('#libraryUnits .unit-card').forEach(card => {
            const st = card.getAttribute('data-status') || '';
            let show = true;
            if (libraryStatusFilter === 'Available') {
                show = (st === 'Available' || st === 'BOM');
            }
            card.style.display = show ? '' : 'none';
        });
    }
</script>

<?php require_once 'footer.php'; ?>
