<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
$publicRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
require_once $publicRoot . '/app/bootstrap_core.php';

$settings = fb_default_settings();
if (!($pdo instanceof PDO)) { http_response_code(503); exit(fb_message($settings, 'service_unavailable')); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit(fb_message($settings, 'method_not_allowed')); }

try { fb_assert_schema($pdo); } catch (Throwable $error) { http_response_code(503); exit(fb_message($settings, 'service_unavailable')); }
$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
if ($contentLength > 64 * 1024 * 1024 || count($_POST) > 350 || count($_FILES) > 100) { http_response_code(413); exit(fb_message($settings, 'request_too_large')); }
foreach (['fb_return'=>2048,'fb_slug'=>80,'csrf_token'=>2048,'fb_website'=>256,'fb_started'=>100,'fb_idempotency'=>64,'g-recaptcha-response'=>8192] as $reserved => $limit) {
    if (isset($_POST[$reserved]) && (!is_string($_POST[$reserved]) || strlen($_POST[$reserved]) > $limit)) { http_response_code(400); exit(fb_message($settings, 'invalid_request')); }
}

$return = fb_safe_return_url(is_string($_POST['fb_return'] ?? null) ? $_POST['fb_return'] : '/');
$formId = filter_var($_POST['fb_form_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$form = $formId ? fb_get_form($pdo, (int)$formId) : null;
$fail = static function (string $message, int $status = 303) use ($return, &$form): never {
    if ($status !== 303) { http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); exit($message); }
    fb_redirect($return, ['fb_status' => 'err', 'fb_form' => (string)($form['slug'] ?? ''), 'fb_msg' => $message]);
};
if ($form === null || ($form['status'] ?? '') !== 'active') $fail(fb_message($settings, 'form_unavailable'));
if (!is_string($_POST['fb_slug'] ?? null) || trim($_POST['fb_slug']) !== (string)$form['slug']) $fail(fb_message($settings, 'invalid_form'));

$settings = fb_form_settings($form);
$fields = fb_flat_fields(fb_get_fields($pdo, (int)$formId, false));
$localizedFields = [];
foreach ($fields as $field) $localizedFields[] = fb_localized_field($field, $settings);
$localizedByKey = [];
foreach ($localizedFields as $field) $localizedByKey[$field['field_key']] = $field;
[$localizedForm, $settings] = fb_localized_form($form, $settings);
$ctx = fb_public_ctx($pdo);
if (!is_string($_POST['csrf_token'] ?? null) || !fb_csrf_check('', $_POST['csrf_token'])) $fail(fb_message($settings, 'security_invalid'));
if (!is_string($_POST['fb_website'] ?? null) || trim($_POST['fb_website']) !== '') $fail(fb_message($settings, 'spam'), 400);
if (!is_string($_POST['fb_started'] ?? null) || !fb_started_check($pdo, (int)$formId, $_POST['fb_started'], (int)$settings['min_fill_seconds'])) $fail(fb_message($settings, 'wait'), 400);
$idempotency = is_string($_POST['fb_idempotency'] ?? null) ? strtolower(trim($_POST['fb_idempotency'])) : '';
if (preg_match('/\A[a-f0-9]{32,64}\z/', $idempotency) !== 1) $fail(fb_message($settings, 'invalid_submission_key'), 400);

if ($settings['recaptcha'] === '1') {
    $keys = fb_recaptcha_keys($pdo);
    $captcha = is_string($_POST['g-recaptcha-response'] ?? null) ? $_POST['g-recaptcha-response'] : '';
    if ($keys['secret'] === '' || !fb_recaptcha_verify($keys['secret'], $captcha, $ctx['ip'])) $fail(fb_message($settings, 'captcha_failed'));
}

[$data, $errors] = fb_validate_submission($localizedFields, $_POST, $_FILES, $settings);
if ($errors) $fail(implode(' | ', array_slice($errors, 0, 3)));

try { $baseDir = fb_files_base_dir($form); }
catch (Throwable $error) { error_log('[form-builder] storage resolution failed: ' . $error->getMessage()); $fail(fb_message($settings, 'upload_storage_unavailable'), 503); }
$privateRoot = dirname($baseDir);
if (!is_dir($privateRoot) || is_link($privateRoot)) $fail(fb_message($settings, 'upload_storage_unavailable'), 503);
if (!is_dir($baseDir) && !mkdir($baseDir, 0700)) $fail(fb_message($settings, 'upload_storage_unavailable'), 503);
$realBase = realpath($baseDir);
if ($realBase === false || $realBase !== $baseDir || is_link($baseDir)) $fail(fb_message($settings, 'upload_storage_unavailable'), 503);
$stageId = bin2hex(random_bytes(16));
try { $stageDir = fb_ensure_storage_directory($realBase, ['.staging', $stageId]); }
catch (Throwable $error) { error_log('[form-builder] staging directory failed: ' . $error->getMessage()); $fail(fb_message($settings, 'upload_storage_unavailable'), 503); }
$staged = [];
$cleanup = static function (array $paths, ?string $dir = null): void {
    foreach ($paths as $path) if (is_string($path) && is_file($path)) @unlink($path);
    if ($dir !== null && is_dir($dir)) @rmdir($dir);
};

try {
    $types = fb_field_types();
    foreach ($fields as $field) {
        if (empty($types[$field['type']]['file']) || !empty($field['is_hidden'])) continue;
        $key = (string)$field['field_key'];
        $file = $_FILES[$key] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $original = basename((string)$file['name']);
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $path = $stageDir . '/' . $name;
        if (!move_uploaded_file((string)$file['tmp_name'], $path)) throw new RuntimeException(fb_message($settings, 'file_store_failed', ['field'=>$localizedByKey[$key]['label'] ?? $field['label']]));
        if (!chmod($path, 0600)) throw new RuntimeException(fb_message($settings, 'file_store_failed', ['field'=>$localizedByKey[$key]['label'] ?? $field['label']]));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $staged[$key] = ['path' => $path, 'name' => $name, 'original' => mb_substr($original, 0, 255),
            'mime' => (string)$finfo->file($path), 'size' => (int)filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    $pdo->beginTransaction();
    $rate = fb_rate_limit_check($pdo, $ctx['ip'], 'submit_f' . $formId, (int)$settings['rate_window'], (int)$settings['rate_max']);
    if (!$rate['allowed']) {
        $pdo->rollBack(); $cleanup(array_column($staged, 'path'), $stageDir);
        header('Retry-After: ' . $rate['retry_after']); $fail(fb_message($settings, 'rate_limited'), 429);
    }
    $existing = $pdo->prepare('SELECT reference_code FROM fb_submissions WHERE form_id = ? AND idempotency_key = ? FOR UPDATE');
    $existing->execute([$formId, $idempotency]);
    $existingRef = $existing->fetchColumn();
    if (is_string($existingRef) && $existingRef !== '') {
        $pdo->commit(); $cleanup(array_column($staged, 'path'), $stageDir);
        fb_redirect($return, ['fb_status'=>'ok','fb_form'=>(string)$form['slug'],'fb_ref'=>$existingRef]);
    }

    $finalSegments = [(string)$formId, date('Y'), date('m')];
    $finalRelDir = implode('/', $finalSegments);
    $finalDir = fb_ensure_storage_directory($realBase, $finalSegments);
    $files = []; $finalPaths = [];
    foreach ($staged as $key => $file) {
        $destination = $finalDir . '/' . $file['name'];
        if (!rename($file['path'], $destination)) throw new RuntimeException(fb_message($settings, 'upload_finalize_failed'));
        $finalPaths[] = $destination;
        $files[$key] = ['stored'=>$finalRelDir . '/' . $file['name'],'original'=>$file['original'],'mime'=>$file['mime'],'size'=>$file['size'],'sha256'=>$file['sha256']];
    }
    @rmdir($stageDir);
    $reference = 'FB-' . strtoupper(bin2hex(random_bytes(6)));
    $history = [['at'=>gmdate('c'),'from'=>null,'to'=>'submitted','actor'=>null,'source'=>'public']];
    $source = ['channel'=>'web','locale'=>fb_locale(),'path'=>mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''),0,500),'user_agent'=>mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500)];
    $stmt = $pdo->prepare('INSERT INTO fb_submissions (form_id,reference_code,workflow_status,history_json,source_json,idempotency_key,data_json,files_json,totals_json,search_blob,ip,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
    $stmt->execute([$formId,$reference,'submitted',fb_json_encode($history),fb_json_encode($source),$idempotency,fb_json_encode($data),$files ? fb_json_encode($files) : null,fb_json_encode(['total'=>fb_compute_total($fields,$data)]),fb_search_blob($fields,$data),$ctx['ip']]);
    $submissionId = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $cleanup(array_merge(array_column($staged, 'path'), $finalPaths ?? []), $stageDir);
    if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
        $replay = $pdo->prepare('SELECT reference_code FROM fb_submissions WHERE form_id = ? AND idempotency_key = ? LIMIT 1');
        $replay->execute([$formId, $idempotency]);
        $replayRef = $replay->fetchColumn();
        if (is_string($replayRef) && $replayRef !== '') fb_redirect($return, ['fb_status'=>'ok','fb_form'=>(string)$form['slug'],'fb_ref'=>$replayRef]);
    }
    error_log('[form-builder] submission failed: ' . $error->getMessage());
    $fail(fb_message($settings, 'save_failed'), 503);
}

