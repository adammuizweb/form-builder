<?php
// /plugins/form-builder/admin/index.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

require_once __DIR__ . '/_ui.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) { echo '<p>Database not available.</p>'; return; }
[$uid] = adiwira_require_permission($pdo, 'plugin.form-builder.workspace.access', false);

fb_assert_schema($pdo);

$canGlobalSettings = user_can($pdo, $uid, 'plugin.form-builder.global-settings.manage');
$canDefinitions = user_can($pdo, $uid, 'plugin.form-builder.definitions.manage');

$csrf = function_exists('csrf_token') ? csrf_token() : '';
$flash = '';
$flashOk = true;
$scope = (($_GET['scope'] ?? '') === 'archived') ? 'archived' : 'current';

// ---------------- Global POST actions ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $okCsrf = function_exists('csrf_check') && csrf_check($_POST['csrf_token'] ?? '');
    $act = (string)($_POST['fb_action'] ?? '');
    if (!$okCsrf) {
        $flash = 'Invalid CSRF token.'; $flashOk = false;
    } elseif ($act === 'create_form') {
        $title = trim((string)($_POST['title'] ?? 'Untitled Form')) ?: 'Untitled Form';
        $slug = fb_unique_form_slug($pdo, $title);
        $pdo->prepare('INSERT INTO `fb_forms` (slug, title, status, settings_json, access_json, created_by) VALUES (?, ?, "draft", ?, ?, ?)')
            ->execute([
                $slug, $title,
                fb_json_encode(fb_default_settings()),
                fb_json_encode(['roles' => [], 'users' => [], 'owner' => $uid, 'submissions' => ['roles' => [], 'users' => []]]),
                $uid,
            ]);
        $newId = (int)$pdo->lastInsertId();
        fb_js_redirect(fb_visual_builder_url($newId));
        return;
    } elseif (in_array($act, ['duplicate_form', 'archive_form', 'reactivate_form', 'delete_form'], true)) {
        $target = fb_get_form($pdo, (int)($_POST['form_id'] ?? 0));
        if ($target === null || !empty($target['deleted_at']) || !fb_can_access_form($pdo, $target, $uid)) {
            $flash = 'Form not found or access denied.'; $flashOk = false;
        } elseif ($act === 'duplicate_form') {
            $slug = fb_unique_form_slug($pdo, $target['slug'] . '-copy');
            $pdo->prepare('INSERT INTO `fb_forms` (slug, title, description, status, settings_json, css, js, access_json, created_by) VALUES (?, ?, ?, "draft", ?, ?, ?, ?, ?)')
                ->execute([$slug, $target['title'] . ' (Copy)', $target['description'], $target['settings_json'], $target['css'], $target['js'],
                    fb_json_encode(['roles' => [], 'users' => [], 'owner' => $uid, 'submissions' => ['roles' => [], 'users' => []]]), $uid]);
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
            try { fb_archive_form($pdo, $target); $flash = 'Form archived.'; }
            catch (UnexpectedValueException $error) { $flash = $error->getMessage(); $flashOk = false; }
        } elseif ($act === 'reactivate_form') {
            try { fb_reactivate_form_as_draft($pdo, $target); $flash = 'Form reactivated as a draft.'; }
            catch (UnexpectedValueException $error) { $flash = $error->getMessage(); $flashOk = false; }
        } elseif ($act === 'delete_form') {
            fb_trash_form($pdo, $target);
            $flash = 'Form dipindahkan ke Bin. Admin bisa me-restore dari Bin.';
        }
    } elseif ($act === 'bulk') {
        $do = (string)($_POST['do'] ?? '');
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        $allowedBulk = $scope === 'archived' ? ['reactivate', 'delete'] : ['archive', 'delete'];
        if ($ids === [] || !in_array($do, $allowedBulk, true)) {
            $flash = 'Pilih form dan aksi bulk terlebih dahulu.'; $flashOk = false;
        } else {
            $n = 0;
            foreach ($ids as $bid) {
                $target = fb_get_form($pdo, $bid);
                if ($target === null || !fb_can_access_form($pdo, $target, $uid)) continue;
                try {
                    if ($do === 'archive') fb_archive_form($pdo, $target);
                    elseif ($do === 'reactivate') fb_reactivate_form_as_draft($pdo, $target);
                    else fb_trash_form($pdo, $target);
                    $n++;
                } catch (UnexpectedValueException) {}
            }
            $flash = $n . ' form ' . match ($do) {
                'archive' => 'diarsipkan.',
                'reactivate' => 'diaktifkan kembali sebagai draft.',
                default => 'dipindahkan ke Bin.',
            };
            if ($n === 0) $flashOk = false;
        }
    }
}

