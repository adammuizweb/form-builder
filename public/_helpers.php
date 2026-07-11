<?php
// /plugins/form-builder/public/_helpers.php
declare(strict_types=1);

function fb_public_ctx(PDO $pdo): array {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_string($ip) && strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $token = hash_hmac('sha256', session_id() . 'fb', fb_get_secret($pdo));
    return ['csrf' => $token, 'ip' => (string)$ip];
}

function fb_csrf_check(string $token, string $sent): bool {
    return $sent !== '' && hash_equals($token, trim($sent));
}

function fb_rate_limit_check(PDO $pdo, string $ip, string $action, int $windowSeconds, int $max): bool {
    if ($ip === '') return true;
    $bucket = intdiv(time(), max(1, $windowSeconds));
    try {
        $st = $pdo->prepare("
            INSERT INTO `fb_rate_limits` (`ip`, `action`, `bucket`, `count`)
            VALUES (:ip, :action, :bucket, 1)
            ON DUPLICATE KEY UPDATE count = count + 1
        ");
        $st->execute([':ip' => $ip, ':action' => $action, ':bucket' => $bucket]);
        $q = $pdo->prepare("SELECT count FROM `fb_rate_limits` WHERE ip = ? AND action = ? AND bucket = ? LIMIT 1");
        $q->execute([$ip, $action, $bucket]);
        return ((int)($q->fetchColumn() ?: 0)) <= $max;
    } catch (Throwable $e) {
        return true;
    }
}

function fb_safe_return_url(string $raw): string {
    $raw = trim($raw);
    if ($raw === '' || !str_starts_with($raw, '/') || str_starts_with($raw, '//') || str_contains($raw, "\n")) {
        return '/';
    }
    $parts = explode('?', $raw, 2);
    $qs = [];
    if (isset($parts[1])) {
        parse_str($parts[1], $qs);
        unset($qs['fb_status'], $qs['fb_ref'], $qs['fb_form'], $qs['fb_msg']);
    }
    return $parts[0] . ($qs ? ('?' . http_build_query($qs)) : '');
}

function fb_redirect(string $url, array $params): void {
    $sep = str_contains($url, '?') ? '&' : '?';
    header('Location: ' . $url . $sep . http_build_query($params), true, 303);
    exit;
}
