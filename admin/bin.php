<?php
// /plugins/form-builder/admin/bin.php — Bin page for soft-deleted fields/elements
// Route: admin/bin/form-builder/index (requires bin.manage and forms.manage-any)
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

require_once __DIR__ . '/_ui.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) { echo '<p>Database not available.</p>'; return; }

fb_ensure_schema($pdo);

[$uid] = adiwira_require_permission($pdo, 'plugin.form-builder.bin.manage', false);
adiwira_require_permission($pdo, 'plugin.form-builder.forms.manage-any', false);

$csrf = function_exists('csrf_token') ? csrf_token() : '';
$flash = '';
$flashOk = true;

// ---------------- Helpers ----------------
$fbGetNode = static function (int $id) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT * FROM `fb_fields` WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : null;
};

// Purge empty trashed ancestor containers after a hard delete.
$fbPruneAncestors = static function (?int $parentId) use ($pdo, $fbGetNode, &$fbPruneAncestors): void {
    while ($parentId !== null && $parentId > 0) {
        $node = $fbGetNode($parentId);
        if ($node === null || $node['deleted_at'] === null) break; // live ancestor: stop
        if (!in_array($node['type'], ['row', 'col'], true)) break;
        $st = $pdo->prepare('SELECT COUNT(*) FROM `fb_fields` WHERE parent_id = ?');
        $st->execute([$parentId]);
        if ((int)$st->fetchColumn() > 0) break; // still has children
        $next = (int)$node['parent_id'];
        $pdo->prepare('DELETE FROM `fb_fields` WHERE id = ?')->execute([$parentId]);
        $parentId = $next > 0 ? $next : null;
    }
};

