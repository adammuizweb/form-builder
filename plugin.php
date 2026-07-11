<?php
// /plugins/form-builder/plugin.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT') && !defined('PLUGIN_SYSTEM_LOADED')) {
    return;
}

// Submit endpoint for all public forms. Dedicated prefix; forms (rendered via
// the [form slug="..."] shortcode) POST multipart to /form-submit/ (trailing slash).
if (function_exists('register_frontend_route')) {
    register_frontend_route('form-submit', PLUGIN_PATH . '/form-builder/public/submit.php');
}

const FB_SECRET_KEY = 'form_builder_secret';
const FB_RECAPTCHA_SITEKEY_KEY = 'form_builder_recaptcha_sitekey';
const FB_RECAPTCHA_SECRET_KEY = 'form_builder_recaptcha_secret';

function fb_get_secret(PDO $pdo): string {
    $secret = settings_get($pdo, FB_SECRET_KEY, '');
    if (is_string($secret) && strlen($secret) >= 32) {
        return $secret;
    }
    $secret = bin2hex(random_bytes(32));
    settings_set($pdo, FB_SECRET_KEY, $secret, 1);
    return $secret;
}

function fb_ensure_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `fb_forms` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `slug` varchar(80) NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'active',
            `settings_json` longtext DEFAULT NULL,
            `css` mediumtext DEFAULT NULL,
            `js` mediumtext DEFAULT NULL,
            `access_json` longtext DEFAULT NULL,
            `created_by` bigint(20) unsigned DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `fb_fields` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `form_id` bigint(20) unsigned NOT NULL,
            `type` varchar(20) NOT NULL DEFAULT 'text',
            `label` varchar(255) NOT NULL DEFAULT '',
            `field_key` varchar(80) NOT NULL DEFAULT '',
            `placeholder` varchar(255) DEFAULT NULL,
            `help_text` varchar(255) DEFAULT NULL,
            `required` tinyint(1) NOT NULL DEFAULT 0,
            `width` tinyint(3) unsigned NOT NULL DEFAULT 12,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
            `options_json` longtext DEFAULT NULL,
            `validation_json` longtext DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `form_id` (`form_id`),
            KEY `sort_order` (`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `fb_submissions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `form_id` bigint(20) unsigned NOT NULL,
            `data_json` longtext DEFAULT NULL,
            `files_json` longtext DEFAULT NULL,
            `totals_json` longtext DEFAULT NULL,
            `search_blob` mediumtext DEFAULT NULL,
            `ip` varchar(45) DEFAULT NULL,
            `is_read` tinyint(1) NOT NULL DEFAULT 0,
            `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `form_id` (`form_id`),
            KEY `created_at` (`created_at`),
            KEY `is_read` (`is_read`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `fb_rate_limits` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `ip` varchar(45) NOT NULL,
            `action` varchar(40) NOT NULL,
            `bucket` int(10) unsigned NOT NULL,
            `count` int(10) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ip_action_bucket` (`ip`,`action`,`bucket`),
            KEY `bucket` (`bucket`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// ---------------- Field type registry ----------------
function fb_field_types(): array {
    return [
        'text'      => ['label' => 'Text',       'input' => true],
        'email'     => ['label' => 'Email',      'input' => true],
        'tel'       => ['label' => 'Phone',      'input' => true],
        'number'    => ['label' => 'Number',     'input' => true],
        'textarea'  => ['label' => 'Textarea',   'input' => true],
        'date'      => ['label' => 'Date',       'input' => true],
        'select'    => ['label' => 'Select',     'input' => true, 'options' => true],
        'radio'     => ['label' => 'Radio',      'input' => true, 'options' => true],
        'checkbox'  => ['label' => 'Checkbox',   'input' => true, 'options' => true, 'multi' => true],
        'file'      => ['label' => 'File Upload','input' => true, 'file' => true],
        'image'     => ['label' => 'Image Upload','input' => true, 'file' => true, 'image' => true],
        'heading'   => ['label' => 'Heading',    'display' => true],
        'paragraph' => ['label' => 'Paragraph',  'display' => true],
        'divider'   => ['label' => 'Divider',    'display' => true],
    ];
}

function fb_normalize_key(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
    $s = trim($s, '_');
    return $s !== '' ? $s : 'field';
}

// ---------------- Form accessors ----------------
function fb_get_form(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM `fb_forms` WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function fb_get_form_by_slug(PDO $pdo, string $slug): ?array {
    $st = $pdo->prepare('SELECT * FROM `fb_forms` WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function fb_get_fields(PDO $pdo, int $formId, bool $includeHidden = true): array {
    $sql = 'SELECT * FROM `fb_fields` WHERE form_id = ?' . ($includeHidden ? '' : ' AND is_hidden = 0') . ' ORDER BY sort_order ASC, id ASC';
    $st = $pdo->prepare($sql);
    $st->execute([$formId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function fb_field_options(array $field): array {
    $raw = json_decode((string)($field['options_json'] ?? ''), true);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $o) {
        if (!is_array($o)) continue;
        $value = (string)($o['value'] ?? '');
        if ($value === '') continue;
        $out[] = [
            'value' => $value,
            'label' => (string)($o['label'] ?? $value),
            'price' => (int)($o['price'] ?? 0),
        ];
    }
    return $out;
}

function fb_field_validation(array $field): array {
    $raw = json_decode((string)($field['validation_json'] ?? ''), true);
    return is_array($raw) ? $raw : [];
}

function fb_default_settings(): array {
    return [
        'submit_label'    => 'Submit',
        'success_message' => 'Thank you! Your submission has been received.',
        'recaptcha'       => '0',
        'rate_max'        => 10,
        'rate_window'     => 3600,
        'notify_email'    => '',
        'show_total'      => '0',
        'total_label'     => 'Total',
        'columns'         => [],
    ];
}

function fb_form_settings(array $form): array {
    $raw = json_decode((string)($form['settings_json'] ?? ''), true);
    $s = array_merge(fb_default_settings(), is_array($raw) ? $raw : []);
    $s['columns'] = is_array($s['columns'] ?? null) ? array_values(array_map('strval', $s['columns'])) : [];
    $s['rate_max'] = max(1, (int)$s['rate_max']);
    $s['rate_window'] = max(60, (int)$s['rate_window']);
    return $s;
}

function fb_form_access(array $form): array {
    $raw = json_decode((string)($form['access_json'] ?? ''), true);
    if (!is_array($raw)) $raw = [];
    return [
        'roles' => array_values(array_filter(array_map('strval', (array)($raw['roles'] ?? [])))),
        'users' => array_values(array_filter(array_map('intval', (array)($raw['users'] ?? [])))),
        'owner' => (int)($raw['owner'] ?? ($form['created_by'] ?? 0)),
    ];
}

function fb_can_access_form(PDO $pdo, array $form, ?int $uid = null, ?string $role = null): bool {
    if (!function_exists('current_user_id')) return true; // CLI/tests
    $uid = $uid ?? current_user_id();
    $role = $role ?? (function_exists('current_user_role') ? current_user_role($pdo) : null);
    if ($role === 'admin') return true;
    $acc = fb_form_access($form);
    if ($acc['owner'] > 0 && $acc['owner'] === $uid) return true;
    if ($role !== null && in_array($role, $acc['roles'], true)) return true;
    if (in_array($uid, $acc['users'], true)) return true;
    return false;
}

// All forms the current user may manage (PHP-filtered; form counts are small).
function fb_accessible_forms(PDO $pdo, string $statusFilter = "status != 'archived'"): array {
    $rows = $pdo->query("SELECT * FROM `fb_forms` WHERE {$statusFilter} ORDER BY updated_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $rows = is_array($rows) ? $rows : [];
    if (!function_exists('current_user_id')) return $rows;
    $role = function_exists('current_user_role') ? current_user_role($pdo) : null;
    if ($role === 'admin') return $rows;
    $uid = current_user_id();
    return array_values(array_filter($rows, static fn($f) => fb_can_access_form($pdo, $f, $uid, $role)));
}

// ---------------- Pricing ----------------
function fb_format_rupiah(int $n): string {
    return 'Rp ' . number_format($n, 0, ',', '.');
}

// Sum of prices for all selected option values (select/radio single, checkbox multi).
function fb_compute_total(array $fields, array $data): int {
    $total = 0;
    foreach ($fields as $f) {
        if (!empty($f['is_hidden'])) continue;
        $type = (string)$f['type'];
        if (!in_array($type, ['select', 'radio', 'checkbox'], true)) continue;
        $priceMap = [];
        foreach (fb_field_options($f) as $o) $priceMap[$o['value']] = $o['price'];
        $val = $data[$f['field_key']] ?? null;
        if (is_array($val)) {
            foreach ($val as $v) $total += $priceMap[(string)$v] ?? 0;
        } elseif ($val !== null && $val !== '') {
            $total += $priceMap[(string)$val] ?? 0;
        }
    }
    return $total;
}

// ---------------- Validation engine ----------------
// Returns [data(array key=>value), fileErrors]. Files validated; moving happens in submit.php.
function fb_validate_submission(array $fields, array $post, array $files): array {
    $types = fb_field_types();
    $data = [];
    $errors = [];

    foreach ($fields as $f) {
        $type = (string)$f['type'];
        $meta = $types[$type] ?? null;
        if ($meta === null || !empty($meta['display']) || !empty($f['is_hidden'])) continue;
        $key = (string)$f['field_key'];
        $label = (string)($f['label'] !== '' ? $f['label'] : $key);
        $required = !empty($f['required']);
        $valid = fb_field_validation($f);

        if (!empty($meta['file'])) {
            $err = fb_validate_file($f, $files[$key] ?? null);
            if ($err !== null) $errors[] = $err;
            continue;
        }

        $isMulti = !empty($meta['multi']);
        $raw = $post[$key] ?? ($isMulti ? [] : '');

        if ($isMulti) {
            $vals = is_array($raw) ? array_values(array_filter(array_map('strval', $raw), static fn($v) => $v !== '')) : [];
            if ($required && !$vals) { $errors[] = $label . ' is required'; continue; }
            $allowed = array_column(fb_field_options($f), 'value');
            foreach ($vals as $v) {
                if (!in_array($v, $allowed, true)) { $errors[] = $label . ' has an invalid option'; break; }
            }
            $data[$key] = $vals;
            continue;
        }

        $value = is_string($raw) ? trim($raw) : '';
        if ($required && $value === '') { $errors[] = $label . ' is required'; continue; }
        if ($value === '') { $data[$key] = ''; continue; }

        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) $errors[] = $label . ' must be a valid email';
                break;
            case 'tel':
                if (!preg_match('/^[0-9+()\-.\s]{6,25}$/', $value)) $errors[] = $label . ' must be a valid phone number';
                break;
            case 'number':
                if (!is_numeric($value)) { $errors[] = $label . ' must be a number'; break; }
                if (isset($valid['min']) && $valid['min'] !== '' && (float)$value < (float)$valid['min']) $errors[] = $label . ' must be at least ' . $valid['min'];
                if (isset($valid['max']) && $valid['max'] !== '' && (float)$value > (float)$valid['max']) $errors[] = $label . ' must be at most ' . $valid['max'];
                break;
            case 'date':
                if (strtotime($value) === false) $errors[] = $label . ' must be a valid date';
                break;
            case 'select':
            case 'radio':
                $allowed = array_column(fb_field_options($f), 'value');
                if (!in_array($value, $allowed, true)) $errors[] = $label . ' has an invalid option';
                break;
        }
        if (!empty($valid['pattern']) && @preg_match('/' . str_replace('/', '\/', (string)$valid['pattern']) . '/', $value) !== 1) {
            $errors[] = $label . ' has an invalid format';
        }
        if (!empty($valid['maxlength']) && mb_strlen($value) > (int)$valid['maxlength']) {
            $errors[] = $label . ' is too long (max ' . (int)$valid['maxlength'] . ' chars)';
        }
        $data[$key] = $value;
    }
    return [$data, $errors];
}

function fb_validate_file(array $field, ?array $file): ?string {
    $label = (string)($field['label'] !== '' ? $field['label'] : $field['field_key']);
    $required = !empty($field['required']);
    $valid = fb_field_validation($field);
    $isImage = (string)$field['type'] === 'image';
    $maxBytes = (int)($valid['max_bytes'] ?? 5 * 1024 * 1024);
    $exts = $isImage
        ? (array)($valid['exts'] ?? ['jpg', 'jpeg', 'png', 'webp'])
        : (array)($valid['exts'] ?? ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);

    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $required ? $label . ' is required' : null;
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) return $label . ' upload failed (code ' . (int)$file['error'] . ')';
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        return $label . ' exceeds the ' . round($maxBytes / 1048576, 1) . ' MB limit';
    }
    $orig = basename((string)($file['name'] ?? 'file'));
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, array_map('strtolower', $exts), true)) {
        return $label . ': file type .' . $ext . ' is not allowed';
    }
    if ($isImage && function_exists('getimagesize') && @getimagesize((string)$file['tmp_name']) === false) {
        return $label . ' is not a valid image';
    }
    return null;
}

function fb_search_blob(array $fields, array $data): string {
    $types = fb_field_types();
    $parts = [];
    foreach ($fields as $f) {
        $key = (string)$f['field_key'];
        $meta = $types[(string)$f['type']] ?? null;
        if ($meta === null || !empty($meta['display']) || !empty($meta['file'])) continue;
        $v = $data[$key] ?? null;
        if (is_array($v)) $parts[] = implode(' ', $v);
        elseif (is_scalar($v)) $parts[] = (string)$v;
    }
    return mb_substr(implode(' | ', $parts), 0, 60000);
}

// Storage dir for a form's uploads (outside web root): private_files/form-builder/{formId}/YYYY/MM
// Base dir for a form's uploads (outside web root). Filterable so other plugins
// can relocate storage (e.g. another disk/S3 bridge) without patching the CMS core:
//   add_filter('fb_files_base_dir', fn($base, $form) => '/mnt/uploads/fb');
function fb_files_base_dir(array $form): string {
    $base = dirname(__DIR__, 2) . '/private_files/form-builder';
    if (function_exists('apply_filters')) {
        $base = apply_filters('fb_files_base_dir', $base, $form);
    }
    return rtrim((string)$base, '/');
}

function fb_upload_dir(int $formId): string {
    $dir = dirname(__DIR__, 2) . '/private_files/form-builder/' . $formId . '/' . date('Y') . '/' . date('m');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

// ---------------- reCAPTCHA (global keys, per-form toggle) ----------------
function fb_recaptcha_keys(PDO $pdo): array {
    return [
        'sitekey' => (string)settings_get($pdo, FB_RECAPTCHA_SITEKEY_KEY, ''),
        'secret'  => (string)settings_get($pdo, FB_RECAPTCHA_SECRET_KEY, ''),
    ];
}

function fb_recaptcha_verify(string $secret, string $token, string $remoteIp = ''): bool {
    $token = trim($token);
    if ($token === '') return false;
    $post = http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $remoteIp]);
    $resp = null;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $post, 'timeout' => 10]]);
        $resp = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
    }
    if (!is_string($resp) || $resp === '') return false;
    $j = json_decode($resp, true);
    return is_array($j) && !empty($j['success']);
}

