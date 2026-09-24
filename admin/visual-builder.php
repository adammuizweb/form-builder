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
$tree = fb_get_tree($pdo, $formId);
$fieldCount = 0;
foreach ($tree as $row) foreach ($row['cols'] as $column) $fieldCount += count($column['fields']);
$statusClass = ['active' => 'active', 'draft' => 'draft', 'archived' => 'arch'][$form['status']] ?? 'draft';
$classicUrl = fb_url(['view' => 'builder', 'id' => $formId]);
$settingsUrl = fb_url(['view' => 'settings', 'id' => $formId]);
$formsUrl = fb_url(['view' => 'forms', 'id' => null]);
$csrf = function_exists('csrf_token') ? csrf_token() : '';

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
.fbv-library button { display: flex; align-items: center; gap: .55rem; width: 100%; padding: .55rem .65rem; border: 1px solid var(--adam-border); border-radius: 10px; background: var(--adam-bg); color: var(--adam-text); font: inherit; font-size: .78rem; font-weight: 600; text-align: left; opacity: .72; }
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
.fbv-preview form, .fbv-preview button, .fbv-preview input, .fbv-preview textarea, .fbv-preview select { pointer-events: none; }
.fbv-inspector-empty { padding: 1.1rem; border: 1px dashed var(--adam-border); border-radius: 12px; background: var(--adam-bg); color: var(--adam-muted); font-size: .78rem; line-height: 1.55; }
.fbv-mode-card { margin-top: 1rem; padding: .85rem; border: 1px solid var(--adam-border); border-radius: 12px; }
.fbv-mode-card strong { display: block; margin-bottom: .25rem; font-size: .78rem; }
.fbv-mode-card span { display: block; margin-bottom: .65rem; color: var(--adam-muted); font-size: .72rem; line-height: 1.45; }
@media (max-width: 1100px) {
  .fbv-workspace { grid-template-columns: 190px minmax(320px, 1fr); }
  .fbv-sidebar.right { display: none; }
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
      <p>The visual field library is connected in the next development slice. Existing forms remain editable in Classic.</p>
      <?php foreach (['input' => 'Questions', 'element' => 'Content'] as $group => $label): ?>
        <div class="fbv-library-title"><?= $label ?></div>
        <div class="fbv-library">
          <?php foreach ($types as $type => $meta):
            if (!empty($meta['container']) || ($meta['group'] ?? '') !== $group) continue; ?>
            <button type="button" disabled data-fbv-type="<?= htmlspecialchars((string)$type, ENT_QUOTES) ?>"><?= htmlspecialchars((string)$meta['label'], ENT_QUOTES) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </aside>

    <main class="fbv-stage">
      <div class="fbv-notice" role="status">
        <strong>Draft workspace.</strong>
        <span>Your visual draft is stored separately from the published form with revision-safe autosave. This preview remains read-only and shows the current canonical structure until visual field editing is connected.</span>
      </div>
      <div class="fbv-canvas-tools">
        <div class="fbv-devices" aria-label="Preview width">
          <button class="fbv-device is-active" type="button" data-device="desktop" aria-pressed="true">Desktop</button>
          <button class="fbv-device" type="button" data-device="mobile" aria-pressed="false">Mobile</button>
        </div>
        <span class="fbv-count"><?= $fieldCount ?> field<?= $fieldCount === 1 ? '' : 's' ?></span>
      </div>
      <div class="fbv-canvas-shell" id="fbvCanvas" data-device="desktop">
        <div class="fbv-preview" inert aria-label="Form preview">
          <?= fb_render_form($pdo, $form) ?>
        </div>
      </div>
    </main>

    <aside class="fbv-sidebar right" aria-label="Question properties">
      <h2>Question properties</h2>
      <p>Select a question on the canvas to edit its label, help text, options, validation, and required state.</p>
      <div class="fbv-inspector-empty">The canonical preview is read-only in this foundation slice. Use Classic Builder for edits while the revision-safe draft API is implemented.</div>
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
  const canvas = document.getElementById('fbvCanvas');
  const status = document.getElementById('fbvDraftStatus');
  const devices = document.querySelectorAll('.fbv-device');
  let draft = null;
  let saveTimer = 0;
  let saveInFlight = false;
  let pendingDefinition = null;

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
  const save = async (definition) => {
    if (!draft) throw new Error('Draft is not ready');
    if (saveInFlight) {
      pendingDefinition = definition;
      return;
    }
    saveInFlight = true;
    setStatus('Saving draft...', 'saving');
    try {
      draft = await request('save', { revision: String(draft.revision), definition: JSON.stringify(definition) });
      showDraftState();
      window.dispatchEvent(new CustomEvent('fbv:draft-saved', { detail: draft }));
    } catch (error) {
      pendingDefinition = null;
      setStatus(error.conflict ? 'Autosave conflict - reload required' : 'Autosave failed', error.conflict ? 'conflict' : 'error');
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
    window.clearTimeout(saveTimer);
    pendingDefinition = definition;
    setStatus('Unsaved changes', 'saving');
    saveTimer = window.setTimeout(() => {
      const nextDefinition = pendingDefinition;
      pendingDefinition = null;
      if (nextDefinition) save(nextDefinition).catch(() => {});
    }, 700);
  };

  window.fbVisualDraft = { get current() { return draft; }, save, queueSave };
  window.addEventListener('fbv:draft-change', (event) => {
    if (event.detail?.definition) queueSave(event.detail.definition);
  });
  request('load').then((loaded) => { draft = loaded; showDraftState(); })
    .catch(() => setStatus('Draft unavailable', 'error'));

  devices.forEach((button) => button.addEventListener('click', () => {
    devices.forEach((candidate) => {
      const active = candidate === button;
      candidate.classList.toggle('is-active', active);
      candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    canvas.dataset.device = button.dataset.device;
  }));
  document.querySelector('.fbv-preview')?.addEventListener('submit', (event) => event.preventDefault(), true);
})();
</script>
