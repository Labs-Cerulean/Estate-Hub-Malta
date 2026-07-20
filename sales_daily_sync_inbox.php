<?php
require_once 'config.php';
require_once 'session-check.php';
require_once __DIR__ . '/includes/nav_config.php';

if (!navCanAccessSalesProjectManager()) {
    header('Location: index.php?error=unauthorized');
    exit;
}

$pageTitle = 'Daily Sync Inbox';
require_once 'header.php';

$defaultReportDate = (new DateTime('now', new DateTimeZone('Europe/Malta')))->format('Y-m-d');
?>

<style>
    :root {
        --pm-bg-base: #0f172a;
        --pm-bg-panel: #1e293b;
        --pm-border: #334155;
        --pm-border-light: rgba(255,255,255,0.1);
        --pm-text-main: #f8fafc;
        --pm-text-muted: #94a3b8;
        --pm-accent: #3b82f6;
        --pm-avail: #10b981;
        --pm-proc: #f59e0b;
        --pm-danger: #ef4444;
    }
    .manager-wrapper { background: var(--pm-bg-base); min-height: 100vh; padding: 20px 0 50px; }
    .manager-container { max-width: 1100px; margin: 0 auto; padding: 0 24px; color: var(--pm-text-main); }
    .header-bar {
        background: var(--pm-bg-panel); padding: 20px; border-radius: 12px; border: 1px solid var(--pm-border);
        margin-bottom: 20px; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 16px;
    }
    .header-bar h2 { margin: 0; color: #fff; }
    .pm-panel {
        background: var(--pm-bg-panel); border: 1px solid var(--pm-border); border-radius: 12px;
        padding: 20px; margin-bottom: 20px;
    }
    .pm-panel h3 { margin: 0 0 12px; font-size: 1rem; }
    .sync-inbox-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .sync-inbox-table th { text-align: left; padding: 10px; border-bottom: 1px solid var(--pm-border); color: var(--pm-text-muted); }
    .btn-heavy { padding: 10px 16px; border-radius: 8px; border: none; cursor: pointer; font-weight: 700; }
    .btn-green { background: var(--pm-avail); color: #fff; }
    .btn-blue { background: var(--pm-accent); color: #fff; }
    .schema-banner {
        display: none; background: rgba(239,68,68,0.15); border: 1px solid var(--pm-danger);
        padding: 14px; border-radius: 8px; margin-bottom: 16px; color: #fecaca;
    }
    .manager-container input[type="date"], .manager-container input[type="file"] {
        background: var(--pm-bg-base); color: var(--pm-text-main); border: 1px solid var(--pm-border);
        padding: 8px; border-radius: 6px;
    }
    #pm-toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 10000; }
    .pm-toast { padding: 12px 18px; border-radius: 8px; color: #fff; margin-top: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.4); }
</style>

<div class="manager-wrapper">
    <div class="manager-container">
        <div id="syncSchemaBanner" class="schema-banner">
            Database tables for the daily sync inbox are missing. Run
            <code>sql/2026-07-17_sales_daily_sync_inbox.sql</code> in phpMyAdmin, then refresh this page.
        </div>

        <div class="header-bar">
            <div>
                <h2><i class="fas fa-inbox"></i> Daily Sync Inbox</h2>
                <p style="margin:8px 0 0;color:var(--pm-text-muted);max-width:520px;">
                    Processed CSV reports from email ingest appear here with their report date.
                    Use <strong>Review &amp; Sync</strong> for the same approval flow as Sales Management.
                </p>
            </div>
            <div>
                <a href="sales_project_manager.php" class="btn-heavy btn-blue" style="text-decoration:none;display:inline-block;">
                    <i class="fas fa-arrow-left"></i> Sales Management
                </a>
            </div>
        </div>

        <div class="pm-panel">
            <h3><i class="fas fa-file-csv"></i> Pending &amp; recent reports</h3>
            <div style="overflow-x:auto;">
                <table class="sync-inbox-table">
                    <thead>
                        <tr>
                            <th>Report date</th>
                            <th>Received</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="syncInboxFilesBody">
                        <tr><td colspan="5" style="padding:24px;text-align:center;color:var(--pm-text-muted);">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pm-panel">
            <h3><i class="fas fa-history"></i> Sync run audit</h3>
            <p style="color:var(--pm-text-muted);font-size:0.85rem;margin:0 0 12px;">
                Each committed sync is recorded (e.g. “Maria ran sync #47”). Unit-level changes remain in property logs.
            </p>
            <div style="overflow-x:auto;">
                <table class="sync-inbox-table">
                    <thead>
                        <tr>
                            <th>Run</th>
                            <th>User</th>
                            <th>Started</th>
                            <th>Report date</th>
                            <th>Outcome</th>
                            <th>Counts</th>
                        </tr>
                    </thead>
                    <tbody id="syncInboxRunsBody">
                        <tr><td colspan="6" style="padding:16px;text-align:center;color:var(--pm-text-muted);">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pm-panel">
            <h3><i class="fas fa-upload"></i> Manual staging (until email ingest is live)</h3>
            <p style="color:var(--pm-text-muted);font-size:0.85rem;margin:0 0 12px;">
                Upload the same CSV you would use for 1-Click Daily Sync. Duplicates (same file hash) are rejected.
            </p>
            <form id="syncInboxUploadForm" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                <label>Report date<br>
                    <input type="date" name="report_date" id="syncReportDate" value="<?= htmlspecialchars($defaultReportDate, ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label>CSV file<br>
                    <input type="file" name="sync_csv" accept=".csv,text/csv" required>
                </label>
                <button type="submit" class="btn-heavy btn-green">Add to inbox</button>
            </form>
        </div>
    </div>
</div>

<div id="pm-toast-container"></div>

<script src="js/sales_pm_tools.js"></script>
<script src="js/sales_daily_sync_inbox.js"></script>

<?php require_once 'footer.php'; ?>
