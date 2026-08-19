<?php
// /plugins/form-builder/admin/builder.php  ($form, $pdo, $uid, $csrf in scope)
declare(strict_types=1);

require_once __DIR__ . '/_canvas.php';

$formId = (int)$form['id'];
$types = fb_field_types();
$tree = fb_get_tree($pdo, $formId);
$trashedCount = (int)$pdo->query("SELECT COUNT(*) FROM `fb_fields` WHERE form_id = {$formId} AND deleted_at IS NOT NULL AND type NOT IN ('row','col')")->fetchColumn();
$canManageBin = user_can($pdo, $uid, 'plugin.form-builder.bin.manage')
    && user_can($pdo, $uid, 'plugin.form-builder.forms.manage-any');
?>
<div class="fba">
  <div class="fba-head">
    <h1>Builder: <?= htmlspecialchars($form['title'], ENT_QUOTES) ?> <span class="fba-badge <?= ['active'=>'active','draft'=>'draft','archived'=>'arch'][$form['status']] ?? 'draft' ?>"><?= htmlspecialchars($form['status'], ENT_QUOTES) ?></span></h1>
    <div class="fba-actions">
      <a class="fba-btn" href="<?= fb_url(['view' => 'forms', 'id' => null]) ?>">&larr; Forms</a>
      <a class="fba-btn" href="<?= fb_url(['view' => 'submissions', 'id' => $formId]) ?>">Submissions</a>
      <a class="fba-btn" href="<?= fb_url(['view' => 'settings', 'id' => $formId]) ?>">Settings</a>
      <?php if ($canManageBin): ?>
      <a class="fba-btn" href="?page=admin/bin/form-builder/index">🗑 Bin<?= $trashedCount > 0 ? ' (' . $trashedCount . ')' : '' ?></a>
      <?php endif; ?>
    </div>
  </div>

  <div class="fba-card">
    <span class="fba-hint">Shortcode:</span> <span class="fba-code">[form slug=&quot;<?= htmlspecialchars($form['slug'], ENT_QUOTES) ?>&quot;]</span>
    <span class="fba-hint">&mdash; susun <strong>Row &rarr; Column &rarr; Field</strong>: klik <strong>+ Add Row</strong>, atur jumlah kolom, lalu <strong>drag</strong> field dari palette ke kolom (klik field untuk edit). Semua perubahan tersimpan otomatis. Field yang dihapus masuk ke <strong>Bin</strong> dan bisa di-restore.</span>
  </div>

  <div class="fbb3" id="fbb3">
    <div class="fbc-palette">
      <h3>Inputs</h3>
      <p class="fba-hint" style="margin:-.3rem 0 .6rem">Field yang bisa diisi pengunjung.</p>
      <?php foreach ($types as $t => $meta):
        if (!empty($meta['container']) || ($meta['group'] ?? '') !== 'input') continue; ?>
      <button type="button" class="fbc-p-chip" draggable="true" data-type="<?= $t ?>">+ <?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></button>
      <?php endforeach; ?>
      <h3 style="margin-top:1.1rem">Elements</h3>
      <p class="fba-hint" style="margin:-.3rem 0 .6rem">Konten tampilan (bukan isian).</p>
      <?php foreach ($types as $t => $meta):
        if (!empty($meta['container']) || ($meta['group'] ?? '') !== 'element') continue; ?>
      <button type="button" class="fbc-p-chip el" draggable="true" data-type="<?= $t ?>">+ <?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></button>
      <?php endforeach; ?>
    </div>

    <div id="fbcCanvasWrap"><?= fb_render_canvas($form, $tree) ?></div>

    <div class="fbc-panel" id="fbcPanel" style="display:none">
      <div class="fbc-panel-head"><h3>Edit Field</h3><button type="button" id="fbcPanelClose">&times;</button></div>
      <div class="fbc-panel-body" id="fbcPanelBody"></div>
    </div>
    <div class="fbc-panel-backdrop" id="fbcPanelBackdrop" style="display:none"></div>
  </div>
</div>

<div class="fbc-editor-overlay" id="fbcEditorOverlay" style="display:none">
  <div class="fbc-editor-modal">
    <div class="fbc-editor-head"><h3 id="fbcEditorTitle">Edit Content</h3><button type="button" id="fbcEditorClose">&times;</button></div>
    <div class="fbc-editor-body" id="fbcEditorBody"></div>
    <div class="fbc-editor-foot">
      <button type="button" class="fba-btn" id="fbcEditorCancel">Batal</button>
      <button type="button" class="fba-btn primary" id="fbcEditorApply">Apply</button>
    </div>
  </div>
