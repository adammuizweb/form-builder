<?php
// /plugins/form-builder/plugin.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT') && !defined('PLUGIN_SYSTEM_LOADED')) {
    return;
}

// Submit endpoint for all public forms. Dedicated prefix; forms (rendered via
// the [form slug="..."] shortcode) POST multipart to /form-submit/ (trailing slash).
if (function_exists('register_frontend_route')) {
    register_frontend_route('form-submit', PLUGIN_PATH . '/form-builder/public/submit.php', ['match' => 'exact', 'methods' => ['POST']]);
    // Builder AJAX endpoint (admin-authenticated, JSON). Frontend route keeps
    // output clean of theme markup.
    register_frontend_route('fb-builder', PLUGIN_PATH . '/form-builder/admin/ajax.php', ['match' => 'exact', 'methods' => ['POST']]);
}

const FB_SECRET_KEY = 'form_builder_secret';
const FB_RECAPTCHA_SITEKEY_KEY = 'form_builder_recaptcha_sitekey';
const FB_RECAPTCHA_SECRET_KEY = 'form_builder_recaptcha_secret';

require_once __DIR__ . '/includes/countries.php';

function fb_get_secret(PDO $pdo): string {
    $secret = settings_get($pdo, FB_SECRET_KEY, '');
    if (is_string($secret) && strlen($secret) >= 32) {
        return $secret;
    }
    $secret = bin2hex(random_bytes(32));
    settings_set($pdo, FB_SECRET_KEY, $secret, 1);
    return $secret;
}

function fb_assert_schema(PDO $pdo): void {
    static $checked = [];
    $key = spl_object_id($pdo);
    if (isset($checked[$key])) return;
    $st = $pdo->query("SELECT reference_code, workflow_status, updated_at FROM fb_submissions LIMIT 0");
    if (!$st) throw new RuntimeException('Form Builder migrations are incomplete.');
    if (!$pdo->query('SELECT definition_id FROM fb_import_ledger LIMIT 0') || !$pdo->query('SELECT source_namespace FROM fb_submission_imports LIMIT 0')) throw new RuntimeException('Form Builder import migrations are incomplete.');
    $checked[$key] = true;
}

/** @deprecated Runtime schema mutation was removed in 1.6.0. */
function fb_ensure_schema(PDO $pdo): void { fb_assert_schema($pdo); }

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
        'country'   => ['label' => 'Country',     'input' => true, 'group' => 'input'],
        'intl_phone'=> ['label' => 'International Phone', 'input' => true, 'group' => 'input'],
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
// contrast-safe mini palette: accent, deep shade, text-on-accent, soft tint,
// and a light background tint for the wrapper gradient.
// Scoped to .fb-wrap via CSS variables — no leakage into site themes.
function fb_accent_presets(): array {
    return [
        'green'  => ['label' => 'Hijau',   'accent' => '#2b7a4a', 'deep' => '#1c5633', 'on' => '#ffffff', 'soft' => 'rgba(43 122 74 / .12)',  'bg' => '#f4f7f2'],
        'blue'   => ['label' => 'Biru',    'accent' => '#2563eb', 'deep' => '#1d4ed8', 'on' => '#ffffff', 'soft' => 'rgba(37 99 235 / .12)',  'bg' => '#f0f5fe'],
        'aqua'   => ['label' => 'Aqua',    'accent' => '#0891b2', 'deep' => '#155e75', 'on' => '#ffffff', 'soft' => 'rgba(8 145 178 / .12)',  'bg' => '#effbfc'],
        'red'    => ['label' => 'Merah',   'accent' => '#dc2626', 'deep' => '#991b1b', 'on' => '#ffffff', 'soft' => 'rgba(220 38 38 / .10)',  'bg' => '#fdf3f3'],
        'yellow' => ['label' => 'Kuning',  'accent' => '#ca8a04', 'deep' => '#854d0e', 'on' => '#ffffff', 'soft' => 'rgba(202 138 4 / .14)',  'bg' => '#fdf9ec'],
        'orange' => ['label' => 'Orange',  'accent' => '#ea580c', 'deep' => '#9a3412', 'on' => '#ffffff', 'soft' => 'rgba(234 88 12 / .12)',  'bg' => '#fdf4ee'],
        'purple' => ['label' => 'Ungu',    'accent' => '#7c3aed', 'deep' => '#5b21b6', 'on' => '#ffffff', 'soft' => 'rgba(124 58 237 / .12)', 'bg' => '#f6f2fe'],
        'white'  => ['label' => 'Putih',   'accent' => '#cbd5e1', 'deep' => '#64748b', 'on' => '#0f172a', 'soft' => 'rgba(100 116 139 / .16)','bg' => '#f8fafc'],
        'black'  => ['label' => 'Hitam',   'accent' => '#1f2937', 'deep' => '#030712', 'on' => '#ffffff', 'soft' => 'rgba(31 41 55 / .12)',   'bg' => '#f3f4f6'],
        'pink'   => ['label' => 'Pink',    'accent' => '#db2777', 'deep' => '#9d174d', 'on' => '#ffffff', 'soft' => 'rgba(219 39 119 / .12)', 'bg' => '#fdf2f8'],
        'gray'   => ['label' => 'Abu-abu', 'accent' => '#6b7280', 'deep' => '#374151', 'on' => '#ffffff', 'soft' => 'rgba(107 114 128 / .14)','bg' => '#f4f5f7'],
        'navy'   => ['label' => 'Navy',    'accent' => '#1b3a6b', 'deep' => '#0c2340', 'on' => '#ffffff', 'soft' => 'rgba(27 58 107 / .12)',  'bg' => '#f0f4fa'],
        'gold'   => ['label' => 'Gold',    'accent' => '#b8860b', 'deep' => '#7a5a08', 'on' => '#1c1917', 'soft' => 'rgba(184 134 11 / .14)', 'bg' => '#faf6ea'],
    ];
}

