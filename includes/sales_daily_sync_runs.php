<?php
/**
 * Batch audit for daily CSV sync commits ("Maria ran sync #47").
 */

function salesDailySyncSchemaReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $pdo->query('SELECT 1 FROM sales_daily_sync_runs LIMIT 1');
        $pdo->query('SELECT 1 FROM sales_daily_sync_files LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function salesDailySyncStartRun(PDO $pdo, int $userId, ?int $syncFileId): ?int
{
    if (!salesDailySyncSchemaReady($pdo)) {
        return null;
    }
    if ($syncFileId !== null && $syncFileId > 0) {
        $chk = $pdo->prepare('SELECT id, file_status FROM sales_daily_sync_files WHERE id = ?');
        $chk->execute([$syncFileId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['file_status'] !== 'ready') {
            throw new InvalidArgumentException('Pending sync file is not available.');
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO sales_daily_sync_runs (sync_file_id, started_by_user_id, outcome) VALUES (?, ?, ?)'
    );
    $stmt->execute([$syncFileId > 0 ? $syncFileId : null, $userId, 'in_progress']);
    return (int)$pdo->lastInsertId();
}

/**
 * @param 'committed'|'failed'|'cancelled' $outcome
 */
function salesDailySyncFinishRun(
    PDO $pdo,
    ?int $runId,
    string $outcome,
    int $priceCount = 0,
    int $statusCount = 0,
    int $translationCount = 0,
    ?string $errorMessage = null
): void {
    if ($runId === null || $runId <= 0 || !salesDailySyncSchemaReady($pdo)) {
        return;
    }
    $allowed = ['committed', 'failed', 'cancelled'];
    if (!in_array($outcome, $allowed, true)) {
        $outcome = 'failed';
    }
    $err = $errorMessage !== null ? mb_substr($errorMessage, 0, 255, 'UTF-8') : null;
    $stmt = $pdo->prepare(
        'UPDATE sales_daily_sync_runs SET completed_at = NOW(), outcome = ?, price_updates_count = ?, status_updates_count = ?, translation_updates_count = ?, error_message = ? WHERE id = ?'
    );
    $stmt->execute([$outcome, $priceCount, $statusCount, $translationCount, $err, $runId]);
}

function salesDailySyncMarkFileApplied(PDO $pdo, int $syncFileId, int $runId): void
{
    if (!salesDailySyncSchemaReady($pdo)) {
        return;
    }
    $stmt = $pdo->prepare(
        'UPDATE sales_daily_sync_files SET file_status = ?, applied_run_id = ? WHERE id = ? AND file_status = ?'
    );
    $stmt->execute(['applied', $runId, $syncFileId, 'ready']);
}

function salesDailySyncRunLogPrefix(?int $runId): string
{
    return ($runId !== null && $runId > 0) ? ('Daily sync run #' . $runId . '. ') : '';
}

function salesDailySyncTruncateJustification(?int $runId, string $text): string
{
    return mb_substr(salesDailySyncRunLogPrefix($runId) . $text, 0, 255, 'UTF-8');
}