// ---------------- View dispatch ----------------
$view = (string)($_GET['view'] ?? 'forms');

// Raw-output actions (file stream / CSV export) must run BEFORE any HTML is printed,
// otherwise headers are already sent and the download is corrupted.
$isSubmissionDownload = $view === 'submissions' && (
    ($_GET['action'] ?? '') === 'file'
    || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['fb_action'] ?? '') === 'export')
);
if ($isSubmissionDownload) {
    $form = fb_get_form($pdo, (int)($_GET['id'] ?? 0));
    if ($form === null || ($form['deleted_at'] ?? null) !== null || !fb_can_view_submissions($pdo, $form, $uid)) {
        http_response_code(403);
        exit('Access denied');
    }
    require __DIR__ . '/submissions.php'; // streams and exits
    exit;
}
if (($_GET['action'] ?? '') === 'export_definition') {
    if (!$canDefinitions) { http_response_code(403); exit('Access denied'); }
    $target = fb_get_form($pdo, (int)($_GET['id'] ?? 0));
    if ($target === null || !fb_can_access_form($pdo, $target, $uid)) { http_response_code(404); exit('Not found'); }
    $includeUnsafe = user_can($pdo, $uid, 'plugin.form-builder.unsafe-code.manage') && ($_GET['unsafe'] ?? '') === '1';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_-]/', '-', $target['slug']) . '.form.json"');
    header('Cache-Control: no-store');
    echo json_encode(fb_export_form_definition($pdo, (int)$target['id'], $includeUnsafe), JSON_PRETTY_PRINT | FB_JSON_FLAGS);
    exit;
}

fb_admin_css();

if ($flash !== '') {
    echo '<div class="fba-flash ' . ($flashOk ? 'ok' : 'err') . '">' . htmlspecialchars($flash, ENT_QUOTES) . '</div>';
}

if ($view === 'builder' || $view === 'settings' || $view === 'submissions') {
    $form = fb_get_form($pdo, (int)($_GET['id'] ?? 0));
    $hasViewAccess = $form !== null && ($view === 'submissions'
        ? fb_can_view_submissions($pdo, $form, $uid)
        : fb_can_access_form($pdo, $form, $uid));
    if ($form === null || ($form['deleted_at'] ?? null) !== null || !$hasViewAccess) {
        echo '<div class="fba-empty">Form not found or access denied. <a href="' . fb_url(['view' => 'forms', 'id' => null]) . '">Back to forms</a></div>';
        return;
    }
    $file = __DIR__ . '/' . $view . '.php';
    if (is_file($file)) { require $file; }
    return;
}