// Inline CSS-variable style for a form's accent preset ('' = default green).
function fb_accent_style(array $settings): string {
    $presets = fb_accent_presets();
    $key = (string)($settings['accent'] ?? 'green');
    if (!isset($presets[$key]) || $key === 'green') return '';
    $p = $presets[$key];
    return '--fb-accent:' . $p['accent'] . ';--fb-accent-deep:' . $p['deep'] . ';--fb-on-accent:' . $p['on'] . ';--fb-accent-soft:' . $p['soft'] . ';--fb-bg:' . $p['bg'] . ';';
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
        'min_fill_seconds'=> 2,
        'notify_email'    => '',
        'confirmation_email_field' => '',
        'reply_to_email_field' => '',
        'workflow_statuses' => ['submitted', 'reviewing', 'accepted', 'rejected', 'archived'],
        'translations' => [],
        'show_total'      => '0',
        'total_label'     => 'Total',
        'currency_code'   => 'USD',
        'columns'         => [],
        'accent'          => 'green',
        'unsafe_code_enabled' => false,
    ];
}

function fb_form_settings(array $form): array {
    $raw = json_decode((string)($form['settings_json'] ?? ''), true);
    $s = array_merge(fb_default_settings(), is_array($raw) ? $raw : []);
    $s['columns'] = is_array($s['columns'] ?? null) ? array_values(array_map('strval', $s['columns'])) : [];
    $s['rate_max'] = max(1, min(10000, (int)$s['rate_max']));
    $s['rate_window'] = max(60, min(604800, (int)$s['rate_window']));
    $s['min_fill_seconds'] = max(0, min(30, (int)($s['min_fill_seconds'] ?? 2)));
    $statuses = array_values(array_unique(array_filter(array_map('strval', (array)($s['workflow_statuses'] ?? [])), static fn(string $v): bool => preg_match('/\A[a-z][a-z0-9_-]{0,39}\z/', $v) === 1)));
    $s['workflow_statuses'] = $statuses ?: ['submitted', 'reviewing', 'accepted', 'rejected', 'archived'];
    if (!in_array('submitted', $s['workflow_statuses'], true)) array_unshift($s['workflow_statuses'], 'submitted');
    return $s;
}

