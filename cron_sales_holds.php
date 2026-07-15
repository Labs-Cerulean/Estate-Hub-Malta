<?php
/**
 * cron_sales_holds.php — Sales Hub hold expiry alerts (Stage E)
 *
 * - Sends 24h warning emails to holding agents
 * - Sends expired-hold alerts to agents + sales managers
 * - Does NOT auto-release holds (manual manager release required)
 *
 * Schedule: hourly recommended. Protect with CRON_SECRET_TOKEN.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/user-functions.php';
require_once __DIR__ . '/email_helper.php';

$providedToken = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
$expectedToken = getenv('CRON_SECRET_TOKEN');

if (empty($expectedToken) || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    die('Unauthorized: Invalid or missing Cron Token.');
}

date_default_timezone_set('Europe/Malta');
$alertColumnsReady = salesHoldAlertColumnsAvailable($pdo);
$managerEmails = salesGetHoldAlertManagerEmails($pdo);

echo 'Sales holds alert run at ' . date('Y-m-d H:i:s') . "\n";

// --- 24h expiry warnings ---
$warningSql = "
    SELECT sp.id, sp.unit_name, sp.hold_expiry, sp.held_by_agent_id,
           p.name AS project_name,
           u.email, u.first_name
    FROM sales_properties sp
    JOIN projects p ON sp.project_id = p.id
    JOIN users u ON sp.held_by_agent_id = u.id
    WHERE sp.status = 'On Hold'
      AND sp.hold_expiry IS NOT NULL
      AND sp.hold_expiry > NOW()
      AND sp.hold_expiry <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
";
if ($alertColumnsReady) {
    $warningSql .= " AND sp.hold_warning_sent_at IS NULL";
}
$warnings = $pdo->query($warningSql)->fetchAll(PDO::FETCH_ASSOC);

foreach ($warnings as $alert) {
    $agentEmail = filter_var($alert['email'], FILTER_VALIDATE_EMAIL);
    if (!$agentEmail) {
        continue;
    }

    $expiryLabel = date('d M Y H:i', strtotime($alert['hold_expiry']));
    $subject = "Hold expiring soon: {$alert['unit_name']} ({$alert['project_name']})";
    $htmlBody = '<p>Hi ' . htmlspecialchars($alert['first_name']) . ',</p>'
        . '<p>Your hold on <strong>' . htmlspecialchars($alert['unit_name']) . '</strong>'
        . ' at <strong>' . htmlspecialchars($alert['project_name']) . '</strong>'
        . ' expires at <strong>' . htmlspecialchars($expiryLabel) . '</strong> (Europe/Malta).</p>'
        . '<p>Please request an extension with justification or ask a sales manager to adjust the deadline. Holds are not released automatically when they expire.</p>'
        . '<p>Regards,<br>Estate Hub Malta</p>';

    $sent = sendSystemEmail($agentEmail, $subject, $htmlBody);
    if ($sent === true) {
        if ($alertColumnsReady) {
            $upd = $pdo->prepare('UPDATE sales_properties SET hold_warning_sent_at = NOW() WHERE id = ?');
            $upd->execute([(int)$alert['id']]);
        }
        echo "Warning sent: property {$alert['id']}\n";
    }
}

// --- Expired holds: alert only, no auto-release ---
$expiredSql = "
    SELECT sp.id, sp.unit_name, sp.hold_expiry, sp.held_by_agent_id,
           p.name AS project_name,
           u.email, u.first_name
    FROM sales_properties sp
    JOIN projects p ON sp.project_id = p.id
    LEFT JOIN users u ON sp.held_by_agent_id = u.id
    WHERE sp.status = 'On Hold'
      AND sp.hold_expiry IS NOT NULL
      AND sp.hold_expiry <= NOW()
";
if ($alertColumnsReady) {
    $expiredSql .= " AND sp.hold_expired_alert_sent_at IS NULL";
}
$expired = $pdo->query($expiredSql)->fetchAll(PDO::FETCH_ASSOC);

foreach ($expired as $hold) {
    $recipients = $managerEmails;
    $agentEmail = filter_var($hold['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if ($agentEmail) {
        $recipients[] = $agentEmail;
    }
    $recipients = array_values(array_unique($recipients));
    if (empty($recipients)) {
        continue;
    }

    $expiryLabel = date('d M Y H:i', strtotime($hold['hold_expiry']));
    $subject = "EXPIRED hold: {$hold['unit_name']} ({$hold['project_name']})";
    $htmlBody = '<p>A hold has passed its deadline and still requires manual action.</p>'
        . '<ul>'
        . '<li><strong>Project:</strong> ' . htmlspecialchars($hold['project_name']) . '</li>'
        . '<li><strong>Unit:</strong> ' . htmlspecialchars($hold['unit_name']) . '</li>'
        . '<li><strong>Deadline:</strong> ' . htmlspecialchars($expiryLabel) . ' (Europe/Malta)</li>'
        . '<li><strong>Agent:</strong> ' . htmlspecialchars(trim(($hold['first_name'] ?? '') . ' ')) . '</li>'
        . '</ul>'
        . '<p>The unit remains <strong>On Hold</strong> until a manager releases it or updates the deadline in the Holds Ledger.</p>';

    if (sendSystemEmail($recipients, $subject, $htmlBody)) {
        if ($alertColumnsReady) {
            $upd = $pdo->prepare('UPDATE sales_properties SET hold_expired_alert_sent_at = NOW() WHERE id = ?');
            $upd->execute([(int)$hold['id']]);
        }

        $log = $pdo->prepare('INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, 0, ?, ?, ?, ?)');
        $log->execute([
            (int)$hold['id'],
            'Hold Expired Alert Sent',
            'On Hold',
            'On Hold',
            'Hold passed deadline — manual release or new deadline required.',
        ]);
        echo "Expired alert sent: property {$hold['id']}\n";
    }
}

echo "Done.\n";
