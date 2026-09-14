<?php
declare(strict_types=1);

const FB_SUBMISSION_IMPORT_SCHEMA = 1;
const FB_SUBMISSION_IMPORT_MAX_BYTES = 1048576;

function fb_import_canonicalize(mixed $value): mixed {
    if (is_array($value)) {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = fb_import_canonicalize($item);
    }
    return $value;
}

function fb_import_bounded_json(mixed $value, int $depth = 0): mixed {
    if ($depth > 8) throw new InvalidArgumentException('Import metadata is too deep.');
    if (is_string($value)) {
        if (strlen($value) > 65536 || str_contains($value, "\0")) throw new InvalidArgumentException('Import string is invalid.');
        return $value;
    }
    if (is_int($value) || is_float($value) || is_bool($value) || $value === null) return $value;
    if (!is_array($value) || count($value) > 500) throw new InvalidArgumentException('Import metadata is invalid.');
    foreach ($value as $key => $item) {
        if (!is_int($key) && (!is_string($key) || strlen($key) > 100 || preg_match('/[\x00-\x1F\x7F]/', $key))) throw new InvalidArgumentException('Import metadata key is invalid.');
        $value[$key] = fb_import_bounded_json($item, $depth + 1);
    }
    return $value;
}

function fb_import_datetime(mixed $value, string $name): string {
    if (!is_string($value)) throw new InvalidArgumentException('Invalid ' . $name . '.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d H:i:s') !== $value) throw new InvalidArgumentException('Invalid ' . $name . '.');
    return $value;
}