function fb_form_access(array $form): array {
    $raw = json_decode((string)($form['access_json'] ?? ''), true);
    if (!is_array($raw)) $raw = [];
    $submissions = is_array($raw['submissions'] ?? null) ? $raw['submissions'] : [];
    return [
        'roles' => array_values(array_filter(array_map('strval', (array)($raw['roles'] ?? [])))),
        'users' => array_values(array_filter(array_map('intval', (array)($raw['users'] ?? [])))),
        'owner' => (int)($raw['owner'] ?? ($form['created_by'] ?? 0)),
        'submissions' => [
            'roles' => array_values(array_filter(array_map('strval', (array)($submissions['roles'] ?? [])))),
            'users' => array_values(array_filter(array_map('intval', (array)($submissions['users'] ?? [])))),
        ],
    ];
}

function fb_user_role_slugs(PDO $pdo, int $uid): array {
    if ($uid <= 0) return [];
    if (function_exists('authorization_actor')) {
        $actor = authorization_actor($pdo, $uid);
        if ($actor === null) return [];
        return array_values(array_unique(array_filter(array_map(
            static fn(array $role): string => strtolower(trim((string)($role['slug'] ?? ''))),
            (array)$actor['roles']
        ))));
    }
    $legacy = function_exists('current_user_role') ? current_user_role($pdo) : null;
    return is_string($legacy) && $legacy !== '' ? [$legacy] : [];
}

function fb_can_access_form(PDO $pdo, array $form, ?int $uid = null): bool {
    $uid = $uid ?? (function_exists('current_user_id') ? (int)current_user_id() : 0);
    if ($uid <= 0 || !function_exists('user_can')
        || !user_can($pdo, $uid, 'plugin.form-builder.workspace.access')) return false;
    if (user_can($pdo, $uid, 'plugin.form-builder.forms.manage-any')) return true;
    $roles = fb_user_role_slugs($pdo, $uid);
    $acc = fb_form_access($form);
    if ($acc['owner'] > 0 && $acc['owner'] === $uid) return true;
    if (array_intersect($roles, $acc['roles']) !== []) return true;
    if (in_array($uid, $acc['users'], true)) return true;
    return false;
}

function fb_can_view_submissions(PDO $pdo, array $form, ?int $uid = null): bool {
    $uid = $uid ?? (function_exists('current_user_id') ? (int)current_user_id() : 0);
    if ($uid <= 0 || !function_exists('user_can')
        || !user_can($pdo, $uid, 'plugin.form-builder.workspace.access')) return false;
    $acc = fb_form_access($form);
    if ($acc['owner'] > 0 && $acc['owner'] === $uid) return true;
    if (user_can($pdo, $uid, 'plugin.form-builder.submissions.manage') && fb_can_access_form($pdo, $form, $uid)) return true;
    if (array_intersect(fb_user_role_slugs($pdo, $uid), $acc['submissions']['roles']) !== []) return true;
    return in_array($uid, $acc['submissions']['users'], true);
}

function fb_can_manage_submissions(PDO $pdo, array $form, ?int $uid = null): bool {
    $uid = $uid ?? (function_exists('current_user_id') ? (int)current_user_id() : 0);
    return $uid > 0 && function_exists('user_can')
        && user_can($pdo, $uid, 'plugin.form-builder.submissions.manage')
        && fb_can_access_form($pdo, $form, $uid);
}

