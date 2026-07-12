<?php
/**
 * plant_erp_diagnostic.php — read-only J2 ERP connectivity check for staging/production.
 * Unlinked in nav; visit directly while logged in as admin/plant manager.
 */
require_once 'init.php';
require_once 'session-check.php';
require_once 'user-functions.php';

$role = $_SESSION['role'] ?? '';
$allowed = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager'], true)
    || (function_exists('hasPermission') && hasPermission('manage_plant_fleet'));

if (!$allowed) {
    die('Unauthorized. Admin or plant manager access required.');
}

date_default_timezone_set('Europe/Malta');

function diagProbeJ2(string $endpoint, string $apiKey): array
{
    $url = 'https://j2api.agiusgroup.com/api/public' . $endpoint;
    $started = microtime(true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-key: ' . $apiKey,
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = round((microtime(true) - $started) * 1000);
    curl_close($ch);

    $decoded = null;
    $itemCount = null;
    if ($response !== false && $response !== '') {
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $itemCount = count($decoded);
        }
    }

    $preview = '';
    if (is_string($response) && $response !== '') {
        $preview = substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $response), 0, 280);
    }

    return [
        'endpoint' => $endpoint,
        'http_code' => $httpCode,
        'curl_error' => $curlErr !== '' ? $curlErr : null,
        'elapsed_ms' => $totalTime,
        'response_bytes' => is_string($response) ? strlen($response) : 0,
        'item_count' => $itemCount,
        'ok' => ($httpCode >= 200 && $httpCode < 300 && $curlErr === ''),
        'preview' => $preview,
        'likely_ip_block' => in_array($httpCode, [401, 403, 407], true),
    ];
}