function fb_normalize_legacy_submission(array $record, array $form, array $fields, bool $verifyFiles = true): array {
    $allowed = ['schema','reference_code','workflow_status','created_at','updated_at','notes','history','source','data','files','totals','ip','is_read','is_deleted'];
    if (($record['schema'] ?? null) !== FB_SUBMISSION_IMPORT_SCHEMA || array_diff(array_keys($record), $allowed) !== []) throw new InvalidArgumentException('Invalid submission import contract.');
    $reference = $record['reference_code'] ?? null;
    if (!is_string($reference) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,39}\z/', $reference) !== 1) throw new InvalidArgumentException('Invalid imported reference.');
    $settings = fb_form_settings($form);
    $workflow = $record['workflow_status'] ?? null;
    if (!is_string($workflow) || !in_array($workflow, $settings['workflow_statuses'], true)) throw new InvalidArgumentException('Invalid imported workflow status.');
    $created = fb_import_datetime($record['created_at'] ?? null, 'created timestamp');
    $updated = fb_import_datetime($record['updated_at'] ?? null, 'updated timestamp');
    if ($updated < $created) throw new InvalidArgumentException('Updated timestamp precedes creation.');

    $types = fb_field_types(); $input = []; $fileFields = [];
    foreach ($fields as $field) {
        $type = $types[$field['type']] ?? [];
        if (!empty($type['file'])) $fileFields[$field['field_key']] = $field;
        elseif (!empty($type['input'])) $input[$field['field_key']] = true;
    }
    $data = $record['data'] ?? [];
    if (!is_array($data) || array_is_list($data) || count($data) > 300) throw new InvalidArgumentException('Invalid imported field data.');
    foreach ($data as $key => $value) {
        if (!is_string($key) || !isset($input[$key])) throw new InvalidArgumentException('Unknown imported field.');
        if (is_string($value)) { if (strlen($value) > 65536 || str_contains($value, "\0")) throw new InvalidArgumentException('Invalid imported value.'); }
        elseif (is_array($value) && array_is_list($value) && count($value) <= 100) { foreach ($value as $item) if (!is_string($item) || strlen($item) > 1000 || str_contains($item, "\0")) throw new InvalidArgumentException('Invalid imported list value.'); }
        else throw new InvalidArgumentException('Invalid imported value.');
    }

    $notes = $record['notes'] ?? [];
    if (!is_array($notes) || !array_is_list($notes) || count($notes) > 1000) throw new InvalidArgumentException('Invalid imported notes.');
    foreach ($notes as $note) {
        if (!is_array($note) || array_diff(array_keys($note), ['at','actor','text']) !== []) throw new InvalidArgumentException('Invalid imported note.');
        fb_import_datetime($note['at'] ?? null, 'note timestamp');
        if (($note['actor'] ?? null) !== null && (!is_int($note['actor']) || $note['actor'] < 1)) throw new InvalidArgumentException('Invalid note actor.');
        if (!is_string($note['text'] ?? null) || trim($note['text']) === '' || mb_strlen($note['text']) > 4000) throw new InvalidArgumentException('Invalid imported note text.');
    }
    $history = $record['history'] ?? [];
    if (!is_array($history) || !array_is_list($history) || count($history) > 2000) throw new InvalidArgumentException('Invalid imported history.');
    foreach ($history as $event) {
        if (!is_array($event) || array_diff(array_keys($event), ['at','actor','from','to','source','note_added']) !== []) throw new InvalidArgumentException('Invalid imported history event.');
        fb_import_datetime($event['at'] ?? null, 'history timestamp');
        if (($event['actor'] ?? null) !== null && (!is_int($event['actor']) || $event['actor'] < 1)) throw new InvalidArgumentException('Invalid history actor.');
        foreach (['from','to'] as $statusKey) if (($event[$statusKey] ?? null) !== null && (!is_string($event[$statusKey]) || preg_match('/\A[a-z][a-z0-9_-]{0,39}\z/', $event[$statusKey]) !== 1)) throw new InvalidArgumentException('Invalid history status.');
        if (isset($event['source']) && (!is_string($event['source']) || strlen($event['source']) > 100)) throw new InvalidArgumentException('Invalid history source.');
        if (isset($event['note_added']) && !is_bool($event['note_added'])) throw new InvalidArgumentException('Invalid history note marker.');
    }
    $source = fb_import_bounded_json($record['source'] ?? []);
    if (!is_array($source) || ($source !== [] && array_is_list($source))) throw new InvalidArgumentException('Invalid import provenance.');
    if (array_key_exists('_form_builder_import', $source)) throw new InvalidArgumentException('Import provenance uses a reserved key.');
    $totals = fb_import_bounded_json($record['totals'] ?? []);
    if (!is_array($totals) || ($totals !== [] && array_is_list($totals))) throw new InvalidArgumentException('Invalid imported totals.');

    $files = $record['files'] ?? [];
    if (!is_array($files) || ($files !== [] && array_is_list($files)) || count($files) > 100) throw new InvalidArgumentException('Invalid imported attachments.');
    $base = $verifyFiles && $files !== [] ? fb_files_base_dir($form) : '';
    foreach ($files as $key => $file) {
        if (!is_string($key) || !isset($fileFields[$key]) || !is_array($file) || array_diff(array_keys($file), ['stored','original','mime','size','sha256']) !== []) throw new InvalidArgumentException('Invalid imported attachment contract.');
        if (!is_string($file['stored'] ?? null) || !is_string($file['original'] ?? null) || mb_strlen($file['original']) > 255 || str_contains($file['original'], "\0") || !is_string($file['mime'] ?? null) || preg_match('#\A[a-z0-9.+-]+/[a-z0-9.+-]+\z#i', $file['mime']) !== 1 || !is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > 25 * 1024 * 1024 || !is_string($file['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $file['sha256']) !== 1) throw new InvalidArgumentException('Invalid imported attachment metadata.');
        $extension = strtolower(pathinfo($file['stored'], PATHINFO_EXTENSION));
        $validation = fb_field_validation($fileFields[$key]);
        $allowedExtensions = $validation['exts'] ?? ($fileFields[$key]['type'] === 'image' ? ['jpg','jpeg','png','webp'] : ['jpg','jpeg','png','webp','pdf']);
        $mimeByExtension = ['jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp'],'pdf'=>['application/pdf']];
        if (!in_array($extension, $allowedExtensions, true) || !in_array(strtolower($file['mime']), $mimeByExtension[$extension] ?? [], true)) throw new InvalidArgumentException('Imported attachment violates the field policy.');
        $maxBytes = min(25 * 1024 * 1024, (int)($validation['max_bytes'] ?? 5 * 1024 * 1024));
        $extensionMax = min($maxBytes, (int)($validation['max_bytes_by_ext'][$extension] ?? $maxBytes));
        if ($file['size'] > $extensionMax) throw new InvalidArgumentException('Imported attachment exceeds the field policy.');
        if ($verifyFiles) {
            $path = fb_contained_path($base, $file['stored'], true);
            if ($path === null || !is_file($path) || filesize($path) !== $file['size'] || !hash_equals($file['sha256'], (string)hash_file('sha256', $path))) throw new InvalidArgumentException('Imported attachment does not match private storage.');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            if (!hash_equals(strtolower($file['mime']), strtolower((string)$finfo->file($path)))) throw new InvalidArgumentException('Imported attachment MIME does not match private storage.');
        }
    }
    $ip = $record['ip'] ?? null;
    if ($ip !== null && (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false)) throw new InvalidArgumentException('Invalid imported IP address.');
    foreach (['is_read','is_deleted'] as $flag) if (isset($record[$flag]) && !is_bool($record[$flag])) throw new InvalidArgumentException('Invalid imported state.');
    $normalized = ['schema'=>FB_SUBMISSION_IMPORT_SCHEMA,'reference_code'=>$reference,'workflow_status'=>$workflow,'created_at'=>$created,'updated_at'=>$updated,'notes'=>$notes,'history'=>$history,'source'=>$source,'data'=>$data,'files'=>$files,'totals'=>$totals,'ip'=>$ip,'is_read'=>$record['is_read'] ?? false,'is_deleted'=>$record['is_deleted'] ?? false];
    if (strlen(fb_json_encode($normalized)) > FB_SUBMISSION_IMPORT_MAX_BYTES) throw new InvalidArgumentException('Submission import is too large.');
    return $normalized;
}

function fb_import_legacy_submission(PDO $pdo, string|int $form, string $sourceNamespace, string $sourceKey, array $record): array {
    if (preg_match('/\A[a-z][a-z0-9._-]{0,79}\z/', $sourceNamespace) !== 1 || preg_match('/\A[\x21-\x7E]{1,191}\z/', $sourceKey) !== 1) throw new InvalidArgumentException('Invalid import source identity.');
    $formRow = is_int($form) ? fb_get_form($pdo, $form) : fb_get_form_by_slug($pdo, $form);
    if ($formRow === null || $formRow['deleted_at'] !== null) throw new InvalidArgumentException('Import form not found.');
    $fields = fb_flat_fields(fb_get_fields($pdo, (int)$formRow['id']));
    $normalized = fb_normalize_legacy_submission($record, $formRow, $fields, true);
    $payloadHash = hash('sha256', fb_json_encode(fb_import_canonicalize($normalized)));
    $formId = (int)$formRow['id'];
    $pdo->beginTransaction();
    try {
        $lockForm = $pdo->prepare('SELECT id FROM fb_forms WHERE id = ? AND deleted_at IS NULL FOR UPDATE'); $lockForm->execute([$formId]);
        if ($lockForm->fetchColumn() === false) throw new RuntimeException('Import form changed.');
        $ledger = $pdo->prepare('SELECT payload_sha256,form_id,submission_id,reference_code FROM fb_submission_imports WHERE source_namespace = ? AND source_key = ? FOR UPDATE');
        $ledger->execute([$sourceNamespace,$sourceKey]); $existing = $ledger->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (!hash_equals((string)$existing['payload_sha256'], $payloadHash) || (int)$existing['form_id'] !== $formId || !hash_equals((string)$existing['reference_code'], $normalized['reference_code'])) throw new DomainException('Divergent legacy submission import.');
            $check = $pdo->prepare('SELECT reference_code FROM fb_submissions WHERE id = ? AND form_id = ?'); $check->execute([(int)$existing['submission_id'],$formId]);
            if ($check->fetchColumn() !== $normalized['reference_code']) throw new RuntimeException('Imported submission ledger is inconsistent.');
            $pdo->commit(); return ['submission_id'=>(int)$existing['submission_id'],'reference_code'=>$normalized['reference_code'],'reconciled'=>true,'sha256'=>$payloadHash];
        }
        $reference = $pdo->prepare('SELECT id FROM fb_submissions WHERE form_id = ? AND reference_code = ? FOR UPDATE'); $reference->execute([$formId,$normalized['reference_code']]);
        if ($reference->fetchColumn() !== false) throw new DomainException('Imported reference already exists without this source identity.');
        $search = fb_search_blob($fields, $normalized['data']);
        $persistedSource = $normalized['source'];
        $persistedSource['_form_builder_import'] = ['namespace'=>$sourceNamespace,'key'=>$sourceKey];
        $insert = $pdo->prepare('INSERT INTO fb_submissions (form_id,reference_code,workflow_status,notes_json,history_json,source_json,idempotency_key,data_json,files_json,totals_json,search_blob,ip,is_read,is_deleted,created_at,updated_at) VALUES (?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?,?)');
        $insert->execute([$formId,$normalized['reference_code'],$normalized['workflow_status'],fb_json_encode($normalized['notes']),fb_json_encode($normalized['history']),fb_json_encode($persistedSource),fb_json_encode($normalized['data']),$normalized['files'] ? fb_json_encode($normalized['files']) : null,fb_json_encode($normalized['totals']),$search,$normalized['ip'],$normalized['is_read'] ? 1 : 0,$normalized['is_deleted'] ? 1 : 0,$normalized['created_at'],$normalized['updated_at']]);
        $submissionId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO fb_submission_imports (source_namespace,source_key,payload_sha256,form_id,submission_id,reference_code) VALUES (?,?,?,?,?,?)')->execute([$sourceNamespace,$sourceKey,$payloadHash,$formId,$submissionId,$normalized['reference_code']]);
        $pdo->commit(); return ['submission_id'=>$submissionId,'reference_code'=>$normalized['reference_code'],'reconciled'=>false,'sha256'=>$payloadHash];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