// All forms available for one workspace capability (PHP-filtered; form counts are small).
// Trashed forms (deleted_at) are always excluded — they live in the Bin.
function fb_accessible_forms(PDO $pdo, string $statusFilter = "status != 'archived'", string $capability = 'workspace'): array {
    $rows = $pdo->query("SELECT * FROM `fb_forms` WHERE {$statusFilter} AND deleted_at IS NULL ORDER BY updated_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $rows = is_array($rows) ? $rows : [];
    $uid = function_exists('current_user_id') ? (int)current_user_id() : 0;
    return array_values(array_filter($rows, static function ($form) use ($pdo, $uid, $capability): bool {
        $canEdit = fb_can_access_form($pdo, $form, $uid);
        $canViewSubmissions = fb_can_view_submissions($pdo, $form, $uid);
        return match ($capability) {
            'edit' => $canEdit,
            'submissions' => $canViewSubmissions,
            default => $canEdit || $canViewSubmissions,
        };
    }));
}

// ---------------- Form Bin (soft delete) ----------------
// Move a form to the Bin. Slug is suffixed so it can be reused and restored later.
function fb_trash_form(PDO $pdo, array $form): void {
    $fid = (int)$form['id'];
    $trashedSlug = substr((string)$form['slug'], 0, 60) . '--trash-' . $fid;
    $pdo->prepare('UPDATE `fb_forms` SET deleted_at = NOW(), slug = ? WHERE id = ?')->execute([$trashedSlug, $fid]);
}

// Restore a trashed form. Original slug is recovered if still free.
function fb_restore_form(PDO $pdo, array $form): void {
    $fid = (int)$form['id'];
    $base = (string)preg_replace('/--trash-\d+$/', '', (string)$form['slug']);
    if ($base === '') $base = 'form-' . $fid;
    $slug = $base;
    $i = 2;
    $chk = $pdo->prepare('SELECT id FROM `fb_forms` WHERE slug = ? AND id != ? LIMIT 1');
    while (true) {
        $chk->execute([$slug, $fid]);
        if ($chk->fetchColumn() === false) break;
        $slug = $base . '-' . $i++;
    }
    $pdo->prepare('UPDATE `fb_forms` SET deleted_at = NULL, slug = ?, updated_at = NOW() WHERE id = ?')->execute([$slug, $fid]);
}

// Hard delete a form: submissions, uploaded files, fields, the form itself.
function fb_hard_delete_form(PDO $pdo, array $form): void {
    $fid = (int)$form['id'];
    $subs = $pdo->prepare('SELECT files_json FROM `fb_submissions` WHERE form_id = ?');
    $subs->execute([$fid]);
    $root = fb_files_base_dir($form);
    while ($r = $subs->fetch(PDO::FETCH_ASSOC)) {
        $fj = json_decode((string)($r['files_json'] ?? ''), true);
        if (is_array($fj)) foreach ($fj as $info) {
            $rel = (string)($info['stored'] ?? '');
            $path = function_exists('fb_contained_path') ? fb_contained_path($root, $rel, true) : null;
            if ($rel !== '' && $path === null) throw new RuntimeException('Refusing unsafe private attachment path.');
            if ($path !== null && is_file($path) && !unlink($path)) throw new RuntimeException('Unable to remove private attachment.');
        }
    }
    $pdo->prepare('DELETE FROM `fb_submissions` WHERE form_id = ?')->execute([$fid]);
    $pdo->prepare('DELETE FROM `fb_fields` WHERE form_id = ?')->execute([$fid]);
    $pdo->prepare('DELETE FROM `fb_forms` WHERE id = ?')->execute([$fid]);
}

// ---------------- Pricing ----------------
function fb_format_currency(int $amount, string $currency): string {
    $currency = preg_match('/\A[A-Z]{3}\z/', $currency) === 1 ? $currency : 'USD';
    return $currency . ' ' . number_format($amount, 0, '.', ',');
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
function fb_normalize_international_phone(string $value, string $countryCode): ?string {
    $country = fb_country($countryCode);
    $dial = (string)($country['dial'] ?? '');
    if ($country === null || preg_match('/\A[0-9+()\-.\s]+\z/', $value) !== 1) return null;

    $compact = preg_replace('/[()\-.\s]/', '', trim($value)) ?? '';
    if (str_starts_with($compact, '00')) $compact = '+' . substr($compact, 2);
    if (str_starts_with($compact, '+')) {
        $digits = substr($compact, 1);
        if (!ctype_digit($digits) || ($dial !== '' && !str_starts_with($digits, $dial))) return null;
    } else {
        if ($dial === '' || !ctype_digit($compact)) return null;
        $prefix = (string)($country['prefix'] ?? '');
        if ($prefix !== '' && str_starts_with($compact, $prefix)) {
            $stripPrefix = $dial !== '1' || $prefix !== '1' || strlen($compact) === 11;
            if ($stripPrefix) $compact = substr($compact, strlen($prefix));
        }
        $digits = $dial . $compact;
    }
    if (preg_match('/\A[1-9][0-9]{6,14}\z/', $digits) !== 1) return null;
    return '+' . $digits;
}

// Returns [data(array key=>value), fileErrors]. Files validated; moving happens in submit.php.
function fb_validate_submission(array $fields, array $post, array $files, array $settings = []): array {
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
            $err = fb_validate_file($f, $files[$key] ?? null, $settings);
            if ($err !== null) $errors[] = $err;
            continue;
        }

        $isMulti = !empty($meta['multi']);
        $raw = $post[$key] ?? ($isMulti ? [] : '');

        if ($isMulti) {
            $vals = is_array($raw) && count($raw) <= 100 ? array_values(array_filter(array_map(static fn($v): string => is_scalar($v) ? mb_substr(trim((string)$v), 0, 1000) : '', $raw), static fn($v) => $v !== '')) : [];
            if ($required && !$vals) { $errors[] = fb_message($settings, 'required', ['field'=>$label]); continue; }
            $allowed = array_column(fb_field_options($f), 'value');
            foreach ($vals as $v) {
                if (!in_array($v, $allowed, true)) { $errors[] = fb_message($settings, 'invalid_option', ['field'=>$label]); break; }
            }
            $data[$key] = $vals;
            continue;
        }

        $value = is_string($raw) && strlen($raw) <= 65536 ? trim($raw) : '';
        if (!is_string($raw) || strlen((string)$raw) > 65536) { $errors[] = fb_message($settings, 'invalid_input', ['field'=>$label]); continue; }
        if ($required && $value === '') { $errors[] = fb_message($settings, 'required', ['field'=>$label]); continue; }
        if ($value === '') { $data[$key] = ''; continue; }
        $enteredValue = $value;

        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) $errors[] = fb_message($settings, 'invalid_email', ['field'=>$label]);
                break;
            case 'tel':
                if (!preg_match('/^[0-9+()\-.\s]{6,25}$/', $value)) $errors[] = fb_message($settings, 'invalid_phone', ['field'=>$label]);
                break;
            case 'country':
                $value = strtoupper($value);
                if (fb_country($value) === null) $errors[] = fb_message($settings, 'invalid_country', ['field'=>$label]);
                break;
            case 'intl_phone':
                $fieldSettings = fb_field_settings($f);
                $countryKey = (string)($fieldSettings['country_field'] ?? '');
                $countryValue = is_string($post[$countryKey] ?? null) ? strtoupper(trim($post[$countryKey])) : '';
                $normalizedPhone = fb_normalize_international_phone($value, $countryValue);
                if ($normalizedPhone === null) $errors[] = fb_message($settings, 'invalid_phone', ['field'=>$label]);
                else $value = $normalizedPhone;
                break;
            case 'number':
                if (!is_numeric($value)) { $errors[] = fb_message($settings, 'invalid_number', ['field'=>$label]); break; }
                if (isset($valid['min']) && $valid['min'] !== '' && (float)$value < (float)$valid['min']) $errors[] = fb_message($settings, 'number_min', ['field'=>$label,'min'=>$valid['min']]);
                if (isset($valid['max']) && $valid['max'] !== '' && (float)$value > (float)$valid['max']) $errors[] = fb_message($settings, 'number_max', ['field'=>$label,'max'=>$valid['max']]);
                break;
            case 'date':
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                $dateErrors = DateTimeImmutable::getLastErrors();
                if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) $errors[] = fb_message($settings, 'invalid_date', ['field'=>$label]);
                break;
            case 'select':
            case 'radio':
                $allowed = array_column(fb_field_options($f), 'value');
                if (!in_array($value, $allowed, true)) $errors[] = fb_message($settings, 'invalid_option', ['field'=>$label]);
                break;
        }
        $pattern = is_string($valid['pattern'] ?? null) && strlen($valid['pattern']) <= 500 ? $valid['pattern'] : '';
        $validationValue = $type === 'intl_phone' ? $enteredValue : $value;
        if ($pattern !== '' && @preg_match('/(*LIMIT_MATCH=100000)(*LIMIT_RECURSION=1000)' . str_replace('/', '\/', $pattern) . '/', $validationValue) !== 1) {
            $errors[] = fb_message($settings, 'invalid_format', ['field'=>$label]);
        }
        if (!empty($valid['maxlength']) && mb_strlen($validationValue) > (int)$valid['maxlength']) {
            $errors[] = fb_message($settings, 'too_long', ['field'=>$label,'max'=>(int)$valid['maxlength']]);
        }
        $data[$key] = $value;
    }
    $labels = [];
    foreach ($fields as $candidate) $labels[(string)$candidate['field_key']] = (string)($candidate['label'] ?: $candidate['field_key']);
    foreach ($fields as $field) {
        if ((string)$field['type'] !== 'date') continue;
        $validation = fb_field_validation($field);
        $other = (string)($validation['after_field'] ?? '');
        if ($other !== '' && !empty($data[$field['field_key']]) && !empty($data[$other]) && $data[$field['field_key']] <= $data[$other]) {
            $errors[] = fb_message($settings, 'date_after', ['field'=>$labels[$field['field_key']] ?? $field['field_key'],'other'=>$labels[$other] ?? $other]);
        }
        $other = (string)($validation['before_field'] ?? '');
        if ($other !== '' && !empty($data[$field['field_key']]) && !empty($data[$other]) && $data[$field['field_key']] >= $data[$other]) {
            $errors[] = fb_message($settings, 'date_before', ['field'=>$labels[$field['field_key']] ?? $field['field_key'],'other'=>$labels[$other] ?? $other]);
        }
    }
    return [$data, $errors];
}

