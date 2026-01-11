<?php
$versionFile = __DIR__ . '/version.php';
if (file_exists($versionFile)) {
    $version = include $versionFile;
    $versionText = "{$version['version']} ({$version['branch']}@{$version['commit']})";
} else {
    $versionText = 'dev';
}
?>

<footer style="padding: 1rem; text-align: center; color: var(--text-secondary); font-size: 0.85rem; border-top: 1px solid var(--border-glass); margin-top: 2rem;">
    <div class="footer-content">
        <p style="margin: 0;">&copy; <?php echo date('Y'); ?> Estate Hub Malta. All rights reserved.</p>
        <p style="margin: 0.5rem 0 0 0; font-size: 0.75rem; opacity: 0.7;">
            Version: <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($versionText); ?></code>
        </p>
    </div>
</footer>
</body>
</html>