// Search + pagination
$q = trim((string)($_GET['q'] ?? ''));
$perPage = 10;
$allForms = fb_accessible_forms($pdo, $scope === 'archived' ? "status = 'archived'" : "status IN ('active','draft')");
if ($q !== '') {
    $allForms = array_values(array_filter($allForms, static function ($f) use ($q): bool {
        return mb_stripos((string)$f['title'], $q) !== false || mb_stripos((string)$f['slug'], $q) !== false;
    }));
}
$totalForms = count($allForms);
$totalPages = max(1, (int)ceil($totalForms / $perPage));
$pageNum = max(1, min($totalPages, (int)($_GET['p'] ?? 1)));
$forms = array_slice($allForms, ($pageNum - 1) * $perPage, $perPage);
$listUrl = static function (array $extra = []) use ($q, $scope): string {
    $params = array_merge(['page' => 'admin/tools/form-builder'], $extra);
    if ($scope === 'archived' && !isset($extra['scope'])) $params['scope'] = 'archived';
    if ($q !== '' && !isset($extra['q'])) $params['q'] = $q;
    return '?' . http_build_query($params);
};
?>
<div class="fba">
  <div class="fba-head">
    <h1>Form Builder</h1>
    <div class="fba-actions">
      <?php if ($canGlobalSettings || $canDefinitions): ?><a class="fba-btn" href="?page=admin/tools/form-builder/settings"><?= svg_ico('settings') ?> Settings</a><?php endif; ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="fb_action" value="create_form">
        <input type="hidden" name="title" value="New Form">
        <button type="submit" class="fba-btn primary">+ New Form</button>
      </form>
    </div>
  </div>

  <div class="fba-toolbar">
    <div class="fba-state-tabs" aria-label="Form status views">
      <a class="fba-btn sm <?= $scope === 'current' ? 'is-active' : '' ?>" href="?page=admin/tools/form-builder">Current</a>
      <a class="fba-btn sm <?= $scope === 'archived' ? 'is-active' : '' ?>" href="?page=admin/tools/form-builder&amp;scope=archived">Archived</a>
    </div>
    <form method="get" class="fba-search">
      <input type="hidden" name="page" value="admin/tools/form-builder">
      <?php if ($scope === 'archived'): ?><input type="hidden" name="scope" value="archived"><?php endif; ?>
      <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Cari form (judul / slug)…">
      <button class="fba-btn sm" type="submit">Cari</button>
      <?php if ($q !== ''): ?><a class="fba-btn sm" href="<?= $scope === 'archived' ? '?page=admin/tools/form-builder&amp;scope=archived' : '?page=admin/tools/form-builder' ?>">Reset</a><?php endif; ?>
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
    <div class="fba-empty"><?php if ($q !== ''): ?>Tidak ada form yang cocok dengan pencarian. <a href="<?= $scope === 'archived' ? '?page=admin/tools/form-builder&amp;scope=archived' : '?page=admin/tools/form-builder' ?>">Reset</a><?php elseif ($scope === 'archived'): ?>No archived forms. Archived forms remain here until they are reactivated or moved to the Bin.<?php else: ?>No forms yet. Click <strong>+ New Form</strong> to build your first one.<?php endif; ?></div>
  <?php else: ?>
  <form method="post" id="fbaBulkForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="fb_action" value="bulk">
    <div class="fba-bulkbar">
      <select name="do">
        <option value="">Bulk action…</option>
        <?php if ($scope === 'archived'): ?><option value="reactivate">Reactivate as draft</option><?php else: ?><option value="archive">Arsipkan</option><?php endif; ?>
        <option value="delete">Pindahkan ke Bin</option>
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
          $canEditForm = fb_can_access_form($pdo, $f, $uid);
          $canViewFormSubmissions = fb_can_view_submissions($pdo, $f, $uid);
          $fieldsCount = (int)$pdo->query("SELECT COUNT(*) FROM `fb_fields` WHERE form_id = {$fid} AND type NOT IN ('row','col') AND deleted_at IS NULL")->fetchColumn();
          $subsCount = (int)$pdo->query("SELECT COUNT(*) FROM `fb_submissions` WHERE form_id = {$fid} AND is_deleted = 0")->fetchColumn();
          $newCount = (int)$pdo->query("SELECT COUNT(*) FROM `fb_submissions` WHERE form_id = {$fid} AND is_deleted = 0 AND is_read = 0")->fetchColumn();
          $statusCls = ['active' => 'active', 'draft' => 'draft', 'archived' => 'arch'][$f['status']] ?? 'draft';
          ?>
          <tr>
            <td class="fba-checkcol"><?php if ($canEditForm): ?><input type="checkbox" name="ids[]" value="<?= $fid ?>"><?php endif; ?></td>
            <td><strong><?= htmlspecialchars($f['title'], ENT_QUOTES) ?></strong><span class="fba-sub fba-mono"><?= htmlspecialchars($f['slug'], ENT_QUOTES) ?></span></td>
            <td data-col="shortcode"><span class="fba-code">[form slug=&quot;<?= htmlspecialchars($f['slug'], ENT_QUOTES) ?>&quot;]</span></td>
            <td data-col="fields"><?= $fieldsCount ?></td>
            <td data-col="subs"><?= $canViewFormSubmissions ? $subsCount . ($newCount > 0 ? ' <span class="fba-badge new">' . $newCount . ' new</span>' : '') : '&mdash;' ?></td>
            <td data-col="status"><span class="fba-badge <?= $statusCls ?>"><?= htmlspecialchars($f['status'], ENT_QUOTES) ?></span></td>
            <td data-col="updated" style="white-space:nowrap"><span class="fba-sub"><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$f['updated_at'])), ENT_QUOTES) ?></span></td>
            <td style="white-space:nowrap">
               <?php if ($canEditForm && $scope !== 'archived'): ?><a class="fba-btn sm primary" href="<?= fb_visual_builder_url($fid) ?>">Visual Builder</a><?php elseif ($canEditForm): ?><a class="fba-btn sm primary" href="<?= fb_url(['view' => 'settings', 'id' => $fid]) ?>">Review</a><?php elseif ($canViewFormSubmissions): ?><a class="fba-btn sm primary" href="<?= fb_url(['view' => 'submissions', 'id' => $fid]) ?>">Submissions</a><?php endif; ?>
              <details class="fba-more">
                <summary class="fba-btn sm" title="Aksi lainnya" aria-label="Aksi lainnya"><svg class="lucide-icon fba-menu-trigger-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="5" cy="12" r="1"></circle><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle></svg></summary>
                <div class="fba-more-menu">
                   <?php if ($canViewFormSubmissions): ?><a href="<?= fb_url(['view' => 'submissions', 'id' => $fid]) ?>"><?= svg_ico('clipboard-list') ?><span>Submissions</span></a><?php endif; ?>
                    <?php if ($canEditForm && $scope !== 'archived'): ?><a href="<?= fb_url(['view' => 'builder', 'id' => $fid]) ?>"><?= svg_ico('panel-top') ?><span>Classic Builder</span></a><?php endif; ?>
                    <?php if ($canEditForm): ?><a href="<?= fb_url(['view' => 'settings', 'id' => $fid]) ?>"><?= svg_ico('settings') ?><span>Form settings</span></a>
                    <?php if ($canDefinitions): ?><a href="<?= fb_url(['action' => 'export_definition', 'id' => $fid]) ?>"><?= svg_ico('download') ?><span>Export definition</span></a><?php endif; ?>
                    <button type="submit" form="fba-dup-<?= $fid ?>"><?= svg_ico('copy') ?><span>Duplikat</span></button>
                    <?php if ($scope === 'archived'): ?><button type="submit" form="fba-reactivate-<?= $fid ?>"><?= svg_ico('rotate-ccw') ?><span>Reactivate as draft</span></button><?php else: ?><button type="submit" form="fba-arch-<?= $fid ?>" class="danger"><?= svg_ico('box') ?><span>Arsipkan</span></button><?php endif; ?>
                   <button type="submit" form="fba-del-<?= $fid ?>" class="danger"><?= svg_ico('trash-2') ?><span>Hapus</span></button><?php endif; ?>
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
  <?php foreach ($forms as $f): $fid = (int)$f['id']; if (!fb_can_access_form($pdo, $f, $uid)) continue; ?>
  <form method="post" id="fba-dup-<?= $fid ?>" style="display:none" data-fb-confirm-title="Duplicate form" data-fb-confirm-message="Duplikat form ini?" data-fb-confirm-text="Duplicate">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="fb_action" value="duplicate_form">
    <input type="hidden" name="form_id" value="<?= $fid ?>">
  </form>
  <?php if ($scope === 'archived'): ?>
  <form method="post" id="fba-reactivate-<?= $fid ?>" style="display:none" data-fb-confirm-title="Reactivate form" data-fb-confirm-message="Reactivate this form as a draft? Review it before publishing." data-fb-confirm-text="Reactivate as draft">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="fb_action" value="reactivate_form">
    <input type="hidden" name="form_id" value="<?= $fid ?>">
  </form>
  <?php else: ?>
  <form method="post" id="fba-arch-<?= $fid ?>" style="display:none" data-fb-confirm-title="Archive form" data-fb-confirm-message="Arsipkan form ini? Form berhenti menerima submission, tetapi data dan upload tetap disimpan." data-fb-confirm-text="Archive">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="fb_action" value="archive_form">
    <input type="hidden" name="form_id" value="<?= $fid ?>">
  </form>
  <?php endif; ?>
  <form method="post" id="fba-del-<?= $fid ?>" style="display:none" data-fb-confirm-title="Move form to Bin" data-fb-confirm-message="Pindahkan form ini ke Bin? Admin masih bisa me-restore dari Bin." data-fb-confirm-text="Move to Bin">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="fb_action" value="delete_form">
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
    var bulkConfirmed = false;
    bulkForm.addEventListener('submit', async function (e) {
      var sel = bulkForm.querySelector('select[name="do"]').value;
      var n = bulkForm.querySelectorAll('input[name="ids[]"]:checked').length;
      if (!sel || n === 0) { e.preventDefault(); return; }
      if (bulkConfirmed) { bulkConfirmed = false; return; }
      if (sel === 'delete') {
        e.preventDefault();
        var submitter = e.submitter;
        var confirmed = await window.FormBuilderConfirm({
          variant: 'warning',
          badgeText: 'Form Builder',
          title: 'Move forms to Bin',
          message: 'Pindahkan ' + n + ' form ke Bin? Admin masih bisa me-restore dari Bin.',
          confirmText: 'Move to Bin',
          cancelText: 'Cancel',
          focus: 'cancel'
        });
        if (!confirmed || !bulkForm.isConnected || !bulkForm.reportValidity()) return;
        bulkConfirmed = true;
        try {
          if (submitter instanceof HTMLElement && submitter.form === bulkForm) bulkForm.requestSubmit(submitter);
          else bulkForm.requestSubmit();
        } finally {
          queueMicrotask(function () { bulkConfirmed = false; });
        }
      }
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
  // Actions menu portal: an open menu is moved to <body> with fixed coords so it
  // is never clipped by the table wrapper's overflow or the .adam-main transform.
  var mores = document.querySelectorAll('.fba-more');
  mores.forEach(function (det) {
    var menu = det.querySelector('.fba-more-menu');
    var sum = det.querySelector('summary');
    if (!menu || !sum) return;
    function place() {
      var r = sum.getBoundingClientRect();
      var gap = 4;
      var menuHeight = menu.offsetHeight;
      var below = r.bottom + gap;
      var top = below + menuHeight <= window.innerHeight - 8 ? below : Math.max(8, r.top - menuHeight - gap);
      menu.style.top = top + 'px';
      menu.style.left = Math.min(Math.max(8, r.right - menu.offsetWidth), Math.max(8, window.innerWidth - menu.offsetWidth - 8)) + 'px';
      menu.style.maxHeight = Math.max(120, window.innerHeight - 16) + 'px';
      menu.style.overflowY = 'auto';
    }
    det.addEventListener('toggle', function () {
      if (det.open) {
        mores.forEach(function (o) { if (o !== det) o.open = false; });
        document.body.appendChild(menu);
        menu.classList.add('fba-portal');
        place();
        requestAnimationFrame(function () {
          place();
          var firstAction = menu.querySelector('a, button');
          if (firstAction) firstAction.focus({ preventScroll: true });
        });
      } else {
        if (menu.contains(document.activeElement)) sum.focus({ preventScroll: true });
        menu.classList.remove('fba-portal');
        det.appendChild(menu);
      }
    });
    menu.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); det.open = false; sum.focus({ preventScroll: true }); }
    });
  });
  function closeAllMores() { mores.forEach(function (o) { o.open = false; }); }
  window.addEventListener('scroll', closeAllMores, true);
  window.addEventListener('resize', closeAllMores);
})();
</script>
