<?php
// /plugins/form-builder/admin/builder.php  ($form, $pdo, $uid, $role, $csrf in scope)
declare(strict_types=1);

require_once __DIR__ . '/_canvas.php';

$formId = (int)$form['id'];
$types = fb_field_types();
$tree = fb_get_tree($pdo, $formId);
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
    <span class="fba-hint">&mdash; susun <strong>Row &rarr; Column &rarr; Field</strong>: klik <strong>+ Add Row</strong>, atur jumlah kolom, lalu <strong>drag</strong> field dari palette ke kolom (klik field untuk edit). Semua perubahan tersimpan otomatis.</span>
  </div>

  <div class="fbb3" id="fbb3">
    <div class="fbc-palette">
      <h3>Field Palette</h3>
      <?php foreach ($types as $t => $meta):
        if (!empty($meta['container'])) continue; ?>
      <button type="button" class="fbc-p-chip" draggable="true" data-type="<?= $t ?>">+ <?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></button>
      <?php endforeach; ?>
    </div>

    <div id="fbcCanvasWrap"><?= fb_render_canvas($form, $tree) ?></div>

    <div class="fbc-panel" id="fbcPanel" style="display:none">
      <div class="fbc-panel-head"><h3>Edit Field</h3><button type="button" id="fbcPanelClose">&times;</button></div>
      <div class="fbc-panel-body" id="fbcPanelBody"></div>
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
  var wrap = document.getElementById('fbcCanvasWrap');
  var panel = document.getElementById('fbcPanel');
  var panelBody = document.getElementById('fbcPanelBody');
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

    // Delete (chip or row)
    var del = e.target.closest('[data-del]');
    if (del) {
      var id = del.getAttribute('data-del');
      var isRow = del.classList.contains('fbc-row-del');
      if (del.id === 'fbcFieldDelete') { closePanel(); }
      if (confirm(isRow ? 'Delete this row and all its fields?' : 'Delete this field?')) {
        run('delete', { id: id }, isRow ? 'Row deleted' : 'Field deleted');
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
        layout.classList.add('has-panel');
      });
    }
  });

  // Column count change
  document.addEventListener('change', function (e) {
    var sel = e.target.closest('.fbc-cols-sel');
    if (sel) run('set_cols', { row_id: sel.getAttribute('data-row'), cols: sel.value }, 'Columns updated');
  });

  // Panel
  function closePanel() { panel.style.display = 'none'; layout.classList.remove('has-panel'); panelBody.innerHTML = ''; }
  document.getElementById('fbcPanelClose').addEventListener('click', closePanel);
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
