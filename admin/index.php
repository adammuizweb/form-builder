<?php
// /plugins/form-builder/admin/index.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

require_once __DIR__ . '/_ui.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) { echo '<p>Database not available.</p>'; return; }

fb_ensure_schema($pdo);

$uid = function_exists('current_user_id') ? current_user_id() : 0;
$role = function_exists('current_user_role') ? current_user_role($pdo) : null;
if ($uid <= 0) { echo '<div class="fba-empty">Please log in.</div>'; return; }

$csrf = function_exists('csrf_token') ? csrf_token() : '';
$flash = '';
$flashOk = true;

// Hard delete a form: submissions, uploaded files, fields, the form itself.
$hardDeleteForm = static function (array $target) use ($pdo): void {
    $fid = (int)$target['id'];
    $subs = $pdo->prepare('SELECT files_json FROM `fb_submissions` WHERE form_id = ?');
    $subs->execute([$fid]);
    $root = fb_files_base_dir($target) . '/';
    while ($r = $subs->fetch(PDO::FETCH_ASSOC)) {
        $fj = json_decode((string)($r['files_json'] ?? ''), true);
        if (is_array($fj)) foreach ($fj as $info) {
            $rel = (string)($info['stored'] ?? '');
            if ($rel !== '' && !str_contains($rel, '..') && !str_starts_with($rel, '/')) @unlink($root . $rel);
        }
    }
    @rmdir($root . $fid);
    $pdo->prepare('DELETE FROM `fb_submissions` WHERE form_id = ?')->execute([$fid]);
    $pdo->prepare('DELETE FROM `fb_fields` WHERE form_id = ?')->execute([$fid]);
    $pdo->prepare('DELETE FROM `fb_forms` WHERE id = ?')->execute([$fid]);
};

