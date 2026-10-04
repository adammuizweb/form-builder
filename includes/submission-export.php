<?php
declare(strict_types=1);

function fb_submission_export_support(): array {
    $requiredExtensions = [
        'ctype', 'dom', 'fileinfo', 'filter', 'gd', 'iconv', 'libxml', 'mbstring',
        'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib',
    ];
    $missing = array_values(array_filter($requiredExtensions, static fn(string $extension): bool => !extension_loaded($extension)));
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) return ['available'=>false, 'autoload'=>$autoload, 'message'=>'PhpSpreadsheet is not bundled with this plugin package.'];
    if (PHP_INT_SIZE !== 8) return ['available'=>false, 'autoload'=>$autoload, 'message'=>'Excel export requires 64-bit PHP.'];
    if ($missing !== []) return ['available'=>false, 'autoload'=>$autoload, 'message'=>'Missing PHP extensions: ' . implode(', ', $missing) . '.'];
    return ['available'=>true, 'autoload'=>$autoload, 'message'=>''];
}

function fb_submission_export_columns(array $fields, array $types, array $settings): array {
    $columns = [
        ['key'=>'reference_code', 'label'=>'Reference', 'type'=>'text', 'width'=>22],
        ['key'=>'workflow_status', 'label'=>'Workflow', 'type'=>'text', 'width'=>16],
        ['key'=>'created_at', 'label'=>'Submitted', 'type'=>'datetime', 'width'=>20],
        ['key'=>'updated_at', 'label'=>'Updated', 'type'=>'datetime', 'width'=>20],
        ['key'=>'ip', 'label'=>'IP Address', 'type'=>'text', 'width'=>18],
    ];
    foreach ($fields as $field) {
        $meta = $types[(string)$field['type']] ?? null;
        if ($meta === null || empty($meta['input']) || !empty($field['is_hidden'])) continue;
        $isFile = !empty($meta['file']);
        $fieldType = (string)$field['type'];
        $fileColumns = $isFile ? fb_upload_max_files($field) : 1;
        for ($fileIndex = 0; $fileIndex < $fileColumns; $fileIndex++) {
            $columns[] = [
                'key'=>'field:' . $field['field_key'] . ($fileIndex > 0 ? ':' . ($fileIndex + 1) : ''),
                'field'=>$field,
                'attachment_index'=>$isFile ? $fileIndex : null,
                'label'=>(string)($field['label'] ?: $field['field_key']) . ($isFile ? ($fileColumns > 1 ? ' (file ' . ($fileIndex + 1) . ')' : ' (file)') : ''),
                'type'=>$isFile ? 'attachment' : 'text',
                'width'=>$isFile ? 36 : (in_array($fieldType, ['textarea','checkbox'], true) ? 42 : ($fieldType === 'email' ? 32 : 24)),
            ];
        }
    }
    if (($settings['show_total'] ?? '0') === '1') {
        $columns[] = ['key'=>'total', 'label'=>(string)$settings['total_label'], 'type'=>'number', 'width'=>16];
    }
    return $columns;
}

function fb_submission_export_admin_base_url(PDO $pdo): string {
    $siteUrl = trim((string)settings_get($pdo, 'site_url', ''));
    if ($siteUrl === '' || filter_var($siteUrl, FILTER_VALIDATE_URL) === false) return '';
    $parts = parse_url($siteUrl);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)
        || !is_string($parts['host'] ?? null) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) return '';
    $host = str_contains($parts['host'], ':') ? '[' . trim($parts['host'], '[]') . ']' : $parts['host'];
    $origin = strtolower((string)$parts['scheme']) . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    $adminPath = defined('ADMIN_BASE_PATH') ? (string)ADMIN_BASE_PATH : '/' . trim((string)settings_get($pdo, 'admin_path', 'dashboard'), '/');
    if (preg_match('#\A/[A-Za-z0-9/_-]*\z#', $adminPath) !== 1) return '';
    return $origin . rtrim($adminPath, '/') . '/';
}