// ---------------- POST actions ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $okCsrf = function_exists('csrf_check') && csrf_check($_POST['csrf_token'] ?? '');
    $act = (string)($_POST['fb_action'] ?? '');
    if (!$okCsrf) {
        $flash = 'Invalid CSRF token.'; $flashOk = false;
    } elseif ($act === 'restore') {
        $f = $fbGetNode((int)($_POST['field_id'] ?? 0));
        if ($f === null || $f['deleted_at'] === null || in_array($f['type'], ['row', 'col'], true)) {
            $flash = 'Item tidak ditemukan di Bin.'; $flashOk = false;
        } else {
            $formId = (int)$f['form_id'];
            $parentId = (int)$f['parent_id'];
            $col = $parentId > 0 ? $fbGetNode($parentId) : null;
            if ($col === null || $col['type'] !== 'col' || (int)$col['form_id'] !== $formId) {
                // ancestor gone: create a fresh row+column at the end of the form
                $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM `fb_fields` WHERE form_id = {$formId} AND parent_id = 0")->fetchColumn();
                $pdo->prepare("INSERT INTO `fb_fields` (form_id, type, label, field_key, parent_id, sort_order) VALUES (?, 'row', '', ?, 0, ?)")
                    ->execute([$formId, 'row_' . bin2hex(random_bytes(4)), $maxSort + 10]);
                $rowId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO `fb_fields` (form_id, type, label, field_key, parent_id, sort_order) VALUES (?, 'col', '', ?, ?, 10)")
                    ->execute([$formId, 'col_' . bin2hex(random_bytes(4)), $rowId]);
                $parentId = (int)$pdo->lastInsertId();
            } else {
                // undelete the ancestor chain (col + its row)
                $rowId = (int)$col['parent_id'];
                if ($rowId > 0) {
                    $pdo->prepare('UPDATE `fb_fields` SET deleted_at = NULL WHERE id = ?')->execute([$rowId]);
                }
                $pdo->prepare('UPDATE `fb_fields` SET deleted_at = NULL WHERE id = ?')->execute([(int)$col['id']]);
            }
            $maxInCol = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM `fb_fields` WHERE parent_id = {$parentId} AND deleted_at IS NULL")->fetchColumn();
            $pdo->prepare('UPDATE `fb_fields` SET deleted_at = NULL, parent_id = ?, sort_order = ? WHERE id = ?')
                ->execute([$parentId, $maxInCol + 10, (int)$f['id']]);
            $pdo->prepare('UPDATE `fb_forms` SET updated_at = NOW() WHERE id = ?')->execute([$formId]);
            $flash = 'Field dipulihkan ke form.';
        }
    } elseif ($act === 'purge') {
        $f = $fbGetNode((int)($_POST['field_id'] ?? 0));
        if ($f === null || $f['deleted_at'] === null) {
            $flash = 'Item tidak ditemukan di Bin.'; $flashOk = false;
        } else {
            $parentId = (int)$f['parent_id'];
            $pdo->prepare('DELETE FROM `fb_fields` WHERE id = ?')->execute([(int)$f['id']]);
            $fbPruneAncestors($parentId > 0 ? $parentId : null);
            $flash = 'Field dihapus permanen.';
        }
    } elseif ($act === 'purge_all') {
        $rows = $pdo->query("SELECT id, parent_id FROM `fb_fields` WHERE deleted_at IS NOT NULL AND type NOT IN ('row','col')")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $del = $pdo->prepare('DELETE FROM `fb_fields` WHERE id = ?');
        foreach ($rows as $r) {
            $del->execute([(int)$r['id']]);
            $fbPruneAncestors((int)$r['parent_id'] > 0 ? (int)$r['parent_id'] : null);
        }
        // sweep any remaining trashed containers with no children
        $pdo->exec("DELETE c FROM `fb_fields` c WHERE c.deleted_at IS NOT NULL AND c.type IN ('row','col')
            AND NOT EXISTS (SELECT 1 FROM `fb_fields` ch WHERE ch.parent_id = c.id)");
        $flash = count($rows) . ' item dihapus permanen.';
    } elseif ($act === 'restore_form') {
        $st = $pdo->prepare('SELECT * FROM `fb_forms` WHERE id = ? LIMIT 1');
        $st->execute([(int)($_POST['form_id'] ?? 0)]);
        $form = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($form) || $form['deleted_at'] === null) {
            $flash = 'Form tidak ditemukan di Bin.'; $flashOk = false;
        } else {
            fb_restore_form($pdo, $form);
            $flash = 'Form "' . $form['title'] . '" dipulihkan.';
        }
    } elseif ($act === 'purge_form') {
        $st = $pdo->prepare('SELECT * FROM `fb_forms` WHERE id = ? LIMIT 1');
        $st->execute([(int)($_POST['form_id'] ?? 0)]);
        $form = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($form) || $form['deleted_at'] === null) {
            $flash = 'Form tidak ditemukan di Bin.'; $flashOk = false;
        } else {
            fb_hard_delete_form($pdo, $form);
            $flash = 'Form "' . $form['title'] . '" dihapus permanen beserta fields, submissions & file upload-nya.';
        }
    }
    if ($flash !== '') {
        // JS redirect to keep flash (headers already sent in dashboard context)
        $_SESSION['fb_bin_flash'] = [$flash, $flashOk];
        fb_js_redirect('?page=admin/bin/form-builder/index');
        return;
    }
}

if (!empty($_SESSION['fb_bin_flash'])) {
    [$flash, $flashOk] = $_SESSION['fb_bin_flash'];
    unset($_SESSION['fb_bin_flash']);
}

