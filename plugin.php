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
    // Builder AJAX endpoint (admin-authenticated, JSON). Frontend route keeps
    // output clean of theme markup.
    register_frontend_route('fb-builder', PLUGIN_PATH . '/form-builder/admin/ajax.php');
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
            `parent_id` bigint(20) unsigned NOT NULL DEFAULT 0,
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
            `settings_json` longtext DEFAULT NULL,
            `deleted_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `form_id` (`form_id`),
            KEY `parent_id` (`parent_id`),
            KEY `sort_order` (`sort_order`),
            KEY `deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // idempotent migrations
    try {
        $pdo->exec("ALTER TABLE `fb_fields` ADD COLUMN IF NOT EXISTS `parent_id` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `form_id`");
    } catch (Throwable $e) {
        // ignore if syntax unsupported or column exists
    }
    try {
        $pdo->exec("ALTER TABLE `fb_fields` ADD COLUMN IF NOT EXISTS `settings_json` longtext DEFAULT NULL AFTER `validation_json`");
        $pdo->exec("ALTER TABLE `fb_fields` ADD COLUMN IF NOT EXISTS `deleted_at` datetime DEFAULT NULL AFTER `settings_json`");
        $pdo->exec("ALTER TABLE `fb_fields` ADD INDEX IF NOT EXISTS `deleted_at` (`deleted_at`)");
    } catch (Throwable $e) {
        // ignore if syntax unsupported or column exists
    }
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
// group: 'input' = fillable by the visitor; 'element' = display-only content.
// editor: panel editor kind for elements ('quill' | 'code' | 'media').
function fb_field_types(): array {
    return [
        'row'       => ['label' => 'Row',         'container' => true],
        'col'       => ['label' => 'Column',      'container' => true],
        'text'      => ['label' => 'Text',        'input' => true, 'group' => 'input'],
        'email'     => ['label' => 'Email',       'input' => true, 'group' => 'input'],
        'tel'       => ['label' => 'Phone',       'input' => true, 'group' => 'input'],
        'number'    => ['label' => 'Number',      'input' => true, 'group' => 'input'],
        'textarea'  => ['label' => 'Textarea',    'input' => true, 'group' => 'input'],
        'date'      => ['label' => 'Date',        'input' => true, 'group' => 'input'],
        'select'    => ['label' => 'Select',      'input' => true, 'group' => 'input', 'options' => true],
        'radio'     => ['label' => 'Radio',       'input' => true, 'group' => 'input', 'options' => true],
        'checkbox'  => ['label' => 'Checkbox',    'input' => true, 'group' => 'input', 'options' => true, 'multi' => true],
        'file'      => ['label' => 'File Upload', 'input' => true, 'group' => 'input', 'file' => true],
        'image'     => ['label' => 'Image Upload','input' => true, 'group' => 'input', 'file' => true, 'image' => true],
        'heading'   => ['label' => 'Heading',     'display' => true, 'group' => 'element'],
        'paragraph' => ['label' => 'Paragraph',   'display' => true, 'group' => 'element'],
        'divider'   => ['label' => 'Divider',     'display' => true, 'group' => 'element'],
        'richtext'  => ['label' => 'Rich Text',   'display' => true, 'group' => 'element', 'editor' => 'quill'],
        'image_block' => ['label' => 'Image',     'display' => true, 'group' => 'element', 'editor' => 'media'],
        'raw_html'  => ['label' => 'Raw HTML',    'display' => true, 'group' => 'element', 'editor' => 'code'],
        'total'     => ['label' => 'Total',        'display' => true, 'group' => 'element'],
    ];
}

const FB_HEADING_LEVELS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