// ---------------- Global POST actions ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $okCsrf = function_exists('csrf_check') ? csrf_check($_POST['csrf_token'] ?? '') : true;
    $act = (string)($_POST['fb_action'] ?? '');
    if (!$okCsrf) {
        $flash = 'Invalid CSRF token.'; $flashOk = false;
    } elseif ($act === 'create_form') {
        $title = trim((string)($_POST['title'] ?? 'Untitled Form')) ?: 'Untitled Form';
        $base = fb_normalize_key($title);
        $slug = $base;
        $i = 2;
        while (fb_get_form_by_slug($pdo, $slug) !== null) $slug = $base . '-' . $i++;
        $pdo->prepare('INSERT INTO `fb_forms` (slug, title, status, settings_json, access_json, created_by) VALUES (?, ?, "draft", ?, ?, ?)')
            ->execute([
                $slug, $title,
                json_encode(fb_default_settings(), JSON_UNESCAPED_UNICODE),
                json_encode(['roles' => [], 'users' => [], 'owner' => $uid], JSON_UNESCAPED_UNICODE),
                $uid,
            ]);
        $newId = (int)$pdo->lastInsertId();
        fb_js_redirect(fb_url(['view' => 'builder', 'id' => $newId]));
        return;
    } elseif ($act === 'save_recaptcha') {
        settings_set($pdo, FB_RECAPTCHA_SITEKEY_KEY, trim((string)($_POST['sitekey'] ?? '')), 1);
        settings_set($pdo, FB_RECAPTCHA_SECRET_KEY, trim((string)($_POST['secret'] ?? '')), 1);
        $flash = 'reCAPTCHA keys saved.';
    } elseif (in_array($act, ['duplicate_form', 'archive_form', 'delete_form'], true)) {
        $target = fb_get_form($pdo, (int)($_POST['form_id'] ?? 0));
        if ($target === null || !fb_can_access_form($pdo, $target, $uid, $role)) {
            $flash = 'Form not found or access denied.'; $flashOk = false;
        } elseif ($act === 'duplicate_form') {
            $base = fb_normalize_key($target['slug'] . '-copy');
            $slug = $base; $i = 2;
            while (fb_get_form_by_slug($pdo, $slug) !== null) $slug = $base . '-' . $i++;
            $pdo->prepare('INSERT INTO `fb_forms` (slug, title, description, status, settings_json, css, js, access_json, created_by) VALUES (?, ?, ?, "draft", ?, ?, ?, ?, ?)')
                ->execute([$slug, $target['title'] . ' (Copy)', $target['description'], $target['settings_json'], $target['css'], $target['js'],
                    json_encode(['roles' => [], 'users' => [], 'owner' => $uid], JSON_UNESCAPED_UNICODE), $uid]);
            $newId = (int)$pdo->lastInsertId();
            $fields = fb_get_fields($pdo, (int)$target['id']);
            usort($fields, static fn($a, $b) => (int)$a['id'] <=> (int)$b['id']); // parents before children
            $ins = $pdo->prepare('INSERT INTO `fb_fields` (form_id, parent_id, type, label, field_key, placeholder, help_text, required, width, sort_order, is_hidden, options_json, validation_json, settings_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $idMap = [];
            foreach ($fields as $f) {
                $newParent = (int)$f['parent_id'] > 0 ? ($idMap[(int)$f['parent_id']] ?? 0) : 0;
                $ins->execute([$newId, $newParent, $f['type'], $f['label'], $f['field_key'], $f['placeholder'], $f['help_text'], $f['required'], $f['width'], $f['sort_order'], $f['is_hidden'], $f['options_json'], $f['validation_json'], $f['settings_json'] ?? null]);
                $idMap[(int)$f['id']] = (int)$pdo->lastInsertId();
            }
            $flash = 'Form duplicated as draft.';
        } elseif ($act === 'archive_form') {
            $pdo->prepare('UPDATE `fb_forms` SET status = "archived" WHERE id = ?')->execute([(int)$target['id']]);
            $flash = 'Form archived.';
        } elseif ($act === 'delete_form') {
            $hardDeleteForm($target);
            $flash = 'Form permanently deleted.';
        }
    } elseif ($act === 'bulk') {
        $do = (string)($_POST['do'] ?? '');
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        if ($ids === [] || !in_array($do, ['archive', 'delete'], true)) {
            $flash = 'Pilih form dan aksi bulk terlebih dahulu.'; $flashOk = false;
        } else {
            $n = 0;
            foreach ($ids as $bid) {
                $target = fb_get_form($pdo, $bid);
                if ($target === null || !fb_can_access_form($pdo, $target, $uid, $role)) continue;
                if ($do === 'archive') {
                    $pdo->prepare('UPDATE `fb_forms` SET status = "archived" WHERE id = ?')->execute([$bid]);
                } else {
                    $hardDeleteForm($target);
                }
                $n++;
            }
            $flash = $n . ' form ' . ($do === 'archive' ? 'diarsipkan.' : 'dihapus permanen.');
            if ($n === 0) $flashOk = false;
        }
    }
}

// ---------------- View dispatch ----------------
$view = (string)($_GET['view'] ?? 'forms');

// Raw-output actions (file stream / CSV export) must run BEFORE any HTML is printed,
// otherwise headers are already sent and the download is corrupted.
if ($view === 'submissions' && in_array(($_GET['action'] ?? ''), ['file', 'export'], true)) {
    $form = fb_get_form($pdo, (int)($_GET['id'] ?? 0));
    if ($form === null || !fb_can_access_form($pdo, $form, $uid, $role)) {
        http_response_code(403);
        exit('Access denied');
    }
    require __DIR__ . '/submissions.php'; // streams and exits
    exit;
}

fb_admin_css();

if ($flash !== '') {
    echo '<div class="fba-flash ' . ($flashOk ? 'ok' : 'err') . '">' . htmlspecialchars($flash, ENT_QUOTES) . '</div>';
}

