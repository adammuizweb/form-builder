<?php
// /plugins/form-builder/admin/visual-builder.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

require_once __DIR__ . '/_ui.php';
require_once dirname(__DIR__) . '/public/render.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) { echo '<p>Database not available.</p>'; return; }
[$uid] = adiwira_require_permission($pdo, 'plugin.form-builder.workspace.access', false);

fb_assert_schema($pdo);
$formId = is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0;
$form = fb_get_form($pdo, $formId);
if ($form === null || ($form['deleted_at'] ?? null) !== null || !fb_can_access_form($pdo, $form, $uid)) {
    fb_admin_css();
    echo '<div class="fba"><div class="fba-empty">Form not found or access denied. <a href="?page=admin/tools/form-builder">Back to forms</a></div></div>';
    return;
}

$types = fb_field_types();
$canUnsafeCode = user_can($pdo, $uid, 'plugin.form-builder.unsafe-code.manage');
$tree = fb_get_tree($pdo, $formId);
$fieldCount = 0;
foreach ($tree as $row) foreach ($row['cols'] as $column) $fieldCount += count($column['fields']);
$statusClass = ['active' => 'active', 'draft' => 'draft', 'archived' => 'arch'][$form['status']] ?? 'draft';
$classicUrl = fb_url(['view' => 'builder', 'id' => $formId]);
$settingsUrl = fb_url(['view' => 'settings', 'id' => $formId]);
$formsUrl = fb_url(['view' => 'forms', 'id' => null]);
$csrf = function_exists('csrf_token') ? csrf_token() : '';
$previewForm = $form;
$previewSettings = fb_form_settings($previewForm);
$previewSettings['unsafe_code_enabled'] = false;
$previewForm['settings_json'] = fb_json_encode($previewSettings);
$previewForm['css'] = null;
$previewForm['js'] = null;