// Accent color presets for the public form. Each preset is a complete
// contrast-safe mini palette: accent, deep shade, text-on-accent, soft tint.
// Scoped to .fb-wrap via CSS variables — no leakage into site themes.
function fb_accent_presets(): array {
    return [
        'green'  => ['label' => 'Hijau',   'accent' => '#2b7a4a', 'deep' => '#1c5633', 'on' => '#ffffff', 'soft' => 'rgba(43 122 74 / .12)'],
        'blue'   => ['label' => 'Biru',    'accent' => '#2563eb', 'deep' => '#1d4ed8', 'on' => '#ffffff', 'soft' => 'rgba(37 99 235 / .12)'],
        'aqua'   => ['label' => 'Aqua',    'accent' => '#0891b2', 'deep' => '#155e75', 'on' => '#ffffff', 'soft' => 'rgba(8 145 178 / .12)'],
        'red'    => ['label' => 'Merah',   'accent' => '#dc2626', 'deep' => '#991b1b', 'on' => '#ffffff', 'soft' => 'rgba(220 38 38 / .10)'],
        'yellow' => ['label' => 'Kuning',  'accent' => '#ca8a04', 'deep' => '#854d0e', 'on' => '#ffffff', 'soft' => 'rgba(202 138 4 / .14)'],
        'orange' => ['label' => 'Orange',  'accent' => '#ea580c', 'deep' => '#9a3412', 'on' => '#ffffff', 'soft' => 'rgba(234 88 12 / .12)'],
        'purple' => ['label' => 'Ungu',    'accent' => '#7c3aed', 'deep' => '#5b21b6', 'on' => '#ffffff', 'soft' => 'rgba(124 58 237 / .12)'],
        'white'  => ['label' => 'Putih',   'accent' => '#cbd5e1', 'deep' => '#64748b', 'on' => '#0f172a', 'soft' => 'rgba(100 116 139 / .16)'],
        'black'  => ['label' => 'Hitam',   'accent' => '#1f2937', 'deep' => '#030712', 'on' => '#ffffff', 'soft' => 'rgba(31 41 55 / .12)'],
        'pink'   => ['label' => 'Pink',    'accent' => '#db2777', 'deep' => '#9d174d', 'on' => '#ffffff', 'soft' => 'rgba(219 39 119 / .12)'],
        'gray'   => ['label' => 'Abu-abu', 'accent' => '#6b7280', 'deep' => '#374151', 'on' => '#ffffff', 'soft' => 'rgba(107 114 128 / .14)'],
    ];
}

// Inline CSS-variable style for a form's accent preset ('' = default green).
function fb_accent_style(array $settings): string {
    $presets = fb_accent_presets();
    $key = (string)($settings['accent'] ?? 'green');
    if (!isset($presets[$key]) || $key === 'green') return '';
    $p = $presets[$key];
    return '--fb-accent:' . $p['accent'] . ';--fb-accent-deep:' . $p['deep'] . ';--fb-on-accent:' . $p['on'] . ';--fb-accent-soft:' . $p['soft'] . ';';
}