if ($view === 'builder' || $view === 'settings' || $view === 'submissions') {
    $form = fb_get_form($pdo, (int)($_GET['id'] ?? 0));
    if ($form === null || !fb_can_access_form($pdo, $form, $uid, $role)) {
        echo '<div class="fba-empty">Form not found or access denied. <a href="' . fb_url(['view' => 'forms', 'id' => null]) . '">Back to forms</a></div>';
        return;
    }
    $file = __DIR__ . '/' . $view . '.php';
    if (is_file($file)) { require $file; }
    return;
}

// ---------------- Forms list ----------------
$rcKeys = fb_recaptcha_keys($pdo);

// Search + pagination
$q = trim((string)($_GET['q'] ?? ''));
$perPage = 10;
$allForms = fb_accessible_forms($pdo);
if ($q !== '') {
    $allForms = array_values(array_filter($allForms, static function ($f) use ($q): bool {
        return mb_stripos((string)$f['title'], $q) !== false || mb_stripos((string)$f['slug'], $q) !== false;
    }));
}
$totalForms = count($allForms);
$totalPages = max(1, (int)ceil($totalForms / $perPage));
$pageNum = max(1, min($totalPages, (int)($_GET['p'] ?? 1)));
$forms = array_slice($allForms, ($pageNum - 1) * $perPage, $perPage);
$listUrl = static function (array $extra = []) use ($q, $pageNum): string {
    $params = array_merge(['page' => 'admin/tools/form-builder'], $extra);
    if ($q !== '' && !isset($extra['q'])) $params['q'] = $q;
    return '?' . http_build_query($params);
};
?>
<div class="fba">
  <div class="fba-head">
    <h1>Form Builder</h1>
    <div class="fba-actions">
      <button class="fba-btn" onclick="document.getElementById('fba-rc').style.display='flex'">reCAPTCHA</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="fb_action" value="create_form">
        <input type="hidden" name="title" value="New Form">
        <button type="submit" class="fba-btn primary">+ New Form</button>
      </form>
    </div>
  </div>

  <div class="fba-toolbar">
    <form method="get" class="fba-search">
      <input type="hidden" name="page" value="admin/tools/form-builder">
      <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Cari form (judul / slug)…">
      <button class="fba-btn sm" type="submit">Cari</button>
      <?php if ($q !== ''): ?><a class="fba-btn sm" href="?page=admin/tools/form-builder">Reset</a><?php endif; ?>
    </form>
    <div class="fba-cols-toggle">
      <button type="button" class="fba-btn sm" id="fbaColsBtn">⚙ Kolom ▾</button>
      <div class="fba-cols-menu" id="fbaColsMenu" style="display:none">
        <label><input type="checkbox" data-colkey="shortcode" checked> Shortcode</label>
        <label><input type="checkbox" data-colkey="fields" checked> Fields</label>
        <label><input type="checkbox" data-colkey="subs" checked> Submissions</label>
        <label><input type="checkbox" data-colkey="status" checked> Status</label>
        <label><input type="checkbox" data-colkey="updated" checked> Updated</label>
      </div>
    </div>
  </div>

  <?php if (!$allForms): ?>
    <div class="fba-empty"><?= $q !== '' ? 'Tidak ada form yang cocok dengan pencarian. <a href="?page=admin/tools/form-builder">Reset</a>' : 'No forms yet. Click <strong>+ New Form</strong> to build your first one.' ?></div>
  <?php else: ?>
  <form method="post" id="fbaBulkForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="fb_action" value="bulk">
    <div class="fba-bulkbar">
      <select name="do">
        <option value="">Bulk action…</option>
        <option value="archive">Arsipkan</option>
        <option value="delete">Hapus permanen</option>
      </select>
      <button class="fba-btn sm" type="submit">Terapkan</button>
      <span class="fba-hint" id="fbaBulkCount"></span>
      <span class="fba-hint" style="margin-left:auto"><?= $totalForms ?> form<?= $totalPages > 1 ? ' — halaman ' . $pageNum . '/' . $totalPages : '' ?></span>
    </div>
    <div class="fba-table-wrap">
      <table class="fba-table">
        <thead><tr>
          <th class="fba-checkcol"><input type="checkbox" id="fbaCheckAll" title="Pilih semua"></th>
          <th>Form</th>
          <th data-col="shortcode">Shortcode</th>
          <th data-col="fields">Fields</th>
          <th data-col="subs">Submissions</th>
          <th data-col="status">Status</th>
          <th data-col="updated">Updated</th>
          <th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($forms as $f):
          $fid = (int)$f['id'];
          $fieldsCount = (int)$pdo->query("SELECT COUNT(*) FROM `fb_fields` WHERE form_id = {$fid} AND type NOT IN ('row','col') AND deleted_at IS NULL")->fetchColumn();
          $subsCount = (int)$pdo->query("SELECT COUNT(*) FROM `fb_submissions` WHERE form_id = {$fid} AND is_deleted = 0")->fetchColumn();
          $newCount = (int)$pdo->query("SELECT COUNT(*) FROM `fb_submissions` WHERE form_id = {$fid} AND is_deleted = 0 AND is_read = 0")->fetchColumn();
          $statusCls = ['active' => 'active', 'draft' => 'draft', 'archived' => 'arch'][$f['status']] ?? 'draft';
          ?>
          <tr>
            <td class="fba-checkcol"><input type="checkbox" name="ids[]" value="<?= $fid ?>"></td>
            <td><strong><?= htmlspecialchars($f['title'], ENT_QUOTES) ?></strong><span class="fba-sub fba-mono"><?= htmlspecialchars($f['slug'], ENT_QUOTES) ?></span></td>
            <td data-col="shortcode"><span class="fba-code">[form slug=&quot;<?= htmlspecialchars($f['slug'], ENT_QUOTES) ?>&quot;]</span></td>
            <td data-col="fields"><?= $fieldsCount ?></td>
            <td data-col="subs"><?= $subsCount ?><?= $newCount > 0 ? ' <span class="fba-badge new">' . $newCount . ' new</span>' : '' ?></td>
            <td data-col="status"><span class="fba-badge <?= $statusCls ?>"><?= htmlspecialchars($f['status'], ENT_QUOTES) ?></span></td>
            <td data-col="updated" style="white-space:nowrap" class="fba-sub"><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$f['updated_at'])), ENT_QUOTES) ?></td>
            <td style="white-space:nowrap">
              <a class="fba-btn sm primary" href="<?= fb_url(['view' => 'builder', 'id' => $fid]) ?>">Builder</a>
              <details class="fba-more">
                <summary class="fba-btn sm" title="Aksi lainnya">⋯</summary>
                <div class="fba-more-menu">
                  <a href="<?= fb_url(['view' => 'submissions', 'id' => $fid]) ?>">📋 Submissions</a>
                  <a href="<?= fb_url(['view' => 'settings', 'id' => $fid]) ?>">⚙ Settings</a>
                  <button type="submit" form="fba-dup-<?= $fid ?>">⧉ Duplikat</button>
                  <button type="submit" form="fba-arch-<?= $fid ?>" class="danger">🗄 Arsipkan</button>
                </div>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>

  <?php if ($totalPages > 1): ?>
  <div class="fba-pager">
    <?php if ($pageNum > 1): ?><a href="<?= $listUrl(['p' => $pageNum - 1]) ?>">‹ Prev</a><?php endif; ?>
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <?php if ($i === $pageNum): ?><span class="cur"><?= $i ?></span>
      <?php else: ?><a href="<?= $listUrl(['p' => $i]) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($pageNum < $totalPages): ?><a href="<?= $listUrl(['p' => $pageNum + 1]) ?>">Next ›</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* standalone POST forms for row actions (referenced via form= attr, no nesting) */ ?>
  <?php foreach ($forms as $f): $fid = (int)$f['id']; ?>
  <form method="post" id="fba-dup-<?= $fid ?>" style="display:none" onsubmit="return confirm('Duplikat form ini?')">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="fb_action" value="duplicate_form">
    <input type="hidden" name="form_id" value="<?= $fid ?>">
  </form>
  <form method="post" id="fba-arch-<?= $fid ?>" style="display:none" onsubmit="return confirm('Arsipkan form ini? Form berhenti menerima submission.')">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="fb_action" value="archive_form">
    <input type="hidden" name="form_id" value="<?= $fid ?>">
  </form>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