function diagOutboundIp(): array
{
    $ch = curl_init('https://api.ipify.org?format=json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ip = null;
    if ($body) {
        $json = json_decode($body, true);
        $ip = is_array($json) ? trim((string)($json['ip'] ?? '')) : null;
    }

    return [
        'ip' => $ip ?: null,
        'http_code' => $code,
        'error' => $err !== '' ? $err : null,
    ];
}

$praKey = getenv('J2_API_KEY_PRA') ?: '';
$praxKey = getenv('J2_API_KEY_PRAX') ?: '';
$run = isset($_GET['run']) && $_GET['run'] === '1';

$outbound = $run ? diagOutboundIp() : null;
$tests = [];

if ($run) {
    if ($praKey !== '') {
        $tests[] = ['label' => 'PRA (company 24) — /clients', 'result' => diagProbeJ2('/clients', $praKey)];
        $tests[] = ['label' => 'PRA (company 24) — /nominalcateg', 'result' => diagProbeJ2('/nominalcateg', $praKey)];
    }
    if ($praxKey !== '') {
        $tests[] = ['label' => 'PRAX (company 26) — /clients', 'result' => diagProbeJ2('/clients', $praxKey)];
        $tests[] = ['label' => 'PRAX (company 26) — /nominalcateg', 'result' => diagProbeJ2('/nominalcateg', $praxKey)];
    }
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function statusClass(array $r): string
{
    if ($r['ok'] && ($r['item_count'] === null || $r['item_count'] > 0)) {
        return 'ok';
    }
    if ($r['ok'] && $r['item_count'] === 0) {
        return 'warn';
    }
    return 'fail';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plant ERP Diagnostic</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: Inter, system-ui, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 32px 20px; }
        .wrap { max-width: 960px; margin: 0 auto; }
        h1 { margin: 0 0 8px; font-size: 1.6rem; }
        .sub { color: #64748b; margin-bottom: 24px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 18px; }
        .card h2 { margin: 0 0 12px; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.04em; color: #475569; }
        .btn { display: inline-flex; align-items: center; gap: 8px; background: #2563eb; color: #fff; border: none; border-radius: 8px; padding: 12px 18px; font-weight: 700; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #1d4ed8; }
        table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: 10px 8px; text-align: left; vertical-align: top; }
        th { font-size: 0.75rem; text-transform: uppercase; color: #64748b; }
        .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; }
        .pill.ok { background: #dcfce7; color: #166534; }
        .pill.warn { background: #fef3c7; color: #b45309; }
        .pill.fail { background: #fee2e2; color: #b91c1c; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.85rem; word-break: break-all; }
        .note { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 14px; border-radius: 8px; font-size: 0.9rem; line-height: 1.5; }
        .note.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
        ul { margin: 8px 0 0 18px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1><i class="fas fa-stethoscope"></i> Plant ERP Diagnostic</h1>
    <p class="sub">Read-only connectivity test for J2 API (client search uses <code>/clients</code>). Run while logged in on the environment you want to check.</p>

    <div class="card">
        <h2>Environment</h2>
        <table>
            <tr><th>Checked at</th><td><?= h(date('d M Y H:i:s T')) ?></td></tr>
            <tr><th>Server</th><td><?= h($_SERVER['HTTP_HOST'] ?? 'unknown') ?></td></tr>
            <tr><th>PRA key configured</th><td><?= $praKey !== '' ? 'Yes' : '<b style="color:#b91c1c">No</b>' ?></td></tr>
            <tr><th>PRAX key configured</th><td><?= $praxKey !== '' ? 'Yes' : '<b style="color:#b91c1c">No</b>' ?></td></tr>
        </table>
        <p style="margin-top:16px;">
            <a class="btn" href="?run=1"><i class="fas fa-play"></i> Run ERP connectivity test</a>
        </p>
    </div>

    <?php if ($run): ?>
    <div class="card">
        <h2>Outbound IP (this request)</h2>
        <?php if ($outbound && $outbound['ip']): ?>
            <p style="font-size:1.4rem; font-weight:900; margin:0;"><?= h($outbound['ip']) ?></p>
            <p class="sub" style="margin-top:8px;">Ensure this IP is whitelisted on the J2 API side. Staging with multiple egress IPs may fail intermittently if only some are whitelisted.</p>
        <?php else: ?>
            <p style="color:#b91c1c;">Could not detect outbound IP<?= $outbound && $outbound['error'] ? ': ' . h($outbound['error']) : '' ?>.</p>
        <?php endif; ?>
    </div>

    <?php if (empty($tests)): ?>
        <div class="card note warn">No API keys found in environment — cannot probe ERP.</div>
    <?php else: ?>
        <div class="card">
            <h2>API probe results</h2>
            <table>
                <thead>
                    <tr>
                        <th>Test</th>
                        <th>Status</th>
                        <th>HTTP</th>
                        <th>Items</th>
                        <th>Time</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tests as $t):
                    $r = $t['result'];
                    $cls = statusClass($r);
                ?>
                    <tr>
                        <td><?= h($t['label']) ?></td>
                        <td><span class="pill <?= h($cls) ?>"><?= $cls === 'ok' ? 'OK' : ($cls === 'warn' ? 'Empty' : 'Fail') ?></span></td>
                        <td><?= (int)$r['http_code'] ?></td>
                        <td><?= $r['item_count'] === null ? '—' : (int)$r['item_count'] ?></td>
                        <td><?= (int)$r['elapsed_ms'] ?> ms</td>
                        <td class="mono">
                            <?php if ($r['curl_error']): ?>cURL: <?= h($r['curl_error']) ?><br><?php endif; ?>
                            <?php if ($r['likely_ip_block']): ?><b style="color:#b45309;">Possible IP whitelist block (<?= (int)$r['http_code'] ?>)</b><br><?php endif; ?>
                            bytes=<?= (int)$r['response_bytes'] ?>
                            <?php if ($r['preview'] !== ''): ?><br><?= h($r['preview']) ?><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $clientsEmpty = false;
        $clientsBlocked = false;
        foreach ($tests as $t) {
            if (strpos($t['label'], '/clients') !== false) {
                if ($t['result']['likely_ip_block']) $clientsBlocked = true;
                if ($t['result']['ok'] && ($t['result']['item_count'] ?? 0) === 0) $clientsEmpty = true;
                if (!$t['result']['ok']) $clientsBlocked = true;
            }
        }
        ?>
        <div class="card note <?= ($clientsBlocked || $clientsEmpty) ? 'warn' : '' ?>">
            <b>How this relates to “No client found” in Add Booking</b>
            <ul>
                <li>Booking client search calls <code>api/plant_actions.php?action=get_company_clients</code>, which hits J2 <code>/clients</code>.</li>
                <li>If the HTTP call fails (403 IP block, timeout, bad key), our code silently returns an <b>empty array</b> — the UI then shows “No client found” even though the real issue is connectivity.</li>
                <li>If <code>/clients</code> returns HTTP 200 with <b>0 items</b> above, that matches the booking form symptom exactly.</li>
                <li><b>IP whitelisting:</b> if staging egresses from 3 static IPs and only 2 are whitelisted, you can see intermittent or persistent failures depending on which IP handles each request. Production (all 3 whitelisted) would work reliably.</li>
            </ul>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
