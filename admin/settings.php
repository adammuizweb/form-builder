<?php
// /plugins/form-builder/admin/settings.php  ($form, $pdo, $uid, $csrf in scope)
declare(strict_types=1);

$formId = (int)$form['id'];
$settings = fb_form_settings($form);
$access = fb_form_access($form);
$fields = fb_flat_fields(fb_get_fields($pdo, $formId));
$types = fb_field_types();
$inputFields = array_values(array_filter($fields, static fn($f) => !empty($types[$f['type']]['input']) && empty($types[$f['type']]['file'])));
$successValueFields = array_values(array_filter($inputFields, static fn(array $field): bool => empty($field['is_hidden'])));

$saved = false;
$canDelegate = user_can($pdo, $uid, 'plugin.form-builder.forms.manage-any') || $access['owner'] === $uid;
$canUnsafeCode = user_can($pdo, $uid, 'plugin.form-builder.unsafe-code.manage');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!function_exists('csrf_check') || !csrf_check($_POST['csrf_token'] ?? '')) {
        echo '<div class="fba-empty">Invalid CSRF token.</div>';
        return;
    }
    try {
        $mutationLock = fb_acquire_form_mutation_lock($pdo, $formId);
        register_shutdown_function(static function () use ($pdo, $mutationLock): void { fb_release_form_mutation_lock($pdo, $mutationLock); });
    } catch (UnexpectedValueException $error) {
        echo '<div class="fba-empty">' . htmlspecialchars($error->getMessage(), ENT_QUOTES) . '</div>';
        return;
    }
    $form = fb_get_form($pdo, $formId);
    if ($form === null || !empty($form['deleted_at']) || !fb_can_access_form($pdo, $form, $uid)) {
        echo '<div class="fba-empty">Form not found or access denied.</div>';
        return;
    }
    $settings = fb_form_settings($form);
    $access = fb_form_access($form);
    $fields = fb_flat_fields(fb_get_fields($pdo, $formId));
    $inputFields = array_values(array_filter($fields, static fn($field) => !empty($types[$field['type']]['input']) && empty($types[$field['type']]['file'])));
    $successValueFields = array_values(array_filter($inputFields, static fn(array $field): bool => empty($field['is_hidden'])));
    $canDelegate = user_can($pdo, $uid, 'plugin.form-builder.forms.manage-any') || $access['owner'] === $uid;
    $act = (string)($_POST['fb_action'] ?? '');

    if ($act === 'save_general') {
        $title = trim((string)($_POST['title'] ?? '')) ?: 'Untitled Form';
        $slug = fb_unique_form_slug($pdo, (string)($_POST['slug'] ?? '') !== '' ? (string)$_POST['slug'] : $title, $formId);
        $status = in_array(($_POST['status'] ?? ''), ['active', 'draft', 'archived'], true) ? (string)$_POST['status'] : 'draft';
        if (($form['status'] ?? '') === 'archived' && $status !== 'archived') {
            echo '<div class="fba-empty">Use the Archived forms view to reactivate this form safely as a draft.</div>';
            return;
        }
        $recaptchaLock = null;
        try {
            if ($status === 'active' && $settings['recaptcha'] === '1') {
                $recaptchaLock = fb_acquire_recaptcha_config_lock($pdo);
                if (!fb_recaptcha_configured($pdo, true)) {
                    echo '<div class="fba-empty">Configure both global reCAPTCHA keys before activating this form.</div>';
                    return;
                }
            }
            $pdo->prepare('UPDATE `fb_forms` SET title = ?, slug = ?, description = ?, status = ? WHERE id = ?')
                ->execute([$title, $slug, trim((string)($_POST['description'] ?? '')) ?: null, $status, $formId]);
            $saved = true;
        } catch (UnexpectedValueException $error) {
            echo '<div class="fba-empty">' . htmlspecialchars($error->getMessage(), ENT_QUOTES) . '</div>';
            return;
        } finally {
            if ($recaptchaLock !== null) fb_release_recaptcha_config_lock($pdo, $recaptchaLock);
        }
    }

    if ($act === 'save_submission') {
        $settings['submit_label'] = trim((string)($_POST['submit_label'] ?? 'Submit')) ?: 'Submit';
        $successMessage = $_POST['success_message'] ?? '';
        $settings['success_message'] = is_string($successMessage) ? trim(mb_substr($successMessage, 0, 4000)) : '';
        $settings['success_detail_template'] = '';
        $successMessageCase = $_POST['success_message_case'] ?? 'preserve';
        $settings['success_message_case'] = is_string($successMessageCase) && in_array($successMessageCase, ['preserve', 'upper'], true) ? $successMessageCase : 'preserve';
        $successField = $_POST['success_detail_field'] ?? '';
        $successFieldKeys = array_column($successValueFields, 'field_key');
        $settings['success_detail_field'] = is_string($successField) && in_array($successField, $successFieldKeys, true) ? $successField : '';
        $settings['submission_proof_enabled'] = !empty($_POST['submission_proof_enabled']) ? '1' : '0';
        $proofFormat = $_POST['submission_proof_format'] ?? 'png';
        $settings['submission_proof_format'] = is_string($proofFormat) && in_array($proofFormat, ['png', 'pdf'], true) ? $proofFormat : 'png';
        $enableRecaptcha = !empty($_POST['recaptcha']);
        $settings['recaptcha'] = $enableRecaptcha ? '1' : '0';
        $settings['rate_max'] = max(1, min(10000, (int)($_POST['rate_max'] ?? 10)));
        $settings['rate_window'] = max(60, min(604800, (int)($_POST['rate_window'] ?? 3600)));
        $settings['notify_email'] = trim((string)($_POST['notify_email'] ?? ''));
        if ($settings['notify_email'] !== '' && filter_var($settings['notify_email'], FILTER_VALIDATE_EMAIL) === false) $settings['notify_email'] = '';
        $emailKeys = array_column(array_filter($inputFields, static fn(array $f): bool => $f['type'] === 'email'), 'field_key');
        foreach (['confirmation_email_field', 'reply_to_email_field'] as $emailSetting) {
            $candidate = (string)($_POST[$emailSetting] ?? '');
            $settings[$emailSetting] = in_array($candidate, $emailKeys, true) ? $candidate : '';
        }
        $statuses = array_values(array_unique(array_filter(array_map(static fn(string $v): string => strtolower(trim($v)), explode(',', (string)($_POST['workflow_statuses'] ?? ''))), static fn(string $v): bool => preg_match('/\A[a-z][a-z0-9_-]{0,39}\z/', $v) === 1)));
        $settings['workflow_statuses'] = $statuses ?: fb_default_settings()['workflow_statuses'];
        $settings['min_fill_seconds'] = max(0, min(30, (int)($_POST['min_fill_seconds'] ?? 2)));
        $settings['show_total'] = !empty($_POST['show_total']) ? '1' : '0';
        $settings['total_label'] = trim((string)($_POST['total_label'] ?? 'Total')) ?: 'Total';
        $currencyCode = strtoupper(trim((string)($_POST['currency_code'] ?? 'USD')));
        $settings['currency_code'] = preg_match('/\A[A-Z]{3}\z/', $currencyCode) === 1 ? $currencyCode : 'USD';
        $settings['columns'] = array_values(array_filter(array_map('strval', (array)($_POST['columns'] ?? []))));
        $recaptchaLock = null;
        try {
            if ($enableRecaptcha) {
                $recaptchaLock = fb_acquire_recaptcha_config_lock($pdo);
                if (!fb_recaptcha_configured($pdo, true)) {
                    echo '<div class="fba-empty">Configure both global reCAPTCHA keys before enabling reCAPTCHA for this form.</div>';
                    return;
                }
            }
            $pdo->prepare('UPDATE `fb_forms` SET settings_json = ? WHERE id = ?')
                ->execute([fb_json_encode($settings), $formId]);
            $saved = true;
        } catch (UnexpectedValueException $error) {
            echo '<div class="fba-empty">' . htmlspecialchars($error->getMessage(), ENT_QUOTES) . '</div>';
            return;
        } finally {
            if ($recaptchaLock !== null) fb_release_recaptcha_config_lock($pdo, $recaptchaLock);
        }
    }

    if ($act === 'save_display') {
        $presets = fb_accent_presets();
        $accent = (string)($_POST['accent'] ?? 'green');
        $settings['accent'] = isset($presets[$accent]) ? $accent : 'green';
        if ($canUnsafeCode) $settings['unsafe_code_enabled'] = true;
        $pdo->prepare('UPDATE `fb_forms` SET settings_json = ?, css = ?, js = ? WHERE id = ?')
            ->execute([
                fb_json_encode($settings),
                $canUnsafeCode ? (trim((string)($_POST['css'] ?? '')) !== '' ? (string)$_POST['css'] : null) : $form['css'],
                $canUnsafeCode ? (trim((string)($_POST['js'] ?? '')) !== '' ? (string)$_POST['js'] : null) : $form['js'],
                $formId,
            ]);
        $saved = true;
    }

    if ($act === 'save_access') {
        if (!$canDelegate) {
            echo '<div class="fba-empty">Access denied.</div>';
            return;
        }
        $roles = array_values(array_filter(array_map('strval', (array)($_POST['access_roles'] ?? []))));
        $users = array_values(array_filter(array_map('intval', (array)($_POST['access_users'] ?? []))));
        $submissionRoles = array_values(array_filter(array_map('strval', (array)($_POST['submission_roles'] ?? []))));
        $submissionUsers = array_values(array_filter(array_map('intval', (array)($_POST['submission_users'] ?? []))));
        $access['roles'] = $roles;
        $access['users'] = $users;
        $access['submissions'] = ['roles' => $submissionRoles, 'users' => $submissionUsers];
        $pdo->prepare('UPDATE `fb_forms` SET access_json = ? WHERE id = ?')
            ->execute([fb_json_encode($access), $formId]);
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

$allRoles = $pdo->query("SELECT slug FROM `roles` ORDER BY is_system DESC, authority_rank DESC, name")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$allUsers = $pdo->query("SELECT id, name, email, role FROM `users` WHERE is_deleted = 0 ORDER BY name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="fba">
  <div class="fba-head">
    <h1>Settings: <?= htmlspecialchars($form['title'], ENT_QUOTES) ?></h1>
    <div class="fba-actions">
      <a class="fba-btn" href="<?= fb_url(['view' => 'forms', 'id' => null]) ?>">&larr; Forms</a>
      <?php if ($form['status'] !== 'archived'): ?><a class="fba-btn" href="<?= fb_url(['view' => 'builder', 'id' => $formId]) ?>">Builder</a><?php endif; ?>
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
            <?php $statusOptions = $form['status'] === 'archived' ? ['archived' => 'Archived'] : ['draft' => 'Draft (not accepting)', 'active' => 'Active (accepting)', 'archived' => 'Archived']; foreach ($statusOptions as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $form['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($form['status'] === 'archived'): ?><div class="fba-hint">Reactivate from the Archived forms view before publishing.</div><?php endif; ?>
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
        <div class="fba-field"><label>Success notification and proof message</label><textarea name="success_message" rows="3" maxlength="4000" placeholder="Your submission for {value} has been received." style="font-family:inherit"><?= htmlspecialchars(fb_success_notification_template($settings), ENT_QUOTES) ?></textarea><div class="fba-hint">This exact message is shown after submission and included in the downloadable proof. <span class="fba-code">{value}</span> inserts the selected field's submitted display value; <span class="fba-code">{label}</span> inserts that field's label.</div></div>
        <div class="fba-field"><label>Notification email</label><input type="email" name="notify_email" value="<?= htmlspecialchars($settings['notify_email'], ENT_QUOTES) ?>" placeholder="admin@example.com"><div class="fba-hint">Sent after persistence through the Core Mail API.</div></div>
      </div>
      <div class="fba-row2">
        <div class="fba-field"><label>Dynamic message value</label><select name="success_detail_field"><option value="">No dynamic value</option><?php foreach ($successValueFields as $successValueField): ?><option value="<?= htmlspecialchars($successValueField['field_key'], ENT_QUOTES) ?>" <?= $settings['success_detail_field'] === $successValueField['field_key'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($successValueField['label'] ?: $successValueField['field_key']), ENT_QUOTES) ?></option><?php endforeach; ?></select><div class="fba-hint">For choice fields, <span class="fba-code">{value}</span> uses the visitor-facing option label rather than its stored key.</div></div>
        <div class="fba-field"><label>Message letter case</label><select name="success_message_case"><option value="preserve" <?= $settings['success_message_case'] === 'preserve' ? 'selected' : '' ?>>As entered</option><option value="upper" <?= $settings['success_message_case'] === 'upper' ? 'selected' : '' ?>>UPPERCASE</option></select><div class="fba-hint">Applied to the complete message, including dynamic labels and values, on both the success screen and proof.</div></div>
      </div>
      <div class="fba-row2">
        <div class="fba-field"><label class="fba-check"><input type="checkbox" name="submission_proof_enabled" value="1" <?= $settings['submission_proof_enabled'] === '1' ? 'checked' : '' ?>> Enable downloadable submission proof</label><div class="fba-hint">Shown only after a verified successful submission. The proof is generated in the visitor's browser and is not stored on the server.</div></div>
        <div class="fba-field"><label>Submission proof format</label><select name="submission_proof_format"><option value="png" <?= $settings['submission_proof_format'] === 'png' ? 'selected' : '' ?>>PNG image</option><option value="pdf" <?= $settings['submission_proof_format'] === 'pdf' ? 'selected' : '' ?>>PDF document</option></select></div>
      </div>
      <div class="fba-row3">
        <div class="fba-field"><label>Confirmation email field</label><select name="confirmation_email_field"><option value="">Disabled</option><?php foreach ($inputFields as $ef) if ($ef['type'] === 'email'): ?><option value="<?= htmlspecialchars($ef['field_key'], ENT_QUOTES) ?>" <?= $settings['confirmation_email_field'] === $ef['field_key'] ? 'selected' : '' ?>><?= htmlspecialchars($ef['label'], ENT_QUOTES) ?></option><?php endif; ?></select></div>
        <div class="fba-field"><label>Reply-to email field</label><select name="reply_to_email_field"><option value="">Default</option><?php foreach ($inputFields as $ef) if ($ef['type'] === 'email'): ?><option value="<?= htmlspecialchars($ef['field_key'], ENT_QUOTES) ?>" <?= $settings['reply_to_email_field'] === $ef['field_key'] ? 'selected' : '' ?>><?= htmlspecialchars($ef['label'], ENT_QUOTES) ?></option><?php endif; ?></select></div>
        <div class="fba-field"><label>Minimum fill time (seconds)</label><input type="number" min="0" max="30" name="min_fill_seconds" value="<?= (int)$settings['min_fill_seconds'] ?>"></div>
      </div>
      <div class="fba-field"><label>Workflow statuses (comma-separated)</label><input type="text" name="workflow_statuses" value="<?= htmlspecialchars(implode(',', $settings['workflow_statuses']), ENT_QUOTES) ?>"></div>
      <div class="fba-checks" style="margin:.3rem 0 .8rem">
        <label class="fba-check"><input type="checkbox" name="recaptcha" value="1" <?= $settings['recaptcha'] === '1' ? 'checked' : '' ?>> Enable reCAPTCHA v2 checkbox</label>
        <label class="fba-check"><input type="checkbox" name="show_total" value="1" <?= $settings['show_total'] === '1' ? 'checked' : '' ?>> Show total (sums priced options)</label>
        <div class="fba-hint" style="margin-top:.3rem">Legacy: total otomatis di akhir form. <strong>Diabaikan</strong> jika ada element <strong>Total</strong> di canvas builder (cara yang disarankan — posisi &amp; alignment bisa diatur).</div>
      </div>
      <div class="fba-row3">
        <div class="fba-field"><label>Total label</label><input type="text" name="total_label" value="<?= htmlspecialchars($settings['total_label'], ENT_QUOTES) ?>"></div>
        <div class="fba-field"><label>Currency code</label><input type="text" name="currency_code" value="<?= htmlspecialchars($settings['currency_code'], ENT_QUOTES) ?>" minlength="3" maxlength="3" pattern="[A-Za-z]{3}"><div class="fba-hint">ISO 4217 code, for example USD or EUR.</div></div>
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
    <div class="fba-sec">Display<?= $canUnsafeCode ? ' &amp; Custom CSS/JS' : '' ?></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="fb_action" value="save_display">
      <div class="fba-field"><label>Warna aksen form</label>
        <div class="fba-accents">
          <?php foreach (fb_accent_presets() as $ak => $ap): ?>
          <label class="fba-accent-opt" title="<?= htmlspecialchars($ap['label'], ENT_QUOTES) ?>">
            <input type="radio" name="accent" value="<?= $ak ?>" <?= ($settings['accent'] ?? 'green') === $ak ? 'checked' : '' ?>>
            <span class="fba-swatch" style="background:<?= htmlspecialchars($ap['accent'], ENT_QUOTES) ?>"></span>
            <span><?= htmlspecialchars($ap['label'], ENT_QUOTES) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="fba-hint">Warna tombol submit, focus, harga, dropzone &amp; total. Aman untuk semua tema (di-scope ke form). Untuk kustomisasi penuh, override via Custom CSS di bawah.</div>
      </div>
      <?php if ($canUnsafeCode): ?><div class="fba-field"><label>Custom CSS</label>
        <textarea name="css" rows="6" placeholder="#fb-<?= htmlspecialchars($form['slug'], ENT_QUOTES) ?> { --fb-accent: #1a73e8; }"><?= htmlspecialchars((string)$form['css'], ENT_QUOTES) ?></textarea>
        <div class="fba-hint">Injected after the base styles. Target the wrapper <span class="fba-code">#fb-<?= htmlspecialchars($form['slug'], ENT_QUOTES) ?></span>.</div>
      </div>
      <div class="fba-field"><label>Custom JavaScript</label>
        <textarea name="js" rows="6" placeholder="// root = form wrapper element, form = the &lt;form&gt;&#10;console.log(root, form);"><?= htmlspecialchars((string)$form['js'], ENT_QUOTES) ?></textarea>
        <div class="fba-hint">Wrapped in <span class="fba-mono">(function(root, form){ … })</span>. Runs after the base script.</div>
      </div>
      <?php else: ?><p class="fba-hint">Custom code requires the Unsafe Form Code permission and remains disabled.</p><?php endif; ?>
      <button class="fba-btn primary" type="submit">Save display</button>
    </form>
  </div>

  <?php if ($canDelegate): ?><div class="fba-card">
    <div class="fba-sec">Access Control</div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="fb_action" value="save_access">
      <p class="fba-hint" style="margin-bottom:.8rem">Form owners and users with Manage Any Form access can manage this ACL. All grants below also require Form Builder workspace access.</p>
      <div class="fba-field"><label>Form editor roles</label>
        <div class="fba-checks">
          <?php foreach ($allRoles as $r): ?>
          <label class="fba-check"><input type="checkbox" name="access_roles[]" value="<?= htmlspecialchars((string)$r, ENT_QUOTES) ?>" <?= in_array((string)$r, $access['roles'], true) ? 'checked' : '' ?>> <?= htmlspecialchars((string)$r, ENT_QUOTES) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="fba-field"><label>Form editor users</label>
        <div class="fba-checks" style="max-height:180px;overflow-y:auto;border:1px solid var(--adam-border);border-radius:9px;padding:.6rem .8rem">
          <?php foreach ($allUsers as $u): ?>
          <label class="fba-check"><input type="checkbox" name="access_users[]" value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $access['users'], true) ? 'checked' : '' ?>> <?= htmlspecialchars($u['name'] !== '' ? $u['name'] : $u['email'], ENT_QUOTES) ?> <span class="fba-sub">(<?= htmlspecialchars((string)$u['role'], ENT_QUOTES) ?>)</span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="fba-field"><label>Submission viewer roles</label>
        <div class="fba-checks">
          <?php foreach ($allRoles as $r): ?>
          <label class="fba-check"><input type="checkbox" name="submission_roles[]" value="<?= htmlspecialchars((string)$r, ENT_QUOTES) ?>" <?= in_array((string)$r, $access['submissions']['roles'], true) ? 'checked' : '' ?>> <?= htmlspecialchars((string)$r, ENT_QUOTES) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="fba-field"><label>Submission viewer users</label>
        <div class="fba-checks" style="max-height:180px;overflow-y:auto;border:1px solid var(--adam-border);border-radius:9px;padding:.6rem .8rem">
          <?php foreach ($allUsers as $u): ?>
          <label class="fba-check"><input type="checkbox" name="submission_users[]" value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $access['submissions']['users'], true) ? 'checked' : '' ?>> <?= htmlspecialchars($u['name'] !== '' ? $u['name'] : $u['email'], ENT_QUOTES) ?> <span class="fba-sub">(<?= htmlspecialchars((string)$u['role'], ENT_QUOTES) ?>)</span></label>
          <?php endforeach; ?>
        </div>
        <div class="fba-hint">Viewers can inspect, download, and export this form's submissions. Destructive actions also require form editor access and the global submission permission; workflow changes additionally require the workflow permission.</div>
      </div>
      <button class="fba-btn primary" type="submit">Save access</button>
    </form>
  </div><?php endif; ?>
</div>
