<?php
/**
 * Shared project header partial for PM pages.
 * Expects: $project (array with name, client_name, stage, finishlevel, type), optional $scheduleRag
 */
if (empty($project)) return;
$typeLabel = ($project['type'] ?? '') === 'in-house' ? 'In-House' : 'Capital / 3rd-Party';
?>
<div class="project-header-bar" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;padding:1rem 1.25rem;background:var(--bg-card);border:1px solid var(--border-glass);border-radius:10px;border-left:4px solid var(--primary-color);">
    <div>
        <h2 style="margin:0 0 0.35rem;font-size:1.35rem;"><?= htmlspecialchars($project['name']) ?></h2>
        <div style="display:flex;flex-wrap:wrap;gap:8px;font-size:0.8rem;">
            <span class="info-tag"><?= htmlspecialchars($project['client_name'] ?? 'No Client') ?></span>
            <span class="info-tag"><?= $typeLabel ?></span>
            <?php if (!empty($project['stage'])): ?><span class="info-tag"><?= htmlspecialchars($project['stage']) ?></span><?php endif; ?>
            <?php if (!empty($project['finishlevel'])): ?><span class="info-tag">Fin: <?= htmlspecialchars($project['finishlevel']) ?></span><?php endif; ?>
        </div>
    </div>
    <?php if (!empty($headerLinks) && is_array($headerLinks)): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php foreach ($headerLinks as $link): ?>
            <a href="<?= htmlspecialchars($link['href']) ?>" class="btn btn-sm btn-secondary"><?= htmlspecialchars($link['label']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
