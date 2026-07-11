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
        header('Location: ' . fb_url(['view' => 'builder', 'id' => $newId]), true, 303);
        exit;
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
            $ins = $pdo->prepare('INSERT INTO `fb_fields` (form_id, type, label, field_key, placeholder, help_text, required, width, sort_order, is_hidden, options_json, validation_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($fields as $f) {
                $ins->execute([$newId, $f['type'], $f['label'], $f['field_key'], $f['placeholder'], $f['help_text'], $f['required'], $f['width'], $f['sort_order'], $f['is_hidden'], $f['options_json'], $f['validation_json']]);
            }
            $flash = 'Form duplicated as draft.';
        } elseif ($act === 'archive_form') {
            $pdo->prepare('UPDATE `fb_forms` SET status = "archived" WHERE id = ?')->execute([(int)$target['id']]);
            $flash = 'Form archived.';
        } elseif ($act === 'delete_form') {
            $fid = (int)$target['id'];
            // remove uploaded files
            $subs = $pdo->prepare('SELECT files_json FROM `fb_submissions` WHERE form_id = ?');
            $subs->execute([$fid]);
            $root = dirname(__DIR__, 3) . '/private_files/form-builder/';
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
            $flash = 'Form permanently deleted.';
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
$forms = fb_accessible_forms($pdo);
$rcKeys = fb_recaptcha_keys($pdo);
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

  <?php if (!$forms): ?>
    <div class="fba-empty">No forms yet. Click <strong>+ New Form</strong> to build your first one.</div>
  <?php else: ?>
  <div class="fba-table-wrap">
    <table class="fba-table">
      <thead><tr><th>Form</th><th>Shortcode</th><th>Fields</th><th>Submissions</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($forms as $f):
        $fid = (int)$f['id'];
        $fieldsCount = (int)$pdo->query("SELECT COUNT(*) FROM `fb_fields` WHERE form_id = {$fid}")->fetchColumn();
        $subsCount = (int)$pdo->query("SELECT COUNT(*) FROM `fb_submissions` WHERE form_id = {$fid} AND is_deleted = 0")->fetchColumn();
        $newCount = (int)$pdo->query("SELECT COUNT(*) FROM `fb_submissions` WHERE form_id = {$fid} AND is_deleted = 0 AND is_read = 0")->fetchColumn();
        $statusCls = ['active' => 'active', 'draft' => 'draft', 'archived' => 'arch'][$f['status']] ?? 'draft';
        ?>
        <tr>
          <td><strong><?= htmlspecialchars($f['title'], ENT_QUOTES) ?></strong><span class="fba-sub fba-mono"><?= htmlspecialchars($f['slug'], ENT_QUOTES) ?></span></td>
          <td><span class="fba-code">[form slug=&quot;<?= htmlspecialchars($f['slug'], ENT_QUOTES) ?>&quot;]</span></td>
          <td><?= $fieldsCount ?></td>
          <td><?= $subsCount ?><?= $newCount > 0 ? ' <span class="fba-badge new">' . $newCount . ' new</span>' : '' ?></td>
          <td><span class="fba-badge <?= $statusCls ?>"><?= htmlspecialchars($f['status'], ENT_QUOTES) ?></span></td>
          <td style="white-space:nowrap" class="fba-sub"><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$f['updated_at'])), ENT_QUOTES) ?></td>
          <td style="white-space:nowrap">
            <a class="fba-btn sm" href="<?= fb_url(['view' => 'builder', 'id' => $fid]) ?>">Builder</a>
            <a class="fba-btn sm" href="<?= fb_url(['view' => 'submissions', 'id' => $fid]) ?>">Submissions</a>
            <a class="fba-btn sm" href="<?= fb_url(['view' => 'settings', 'id' => $fid]) ?>">Settings</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Duplicate this form?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="fb_action" value="duplicate_form">
              <input type="hidden" name="form_id" value="<?= $fid ?>">
              <button class="fba-btn sm">Duplicate</button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Archive this form? It will stop accepting submissions.')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="fb_action" value="archive_form">
              <input type="hidden" name="form_id" value="<?= $fid ?>">
              <button class="fba-btn sm danger">Archive</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

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
