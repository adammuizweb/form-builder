<?php
// /plugins/form-builder/public/submit.php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$publicRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
require_once $publicRoot . '/app/bootstrap_core.php';

if (!($pdo instanceof PDO)) { http_response_code(500); exit('DB unavailable'); }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(404);
    exit('Not found');
}

fb_ensure_schema($pdo);

$return = fb_safe_return_url((string)($_POST['fb_return'] ?? '/'));
$formId = (int)($_POST['fb_form_id'] ?? 0);
$form = $formId > 0 ? fb_get_form($pdo, $formId) : null;

$fail = static function (string $msg) use ($return, $form): void {
    fb_redirect($return, ['fb_status' => 'err', 'fb_form' => (string)($form['slug'] ?? ''), 'fb_msg' => $msg]);
};

if ($form === null || ($form['status'] ?? '') !== 'active') {
    $fail('This form is not accepting submissions.');
}
// Slug must match the id (prevents forged form ids)
if (trim((string)($_POST['fb_slug'] ?? '')) !== (string)$form['slug']) {
    $fail('Invalid form reference.');
}

$settings = fb_form_settings($form);
$fields = fb_get_fields($pdo, $formId, false);

// CSRF
$ctx = fb_public_ctx($pdo);
if (!fb_csrf_check($ctx['csrf'], (string)($_POST['csrf_token'] ?? ''))) {
    $fail('Security token invalid. Please reload the page and try again.');
}

// Honeypot
if (trim((string)($_POST['fb_website'] ?? '')) !== '') {
    http_response_code(400);
    exit('Spam detected.');
}

// reCAPTCHA (per-form toggle, global keys)
if ($settings['recaptcha'] === '1') {
    $keys = fb_recaptcha_keys($pdo);
    if ($keys['secret'] === '' || !fb_recaptcha_verify($keys['secret'], (string)($_POST['g-recaptcha-response'] ?? ''), $ctx['ip'])) {
        $fail('reCAPTCHA verification failed.');
    }
}

// Rate limit (per form)
if (!fb_rate_limit_check($pdo, $ctx['ip'], 'submit_f' . $formId, (int)$settings['rate_window'], (int)$settings['rate_max'])) {
    $fail('Too many submissions. Please try again later.');
}

// Validate
[$data, $errors] = fb_validate_submission($fields, $_POST, $_FILES);
if ($errors) {
    $fail(implode(' | ', array_slice($errors, 0, 3)));
}

// Store files (after validation passed)
$stored = [];
$types = fb_field_types();
$baseDir = fb_files_base_dir($form);
$relDir = $formId . '/' . date('Y') . '/' . date('m');
if (!is_dir($baseDir . '/' . $relDir)) @mkdir($baseDir . '/' . $relDir, 0755, true);
foreach ($fields as $f) {
    if (empty($types[$f['type']]['file']) || !empty($f['is_hidden'])) continue;
    $key = (string)$f['field_key'];
    $file = $_FILES[$key] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
    $orig = basename((string)($file['name'] ?? 'file'));
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $rel = $relDir . '/' . bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
    $dest = $baseDir . '/' . $rel;
    if (@move_uploaded_file((string)$file['tmp_name'], $dest)) {
        $stored[$key] = ['stored' => $rel, 'original' => $orig];
        if (function_exists('do_action')) do_action('fb_file_stored', $form, $f, $stored[$key]);
    }
}

// Total (server-side recompute)
$total = fb_compute_total($fields, $data);

$stmt = $pdo->prepare("
    INSERT INTO `fb_submissions` (`form_id`, `data_json`, `files_json`, `totals_json`, `search_blob`, `ip`, `created_at`)
    VALUES (:fid, :data, :files, :totals, :blob, :ip, NOW())
");
$stmt->execute([
    ':fid' => $formId,
    ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),
    ':files' => $stored ? json_encode($stored, JSON_UNESCAPED_UNICODE) : null,
    ':totals' => json_encode(['total' => $total], JSON_UNESCAPED_UNICODE),
    ':blob' => fb_search_blob($fields, $data),
    ':ip' => $ctx['ip'] ?: null,
]);
$subId = (int)$pdo->lastInsertId();
$ref = 'FB-' . $formId . '-' . str_pad((string)$subId, 5, '0', STR_PAD_LEFT);

// Extensibility: other plugins can react to new submissions
//   add_action('fb_after_submit', fn($form, $data, $subId, $ref) => ...);
if (function_exists('do_action')) do_action('fb_after_submit', $form, $data, $subId, $ref);

// Optional email notification (best-effort)
$notify = trim((string)$settings['notify_email']);
if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL) && function_exists('mail')) {
    @mail($notify, '[' . $form['title'] . '] New submission ' . $ref, "New submission on form \"{$form['title']}\" ({$ref}).\n\n" . print_r($data, true));
}

fb_redirect($return, ['fb_status' => 'ok', 'fb_form' => (string)$form['slug'], 'fb_ref' => $ref]);
