<?php
// /plugins/form-builder/admin/submissions.php  ($form, $pdo, $uid, $csrf in scope)
declare(strict_types=1);

$formId = (int)$form['id'];
$settings = fb_form_settings($form);
$fields = fb_flat_fields(fb_get_fields($pdo, $formId));
$types = fb_field_types();
$canManageSubmissions = fb_can_manage_submissions($pdo, $form, $uid);
$canWorkflow = $canManageSubmissions && user_can($pdo, $uid, 'plugin.form-builder.workflow.manage');
$canEditForm = fb_can_access_form($pdo, $form, $uid);
$xlsxSupport = fb_submission_export_support();
$workflowStatuses = $settings['workflow_statuses'];
$optionFields = array_values(array_filter($fields, static fn($f) => in_array($f['type'], ['select', 'radio', 'checkbox'], true) && empty($f['is_hidden'])));
$inputFields = array_values(array_filter($fields, static fn($f) => !empty($types[$f['type']]['input']) && empty($types[$f['type']]['file']) && empty($f['is_hidden'])));

// Columns: configured or auto (first 4 input fields)
$columns = [];
foreach ($settings['columns'] as $k) {
    foreach ($inputFields as $f) if ($f['field_key'] === $k) $columns[] = $f;
}
if (!$columns) $columns = array_slice($inputFields, 0, 4);

// ---------------- File streaming ----------------
if (($_GET['action'] ?? '') === 'file') {
    $sid = (int)($_GET['sid'] ?? 0);
    $fkey = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['fkey'] ?? ''));
    $st = $pdo->prepare('SELECT files_json FROM `fb_submissions` WHERE id = ? AND form_id = ? LIMIT 1');
    $st->execute([$sid, $formId]);
    $fj = json_decode((string)($st->fetchColumn() ?: ''), true);
    $info = is_array($fj) ? ($fj[$fkey] ?? null) : null;
    $rel = is_array($info) ? (string)($info['stored'] ?? '') : '';
    if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) { http_response_code(404); exit('File not found'); }
    $path = fb_contained_path(fb_files_base_dir($form), $rel, true);
    if ($path === null || !is_file($path)) { http_response_code(404); exit('File not found'); }
    $mime = is_string($info['mime'] ?? null) && preg_match('#\A[a-z0-9.+-]+/[a-z0-9.+-]+\z#i', $info['mime']) ? $info['mime'] : 'application/octet-stream';
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($info['original'] ?? 'file')) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

// ---------------- Filters ----------------
$isExport = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['fb_action'] ?? '') === 'export');
$filterInput = $isExport ? $_POST : $_GET;
$q = trim((string)($filterInput['q'] ?? ''));
$df = trim((string)($filterInput['df'] ?? ''));
$dt = trim((string)($filterInput['dt'] ?? ''));
$state = (string)($filterInput['st'] ?? 'all');
if (!in_array($state, ['all', 'new', 'read', 'trash'], true)) $state = 'all';
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;

$where = ['form_id = :fid'];
$params = [':fid' => $formId];
$workflow = (string)($filterInput['workflow'] ?? '');
if ($workflow !== '' && in_array($workflow, $workflowStatuses, true)) { $where[] = 'workflow_status = :workflow'; $params[':workflow'] = $workflow; }
if ($state === 'trash') $where[] = 'is_deleted = 1';
else {
    $where[] = 'is_deleted = 0';
    if ($state === 'new') $where[] = 'is_read = 0';
    if ($state === 'read') $where[] = 'is_read = 1';
}
if ($q !== '') { $where[] = '(search_blob LIKE :q OR reference_code LIKE :q)'; $params[':q'] = '%' . $q . '%'; }
if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $df)) { $where[] = 'created_at >= :df'; $params[':df'] = $df . ' 00:00:00'; }
if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $dt)) { $where[] = 'created_at <= :dt'; $params[':dt'] = $dt . ' 23:59:59'; }

