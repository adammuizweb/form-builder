<?php
// /plugins/form-builder/admin/submissions.php  ($form, $pdo, $uid, $role, $csrf in scope)
declare(strict_types=1);

$formId = (int)$form['id'];
$settings = fb_form_settings($form);
$fields = fb_get_fields($pdo, $formId);
$types = fb_field_types();
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
    $path = dirname(__DIR__, 3) . '/private_files/form-builder/' . $rel;
    if (!is_file($path)) { http_response_code(404); exit('File not found'); }
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = (string)finfo_file($fi, $path); finfo_close($fi); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($info['original'] ?? 'file')) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

// ---------------- Filters ----------------
$q = trim((string)($_GET['q'] ?? ''));
$df = trim((string)($_GET['df'] ?? ''));
$dt = trim((string)($_GET['dt'] ?? ''));
$state = (string)($_GET['st'] ?? 'all');
if (!in_array($state, ['all', 'new', 'read', 'trash'], true)) $state = 'all';
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;

$where = ['form_id = :fid'];
$params = [':fid' => $formId];
if ($state === 'trash') $where[] = 'is_deleted = 1';
else {
    $where[] = 'is_deleted = 0';
    if ($state === 'new') $where[] = 'is_read = 0';
    if ($state === 'read') $where[] = 'is_read = 1';
}
if ($q !== '') { $where[] = 'search_blob LIKE :q'; $params[':q'] = '%' . $q . '%'; }
if ($df !== '' && strtotime($df) !== false) { $where[] = 'created_at >= :df'; $params[':df'] = $df . ' 00:00:00'; }
if ($dt !== '' && strtotime($dt) !== false) { $where[] = 'created_at <= :dt'; $params[':dt'] = $dt . ' 23:59:59'; }

// Auto option-field filters: ff_{key}
foreach ($optionFields as $of) {
    $k = (string)$of['field_key'];
    $v = trim((string)($_GET['ff_' . $k] ?? ''));
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

// ---------------- CSV export ----------------
if (($_GET['action'] ?? '') === 'export') {
    $st = $pdo->prepare("SELECT * FROM `fb_submissions` {$whereSql} ORDER BY created_at DESC");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]/', '-', (string)$form['slug']) . '-submissions-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $esc = '\\';
    $header = ['Ref', 'Submitted', 'IP'];
    foreach ($inputFields as $f) $header[] = $f['label'];
    $fileFields = array_values(array_filter($fields, static fn($f) => !empty($types[$f['type']]['file'])));
    foreach ($fileFields as $f) $header[] = $f['label'] . ' (file)';
    if ($settings['show_total'] === '1') $header[] = $settings['total_label'];
    fputcsv($out, $header, ',', '"', $esc);
    $n = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $n++;
        $data = json_decode((string)$r['data_json'], true) ?: [];
        $filesJ = json_decode((string)$r['files_json'], true) ?: [];
        $tot = json_decode((string)$r['totals_json'], true) ?: [];
        $row = ['FB-' . $formId . '-' . str_pad((string)$r['id'], 5, '0', STR_PAD_LEFT), $r['created_at'], $r['ip']];
        foreach ($inputFields as $f) {
            $v = $data[$f['field_key']] ?? '';
            $row[] = is_array($v) ? implode(', ', $v) : (string)$v;
        }
        foreach ($fileFields as $f) $row[] = (string)($filesJ[$f['field_key']]['original'] ?? '');
        if ($settings['show_total'] === '1') $row[] = (string)($tot['total'] ?? 0);
        fputcsv($out, $row, ',', '"', $esc);
    }
    fclose($out);
    exit;
}

// ---------------- Bulk POST ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && function_exists('csrf_check') && csrf_check($_POST['csrf_token'] ?? '')) {
    $act = (string)($_POST['fb_action'] ?? '');
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
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
                $root = dirname(__DIR__, 3) . '/private_files/form-builder/';
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $fj = json_decode((string)($r['files_json'] ?? ''), true);
                    if (is_array($fj)) foreach ($fj as $info) {
                        $rel = (string)($info['stored'] ?? '');
                        if ($rel !== '' && !str_contains($rel, '..') && !str_starts_with($rel, '/')) @unlink($root . $rel);
                    }
                }
                $pdo->prepare("DELETE FROM `fb_submissions` WHERE id IN ({$in}) AND form_id = ?")->execute($args);
                break;
        }
        header('Location: ' . fb_url(), true, 303);
        exit;
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
    if ($detail && !(int)$detail['is_read']) {
        $pdo->prepare('UPDATE `fb_submissions` SET is_read = 1 WHERE id = ?')->execute([(int)$detail['id']]);
        $detail['is_read'] = 1;
    }
}