function fb_validate_file(array $field, ?array $file, array $settings = []): ?string {
    $label = (string)($field['label'] !== '' ? $field['label'] : $field['field_key']);
    $required = !empty($field['required']);
    $valid = fb_field_validation($field);
    $isImage = (string)$field['type'] === 'image';
    $maxBytes = max(1, min(25 * 1024 * 1024, (int)($valid['max_bytes'] ?? 5 * 1024 * 1024)));
    $exts = $isImage
        ? (array)($valid['exts'] ?? ['jpg', 'jpeg', 'png', 'webp'])
        : (array)($valid['exts'] ?? ['jpg', 'jpeg', 'png', 'webp', 'pdf']);

    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $required ? fb_message($settings, 'required', ['field'=>$label]) : null;
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) return fb_message($settings, 'upload_failed', ['field'=>$label]);
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return fb_message($settings, 'upload_invalid', ['field'=>$label]);
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        return fb_message($settings, 'file_too_large_server', ['field'=>$label,'size'=>round($maxBytes / 1048576, 1)]);
    }
    $orig = basename((string)($file['name'] ?? 'file'));
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, array_map('strtolower', $exts), true)) {
        return fb_message($settings, 'extension_not_allowed', ['field'=>$label,'extension'=>$ext]);
    }
    $mimeByExtension = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if (!isset($mimeByExtension[$ext]) || !in_array($mime, $mimeByExtension[$ext], true)) return fb_message($settings, 'mime_mismatch', ['field'=>$label]);
    $perExtension = is_array($valid['max_bytes_by_ext'] ?? null) ? $valid['max_bytes_by_ext'] : [];
    if (isset($perExtension[$ext]) && $size > max(1, min($maxBytes, (int)$perExtension[$ext]))) return fb_message($settings, 'extension_size', ['field'=>$label,'extension'=>$ext]);
    if ($isImage && function_exists('getimagesize') && @getimagesize($tmp) === false) {
        return fb_message($settings, 'invalid_image', ['field'=>$label]);
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
//   add_filter('fb_files_base_dir', fn($base, $form) => '/mnt/private/form-builder');
function fb_project_root(): string {
    $configured = defined('PLUGIN_PATH') ? (string)PLUGIN_PATH : '';
    $plugins = $configured !== '' ? realpath($configured) : false;
    if ($plugins === false) {
        $plugins = realpath(dirname(__DIR__));
        $pluginDirectory = realpath(__DIR__);
        if ($plugins === false || basename($plugins) !== 'plugins' || $pluginDirectory === false || dirname($pluginDirectory) !== $plugins) throw new RuntimeException('Cannot resolve the Jyavani plugin directory.');
    }
    if (!is_dir($plugins) || ($configured !== '' && is_link($configured))) throw new RuntimeException('Unsafe Jyavani plugin directory.');
    $root = realpath(dirname($plugins));
    if ($root === false || $root === DIRECTORY_SEPARATOR) throw new RuntimeException('Cannot resolve the Jyavani project root.');
    return $root;
}

function fb_normalize_storage_base(string $path): string {
    if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0") || str_contains($path, '\\') || basename($path) !== 'form-builder') {
        throw new RuntimeException('Form Builder storage must be an absolute dedicated form-builder directory.');
    }
    $parent = realpath(dirname($path));
    if ($parent === false || $parent === DIRECTORY_SEPARATOR || is_link(dirname($path))) throw new RuntimeException('Unsafe Form Builder storage parent.');
    $normalized = $parent . DIRECTORY_SEPARATOR . 'form-builder';
    if (file_exists($normalized)) {
        $real = realpath($normalized);
        if ($real === false || $real !== $normalized || !is_dir($real) || is_link($normalized)) throw new RuntimeException('Unsafe Form Builder storage directory.');
    }
    return $normalized;
}