// Per-field type-specific settings (heading level, rich text/html content, image data).
function fb_field_settings(array $f): array {
    $s = json_decode((string)($f['settings_json'] ?? ''), true);
    return is_array($s) ? $s : [];
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
    $sql = 'SELECT * FROM `fb_fields` WHERE form_id = ? AND deleted_at IS NULL' . ($includeHidden ? '' : ' AND is_hidden = 0') . ' ORDER BY sort_order ASC, id ASC';
    $st = $pdo->prepare($sql);
    $st->execute([$formId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

// Strip layout containers (row/col) — returns only real fields.
function fb_flat_fields(array $fields): array {
    $types = fb_field_types();
    return array_values(array_filter($fields, static fn($f) => empty($types[$f['type']]['container'])));
}

// Layout tree: [ ['node'=>row, 'cols'=>[ ['node'=>col, 'fields'=>[...] ], ...] ], ... ]
function fb_get_tree(PDO $pdo, int $formId): array {
    fb_migrate_to_tree($pdo, $formId);
    $all = fb_get_fields($pdo, $formId);
    $byParent = [];
    foreach ($all as $f) $byParent[(int)$f['parent_id']][] = $f;
    $tree = [];
    foreach ($byParent[0] ?? [] as $row) {
        if ($row['type'] !== 'row') continue;
        $cols = [];
        foreach ($byParent[(int)$row['id']] ?? [] as $col) {
            if ($col['type'] !== 'col') continue;
            $cols[] = ['node' => $col, 'fields' => array_values(array_filter($byParent[(int)$col['id']] ?? [], static fn($x) => $x['type'] !== 'row' && $x['type'] !== 'col'))];
        }
        $tree[] = ['node' => $row, 'cols' => $cols];
    }
    return $tree;
}

// One-time migration: wrap legacy flat fields (parent_id=0) into rows/columns,
// grouping by their old width so the visual layout is preserved.
function fb_migrate_to_tree(PDO $pdo, int $formId): void {
    $hasRows = (bool)$pdo->query("SELECT 1 FROM `fb_fields` WHERE form_id = {$formId} AND type = 'row' LIMIT 1")->fetchColumn();
    if ($hasRows) return;
    $orphans = $pdo->query("SELECT * FROM `fb_fields` WHERE form_id = {$formId} AND parent_id = 0 AND deleted_at IS NULL AND type NOT IN ('row','col') ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($orphans) || !$orphans) return;
    $groups = []; $cur = []; $sum = 0;
    foreach ($orphans as $f) {
        $w = max(1, min(12, (int)($f['width'] ?? 12) ?: 12));
        if ($cur && $sum + $w > 12) { $groups[] = $cur; $cur = []; $sum = 0; }
        $cur[] = $f; $sum += $w;
        if ($sum >= 12) { $groups[] = $cur; $cur = []; $sum = 0; }
    }
    if ($cur) $groups[] = $cur;
    $rowSort = 10;
    foreach ($groups as $g) {
        $pdo->prepare("INSERT INTO `fb_fields` (form_id, type, label, field_key, parent_id, sort_order) VALUES (?, 'row', '', ?, 0, ?)")
            ->execute([$formId, 'row_' . bin2hex(random_bytes(4)), $rowSort]);
        $rowId = (int)$pdo->lastInsertId();
        $rowSort += 10;
        $colSort = 10;
        foreach ($g as $f) {
            $pdo->prepare("INSERT INTO `fb_fields` (form_id, type, label, field_key, parent_id, sort_order) VALUES (?, 'col', '', ?, ?, ?)")
                ->execute([$formId, 'col_' . bin2hex(random_bytes(4)), $rowId, $colSort]);
            $colId = (int)$pdo->lastInsertId();
            $colSort += 10;
            $pdo->prepare('UPDATE `fb_fields` SET parent_id = ? WHERE id = ?')->execute([$colId, (int)$f['id']]);
        }
    }
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
        'accent'          => 'green',
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
        if ($meta === null || empty($meta['input']) || !empty($f['is_hidden'])) continue;
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

// ---------------- Bin integration (soft-deleted fields/elements) ----------------
// Core bin hub calls apply_filters('bin_items', $items, $pdo, ...counts, $base).
add_filter('bin_items', function (array $items, $pdo = null, ...$rest): array {
    if (!($pdo instanceof PDO)) return $items;
    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `fb_fields` WHERE deleted_at IS NOT NULL AND type NOT IN ('row','col')")->fetchColumn();
    } catch (Throwable $e) {
        $cnt = 0;
    }
    $base = '';
    if ($rest) {
        $last = end($rest);
        $base = is_string($last) ? $last : '';
    }
    $items[] = [
        'key'   => 'form-builder',
        'title' => 'Bin Form Fields',
        'desc'  => 'Trash for deleted form builder fields & elements (restorable).',
        'count' => $cnt,
        'href'  => $base . '/?page=admin/bin/form-builder/index',
        'svg'   => 'list',
        'route' => 'admin/bin/form-builder/index',
    ];
    return $items;
}, 10);

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