$stats = [
    'total' => (int)$pdo->query("SELECT COUNT(*) FROM `fb_submissions` WHERE form_id = {$formId} AND is_deleted = 0")->fetchColumn(),
    'new'   => (int)$pdo->query("SELECT COUNT(*) FROM `fb_submissions` WHERE form_id = {$formId} AND is_deleted = 0 AND is_read = 0")->fetchColumn(),
];
$accessible = fb_accessible_forms($pdo);

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
    return nl2br(htmlspecialchars((string)$v, ENT_QUOTES));
}
?>
<div class="fba">
  <div class="fba-head">
    <h1>Submissions: <?= htmlspecialchars($form['title'], ENT_QUOTES) ?></h1>
    <div class="fba-actions">
      <a class="fba-btn" href="<?= fb_url(['view' => 'forms', 'id' => null]) ?>">&larr; Forms</a>
      <a class="fba-btn" href="<?= fb_url(['view' => 'builder', 'id' => $formId]) ?>">Builder</a>
      <a class="fba-btn" href="<?= fb_url(['view' => 'settings', 'id' => $formId]) ?>">Settings</a>
      <a class="fba-btn primary" href="<?= fb_url(['action' => 'export', 'p' => null, 'detail' => null]) ?>">Export CSV</a>
    </div>
  </div>

  <div class="fba-card" style="display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap">
    <div><strong><?= $stats['total'] ?></strong> <span class="fba-sub">total</span></div>
    <div><strong><?= $stats['new'] ?></strong> <span class="fba-sub">new</span></div>
    <?php if (count($accessible) > 1): ?>
    <form method="get" style="margin-left:auto">
      <input type="hidden" name="page" value="admin/tools/form-builder">
      <input type="hidden" name="view" value="submissions">
      <select name="id" onchange="this.form.submit()">
        <?php foreach ($accessible as $af): ?>
        <option value="<?= (int)$af['id'] ?>" <?= (int)$af['id'] === $formId ? 'selected' : '' ?>><?= htmlspecialchars($af['title'], ENT_QUOTES) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>

  <div class="fba-toolbar">
    <div style="display:flex;gap:.35rem">
      <?php foreach (['all' => 'All', 'new' => 'New', 'read' => 'Read', 'trash' => 'Trash'] as $k => $lbl): ?>
      <a class="fba-btn sm <?= $state === $k ? 'primary' : '' ?>" href="<?= fb_url(['st' => $k, 'p' => 1, 'detail' => null]) ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get">
      <input type="hidden" name="page" value="admin/tools/form-builder">
      <input type="hidden" name="view" value="submissions">
      <input type="hidden" name="id" value="<?= $formId ?>">
      <input type="hidden" name="st" value="<?= htmlspecialchars($state, ENT_QUOTES) ?>">
      <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Search…" style="width:160px">
      <input type="date" name="df" value="<?= htmlspecialchars($df, ENT_QUOTES) ?>" title="From">
      <input type="date" name="dt" value="<?= htmlspecialchars($dt, ENT_QUOTES) ?>" title="To">
      <?php foreach ($optionFields as $of):
        $k = (string)$of['field_key']; ?>
      <select name="ff_<?= htmlspecialchars($k, ENT_QUOTES) ?>">
        <option value=""><?= htmlspecialchars($of['label'], ENT_QUOTES) ?>: all</option>
        <?php foreach (fb_field_options($of) as $o): ?>
        <option value="<?= htmlspecialchars($o['value'], ENT_QUOTES) ?>" <?= (($_GET['ff_' . $k] ?? '') === $o['value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['label'], ENT_QUOTES) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endforeach; ?>
      <button class="fba-btn sm primary" type="submit">Filter</button>
      <a class="fba-btn sm" href="<?= fb_url(['q' => null, 'df' => null, 'dt' => null, 'p' => 1, 'detail' => null] + array_fill_keys(array_map(static fn($f) => 'ff_' . $f['field_key'], $optionFields), null)) ?>">Reset</a>
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
          <th style="width:30px"><input type="checkbox" onclick="document.querySelectorAll('.fba-row-check').forEach(c=>c.checked=this.checked)"></th>
          <th>Ref</th>
          <?php foreach ($columns as $c): ?><th><?= htmlspecialchars($c['label'], ENT_QUOTES) ?></th><?php endforeach; ?>
          <?php if ($settings['show_total'] === '1'): ?><th><?= htmlspecialchars($settings['total_label'], ENT_QUOTES) ?></th><?php endif; ?>
          <th>Date</th><th>State</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $sid = (int)$r['id'];
          $data = json_decode((string)$r['data_json'], true) ?: [];
          $tot = json_decode((string)$r['totals_json'], true) ?: [];
          ?>
          <tr class="<?= (int)$r['is_deleted'] ? '' : ((int)$r['is_read'] ? '' : 'unread') ?>">
            <td><input type="checkbox" class="fba-row-check" name="ids[]" value="<?= $sid ?>"></td>
            <td class="fba-mono">FB-<?= $formId ?>-<?= str_pad((string)$sid, 5, '0', STR_PAD_LEFT) ?></td>
            <?php foreach ($columns as $c):
              $v = $data[$c['field_key']] ?? '';
              $txt = is_array($v) ? implode(', ', $v) : (string)$v;
              ?>
            <td><?= htmlspecialchars(mb_strimwidth($txt, 0, 60, '…'), ENT_QUOTES) ?></td>
            <?php endforeach; ?>
            <?php if ($settings['show_total'] === '1'): ?><td class="fba-mono"><?= fb_format_rupiah((int)($tot['total'] ?? 0)) ?></td><?php endif; ?>
            <td style="white-space:nowrap" class="fba-sub"><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$r['created_at'])), ENT_QUOTES) ?></td>
            <td>
              <?php if ((int)$r['is_deleted']): ?><span class="fba-badge trash">Trash</span>
              <?php elseif ((int)$r['is_read']): ?><span class="fba-badge read">Read</span>
              <?php else: ?><span class="fba-badge new">New</span><?php endif; ?>
            </td>
            <td style="white-space:nowrap">
              <a class="fba-btn sm" href="<?= fb_url(['detail' => $sid]) ?>">View</a>
              <?php if ((int)$r['is_deleted']): ?>
              <button class="fba-btn sm" name="fb_action" value="restore" onclick="this.form.querySelectorAll('.fba-row-check').forEach(c=>c.checked=false);this.closest('tr').querySelector('.fba-row-check').checked=true">Restore</button>
              <button class="fba-btn sm danger" name="fb_action" value="delete" onclick="return confirm('Delete permanently?')&&(this.form.querySelectorAll('.fba-row-check').forEach(c=>c.checked=false),this.closest('tr').querySelector('.fba-row-check').checked=true,true)">Delete</button>
              <?php else: ?>
              <button class="fba-btn sm danger" name="fb_action" value="trash" onclick="this.form.querySelectorAll('.fba-row-check').forEach(c=>c.checked=false);this.closest('tr').querySelector('.fba-row-check').checked=true">Trash</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="fba-toolbar" style="margin-top:.9rem">
      <select name="fb_action">
        <option value="read">Mark read</option>
        <option value="unread">Mark unread</option>
        <option value="trash">Move to trash</option>
        <?php if ($state === 'trash'): ?>
        <option value="restore">Restore</option>
        <option value="delete">Delete permanently</option>
        <?php endif; ?>
      </select>
      <button class="fba-btn" type="submit">Apply to selected</button>
    </div>
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

