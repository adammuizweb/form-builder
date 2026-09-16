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
        $columns[] = [
            'key'=>'field:' . $field['field_key'],
            'field'=>$field,
            'label'=>(string)($field['label'] ?: $field['field_key']) . ($isFile ? ' (file)' : ''),
            'type'=>'text',
            'width'=>in_array($fieldType, ['textarea','checkbox'], true) ? 42 : ($fieldType === 'email' ? 32 : 24),
        ];
    }
    if (($settings['show_total'] ?? '0') === '1') {
        $columns[] = ['key'=>'total', 'label'=>(string)$settings['total_label'], 'type'=>'number', 'width'=>16];
    }
    return $columns;
}

function fb_submission_export_record(array $submission, array $columns): array {
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
    foreach ($columns as $column) {
        if (!isset($column['field'])) continue;
        $field = $column['field'];
        $key = (string)$field['field_key'];
        if (in_array((string)$field['type'], ['file','image'], true)) {
            $value = (string)($files[$key]['original'] ?? '');
        } else {
            $value = $data[$key] ?? '';
            if (is_array($value)) $value = implode(', ', array_map('strval', $value));
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