if (function_exists('do_action_isolated')) {
    foreach ($files as $key => $fileInfo) {
        $fileField = null;
        foreach ($fields as $candidate) if ($candidate['field_key'] === $key) { $fileField = $candidate; break; }
        if ($fileField === null) continue;
        foreach (do_action_isolated('fb_file_stored', $form, $fileField, $fileInfo) as $hookError) error_log('[form-builder] observer failed: ' . $hookError['message']);
    }
    foreach (do_action_isolated('fb_after_submit', $form, $data, $submissionId, $reference) as $hookError) error_log('[form-builder] observer failed: ' . $hookError['message']);
}

$mailFields = '';
foreach ($localizedFields as $field) {
    $key = (string)$field['field_key'];
    if (!array_key_exists($key, $data)) continue;
    $value = is_array($data[$key]) ? implode(', ', $data[$key]) : (string)$data[$key];
    $mailFields .= mb_substr((string)$field['label'], 0, 200) . ': ' . mb_substr(str_replace(["\r","\n"], ' ', $value), 0, 2000) . "\n";
}
$mailValues = ['form_title'=>mb_substr((string)$localizedForm['title'],0,180),'reference'=>$reference,'fields'=>trim($mailFields)];
$notify = trim((string)$settings['notify_email']);
$replyKey = (string)$settings['reply_to_email_field'];
$reply = isset($data[$replyKey]) && is_string($data[$replyKey]) && filter_var($data[$replyKey], FILTER_VALIDATE_EMAIL) ? $data[$replyKey] : null;
if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL) && function_exists('jy_mail_send')) {
    $message = ['to'=>[$notify],'subject'=>fb_email_text($settings,'admin_subject',$mailValues),'body'=>fb_email_text($settings,'admin_body',$mailValues)];
    if ($reply !== null) $message['reply_to'] = ['email'=>$reply,'name'=>''];
    jy_mail_send($pdo, $message);
}
$confirmKey = (string)$settings['confirmation_email_field'];
$applicant = isset($data[$confirmKey]) && is_string($data[$confirmKey]) && filter_var($data[$confirmKey], FILTER_VALIDATE_EMAIL) ? $data[$confirmKey] : null;
if ($applicant !== null && function_exists('jy_mail_send')) jy_mail_send($pdo, ['to'=>[$applicant],'subject'=>fb_email_text($settings,'applicant_subject',$mailValues),'body'=>fb_email_text($settings,'applicant_body',$mailValues)]);

fb_redirect($return, ['fb_status'=>'ok','fb_form'=>(string)$form['slug'],'fb_ref'=>$reference]);