(function () {
  var bulkForm = document.getElementById('fbaBulkForm');
  if (bulkForm) {
    var cnt = document.getElementById('fbaBulkCount');
    function upd() {
      var n = bulkForm.querySelectorAll('input[name="ids[]"]:checked').length;
      if (cnt) cnt.textContent = n > 0 ? n + ' dipilih' : '';
    }
    var all = document.getElementById('fbaCheckAll');
    if (all) all.addEventListener('change', function () {
      bulkForm.querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = all.checked; });
      upd();
    });
    bulkForm.addEventListener('change', function (e) { if (e.target.name === 'ids[]') upd(); });
    bulkForm.addEventListener('submit', function (e) {
      var sel = bulkForm.querySelector('select[name="do"]').value;
      var n = bulkForm.querySelectorAll('input[name="ids[]"]:checked').length;
      if (!sel || n === 0) { e.preventDefault(); return; }
      if (sel === 'delete' && !confirm('Hapus permanen ' + n + ' form beserta submissions & file upload-nya? Tindakan ini tidak bisa dibatalkan.')) e.preventDefault();
    });
  }
  // Column visibility (persisted per browser)
  var KEY = 'fbFormsColsV1';
  var menu = document.getElementById('fbaColsMenu'), btn = document.getElementById('fbaColsBtn');
  function loadCols() { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; } }
  function applyCols() {
    var st = loadCols();
    document.querySelectorAll('[data-col]').forEach(function (el) {
      el.style.display = st[el.getAttribute('data-col')] === false ? 'none' : '';
    });
  }
  if (btn && menu) {
    btn.addEventListener('click', function (e) { e.stopPropagation(); menu.style.display = menu.style.display === 'none' ? 'block' : 'none'; });
    document.addEventListener('click', function (e) { if (!menu.contains(e.target) && e.target !== btn) menu.style.display = 'none'; });
    menu.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
      var key = cb.getAttribute('data-colkey');
      cb.checked = loadCols()[key] !== false;
      cb.addEventListener('change', function () {
        var st = loadCols(); st[key] = cb.checked;
        localStorage.setItem(KEY, JSON.stringify(st));
        applyCols();
      });
    });
    applyCols();
  }
})();
</script>

<div class="fba-overlay" id="fba-rc" style="display:none" onclick="if(event.target===this)this.style.display='none'">
  <div class="fba-modal">
    <div class="fba-modal-head"><h2>reCAPTCHA (global keys)</h2><a href="javascript:void(0)" onclick="document.getElementById('fba-rc').style.display='none'">&times;</a></div>
    <div class="fba-modal-body">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="fb_action" value="save_recaptcha">
        <div class="fba-field"><label>Site Key</label><input type="text" name="sitekey" value="<?= htmlspecialchars($rcKeys['sitekey'], ENT_QUOTES) ?>"></div>
        <div class="fba-field"><label>Secret Key</label><input type="text" name="secret" value="<?= htmlspecialchars($rcKeys['secret'], ENT_QUOTES) ?>"></div>
        <p class="fba-hint">Keys are global. Enable reCAPTCHA per form in each form's Settings.</p>
        <button class="fba-btn primary" type="submit">Save</button>
      </form>
    </div>
  </div>
</div>
