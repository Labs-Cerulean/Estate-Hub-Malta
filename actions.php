<?php
require_once 'init.php';
require_once 'session-check.php';

$message = '';
$userId = getCurrentUserId();

// Handle action completion toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $logId = (int)($_POST['log_id'] ?? 0);
    
    if ($action === 'complete' && $logId) {
        if (completeAction($pdo, $userId, $logId)) {
            $message = 'Action marked as complete!';
        } else {
            $message = 'Error updating action.';
        }
    }
    
    if ($action === 'uncomplete' && $logId) {
        if (uncompleteAction($pdo, $userId, $logId)) {
            $message = 'Action marked as incomplete!';
        } else {
            $message = 'Error updating action.';
        }
    }
    
    // Redirect to prevent form resubmission
    header('Location: actions.php' . ($message ? '?msg=' . urlencode($message) : ''));
    exit;
}

// Get message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Get filter
$showCompleted = isset($_GET['completed']) && $_GET['completed'] === '1';

// Get all actions
$actions = getUserActions($pdo, $userId, $showCompleted);

// Separate incomplete and complete
$incompleteActions = array_filter($actions, fn($a) => $a['is_complete'] === 'No');
$completeActions = array_filter($actions, fn($a) => $a['is_complete'] === 'Yes');

$pageTitle = 'Actions';
require_once 'header.php';
?>

<div class="main-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h1 class="page-title">My Actions</h1>
        <a href="actions.php?completed=<?= $showCompleted ? '0' : '1' ?>" 
           class="btn btn-sm">
            <?= $showCompleted ? 'Hide Completed' : 'Show Completed' ?>
        </a>
    </div>

    <?php if ($message): ?>
        <div class="message success" style="margin-bottom: 1.5rem;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Incomplete Actions -->
    <div class="section-card" style="margin-bottom: 2rem;">
        <div class="section-header">
            <h2 style="margin: 0;">Active Actions (<?= count($incompleteActions) ?>)</h2>
        </div>
        
        <?php if (empty($incompleteActions)): ?>
            <div style="text-align: center; padding: 2rem; color: #9CA3AF;">
                <p>No active actions. Great job!</p>
            </div>
        <?php else: ?>
            <div class="actions-list">
                <?php foreach ($incompleteActions as $action): ?>
                    <?php
                    $timestamp = date('d M Y, H:i', strtotime($action['log_created_at']));
                    $userName = trim($action['first_name'] . ' ' . $action['last_name']) ?: $action['username'];
                    ?>
                    
                    <div class="action-item">
                        <div class="action-checkbox">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="log_id" value="<?= $action['log_id'] ?>">
                                <button type="submit" class="checkbox-btn" title="Mark as complete">
                                    <span class="checkbox-empty"></span>
                                </button>
                            </form>
                        </div>
                        
                        <div class="action-content">
                            <div class="action-header">
                                <span class="action-user"><?= htmlspecialchars($userName) ?></span>
                                <span class="action-separator">•</span>
                                <span class="action-timestamp"><?= $timestamp ?></span>
                            </div>
                            
                            <div class="action-project">
                                <a href="mobilisation_detail.php?project_id=<?= $action['projectid'] ?>#project-log">
                                    <?= htmlspecialchars($action['project_name']) ?>
                                </a>
                                <?php if ($action['client_name']): ?>
                                    <span style="color: #9CA3AF; margin-left: 0.5rem;">
                                        (<?= htmlspecialchars($action['client_name']) ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="action-message">
                                <?= htmlspecialchars($action['message']) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Completed Actions -->
    <?php if ($showCompleted && !empty($completeActions)): ?>
        <div class="section-card">
            <div class="section-header">
                <h2 style="margin: 0;">Completed Actions (<?= count($completeActions) ?>)</h2>
            </div>
            
            <div class="actions-list">
                <?php foreach ($completeActions as $action): ?>
                    <?php
                    $timestamp = date('d M Y, H:i', strtotime($action['log_created_at']));
                    $completedTime = date('d M Y, H:i', strtotime($action['completed_at']));
                    $userName = trim($action['first_name'] . ' ' . $action['last_name']) ?: $action['username'];
                    ?>
                    
                    <div class="action-item completed">
                        <div class="action-checkbox">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="uncomplete">
                                <input type="hidden" name="log_id" value="<?= $action['log_id'] ?>">
                                <button type="submit" class="checkbox-btn" title="Mark as incomplete">
                                    <span class="checkbox-checked">✓</span>
                                </button>
                            </form>
                        </div>
                        
                        <div class="action-content">
                            <div class="action-header">
                                <span class="action-user"><?= htmlspecialchars($userName) ?></span>
                                <span class="action-separator">•</span>
                                <span class="action-timestamp"><?= $timestamp ?></span>
                                <span class="completed-badge">Completed <?= $completedTime ?></span>
                            </div>
                            
                            <div class="action-project">
                                <a href="mobilisation_detail.php?project_id=<?= $action['projectid'] ?>#project-log">
                                    <?= htmlspecialchars($action['project_name']) ?>
                                </a>
                                <?php if ($action['client_name']): ?>
                                    <span style="color: #9CA3AF; margin-left: 0.5rem;">
                                        (<?= htmlspecialchars($action['client_name']) ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="action-message">
                                <?= htmlspecialchars($action['message']) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.actions-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.action-item {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    background: white;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    transition: all 0.2s;
}

.action-item:hover {
    background: #F9FAFB;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.action-item.completed {
    opacity: 0.6;
}

.action-item.completed .action-message {
    text-decoration: line-through;
    color: #9CA3AF;
}

.action-checkbox {
    flex-shrink: 0;
}

.checkbox-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
}

.checkbox-empty {
    display: block;
    width: 24px;
    height: 24px;
    border: 2px solid #D1D5DB;
    border-radius: 6px;
    transition: all 0.2s;
}

.checkbox-btn:hover .checkbox-empty {
    border-color: #6366F1;
    background: #F0F9FF;
}

.checkbox-checked {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: #10B981;
    color: white;
    border-radius: 6px;
    font-weight: 700;
    font-size: 1rem;
}

.action-content {
    flex: 1;
}

.action-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
    font-size: 0.85rem;
}

.action-user {
    font-weight: 700;
    color: #374151;
}

.action-separator {
    color: #D1D5DB;
}

.action-timestamp {
    color: #9CA3AF;
}

.completed-badge {
    background: #10B981;
    color: white;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-left: auto;
}

.action-project {
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.action-project a {
    color: #6366F1;
    text-decoration: none;
    font-weight: 600;
}

.action-project a:hover {
    text-decoration: underline;
}

.action-message {
    color: #374151;
    line-height: 1.6;
}
</style>

<?php require_once 'footer.php'; ?>
