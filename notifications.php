<?php
require_once 'init.php';
require_once 'session-check.php';

$message = '';
$userId = getCurrentUserId();

// Handle AJAX requests for marking read/unread
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $logId = (int)($_POST['log_id'] ?? 0);
    
    if ($action === 'mark_read' && $logId) {
        $success = markNotificationRead($pdo, $userId, $logId);
        echo json_encode(['success' => $success]);
        exit;
    }
    
    if ($action === 'mark_unread' && $logId) {
        $success = markNotificationUnread($pdo, $userId, $logId);
        echo json_encode(['success' => $success]);
        exit;
    }
    
    if ($action === 'mark_action' && $logId) {
        $success = markAsAction($pdo, $userId, $logId);
        echo json_encode(['success' => $success]);
        exit;
    }
    
    echo json_encode(['success' => false]);
    exit;
}

// Get filter
$showUnreadOnly = isset($_GET['unread']) && $_GET['unread'] === '1';

// Get all notifications
$notifications = getUserNotifications($pdo, $userId, $showUnreadOnly);
$unreadCount = getUnreadNotificationCount($pdo, $userId);

$pageTitle = 'Notifications';
require_once 'header.php';
?>

<div class="main-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h1 class="page-title">Notifications</h1>
        <div style="display: flex; gap: 1rem; align-items: center;">
            <span style="color: #6B7280; font-size: 0.9rem;">
                <?= $unreadCount ?> unread
            </span>
            <a href="notifications.php<?= $showUnreadOnly ? '' : '?unread=1' ?>" 
               class="btn btn-sm"
               style="<?= $showUnreadOnly ? 'background: #6366F1; color: white;' : '' ?>">
                <?= $showUnreadOnly ? 'Show All' : 'Show Unread Only' ?>
            </a>
        </div>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="card" style="text-align: center; padding: 3rem;">
            <p style="color: #9CA3AF; font-size: 1rem;">No notifications to display.</p>
        </div>
    <?php else: ?>
        <div class="notifications-list">
            <?php foreach ($notifications as $notif): ?>
                <?php
                $isRead = (bool)$notif['is_read'];
                $isAction = (bool)$notif['is_action'];
                $timestamp = date('d M Y, H:i', strtotime($notif['created_at']));
                // FIX: Use first_name and last_name with underscores
                $userName = trim($notif['first_name'] . ' ' . $notif['last_name']) ?: $notif['username'];
                ?>
                
                <div class="notification-item <?= $isRead ? 'read' : 'unread' ?>" 
                     data-log-id="<?= $notif['id'] ?>">
                    <div class="notification-header">
                        <div class="notification-meta">
                            <span class="notification-user"><?= htmlspecialchars($userName) ?></span>
                            <span class="notification-separator">•</span>
                            <span class="notification-timestamp"><?= $timestamp ?></span>
                            <?php if ($isAction): ?>
                                <span class="action-badge">Action</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isRead): ?>
                            <span class="unread-indicator"></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-project">
                        <a href="mobilisation_detail.php?project_id=<?= $notif['project_id'] ?>#project-log" 
                           style="color: #6366F1; text-decoration: none; font-weight: 600;">
                            <?= htmlspecialchars($notif['project_name']) ?>
                        </a>
                        <?php if ($notif['client_name']): ?>
                            <span style="color: #9CA3AF; margin-left: 0.5rem;">
                                (<?= htmlspecialchars($notif['client_name']) ?>)
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-message" onclick="toggleRead(this, <?= $notif['id'] ?>, <?= $isRead ? 'true' : 'false' ?>)">
                        <?= htmlspecialchars($notif['message']) ?>
                    </div>
                    
                    <div class="notification-actions">
                        <button class="btn-text mark-toggle" 
                                onclick="toggleRead(this, <?= $notif['id'] ?>, <?= $isRead ? 'true' : 'false' ?>)">
                            <?= $isRead ? '✓ Mark as unread' : '✓ Mark as read' ?>
                        </button>
                        <?php if (!$isAction): ?>
                            <button class="btn-text" 
                                    onclick="markAsAction(<?= $notif['id'] ?>)">
                                ⚡ Mark as action
                            </button>
                        <?php endif; ?>
                        <a href="mobilisation_detail.php?project_id=<?= $notif['project_id'] ?>#project-log" 
                           class="btn-text">
                            → View project
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.notification-item {
    background: white;
    border: 1px solid #E5E7EB;
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.2s;
}

.notification-item.unread {
    background: #F0F9FF;
    border-left: 4px solid #6366F1;
}

.notification-item:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.notification-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.notification-meta {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
}

.notification-user {
    font-weight: 700;
    color: #374151;
}

.notification-separator {
    color: #D1D5DB;
}

.notification-timestamp {
    color: #9CA3AF;
}

.unread-indicator {
    width: 10px;
    height: 10px;
    background: #6366F1;
    border-radius: 50%;
}

.notification-project {
    margin-bottom: 0.75rem;
    font-size: 0.9rem;
}

.notification-message {
    color: #374151;
    line-height: 1.6;
    margin-bottom: 1rem;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 6px;
    transition: background 0.2s;
}

.notification-message:hover {
    background: #F9FAFB;
}

.notification-actions {
    display: flex;
    gap: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid #E5E7EB;
}

.btn-text {
    background: none;
    border: none;
    color: #6366F1;
    font-size: 0.85rem;
    cursor: pointer;
    padding: 0.25rem 0.5rem;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-text:hover {
    color: #4F46E5;
    text-decoration: underline;
}

.action-badge {
    background: #10B981;
    color: white;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
}
</style>

<script>
function toggleRead(element, logId, isCurrentlyRead) {
    const action = isCurrentlyRead ? 'mark_unread' : 'mark_read';
    
    fetch('notifications.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=${action}&log_id=${logId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

function markAsAction(logId) {
    fetch('notifications.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=mark_action&log_id=${logId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Notification marked as action!');
            location.reload();
        }
    });
}
</script>

<?php require_once 'footer.php'; ?>