<?php if ($detail):
  $sid = (int)$detail['id'];
  $data = json_decode((string)$detail['data_json'], true) ?: [];
  $filesJ = json_decode((string)$detail['files_json'], true) ?: [];
  $tot = json_decode((string)$detail['totals_json'], true) ?: [];
  ?>
<div class="fba-overlay" onclick="if(event.target===this)location.href='<?= fb_url(['detail' => null]) ?>'">
  <div class="fba-modal">
    <div class="fba-modal-head">
      <h2>FB-<?= $formId ?>-<?= str_pad((string)$sid, 5, '0', STR_PAD_LEFT) ?></h2>
      <a href="<?= fb_url(['detail' => null]) ?>">&times;</a>
    </div>
    <div class="fba-modal-body">
      <?php foreach ($fields as $f):
        if (!empty($types[$f['type']]['display']) || !empty($f['is_hidden'])) continue; ?>
      <div class="fba-field">
        <label><?= htmlspecialchars($f['label'], ENT_QUOTES) ?></label>
        <div><?= fb_render_value($f, $data[$f['field_key']] ?? '', $sid, $filesJ, (string)$formId) ?></div>
      </div>
      <?php endforeach; ?>
      <?php if ($settings['show_total'] === '1'): ?>
      <div class="fba-field"><label><?= htmlspecialchars($settings['total_label'], ENT_QUOTES) ?></label>
        <div><strong class="fba-mono"><?= fb_format_rupiah((int)($tot['total'] ?? 0)) ?></strong></div></div>
      <?php endif; ?>
      <div class="fba-row2">
        <div class="fba-field"><label>IP</label><div class="fba-mono"><?= htmlspecialchars((string)$detail['ip'], ENT_QUOTES) ?></div></div>
        <div class="fba-field"><label>Submitted</label><div><?= htmlspecialchars(date('d M Y, H:i:s', strtotime((string)$detail['created_at'])), ENT_QUOTES) ?></div></div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