// Auto option-field filters: ff_{key}
foreach ($optionFields as $of) {
    $k = (string)$of['field_key'];
    $v = trim((string)($filterInput['ff_' . $k] ?? ''));
    if ($v === '') continue;
    if ($of['type'] === 'checkbox') {
        $where[] = "JSON_CONTAINS(data_json, :jv_{$k}, :jp_{$k})";
        $params[':jv_' . $k] = json_encode($v);
        $params[':jp_' . $k] = '$."' . $k . '"';
    } else {
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(data_json, :jp_{$k})) = :jv_{$k}";
        $params[':jp_' . $k] = '$."' . $k . '"';
        $params[':jv_' . $k] = $v;
    }
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// ---------------- Spreadsheet export ----------------
if ($isExport) {
    if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $format = strtolower(trim((string)($_POST['format'] ?? 'xlsx')));
    if (!in_array($format, ['xlsx', 'csv'], true)) {
        http_response_code(422);
        exit('Invalid export format');
    }
    if ($format === 'xlsx') {
        $support = fb_submission_export_support();
        if (!$support['available']) {
            http_response_code(503);
            exit('Excel export is unavailable. ' . $support['message'] . ' Use CSV export instead.');
        }
        require_once $support['autoload'];
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            http_response_code(503);
            exit('Excel export is unavailable because PhpSpreadsheet could not be loaded.');
        }
        $countExport = $pdo->prepare("SELECT COUNT(*) FROM `fb_submissions` {$whereSql}");
        $countExport->execute($params);
        if ((int)$countExport->fetchColumn() > 10000) {
            http_response_code(413);
            exit('Excel export is limited to 10,000 filtered submissions. Narrow the filters or use CSV.');
        }
    }
    $st = $pdo->prepare("SELECT * FROM `fb_submissions` {$whereSql} ORDER BY created_at DESC");
    $st->execute($params);
    $exportColumns = fb_submission_export_columns($fields, $types, $settings);
    $headers = array_column($exportColumns, 'label');
    $filenameBase = preg_replace('/[^a-z0-9-]/', '-', strtolower((string)$form['slug'])) . '-submissions-' . date('Ymd-His');
    while (ob_get_level() > 0) @ob_end_clean();
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
        $out = fopen('php://output', 'wb');
        if ($out === false) { http_response_code(500); exit('Unable to create export'); }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map('fb_submission_csv_cell', $headers), ';', '"', '');
        while ($submission = $st->fetch(PDO::FETCH_ASSOC)) {
            $record = fb_submission_export_record($submission, $exportColumns);
            $row = array_map(static fn(array $column): mixed => $record[$column['key']] ?? '', $exportColumns);
            fputcsv($out, array_map('fb_submission_csv_cell', $row), ';', '"', '');
        }
        fclose($out);
        exit;
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()->setCreator('Jyavani Form Builder')->setTitle((string)$form['title'] . ' Submissions')->setSubject('Filtered form submissions');
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Submissions');
    foreach ($headers as $index => $header) {
        $columnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $sheet->setCellValueExplicit($columnName . '1', (string)$header, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $rowNumber = 2;
    while ($submission = $st->fetch(PDO::FETCH_ASSOC)) {
        $record = fb_submission_export_record($submission, $exportColumns);
        foreach ($exportColumns as $index => $definition) {
            $columnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $value = $record[$definition['key']] ?? '';
            if ($definition['type'] === 'datetime' && ($timestamp = strtotime((string)$value)) !== false) {
                $sheet->setCellValue($columnName . $rowNumber, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($timestamp));
            } elseif ($definition['type'] === 'number') {
                $sheet->setCellValue($columnName . $rowNumber, (float)$value);
            } else {
                $sheet->setCellValueExplicit($columnName . $rowNumber, (string)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $rowNumber++;
    }
    $lastRow = max(1, $rowNumber - 1);
    $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($exportColumns));
    $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray([
        'font'=>['bold'=>true, 'color'=>['argb'=>'FFFFFFFF']],
        'fill'=>['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor'=>['argb'=>'FF1F6B45']],
        'alignment'=>['horizontal'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(25);
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:' . $lastColumn . $lastRow);
    $sheet->getSheetView()->setZoomScale(90);
    foreach ($exportColumns as $index => $definition) {
        $columnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $sheet->getColumnDimension($columnName)->setWidth((float)$definition['width']);
        if ($lastRow < 2) continue;
        $range = $columnName . '2:' . $columnName . $lastRow;
        if ($definition['type'] === 'text') $sheet->getStyle($range)->getAlignment()->setWrapText(true);
        if ($definition['type'] === 'datetime') $sheet->getStyle($range)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        if ($definition['type'] === 'number') $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0');
    }
    if ($lastRow >= 2) {
        $dataRange = 'A2:' . $lastColumn . $lastRow;
        $sheet->getStyle($dataRange)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
        $sheet->getStyle($dataRange)->getBorders()->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_HAIR)->getColor()->setARGB('FFD7E5DD');
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
    header('Content-Transfer-Encoding: binary');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(false);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
}

// ---------------- Bulk POST ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf_token'] ?? '')) {
        echo '<div class="fba-empty">Invalid CSRF token.</div>';
        return;
    }
    $act = (string)($_POST['fb_action'] ?? '');
    if (!$canManageSubmissions) {
        echo '<div class="fba-empty">Access denied.</div>';
        return;
    }
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))))), 0, 200);
    if (!$ids && isset($_POST['id_one'])) $ids = [(int)$_POST['id_one']];
    if ($ids && in_array($act, ['read', 'unread', 'trash', 'restore', 'delete'], true)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $args = array_merge($ids, [$formId]);
        switch ($act) {
            case 'read':    $pdo->prepare("UPDATE `fb_submissions` SET is_read = 1 WHERE id IN ({$in}) AND form_id = ?")->execute($args); break;
            case 'unread':  $pdo->prepare("UPDATE `fb_submissions` SET is_read = 0 WHERE id IN ({$in}) AND form_id = ?")->execute($args); break;
            case 'trash':   $pdo->prepare("UPDATE `fb_submissions` SET is_deleted = 1 WHERE id IN ({$in}) AND form_id = ?")->execute($args); break;
            case 'restore': $pdo->prepare("UPDATE `fb_submissions` SET is_deleted = 0 WHERE id IN ({$in}) AND form_id = ?")->execute($args); break;
            case 'delete':
                $st = $pdo->prepare("SELECT files_json FROM `fb_submissions` WHERE id IN ({$in}) AND form_id = ?");
                $st->execute($args);
                $root = fb_files_base_dir($form);
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $fj = json_decode((string)($r['files_json'] ?? ''), true);
                    if (is_array($fj)) foreach ($fj as $info) {
                        $rel = (string)($info['stored'] ?? '');
                        $path = fb_contained_path($root, $rel, true);
                        if ($rel !== '' && $path === null) throw new RuntimeException('Refusing unsafe private attachment path.');
                        if ($path !== null && is_file($path) && !unlink($path)) throw new RuntimeException('Unable to remove private attachment.');
                    }
                }
                $pdo->prepare("DELETE FROM `fb_submissions` WHERE id IN ({$in}) AND form_id = ?")->execute($args);
                break;
        }
        fb_js_redirect(fb_url());
        return;
    }
    if ($ids && $canWorkflow && ($act === 'workflow' || $act === 'note')) {
        $next = strtolower(trim((string)($_POST['workflow_status'] ?? '')));
        $note = trim((string)($_POST['reviewer_note'] ?? ''));
        if (($act === 'workflow' && !in_array($next, $workflowStatuses, true)) || mb_strlen($note) > 4000 || ($act === 'note' && $note === '')) { echo '<div class="fba-empty">Invalid workflow update.</div>'; return; }
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare('SELECT id,workflow_status,notes_json,history_json FROM fb_submissions WHERE id = ? AND form_id = ? FOR UPDATE');
            $update = $pdo->prepare('UPDATE fb_submissions SET workflow_status=?,notes_json=?,history_json=?,updated_at=NOW() WHERE id=? AND form_id=?');
            foreach ($ids as $sid) {
                $select->execute([$sid,$formId]); $submission = $select->fetch(PDO::FETCH_ASSOC); if (!$submission) continue;
                $notes = json_decode((string)$submission['notes_json'], true); if (!is_array($notes)) $notes = [];
                $history = json_decode((string)$submission['history_json'], true); if (!is_array($history)) $history = [];
                $target = $act === 'workflow' ? $next : (string)$submission['workflow_status'];
                $at = gmdate('c');
                if ($note !== '') $notes[] = ['at'=>$at,'actor'=>$uid,'text'=>$note];
                $history[] = ['at'=>$at,'actor'=>$uid,'from'=>$submission['workflow_status'],'to'=>$target,'note_added'=>$note !== ''];
                $update->execute([$target,fb_json_encode($notes),fb_json_encode($history),$sid,$formId]);
            }
            $pdo->commit();
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
        fb_js_redirect(fb_url()); return;
    }
}