function fb_storage_base_candidate(array $form): string {
    $base = fb_project_root() . '/private_files/form-builder';
    if (function_exists('apply_filters')) $base = apply_filters('fb_files_base_dir', $base, $form);
    if (!is_string($base)) throw new RuntimeException('Invalid Form Builder storage filter result.');
    return rtrim($base, '/');
}

function fb_files_base_dir(array $form): string {
    return fb_normalize_storage_base(fb_storage_base_candidate($form));
}

function fb_prepare_files_base_dir(array $form): string {
    $projectRoot = fb_project_root();
    $candidate = fb_storage_base_candidate($form);
    $defaultParent = $projectRoot . '/private_files';
    if ($candidate === $defaultParent . '/form-builder' && !file_exists($defaultParent)) {
        if (!mkdir($defaultParent, 0750) && !is_dir($defaultParent)) {
            throw new RuntimeException('Unable to create the private files directory.');
        }
    }

    $base = fb_normalize_storage_base($candidate);
    if (!file_exists($base) && !mkdir($base, 0750) && !is_dir($base)) {
        throw new RuntimeException('Unable to create the Form Builder storage root.');
    }
    clearstatcache(true, $base);
    $stat = @lstat($base);
    $resolved = realpath($base);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000 || is_link($base)
        || $resolved === false || $resolved !== $base || !is_writable($resolved)) {
        throw new RuntimeException('Form Builder storage root is unavailable or not writable.');
    }
    return $resolved;
}