fb_admin_css();
?>
<style>
.fbv { --fbv-ink: #14261b; --fbv-muted: #68786e; --fbv-line: #dce5de; --fbv-paper: #f7faf7; color: var(--adam-text); }
.fbv-topbar { display: flex; align-items: center; gap: .8rem; min-height: 58px; margin: -1rem -1rem 0; padding: .65rem 1rem; position: sticky; top: 0; z-index: 30; border-bottom: 1px solid var(--adam-border); background: color-mix(in srgb, var(--adam-card) 94%, transparent); backdrop-filter: blur(12px); }
.fbv-back { display: inline-grid; place-items: center; width: 36px; height: 36px; border: 1px solid var(--adam-border); border-radius: 10px; color: var(--adam-text); text-decoration: none; }
.fbv-title { min-width: 0; flex: 1; }
.fbv-title strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fbv-title span { color: var(--adam-muted); font-size: .73rem; }
.fbv-status { display: inline-flex; align-items: center; gap: .4rem; color: var(--adam-muted); font-size: .76rem; }
.fbv-status::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #2b7a4a; box-shadow: 0 0 0 4px rgba(43 122 74 / .12); }
.fbv-status[data-state="loading"]::before, .fbv-status[data-state="saving"]::before { background: #ca8a04; box-shadow: 0 0 0 4px rgba(202 138 4 / .14); }
.fbv-status[data-state="error"]::before, .fbv-status[data-state="conflict"]::before { background: #dc2626; box-shadow: 0 0 0 4px rgba(220 38 38 / .12); }
.fbv-workspace { display: grid; grid-template-columns: 224px minmax(360px, 1fr) 288px; min-height: calc(100vh - 150px); margin: 0 -1rem -1rem; background: var(--adam-bg); }
.fbv-sidebar { padding: 1rem; background: var(--adam-card); }
.fbv-sidebar.left { border-right: 1px solid var(--adam-border); }
.fbv-sidebar.right { border-left: 1px solid var(--adam-border); }
.fbv-sidebar h2 { margin: 0 0 .25rem; font-size: .92rem; }
.fbv-sidebar > p { margin: 0 0 1rem; color: var(--adam-muted); font-size: .75rem; line-height: 1.45; }
.fbv-library-title { margin: 1rem 0 .5rem; color: var(--adam-muted); font-size: .66rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
.fbv-library { display: grid; gap: .4rem; }
.fbv-library button { display: flex; align-items: center; gap: .55rem; width: 100%; padding: .55rem .65rem; border: 1px solid var(--adam-border); border-radius: 10px; background: var(--adam-bg); color: var(--adam-text); font: inherit; font-size: .78rem; font-weight: 600; text-align: left; cursor: pointer; }
.fbv-library button:hover:not(:disabled) { border-color: var(--adam-accent); background: color-mix(in srgb, var(--adam-accent) 6%, var(--adam-card)); }
.fbv-library button:disabled { cursor: not-allowed; opacity: .45; }
.fbv-library button::before { content: '+'; display: grid; place-items: center; width: 21px; height: 21px; border-radius: 7px; background: color-mix(in srgb, var(--adam-accent) 12%, transparent); color: var(--adam-accent); }
.fbv-stage { min-width: 0; padding: 1rem clamp(1rem, 3vw, 2.5rem) 3rem; overflow: auto; }
.fbv-notice { display: flex; gap: .65rem; align-items: flex-start; max-width: 860px; margin: 0 auto 1rem; padding: .7rem .85rem; border: 1px solid color-mix(in srgb, var(--adam-accent) 28%, var(--adam-border)); border-radius: 12px; background: color-mix(in srgb, var(--adam-accent) 6%, var(--adam-card)); font-size: .78rem; line-height: 1.5; }
.fbv-canvas-tools { display: flex; align-items: center; justify-content: space-between; gap: .7rem; max-width: 860px; margin: 0 auto .65rem; }
.fbv-devices { display: inline-flex; gap: .2rem; padding: .2rem; border: 1px solid var(--adam-border); border-radius: 10px; background: var(--adam-card); }
.fbv-device { border: 0; border-radius: 7px; padding: .35rem .6rem; background: transparent; color: var(--adam-muted); font: inherit; font-size: .73rem; cursor: pointer; }
.fbv-device.is-active { background: var(--adam-accent); color: #fff; }
.fbv-count { color: var(--adam-muted); font-size: .73rem; }
.fbv-canvas-shell { width: 100%; max-width: 860px; min-height: 500px; margin: 0 auto; padding: clamp(1rem, 3vw, 2.2rem); border: 1px solid var(--adam-border); border-radius: 18px; background: #eef3ef; box-shadow: 0 22px 60px rgba(17 40 25 / .11); transition: max-width .25s ease; }
.fbv-canvas-shell[data-device="mobile"] { max-width: 430px; }
.fbv-preview { position: relative; }
.fbv-preview .fb-wrap { margin: 0; box-shadow: 0 10px 35px rgba(26 55 37 / .09); }
.fbv-preview button, .fbv-preview input, .fbv-preview textarea, .fbv-preview select { pointer-events: none; }
.fbv-preview .fb-field { position: relative; border-radius: 10px; cursor: pointer; outline: 2px solid transparent; outline-offset: 6px; transition: outline-color .15s, background .15s; }
.fbv-preview .fb-field:hover { outline-color: color-mix(in srgb, var(--adam-accent) 36%, transparent); }
.fbv-preview .fb-field.is-selected { outline-color: var(--adam-accent); background: color-mix(in srgb, var(--adam-accent) 5%, transparent); }
.fbv-inspector-empty { padding: 1.1rem; border: 1px dashed var(--adam-border); border-radius: 12px; background: var(--adam-bg); color: var(--adam-muted); font-size: .78rem; line-height: 1.55; }
.fbv-inspector-type { display: inline-flex; margin-bottom: .8rem; padding: .2rem .45rem; border-radius: 999px; background: color-mix(in srgb, var(--adam-accent) 10%, transparent); color: var(--adam-accent); font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
.fbv-inspector .fba-field { margin-bottom: .7rem; }
.fbv-field-picker { width: 100%; margin-bottom: .85rem; }
.fbv-inspector textarea { resize: vertical; }
.fbv-inspector-actions { display: flex; justify-content: space-between; gap: .5rem; margin-top: 1rem; padding-top: .8rem; border-top: 1px solid var(--adam-border); }
.fbv-inspector-key { font-family: ui-monospace, monospace; font-size: .72rem; }
.fbv-mode-card { margin-top: 1rem; padding: .85rem; border: 1px solid var(--adam-border); border-radius: 12px; }
.fbv-mode-card strong { display: block; margin-bottom: .25rem; font-size: .78rem; }
.fbv-mode-card span { display: block; margin-bottom: .65rem; color: var(--adam-muted); font-size: .72rem; line-height: 1.45; }
@media (max-width: 1100px) {
  .fbv-workspace { grid-template-columns: 190px minmax(320px, 1fr); }
  .fbv-sidebar.right { grid-column: 1 / -1; border-left: 0; border-top: 1px solid var(--adam-border); }
}
@media (max-width: 760px) {
  .fbv-topbar { flex-wrap: wrap; }
  .fbv-status { display: none; }
  .fbv-workspace { display: block; }
  .fbv-sidebar.left { border: 0; border-bottom: 1px solid var(--adam-border); }
  .fbv-library { display: flex; overflow-x: auto; padding-bottom: .25rem; }
  .fbv-library button { min-width: 135px; }
  .fbv-library-title { display: none; }
  .fbv-stage { padding: 1rem .75rem 2rem; }
}
</style>

<div class="fbv">
  <header class="fbv-topbar">
    <a class="fbv-back" href="<?= htmlspecialchars($formsUrl, ENT_QUOTES) ?>" aria-label="Back to forms">&larr;</a>
    <div class="fbv-title">
      <strong><?= htmlspecialchars((string)$form['title'], ENT_QUOTES) ?></strong>
      <span><?= htmlspecialchars((string)$form['slug'], ENT_QUOTES) ?> &middot; Visual Builder</span>
    </div>
    <span class="fba-badge <?= $statusClass ?>"><?= htmlspecialchars((string)$form['status'], ENT_QUOTES) ?></span>
    <div class="fbv-status" id="fbvDraftStatus" data-state="loading" role="status">Loading draft...</div>
    <a class="fba-btn" href="<?= htmlspecialchars($settingsUrl, ENT_QUOTES) ?>">Settings</a>
    <a class="fba-btn" href="<?= htmlspecialchars($classicUrl, ENT_QUOTES) ?>">Classic</a>
    <button class="fba-btn primary" type="button" disabled title="Draft publishing arrives with the next foundation slice">Publish</button>
  </header>

  <div class="fbv-workspace">
    <aside class="fbv-sidebar left" aria-label="Question library">
      <h2>Add a question</h2>
      <p>Add a field to the final column, then select it on the canvas to edit its draft properties.</p>
      <?php foreach (['input' => 'Questions', 'element' => 'Content'] as $group => $label): ?>
        <div class="fbv-library-title"><?= $label ?></div>
        <div class="fbv-library">
          <?php foreach ($types as $type => $meta):
            if (!empty($meta['container']) || ($meta['group'] ?? '') !== $group) continue; ?>
            <?php $unsafeType = in_array($type, ['richtext', 'raw_html'], true); ?>
            <button type="button" disabled data-fbv-type="<?= htmlspecialchars((string)$type, ENT_QUOTES) ?>" data-unsafe="<?= $unsafeType ? '1' : '0' ?>"<?= $unsafeType && !$canUnsafeCode ? ' title="Unsafe-code permission required"' : '' ?>><?= htmlspecialchars((string)$meta['label'], ENT_QUOTES) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </aside>

    <main class="fbv-stage">
      <div class="fbv-notice" role="status">
        <strong>Draft workspace.</strong>
        <span>Select a field to edit its draft. Changes autosave separately and do not affect the published form until publishing is enabled.</span>
      </div>
      <div class="fbv-canvas-tools">
        <div class="fbv-devices" aria-label="Preview width">
          <button class="fbv-device is-active" type="button" data-device="desktop" aria-pressed="true">Desktop</button>
          <button class="fbv-device" type="button" data-device="mobile" aria-pressed="false">Mobile</button>
        </div>
        <span class="fbv-count" id="fbvFieldCount"><?= $fieldCount ?> field<?= $fieldCount === 1 ? '' : 's' ?></span>
      </div>
      <div class="fbv-canvas-shell" id="fbvCanvas" data-device="desktop">
        <div class="fbv-preview" inert id="fbvPreview" aria-label="Form preview">
          <?= fb_render_form($pdo, $previewForm) ?>
        </div>
      </div>
    </main>

    <aside class="fbv-sidebar right" aria-label="Question properties">
      <h2>Question properties</h2>
      <p>Select a question on the canvas to edit its label, help text, options, required state, and visibility.</p>
      <select class="fbv-field-picker" id="fbvFieldPicker" aria-label="Select a field" disabled><option value="">Select a field</option></select>
      <div class="fbv-inspector" id="fbvInspector"><div class="fbv-inspector-empty">Select a field on the canvas, or add one from the library.</div></div>
      <div class="fbv-mode-card">
        <strong>Need advanced layout?</strong>
        <span>Rows, columns, custom HTML, and current production controls remain available.</span>
        <a class="fba-btn sm" href="<?= htmlspecialchars($classicUrl, ENT_QUOTES) ?>">Open Classic Builder</a>
      </div>
    </aside>
  </div>
</div>

<script>
(() => {
  const ENDPOINT = '/fb-visual-builder/';
  const FORM_ID = <?= $formId ?>;
  const CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const CAN_UNSAFE = <?= $canUnsafeCode ? 'true' : 'false' ?>;
  const TYPES = <?= json_encode($types, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const canvas = document.getElementById('fbvCanvas');
  const preview = document.getElementById('fbvPreview');
  const inspector = document.getElementById('fbvInspector');
  const fieldPicker = document.getElementById('fbvFieldPicker');
  const fieldCount = document.getElementById('fbvFieldCount');
  const status = document.getElementById('fbvDraftStatus');
  const devices = document.querySelectorAll('.fbv-device');
  const libraryButtons = document.querySelectorAll('[data-fbv-type]');
  let draft = null;
  let workingDefinition = null;
  let selectedKey = null;
  let saveTimer = 0;
  let saveInFlight = false;
  let pendingDefinition = null;
  let hasUnsavedChanges = false;

  const setStatus = (message, state = 'ready') => {
    status.textContent = message;
    status.dataset.state = state;
  };
  const request = async (action, values = {}) => {
    const body = new URLSearchParams({ fb_action: action, form_id: String(FORM_ID), csrf_token: CSRF, ...values });
    const response = await fetch(ENDPOINT, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
    const payload = await response.json().catch(() => ({ ok: false, error: 'Invalid server response' }));
    if (!response.ok || !payload.ok) {
      const error = new Error(payload.error || 'Draft request failed');
      error.conflict = response.status === 409 || payload.conflict === true;
      throw error;
    }
    return payload.draft;
  };
  const showDraftState = () => {
    if (draft.published_changed) setStatus('Draft ready - Classic changed since draft start', 'conflict');
    else if (draft.unsafe_content_protected) setStatus(`Draft ready - revision ${draft.revision} - protected code preserved`);
    else setStatus(`Draft ready - revision ${draft.revision}`);
  };
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]);
  const currentFields = () => workingDefinition?.form?.fields || [];
  const currentField = () => currentFields().find((field) => field.key === selectedKey) || null;
  const updateFieldCount = () => {
    const count = currentFields().filter((field) => !['row', 'col'].includes(field.type)).length;
    fieldCount.textContent = `${count} field${count === 1 ? '' : 's'}`;
  };
  const markSelection = () => {
    preview.querySelectorAll('.fb-field').forEach((element) => element.classList.toggle('is-selected', element.dataset.key === selectedKey));
  };
  const renderFieldPicker = () => {
    const fields = currentFields().filter((field) => !['row', 'col'].includes(field.type));
    fieldPicker.innerHTML = `<option value="">Select a field (${fields.length})</option>` + fields.map((field) => `<option value="${escapeHtml(field.key)}"${field.key === selectedKey ? ' selected' : ''}>${escapeHtml(field.label || TYPES[field.type]?.label || field.key)}${field.hidden ? ' (hidden)' : ''}</option>`).join('');
    fieldPicker.disabled = !draft || fields.length === 0;
  };
  const applyPreview = (html) => {
    if (!html) return;
    const template = document.createElement('template');
    template.innerHTML = html.trim();
    const fresh = template.content.querySelector('.fb-wrap');
    const existing = preview.querySelector('.fb-wrap');
    if (fresh && existing) existing.replaceWith(fresh);
    preview.querySelectorAll('input, textarea, select, button').forEach((control) => control.tabIndex = -1);
    markSelection();
    updateFieldCount();
    renderFieldPicker();
  };
  const renderInspector = () => {
    const field = currentField();
    if (!field) {
      inspector.innerHTML = '<div class="fbv-inspector-empty">Select a field on the canvas, or add one from the library.</div>';
      return;
    }
    const meta = TYPES[field.type] || { label: field.type };
    const isInput = meta.input === true;
    const isChoice = meta.options === true;
    const encodeOptionPart = (value) => String(value ?? '').replace(/\\/g, '\\\\').replace(/\r/g, '\\r').replace(/\n/g, '\\n').replace(/\|/g, '\\|');
    const options = (field.options || []).map((option) => `${encodeOptionPart(option.value)}|${encodeOptionPart(option.label)}|${option.price || 0}`).join('\n');
    const labelControl = field.type === 'divider' ? '' : `<div class="fba-field"><label>${field.type === 'paragraph' ? 'Text' : 'Label'}</label>${field.type === 'paragraph' ? `<textarea data-field-prop="label" rows="4" maxlength="1000">${escapeHtml(field.label)}</textarea>` : `<input data-field-prop="label" type="text" maxlength="1000" value="${escapeHtml(field.label)}">`}</div>`;
    const headingControl = field.type === 'heading' ? `<div class="fba-field"><label>Heading level</label><select data-setting-prop="level">${['h1','h2','h3','h4','h5','h6'].map((level) => `<option value="${level}"${(field.settings?.level || 'h2') === level ? ' selected' : ''}>${level.toUpperCase()}</option>`).join('')}</select></div>` : '';
    const inputControls = isInput ? `<div class="fba-field"><label>Field key</label><input class="fbv-inspector-key" type="text" value="${escapeHtml(field.key)}" readonly></div><div class="fba-field"><label>Placeholder</label><input data-field-prop="placeholder" type="text" maxlength="1000" value="${escapeHtml(field.placeholder || '')}"></div><div class="fba-field"><label>Help text</label><input data-field-prop="help" type="text" maxlength="2000" value="${escapeHtml(field.help || '')}"></div>${isChoice ? `<div class="fba-field"><label>Options <span class="fba-hint">value|Label|price, use \\| for a literal pipe</span></label><textarea data-field-options rows="6">${escapeHtml(options)}</textarea></div>` : ''}<div class="fba-checks"><label class="fba-check"><input data-field-prop="required" type="checkbox"${field.required ? ' checked' : ''}> Required</label><label class="fba-check"><input data-field-prop="hidden" type="checkbox"${field.hidden ? ' checked' : ''}> Hidden</label></div>` : '';
    const protectedType = ['richtext', 'raw_html'].includes(field.type) && !CAN_UNSAFE;
    inspector.innerHTML = `<span class="fbv-inspector-type">${escapeHtml(meta.label)}</span>${labelControl}${headingControl}${inputControls}<div class="fbv-inspector-actions"><span class="fba-hint">Autosaved draft</span><button class="fba-btn danger sm" type="button" data-fbv-delete${protectedType ? ' disabled title="Unsafe-code permission required"' : ''}>Delete</button></div>`;
  };
  const mutateDefinition = (callback, refreshInspector = false) => {
    if (!draft) return;
    const definition = structuredClone(workingDefinition);
    callback(definition);
    workingDefinition = definition;
    if (refreshInspector) renderInspector();
    updateFieldCount();
    renderFieldPicker();
    queueSave(definition);
  };
  const uniqueKey = (base) => {
    const stem = String(base || 'field').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'field';
    const used = new Set(currentFields().map((field) => field.key));
    let key = stem.slice(0, 80);
    let suffix = 2;
    while (used.has(key)) {
      const ending = `_${suffix++}`;
      key = stem.slice(0, 80 - ending.length) + ending;
    }
    return key;
  };
  const newNode = (key, parent, type, label, order) => ({ key, parent, type, label, placeholder: '', help: '', required: false, width: 12, order, hidden: false, options: [], validation: {}, settings: {} });
  const addField = (type) => {
    const meta = TYPES[type];
    if (!meta || meta.container || (['richtext', 'raw_html'].includes(type) && !CAN_UNSAFE)) return;
    const existingRows = currentFields().filter((field) => field.type === 'row');
    const finalRow = existingRows.at(-1);
    const finalRowHasColumn = finalRow && currentFields().some((field) => field.type === 'col' && field.parent === finalRow.key);
    const nodesNeeded = 1 + (existingRows.length ? (finalRowHasColumn ? 0 : 1) : 2);
    if (currentFields().length + nodesNeeded > 300) {
      setStatus('This form has reached the 300-field limit', 'error');
      return;
    }
    const country = currentFields().find((field) => field.type === 'country' && !field.hidden);
    if (type === 'intl_phone' && !country) {
      setStatus('Add a visible Country field first', 'error');
      return;
    }
    mutateDefinition((definition) => {
      const fields = definition.form.fields;
      let rows = fields.filter((field) => field.type === 'row');
      if (!rows.length) {
        const rowKey = uniqueKey('row_visual');
        fields.push(newNode(rowKey, null, 'row', '', 10));
        rows = fields.filter((field) => field.type === 'row');
      }
      const row = rows.at(-1);
      let columns = fields.filter((field) => field.type === 'col' && field.parent === row.key);
      if (!columns.length) {
        const columnKey = uniqueKey('col_visual');
        fields.push(newNode(columnKey, row.key, 'col', '', 10));
        columns = fields.filter((field) => field.type === 'col' && field.parent === row.key);
      }
      const column = columns.at(-1);
      const siblings = fields.filter((field) => field.parent === column.key);
      const key = uniqueKey(meta.label || type);
      const field = newNode(key, column.key, type, meta.label || type, Math.max(0, ...siblings.map((item) => Number(item.order) || 0)) + 10);
      if (meta.options) field.options = [{ value: 'option_1', label: 'Option 1', price: 0 }];
      if (type === 'heading') field.settings.level = 'h2';
      if (type === 'intl_phone') field.settings.country_field = country.key;
      fields.push(field);
      selectedKey = key;
    }, true);
  };
  const save = async (definition) => {
    if (!draft) throw new Error('Draft is not ready');
    if (definition !== workingDefinition) {
      workingDefinition = structuredClone(definition);
      updateFieldCount();
      renderFieldPicker();
      renderInspector();
    }
    definition = workingDefinition;
    if (saveInFlight) {
      pendingDefinition = definition;
      return;
    }
    saveInFlight = true;
    setStatus('Saving draft...', 'saving');
    try {
      const saved = await request('save', { revision: String(draft.revision), definition: JSON.stringify(definition) });
      draft = saved;
      if (!pendingDefinition && workingDefinition === definition) {
        applyPreview(saved.preview_html);
        hasUnsavedChanges = false;
      }
      showDraftState();
      window.dispatchEvent(new CustomEvent('fbv:draft-saved', { detail: draft }));
    } catch (error) {
      pendingDefinition = null;
      hasUnsavedChanges = true;
      setStatus(error.conflict ? 'Autosave conflict - reload required' : error.message, error.conflict ? 'conflict' : 'error');
      window.dispatchEvent(new CustomEvent('fbv:draft-error', { detail: error }));
      throw error;
    } finally {
      saveInFlight = false;
      if (pendingDefinition) {
        const nextDefinition = pendingDefinition;
        pendingDefinition = null;
        save(nextDefinition).catch(() => {});
      }
    }
  };
  const queueSave = (definition) => {
    if (definition !== workingDefinition) {
      workingDefinition = structuredClone(definition);
      updateFieldCount();
      renderFieldPicker();
      renderInspector();
    }
    definition = workingDefinition;
    window.clearTimeout(saveTimer);
    pendingDefinition = definition;
    hasUnsavedChanges = true;
    setStatus('Unsaved changes', 'saving');
    saveTimer = window.setTimeout(() => {
      const nextDefinition = pendingDefinition;
      pendingDefinition = null;
      if (nextDefinition) save(nextDefinition).catch(() => {});
    }, 700);
  };

  window.fbVisualDraft = { get current() { return draft ? { ...draft, definition: workingDefinition } : null; }, save, queueSave };
  window.addEventListener('fbv:draft-change', (event) => {
    if (event.detail?.definition) queueSave(event.detail.definition);
  });
  request('load').then((loaded) => {
    draft = loaded;
    workingDefinition = loaded.definition;
    applyPreview(draft.preview_html);
    preview.removeAttribute('inert');
    renderInspector();
    renderFieldPicker();
    libraryButtons.forEach((button) => button.disabled = button.dataset.unsafe === '1' && !CAN_UNSAFE);
    showDraftState();
  })
    .catch(() => setStatus('Draft unavailable', 'error'));

  libraryButtons.forEach((button) => button.addEventListener('click', () => addField(button.dataset.fbvType)));
  preview.addEventListener('click', (event) => {
    const field = event.target.closest('.fb-field[data-key]');
    if (!field || !preview.contains(field)) return;
    selectedKey = field.dataset.key;
    markSelection();
    renderInspector();
    renderFieldPicker();
  });
  fieldPicker.addEventListener('change', () => {
    selectedKey = fieldPicker.value || null;
    markSelection();
    renderInspector();
  });
  inspector.addEventListener('input', (event) => {
    const property = event.target.dataset.fieldProp;
    const setting = event.target.dataset.settingProp;
    if (!property && !setting && !event.target.matches('[data-field-options]')) return;
    if (event.target.matches('[data-field-options]')) {
      const parseOptionLine = (line) => {
        const parts = [''];
        let escaped = false;
        for (const character of line) {
          if (escaped) {
            parts[parts.length - 1] += character === 'n' ? '\n' : character === 'r' ? '\r' : character;
            escaped = false;
          } else if (character === '\\') escaped = true;
          else if (character === '|') parts.push('');
          else parts[parts.length - 1] += character;
        }
        if (escaped) parts[parts.length - 1] += '\\';
        return parts;
      };
      const lines = event.target.value.split(/\r?\n/).filter((line) => line.trim() !== '');
      const parsed = lines.map(parseOptionLine);
      const options = parsed.map(([value = '', label = '', price = '0']) => ({ value, label, price: Number(price) }));
      const optionValues = options.map((option) => option.value);
      if (!options.length || options.length > 200 || parsed.some((parts) => parts.length < 2 || parts.length > 3) || new Set(optionValues).size !== optionValues.length || options.some((option) => option.value.trim() === '' || option.label.trim() === '' || /[\x00-\x1f\x7f]/.test(option.value) || !Number.isSafeInteger(option.price) || Math.abs(option.price) > 1000000000000)) {
        setStatus('Options need unique value|Label|integer price entries', 'error');
        return;
      }
      mutateDefinition((definition) => {
        definition.form.fields.find((field) => field.key === selectedKey).options = options;
        const allowedValues = new Set(options.map((option) => option.value));
        Object.values(definition.form.settings.translations || {}).forEach((translation) => {
          const translated = translation?.fields?.[selectedKey]?.options;
          if (!translated) return;
          Object.keys(translated).forEach((value) => { if (!allowedValues.has(value)) delete translated[value]; });
        });
      });
      return;
    }
    const value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    const selected = currentField();
    if (!selected) return;
    if (selected.type === 'country' && property === 'hidden' && value && currentFields().some((candidate) => candidate.type === 'intl_phone' && candidate.settings?.country_field === selected.key)) {
      event.target.checked = false;
      setStatus('A linked international phone requires a visible country', 'error');
      return;
    }
    if (selected.type === 'country' && property === 'required' && !value && currentFields().some((candidate) => candidate.type === 'intl_phone' && candidate.required && candidate.settings?.country_field === selected.key)) {
      event.target.checked = true;
      setStatus('Country must remain required while its linked phone is required', 'error');
      return;
    }
    mutateDefinition((definition) => {
      const field = definition.form.fields.find((candidate) => candidate.key === selectedKey);
      if (!field) return;
      if (setting) field.settings[setting] = value;
      else {
        field[property] = value;
        if (field.type === 'intl_phone' && property === 'required' && value) {
          const countryField = definition.form.fields.find((candidate) => candidate.key === field.settings?.country_field);
          if (countryField) countryField.required = true;
        }
      }
    });
  });
  inspector.addEventListener('click', (event) => {
    const button = event.target.closest('[data-fbv-delete]');
    if (!button || button.disabled) return;
    const field = currentField();
    if (!field) return;
    if (field.type === 'country' && currentFields().some((candidate) => candidate.type === 'intl_phone' && candidate.settings?.country_field === field.key)) {
      setStatus('Remove linked international phone fields first', 'error');
      return;
    }
    mutateDefinition((definition) => {
      definition.form.fields = definition.form.fields.filter((candidate) => candidate.key !== selectedKey);
      const settings = definition.form.settings;
      settings.columns = (settings.columns || []).filter((key) => key !== selectedKey);
      ['confirmation_email_field', 'reply_to_email_field'].forEach((key) => { if (settings[key] === selectedKey) settings[key] = ''; });
      Object.values(settings.translations || {}).forEach((translation) => { if (translation?.fields) delete translation.fields[selectedKey]; });
      definition.form.fields.forEach((candidate) => {
        if (candidate.validation?.after_field === selectedKey) delete candidate.validation.after_field;
        if (candidate.validation?.before_field === selectedKey) delete candidate.validation.before_field;
      });
    });
    selectedKey = null;
    renderInspector();
    renderFieldPicker();
  });

  window.addEventListener('beforeunload', (event) => {
    if (!hasUnsavedChanges && !saveInFlight) return;
    event.preventDefault();
    event.returnValue = '';
  });

  devices.forEach((button) => button.addEventListener('click', () => {
    devices.forEach((candidate) => {
      const active = candidate === button;
      candidate.classList.toggle('is-active', active);
      candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    canvas.dataset.device = button.dataset.device;
  }));
  preview.addEventListener('submit', (event) => event.preventDefault(), true);
})();
</script>