// ---------------- Data ----------------
$items = $pdo->query("
    SELECT f.*, fo.title AS form_title, fo.slug AS form_slug, fo.deleted_at AS form_deleted_at
    FROM `fb_fields` f
    LEFT JOIN `fb_forms` fo ON fo.id = f.form_id
    WHERE f.deleted_at IS NOT NULL AND f.type NOT IN ('row','col')
    ORDER BY f.deleted_at DESC, f.id DESC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$trashedForms = $pdo->query("
    SELECT * FROM `fb_forms` WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$types = fb_field_types();

fb_admin_css();
?>
<div class="fba">
  <div class="fba-head">
    <h1>🗑 Bin — Form Builder</h1>
    <div class="fba-actions">
      <a class="fba-btn" href="?page=admin/bin/index">&larr; Bin Hub</a>
      <a class="fba-btn" href="?page=admin/tools/form-builder">Form Builder</a>
      <?php if ($items): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Hapus permanen SEMUA field di Bin? Tindakan ini tidak bisa dibatalkan.')">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="fb_action" value="purge_all">
        <button class="fba-btn danger" type="submit">Kosongkan Bin Fields (<?= count($items) ?>)</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash !== ''): ?>
  <div class="fba-flash <?= $flashOk ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <?php if ($trashedForms): ?>
  <div class="fba-card">
    <span class="fba-hint"><strong>Forms</strong> yang dihapus dari daftar form disembunyikan di sini. <strong>Restore</strong> mengembalikan form beserta seluruh fields &amp; submissions-nya. <strong>Hapus Permanen</strong> menghapus form, fields, submissions, dan file upload-nya selamanya.</span>
  </div>
  <div class="fba-table-wrap" style="margin-bottom:1.5rem">
    <table class="fba-table">
      <thead><tr><th>Form</th><th>Status</th><th>Dihapus</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($trashedForms as $tf):
        $tfid = (int)$tf['id'];
        $origSlug = (string)preg_replace('/--trash-\d+$/', '', (string)$tf['slug']);
        ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($tf['title'], ENT_QUOTES) ?></strong>
            <span class="fba-sub fba-mono"><?= htmlspecialchars($origSlug, ENT_QUOTES) ?></span>
          </td>
          <td><span class="fba-badge <?= htmlspecialchars(['active' => 'active', 'draft' => 'draft', 'archived' => 'arch'][$tf['status']] ?? 'draft', ENT_QUOTES) ?>"><?= htmlspecialchars($tf['status'], ENT_QUOTES) ?></span></td>
          <td style="white-space:nowrap" class="fba-sub"><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$tf['deleted_at'])), ENT_QUOTES) ?></td>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="fb_action" value="restore_form">
              <input type="hidden" name="form_id" value="<?= $tfid ?>">
              <button class="fba-btn sm primary" type="submit">Restore</button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Hapus permanen form ini beserta fields, submissions & file upload-nya? Tindakan ini tidak bisa dibatalkan.')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="fb_action" value="purge_form">
              <input type="hidden" name="form_id" value="<?= $tfid ?>">
              <button class="fba-btn sm danger" type="submit">Hapus Permanen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="fba-card">
    <span class="fba-hint"><strong>Fields</strong> &amp; element yang dihapus dari builder disembunyikan di sini. <strong>Restore</strong> mengembalikan field beserta data &amp; pengaturannya ke posisi semula (atau row baru jika row aslinya sudah tidak ada). Hanya pengguna dengan izin Bin yang bisa melihat halaman ini.</span>
  </div>

  <?php if (!$items): ?>
    <div class="fba-empty">Bin kosong. Tidak ada field yang dihapus.</div>
  <?php else: ?>
  <div class="fba-table-wrap">
    <table class="fba-table">
      <thead><tr><th>Field</th><th>Type</th><th>Form</th><th>Dihapus</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it):
        $meta = $types[$it['type']] ?? ['label' => $it['type']];
        $group = ($meta['group'] ?? '') === 'element' ? 'element' : 'input';
        ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($it['label'] !== '' ? $it['label'] : '(tanpa label)', ENT_QUOTES) ?></strong>
            <span class="fba-sub fba-mono"><?= htmlspecialchars($it['field_key'], ENT_QUOTES) ?></span>
          </td>
          <td><span class="fbc-chip-type"><?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></span> <span class="fba-hint"><?= $group ?></span></td>
          <td>
            <?php if ($it['form_title'] !== null): ?>
            <a href="?page=admin/tools/form-builder&view=builder&id=<?= (int)$it['form_id'] ?>"><?= htmlspecialchars($it['form_title'], ENT_QUOTES) ?></a>
            <?php else: ?><span class="fba-hint">(form dihapus)</span><?php endif; ?>
          </td>
          <td style="white-space:nowrap" class="fba-sub"><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$it['deleted_at'])), ENT_QUOTES) ?></td>
          <td style="white-space:nowrap">
            <?php if ($it['form_title'] !== null && ($it['form_deleted_at'] ?? null) === null): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="fb_action" value="restore">
              <input type="hidden" name="field_id" value="<?= (int)$it['id'] ?>">
              <button class="fba-btn sm primary" type="submit">Restore</button>
            </form>
            <?php elseif ($it['form_title'] !== null): ?>
            <span class="fba-hint">form di Bin</span>
            <?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Hapus permanen field ini? Data submission lama tetap aman, tapi field tidak bisa dikembalikan.')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="fb_action" value="purge">
              <input type="hidden" name="field_id" value="<?= (int)$it['id'] ?>">
              <button class="fba-btn sm danger" type="submit">Hapus Permanen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
