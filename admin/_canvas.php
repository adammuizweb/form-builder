<?php
// /plugins/form-builder/admin/_canvas.php — canvas + field-editor fragments
declare(strict_types=1);

function fb_render_canvas(array $form, array $tree): string {
    $types = fb_field_types();
    ob_start(); ?>
    <div class="fbc-canvas" id="fbcCanvas">
      <?php foreach ($tree as $i => $row):
        $rowId = (int)$row['node']['id'];
        $n = max(1, count($row['cols'])); ?>
      <div class="fbc-row" data-node="<?= $rowId ?>">
        <div class="fbc-row-bar">
          <span class="fbc-grip" draggable="true" data-grip="<?= $rowId ?>" title="Drag to reorder row">⠿</span>
          <span class="fbc-row-title">Row <?= $i + 1 ?></span>
          <label class="fbc-cols-lbl">Columns
            <select class="fbc-cols-sel" data-row="<?= $rowId ?>">
              <?php for ($c = 1; $c <= 4; $c++): ?>
              <option value="<?= $c ?>" <?= $c === $n ? 'selected' : '' ?>><?= $c ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <button type="button" class="fbc-row-del" data-del="<?= $rowId ?>" title="Delete row">×</button>
        </div>
        <div class="fbc-cols" style="grid-template-columns:repeat(<?= $n ?>,minmax(0,1fr))">
          <?php foreach ($row['cols'] as $col):
            $colId = (int)$col['node']['id']; ?>
          <div class="fbc-col" data-node="<?= $colId ?>" data-parent="<?= $rowId ?>">
            <?php foreach ($col['fields'] as $f):
              $meta = $types[$f['type']] ?? ['label' => $f['type']]; ?>
            <div class="fbc-chip<?= !empty($f['is_hidden']) ? ' is-hidden' : '' ?>" draggable="true" data-node="<?= (int)$f['id'] ?>">
              <span class="fbc-chip-type"><?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></span>
              <span class="fbc-chip-lbl"><?= htmlspecialchars($f['label'] !== '' ? $f['label'] : '(no label)', ENT_QUOTES) ?><?= !empty($f['required']) ? ' <b class="req">*</b>' : '' ?></span>
              <span class="fbc-chip-key"><?= htmlspecialchars($f['field_key'], ENT_QUOTES) ?></span>
              <button type="button" class="fbc-chip-edit" data-edit="<?= (int)$f['id'] ?>" title="Edit">✎</button>
              <button type="button" class="fbc-chip-del" data-del="<?= (int)$f['id'] ?>" title="Delete">×</button>
            </div>
            <?php endforeach; ?>
            <?php if (!$col['fields']): ?><div class="fbc-empty">Drop a field here</div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$tree): ?>
      <div class="fba-empty" style="padding:2rem">Canvas kosong. Klik <strong>+ Add Row</strong> untuk mulai, lalu drag field dari palette ke kolom.</div>
      <?php endif; ?>
      <div class="fbc-add-row">
        <button type="button" class="fba-btn primary" id="fbcAddRow">+ Add Row</button>
        <span class="fba-hint">default 2 kolom — bisa diubah per baris</span>
      </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

// Field settings form fragment (loaded into the side panel via AJAX).
function fb_render_field_form(array $f): string {
    $types = fb_field_types();
    $fid = (int)$f['id'];
    $type = (string)$f['type'];
    $meta = $types[$type] ?? ['label' => $type];
    $valid = fb_field_validation($f);
    $optLines = [];
    foreach (fb_field_options($f) as $o) $optLines[] = $o['value'] . '|' . $o['label'] . ($o['price'] !== 0 ? '|' . $o['price'] : '');
    ob_start(); ?>
    <form id="fbcFieldForm">
      <input type="hidden" name="field_id" value="<?= $fid ?>">
      <div class="fba-field"><label>Label</label><input type="text" name="label" value="<?= htmlspecialchars($f['label'], ENT_QUOTES) ?>"></div>
      <div class="fba-row2">
        <div class="fba-field"><label>Key</label><input type="text" name="field_key" value="<?= htmlspecialchars($f['field_key'], ENT_QUOTES) ?>"></div>
        <div class="fba-field"><label>Type</label>
          <select name="type">
            <?php foreach ($types as $t => $m): if (!empty($m['container'])) continue; ?>
            <option value="<?= $t ?>" <?= $t === $type ? 'selected' : '' ?>><?= htmlspecialchars($m['label'], ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="fba-row2">
        <div class="fba-field"><label>Placeholder</label><input type="text" name="placeholder" value="<?= htmlspecialchars((string)$f['placeholder'], ENT_QUOTES) ?>"></div>
        <div class="fba-field"><label>Help text</label><input type="text" name="help_text" value="<?= htmlspecialchars((string)$f['help_text'], ENT_QUOTES) ?>"></div>
      </div>
      <?php if (!empty($meta['options'])): ?>
      <div class="fba-field"><label>Options — one per line: <span class="fba-mono">value|Label|price</span></label>
        <textarea name="options" rows="5" placeholder="s1|S1 UNISSULA|500000"><?= htmlspecialchars(implode("\n", $optLines), ENT_QUOTES) ?></textarea>
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
        <div class="fba-field"><label>Pattern (regex)</label><input type="text" name="v_pattern" value="<?= htmlspecialchars((string)($valid['pattern'] ?? ''), ENT_QUOTES) ?>"></div>
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
      <div style="display:flex;gap:.5rem">
        <button class="fba-btn primary" type="submit">Save field</button>
        <button class="fba-btn danger" type="button" id="fbcFieldDelete" data-del="<?= $fid ?>">Delete field</button>
      </div>
    </form>
    <?php
    return (string)ob_get_clean();
}
