<?php
// /plugins/form-builder/admin/settings.php  ($form, $pdo, $uid, $role, $csrf in scope)
declare(strict_types=1);

$formId = (int)$form['id'];
$settings = fb_form_settings($form);
$access = fb_form_access($form);
$fields = fb_get_fields($pdo, $formId);
$types = fb_field_types();
$inputFields = array_values(array_filter($fields, static fn($f) => !empty($types[$f['type']]['input']) && empty($types[$f['type']]['file'])));

$saved = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && function_exists('csrf_check') && csrf_check($_POST['csrf_token'] ?? '')) {
    $act = (string)($_POST['fb_action'] ?? '');

    if ($act === 'save_general') {
        $title = trim((string)($_POST['title'] ?? '')) ?: 'Untitled Form';
        $slug = fb_normalize_key((string)($_POST['slug'] ?? '') !== '' ? (string)$_POST['slug'] : $title);
        $base = $slug; $i = 2;
        while (true) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM `fb_forms` WHERE slug = ? AND id != ?');
            $st->execute([$slug, $formId]);
            if ((int)$st->fetchColumn() === 0) break;
            $slug = $base . '-' . $i++;
        }
        $status = in_array(($_POST['status'] ?? ''), ['active', 'draft', 'archived'], true) ? (string)$_POST['status'] : 'draft';
        $pdo->prepare('UPDATE `fb_forms` SET title = ?, slug = ?, description = ?, status = ? WHERE id = ?')
            ->execute([$title, $slug, trim((string)($_POST['description'] ?? '')) ?: null, $status, $formId]);
        $saved = true;
    }

    if ($act === 'save_submission') {
        $settings['submit_label'] = trim((string)($_POST['submit_label'] ?? 'Submit')) ?: 'Submit';
        $settings['success_message'] = trim((string)($_POST['success_message'] ?? ''));
        $settings['recaptcha'] = !empty($_POST['recaptcha']) ? '1' : '0';
        $settings['rate_max'] = max(1, (int)($_POST['rate_max'] ?? 10));
        $settings['rate_window'] = max(60, (int)($_POST['rate_window'] ?? 3600));
        $settings['notify_email'] = trim((string)($_POST['notify_email'] ?? ''));
        $settings['show_total'] = !empty($_POST['show_total']) ? '1' : '0';
        $settings['total_label'] = trim((string)($_POST['total_label'] ?? 'Total')) ?: 'Total';
        $settings['columns'] = array_values(array_filter(array_map('strval', (array)($_POST['columns'] ?? []))));
        $pdo->prepare('UPDATE `fb_forms` SET settings_json = ? WHERE id = ?')
            ->execute([json_encode($settings, JSON_UNESCAPED_UNICODE), $formId]);
        $saved = true;
    }

    if ($act === 'save_display') {
        $pdo->prepare('UPDATE `fb_forms` SET css = ?, js = ? WHERE id = ?')
            ->execute([
                trim((string)($_POST['css'] ?? '')) !== '' ? (string)$_POST['css'] : null,
                trim((string)($_POST['js'] ?? '')) !== '' ? (string)$_POST['js'] : null,
                $formId,
            ]);
        $saved = true;
    }

    if ($act === 'save_access') {
        $roles = array_values(array_filter(array_map('strval', (array)($_POST['access_roles'] ?? []))));
        $users = array_values(array_filter(array_map('intval', (array)($_POST['access_users'] ?? []))));
        $access['roles'] = $roles;
        $access['users'] = $users;
        $pdo->prepare('UPDATE `fb_forms` SET access_json = ? WHERE id = ?')
            ->execute([json_encode($access, JSON_UNESCAPED_UNICODE), $formId]);
        $saved = true;
    }

    if ($saved) {
        fb_js_redirect(fb_url(['view' => 'settings', 'id' => $formId, 'saved' => 1]));
        return;
    }
}

// refresh after potential save
$form = fb_get_form($pdo, $formId) ?? $form;
$settings = fb_form_settings($form);
$access = fb_form_access($form);

