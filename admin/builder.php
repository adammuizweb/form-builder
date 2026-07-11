<?php
// /plugins/form-builder/admin/builder.php  ($form, $pdo, $uid, $role, $csrf in scope)
declare(strict_types=1);

$formId = (int)$form['id'];
$types = fb_field_types();

// ---------------- Builder POST actions ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && function_exists('csrf_check') && csrf_check($_POST['csrf_token'] ?? '')) {
    $act = (string)($_POST['fb_action'] ?? '');

    if ($act === 'add_field' && isset($types[$_POST['type'] ?? ''])) {
        $type = (string)$_POST['type'];
        $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM `fb_fields` WHERE form_id = {$formId}")->fetchColumn();
        $base = fb_normalize_key($types[$type]['label']);
        $key = $base; $i = 2;
        while (true) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM `fb_fields` WHERE form_id = ? AND field_key = ?');
            $st->execute([$formId, $key]);
            if ((int)$st->fetchColumn() === 0) break;
            $key = $base . '_' . $i++;
        }
        $pdo->prepare('INSERT INTO `fb_fields` (form_id, type, label, field_key, sort_order, width) VALUES (?, ?, ?, ?, ?, 12)')
            ->execute([$formId, $type, $types[$type]['label'], $key, $maxSort + 10]);
        $pdo->prepare('UPDATE `fb_forms` SET updated_at = NOW() WHERE id = ?')->execute([$formId]);
        header('Location: ' . fb_url(['view' => 'builder', 'id' => $formId]), true, 303);
        exit;
    }

    if ($act === 'save_field') {
        $fieldId = (int)($_POST['field_id'] ?? 0);
        $type = (string)($_POST['type'] ?? 'text');
        if (!isset($types[$type])) $type = 'text';
        $label = trim((string)($_POST['label'] ?? ''));
        $key = fb_normalize_key((string)($_POST['field_key'] ?? '') !== '' ? (string)$_POST['field_key'] : $label);
        // ensure key unique within form (excluding this field)
        $keyBase = $key; $i = 2;
        while (true) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM `fb_fields` WHERE form_id = ? AND field_key = ? AND id != ?');
            $st->execute([$formId, $key, $fieldId]);
            if ((int)$st->fetchColumn() === 0) break;
            $key = $keyBase . '_' . $i++;
        }
        $width = in_array((int)($_POST['width'] ?? 12), [3, 4, 6, 12], true) ? (int)$_POST['width'] : 12;

        // options editor: lines "value|Label|price"
        $options = [];
        if (!empty($types[$type]['options'])) {
            foreach (preg_split('/\r?\n/', (string)($_POST['options'] ?? '')) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $parts = array_map('trim', explode('|', $line));
                $val = fb_normalize_key($parts[0] ?? '');
                if ($val === '') continue;
                $options[] = [
                    'value' => $val,
                    'label' => ($parts[1] ?? '') !== '' ? $parts[1] : ($parts[0] ?? $val),
                    'price' => (int)preg_replace('/[^\d\-]/', '', (string)($parts[2] ?? '0')),
                ];
            }
        }

        // validation per type
        $validation = [];
        if ($type === 'number') {
            if (trim((string)($_POST['v_min'] ?? '')) !== '') $validation['min'] = trim((string)$_POST['v_min']);
            if (trim((string)($_POST['v_max'] ?? '')) !== '') $validation['max'] = trim((string)$_POST['v_max']);
        } elseif (in_array($type, ['text', 'tel', 'textarea'], true)) {
            if ((int)($_POST['v_maxlength'] ?? 0) > 0) $validation['maxlength'] = (int)$_POST['v_maxlength'];
            if (trim((string)($_POST['v_pattern'] ?? '')) !== '') $validation['pattern'] = trim((string)$_POST['v_pattern']);
        } elseif (in_array($type, ['file', 'image'], true)) {
            $validation['max_bytes'] = max(1, (int)($_POST['v_maxmb'] ?? 5)) * 1024 * 1024;
            $exts = array_values(array_filter(array_map(static fn($e) => strtolower(trim($e, " .")), explode(',', (string)($_POST['v_exts'] ?? '')))));
            if ($exts) $validation['exts'] = $exts;
        }

        $pdo->prepare('UPDATE `fb_fields` SET type = ?, label = ?, field_key = ?, placeholder = ?, help_text = ?, required = ?, width = ?, is_hidden = ?, options_json = ?, validation_json = ? WHERE id = ? AND form_id = ?')
            ->execute([
                $type, $label, $key,
                trim((string)($_POST['placeholder'] ?? '')) ?: null,
                trim((string)($_POST['help_text'] ?? '')) ?: null,
                !empty($_POST['required']) ? 1 : 0,
                $width,
                !empty($_POST['is_hidden']) ? 1 : 0,
                $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                $validation ? json_encode($validation, JSON_UNESCAPED_UNICODE) : null,
                $fieldId, $formId,
            ]);
        $pdo->prepare('UPDATE `fb_forms` SET updated_at = NOW() WHERE id = ?')->execute([$formId]);
        header('Location: ' . fb_url(['view' => 'builder', 'id' => $formId]), true, 303);
        exit;
    }

    if ($act === 'delete_field') {
        $pdo->prepare('DELETE FROM `fb_fields` WHERE id = ? AND form_id = ?')->execute([(int)$_POST['field_id'], $formId]);
        $pdo->prepare('UPDATE `fb_forms` SET updated_at = NOW() WHERE id = ?')->execute([$formId]);
        header('Location: ' . fb_url(['view' => 'builder', 'id' => $formId]), true, 303);
        exit;
    }

    if ($act === 'move_field') {
        $fieldId = (int)($_POST['field_id'] ?? 0);
        $dir = (string)($_POST['dir'] ?? 'up') === 'up' ? -1 : 1;
        $fields = fb_get_fields($pdo, $formId);
        $ids = array_map(static fn($f) => (int)$f['id'], $fields);
        $idx = array_search($fieldId, $ids, true);
        if ($idx !== false) {
            $swap = $idx + $dir;
            if (isset($ids[$swap])) {
                [$ids[$idx], $ids[$swap]] = [$ids[$swap], $ids[$idx]];
                $st = $pdo->prepare('UPDATE `fb_fields` SET sort_order = ? WHERE id = ?');
                foreach ($ids as $pos => $fid) $st->execute([($pos + 1) * 10, $fid]);
                $pdo->prepare('UPDATE `fb_forms` SET updated_at = NOW() WHERE id = ?')->execute([$formId]);
            }
        }
        header('Location: ' . fb_url(['view' => 'builder', 'id' => $formId]), true, 303);
        exit;
    }
}

