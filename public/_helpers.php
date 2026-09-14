<?php
// /plugins/form-builder/public/_helpers.php
declare(strict_types=1);

function fb_public_ctx(PDO $pdo): array {
    $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? trim($_SERVER['REMOTE_ADDR']) : '';
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) $ip = '';
    $token = function_exists('stateless_csrf_token') ? stateless_csrf_token() : '';
    return ['csrf' => $token, 'ip' => $ip];
}

function fb_csrf_check(string $token, string $sent): bool {
    return $sent !== '' && function_exists('stateless_csrf_check') && stateless_csrf_check(trim($sent));
}

function fb_rate_limit_check(PDO $pdo, string $ip, string $action, int $windowSeconds, int $max): array {
    if ($ip === '') return ['allowed' => false, 'retry_after' => $windowSeconds];
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
        $allowed = ((int)($q->fetchColumn() ?: 0)) <= $max;
        return ['allowed' => $allowed, 'retry_after' => max(1, (($bucket + 1) * $windowSeconds) - time())];
    } catch (Throwable $e) {
        return ['allowed' => false, 'retry_after' => max(1, $windowSeconds)];
    }
}

function fb_started_token(PDO $pdo, int $formId): string {
    $time = time();
    return $time . '.' . hash_hmac('sha256', $formId . ':' . $time, fb_get_secret($pdo));
}

function fb_started_check(PDO $pdo, int $formId, string $token, int $minimum): bool {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) return false;
    $time = (int)$parts[0];
    return $time <= time() - $minimum && $time >= time() - 7200
        && hash_equals(hash_hmac('sha256', $formId . ':' . $time, fb_get_secret($pdo)), $parts[1]);
}

function fb_contained_path(string $base, string $relative, bool $mustExist = true): ?string {
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, '\\')) return null;
    $realBase = realpath($base);
    if ($realBase === false || is_link($base)) return null;
    $candidate = $realBase . '/' . $relative;
    $resolved = $mustExist ? realpath($candidate) : realpath(dirname($candidate));
    if ($resolved === false || ($resolved !== $realBase && !str_starts_with($resolved, $realBase . DIRECTORY_SEPARATOR))) return null;
    if ($mustExist && is_link($candidate)) return null;
    return $candidate;
}

function fb_csv_cell(mixed $value): string {
    $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
    return preg_match('/\A[=+\-@\t\r]/', ltrim($value)) === 1 ? "'" . $value : $value;
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