$allRoles = $pdo->query("SELECT DISTINCT role FROM `users` WHERE is_deleted = 0 AND role != '' ORDER BY role")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$allUsers = $pdo->query("SELECT id, name, email, role FROM `users` WHERE is_deleted = 0 ORDER BY name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="fba">
  <div class="fba-head">
    <h1>Settings: <?= htmlspecialchars($form['title'], ENT_QUOTES) ?></h1>
    <div class="fba-actions">
      <a class="fba-btn" href="<?= fb_url(['view' => 'forms', 'id' => null]) ?>">&larr; Forms</a>
      <a class="fba-btn" href="<?= fb_url(['view' => 'builder', 'id' => $formId]) ?>">Builder</a>
      <a class="fba-btn" href="<?= fb_url(['view' => 'submissions', 'id' => $formId]) ?>">Submissions</a>
    </div>
  </div>

  <?php if (!empty($_GET['saved'])): ?><div class="fba-flash ok">Settings saved.</div><?php endif; ?>

  <div class="fba-card">
    <div class="fba-sec">General</div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="fb_action" value="save_general">
      <div class="fba-row2">
        <div class="fba-field"><label>Title</label><input type="text" name="title" value="<?= htmlspecialchars($form['title'], ENT_QUOTES) ?>" required></div>
        <div class="fba-field"><label>Slug</label><input type="text" name="slug" value="<?= htmlspecialchars($form['slug'], ENT_QUOTES) ?>"><div class="fba-hint">Shortcode: <span class="fba-code">[form slug=&quot;<?= htmlspecialchars($form['slug'], ENT_QUOTES) ?>&quot;]</span></div></div>
      </div>
      <div class="fba-row2">
        <div class="fba-field"><label>Description (shown above the form)</label><input type="text" name="description" value="<?= htmlspecialchars((string)$form['description'], ENT_QUOTES) ?>"></div>
        <div class="fba-field"><label>Status</label>
          <select name="status">
            <?php foreach (['draft' => 'Draft (not accepting)', 'active' => 'Active (accepting)', 'archived' => 'Archived'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $form['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <button class="fba-btn primary" type="submit">Save general</button>
    </form>
  </div>

  <div class="fba-card">
    <div class="fba-sec">Submission Behavior</div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="fb_action" value="save_submission">
      <div class="fba-row3">
        <div class="fba-field"><label>Submit button label</label><input type="text" name="submit_label" value="<?= htmlspecialchars($settings['submit_label'], ENT_QUOTES) ?>"></div>
        <div class="fba-field"><label>Rate limit (max / IP)</label><input type="number" name="rate_max" value="<?= (int)$settings['rate_max'] ?>"></div>
        <div class="fba-field"><label>Rate window (seconds)</label><input type="number" name="rate_window" value="<?= (int)$settings['rate_window'] ?>"></div>
      </div>
      <div class="fba-row2">
        <div class="fba-field"><label>Success message</label><textarea name="success_message" rows="2" style="font-family:inherit"><?= htmlspecialchars($settings['success_message'], ENT_QUOTES) ?></textarea></div>
        <div class="fba-field"><label>Notification email</label><input type="email" name="notify_email" value="<?= htmlspecialchars($settings['notify_email'], ENT_QUOTES) ?>" placeholder="admin@example.com"><div class="fba-hint">Best-effort via server mail()</div></div>
      </div>
      <div class="fba-checks" style="margin:.3rem 0 .8rem">
        <label class="fba-check"><input type="checkbox" name="recaptcha" value="1" <?= $settings['recaptcha'] === '1' ? 'checked' : '' ?>> Enable reCAPTCHA</label>
        <label class="fba-check"><input type="checkbox" name="show_total" value="1" <?= $settings['show_total'] === '1' ? 'checked' : '' ?>> Show total (sums priced options)</label>
      </div>
      <div class="fba-row2">
        <div class="fba-field"><label>Total label</label><input type="text" name="total_label" value="<?= htmlspecialchars($settings['total_label'], ENT_QUOTES) ?>"></div>
        <div class="fba-field"><label>Submission list columns</label>
          <div class="fba-checks">
            <?php foreach ($inputFields as $f): ?>
            <label class="fba-check"><input type="checkbox" name="columns[]" value="<?= htmlspecialchars($f['field_key'], ENT_QUOTES) ?>" <?= in_array($f['field_key'], $settings['columns'], true) ? 'checked' : '' ?>> <?= htmlspecialchars($f['label'], ENT_QUOTES) ?></label>
            <?php endforeach; ?>
            <?php if (!$inputFields): ?><span class="fba-hint">Add input fields in the Builder first.</span><?php endif; ?>
          </div>
          <div class="fba-hint">Unchecked = auto (first 4 fields).</div>
        </div>
      </div>
      <button class="fba-btn primary" type="submit">Save submission settings</button>
    </form>
  </div>

  <div class="fba-card">
    <div class="fba-sec">Custom CSS &amp; JavaScript</div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="fb_action" value="save_display">
      <div class="fba-field"><label>Custom CSS</label>
        <textarea name="css" rows="6" placeholder="#fb-<?= htmlspecialchars($form['slug'], ENT_QUOTES) ?> { --fb-accent: #1a73e8; }"><?= htmlspecialchars((string)$form['css'], ENT_QUOTES) ?></textarea>
        <div class="fba-hint">Injected after the base styles. Target the wrapper <span class="fba-code">#fb-<?= htmlspecialchars($form['slug'], ENT_QUOTES) ?></span>.</div>
      </div>
      <div class="fba-field"><label>Custom JavaScript</label>
        <textarea name="js" rows="6" placeholder="// root = form wrapper element, form = the &lt;form&gt;&#10;console.log(root, form);"><?= htmlspecialchars((string)$form['js'], ENT_QUOTES) ?></textarea>
        <div class="fba-hint">Wrapped in <span class="fba-mono">(function(root, form){ … })</span>. Runs after the base script.</div>
      </div>
      <button class="fba-btn primary" type="submit">Save display</button>
    </form>
  </div>

  <div class="fba-card">
    <div class="fba-sec">Access Control</div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="fb_action" value="save_access">
      <p class="fba-hint" style="margin-bottom:.8rem">Admins and the form owner always have access. Grant additional access below — users only see forms granted to them.</p>
      <div class="fba-field"><label>Roles</label>
        <div class="fba-checks">
          <?php foreach ($allRoles as $r): ?>
          <label class="fba-check"><input type="checkbox" name="access_roles[]" value="<?= htmlspecialchars((string)$r, ENT_QUOTES) ?>" <?= in_array((string)$r, $access['roles'], true) ? 'checked' : '' ?> <?= $r === 'admin' ? 'disabled checked' : '' ?>> <?= htmlspecialchars((string)$r, ENT_QUOTES) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="fba-field"><label>Users</label>
        <div class="fba-checks" style="max-height:180px;overflow-y:auto;border:1px solid var(--adam-border);border-radius:9px;padding:.6rem .8rem">
          <?php foreach ($allUsers as $u): ?>
          <label class="fba-check"><input type="checkbox" name="access_users[]" value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $access['users'], true) ? 'checked' : '' ?>> <?= htmlspecialchars($u['name'] !== '' ? $u['name'] : $u['email'], ENT_QUOTES) ?> <span class="fba-sub">(<?= htmlspecialchars((string)$u['role'], ENT_QUOTES) ?>)</span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <button class="fba-btn primary" type="submit">Save access</button>
    </form>
  </div>
</div>