$fields = fb_get_fields($pdo, $formId);
?>
<div class="fba">
  <div class="fba-head">
    <h1>Builder: <?= htmlspecialchars($form['title'], ENT_QUOTES) ?> <span class="fba-badge <?= ['active'=>'active','draft'=>'draft','archived'=>'arch'][$form['status']] ?? 'draft' ?>"><?= htmlspecialchars($form['status'], ENT_QUOTES) ?></span></h1>
    <div class="fba-actions">
      <a class="fba-btn" href="<?= fb_url(['view' => 'forms', 'id' => null]) ?>">&larr; Forms</a>
      <a class="fba-btn" href="<?= fb_url(['view' => 'submissions', 'id' => $formId]) ?>">Submissions</a>
      <a class="fba-btn" href="<?= fb_url(['view' => 'settings', 'id' => $formId]) ?>">Settings</a>
    </div>
  </div>

  <div class="fba-card">
    <span class="fba-hint">Shortcode:</span> <span class="fba-code">[form slug=&quot;<?= htmlspecialchars($form['slug'], ENT_QUOTES) ?>&quot;]</span>
    <span class="fba-hint">&mdash; paste it into any page or post. Click a field to edit it.</span>
  </div>

  <div class="fbb-layout">
    <div class="fbb-palette">
      <h3>Add Field</h3>
      <?php foreach ($types as $t => $meta): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="fb_action" value="add_field">
        <input type="hidden" name="type" value="<?= $t ?>">
        <button type="submit">+ <?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></button>
      </form>
      <?php endforeach; ?>
    </div>

    <div>
      <?php if (!$fields): ?>
        <div class="fba-empty">No fields yet. Add one from the palette.</div>
      <?php endif; ?>

      <?php foreach ($fields as $f):
        $fid = (int)$f['id'];
        $type = (string)$f['type'];
        $meta = $types[$type] ?? ['label' => $type];
        $opts = fb_field_options($f);
        $valid = fb_field_validation($f);
        $optLines = [];
        foreach ($opts as $o) $optLines[] = $o['value'] . '|' . $o['label'] . ($o['price'] !== 0 ? '|' . $o['price'] : '');
        ?>
      <div class="fbb-field <?= !empty($f['is_hidden']) ? 'hidden-f' : '' ?>" id="fb-field-<?= $fid ?>">
        <div class="fbb-field-head" onclick="this.parentElement.classList.toggle('open')">
          <span class="type"><?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></span>
          <span class="lbl"><?= htmlspecialchars($f['label'] !== '' ? $f['label'] : '(no label)', ENT_QUOTES) ?> <?= !empty($f['required']) ? '<span class="req-star">*</span>' : '' ?></span>
          <span class="key"><?= htmlspecialchars($f['field_key'], ENT_QUOTES) ?></span>
          <span class="fbb-order" onclick="event.stopPropagation()">
            <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>"><input type="hidden" name="fb_action" value="move_field"><input type="hidden" name="field_id" value="<?= $fid ?>"><input type="hidden" name="dir" value="up"><button title="Move up">&uarr;</button></form>
            <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>"><input type="hidden" name="fb_action" value="move_field"><input type="hidden" name="field_id" value="<?= $fid ?>"><input type="hidden" name="dir" value="down"><button title="Move down">&darr;</button></form>
          </span>
        </div>
        <div class="fbb-field-body">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="fb_action" value="save_field">
            <input type="hidden" name="field_id" value="<?= $fid ?>">
            <div class="fba-row3">
              <div class="fba-field"><label>Label</label><input type="text" name="label" value="<?= htmlspecialchars($f['label'], ENT_QUOTES) ?>"></div>
              <div class="fba-field"><label>Key</label><input type="text" name="field_key" value="<?= htmlspecialchars($f['field_key'], ENT_QUOTES) ?>"><div class="fba-hint">auto-normalized</div></div>
              <div class="fba-field"><label>Type</label>
                <select name="type">
                  <?php foreach ($types as $t => $m): ?>
                  <option value="<?= $t ?>" <?= $t === $type ? 'selected' : '' ?>><?= htmlspecialchars($m['label'], ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="fba-row3">
              <div class="fba-field"><label>Width</label>
                <select name="width">
                  <option value="12" <?= (int)$f['width'] === 12 ? 'selected' : '' ?>>Full (12/12)</option>
                  <option value="6" <?= (int)$f['width'] === 6 ? 'selected' : '' ?>>Half (6/12)</option>
                  <option value="4" <?= (int)$f['width'] === 4 ? 'selected' : '' ?>>Third (4/12)</option>
                  <option value="3" <?= (int)$f['width'] === 3 ? 'selected' : '' ?>>Quarter (3/12)</option>
                </select>
              </div>
              <div class="fba-field"><label>Placeholder</label><input type="text" name="placeholder" value="<?= htmlspecialchars((string)$f['placeholder'], ENT_QUOTES) ?>"></div>
              <div class="fba-field"><label>Help text</label><input type="text" name="help_text" value="<?= htmlspecialchars((string)$f['help_text'], ENT_QUOTES) ?>"></div>
            </div>

            <?php if (!empty($meta['options'])): ?>
            <div class="fba-field"><label>Options — one per line: <span class="fba-mono">value|Label|price</span></label>
              <textarea name="options" rows="5" placeholder="s1|S1 UNISSULA|500000"><?= htmlspecialchars(implode("\n", $optLines), ENT_QUOTES) ?></textarea>
              <div class="fba-hint">Price is optional; used by the Total element (Settings → Show total).</div>
            </div>
            <?php endif; ?>

            <?php if ($type === 'number'): ?>
            <div class="fba-row2">
              <div class="fba-field"><label>Min</label><input type="number" step="any" name="v_min" value="<?= htmlspecialchars((string)($valid['min'] ?? ''), ENT_QUOTES) ?>"></div>
              <div class="fba-field"><label>Max</label><input type="number" step="any" name="v_max" value="<?= htmlspecialchars((string)($valid['max'] ?? ''), ENT_QUOTES) ?>"></div>
            </div>
            <?php elseif (in_array($type, ['text', 'tel', 'textarea'], true)): ?>
            <div class="fba-row2">
              <div class="fba-field"><label>Max length</label><input type="number" name="v_maxlength" value="<?= (int)($valid['maxlength'] ?? 0) ?: '' ?>"></div>
              <div class="fba-field"><label>Pattern (regex)</label><input type="text" name="v_pattern" value="<?= htmlspecialchars((string)($valid['pattern'] ?? ''), ENT_QUOTES) ?>" placeholder="^\d{16}$"></div>
            </div>
            <?php elseif (in_array($type, ['file', 'image'], true)): ?>
            <div class="fba-row2">
              <div class="fba-field"><label>Max size (MB)</label><input type="number" name="v_maxmb" value="<?= (int)round(($valid['max_bytes'] ?? 5242880) / 1048576) ?>"></div>
              <div class="fba-field"><label>Allowed extensions</label><input type="text" name="v_exts" value="<?= htmlspecialchars(implode(', ', (array)($valid['exts'] ?? ($type === 'image' ? ['jpg','jpeg','png','webp'] : ['jpg','jpeg','png','pdf','doc','docx']))), ENT_QUOTES) ?>"></div>
            </div>
            <?php endif; ?>

            <div class="fba-checks" style="margin:.4rem 0 .9rem">
              <label class="fba-check"><input type="checkbox" name="required" value="1" <?= !empty($f['required']) ? 'checked' : '' ?>> Required</label>
              <label class="fba-check"><input type="checkbox" name="is_hidden" value="1" <?= !empty($f['is_hidden']) ? 'checked' : '' ?>> Hidden</label>
            </div>
            <button class="fba-btn primary" type="submit">Save field</button>
          </form>
          <form method="post" style="margin-top:.6rem" onsubmit="return confirm('Delete this field? Existing submission data for it stays stored but hidden.')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="fb_action" value="delete_field">
            <input type="hidden" name="field_id" value="<?= $fid ?>">
            <button class="fba-btn sm danger" type="submit">Delete field</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