function fb_ensure_storage_directory(string $base, array $segments): string {
    $root = realpath($base);
    if ($root === false || !is_dir($root) || is_link($base)) throw new RuntimeException('Unsafe Form Builder storage root.');
    $path = $root;
    foreach ($segments as $segment) {
        if (!is_string($segment) || preg_match('/\A[a-zA-Z0-9._-]+\z/', $segment) !== 1 || $segment === '.' || $segment === '..') {
            throw new RuntimeException('Invalid Form Builder storage segment.');
        }
        $path .= DIRECTORY_SEPARATOR . $segment;
        if (!file_exists($path) && !mkdir($path, 0750)) throw new RuntimeException('Unable to create Form Builder storage directory.');
        $resolved = realpath($path);
        if ($resolved === false || $resolved !== $path || !is_dir($resolved) || is_link($path)
            || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR) || !is_writable($resolved)) {
            throw new RuntimeException('Unsafe Form Builder storage directory.');
        }
    }
    return $path;
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
    fb_assert_schema($pdo);
});

// ---------------- Bin integration (soft-deleted fields/elements) ----------------
// Core bin hub calls apply_filters('bin_items', $items, $pdo, ...counts, $base).
add_filter('bin_items', function (array $items, $pdo = null, ...$rest): array {
    if (!($pdo instanceof PDO)) return $items;
    $uid = function_exists('current_user_id') ? (int)current_user_id() : 0;
    if ($uid <= 0 || !function_exists('user_can')
        || !user_can($pdo, $uid, 'plugin.form-builder.bin.manage')
        || !user_can($pdo, $uid, 'plugin.form-builder.forms.manage-any')) return $items;
    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `fb_fields` WHERE deleted_at IS NOT NULL AND type NOT IN ('row','col')")->fetchColumn();
        $cnt += (int)$pdo->query("SELECT COUNT(*) FROM `fb_forms` WHERE deleted_at IS NOT NULL")->fetchColumn();
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
        'title' => 'Bin Form Builder',
        'desc'  => 'Trash for deleted forms, fields & elements (restorable).',
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
    if (!($pdo instanceof PDO)) throw new RuntimeException('Database unavailable for Form Builder cleanup.');
    $candidate = fb_storage_base_candidate([]);
    if (file_exists($candidate) || is_link($candidate)) {
        $root = fb_normalize_storage_base($candidate);
        $expected = fb_normalize_storage_base($root);
        if ($expected !== $root || is_link($root)) {
            throw new RuntimeException('Refusing unsafe Form Builder storage cleanup.');
        }
        $remove = static function (string $path) use (&$remove): void {
            if (is_link($path)) throw new RuntimeException('Refusing symlink in Form Builder storage.');
            if (is_dir($path)) {
                foreach (new FilesystemIterator($path) as $entry) $remove($entry->getPathname());
                if (!rmdir($path)) throw new RuntimeException('Unable to remove Form Builder storage directory.');
            } elseif (!unlink($path)) throw new RuntimeException('Unable to remove Form Builder private file.');
        };
        $remove($root);
    }
    $pdo->exec('DROP TABLE IF EXISTS `fb_import_ledger`');
    $pdo->exec('DROP TABLE IF EXISTS `fb_submission_imports`');
    $pdo->exec('DROP TABLE IF EXISTS `fb_rate_limits`');
    $pdo->exec('DROP TABLE IF EXISTS `fb_submissions`');
    $pdo->exec('DROP TABLE IF EXISTS `fb_fields`');
    $pdo->exec('DROP TABLE IF EXISTS `fb_forms`');
    $deleteSettings = $pdo->prepare('DELETE FROM settings WHERE `key` IN (?,?,?)');
    if (!$deleteSettings->execute([FB_SECRET_KEY, FB_RECAPTCHA_SITEKEY_KEY, FB_RECAPTCHA_SECRET_KEY])) throw new RuntimeException('Unable to remove Form Builder settings.');
    unset($GLOBALS['__jy_settings_autoload_cache'][FB_SECRET_KEY], $GLOBALS['__jy_settings_autoload_cache'][FB_RECAPTCHA_SITEKEY_KEY], $GLOBALS['__jy_settings_autoload_cache'][FB_RECAPTCHA_SECRET_KEY]);
});