function fb_submission_export_attachment_url(string $adminBaseUrl, int $formId, int $submissionId, string $fieldKey, int $fileIndex): string {
    if ($adminBaseUrl === '' || filter_var($adminBaseUrl, FILTER_VALIDATE_URL) === false || $formId < 1 || $submissionId < 1
        || preg_match('/\A[a-z0-9][a-z0-9_]{0,79}\z/', $fieldKey) !== 1 || $fileIndex < 0 || $fileIndex >= FB_UPLOAD_MAX_FILES) return '';
    return rtrim($adminBaseUrl, '/') . '/?' . http_build_query([
        'page'=>'admin/tools/form-builder',
        'view'=>'submissions',
        'id'=>$formId,
        'action'=>'file',
        'sid'=>$submissionId,
        'fkey'=>$fieldKey,
        'file'=>$fileIndex,
    ], '', '&', PHP_QUERY_RFC3986);
}

function fb_submission_export_record(array $submission, array $columns, string $adminBaseUrl = ''): array {
    $data = json_decode((string)($submission['data_json'] ?? ''), true);
    $files = json_decode((string)($submission['files_json'] ?? ''), true);
    $totals = json_decode((string)($submission['totals_json'] ?? ''), true);
    if (!is_array($data)) $data = [];
    if (!is_array($files)) $files = [];
    if (!is_array($totals)) $totals = [];
    $record = [
        'reference_code'=>(string)($submission['reference_code'] ?? ''),
        'workflow_status'=>(string)($submission['workflow_status'] ?? ''),
        'created_at'=>(string)($submission['created_at'] ?? ''),
        'updated_at'=>(string)($submission['updated_at'] ?? ''),
        'ip'=>(string)($submission['ip'] ?? ''),
        'total'=>(int)($totals['total'] ?? 0),
    ];
    $attachmentCache = [];
    foreach ($columns as $column) {
        if (!isset($column['field'])) continue;
        $field = $column['field'];
        $key = (string)$field['field_key'];
        if (in_array((string)$field['type'], ['file','image'], true)) {
            if (!isset($attachmentCache[$key])) $attachmentCache[$key] = array_values(array_filter(fb_normalize_stored_attachments($files[$key] ?? null), 'is_array'));
            $attachmentIndex = (int)($column['attachment_index'] ?? 0);
            $attachment = $attachmentCache[$key][$attachmentIndex] ?? null;
            $value = is_array($attachment) ? (string)($attachment['original'] ?? '') : '';
            $record[$column['key'] . ':url'] = $value !== '' ? fb_submission_export_attachment_url(
                $adminBaseUrl,
                (int)($submission['form_id'] ?? 0),
                (int)($submission['id'] ?? 0),
                $key,
                $attachmentIndex
            ) : '';
        } else {
            $value = $data[$key] ?? '';
            if (in_array((string)$field['type'], ['select','radio','checkbox'], true)) {
                $labels = [];
                foreach (fb_field_options($field) as $option) $labels[(string)$option['value']] = (string)$option['label'];
                if (is_array($value)) $value = implode(', ', array_map(static fn(mixed $item): string => $labels[(string)$item] ?? (string)$item, $value));
                elseif (is_scalar($value)) $value = $labels[(string)$value] ?? (string)$value;
            } elseif (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            if ((string)$field['type'] === 'country' && is_string($value) && ($country = fb_country($value)) !== null) {
                $value = $country['name'] . ' (' . strtoupper($value) . ')';
            }
        }
        $record[$column['key']] = is_scalar($value) ? (string)$value : '';
    }
    return $record;
}

function fb_submission_csv_cell(mixed $value): string {
    $cell = $value === null ? '' : (string)$value;
    $cell = str_replace(["\0", "\r", "\n"], ['', ' ', ' '], $cell);
    return preg_match('/^[\p{Z}\x00-\x20]*[=+\-@]/u', $cell) === 1 ? "'" . $cell : $cell;
}