// ---------------- List ----------------
$countSt = $pdo->prepare("SELECT COUNT(*) FROM `fb_submissions` {$whereSql}");
$countSt->execute($params);
$totalRows = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$pageNum = min($pageNum, $totalPages);
$offset = ($pageNum - 1) * $perPage;

$listSt = $pdo->prepare("SELECT * FROM `fb_submissions` {$whereSql} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$listSt->execute($params);
$rows = $listSt->fetchAll(PDO::FETCH_ASSOC);

$detail = null;
if (isset($_GET['detail'])) {
    $st = $pdo->prepare('SELECT * FROM `fb_submissions` WHERE id = ? AND form_id = ? LIMIT 1');
    $st->execute([(int)$_GET['detail'], $formId]);
    $detail = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stats = [
    'total' => (int)$pdo->query("SELECT COUNT(*) FROM `fb_submissions` WHERE form_id = {$formId} AND is_deleted = 0")->fetchColumn(),
    'new'   => (int)$pdo->query("SELECT COUNT(*) FROM `fb_submissions` WHERE form_id = {$formId} AND is_deleted = 0 AND is_read = 0")->fetchColumn(),
];
$accessible = fb_accessible_forms($pdo, "status != 'archived'", 'submissions');

function fb_render_value(array $field, mixed $v, int $sid, array $filesJ, string $formIdStr): string {
    $type = (string)$field['type'];
    if ($type === 'image') {
        $info = $filesJ[$field['field_key']] ?? null;
        if (!is_array($info)) return '&mdash;';
        $url = '?page=admin/tools/form-builder&view=submissions&id=' . $formIdStr . '&action=file&sid=' . $sid . '&fkey=' . rawurlencode((string)$field['field_key']);
        return '<a href="' . $url . '" target="_blank"><img src="' . $url . '" alt="" style="max-width:220px;max-height:160px;border-radius:10px;box-shadow:0 4px 14px rgba(0 0 0 / .15)"></a>';
    }
    if ($type === 'file') {
        $info = $filesJ[$field['field_key']] ?? null;
        if (!is_array($info)) return '&mdash;';
        $url = '?page=admin/tools/form-builder&view=submissions&id=' . $formIdStr . '&action=file&sid=' . $sid . '&fkey=' . rawurlencode((string)$field['field_key']);
        return '<a class="fba-btn sm" href="' . $url . '" target="_blank">' . htmlspecialchars((string)($info['original'] ?? 'file'), ENT_QUOTES) . '</a>';
    }
    if (is_array($v)) return htmlspecialchars(implode(', ', array_map('strval', $v)), ENT_QUOTES);
    if ($type === 'email' && $v !== '') return '<a href="mailto:' . htmlspecialchars((string)$v, ENT_QUOTES) . '">' . htmlspecialchars((string)$v, ENT_QUOTES) . '</a>';
    if ($type === 'intl_phone' && $v !== '') return '<a href="tel:' . htmlspecialchars((string)$v, ENT_QUOTES) . '">' . htmlspecialchars((string)$v, ENT_QUOTES) . '</a>';
    if ($type === 'country' && is_string($v) && ($country = fb_country($v)) !== null) return htmlspecialchars($country['name'] . ' (' . strtoupper($v) . ')', ENT_QUOTES);
    return nl2br(htmlspecialchars((string)$v, ENT_QUOTES));
}

if (isset($_GET['detail'])):
    if ($detail === null): ?>
      <div class="fba"><div class="fba-empty">Submission not found. <a href="<?= fb_url(['detail'=>null]) ?>">Back to submissions</a></div></div>
    <?php return; endif;
    $sid = (int)$detail['id'];
    $data = json_decode((string)$detail['data_json'], true) ?: [];
    $filesJ = json_decode((string)$detail['files_json'], true) ?: [];
    $tot = json_decode((string)$detail['totals_json'], true) ?: [];
    $reviewNotes = json_decode((string)$detail['notes_json'], true);
    ?>
<div class="fba">
  <div class="fba-head">
    <div><span class="fba-sub">Submission detail</span><h1><?= htmlspecialchars((string)$detail['reference_code'], ENT_QUOTES) ?></h1></div>
    <div class="fba-actions"><a class="fba-btn" href="<?= fb_url(['detail'=>null]) ?>"><?= svg_ico('arrow-left') ?> Back to submissions</a></div>
  </div>
  <div class="fba-detail-layout">
    <div>
      <div class="fba-card">
        <div class="fba-sec">Submitted data</div>
        <div class="fba-detail-fields">
          <?php foreach ($fields as $field):
            if (!empty($types[$field['type']]['display']) || !empty($field['is_hidden'])) continue;
            $wide = in_array((string)$field['type'], ['textarea','checkbox','file','image'], true); ?>
          <div class="fba-detail-field<?= $wide ? ' wide' : '' ?>">
            <label><?= htmlspecialchars((string)($field['label'] ?: $field['field_key']), ENT_QUOTES) ?></label>
            <div class="fba-detail-value"><?= fb_render_value($field, $data[$field['field_key']] ?? '', $sid, $filesJ, (string)$formId) ?></div>
          </div>
          <?php endforeach; ?>
          <?php if ($settings['show_total'] === '1'): ?><div class="fba-detail-field"><label><?= htmlspecialchars($settings['total_label'], ENT_QUOTES) ?></label><div class="fba-detail-value"><strong class="fba-mono"><?= fb_format_currency((int)($tot['total'] ?? 0), (string)$settings['currency_code']) ?></strong></div></div><?php endif; ?>
        </div>
      </div>
      <?php if (is_array($reviewNotes) && $reviewNotes): ?><div class="fba-card"><div class="fba-sec">Reviewer notes</div><div class="fba-notes"><?php foreach ($reviewNotes as $reviewNote): ?><div class="fba-note"><time><?= htmlspecialchars((string)($reviewNote['at'] ?? ''), ENT_QUOTES) ?></time><div><?= nl2br(htmlspecialchars((string)($reviewNote['text'] ?? ''), ENT_QUOTES)) ?></div></div><?php endforeach; ?></div></div><?php endif; ?>
    </div>
    <aside class="fba-detail-side">
      <div class="fba-card">
        <div class="fba-sec">Summary</div>
        <div class="fba-detail-meta">
          <div><span>Workflow</span><strong><?= htmlspecialchars(ucfirst((string)$detail['workflow_status']), ENT_QUOTES) ?></strong></div>
          <div><span>State</span><strong><?= (int)$detail['is_deleted'] ? 'Trash' : ((int)$detail['is_read'] ? 'Read' : 'New') ?></strong></div>
          <div><span>Submitted</span><strong><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$detail['created_at'])), ENT_QUOTES) ?></strong></div>
          <div><span>Updated</span><strong><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$detail['updated_at'])), ENT_QUOTES) ?></strong></div>
          <div><span>IP address</span><strong class="fba-mono"><?= htmlspecialchars((string)$detail['ip'], ENT_QUOTES) ?></strong></div>
        </div>
      </div>
      <?php if ($canWorkflow): ?><div class="fba-card"><div class="fba-sec">Review</div><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>"><input type="hidden" name="id_one" value="<?= $sid ?>"><input type="hidden" name="fb_action" value="note"><div class="fba-field"><label>Append reviewer note</label><textarea name="reviewer_note" maxlength="4000" required></textarea></div><button class="fba-btn primary" type="submit">Add note</button></form><div class="fba-sec">Workflow</div><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>"><input type="hidden" name="id_one" value="<?= $sid ?>"><input type="hidden" name="fb_action" value="workflow"><div class="fba-field"><label>Change status</label><select name="workflow_status"><?php foreach ($workflowStatuses as $status): ?><option value="<?= htmlspecialchars($status, ENT_QUOTES) ?>" <?= $detail['workflow_status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($status), ENT_QUOTES) ?></option><?php endforeach; ?></select></div><button class="fba-btn primary" type="submit">Update status</button></form></div><?php endif; ?>
    </aside>
  </div>
</div>
<?php return; endif; ?>
?>
<div class="fba">
  <div class="fba-head">
    <h1>Submissions: <?= htmlspecialchars($form['title'], ENT_QUOTES) ?></h1>
    <div class="fba-actions">
      <a class="fba-btn" href="<?= fb_url(['view' => 'forms', 'id' => null]) ?>"><?= svg_ico('arrow-left') ?> Forms</a>
      <?php if ($canEditForm): ?><a class="fba-btn" href="<?= fb_url(['view' => 'builder', 'id' => $formId]) ?>"><?= svg_ico('pen') ?> Builder</a>
      <a class="fba-btn" href="<?= fb_url(['view' => 'settings', 'id' => $formId]) ?>"><?= svg_ico('settings') ?> Settings</a><?php endif; ?>
      <form method="post" class="fba-export">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="export">
        <input type="hidden" name="fb_action" value="export">
        <input type="hidden" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
        <input type="hidden" name="df" value="<?= htmlspecialchars($df, ENT_QUOTES) ?>">
        <input type="hidden" name="dt" value="<?= htmlspecialchars($dt, ENT_QUOTES) ?>">
        <input type="hidden" name="st" value="<?= htmlspecialchars($state, ENT_QUOTES) ?>">
        <input type="hidden" name="workflow" value="<?= htmlspecialchars($workflow, ENT_QUOTES) ?>">
        <?php foreach ($optionFields as $optionField): $optionKey = (string)$optionField['field_key']; ?><input type="hidden" name="ff_<?= htmlspecialchars($optionKey, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string)($filterInput['ff_' . $optionKey] ?? ''), ENT_QUOTES) ?>"><?php endforeach; ?>
        <?php if ($xlsxSupport['available']): ?><button class="fba-btn primary" name="format" value="xlsx" type="submit"><?= svg_ico('download') ?> Export Excel</button><?php endif; ?>
        <button class="fba-btn" name="format" value="csv" type="submit">CSV</button>
        <?php if (!$xlsxSupport['available']): ?><span class="fba-hint" title="<?= htmlspecialchars($xlsxSupport['message'], ENT_QUOTES) ?>">Excel unavailable</span><?php endif; ?>
      </form>
    </div>
  </div>

  <div class="fba-card fba-submission-summary<?= count($accessible) > 1 ? ' has-switch' : '' ?>">
    <div class="fba-stat"><?= svg_ico('clipboard-list') ?><div><strong><?= $stats['total'] ?></strong><span class="fba-sub">Total submissions</span></div></div>
    <div class="fba-stat"><?= svg_ico('mail') ?><div><strong><?= $stats['new'] ?></strong><span class="fba-sub">New submissions</span></div></div>
    <?php if (count($accessible) > 1): ?>
    <form method="get" class="fba-form-switch">
      <input type="hidden" name="page" value="admin/tools/form-builder">
      <input type="hidden" name="view" value="submissions">
      <select name="id" aria-label="Choose form" onchange="this.form.submit()">
        <?php foreach ($accessible as $af): ?>
        <option value="<?= (int)$af['id'] ?>" <?= (int)$af['id'] === $formId ? 'selected' : '' ?>><?= htmlspecialchars($af['title'], ENT_QUOTES) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>

  <div class="fba-toolbar fba-submissions-toolbar">
    <div class="fba-state-tabs">
      <?php foreach (['all' => 'All', 'new' => 'New', 'read' => 'Read', 'trash' => 'Trash'] as $k => $lbl): ?>
      <a class="fba-btn sm <?= $state === $k ? 'primary' : '' ?>" href="<?= fb_url(['st' => $k, 'p' => 1, 'detail' => null]) ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get" class="fba-filter-form">
      <input type="hidden" name="page" value="admin/tools/form-builder">
      <input type="hidden" name="view" value="submissions">
      <input type="hidden" name="id" value="<?= $formId ?>">
      <input type="hidden" name="st" value="<?= htmlspecialchars($state, ENT_QUOTES) ?>">
      <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Search submissions" aria-label="Search submissions">
      <select name="workflow" aria-label="Workflow status"><option value="">All workflow statuses</option><?php foreach ($workflowStatuses as $ws): ?><option value="<?= htmlspecialchars($ws, ENT_QUOTES) ?>" <?= $workflow === $ws ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($ws), ENT_QUOTES) ?></option><?php endforeach; ?></select>
      <input type="date" name="df" value="<?= htmlspecialchars($df, ENT_QUOTES) ?>" title="From" aria-label="Submitted from">
      <input type="date" name="dt" value="<?= htmlspecialchars($dt, ENT_QUOTES) ?>" title="To" aria-label="Submitted through">
      <?php foreach ($optionFields as $of):
        $k = (string)$of['field_key']; ?>
      <select name="ff_<?= htmlspecialchars($k, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($of['label'], ENT_QUOTES) ?> filter">
        <option value=""><?= htmlspecialchars($of['label'], ENT_QUOTES) ?>: all</option>
        <?php foreach (fb_field_options($of) as $o): ?>
        <option value="<?= htmlspecialchars($o['value'], ENT_QUOTES) ?>" <?= (($_GET['ff_' . $k] ?? '') === $o['value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['label'], ENT_QUOTES) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endforeach; ?>
      <button class="fba-btn sm primary" type="submit">Filter</button>
      <a class="fba-btn sm" href="<?= fb_url(['q' => null, 'df' => null, 'dt' => null, 'st' => 'all', 'workflow' => null, 'p' => 1, 'detail' => null] + array_fill_keys(array_map(static fn($f) => 'ff_' . $f['field_key'], $optionFields), null)) ?>">Reset</a>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="fba-empty">No submissions match.</div>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <div class="fba-table-wrap">
      <table class="fba-table">
        <thead><tr>
          <?php if ($canManageSubmissions): ?><th style="width:30px"><input type="checkbox" aria-label="Select all submissions" onclick="document.querySelectorAll('.fba-row-check').forEach(c=>c.checked=this.checked)"></th><?php endif; ?>
          <th>Ref</th>
          <?php foreach ($columns as $c): ?><th><?= htmlspecialchars($c['label'], ENT_QUOTES) ?></th><?php endforeach; ?>
          <?php if ($settings['show_total'] === '1'): ?><th><?= htmlspecialchars($settings['total_label'], ENT_QUOTES) ?></th><?php endif; ?>
          <th>Date</th><th>Workflow</th><th>State</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $sid = (int)$r['id'];
          $data = json_decode((string)$r['data_json'], true) ?: [];
          $tot = json_decode((string)$r['totals_json'], true) ?: [];
          ?>
          <tr class="<?= (int)$r['is_deleted'] ? '' : ((int)$r['is_read'] ? '' : 'unread') ?>">
            <?php if ($canManageSubmissions): ?><td><input type="checkbox" class="fba-row-check" name="ids[]" value="<?= $sid ?>" aria-label="Select submission <?= htmlspecialchars((string)$r['reference_code'], ENT_QUOTES) ?>"></td><?php endif; ?>
             <td class="fba-mono"><?= htmlspecialchars((string)$r['reference_code'], ENT_QUOTES) ?></td>
            <?php foreach ($columns as $c):
              $v = $data[$c['field_key']] ?? '';
              $txt = is_array($v) ? implode(', ', $v) : (string)$v;
              ?>
            <td><?= htmlspecialchars(mb_strimwidth($txt, 0, 60, '…'), ENT_QUOTES) ?></td>
            <?php endforeach; ?>
            <?php if ($settings['show_total'] === '1'): ?><td class="fba-mono"><?= fb_format_currency((int)($tot['total'] ?? 0), (string)$settings['currency_code']) ?></td><?php endif; ?>
             <td style="white-space:nowrap" class="fba-sub"><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$r['created_at'])), ENT_QUOTES) ?></td>
             <td><span class="fba-badge read"><?= htmlspecialchars(ucfirst((string)$r['workflow_status']), ENT_QUOTES) ?></span></td>
            <td>
              <?php if ((int)$r['is_deleted']): ?><span class="fba-badge trash">Trash</span>
              <?php elseif ((int)$r['is_read']): ?><span class="fba-badge read">Read</span>
              <?php else: ?><span class="fba-badge new">New</span><?php endif; ?>
            </td>
            <td><div class="fba-row-actions">
              <a class="fba-btn sm" href="<?= fb_url(['detail' => $sid]) ?>"><?= svg_ico('eye') ?> View</a>
              <?php if ($canManageSubmissions && (int)$r['is_deleted']): ?>
              <button class="fba-btn sm" name="fb_action" value="restore" onclick="this.form.querySelectorAll('.fba-row-check').forEach(c=>c.checked=false);this.closest('tr').querySelector('.fba-row-check').checked=true">Restore</button>
              <button class="fba-btn sm danger" name="fb_action" value="delete" onclick="return confirm('Delete permanently?')&&(this.form.querySelectorAll('.fba-row-check').forEach(c=>c.checked=false),this.closest('tr').querySelector('.fba-row-check').checked=true,true)">Delete</button>
              <?php elseif ($canManageSubmissions): ?>
              <button class="fba-btn sm danger" name="fb_action" value="trash" onclick="this.form.querySelectorAll('.fba-row-check').forEach(c=>c.checked=false);this.closest('tr').querySelector('.fba-row-check').checked=true">Trash</button>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($canManageSubmissions): ?><div class="fba-toolbar fba-bulk-actions">
      <select name="fb_action" aria-label="Bulk action">
        <option value="read">Mark read</option>
        <option value="unread">Mark unread</option>
        <option value="trash">Move to trash</option>
        <?php if ($state === 'trash'): ?>
        <option value="restore">Restore</option>
        <option value="delete">Delete permanently</option>
        <?php endif; ?>
      </select>
      <?php if ($canWorkflow): ?><select name="workflow_status" aria-label="New workflow status"><?php foreach ($workflowStatuses as $ws): ?><option value="<?= htmlspecialchars($ws, ENT_QUOTES) ?>"><?= htmlspecialchars(ucfirst($ws), ENT_QUOTES) ?></option><?php endforeach; ?></select><button class="fba-btn" name="fb_action" value="workflow" type="submit">Set workflow status</button><?php endif; ?>
      <button class="fba-btn" type="submit">Apply to selected</button>
    </div><?php endif; ?>
  </form>

  <?php if ($totalPages > 1): ?>
  <div class="fba-pager">
    <?php if ($pageNum > 1): ?><a href="<?= fb_url(['p' => $pageNum - 1]) ?>">&laquo;</a><?php endif; ?>
    <?php for ($i = max(1, $pageNum - 3); $i <= min($totalPages, $pageNum + 3); $i++): ?>
      <?php if ($i === $pageNum): ?><span class="cur"><?= $i ?></span>
      <?php else: ?><a href="<?= fb_url(['p' => $i]) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($pageNum < $totalPages): ?><a href="<?= fb_url(['p' => $pageNum + 1]) ?>">&raquo;</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