</div>

<div class="fbc-toast" id="fbcToast"></div>

<script>
(function () {
  'use strict';
  var FORM_ID = <?= $formId ?>;
  var CSRF = <?= json_encode($csrf) ?>;
  var ENDPOINT = '/fb-builder/';
  var ADMIN_BASE = <?= json_encode(rtrim((string)(defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : ''), '/')) ?>;
  var wrap = document.getElementById('fbcCanvasWrap');
  var panel = document.getElementById('fbcPanel');
  var panelBody = document.getElementById('fbcPanelBody');
  var panelBackdrop = document.getElementById('fbcPanelBackdrop');
  // Portal to <body>: the admin theme's .adam-app (overflow:hidden) and
  // .adam-main (transform) break both sticky and fixed positioning for
  // in-flow descendants.
  panel.classList.add('fbc-portal');
  panelBackdrop.classList.add('fbc-portal');
  document.body.appendChild(panelBackdrop);
  document.body.appendChild(panel);
  var layout = document.getElementById('fbb3');
  var toastEl = document.getElementById('fbcToast');
  var toastTimer = null;

  function toast(msg, isErr) {
    toastEl.textContent = msg;
    toastEl.className = 'fbc-toast show' + (isErr ? ' err' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.className = 'fbc-toast'; }, 2200);
  }

  function ajax(action, data) {
    var body = new URLSearchParams();
    body.set('fb_action', action);
    body.set('form_id', FORM_ID);
    body.set('csrf_token', CSRF);
    Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
    return fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function applyCanvas(html) {
    var old = document.getElementById('fbcCanvas');
    if (old && html) {
      var tmp = document.createElement('div');
      tmp.innerHTML = html;
      var fresh = tmp.querySelector('#fbcCanvas');
      if (fresh) old.replaceWith(fresh);
    }
  }

  function run(action, data, okMsg) {
    return ajax(action, data).then(function (res) {
      if (!res.ok) { toast(res.error || 'Error', true); return res; }
      if (res.html) applyCanvas(res.html);
      if (okMsg) toast(okMsg);
      return res;
    }).catch(function () { toast('Network error', true); });
  }

  // ---------------- Content editor overlay (Quill / CodeMirror) ----------------
  var overlay = document.getElementById('fbcEditorOverlay');
  var editorBody = document.getElementById('fbcEditorBody');
  var editorTitle = document.getElementById('fbcEditorTitle');
  var activeEditor = null;   // {kind:'quill'|'code', get:fn, destroy:fn}
  var editorApplyCb = null;

  function closeEditor() {
    if (activeEditor) { try { activeEditor.destroy(); } catch (e) {} activeEditor = null; }
    editorApplyCb = null;
    editorBody.innerHTML = '';
    overlay.style.display = 'none';
  }

  function openEditor(kind, initialHtml, onApply) {
    editorBody.innerHTML = '';
    editorApplyCb = onApply;
    if (kind === 'code') {
      editorTitle.textContent = 'Edit Raw HTML';
      var ta = document.createElement('textarea');
      ta.id = 'fbcCodeArea';
      ta.value = initialHtml || '';
      editorBody.appendChild(ta);
      overlay.style.display = 'flex';
      if (typeof CodeMirror !== 'undefined') {
        var cm = CodeMirror.fromTextArea(ta, {
          mode: 'htmlmixed', theme: 'dracula', lineNumbers: true,
          autoCloseTags: true, autoCloseBrackets: true, lineWrapping: true
        });
        cm.setSize('100%', '52vh');
        setTimeout(function () { cm.refresh(); }, 60);
        activeEditor = { kind: 'code', get: function () { return cm.getValue(); }, destroy: function () { cm.toTextArea(); } };
      } else {
        ta.style.cssText = 'width:100%;height:52vh;font-family:monospace';
        activeEditor = { kind: 'code', get: function () { return ta.value; }, destroy: function () {} };
      }
    } else {
      editorTitle.textContent = 'Edit Rich Text';
      var box = document.createElement('div');
      box.id = 'fbcQuillBox';
      box.style.height = '48vh';
      editorBody.appendChild(box);
      overlay.style.display = 'flex';
      if (typeof Quill === 'undefined') { toast('Quill tidak tersedia', true); closeEditor(); return; }
      var quill = new Quill(box, {
        modules: { toolbar: [
          [{ header: [1, 2, 3, 4, 5, 6, false] }],
          ['bold', 'italic', 'underline', 'strike'],
          [{ color: [] }, { background: [] }],
          [{ list: 'ordered' }, { list: 'bullet' }],
          [{ indent: '-1' }, { indent: '+1' }],
          [{ align: [] }],
          ['blockquote', 'code-block'],
          ['link', 'image', 'video'],
          ['clean']
        ] },
        theme: 'snow',
        placeholder: 'Tulis konten...'
      });
      if (initialHtml) quill.root.innerHTML = initialHtml;
      var lastRange = null;
      quill.on('selection-change', function (r) { if (r) lastRange = r; });
      // Gallery integration (CMS modal_img)
      try {
        quill.getModule('toolbar').addHandler('image', function () {
          if (typeof openMediaSelector !== 'function') { toast('Gallery modal tidak tersedia', true); return; }
          var saved = quill.getSelection(); if (saved) lastRange = saved;
          if (quill.root) quill.root.blur();
          openMediaSelector({ url: ADMIN_BASE + '/admin/modal_img/index.php?embedded=1' }).then(function (detail) {
            var m = (typeof normalizeMedia === 'function') ? normalizeMedia(detail) : detail;
            if (!m || !m.url) return;
            var at = (lastRange && typeof lastRange.index === 'number') ? lastRange.index : quill.getLength() - 1;
            var alt = m.alt || m.title || '';
            var html = '<img src="' + String(m.url).replace(/"/g, '&quot;') + '"' + (alt ? ' alt="' + String(alt).replace(/"/g, '&quot;') + '"' : '') + '>';
            if (m.caption) html = '<figure>' + html + '<figcaption>' + String(m.caption).replace(/</g, '&lt;') + '</figcaption></figure>';
            quill.clipboard.dangerouslyPasteHTML(at, html);
          }).catch(function () {});
        });
      } catch (e) {}
      // File integration (CMS modal_file) on video button
      try {
        quill.getModule('toolbar').addHandler('video', function () {
          if (typeof openFileSelector !== 'function') { toast('File modal tidak tersedia', true); return; }
          var saved = quill.getSelection(); if (saved) lastRange = saved;
          if (quill.root) quill.root.blur();
          openFileSelector({ url: ADMIN_BASE + '/admin/modal_file/index.php?embedded=1' }).then(function (picked) {
            var f = (typeof normalizeFile === 'function') ? normalizeFile(picked) : picked;
            if (!f) return;
            var html = (typeof generateFileShortcode === 'function') ? generateFileShortcode(f) : '';
            if (!html) return;
            var at = (lastRange && typeof lastRange.index === 'number') ? lastRange.index : quill.getLength() - 1;
            quill.clipboard.dangerouslyPasteHTML(at, html);
          }).catch(function () {});
        });
      } catch (e) {}
      activeEditor = { kind: 'quill', get: function () { return quill.root.innerHTML; }, destroy: function () {} };
    }
  }

  document.getElementById('fbcEditorClose').addEventListener('click', closeEditor);
  document.getElementById('fbcEditorCancel').addEventListener('click', closeEditor);
  document.getElementById('fbcEditorApply').addEventListener('click', function () {
    if (!activeEditor) { closeEditor(); return; }
    var html = activeEditor.get();
    var cb = editorApplyCb;
    closeEditor();
    if (cb) cb(html);
  });

  // ---------------- Drag & Drop ----------------
  var drag = null;       // {kind:'type'|'node'|'row', type|id}
  var dragJustEnded = 0; // suppress click right after a drag

  document.addEventListener('dragstart', function (e) {
    var p = e.target.closest('.fbc-p-chip');
    if (p) { drag = { kind: 'type', type: p.getAttribute('data-type') }; e.dataTransfer.effectAllowed = 'copy'; return; }
    var grip = e.target.closest('.fbc-grip');
    if (grip) { drag = { kind: 'row', id: grip.getAttribute('data-grip') }; e.dataTransfer.effectAllowed = 'move'; return; }
    var chip = e.target.closest('.fbc-chip');
    if (chip) { drag = { kind: 'node', id: chip.getAttribute('data-node') }; chip.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; }
  });
  document.addEventListener('dragend', function () {
    document.querySelectorAll('.fbc-chip.dragging').forEach(function (c) { c.classList.remove('dragging'); });
    if (drag) dragJustEnded = Date.now();
    drag = null;
    document.querySelectorAll('.col-over,.row-over').forEach(function (c) { c.classList.remove('col-over', 'row-over'); });
  });

  function colIndexAt(col, y) {
    var chips = col.querySelectorAll('.fbc-chip');
    for (var i = 0; i < chips.length; i++) {
      var r = chips[i].getBoundingClientRect();
      if (y < r.top + r.height / 2) return i;
    }
    return chips.length;
  }

  document.addEventListener('dragover', function (e) {
    if (!drag) return;
    var col = e.target.closest('.fbc-col');
    if (col && drag.kind !== 'row') { e.preventDefault(); col.classList.add('col-over'); return; }
    var row = e.target.closest('.fbc-row');
    if (row && drag.kind === 'row' && !e.target.closest('.fbc-cols')) { e.preventDefault(); row.classList.add('row-over'); }
  });
  document.addEventListener('dragleave', function (e) {
    var col = e.target.closest('.fbc-col');
    if (col && !col.contains(e.relatedTarget)) col.classList.remove('col-over');
    var row = e.target.closest('.fbc-row');
    if (row && !row.contains(e.relatedTarget)) row.classList.remove('row-over');
  });
  document.addEventListener('drop', function (e) {
    if (!drag) return;
    var col = e.target.closest('.fbc-col');
    if (col && drag.kind !== 'row') {
      e.preventDefault();
      col.classList.remove('col-over');
      var colId = col.getAttribute('data-node');
      var idx = colIndexAt(col, e.clientY);
      if (drag.kind === 'type') run('add_field', { type: drag.type, col_id: colId, index: idx }, 'Field added');
      else if (drag.kind === 'node') run('move', { id: drag.id, parent: colId, index: idx }, 'Moved');
      return;
    }
    var row = e.target.closest('.fbc-row');
    if (row && drag.kind === 'row' && !e.target.closest('.fbc-cols')) {
      e.preventDefault();
      row.classList.remove('row-over');
      var rows = Array.prototype.slice.call(document.querySelectorAll('.fbc-row'));
      var rIdx = rows.indexOf(row);
      var r = row.getBoundingClientRect();
      if (e.clientY > r.top + r.height / 2) rIdx += 1;
      run('move', { id: drag.id, parent: 0, index: rIdx }, 'Row moved');
    }
  });

  // ---------------- Click actions (delegated) ----------------
  document.addEventListener('click', function (e) {
    // Add Row
    if (e.target.closest('#fbcAddRow')) { run('add_row', { cols: 2 }, 'Row added'); return; }

    // Palette click = add into first column of last row (create row if empty)
    var p = e.target.closest('.fbc-p-chip');
    if (p) {
      var cols = document.querySelectorAll('.fbc-col');
      if (!cols.length) {
        run('add_row', { cols: 2 }).then(function () {
          var c2 = document.querySelectorAll('.fbc-col');
          if (c2.length) run('add_field', { type: p.getAttribute('data-type'), col_id: c2[0].getAttribute('data-node'), index: 0 }, 'Field added');
        });
      } else {
        var rows = document.querySelectorAll('.fbc-row');
        var lastRow = rows[rows.length - 1];
        var firstCol = lastRow.querySelector('.fbc-col');
        run('add_field', { type: p.getAttribute('data-type'), col_id: firstCol.getAttribute('data-node'), index: 999 }, 'Field added');
      }
      return;
    }

    // Rich content editor open
    var edBtn = e.target.closest('#fbcOpenEditor');
    if (edBtn) {
      var kind = edBtn.getAttribute('data-editor') === 'code' ? 'code' : 'quill';
      var input = document.getElementById('fbcContentInput');
      openEditor(kind, input ? input.value : '', function (html) {
        var inp = document.getElementById('fbcContentInput');
        if (inp) inp.value = html;
        var prev = document.getElementById('fbcContentPrev');
        if (prev) prev.innerHTML = html !== '' ? html : '<span class="fba-hint">(belum ada konten)</span>';
      });
      return;
    }

    // Gallery image picker (image_block element)
    var pickBtn = e.target.closest('#fbcPickImage');
    if (pickBtn) {
      if (typeof openMediaSelector !== 'function') { toast('Gallery modal tidak tersedia', true); return; }
      openMediaSelector({ url: ADMIN_BASE + '/admin/modal_img/index.php?embedded=1' }).then(function (detail) {
        var m = (typeof normalizeMedia === 'function') ? normalizeMedia(detail) : detail;
        if (!m || !m.url) return;
        var urlInp = document.getElementById('fbcImgUrl');
        if (urlInp) urlInp.value = m.url;
        var prev = document.getElementById('fbcImgPrev');
        if (prev) prev.innerHTML = '<img src="' + String(m.url).replace(/"/g, '&quot;') + '" alt="">';
        var altInp = document.querySelector('#fbcFieldForm input[name="s_alt"]');
        if (altInp && !altInp.value && (m.alt || m.title)) altInp.value = m.alt || m.title;
        var capInp = document.querySelector('#fbcFieldForm input[name="s_caption"]');
        if (capInp && !capInp.value && m.caption) capInp.value = m.caption;
      }).catch(function () {});
      return;
    }

    // Delete → Bin (chip or row)
    var del = e.target.closest('[data-del]');
    if (del) {
      var id = del.getAttribute('data-del');
      var isRow = del.classList.contains('fbc-row-del');
      if (del.id === 'fbcFieldDelete') { closePanel(); }
      if (confirm(isRow ? 'Pindahkan row ini (beserta semua field di dalamnya) ke Bin?' : 'Pindahkan field ini ke Bin?')) {
        run('delete', { id: id }, 'Dipindahkan ke Bin');
      }
      return;
    }

    // Edit field
    var edit = e.target.closest('[data-edit]');
    var chip = e.target.closest('.fbc-chip');
    var fieldId = edit ? edit.getAttribute('data-edit') : (chip ? chip.getAttribute('data-node') : null);
    if (fieldId && (edit || chip)) {
      if (Date.now() - dragJustEnded < 300) return; // was a drag, not a click
      ajax('field_form', { id: fieldId }).then(function (res) {
        if (!res.ok) { toast(res.error || 'Error', true); return; }
        panelBody.innerHTML = res.html;
        panel.style.display = '';
        panelBackdrop.style.display = window.matchMedia('(max-width: 1100px)').matches ? '' : 'none';
        layout.classList.add('has-panel');
        pinPanel();
      });
    }
  });

  // Column count change
  document.addEventListener('change', function (e) {
    var sel = e.target.closest('.fbc-cols-sel');
    if (sel) run('set_cols', { row_id: sel.getAttribute('data-row'), cols: sel.value }, 'Columns updated');
  });

  // Panel
  var panelPinned = false;
  function pinPanel() {
    if (!window.matchMedia('(min-width: 1101px)').matches) { unpinPanel(); return; }
    var gridRect = layout.getBoundingClientRect();
    var w = 320;
    var top = Math.max(72, gridRect.top);
    panel.style.position = 'fixed';
    panel.style.width = w + 'px';
    panel.style.left = (gridRect.right - w) + 'px';
    panel.style.top = top + 'px';
    panel.style.maxHeight = 'calc(100vh - ' + (top + 16) + 'px)';
    panelPinned = true;
  }
  function unpinPanel() {
    if (!panelPinned) return;
    panel.style.position = '';
    panel.style.width = '';
    panel.style.left = '';
    panel.style.top = '';
    panel.style.maxHeight = '';
    panelPinned = false;
  }
  window.addEventListener('scroll', function () { if (panelPinned) pinPanel(); }, { passive: true });
  window.addEventListener('resize', function () { if (panel.style.display !== 'none') pinPanel(); });

  function closePanel() { unpinPanel(); panel.style.display = 'none'; panelBackdrop.style.display = 'none'; layout.classList.remove('has-panel'); panelBody.innerHTML = ''; }
  document.getElementById('fbcPanelClose').addEventListener('click', closePanel);
  panelBackdrop.addEventListener('click', closePanel);
  panelBody.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(e.target);
    var data = {};
    fd.forEach(function (v, k) { data[k] = v; });
    if (!data.required) data.required = '';
    if (!data.is_hidden) data.is_hidden = '';
    run('save_field', data, 'Field saved').then(function (res) { if (res && res.ok) closePanel(); });
  });
})();
</script>