// ---------------- Lifecycle ----------------
add_action('admin_init', function (): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) return;
    fb_get_secret($pdo);
    fb_ensure_schema($pdo);
});

add_action('plugin_uninstall', function (string $name): void {
    if ($name !== 'form-builder') return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) return;
    $pdo->exec('DROP TABLE IF EXISTS `fb_forms`');
    $pdo->exec('DROP TABLE IF EXISTS `fb_fields`');
    $pdo->exec('DROP TABLE IF EXISTS `fb_submissions`');
    $pdo->exec('DROP TABLE IF EXISTS `fb_rate_limits`');
    settings_set($pdo, FB_SECRET_KEY, '', 1);
    settings_set($pdo, FB_RECAPTCHA_SITEKEY_KEY, '', 1);
    settings_set($pdo, FB_RECAPTCHA_SECRET_KEY, '', 1);
});

// ---------------- Shortcode: [form slug="..."] ----------------
require_once __DIR__ . '/public/render.php';

add_filter('post_content', function (string $html, array $post = []): string {
    if (strpos($html, '[form ') === false && strpos($html, '[form]') === false) {
        return $html;
    }
    return preg_replace_callback('/\[form\s+slug=["\']([^"\']+)["\']\s*\]/', static function (array $m): string {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO) || !function_exists('fb_render_form')) return '';
        fb_ensure_schema($pdo);
        $form = fb_get_form_by_slug($pdo, $m[1]);
        if ($form === null || ($form['status'] ?? '') !== 'active') {
            return '<!-- form "' . htmlspecialchars($m[1], ENT_QUOTES) . '" not available -->';
        }
        return fb_render_form($pdo, $form);
    }, $html) ?? $html;
});
