<?php
require_once 'init.php';
require_once 'session-check.php';

$userId = getCurrentUserId();
$message = '';
$error = '';

// Handle "Mark as Complete"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_task') {
    try {
        $logId = $_POST['log_id'];
        
        // Ensure they can only close tasks assigned to them (or if they are admin)
        $authCheck = isAdmin() ? "" : "AND assigned_to = ?";
        $params = isAdmin() ? [getCurrentUserId(), $logId] : [getCurrentUserId(), $logId, $userId];
        
        $stmt = $pdo->prepare("UPDATE project_logs SET status = 'Action - Closed', closed_at = NOW(), closed_by = ? WHERE id = ? $authCheck");
        $stmt->execute($params);
        
        $message = "Action marked as complete!";
    } catch (Exception $e) {
        $error = "Error updating action: " . $e->getMessage();
    }
}

// Fetch Pending Actions assigned to the user
$pendingStmt = $pdo->prepare("
    SELECT pl.*, p.name as project_name, u.username as assigner_username
    FROM project_logs pl
    JOIN projects p ON pl.project_id = p.id
    JOIN users u ON pl.user_id = u.id
    WHERE pl.assigned_to = ? AND pl.status = 'Action - Pending'
    ORDER BY pl.created_at DESC
");
$pendingStmt->execute([$userId]);
$pendingActions = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Recently Closed Actions (Limit to 20 for history)
$closedStmt = $pdo->prepare("
    SELECT pl.*, p.name as project_name, u.username as assigner_username, cu.username as closer_username
    FROM project_logs pl
    JOIN projects p ON pl.project_id = p.id
    JOIN users u ON pl.user_id = u.id
    LEFT JOIN users cu ON pl.closed_by = cu.id
    WHERE pl.assigned_to = ? AND pl.status = 'Action - Closed'
    ORDER BY pl.closed_at DESC
    LIMIT 20
");
$closedStmt->execute([$userId]);
$closedActions = $closedStmt->fetchAll(PDO::FETCH_ASSOC);

function getUserColor($username) {
    if (!$username) return '#6B7280';
    $colors = ['#6366F1', '#8B5CF6', '#EC4899', '#10B981', '#F59E0B', '#3B82F6', '#EF4444', '#14B8A6', '#F97316', '#06B6D4'];
    return $colors[abs(crc32($username)) % count($colors)];
}

$pageTitle = 'My Assigned Actions';
require_once 'header.php';
?>

<div class="main-container" style="max-width: 1000px;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">My Actions</h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">Tasks and directives assigned to you across all projects.</p>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <h3 style="color: #F59E0B; margin-bottom: 1rem; border-bottom: 2px solid rgba(245, 158, 11, 0.3); padding-bottom: 0.5rem;">
        ⏳ Pending Actions (<?= count($pendingActions) ?>)
    </h3>
    
    <div style="margin-bottom: 3rem;">
        <?php if (empty($pendingActions)): ?>
            <div style="text-align: center; padding: 3rem; background: var(--bg-card); border-radius: 8px; border: 1px dashed var(--border-glass);">
                <p style="color: var(--text-muted); font-size: 1.1rem;">You're all caught up! No pending actions.</p>
            </div>
        <?php else: ?>
            <?php foreach ($pendingActions as $task): ?>
                <div class="card" style="margin-bottom: 1rem; border-left: 4px solid #F59E0B; display: flex; flex-direction: column; gap: 0.75rem;">
                    
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <div style="font-weight: 700; color: var(--primary-color); font-size: 1.1rem; margin-bottom: 0.25rem;">
                                <?= htmlspecialchars($task['project_name']) ?>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                Assigned by <strong style="color: <?= getUserColor($task['assigner_username']) ?>;">@<?= htmlspecialchars($task['assigner_username']) ?></strong> 
                                on <?= date('d M Y, H:i', strtotime($task['created_at'])) ?>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 0.5rem;">
                            <a href="mobilisation_detail.php?project_id=<?= $task['project_id'] ?>#project-log" class="btn btn-sm btn-secondary" style="margin: 0; padding: 0.4rem 0.8rem;">View Project</a>
                            
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="action" value="close_task">
                                <input type="hidden" name="log_id" value="<?= $task['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="margin: 0; padding: 0.4rem 0.8rem; background: #10B981; color: white; border: none; display: flex; align-items: center; gap: 0.3rem;">
                                    <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    Mark Complete
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div style="background: rgba(255,255,255,0.03); padding: 1rem; border-radius: 6px; color: var(--text-primary); font-size: 0.95rem; border: 1px solid var(--border-glass);">
                        <?= nl2br(htmlspecialchars($task['message'])) ?>
                    </div>
                    
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <h3 style="color: #10B981; margin-bottom: 1rem; border-bottom: 2px solid rgba(16, 185, 129, 0.3); padding-bottom: 0.5rem;">
        ✅ Recently Completed (Last 20)
    </h3>
    
    <div>
        <?php if (empty($closedActions)): ?>
            <p style="color: var(--text-muted);">No recently closed actions.</p>
        <?php else: ?>
            <?php foreach ($closedActions as $task): ?>
                <div style="padding: 1rem; background: var(--bg-card); margin-bottom: 0.75rem; border-radius: 8px; border-left: 4px solid #10B981; opacity: 0.75;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                        <div>
                            <div style="font-weight: 600; color: var(--text-primary);">
                                <?= htmlspecialchars($task['project_name']) ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                Assigned by @<?= htmlspecialchars($task['assigner_username']) ?> | 
                                Closed on <?= date('d M Y, H:i', strtotime($task['closed_at'])) ?>
                            </div>
                        </div>
                        <a href="mobilisation_detail.php?project_id=<?= $task['project_id'] ?>#project-log" class="btn btn-sm btn-secondary" style="margin: 0; padding: 0.2rem 0.5rem; font-size: 0.75rem;">View</a>
                    </div>
                    <div style="font-size: 0.85rem; color: var(--text-secondary);">
                        <strike><?= nl2br(htmlspecialchars($task['message'])) ?></strike>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php require_once 'footer.php'; ?>
