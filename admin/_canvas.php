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
          <button type="button" class="fbc-row-del" data-del="<?= $rowId ?>" title="Pindahkan row ke Bin">×</button>
        </div>
        <div class="fbc-cols" style="grid-template-columns:repeat(<?= $n ?>,minmax(0,1fr))">
          <?php foreach ($row['cols'] as $col):
            $colId = (int)$col['node']['id']; ?>
          <div class="fbc-col" data-node="<?= $colId ?>" data-parent="<?= $rowId ?>">
            <?php foreach ($col['fields'] as $f):
              $meta = $types[$f['type']] ?? ['label' => $f['type']];
              $fsC = fb_field_settings($f);
              $alI = ['center' => 'C', 'right' => 'R'][$fsC['align'] ?? ''] ?? '';
              $vaI = ['middle' => 'M', 'bottom' => 'B'][$fsC['valign'] ?? ''] ?? ''; ?>
            <div class="fbc-chip<?= !empty($f['is_hidden']) ? ' is-hidden' : '' ?><?= ($meta['group'] ?? '') === 'element' ? ' is-el' : '' ?>" draggable="true" data-node="<?= (int)$f['id'] ?>">
              <span class="fbc-chip-type"><?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></span>
              <span class="fbc-chip-lbl"><?= htmlspecialchars($f['label'] !== '' ? $f['label'] : '(no label)', ENT_QUOTES) ?><?= !empty($f['required']) ? ' <b class="req">*</b>' : '' ?></span>
              <?php if ($alI || $vaI): ?><span class="fbc-chip-al" title="Perataan: <?= $alI !== '' ? ($alI === 'C' ? 'tengah' : 'kanan') : 'kiri' ?><?= $vaI !== '' ? ' / vertikal ' . ($vaI === 'M' ? 'tengah' : 'bawah') : '' ?>"><?= $alI . ($alI !== '' && $vaI !== '' ? '·' : '') . $vaI ?></span><?php endif; ?>
              <span class="fbc-chip-key"><?= htmlspecialchars($f['field_key'], ENT_QUOTES) ?></span>
              <button type="button" class="fbc-chip-edit" data-edit="<?= (int)$f['id'] ?>" title="Edit">✎</button>
              <button type="button" class="fbc-chip-del" data-del="<?= (int)$f['id'] ?>" title="Pindahkan ke Bin">×</button>
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
// Type is immutable after creation (shown as read-only badge).
function fb_render_field_form(array $f): string {
    $types = fb_field_types();
    $fid = (int)$f['id'];
    $type = (string)$f['type'];
    $meta = $types[$type] ?? ['label' => $type];
    $valid = fb_field_validation($f);
    $fs = fb_field_settings($f);
    $isInput = !empty($meta['input']);
    $editor = (string)($meta['editor'] ?? '');
    $optLines = [];
    foreach (fb_field_options($f) as $o) $optLines[] = $o['value'] . '|' . $o['label'] . ($o['price'] !== 0 ? '|' . $o['price'] : '');
    ob_start(); ?>
    <form id="fbcFieldForm" data-type="<?= $type ?>" data-editor="<?= $editor ?>">
      <input type="hidden" name="field_id" value="<?= $fid ?>">
      <input type="hidden" name="field_key" value="<?= htmlspecialchars($f['field_key'], ENT_QUOTES) ?>">
      <div style="margin-bottom:.7rem">
        <span class="fbc-chip-type" style="font-size:.62rem"><?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></span>
        <span class="fba-hint"><?= $isInput ? 'input' : 'element' ?> — type tidak bisa diubah; hapus &amp; buat baru untuk ganti type.</span>
      </div>

      <?php if ($type === 'richtext' || $type === 'raw_html'): ?>
      <div class="fba-field"><label>Admin label (tidak tampil di publik)</label><input type="text" name="label" value="<?= htmlspecialchars($f['label'], ENT_QUOTES) ?>"></div>
      <input type="hidden" name="s_html" id="fbcContentInput" value="<?= htmlspecialchars((string)($fs['html'] ?? ''), ENT_QUOTES) ?>">
      <div class="fbc-content-prev" id="fbcContentPrev"><?= (string)($fs['html'] ?? '') !== '' ? (string)$fs['html'] : '<span class="fba-hint">(belum ada konten)</span>' ?></div>
      <button type="button" class="fba-btn primary" id="fbcOpenEditor" data-editor="<?= $editor ?>" style="margin:.4rem 0 1rem"><?= $editor === 'code' ? 'Edit HTML (CodeMirror)' : 'Edit Content (Rich Text)' ?></button>

      <?php elseif ($type === 'image_block'): ?>
      <div class="fba-field"><label>Admin label</label><input type="text" name="label" value="<?= htmlspecialchars($f['label'], ENT_QUOTES) ?>"></div>
      <input type="hidden" name="s_url" id="fbcImgUrl" value="<?= htmlspecialchars((string)($fs['url'] ?? ''), ENT_QUOTES) ?>">
      <div class="fbc-img-prev" id="fbcImgPrev">
        <?php if (($fs['url'] ?? '') !== ''): ?><img src="<?= htmlspecialchars((string)$fs['url'], ENT_QUOTES) ?>" alt=""><?php else: ?><span class="fba-hint">(belum ada gambar)</span><?php endif; ?>
      </div>
      <button type="button" class="fba-btn primary" id="fbcPickImage" style="margin:.4rem 0 .8rem">Pilih dari Gallery</button>
      <div class="fba-field"><label>Alt text</label><input type="text" name="s_alt" value="<?= htmlspecialchars((string)($fs['alt'] ?? ''), ENT_QUOTES) ?>"></div>
      <div class="fba-field"><label>Caption</label><input type="text" name="s_caption" value="<?= htmlspecialchars((string)($fs['caption'] ?? ''), ENT_QUOTES) ?>"></div>
      <div class="fba-field"><label>Lebar</label>
        <select name="s_width">
          <?php foreach (['100' => 'Full (100%)', '75' => '75%', '50' => '50%', '25' => '25%'] as $wv => $wl): ?>
          <option value="<?= $wv === '100' ? '' : $wv ?>" <?= (($fs['width'] ?? '') === ($wv === '100' ? '' : $wv)) ? 'selected' : '' ?>><?= $wl ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php else: ?>
      <?php if ($type === 'paragraph'): ?>
      <div class="fba-field"><label>Teks paragraf</label><textarea name="label" rows="4"><?= htmlspecialchars($f['label'], ENT_QUOTES) ?></textarea></div>
      <?php elseif ($type !== 'divider'): ?>
      <div class="fba-field"><label>Label</label><input type="text" name="label" value="<?= htmlspecialchars($f['label'], ENT_QUOTES) ?>"></div>
      <?php endif; ?>

      <?php if ($type === 'heading'): ?>
      <div class="fba-field"><label>Level</label>
        <select name="s_level">
          <?php foreach (FB_HEADING_LEVELS as $hl): ?>
          <option value="<?= $hl ?>" <?= (($fs['level'] ?? 'h2') === $hl) ? 'selected' : '' ?>><?= strtoupper($hl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($isInput): ?>
      <div class="fba-row2">
        <div class="fba-field"><label>Key</label><input type="text" name="field_key" value="<?= htmlspecialchars($f['field_key'], ENT_QUOTES) ?>"></div>
        <div class="fba-field"><label>Placeholder</label><input type="text" name="placeholder" value="<?= htmlspecialchars((string)$f['placeholder'], ENT_QUOTES) ?>"></div>
      </div>
      <div class="fba-field"><label>Help text</label><input type="text" name="help_text" value="<?= htmlspecialchars((string)$f['help_text'], ENT_QUOTES) ?>"></div>
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
      <?php endif; /* inputs */ ?>

      <?php endif; /* per-type */ ?>
      <?php $curAlign = (string)($fs['align'] ?? ''); $curValign = (string)($fs['valign'] ?? ''); ?>
      <div class="fba-row2" style="margin-bottom:.9rem">
        <div class="fba-field"><label>Perataan horizontal</label>
          <select name="s_align">
            <option value="" <?= $curAlign === '' ? 'selected' : '' ?>>⯇ Kiri (default)</option>
            <option value="center" <?= $curAlign === 'center' ? 'selected' : '' ?>>≡ Tengah</option>
            <option value="right" <?= $curAlign === 'right' ? 'selected' : '' ?>>⯈ Kanan</option>
          </select>
        </div>
        <div class="fba-field"><label>Perataan vertikal (dalam kolom)</label>
          <select name="s_valign">
            <option value="" <?= $curValign === '' ? 'selected' : '' ?>>⤒ Atas (default)</option>
            <option value="middle" <?= $curValign === 'middle' ? 'selected' : '' ?>>↕ Tengah</option>
            <option value="bottom" <?= $curValign === 'bottom' ? 'selected' : '' ?>>⤓ Bawah</option>
          </select>
        </div>
      </div>
      <div class="fba-hint" style="margin:-.5rem 0 .8rem">Vertikal berlaku saat kolom lebih tinggi dari isinya (misal kolom sebelah lebih panjang).</div>
      <div style="display:flex;gap:.5rem">
        <button class="fba-btn primary" type="submit">Save field</button>
        <button class="fba-btn danger" type="button" id="fbcFieldDelete" data-del="<?= $fid ?>" title="Pindahkan ke Bin">🗑 Bin</button>
      </div>
    </form>
    <?php
    return (string)ob_get_clean();
}
