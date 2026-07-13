<?php
/**
 * J2 ERP connectivity health — shared probe + short-lived session cache.
 * Used to fail-closed on billing/booking actions when ERP is unreachable.
 */

function j2ErpApiBase(): string
{
    return 'https://j2api.agiusgroup.com/api/public';
}

function j2ErpProbe(string $endpoint, string $apiKey): array
{
    $url = j2ErpApiBase() . $endpoint;
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
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = null;
    if ($response !== false && $response !== '') {
        $decoded = json_decode($response, true);
    }

    $ok = ($curlError === '' && $httpCode >= 200 && $httpCode < 300 && is_array($decoded));

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'curl_error' => $curlError !== '' ? $curlError : null,
        'decoded' => $decoded,
    ];
}

function j2ErpHasSessionContext(): bool
{
    return session_status() === PHP_SESSION_ACTIVE
        || (session_status() === PHP_SESSION_NONE && isset($_SESSION));
}

function j2ErpWriteHealthCache(array $health): void
{
    if (!j2ErpHasSessionContext()) {
        return;
    }

    $reopened = false;
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
        $reopened = true;
    }

    $_SESSION['j2_erp_health_cache'] = $health;

    if ($reopened) {
        session_write_close();
    }
}

function j2ErpGetHealth(bool $forceRefresh = false): array
{
    static $requestCache = null;
    static $requestCacheAt = 0;

    $ttl = 60;

    if (
        !$forceRefresh
        && is_array($requestCache)
        && (time() - $requestCacheAt) < $ttl
    ) {
        return $requestCache;
    }

    if (
        j2ErpHasSessionContext()
        && !$forceRefresh
        && isset($_SESSION['j2_erp_health_cache'])
        && is_array($_SESSION['j2_erp_health_cache'])
        && (time() - (int)($_SESSION['j2_erp_health_cache']['checked_at'] ?? 0)) < $ttl
    ) {
        $requestCache = $_SESSION['j2_erp_health_cache'];
        $requestCacheAt = time();
        return $requestCache;
    }

    $praKey = getenv('J2_API_KEY_PRA') ?: '';
    $praxKey = getenv('J2_API_KEY_PRAX') ?: '';

    if ($praKey === '' || $praxKey === '') {
        $health = [
            'available' => false,
            'reason' => 'keys_missing',
            'message' => 'ERP API keys are not configured. Billing and booking actions are disabled.',
            'http_code' => 0,
            'checked_at' => time(),
        ];
        j2ErpWriteHealthCache($health);
        $requestCache = $health;
        $requestCacheAt = time();
        return $health;
    }

    $probe = j2ErpProbe('/nominalcateg', $praKey);
    $hasData = is_array($probe['decoded']) && count($probe['decoded']) > 0;
    $available = $probe['ok'] && $hasData;

    if ($available) {
        $health = [
            'available' => true,
            'reason' => 'ok',
            'message' => 'ERP connection is healthy.',
            'http_code' => $probe['http_code'],
            'checked_at' => time(),
        ];
    } elseif (!empty($probe['curl_error'])) {
        $health = [
            'available' => false,
            'reason' => 'curl_error',
            'message' => 'Cannot reach the ERP API. System functionality is limited until the link is restored.',
            'http_code' => $probe['http_code'],
            'checked_at' => time(),
        ];
    } elseif (in_array($probe['http_code'], [401, 403, 407], true)) {
        $health = [
            'available' => false,
            'reason' => 'auth_or_ip',
            'message' => 'ERP rejected the connection (access denied). Billing and booking actions are disabled.',
            'http_code' => $probe['http_code'],
            'checked_at' => time(),
        ];
    } elseif ($probe['http_code'] >= 400) {
        $health = [
            'available' => false,
            'reason' => 'http_error',
            'message' => 'ERP returned an error. Billing and booking actions are disabled.',
            'http_code' => $probe['http_code'],
            'checked_at' => time(),
        ];
    } else {
        $health = [
            'available' => false,
            'reason' => 'empty_response',
            'message' => 'ERP returned no usable data. Billing and booking actions are disabled.',
            'http_code' => $probe['http_code'],
            'checked_at' => time(),
        ];
    }

    j2ErpWriteHealthCache($health);
    $requestCache = $health;
    $requestCacheAt = time();
    return $health;
}

function j2ErpIsAvailable(bool $forceRefresh = false): bool
{
    return j2ErpGetHealth($forceRefresh)['available'] === true;
}

function j2ErpGate(string $format = 'json'): void
{
    $health = j2ErpGetHealth();
    if ($health['available'] === true) {
        return;
    }

    if ($format === 'text') {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'ERROR: ERP_UNAVAILABLE — ' . $health['message'];
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => 'ERP_UNAVAILABLE',
        'message' => $health['message'],
        'reason' => $health['reason'],
    ]);
    exit;
}

function j2ErpUnavailablePayload(array $extra = []): array
{
    $health = j2ErpGetHealth();
    return array_merge([
        'error' => 'ERP_UNAVAILABLE',
        'message' => $health['message'],
        'reason' => $health['reason'],
    ], $extra);
}