// ---------------- Shortcode: [form slug="..."] ----------------
require_once __DIR__ . '/includes/definitions.php';
require_once __DIR__ . '/includes/submission-import.php';
require_once __DIR__ . '/public/render.php';

if (function_exists('register_theme_section')) {
    register_theme_section('form-builder', [
        'label' => 'Form Builder',
        'description' => 'Render a published form by slug.',
        'defaults' => ['slug' => ''],
        'fallback' => static function (array $attrs, array $context, ?PDO $database): string {
            $slug = is_string($attrs['slug'] ?? null) ? $attrs['slug'] : '';
            if (!$database instanceof PDO || preg_match('/\A[a-z0-9][a-z0-9_-]{0,79}\z/', $slug) !== 1) return '';
            $form = fb_get_form_by_slug($database, $slug);
            return $form !== null && $form['status'] === 'active' ? fb_render_form($database, $form) : '';
        },
    ]);
}

add_filter('theme_zone_widget_types', static function (array $types): array {
    $types['tz_form_builder'] = ['label'=>'Form Builder','desc'=>'Embed a public form by slug.','default_config'=>['slug'=>'']];
    return $types;
});
add_filter('theme_zone_render_widget', static function (string $html, string $type, array $config, PDO $pdo): string {
    if ($type !== 'tz_form_builder') return $html;
    $slug = is_string($config['slug'] ?? null) ? $config['slug'] : '';
    if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,79}\z/', $slug) !== 1) return '';
    $form = fb_get_form_by_slug($pdo, $slug);
    return $form !== null && $form['status'] === 'active' ? fb_render_form($pdo, $form) : '';
}, 20);

add_filter('post_content', function (string $html, array $post = []): string {
    if (strpos($html, '[form ') === false && strpos($html, '[form]') === false) {
        return $html;
    }
    return preg_replace_callback('/\[form\s+slug=["\']([^"\']+)["\']\s*\]/', static function (array $m): string {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO) || !function_exists('fb_render_form')) return '';
        fb_assert_schema($pdo);
        $form = fb_get_form_by_slug($pdo, $m[1]);
        if ($form === null || ($form['status'] ?? '') !== 'active') {
            return '<!-- form "' . htmlspecialchars($m[1], ENT_QUOTES) . '" not available -->';
        }
        return fb_render_form($pdo, $form);
    }, $html) ?? $html;
});
