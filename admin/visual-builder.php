<?php
// /plugins/form-builder/admin/visual-builder.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

require_once __DIR__ . '/_ui.php';

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
$canManageFieldBin = user_can($pdo, $uid, 'plugin.form-builder.bin.manage') && user_can($pdo, $uid, 'plugin.form-builder.forms.manage-any');
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
.fbv { --fbv-ink: #14261b; --fbv-muted: #68786e; --fbv-line: #dce5de; --fbv-paper: #f7faf7; --fbv-canvas-width: 860px; color: var(--adam-text); }
.fbv.is-left-hidden, .fbv.is-right-hidden { --fbv-canvas-width: 1040px; }
.fbv.is-left-hidden.is-right-hidden { --fbv-canvas-width: 1220px; }
.fbv-topbar { display: flex; align-items: center; gap: .8rem; min-height: 58px; margin: -1rem -1rem 0; padding: .65rem 1rem; position: sticky; top: 0; z-index: 30; border-bottom: 1px solid var(--adam-border); background: color-mix(in srgb, var(--adam-card) 94%, transparent); backdrop-filter: blur(12px); }
.fbv-back { display: inline-grid; place-items: center; width: 36px; height: 36px; border: 1px solid var(--adam-border); border-radius: 10px; color: var(--adam-text); text-decoration: none; }
.fbv-title { min-width: 0; flex: 1; }
.fbv-title strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fbv-title span { color: var(--adam-muted); font-size: .73rem; }
.fbv-status { display: inline-flex; align-items: center; gap: .4rem; color: var(--adam-muted); font-size: .76rem; }
.fbv-status::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #2b7a4a; box-shadow: 0 0 0 4px rgba(43 122 74 / .12); }
.fbv-status[data-state="loading"]::before, .fbv-status[data-state="saving"]::before { background: #ca8a04; box-shadow: 0 0 0 4px rgba(202 138 4 / .14); }
.fbv-status[data-state="error"]::before, .fbv-status[data-state="conflict"]::before { background: #dc2626; box-shadow: 0 0 0 4px rgba(220 38 38 / .12); }
.fbv-workspace { position: relative; display: grid; grid-template-columns: 224px minmax(360px, 1fr) 370px; min-height: calc(100vh - 150px); margin: 0 -1rem -1rem; background: var(--adam-bg); }
.fbv.is-left-hidden .fbv-workspace { grid-template-columns: minmax(360px, 1fr) 370px; }
.fbv.is-right-hidden .fbv-workspace { grid-template-columns: 224px minmax(360px, 1fr); }
.fbv.is-left-hidden.is-right-hidden .fbv-workspace { grid-template-columns: minmax(360px, 1fr); }
.fbv.is-left-hidden .fbv-sidebar.left, .fbv.is-right-hidden .fbv-sidebar.right { display: none; }
.fbv-panel-toggle { position: absolute; top: .75rem; z-index: 20; display: grid; place-items: center; width: 22px; height: 32px; padding: 0; border: 1px solid var(--adam-border); background: var(--adam-card); color: var(--adam-muted); font: 700 16px/1 system-ui, sans-serif; cursor: pointer; box-shadow: 0 3px 10px rgba(17 40 25 / .12); transition: left .2s, right .2s, color .15s, border-color .15s; }
.fbv-panel-toggle:hover, .fbv-panel-toggle:focus-visible { color: var(--adam-accent); border-color: var(--adam-accent); outline: none; }
.fbv-panel-toggle svg { display: block; width: 13px; height: 13px; transition: transform .2s ease; }
.fbv-panel-toggle.left { left: 224px; border-left: 0; border-radius: 0 999px 999px 0; }
.fbv-panel-toggle.right { right: 370px; border-right: 0; border-radius: 999px 0 0 999px; }
.fbv.is-left-hidden .fbv-panel-toggle.left { left: 0; }
.fbv.is-right-hidden .fbv-panel-toggle.right { right: 0; }
.fbv.is-left-hidden .fbv-panel-toggle.left svg, .fbv.is-right-hidden .fbv-panel-toggle.right svg { transform: rotate(180deg); }
.fbv-sidebar { padding: 1rem; background: var(--adam-card); }
.fbv.is-definition-locked .fbv-sidebar, .fbv.is-definition-locked .fbv-canvas-shell { opacity: .68; }
.fbv-sidebar.left { border-right: 1px solid var(--adam-border); }
.fbv-sidebar.right { border-left: 1px solid var(--adam-border); background: color-mix(in srgb, var(--adam-card) 96%, var(--adam-bg)); }
.fbv-sidebar h2 { margin: 0 0 .25rem; font-size: .92rem; }
.fbv-sidebar-section + .fbv-sidebar-section { margin-top: 1.1rem; padding-top: 1rem; border-top: 1px solid var(--adam-border); }
.fbv-sidebar-kicker { display: block; margin-bottom: .2rem; color: var(--adam-accent); font-size: .59rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
.fbv-sidebar-copy { margin: 0 0 .75rem; color: var(--adam-muted); font-size: .72rem; line-height: 1.45; }
.fbv-library-group + .fbv-library-group { margin-top: .55rem; }
.fbv-library-group { overflow: hidden; border: 1px solid var(--adam-border); border-radius: 11px; background: color-mix(in srgb, var(--adam-card) 96%, var(--adam-bg)); }
.fbv-library-group-toggle { display: flex; align-items: center; gap: .45rem; width: 100%; padding: .58rem .65rem; border: 0; background: transparent; color: var(--adam-text); font: inherit; text-align: left; cursor: pointer; }
.fbv-library-group-toggle:hover { background: color-mix(in srgb, var(--adam-accent) 6%, transparent); }
.fbv-library-group-toggle:focus-visible { outline: 2px solid var(--adam-accent); outline-offset: -3px; }
.fbv-library-group-toggle strong { flex: 1; font-size: .7rem; }
.fbv-library-group-toggle span { padding: .12rem .35rem; border-radius: 999px; background: var(--adam-bg); color: var(--adam-muted); font-size: .58rem; font-weight: 700; }
.fbv-library-group-toggle::after { content: ''; width: 6px; height: 6px; margin: 0 .12rem 0 .15rem; border-right: 2px solid var(--adam-text); border-bottom: 2px solid var(--adam-text); transform: rotate(45deg); transition: transform .15s ease; }
.fbv-library-group-toggle[aria-expanded="false"]::after { transform: rotate(-45deg); }
.fbv-library-group-panel { padding: .45rem; border-top: 1px solid var(--adam-border); }
.fbv-library-permission-note { margin: 0 0 .45rem; padding: .45rem .5rem; border-radius: 7px; background: var(--adam-bg); color: var(--adam-muted); font-size: .62rem; line-height: 1.4; }
.fbv-library { display: grid; gap: .4rem; }
.fbv-library button { display: flex; align-items: center; gap: .55rem; width: 100%; padding: .55rem .65rem; border: 1px solid var(--adam-border); border-radius: 10px; background: var(--adam-bg); color: var(--adam-text); font: inherit; font-size: .78rem; font-weight: 600; text-align: left; cursor: pointer; }
.fbv-library button:hover:not(:disabled) { border-color: var(--adam-accent); background: color-mix(in srgb, var(--adam-accent) 6%, var(--adam-card)); }
.fbv-library button:disabled { cursor: not-allowed; opacity: .45; }
.fbv-layout-add { padding: .7rem; border: 1px solid color-mix(in srgb, var(--adam-accent) 24%, var(--adam-border)); border-radius: 13px; background: linear-gradient(145deg, color-mix(in srgb, var(--adam-accent) 7%, var(--adam-card)), var(--adam-bg)); }
.fbv-layout-add-head { display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; }
.fbv-layout-add-head strong { font-size: .75rem; }
.fbv-layout-add-head span { color: var(--adam-muted); font-size: .62rem; }
.fbv-layout-presets { display: grid; grid-template-columns: repeat(4, 1fr); gap: .3rem; margin: .55rem 0; }
.fbv-layout-preset { display: grid; gap: .3rem; min-width: 0; padding: .38rem .28rem; border: 1px solid var(--adam-border); border-radius: 8px; background: var(--adam-card); color: var(--adam-muted); cursor: pointer; }
.fbv-layout-preset:hover, .fbv-layout-preset:focus-visible { border-color: var(--adam-accent); color: var(--adam-accent); }
.fbv-layout-preset:focus-visible { outline: 2px solid var(--adam-accent); outline-offset: 2px; }
.fbv-layout-preset.is-active { border-color: var(--adam-accent); background: color-mix(in srgb, var(--adam-accent) 10%, var(--adam-card)); color: var(--adam-accent); box-shadow: 0 0 0 2px color-mix(in srgb, var(--adam-accent) 10%, transparent); }
.fbv-layout-preset-preview { display: flex; gap: 2px; height: 16px; }
.fbv-layout-preset-preview i { flex: 1; border-radius: 2px; background: currentColor; opacity: .48; }
.fbv-layout-preset > span:last-child { font-size: .6rem; font-weight: 800; text-align: center; }
.fbv-layout-add > .fba-btn { width: 100%; justify-content: center; }
.fbv-layout-current { --fbv-form-accent: #2b7a4a; margin-top: .8rem; overflow: hidden; border: 1px solid color-mix(in srgb, var(--fbv-form-accent) 30%, var(--adam-border)); border-radius: 12px; background: linear-gradient(145deg, color-mix(in srgb, var(--fbv-form-accent) 9%, var(--adam-card)), color-mix(in srgb, var(--fbv-form-accent) 3%, var(--adam-bg))); box-shadow: inset 3px 0 0 var(--fbv-form-accent); }
.fbv-layout-list-head { display: flex; align-items: center; gap: .45rem; width: 100%; padding: .58rem .65rem .58rem .75rem; border: 0; background: transparent; color: var(--adam-text); font: inherit; text-align: left; cursor: pointer; }
.fbv-layout-list-head strong { display: inline-flex; align-items: center; gap: .4rem; flex: 1; font-size: .68rem; }
.fbv-layout-list-head strong::before { content: ''; width: 8px; height: 8px; flex: 0 0 8px; border: 1px solid color-mix(in srgb, var(--fbv-form-accent) 70%, #000); border-radius: 50%; background: var(--fbv-form-accent); }
.fbv-layout-list-head span { color: var(--adam-muted); font-size: .61rem; }
.fbv-layout-list-head::after { content: ''; width: 6px; height: 6px; margin: 0 .12rem 0 .15rem; border-right: 2px solid var(--adam-text); border-bottom: 2px solid var(--adam-text); transform: rotate(45deg); transition: transform .15s ease; }
.fbv-layout-list-head[aria-expanded="false"]::after { transform: rotate(-45deg); }
.fbv-layout-list-head:hover { background: color-mix(in srgb, var(--fbv-form-accent) 7%, transparent); }
.fbv-layout-list-head:focus-visible { outline: 2px solid var(--adam-accent); outline-offset: -3px; }
.fbv-layout-list-panel { padding: .5rem; border-top: 1px solid color-mix(in srgb, var(--fbv-form-accent) 20%, var(--adam-border)); }
.fbv-layout-list { display: grid; gap: .5rem; }
.fbv-layout-empty { padding: .7rem; border: 1px dashed var(--adam-border); border-radius: 10px; color: var(--adam-muted); font-size: .67rem; line-height: 1.4; text-align: center; }
.fbv-layout-row { padding: .55rem; border: 1px solid color-mix(in srgb, var(--fbv-form-accent) 18%, var(--adam-border)); border-radius: 9px; background: color-mix(in srgb, var(--fbv-form-accent) 3%, var(--adam-bg)); }
.fbv-layout-row.is-preview-linked { border-color: var(--fbv-form-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--fbv-form-accent) 20%, transparent); }
.fbv-layout-row-head { display: flex; align-items: center; justify-content: space-between; gap: .4rem; }
.fbv-layout-row-head strong { font-size: .72rem; }
.fbv-layout-row-head span { padding: .12rem .35rem; border-radius: 999px; background: var(--adam-card); color: var(--adam-muted); font-size: .58rem; }
.fbv-layout-map { display: flex; gap: 3px; height: 20px; margin: .45rem 0; padding: 3px; border: 1px solid var(--adam-border); border-radius: 6px; background: var(--adam-card); }
.fbv-layout-map i { flex: 1; min-width: 3px; border-radius: 3px; background: color-mix(in srgb, var(--fbv-form-accent) 48%, var(--adam-border)); }
.fbv-layout-map em { display: grid; place-items: center; min-width: 20px; color: var(--adam-muted); font-size: .55rem; font-style: normal; }
.fbv-layout-row-controls { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .4rem; align-items: end; }
.fbv-layout-row-controls label span { display: block; margin-bottom: .2rem; color: var(--adam-muted); font-size: .58rem; font-weight: 700; }
.fbv-layout-row select { width: 100%; min-height: 30px; font-size: .67rem; }
.fbv-layout-actions { display: grid; grid-template-columns: repeat(3, 28px); gap: .2rem; }
.fbv-layout-actions button { width: 28px; height: 30px; padding: 0; border: 1px solid var(--adam-border); border-radius: 7px; background: var(--adam-card); color: var(--adam-text); cursor: pointer; }
.fbv-layout-actions button:hover:not(:disabled), .fbv-layout-actions button:focus-visible { border-color: var(--adam-accent); color: var(--adam-accent); }
.fbv-layout-actions button:focus-visible { outline: 2px solid var(--adam-accent); outline-offset: 2px; }
.fbv-layout-actions button.danger:hover:not(:disabled), .fbv-layout-actions button.danger:focus-visible { border-color: var(--adam-danger); color: var(--adam-danger); }
.fbv-layout-actions button:disabled { cursor: not-allowed; opacity: .34; }
.fbv-field-guidance { margin: 0 0 .7rem; padding: .62rem .7rem; border: 1px solid color-mix(in srgb, var(--adam-accent) 35%, var(--adam-border)); border-radius: 10px; background: color-mix(in srgb, var(--adam-accent) 8%, var(--adam-card)); font-size: .68rem; line-height: 1.45; }
.fbv-field-guidance strong, .fbv-field-guidance span { display: block; }
.fbv-field-guidance strong { margin-bottom: .12rem; color: var(--adam-accent); font-size: .7rem; }
.fbv-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
#fbvQuestionSection { border-radius: 12px; transition: background .18s, box-shadow .18s; }
#fbvQuestionSection.is-preview-target { background: color-mix(in srgb, var(--adam-accent) 7%, transparent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--adam-accent) 22%, transparent); }
.fbv-library button::before { content: '+'; display: grid; place-items: center; width: 21px; height: 21px; border-radius: 7px; background: color-mix(in srgb, var(--adam-accent) 12%, transparent); color: var(--adam-accent); }
.fbv-stage { min-width: 0; padding: 1rem clamp(1rem, 3vw, 2.5rem) 3rem; overflow: auto; }
.fbv-notice { display: flex; gap: .65rem; align-items: flex-start; max-width: var(--fbv-canvas-width); margin: 0 auto 1rem; padding: .7rem .85rem; border: 1px solid color-mix(in srgb, var(--adam-accent) 28%, var(--adam-border)); border-radius: 12px; background: color-mix(in srgb, var(--adam-accent) 6%, var(--adam-card)); font-size: .78rem; line-height: 1.5; transition: max-width .25s ease; }
.fbv-notice .fba-btn { flex: 0 0 auto; margin-left: auto; }
.fbv-canvas-tools { display: flex; align-items: center; justify-content: space-between; gap: .7rem; max-width: var(--fbv-canvas-width); margin: 0 auto .65rem; transition: max-width .25s ease; }
.fbv-devices { display: inline-flex; gap: .2rem; padding: .2rem; border: 1px solid var(--adam-border); border-radius: 10px; background: var(--adam-card); }
.fbv-device { border: 0; border-radius: 7px; padding: .35rem .6rem; background: transparent; color: var(--adam-muted); font: inherit; font-size: .73rem; cursor: pointer; }
.fbv-device.is-active { background: var(--adam-accent); color: #fff; }
.fbv-count { color: var(--adam-muted); font-size: .73rem; }
.fbv-canvas-shell { width: 100%; max-width: var(--fbv-canvas-width); min-height: 500px; margin: 0 auto; padding: clamp(1rem, 3vw, 2.2rem); border: 1px solid var(--adam-border); border-radius: 18px; background: #eef3ef; box-shadow: 0 22px 60px rgba(17 40 25 / .11); transition: max-width .25s ease; }
.fbv-canvas-shell[data-device="mobile"] { max-width: 430px; }
.fbv-preview-frame { display: block; width: 100%; height: min(900px, 78vh); min-height: 620px; border: 0; border-radius: 12px; background: #fff; }
.fbv-inspector-empty { padding: 1.1rem; border: 1px dashed var(--adam-border); border-radius: 12px; background: var(--adam-bg); color: var(--adam-muted); font-size: .78rem; line-height: 1.55; }
.fbv-inspector-type { display: inline-flex; margin-bottom: .8rem; padding: .2rem .45rem; border-radius: 999px; background: color-mix(in srgb, var(--adam-accent) 10%, transparent); color: var(--adam-accent); font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
.fbv-inspector .fba-field { margin-bottom: .7rem; }
.fbv-field-picker-shell { margin-bottom: 1rem; padding: .7rem; border: 1px solid var(--adam-border); border-radius: 12px; background: var(--adam-bg); }
.fbv-field-picker-shell > label { display: block; margin-bottom: .38rem; color: var(--adam-muted); font-size: .65rem; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
.fbv-field-picker-control { position: relative; }
.fbv-field-picker-control::after { content: ''; position: absolute; top: 50%; right: .85rem; width: 7px; height: 7px; border-right: 2px solid var(--adam-muted); border-bottom: 2px solid var(--adam-muted); transform: translateY(-70%) rotate(45deg); pointer-events: none; }
.fbv-field-picker { width: 100%; min-height: 42px; appearance: none; padding: .62rem 2.25rem .62rem .75rem; border: 1.5px solid var(--adam-border); border-radius: 9px; outline: none; background: var(--adam-card); color: var(--adam-text); font-family: inherit; font-size: .8rem; font-weight: 650; line-height: 1.25; cursor: pointer; }
.fbv-field-picker:hover:not(:disabled) { border-color: color-mix(in srgb, var(--adam-accent) 60%, var(--adam-border)); }
.fbv-field-picker:focus { border-color: var(--adam-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--adam-accent) 14%, transparent); }
.fbv-field-picker:disabled { cursor: not-allowed; opacity: .58; }
.fbv-field-picker-help { display: block; margin-top: .4rem; color: var(--adam-muted); font-size: .68rem; line-height: 1.4; }
.fbv-inspector textarea { resize: vertical; }
.fbv-upload-editor-shell, .fbv-content-editor-shell { margin-bottom: .75rem; }
.fbv-upload-editor-error { padding: .7rem; border: 1px solid #dc2626; border-radius: 9px; color: #b91c1c; font-size: .74rem; line-height: 1.45; }
#fbvUploadEditorParking, #fbvContentEditorParking { position: absolute; left: -100000px; width: 360px; visibility: hidden; pointer-events: none; }
.fbv-content-editor-shell [data-editor-area="quill"] { min-height: 260px; }
.fbv-content-editor-shell [data-editor-quill] { min-height: 210px; }
.fbv-protected-content { padding: .75rem; border: 1px dashed var(--adam-border); border-radius: 10px; color: var(--adam-muted); font-size: .72rem; line-height: 1.45; }
.fbv-image-preview { display: grid; place-items: center; min-height: 120px; margin-bottom: .65rem; overflow: hidden; border: 1px dashed var(--adam-border); border-radius: 10px; background: var(--adam-card); color: var(--adam-muted); font-size: .72rem; }
.fbv-image-preview img { display: block; width: 100%; max-height: 220px; object-fit: contain; }
.fbv-image-actions { display: flex; gap: .45rem; margin-bottom: .7rem; }
.fbv-inspector-actions { display: flex; justify-content: space-between; gap: .5rem; margin-top: 1rem; padding-top: .8rem; border-top: 1px solid var(--adam-border); }
.fbv-position-actions { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; }
.fbv-inspector-key { font-family: ui-monospace, monospace; font-size: .72rem; }
.fbv-options { margin: .9rem 0 1rem; }
.fbv-options-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .7rem; margin-bottom: .65rem; }
.fbv-options-head strong { display: block; font-size: .78rem; }
.fbv-options-head span { display: block; margin-top: .14rem; color: var(--adam-muted); font-size: .68rem; line-height: 1.4; }
.fbv-option-list { display: grid; gap: .65rem; }
.fbv-option-card { overflow: hidden; border: 1px solid var(--adam-border); border-radius: 12px; background: var(--adam-card); box-shadow: 0 4px 14px rgba(17 40 25 / .04); }
.fbv-option-card-head { display: flex; align-items: center; gap: .35rem; padding: .48rem .55rem; border-bottom: 1px solid var(--adam-border); background: color-mix(in srgb, var(--adam-accent) 4%, var(--adam-bg)); }
.fbv-option-number { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 7px; background: color-mix(in srgb, var(--adam-accent) 12%, transparent); color: var(--adam-accent); font-size: .66rem; font-weight: 800; }
.fbv-option-card-head strong { min-width: 0; flex: 1; overflow: hidden; color: var(--adam-muted); font-size: .67rem; font-weight: 750; text-overflow: ellipsis; white-space: nowrap; }
.fbv-option-icon { display: grid; place-items: center; width: 25px; height: 25px; padding: 0; border: 1px solid transparent; border-radius: 7px; background: transparent; color: var(--adam-muted); font: 700 .75rem/1 inherit; cursor: pointer; }
.fbv-option-icon:hover:not(:disabled), .fbv-option-icon:focus-visible { border-color: var(--adam-border); background: var(--adam-card); color: var(--adam-accent); outline: none; }
.fbv-option-icon.danger:hover:not(:disabled), .fbv-option-icon.danger:focus-visible { color: var(--adam-danger); }
.fbv-option-icon:disabled { cursor: not-allowed; opacity: .35; }
.fbv-option-body { display: grid; gap: .65rem; padding: .7rem; }
.fbv-option-control { display: grid; gap: .3rem; }
.fbv-option-control > span, .fbv-option-advanced label > span { color: var(--adam-muted); font-size: .65rem; font-weight: 750; letter-spacing: .04em; }
.fbv-option-control input, .fbv-option-control textarea, .fbv-option-advanced input { width: 100%; min-height: 38px; padding: .5rem .65rem; border: 1.5px solid var(--adam-border); border-radius: 8px; outline: none; background: var(--adam-bg); color: var(--adam-text); font: inherit; font-size: .78rem; }
.fbv-option-control textarea { min-height: 58px; resize: vertical; }
.fbv-option-control input:focus, .fbv-option-control textarea:focus, .fbv-option-advanced input:focus { border-color: var(--adam-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--adam-accent) 12%, transparent); }
.fbv-option-features { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
.fbv-option-feature { padding: .55rem; border: 1px solid var(--adam-border); border-radius: 9px; background: var(--adam-bg); }
.fbv-option-feature:only-child { grid-column: 1 / -1; }
.fbv-option-feature > label { display: flex; align-items: flex-start; gap: .42rem; margin: 0; color: var(--adam-text); font-size: .7rem; font-weight: 700; letter-spacing: 0; text-transform: none; cursor: pointer; }
.fbv-option-feature input[type=checkbox] { margin-top: .1rem; accent-color: var(--adam-accent); }
.fbv-option-feature small { display: block; margin-top: .08rem; color: var(--adam-muted); font-size: .61rem; font-weight: 500; line-height: 1.35; }
.fbv-option-feature input[type=number] { width: 100%; min-height: 34px; margin-top: .5rem; padding: .42rem .5rem; border: 1px solid var(--adam-border); border-radius: 7px; background: var(--adam-card); color: var(--adam-text); font: inherit; font-size: .75rem; }
.fbv-option-advanced { border-top: 1px dashed var(--adam-border); padding-top: .15rem; }
.fbv-option-advanced summary { color: var(--adam-muted); font-size: .68rem; font-weight: 700; cursor: pointer; }
.fbv-option-advanced label { display: grid; gap: .3rem; margin-top: .55rem; }
.fbv-option-empty { padding: .8rem; border: 1px dashed var(--adam-border); border-radius: 10px; color: var(--adam-muted); font-size: .72rem; text-align: center; }
.fbv-property-group { margin: .9rem 0; padding: .75rem; border: 1px solid var(--adam-border); border-radius: 11px; background: var(--adam-bg); }
.fbv-property-group > strong { display: block; margin-bottom: .65rem; font-size: .74rem; }
.fbv-property-group .fba-field:last-child { margin-bottom: 0; }
.fbv-mode-card { margin-top: 1rem; padding: .85rem; border: 1px solid var(--adam-border); border-radius: 12px; }
.fbv-mode-card strong { display: block; margin-bottom: .25rem; font-size: .78rem; }
.fbv-mode-card span { display: block; margin-bottom: .65rem; color: var(--adam-muted); font-size: .72rem; line-height: 1.45; }
@media (max-width: 1180px) {
  .fbv-workspace { grid-template-columns: 190px minmax(320px, 1fr); }
  .fbv.is-left-hidden .fbv-workspace, .fbv.is-left-hidden.is-right-hidden .fbv-workspace { grid-template-columns: minmax(320px, 1fr); }
  .fbv.is-right-hidden:not(.is-left-hidden) .fbv-workspace { grid-template-columns: 190px minmax(320px, 1fr); }
  .fbv-panel-toggle.left { left: 190px; }
  .fbv-panel-toggle.right { right: 0; }
  .fbv.is-left-hidden .fbv-panel-toggle.left { left: 0; }
  .fbv-sidebar.right { grid-column: 1 / -1; border-left: 0; border-top: 1px solid var(--adam-border); }
}
@media (max-width: 760px) {
  .fbv-topbar { flex-wrap: wrap; }
  .fbv-status { order: 10; width: 100%; padding-left: .2rem; font-size: .7rem; }
  .fbv-workspace { display: block; }
  .fbv-panel-toggle.left { left: 0; }
  .fbv-sidebar.left { border: 0; border-bottom: 1px solid var(--adam-border); }
  .fbv-library { display: flex; overflow-x: auto; padding-bottom: .25rem; }
  .fbv-library button { min-width: 135px; }
  .fbv-layout-list { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
  .fbv-stage { padding: 1rem .75rem 2rem; }
  .fbv-option-features { grid-template-columns: 1fr; }
}
</style>

<div class="fbv" id="fbvApp">
  <header class="fbv-topbar">
    <a class="fbv-back" href="<?= htmlspecialchars($formsUrl, ENT_QUOTES) ?>" aria-label="Back to forms">&larr;</a>
    <div class="fbv-title">
      <strong id="fbvFormTitle"><?= htmlspecialchars((string)$form['title'], ENT_QUOTES) ?></strong>
      <span id="fbvFormSlug"><?= htmlspecialchars((string)$form['slug'], ENT_QUOTES) ?> &middot; Visual Builder</span>
    </div>
    <span class="fba-badge <?= $statusClass ?>" id="fbvFormStatus"><?= htmlspecialchars((string)$form['status'], ENT_QUOTES) ?></span>
    <div class="fbv-status" id="fbvDraftStatus" data-state="loading" role="status">Loading draft...</div>
    <a class="fba-btn" href="<?= htmlspecialchars($settingsUrl, ENT_QUOTES) ?>">Settings</a>
    <a class="fba-btn" href="<?= htmlspecialchars($classicUrl, ENT_QUOTES) ?>"><?= svg_ico('panel-top') ?>Classic</a>
    <button class="fba-btn primary" id="fbvPublish" type="button" disabled>Publish</button>
  </header>

  <div class="fbv-workspace">
    <button class="fbv-panel-toggle left" id="fbvToggleLeft" type="button" aria-controls="fbvQuestionLibrary" aria-expanded="true" title="Hide question library"><?= svg_ico('chevron-left', 'fbv-panel-icon') ?></button>
    <button class="fbv-panel-toggle right" id="fbvToggleRight" type="button" aria-controls="fbvQuestionProperties" aria-expanded="true" title="Hide question properties"><?= svg_ico('chevron-right', 'fbv-panel-icon') ?></button>
    <aside class="fbv-sidebar left" id="fbvQuestionLibrary" aria-label="Question library">
      <section class="fbv-sidebar-section" id="fbvLayoutSection" aria-labelledby="fbvLayoutHeading">
        <span class="fbv-sidebar-kicker">Structure</span>
        <h2 id="fbvLayoutHeading">Layout</h2>
        <p class="fbv-sidebar-copy">Create the row and column structure before adding questions.</p>
        <div class="fbv-layout-add">
          <div class="fbv-layout-add-head"><strong>New row</strong><span>Choose columns</span></div>
          <input id="fbvNewRowColumns" type="hidden" value="2">
          <div class="fbv-layout-presets" role="group" aria-label="Columns in new row">
            <?php for ($columnPreset = 1; $columnPreset <= 4; $columnPreset++): ?>
            <button class="fbv-layout-preset<?= $columnPreset === 2 ? ' is-active' : '' ?>" type="button" data-new-row-columns="<?= $columnPreset ?>" aria-pressed="<?= $columnPreset === 2 ? 'true' : 'false' ?>" aria-label="<?= $columnPreset ?> column<?= $columnPreset === 1 ? '' : 's' ?>">
              <span class="fbv-layout-preset-preview" aria-hidden="true"><?php for ($slot = 0; $slot < $columnPreset; $slot++): ?><i></i><?php endfor; ?></span>
              <span><?= $columnPreset ?></span>
            </button>
            <?php endfor; ?>
          </div>
          <button class="fba-btn sm" id="fbvAddRow" type="button" disabled>Add 2-column row</button>
        </div>
        <div class="fbv-layout-current" id="fbvLayoutCurrent">
          <button class="fbv-layout-list-head" id="fbvLayoutToggle" type="button" aria-expanded="true" aria-controls="fbvLayoutListPanel">
            <strong>Layout</strong><span id="fbvLayoutCount">0 rows</span>
          </button>
          <div class="fbv-layout-list-panel" id="fbvLayoutListPanel">
            <div class="fbv-layout-list" id="fbvLayoutList"></div>
          </div>
          <span class="fbv-sr-only" id="fbvStructureStatus" role="status" aria-live="polite"></span>
        </div>
      </section>
      <section class="fbv-sidebar-section" id="fbvQuestionSection" aria-labelledby="fbvQuestionHeading">
        <span class="fbv-sidebar-kicker">Fields</span>
        <h2 id="fbvQuestionHeading" tabindex="-1">Add a question</h2>
        <p class="fbv-sidebar-copy">New fields are added to the final column and can then be moved on the canvas.</p>
        <div class="fbv-field-guidance" id="fbvFieldGuidance" role="status" hidden><strong id="fbvFieldGuidanceTitle">Empty column selected</strong><span id="fbvFieldGuidanceText"></span></div>
        <?php foreach (['input' => 'Questions', 'element' => 'Content'] as $group => $label):
          $groupTypes = array_filter($types, static fn(array $meta): bool => empty($meta['container']) && ($meta['group'] ?? '') === $group);
          $groupExpanded = $group === 'input';
          $groupPanelId = 'fbvLibraryPanel' . ucfirst($group); ?>
          <section class="fbv-library-group" data-library-group="<?= $group ?>">
            <button class="fbv-library-group-toggle" id="fbvLibraryToggle<?= ucfirst($group) ?>" type="button" data-library-toggle="<?= $group ?>" aria-expanded="<?= $groupExpanded ? 'true' : 'false' ?>" aria-controls="<?= $groupPanelId ?>">
              <strong><?= $label ?></strong><span><?= count($groupTypes) ?> types</span>
            </button>
            <div class="fbv-library-group-panel" id="<?= $groupPanelId ?>"<?= $groupExpanded ? '' : ' hidden' ?>>
              <?php if ($group === 'element' && !$canUnsafeCode): ?><p class="fbv-library-permission-note" id="fbvProtectedContentNote">Rich Text and Raw HTML require unsafe-code permission.</p><?php endif; ?>
              <div class="fbv-library">
            <?php foreach ($groupTypes as $type => $meta): ?>
              <?php $unsafeType = in_array($type, ['richtext', 'raw_html'], true); ?>
              <button type="button" disabled data-fbv-type="<?= htmlspecialchars((string)$type, ENT_QUOTES) ?>" data-unsafe="<?= $unsafeType ? '1' : '0' ?>"<?= $unsafeType && !$canUnsafeCode ? ' aria-describedby="fbvProtectedContentNote" title="Unsafe-code permission required"' : '' ?>><?= htmlspecialchars((string)$meta['label'], ENT_QUOTES) ?></button>
            <?php endforeach; ?>
              </div>
            </div>
          </section>
        <?php endforeach; ?>
      </section>
    </aside>

    <main class="fbv-stage">
      <div class="fbv-notice" role="status">
        <strong>Draft workspace.</strong>
        <span>Select a field to edit its draft. Changes autosave separately and do not affect the live form until you publish.</span>
        <button class="fba-btn sm" id="fbvReset" type="button" hidden>Reload Classic version</button>
      </div>
      <div class="fbv-canvas-tools">
        <div class="fbv-devices" aria-label="Preview width">
          <button class="fbv-device is-active" type="button" data-device="desktop" aria-pressed="true">Desktop</button>
          <button class="fbv-device" type="button" data-device="mobile" aria-pressed="false">Mobile</button>
        </div>
        <span class="fbv-count" id="fbvFieldCount"><?= $fieldCount ?> field<?= $fieldCount === 1 ? '' : 's' ?></span>
      </div>
      <div class="fbv-canvas-shell" id="fbvCanvas" data-device="desktop">
        <iframe class="fbv-preview-frame" id="fbvPreviewFrame" title="Public form preview" src="about:blank" data-src="/fb-visual-preview/?id=<?= $formId ?>" sandbox="allow-same-origin"></iframe>
      </div>
    </main>

    <aside class="fbv-sidebar right" id="fbvQuestionProperties" aria-label="Question properties">
      <h2>Question properties</h2>
      <p class="fbv-sidebar-copy">Select a question on the canvas to edit its content, validation, layout, required state, and visibility.</p>
      <div class="fbv-field-picker-shell">
        <label for="fbvFieldPicker">Editing field</label>
        <div class="fbv-field-picker-control">
          <select class="fbv-field-picker" id="fbvFieldPicker" disabled><option value="">Select a field</option></select>
        </div>
        <span class="fbv-field-picker-help">Pick any question directly, including hidden fields.</span>
      </div>
      <div class="fbv-inspector" id="fbvInspector"><div class="fbv-inspector-empty">Select a field on the canvas, or add one from the library.</div></div>
      <div class="fbv-mode-card">
        <strong>Recovery</strong>
        <span>Published field removals move to the Form Builder Bin and can be restored without losing submission history.</span>
        <?php if ($canManageFieldBin): ?><a class="fba-btn sm" href="?page=admin/bin/form-builder/index">Open Field Bin</a><?php endif; ?>
        <a class="fba-btn sm" href="<?= htmlspecialchars($classicUrl, ENT_QUOTES) ?>"><?= svg_ico('panel-top') ?>Open Classic Builder</a>
      </div>
    </aside>
  </div>
  <div id="fbvUploadEditorParking" aria-hidden="true">
    <div class="fbv-upload-editor-shell" id="fbvUploadEditorShell">
      <?php if (function_exists('content_editor_render_mount')): ?>
      <?= content_editor_render_mount([
          'id' => 'fbv-upload-description-editor',
          'name' => 's_upload_description_html',
          'mode_name' => 's_upload_description_mode',
          'value' => '',
          'initial_mode' => 'quill',
          'label' => __('Description'),
      ]) ?>
      <?php else: ?>
      <div class="fbv-upload-editor-error" data-upload-editor-error role="alert"><?= htmlspecialchars((string)__('The description editor is unavailable. Upload descriptions cannot be edited safely.'), ENT_QUOTES) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div id="fbvContentEditorParking" aria-hidden="true">
    <div class="fbv-content-editor-shell" id="fbvContentEditorShell">
      <?php if ($canUnsafeCode && function_exists('content_editor_render_mount')): ?>
      <?= content_editor_render_mount([
          'id' => 'fbv-content-editor',
          'name' => 's_visual_content_html',
          'mode_name' => 's_visual_content_mode',
          'value' => '',
          'initial_mode' => 'codemirror',
          'label' => __('Content'),
      ]) ?>
      <?php elseif ($canUnsafeCode): ?>
      <div class="fbv-upload-editor-error" data-content-editor-error role="alert"><?= htmlspecialchars((string)__('The content editor is unavailable. Protected content cannot be edited safely.'), ENT_QUOTES) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(() => {
  const ENDPOINT = '/fb-visual-builder/';
  const FORM_ID = <?= $formId ?>;
  const CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const ADMIN_BASE = <?= json_encode(rtrim((string)(defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : ''), '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const CAN_UNSAFE = <?= $canUnsafeCode ? 'true' : 'false' ?>;
  const TYPES = <?= json_encode($types, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const FORM_ACCENTS = <?= json_encode(array_map(static fn(array $preset): string => (string)$preset['accent'], fb_accent_presets()), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const app = document.getElementById('fbvApp');
  const questionLibrary = document.getElementById('fbvQuestionLibrary');
  const questionProperties = document.getElementById('fbvQuestionProperties');
  const canvas = document.getElementById('fbvCanvas');
  const previewFrame = document.getElementById('fbvPreviewFrame');
  const inspector = document.getElementById('fbvInspector');
  const fieldPicker = document.getElementById('fbvFieldPicker');
  const fieldCount = document.getElementById('fbvFieldCount');
  const formTitle = document.getElementById('fbvFormTitle');
  const formSlug = document.getElementById('fbvFormSlug');
  const formStatus = document.getElementById('fbvFormStatus');
  const publishButton = document.getElementById('fbvPublish');
  const resetButton = document.getElementById('fbvReset');
  const status = document.getElementById('fbvDraftStatus');
  const devices = document.querySelectorAll('.fbv-device');
  const libraryButtons = document.querySelectorAll('[data-fbv-type]');
  const libraryGroupToggles = document.querySelectorAll('[data-library-toggle]');
  const addRowButton = document.getElementById('fbvAddRow');
  const newRowColumns = document.getElementById('fbvNewRowColumns');
  const newRowLayoutButtons = document.querySelectorAll('[data-new-row-columns]');
  const layoutCurrent = document.getElementById('fbvLayoutCurrent');
  const layoutToggle = document.getElementById('fbvLayoutToggle');
  const layoutPanel = document.getElementById('fbvLayoutListPanel');
  const layoutList = document.getElementById('fbvLayoutList');
  const layoutCount = document.getElementById('fbvLayoutCount');
  const structureStatus = document.getElementById('fbvStructureStatus');
  const questionSection = document.getElementById('fbvQuestionSection');
  const questionHeading = document.getElementById('fbvQuestionHeading');
  const fieldGuidance = document.getElementById('fbvFieldGuidance');
  const fieldGuidanceTitle = document.getElementById('fbvFieldGuidanceTitle');
  const fieldGuidanceText = document.getElementById('fbvFieldGuidanceText');
  const leftToggle = document.getElementById('fbvToggleLeft');
  const rightToggle = document.getElementById('fbvToggleRight');
  const uploadEditorParking = document.getElementById('fbvUploadEditorParking');
  const uploadEditorShell = document.getElementById('fbvUploadEditorShell');
  const uploadEditorRoot = uploadEditorShell?.querySelector('[data-jyavani-editor-mount]') || null;
  const contentEditorParking = document.getElementById('fbvContentEditorParking');
  const contentEditorShell = document.getElementById('fbvContentEditorShell');
  const contentEditorRoot = contentEditorShell?.querySelector('[data-jyavani-editor-mount]') || null;
  const UPLOAD_MAX_FILES = <?= FB_UPLOAD_MAX_FILES ?>;
  const UPLOAD_DESCRIPTION_MAX_LENGTH = <?= FB_UPLOAD_DESCRIPTION_MAX_LENGTH ?>;
  const UPLOAD_MIB = 1024 * 1024;
  const UPLOAD_TEXT = <?= json_encode([
      'maxFiles' => __('Max files'),
      'maxSize' => __('Max size (MB)'),
      'extensions' => __('Allowed extensions'),
      'previewMode' => __('Preview mode'),
      'none' => __('None'),
      'icon' => __('Icon'),
      'real' => __('Real preview'),
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  let draft = null;
  let workingDefinition = null;
  let selectedKey = null;
  let saveTimer = 0;
  let saveInFlight = false;
  let pendingDefinition = null;
  let hasUnsavedChanges = false;
  let hasPendingControlChanges = false;
  let draggedFieldKey = null;
  let retryTimer = 0;
  let preview = null;
  let uploadDescriptionEditor = null;
  let uploadEditorFieldKey = null;
  let uploadEditorApplying = false;
  let uploadEditorSelectionToken = 0;
  let contentEditor = null;
  let contentEditorFieldKey = null;
  let contentEditorApplying = false;
  let contentEditorSelectionToken = 0;
  let targetColumnKey = null;
  let linkedLayoutRowKey = null;
  let layoutHighlightTimer = 0;
  let fieldGuidanceTimer = 0;
  let definitionLocked = false;

  const setPanelHidden = (side, hidden, persist = true) => {
    const toggle = side === 'left' ? leftToggle : rightToggle;
    app.classList.toggle(`is-${side}-hidden`, hidden);
    toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
    toggle.title = `${hidden ? 'Show' : 'Hide'} question ${side === 'left' ? 'library' : 'properties'}`;
    if (persist) {
      try { localStorage.setItem(`fbv_${side}_hidden`, hidden ? '1' : '0'); } catch (error) {}
    }
  };
  leftToggle.addEventListener('click', () => setPanelHidden('left', !app.classList.contains('is-left-hidden')));
  rightToggle.addEventListener('click', () => setPanelHidden('right', !app.classList.contains('is-right-hidden')));
  try {
    setPanelHidden('left', localStorage.getItem('fbv_left_hidden') === '1', false);
    setPanelHidden('right', localStorage.getItem('fbv_right_hidden') === '1', false);
  } catch (error) {}
  const setLibraryGroupExpanded = (group, expanded, persist = true) => {
    const toggle = Array.from(libraryGroupToggles).find((candidate) => candidate.dataset.libraryToggle === group);
    const panel = toggle ? document.getElementById(toggle.getAttribute('aria-controls')) : null;
    if (!toggle || !panel) return;
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    toggle.title = `${expanded ? 'Collapse' : 'Expand'} ${toggle.querySelector('strong')?.textContent || 'field group'}`;
    panel.hidden = !expanded;
    if (persist) {
      try { localStorage.setItem(`fbv_library_${group}_collapsed`, expanded ? '0' : '1'); } catch (error) {}
    }
  };
  libraryGroupToggles.forEach((toggle) => {
    const group = toggle.dataset.libraryToggle;
    let expanded = group === 'input';
    try {
      const saved = localStorage.getItem(`fbv_library_${group}_collapsed`);
      if (saved !== null) expanded = saved !== '1';
    } catch (error) {}
    setLibraryGroupExpanded(group, expanded, false);
    toggle.addEventListener('click', () => setLibraryGroupExpanded(group, toggle.getAttribute('aria-expanded') !== 'true'));
  });
  const setLayoutCollapsed = (collapsed, persist = true) => {
    layoutPanel.hidden = collapsed;
    layoutToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    layoutToggle.title = collapsed ? 'Expand current layout' : 'Collapse current layout';
    if (persist) {
      try { localStorage.setItem(`fbv_layout_collapsed_${FORM_ID}`, collapsed ? '1' : '0'); } catch (error) {}
    }
  };
  layoutToggle.addEventListener('click', () => setLayoutCollapsed(layoutToggle.getAttribute('aria-expanded') === 'true'));
  try { setLayoutCollapsed(localStorage.getItem(`fbv_layout_collapsed_${FORM_ID}`) === '1', false); } catch (error) {}

  const setStatus = (message, state = 'ready') => {
    status.textContent = message;
    status.dataset.state = state;
  };
  const updateActions = () => {
    const busy = !draft || hasUnsavedChanges || hasPendingControlChanges || saveInFlight || definitionLocked;
    publishButton.disabled = busy || draft.published_changed || !draft.has_unpublished_changes;
    publishButton.title = draft?.published_changed ? 'Reload the Classic version before publishing' : publishButton.disabled ? '' : 'Publish this draft';
    resetButton.hidden = !draft?.published_changed;
    resetButton.disabled = saveInFlight || definitionLocked;
    addRowButton.disabled = !draft || saveInFlight || definitionLocked;
    libraryButtons.forEach((button) => { button.disabled = !draft || definitionLocked || (button.dataset.unsafe === '1' && !CAN_UNSAFE); });
  };
  const setDefinitionLocked = (locked) => {
    definitionLocked = locked;
    app.classList.toggle('is-definition-locked', locked);
    questionLibrary.inert = locked;
    questionProperties.inert = locked;
    if (preview) preview.inert = locked;
    updateActions();
  };
  const request = async (action, values = {}) => {
    const body = new URLSearchParams({ fb_action: action, form_id: String(FORM_ID), csrf_token: CSRF, ...values });
    const response = await fetch(ENDPOINT, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
    const payload = await response.json().catch(() => ({ ok: false, error: 'Invalid server response' }));
    if (!response.ok || !payload.ok) {
      const error = new Error(payload.error || 'Draft request failed');
      error.status = response.status;
      error.conflict = response.status === 409 || payload.conflict === true;
      error.canonicalConflict = payload.canonical_conflict === true;
      throw error;
    }
    return payload.draft;
  };
  const showDraftState = () => {
    if (draft.published_changed) setStatus('Draft ready - Classic changed since draft start', 'conflict');
    else if (draft.unsafe_content_protected) setStatus(`Draft ready - revision ${draft.revision} - protected code preserved`);
    else if (!draft.has_unpublished_changes) setStatus(`Published version - revision ${draft.revision}`);
    else setStatus(`Draft ready - revision ${draft.revision}`);
    updateActions();
  };
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]);
  const syncLayoutAccent = (definition) => {
    const accentKey = String(definition?.form?.settings?.accent || 'green');
    layoutCurrent.style.setProperty('--fbv-form-accent', FORM_ACCENTS[accentKey] || FORM_ACCENTS.green || '#2b7a4a');
  };
  const syncHeader = (definition) => {
    const form = definition?.form;
    if (!form) return;
    formTitle.textContent = form.title;
    formSlug.textContent = `${form.slug} · Visual Builder`;
    formStatus.textContent = form.status;
    formStatus.className = `fba-badge ${{ active: 'active', draft: 'draft', archived: 'arch' }[form.status] || 'draft'}`;
    syncLayoutAccent(definition);
  };
  const currentFields = () => workingDefinition?.form?.fields || [];
  const currentField = () => currentFields().find((field) => field.key === selectedKey) || null;
  const isUploadField = (field) => field && ['file', 'image'].includes(field.type);
  const isProtectedContentField = (field) => field && ['richtext', 'raw_html'].includes(field.type);
  const mutableRecord = (value) => value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  const normalizePublicMedia = (detail) => {
    if (!detail) return null;
    const source = detail?.media && typeof detail.media === 'object' ? detail.media : detail;
    const media = typeof window.normalizeMedia === 'function' ? window.normalizeMedia(detail) : detail;
    if (!media?.url) return null;
    const normalized = {
      id: Number(media.id) > 0 ? Number(media.id) : null,
      url: String(media.url),
      title: String(media.title || ''),
      alt: String(media.alt || media.title || ''),
      caption: String(media.caption || '')
    };
    const accessValues = ['visibility', 'storage_disk', 'access_scope'].map((key) => String(source?.[key] || 'public').toLowerCase());
    if (accessValues.some((value) => value !== 'public') || normalized.url.startsWith('/private/') || normalized.url.length > 2000 || (!normalized.url.startsWith('/') && !/^https?:\/\//i.test(normalized.url)) || [...normalized.title].length > 2000 || [...normalized.alt].length > 2000 || [...normalized.caption].length > 2000) {
      throw new Error('Selected image must be publicly accessible');
    }
    return normalized;
  };
  const pickPublicMedia = (context = {}) => {
    if (typeof window.openMediaSelector !== 'function') return Promise.reject(new Error('Gallery selector is unavailable'));
    return window.openMediaSelector({
      url: `${ADMIN_BASE}/admin/modal_img/index.php?embedded=1&visibility=public`,
      maxWidth: '980px',
      context: { surface: 'plugin.form-builder.visual', consumer: 'plugin.form-builder', resource_id: String(FORM_ID), selection_mode: 'immediate', ...context }
    }).then(normalizePublicMedia);
  };
  const ordered = (fields) => [...fields].sort((a, b) => (Number(a.order) || 0) - (Number(b.order) || 0) || a.key.localeCompare(b.key));
  const layoutRows = () => ordered(currentFields().filter((field) => field.type === 'row'));
  const layoutColumns = () => layoutRows().flatMap((row, rowIndex) => ordered(currentFields().filter((field) => field.type === 'col' && field.parent === row.key)).map((column, columnIndex) => ({ row, column, rowIndex, columnIndex })));
  const normalizeOrders = (fields, parent) => {
    ordered(fields.filter((field) => field.parent === parent)).forEach((field, index) => { field.order = (index + 1) * 10; });
  };
  const updateFieldCount = () => {
    const count = currentFields().filter((field) => !['row', 'col'].includes(field.type)).length;
    fieldCount.textContent = `${count} field${count === 1 ? '' : 's'}`;
  };
  const markSelection = () => {
    if (!preview) return;
    preview.querySelectorAll('.fb-field').forEach((element) => element.classList.toggle('is-selected', element.dataset.key === selectedKey));
  };
  const renderFieldPicker = () => {
    const fields = currentFields().filter((field) => !['row', 'col'].includes(field.type));
    fieldPicker.innerHTML = `<option value="">Select a field (${fields.length})</option>` + fields.map((field) => `<option value="${escapeHtml(field.key)}"${field.key === selectedKey ? ' selected' : ''}>${escapeHtml(field.label || TYPES[field.type]?.label || field.key)}${field.hidden ? ' (hidden)' : ''}</option>`).join('');
    fieldPicker.disabled = !draft || fields.length === 0;
  };
  const renderLayout = () => {
    if (targetColumnKey) {
      const targetExists = currentFields().some((field) => field.type === 'col' && field.key === targetColumnKey);
      const targetIsEmpty = !currentFields().some((field) => field.parent === targetColumnKey && !['row', 'col'].includes(field.type));
      if (!targetExists || !targetIsEmpty) clearFieldGuidance();
    }
    const rows = layoutRows();
    layoutCount.textContent = `${rows.length} row${rows.length === 1 ? '' : 's'}`;
    layoutList.innerHTML = rows.map((row, index) => {
      const rowColumns = ordered(currentFields().filter((field) => field.type === 'col' && field.parent === row.key));
      const columns = rowColumns.length;
      const columnKeys = new Set(rowColumns.map((column) => column.key));
      const fields = currentFields().filter((field) => columnKeys.has(field.parent) && !['row', 'col'].includes(field.type)).length;
      const columnChoices = [1,2,3,4];
      if (!columnChoices.includes(columns)) columnChoices.unshift(columns);
      const mapSlots = Array.from({ length: Math.min(columns, 4) }, () => '<i></i>').join('') + (columns > 4 ? `<em>+${columns - 4}</em>` : '');
      return `<article class="fbv-layout-row" data-layout-row="${escapeHtml(row.key)}"><div class="fbv-layout-row-head"><strong>Row ${index + 1}</strong><span>${fields} field${fields === 1 ? '' : 's'}</span></div><div class="fbv-layout-map" aria-hidden="true">${mapSlots}</div><div class="fbv-layout-row-controls"><label><span>Columns</span><select data-row-columns aria-label="Columns in row ${index + 1}">${columnChoices.map((count) => `<option value="${count}"${count === columns ? ' selected' : ''}>${count} column${count === 1 ? '' : 's'}${count < 1 || count > 4 ? ' (advanced)' : ''}</option>`).join('')}</select></label><div class="fbv-layout-actions"><button type="button" data-row-move="up" title="Move row up" aria-label="Move row ${index + 1} up"${index === 0 ? ' disabled' : ''}>&uarr;</button><button type="button" data-row-move="down" title="Move row down" aria-label="Move row ${index + 1} down"${index === rows.length - 1 ? ' disabled' : ''}>&darr;</button><button class="danger" type="button" data-row-delete title="${fields ? 'Move or delete fields before removing this row' : 'Delete empty row'}" aria-label="Delete row ${index + 1}"${fields ? ' disabled' : ''}>&times;</button></div></div></article>`;
    }).join('') || '<div class="fbv-layout-empty">No rows yet. Choose a column layout above to create the form structure.</div>';
    if (linkedLayoutRowKey) {
      const linkedRow = Array.from(layoutList.querySelectorAll('[data-layout-row]')).find((row) => row.dataset.layoutRow === linkedLayoutRowKey);
      if (linkedRow) linkedRow.classList.add('is-preview-linked');
      else linkedLayoutRowKey = null;
    }
  };
  const markPreviewTarget = () => {
    if (!preview) return;
    preview.querySelectorAll('.is-add-target').forEach((column) => column.classList.remove('is-add-target'));
    if (targetColumnKey) Array.from(preview.querySelectorAll('.fb-col[data-fbv-col]')).find((column) => column.dataset.fbvCol === targetColumnKey)?.classList.add('is-add-target');
  };
  const clearFieldGuidance = () => {
    targetColumnKey = null;
    fieldGuidance.hidden = true;
    fieldGuidanceTitle.textContent = 'Empty column selected';
    fieldGuidanceText.textContent = '';
    questionSection.classList.remove('is-preview-target');
    window.clearTimeout(fieldGuidanceTimer);
    markPreviewTarget();
  };
  const highlightLayoutRow = (rowKey, message) => {
    setPanelHidden('left', false);
    setLayoutCollapsed(false);
    linkedLayoutRowKey = rowKey;
    layoutList.querySelectorAll('.is-preview-linked').forEach((row) => row.classList.remove('is-preview-linked'));
    const rowCard = Array.from(layoutList.querySelectorAll('[data-layout-row]')).find((row) => row.dataset.layoutRow === rowKey);
    if (!rowCard) {
      linkedLayoutRowKey = null;
      structureStatus.textContent = 'That row is no longer available in the current layout.';
      return;
    }
    rowCard.classList.add('is-preview-linked');
    rowCard.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
    rowCard.querySelector('[data-row-columns]')?.focus({ preventScroll: true });
    structureStatus.textContent = message;
    window.clearTimeout(layoutHighlightTimer);
    layoutHighlightTimer = window.setTimeout(() => {
      linkedLayoutRowKey = null;
      layoutList.querySelectorAll('.is-preview-linked').forEach((row) => row.classList.remove('is-preview-linked'));
    }, 2400);
  };
  const guideEmptyColumn = (rowKey, columnKey) => {
    const position = layoutColumns().find(({ row, column }) => row.key === rowKey && column.key === columnKey);
    const targetIsEmpty = !currentFields().some((field) => field.parent === columnKey && !['row', 'col'].includes(field.type));
    if (!position || !targetIsEmpty) {
      clearFieldGuidance();
      structureStatus.textContent = position ? 'That column is no longer empty.' : 'That column is no longer available in the current layout.';
      return;
    }
    const label = `Column ${position.columnIndex + 1} in Row ${position.rowIndex + 1}`;
    targetColumnKey = columnKey;
    linkedLayoutRowKey = null;
    window.clearTimeout(layoutHighlightTimer);
    layoutList.querySelectorAll('.is-preview-linked').forEach((row) => row.classList.remove('is-preview-linked'));
    setPanelHidden('left', false);
    setLibraryGroupExpanded('input', true);
    fieldGuidanceText.textContent = `${label} is ready. Choose a field below to add it here.`;
    fieldGuidance.hidden = false;
    questionSection.classList.add('is-preview-target');
    questionSection.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    questionHeading.focus({ preventScroll: true });
    markPreviewTarget();
    window.clearTimeout(fieldGuidanceTimer);
    fieldGuidanceTimer = window.setTimeout(() => questionSection.classList.remove('is-preview-target'), 3600);
  };
  const applyPreview = (html) => {
    updateFieldCount();
    renderFieldPicker();
    renderLayout();
    if (!html || !preview) return;
    const template = preview.ownerDocument.createElement('template');
    template.innerHTML = html.trim();
    const fresh = template.content.querySelector('.fb-wrap');
    const existing = preview.querySelector('.fb-wrap');
    if (fresh && existing) existing.replaceWith(fresh);
    preview.querySelectorAll('input, textarea, select, button').forEach((control) => { control.tabIndex = control.matches('[data-fbv-structure-action]') ? 0 : -1; });
    preview.querySelectorAll('.fb-field[data-key]').forEach((field) => field.draggable = true);
    markSelection();
    markPreviewTarget();
  };
  const renderOptionEditor = (field) => {
    const options = Array.isArray(field.options) ? field.options : [];
    const currency = workingDefinition?.form?.settings?.currency_code || 'USD';
    const optionCards = options.map((option, index) => {
      const priced = Number(option.price || 0) !== 0;
      const limited = field.type === 'select' && Object.prototype.hasOwnProperty.call(option, 'capacity');
      return `<article class="fbv-option-card" data-option-index="${index}">
        <header class="fbv-option-card-head">
          <span class="fbv-option-number">${index + 1}</span>
          <strong>${escapeHtml(option.label || `Option ${index + 1}`)}</strong>
          <button class="fbv-option-icon" type="button" data-option-move="up" title="Move option up" aria-label="Move option ${index + 1} up"${index === 0 ? ' disabled' : ''}>&uarr;</button>
          <button class="fbv-option-icon" type="button" data-option-move="down" title="Move option down" aria-label="Move option ${index + 1} down"${index === options.length - 1 ? ' disabled' : ''}>&darr;</button>
          <button class="fbv-option-icon danger" type="button" data-option-remove title="Remove option" aria-label="Remove option ${index + 1}"${options.length <= 1 ? ' disabled' : ''}>&times;</button>
        </header>
        <div class="fbv-option-body">
          <label class="fbv-option-control">
            <span>Text shown to visitors</span>
            <textarea data-option-prop="label" rows="2" placeholder='Example: Kebidanan : "Midwife Challenge"'>${escapeHtml(option.label || '')}</textarea>
          </label>
          <div class="fbv-option-features">
            <div class="fbv-option-feature">
              <label><input type="checkbox" data-option-toggle="price"${priced ? ' checked' : ''}><span>Add a price<small>Include an amount for this choice.</small></span></label>
              <input type="number" data-option-prop="price" step="1" value="${Number(option.price || 0)}" aria-label="Price in ${escapeHtml(currency)}"${priced ? '' : ' hidden'}>
            </div>
            ${field.type === 'select' ? `<div class="fbv-option-feature">
              <label><input type="checkbox" data-option-toggle="capacity"${limited ? ' checked' : ''}><span>Limit registrations<small>Disable this choice when all slots are taken.</small></span></label>
              <input type="number" data-option-prop="capacity" min="1" max="<?= FB_OPTION_CAPACITY_MAX ?>" step="1" value="${limited ? Number(option.capacity) : 1}" aria-label="Available slots"${limited ? '' : ' hidden'}>
            </div>` : ''}
          </div>
          <details class="fbv-option-advanced">
            <summary>Advanced</summary>
            <label><span>Stored value</span><input type="text" data-option-prop="value" value="${escapeHtml(option.value || '')}" placeholder="Unique internal value"></label>
          </details>
        </div>
      </article>`;
    }).join('');
    return `<section class="fbv-options">
      <div class="fbv-options-head">
        <div><strong>Answer choices</strong><span>Write visitor-facing text normally. Colons, quotes, and pipe characters are supported.</span></div>
        <button class="fba-btn sm" type="button" data-option-add>Add option</button>
      </div>
      <div class="fbv-option-list">${optionCards || '<div class="fbv-option-empty">Add the first answer choice.</div>'}</div>
    </section>`;
  };
  const renderValidationControls = (field) => {
    const validation = mutableRecord(field.validation);
    if (field.type === 'number') {
      return `<section class="fbv-property-group"><strong>Validation</strong><div class="fba-row2"><div class="fba-field"><label>Minimum</label><input data-validation-prop="min" type="number" step="any" value="${escapeHtml(validation.min ?? '')}"></div><div class="fba-field"><label>Maximum</label><input data-validation-prop="max" type="number" step="any" value="${escapeHtml(validation.max ?? '')}"></div></div></section>`;
    }
    if (field.type === 'date') {
      return `<section class="fbv-property-group"><strong>Date validation</strong><div class="fba-row2"><div class="fba-field"><label>Earliest date</label><input data-validation-prop="min" type="date" value="${escapeHtml(validation.min ?? '')}"></div><div class="fba-field"><label>Latest date</label><input data-validation-prop="max" type="date" value="${escapeHtml(validation.max ?? '')}"></div></div></section>`;
    }
    if (['text', 'email', 'tel', 'intl_phone', 'textarea'].includes(field.type)) {
      return `<section class="fbv-property-group"><strong>Text validation</strong><div class="fba-row2"><div class="fba-field"><label>Maximum length</label><input data-validation-prop="maxlength" type="number" min="1" max="65536" step="1" value="${escapeHtml(validation.maxlength ?? '')}"></div><div class="fba-field"><label>Pattern (regex)</label><input data-validation-prop="pattern" type="text" maxlength="500" value="${escapeHtml(validation.pattern ?? '')}"></div></div></section>`;
    }
    return '';
  };
  const renderCountryFieldControl = (field) => {
    if (field.type !== 'intl_phone') return '';
    const countries = currentFields().filter((candidate) => candidate.type === 'country' && !candidate.hidden);
    return `<div class="fba-field"><label>Country field</label><select data-country-field required>${countries.map((country) => `<option value="${escapeHtml(country.key)}"${field.settings?.country_field === country.key ? ' selected' : ''}>${escapeHtml(country.label || country.key)}</option>`).join('')}</select>${countries.length ? '' : '<div class="fba-hint">Add a visible Country field before configuring this phone field.</div>'}</div>`;
  };
  const renderImageBlockEditor = (field) => {
    if (field.type !== 'image_block') return '';
    const settings = mutableRecord(field.settings);
    const url = String(settings.url || '');
    return `<section class="fbv-property-group"><strong>Image</strong><div class="fbv-image-preview">${url ? `<img src="${escapeHtml(url)}" alt="">` : 'No image selected'}</div><div class="fbv-image-actions"><button class="fba-btn sm" type="button" data-image-pick>Choose from Gallery</button></div><div class="fba-field"><label>Image URL</label><input data-image-prop="url" type="text" maxlength="2000" value="${escapeHtml(url)}" placeholder="/static/img/example.jpg"></div><div class="fba-field"><label>Alternative text</label><input data-image-prop="alt" type="text" maxlength="2000" value="${escapeHtml(settings.alt || '')}"></div><div class="fba-field"><label>Caption</label><input data-image-prop="caption" type="text" maxlength="2000" value="${escapeHtml(settings.caption || '')}"></div><div class="fba-field"><label>Width</label><select data-image-prop="width"><option value=""${settings.width ? '' : ' selected'}>Full width</option>${['75','50','25'].map((width) => `<option value="${width}"${settings.width === width ? ' selected' : ''}>${width}%</option>`).join('')}</select></div></section>`;
  };
  const renderContentEditorControl = (field) => {
    if (!isProtectedContentField(field)) return '';
    if (!CAN_UNSAFE) return '<div class="fbv-protected-content">Unsafe-code permission is required to edit this protected content.</div>';
    if (!contentEditorRoot) return '<div class="fbv-upload-editor-error" role="alert">The content editor is unavailable. Protected content cannot be edited safely.</div>';
    return `<section class="fbv-property-group"><strong>${field.type === 'raw_html' ? 'Raw HTML' : 'Rich text'}</strong><div data-content-editor-host></div>${field.type === 'raw_html' ? '<div class="fba-hint">Raw HTML remains in CodeMirror and is not executed in the draft preview.</div>' : '<div class="fba-hint">Complex markup automatically remains in CodeMirror to prevent lossy conversion.</div>'}</section>`;
  };
  const renderAlignmentControls = (field) => `<section class="fbv-property-group"><strong>Alignment</strong><div class="fba-row2"><div class="fba-field"><label>Horizontal</label><select data-setting-prop="align"><option value=""${field.settings?.align ? '' : ' selected'}>Left (default)</option><option value="center"${field.settings?.align === 'center' ? ' selected' : ''}>Center</option><option value="right"${field.settings?.align === 'right' ? ' selected' : ''}>Right</option></select></div><div class="fba-field"><label>Vertical in column</label><select data-setting-prop="valign"><option value=""${field.settings?.valign ? '' : ' selected'}>Top (default)</option><option value="middle"${field.settings?.valign === 'middle' ? ' selected' : ''}>Middle</option><option value="bottom"${field.settings?.valign === 'bottom' ? ' selected' : ''}>Bottom</option></select></div></div></section>`;
  const storeUploadDescription = (fieldKey, content) => {
    const field = currentFields().find((candidate) => candidate.key === fieldKey);
    if (!isUploadField(field) || String(field.settings?.upload_description_html || '') === content) return;
    mutateDefinition((definition) => {
      const target = definition.form.fields.find((candidate) => candidate.key === fieldKey);
      if (!isUploadField(target)) return;
      target.settings = mutableRecord(target.settings);
      target.settings.upload_description_html = content;
    });
  };
  const storeContentHtml = (fieldKey, content) => {
    const field = currentFields().find((candidate) => candidate.key === fieldKey);
    if (!isProtectedContentField(field) || String(field.settings?.html || '') === content) return;
    mutateDefinition((definition) => {
      const target = definition.form.fields.find((candidate) => candidate.key === fieldKey);
      if (!isProtectedContentField(target)) return;
      target.settings = mutableRecord(target.settings);
      if (content === '') delete target.settings.html;
      else {
        target.settings.html = content;
        definition.form.settings.unsafe_code_enabled = true;
      }
    });
  };
  const syncContentEditor = () => {
    if (!contentEditor || !contentEditorFieldKey || contentEditorApplying) return true;
    let content;
    try { content = contentEditor.sync(); }
    catch (error) {
      setStatus(error?.message || 'Content editor sync failed', 'error');
      return false;
    }
    if ([...content].length > 100000) {
      setStatus('Protected content is too long', 'error');
      return false;
    }
    storeContentHtml(contentEditorFieldKey, content);
    return true;
  };
  const detachContentEditor = (sync = true) => {
    if (sync) syncContentEditor();
    contentEditorSelectionToken++;
    contentEditorFieldKey = null;
    if (contentEditorShell && contentEditorParking && contentEditorShell.parentElement !== contentEditorParking) contentEditorParking.appendChild(contentEditorShell);
    contentEditorParking?.setAttribute('aria-hidden', 'true');
  };
  let contentEditorTransition = Promise.resolve();
  const setContentEditorContent = (field) => {
    if (!contentEditor) return;
    const token = ++contentEditorSelectionToken;
    const content = String(field.settings?.html || '');
    contentEditorTransition = contentEditorTransition.then(async () => {
      if (token !== contentEditorSelectionToken || contentEditorFieldKey !== field.key) return;
      contentEditorApplying = true;
      try {
        await contentEditor.setMode('codemirror');
        if (token !== contentEditorSelectionToken || contentEditorFieldKey !== field.key) return;
        contentEditor.setContent(content, { source: 'field-selection' });
        const modes = contentEditorRoot?.querySelector('[data-editor-modes]');
        const quillMode = contentEditorRoot?.querySelector('[data-editor-mode="quill"]');
        if (modes) modes.hidden = field.type === 'raw_html';
        if (quillMode) quillMode.disabled = field.type === 'raw_html';
        if (field.type === 'richtext' && !contentEditor.isComplex()) await contentEditor.setMode('quill');
      } finally {
        contentEditorApplying = false;
      }
    }).catch((error) => {
      if (token === contentEditorSelectionToken) setStatus(error?.message || 'The content editor could not load this field', 'error');
    });
  };
  const attachContentEditor = (field) => {
    const host = inspector.querySelector('[data-content-editor-host]');
    if (!host || !contentEditorShell || !isProtectedContentField(field) || !CAN_UNSAFE) return;
    host.appendChild(contentEditorShell);
    contentEditorParking?.setAttribute('aria-hidden', 'false');
    contentEditorFieldKey = field.key;
    setContentEditorContent(field);
  };
  const syncUploadDescription = () => {
    if (!uploadDescriptionEditor || !uploadEditorFieldKey || uploadEditorApplying) return true;
    let content;
    try { content = uploadDescriptionEditor.sync(); }
    catch (error) {
      setStatus(error?.message || <?= json_encode(__('Description editor sync failed.')) ?>, 'error');
      return false;
    }
    if ([...content].length > UPLOAD_DESCRIPTION_MAX_LENGTH) {
      setStatus(<?= json_encode(__('Upload description is too long.')) ?>, 'error');
      return false;
    }
    storeUploadDescription(uploadEditorFieldKey, content);
    return true;
  };
  const detachUploadDescriptionEditor = (sync = true) => {
    if (sync) syncUploadDescription();
    uploadEditorSelectionToken++;
    uploadEditorFieldKey = null;
    if (uploadEditorShell && uploadEditorParking && uploadEditorShell.parentElement !== uploadEditorParking) {
      uploadEditorParking.appendChild(uploadEditorShell);
    }
    uploadEditorParking?.setAttribute('aria-hidden', 'true');
  };
  const showUploadEditorError = (message) => {
    let error = uploadEditorShell?.querySelector('[data-upload-editor-runtime-error]');
    if (!error && uploadEditorShell) {
      error = document.createElement('div');
      error.className = 'fbv-upload-editor-error';
      error.dataset.uploadEditorRuntimeError = '1';
      error.setAttribute('role', 'alert');
      uploadEditorShell.prepend(error);
    }
    if (error) error.textContent = message;
    setStatus(message, 'error');
  };
  const setUploadEditorContent = (field) => {
    if (!uploadDescriptionEditor) return;
    const token = ++uploadEditorSelectionToken;
    const content = String(field.settings?.upload_description_html || '');
    const apply = () => {
      if (token !== uploadEditorSelectionToken || selectedKey !== field.key || uploadEditorFieldKey !== field.key) return;
      uploadEditorApplying = true;
      try {
        if (uploadDescriptionEditor.getContent() !== content) uploadDescriptionEditor.setContent(content, { source: 'field-selection' });
      } finally { uploadEditorApplying = false; }
    };
    try { apply(); }
    catch (error) {
      if (error?.code !== 'EDITOR_LOSSY_MODE_CHANGE') {
        showUploadEditorError(error?.message || <?= json_encode(__('The description editor could not load this field.')) ?>);
        return;
      }
      uploadDescriptionEditor.setMode('codemirror').then(apply).catch((modeError) => {
        if (token === uploadEditorSelectionToken) showUploadEditorError(modeError?.message || <?= json_encode(__('The description editor could not load this field.')) ?>);
      });
    }
  };
  const attachUploadDescriptionEditor = (field) => {
    const host = inspector.querySelector('[data-upload-editor-host]');
    if (!host || !uploadEditorShell || !isUploadField(field)) return;
    host.appendChild(uploadEditorShell);
    uploadEditorParking?.setAttribute('aria-hidden', 'false');
    uploadEditorFieldKey = field.key;
    setUploadEditorContent(field);
  };
  const renderInspector = () => {
    detachUploadDescriptionEditor();
    detachContentEditor();
    const field = currentField();
    if (!field) {
      inspector.innerHTML = '<div class="fbv-inspector-empty">Select a field on the canvas, or add one from the library.</div>';
      return;
    }
    const meta = TYPES[field.type] || { label: field.type };
    const isInput = meta.input === true;
    const isChoice = meta.options === true;
    const labelControl = field.type === 'divider' ? '' : `<div class="fba-field"><label>${field.type === 'paragraph' ? 'Text' : 'Label'}</label>${field.type === 'paragraph' ? `<textarea data-field-prop="label" rows="4" maxlength="1000">${escapeHtml(field.label)}</textarea>` : `<input data-field-prop="label" type="text" maxlength="1000" value="${escapeHtml(field.label)}">`}</div>`;
    const uploadControl = isUploadField(field) ? (() => {
      const maxFiles = Number.isSafeInteger(field.validation?.max_files) ? field.validation.max_files : 1;
      const maxMb = Math.max(1, Math.min(25, Math.ceil((Number(field.validation?.max_bytes) || 5 * UPLOAD_MIB) / UPLOAD_MIB)));
      const extensions = Array.isArray(field.validation?.exts) && field.validation.exts.length ? field.validation.exts : (field.type === 'image' ? ['jpg','jpeg','png','webp'] : ['jpg','jpeg','png','webp','pdf']);
      const previewMode = ['none','icon','real'].includes(field.settings?.preview_mode) ? field.settings.preview_mode : (field.type === 'image' ? 'real' : 'icon');
      return `<div data-upload-editor-host></div><div class="fba-row2"><div class="fba-field"><label>${escapeHtml(UPLOAD_TEXT.maxFiles)}</label><input name="v_max_files" data-upload-prop="max_files" type="number" min="1" max="${UPLOAD_MAX_FILES}" step="1" value="${maxFiles}"></div><div class="fba-field"><label>${escapeHtml(UPLOAD_TEXT.maxSize)}</label><input name="v_maxmb" data-upload-prop="max_mb" type="number" min="1" max="25" step="1" value="${maxMb}"></div></div><div class="fba-field"><label>${escapeHtml(UPLOAD_TEXT.extensions)}</label><input name="v_exts" data-upload-prop="exts" type="text" value="${escapeHtml(extensions.join(', '))}"></div><div class="fba-field"><label>${escapeHtml(UPLOAD_TEXT.previewMode)}</label><select name="s_preview_mode" data-upload-prop="preview_mode"><option value="none"${previewMode === 'none' ? ' selected' : ''}>${escapeHtml(UPLOAD_TEXT.none)}</option><option value="icon"${previewMode === 'icon' ? ' selected' : ''}>${escapeHtml(UPLOAD_TEXT.icon)}</option><option value="real"${previewMode === 'real' ? ' selected' : ''}>${escapeHtml(UPLOAD_TEXT.real)}</option></select></div>`;
    })() : '';
    const headingControl = field.type === 'heading' ? `<div class="fba-field"><label>Heading level</label><select data-setting-prop="level">${['h1','h2','h3','h4','h5','h6'].map((level) => `<option value="${level}"${(field.settings?.level || 'h2') === level ? ' selected' : ''}>${level.toUpperCase()}</option>`).join('')}</select></div>` : '';
    const inputControls = isInput ? `<div class="fba-field"><label>Field key</label><input class="fbv-inspector-key" data-field-key type="text" maxlength="80" pattern="[a-z0-9][a-z0-9_]{0,79}" value="${escapeHtml(field.key)}" spellcheck="false" autocomplete="off"><div class="fba-hint">Changing a key updates form references. Keys used in submission history are locked.</div></div><div class="fba-field"><label>Placeholder</label><input data-field-prop="placeholder" type="text" maxlength="1000" value="${escapeHtml(field.placeholder || '')}"></div><div class="fba-field"><label>Help text</label><input data-field-prop="help" type="text" maxlength="2000" value="${escapeHtml(field.help || '')}"></div>${renderCountryFieldControl(field)}${isChoice ? renderOptionEditor(field) : ''}${renderValidationControls(field)}<div class="fba-checks"><label class="fba-check"><input data-field-prop="required" type="checkbox"${field.required ? ' checked' : ''}> Required</label><label class="fba-check"><input data-field-prop="hidden" type="checkbox"${field.hidden ? ' checked' : ''}> Hidden</label></div>` : '';
    const siblings = ordered(currentFields().filter((candidate) => candidate.parent === field.parent && !['row', 'col'].includes(candidate.type)));
    const siblingIndex = siblings.findIndex((candidate) => candidate.key === field.key);
    const positionControls = `<div class="fba-field"><label>Column</label><select data-field-parent>${layoutColumns().map(({ row, column, rowIndex, columnIndex }) => `<option value="${escapeHtml(column.key)}"${column.key === field.parent ? ' selected' : ''}>Row ${rowIndex + 1}, column ${columnIndex + 1}</option>`).join('')}</select></div><div class="fbv-position-actions"><button class="fba-btn sm" type="button" data-field-move="up"${siblingIndex <= 0 ? ' disabled' : ''}>Move up</button><button class="fba-btn sm" type="button" data-field-move="down"${siblingIndex < 0 || siblingIndex >= siblings.length - 1 ? ' disabled' : ''}>Move down</button></div>`;
    const protectedType = ['richtext', 'raw_html'].includes(field.type) && !CAN_UNSAFE;
    inspector.innerHTML = `<span class="fbv-inspector-type">${escapeHtml(meta.label)}</span>${labelControl}${uploadControl}${headingControl}${inputControls}${renderImageBlockEditor(field)}${renderContentEditorControl(field)}${renderAlignmentControls(field)}${positionControls}<div class="fbv-inspector-actions"><span class="fba-hint">Autosaved draft</span><button class="fba-btn danger sm" type="button" data-fbv-delete${protectedType ? ' disabled title="Unsafe-code permission required"' : ''}>Move to Bin</button></div>`;
    if (isUploadField(field)) attachUploadDescriptionEditor(field);
    if (isProtectedContentField(field)) attachContentEditor(field);
  };
  const mutateDefinition = (callback, refreshInspector = false) => {
    if (!draft || definitionLocked) return;
    const definition = structuredClone(workingDefinition);
    callback(definition);
    workingDefinition = definition;
    if (refreshInspector) renderInspector();
    updateFieldCount();
    renderFieldPicker();
    renderLayout();
    queueSave(definition);
  };
  const uniqueKey = (base, fields = currentFields()) => {
    const stem = String(base || 'field').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'field';
    const used = new Set(fields.map((field) => field.key));
    let key = stem.slice(0, 80);
    let suffix = 2;
    while (used.has(key)) {
      const ending = `_${suffix++}`;
      key = stem.slice(0, 80 - ending.length) + ending;
    }
    return key;
  };
  const newNode = (key, parent, type, label, order) => ({ key, parent, type, label, placeholder: '', help: '', required: false, width: 12, order, hidden: false, options: [], validation: {}, settings: {} });
  const moveField = (fieldKey, parentKey, beforeKey = null) => {
    if (!fieldKey || !parentKey || fieldKey === beforeKey) return;
    mutateDefinition((definition) => {
      const fields = definition.form.fields;
      const field = fields.find((candidate) => candidate.key === fieldKey && !['row', 'col'].includes(candidate.type));
      const parent = fields.find((candidate) => candidate.key === parentKey && candidate.type === 'col');
      if (!field || !parent) return;
      const sourceParent = field.parent;
      const destination = ordered(fields.filter((candidate) => candidate.parent === parentKey && !['row', 'col'].includes(candidate.type) && candidate.key !== fieldKey));
      const beforeIndex = beforeKey ? destination.findIndex((candidate) => candidate.key === beforeKey) : -1;
      destination.splice(beforeIndex >= 0 ? beforeIndex : destination.length, 0, field);
      field.parent = parentKey;
      destination.forEach((candidate, index) => { candidate.order = (index + 1) * 10; });
      if (sourceParent !== parentKey) normalizeOrders(fields, sourceParent);
      selectedKey = fieldKey;
    }, true);
  };
  const reorderField = (fieldKey, delta) => {
    mutateDefinition((definition) => {
      const fields = definition.form.fields;
      const field = fields.find((candidate) => candidate.key === fieldKey);
      if (!field) return;
      const siblings = ordered(fields.filter((candidate) => candidate.parent === field.parent && !['row', 'col'].includes(candidate.type)));
      const index = siblings.findIndex((candidate) => candidate.key === fieldKey);
      const target = index + delta;
      if (index < 0 || target < 0 || target >= siblings.length) return;
      [siblings[index], siblings[target]] = [siblings[target], siblings[index]];
      siblings.forEach((candidate, position) => { candidate.order = (position + 1) * 10; });
    }, true);
  };
  const addRow = (columnCount) => {
    columnCount = Math.max(1, Math.min(4, Number(columnCount) || 1));
    if (currentFields().length + columnCount + 1 > 300) {
      setStatus('This form has reached the 300-field limit', 'error');
      return;
    }
    mutateDefinition((definition) => {
      const fields = definition.form.fields;
      const rowOrder = Math.max(0, ...fields.filter((field) => field.type === 'row').map((field) => Number(field.order) || 0)) + 10;
      const rowKey = uniqueKey('row_visual', fields);
      fields.push(newNode(rowKey, null, 'row', '', rowOrder));
      for (let index = 0; index < columnCount; index++) {
        const columnKey = uniqueKey('col_visual', fields);
        fields.push(newNode(columnKey, rowKey, 'col', '', (index + 1) * 10));
      }
    }, true);
  };
  const setRowColumns = async (rowKey, columnCount) => {
    columnCount = Math.max(1, Math.min(4, Number(columnCount) || 1));
    const existingCount = currentFields().filter((field) => field.type === 'col' && field.parent === rowKey).length;
    if ((existingCount < 1 || existingCount > 4) && columnCount !== existingCount) {
      const confirmed = await window.FormBuilderConfirm({
        variant: 'warning',
        badgeText: 'Visual Builder',
        title: 'Convert advanced row',
        message: `Convert this ${existingCount}-column advanced row to ${columnCount} columns?`,
        confirmText: 'Convert row',
        cancelText: 'Cancel',
        focus: 'cancel'
      });
      if (!confirmed) {
        renderLayout();
        return;
      }
      const latestCount = currentFields().filter((field) => field.type === 'col' && field.parent === rowKey).length;
      if (latestCount !== existingCount) {
        renderLayout();
        return;
      }
    }
    if (columnCount > existingCount && currentFields().length + columnCount - existingCount > 300) {
      setStatus('This form has reached the 300-field limit', 'error');
      renderLayout();
      return;
    }
    mutateDefinition((definition) => {
      const fields = definition.form.fields;
      const columns = ordered(fields.filter((field) => field.type === 'col' && field.parent === rowKey));
      if (columnCount > columns.length) {
        for (let index = columns.length; index < columnCount; index++) {
          const key = uniqueKey('col_visual', fields);
          const column = newNode(key, rowKey, 'col', '', (index + 1) * 10);
          fields.push(column);
          columns.push(column);
        }
      } else if (columnCount < columns.length) {
        const kept = columns.slice(0, columnCount);
        const removed = columns.slice(columnCount);
        const destination = kept.at(-1);
        let nextOrder = Math.max(0, ...fields.filter((field) => field.parent === destination.key).map((field) => Number(field.order) || 0));
        removed.forEach((column) => {
          ordered(fields.filter((field) => field.parent === column.key)).forEach((field) => { field.parent = destination.key; field.order = nextOrder += 10; });
        });
        const removedKeys = new Set(removed.map((column) => column.key));
        definition.form.fields = fields.filter((field) => !removedKeys.has(field.key));
      }
      normalizeOrders(definition.form.fields, rowKey);
    }, true);
  };
  const moveRow = (rowKey, delta) => {
    mutateDefinition((definition) => {
      const rows = ordered(definition.form.fields.filter((field) => field.type === 'row'));
      const index = rows.findIndex((row) => row.key === rowKey);
      const target = index + delta;
      if (index < 0 || target < 0 || target >= rows.length) return;
      [rows[index], rows[target]] = [rows[target], rows[index]];
      rows.forEach((row, position) => { row.order = (position + 1) * 10; });
    }, true);
  };
  const deleteEmptyRow = (rowKey) => {
    const columns = currentFields().filter((field) => field.type === 'col' && field.parent === rowKey);
    const columnKeys = new Set(columns.map((column) => column.key));
    if (currentFields().some((field) => columnKeys.has(field.parent) && !['row', 'col'].includes(field.type))) {
      setStatus('Move or delete the fields before removing this row', 'error');
      return;
    }
    mutateDefinition((definition) => {
      definition.form.fields = definition.form.fields.filter((field) => field.key !== rowKey && !columnKeys.has(field.key));
      normalizeOrders(definition.form.fields, null);
    }, true);
  };
  const addField = (type) => {
    const meta = TYPES[type];
    if (!meta || meta.container || definitionLocked || (['richtext', 'raw_html'].includes(type) && !CAN_UNSAFE)) return;
    const selectedTargetColumn = currentFields().find((field) => field.type === 'col' && field.key === targetColumnKey);
    const selectedTargetIsEmpty = selectedTargetColumn && !currentFields().some((field) => field.parent === selectedTargetColumn.key && !['row', 'col'].includes(field.type));
    if (targetColumnKey && (!selectedTargetColumn || !selectedTargetIsEmpty)) {
      clearFieldGuidance();
      fieldGuidanceTitle.textContent = 'Layout changed';
      fieldGuidanceText.textContent = 'That column is no longer empty or available. Select an empty column in the preview and try again.';
      fieldGuidance.hidden = false;
      questionSection.classList.add('is-preview-target');
      return;
    }
    const existingRows = ordered(currentFields().filter((field) => field.type === 'row'));
    const finalRow = existingRows.at(-1);
    const finalRowHasColumn = finalRow && currentFields().some((field) => field.type === 'col' && field.parent === finalRow.key);
    const nodesNeeded = selectedTargetColumn ? 1 : 1 + (existingRows.length ? (finalRowHasColumn ? 0 : 1) : 2);
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
      let column = targetColumnKey ? fields.find((field) => field.type === 'col' && field.key === targetColumnKey) : null;
      if (!column) {
        let rows = ordered(fields.filter((field) => field.type === 'row'));
        if (!rows.length) {
          const rowKey = uniqueKey('row_visual', fields);
          fields.push(newNode(rowKey, null, 'row', '', 10));
          rows = ordered(fields.filter((field) => field.type === 'row'));
        }
        const row = rows.at(-1);
        let columns = ordered(fields.filter((field) => field.type === 'col' && field.parent === row.key));
        if (!columns.length) {
          const columnKey = uniqueKey('col_visual', fields);
          fields.push(newNode(columnKey, row.key, 'col', '', 10));
          columns = ordered(fields.filter((field) => field.type === 'col' && field.parent === row.key));
        }
        column = columns.at(-1);
      }
      const siblings = fields.filter((field) => field.parent === column.key);
      const key = uniqueKey(meta.label || type, fields);
      const field = newNode(key, column.key, type, meta.label || type, Math.max(0, ...siblings.map((item) => Number(item.order) || 0)) + 10);
      if (meta.options) field.options = [{ value: 'option_1', label: 'Option 1', price: 0 }];
      if (type === 'heading') field.settings.level = 'h2';
      if (type === 'intl_phone') field.settings.country_field = country.key;
      if (type === 'file' || type === 'image') {
        field.validation = {
          max_files: 1,
          max_bytes: 5 * UPLOAD_MIB,
          exts: type === 'image' ? ['jpg', 'jpeg', 'png', 'webp'] : ['jpg', 'jpeg', 'png', 'webp', 'pdf']
        };
        field.settings.upload_description_html = '';
        field.settings.preview_mode = type === 'image' ? 'real' : 'icon';
      }
      fields.push(field);
      selectedKey = key;
    }, true);
    clearFieldGuidance();
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
    let saveFailed = false;
    setStatus('Saving draft...', 'saving');
    updateActions();
    try {
      const saved = await request('save', { revision: String(draft.revision), definition: JSON.stringify(definition) });
      draft = saved;
      if (!pendingDefinition && workingDefinition === definition && !hasPendingControlChanges) {
        applyPreview(saved.preview_html);
        hasUnsavedChanges = false;
      }
      if (hasPendingControlChanges) {
        setStatus('Unsaved inspector changes', 'saving');
        updateActions();
      } else showDraftState();
      window.dispatchEvent(new CustomEvent('fbv:draft-saved', { detail: draft }));
    } catch (error) {
      saveFailed = true;
      pendingDefinition = pendingDefinition || workingDefinition;
      hasUnsavedChanges = true;
      setStatus(error.conflict ? 'Autosave conflict - reload required' : error.message, error.conflict ? 'conflict' : 'error');
      window.dispatchEvent(new CustomEvent('fbv:draft-error', { detail: error }));
      if (!error.conflict && (!error.status || error.status >= 500)) {
        window.clearTimeout(retryTimer);
        retryTimer = window.setTimeout(() => {
          if (saveInFlight || !pendingDefinition) return;
          const retryDefinition = pendingDefinition;
          pendingDefinition = null;
          save(retryDefinition).catch(() => {});
        }, 2000);
      }
      throw error;
    } finally {
      saveInFlight = false;
      if (!saveFailed && pendingDefinition) {
        const nextDefinition = pendingDefinition;
        pendingDefinition = null;
        save(nextDefinition).catch(() => {});
      }
      updateActions();
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
    window.clearTimeout(retryTimer);
    pendingDefinition = definition;
    hasUnsavedChanges = true;
    setStatus('Unsaved changes', 'saving');
    updateActions();
    saveTimer = window.setTimeout(() => {
      const nextDefinition = pendingDefinition;
      pendingDefinition = null;
      if (nextDefinition) save(nextDefinition).catch(() => {});
    }, 700);
  };
  const waitForSaveIdle = (timeout = 10000) => new Promise((resolve) => {
    const started = Date.now();
    const check = () => {
      if (!saveInFlight && !pendingDefinition) { resolve(true); return; }
      if (Date.now() - started >= timeout) { resolve(false); return; }
      window.setTimeout(check, 100);
    };
    check();
  });

  window.fbVisualDraft = { get current() { return draft ? { ...draft, definition: workingDefinition } : null; }, save, queueSave };
  window.addEventListener('fbv:draft-change', (event) => {
    if (event.detail?.definition) queueSave(event.detail.definition);
  });
  const mountUploadDescriptionEditor = () => {
    if (!uploadEditorRoot || uploadDescriptionEditor) return;
    try {
      if (!window.JyavaniEditor || typeof window.JyavaniEditor.mount !== 'function') throw new Error(<?= json_encode(__('Core description editor is unavailable.')) ?>);
      uploadDescriptionEditor = window.JyavaniEditor.mount(uploadEditorRoot, {
        context: {
          owner: 'plugin.form-builder',
          resourceType: 'visual-upload-description',
          operation: 'edit',
          resourceId: FORM_ID,
          canUpdate: true,
          adminBasePath: window.ADMIN_PATH || ''
        },
        confirmLossy: () => window.FormBuilderConfirm({
          variant: 'warning',
          badgeText: <?= json_encode(__('Visual Builder')) ?>,
          title: <?= json_encode(__('Switch to rich text?')) ?>,
          message: <?= json_encode(__('Complex HTML will be simplified when switching to rich text. Continue?')) ?>,
          confirmText: <?= json_encode(__('Switch editor')) ?>,
          cancelText: <?= json_encode(__('Cancel')) ?>,
          focus: 'cancel'
        })
      });
      uploadDescriptionEditor.on('change', (event) => {
        if (uploadEditorApplying || !uploadEditorFieldKey || selectedKey !== uploadEditorFieldKey) return;
        const field = currentFields().find((candidate) => candidate.key === uploadEditorFieldKey);
        if (!isUploadField(field)) return;
        const content = String(event?.content ?? uploadDescriptionEditor.sync());
        if ([...content].length > UPLOAD_DESCRIPTION_MAX_LENGTH) {
          uploadEditorApplying = true;
          try { uploadDescriptionEditor.setContent(String(field.settings?.upload_description_html || ''), { source: 'length-rejected' }); }
          finally { uploadEditorApplying = false; }
          setStatus(<?= json_encode(__('Upload description is too long. The last change was rejected.')) ?>, 'error');
          return;
        }
        storeUploadDescription(uploadEditorFieldKey, content);
      });
      uploadDescriptionEditor.on('error', (event) => showUploadEditorError(event.error?.message || <?= json_encode(__('Description editor action failed.')) ?>));
    } catch (error) {
      uploadDescriptionEditor = null;
      showUploadEditorError(error?.message || <?= json_encode(__('The description editor could not be loaded.')) ?>);
    }
  };
  const mountContentEditor = () => {
    if (!contentEditorRoot || contentEditor || !CAN_UNSAFE) return;
    try {
      if (!window.JyavaniEditor || typeof window.JyavaniEditor.mount !== 'function') throw new Error('Core content editor is unavailable.');
      contentEditor = window.JyavaniEditor.mount(contentEditorRoot, {
        context: {
          owner: 'plugin.form-builder',
          resourceType: 'visual-protected-content',
          operation: 'edit',
          resourceId: FORM_ID,
          canUpdate: true,
          adminBasePath: ADMIN_BASE
        },
        codeHeight: '46vh',
        mediaContext: { surface: 'plugin.form-builder.visual-content', consumer: 'plugin.form-builder', resource_id: String(FORM_ID), selection_mode: 'immediate' },
        adapters: {
          pickMedia: (request) => pickPublicMedia({ ...request.pickerContext, field: contentEditorFieldKey || '' }),
          pickFile: () => Promise.reject(new Error('File insertion is unavailable for public form content.'))
        },
        confirmLossy: () => window.FormBuilderConfirm({
          variant: 'warning',
          badgeText: 'Visual Builder',
          title: 'Switch to rich text?',
          message: 'Complex HTML will be simplified when switching to rich text. Continue?',
          confirmText: 'Switch editor',
          cancelText: 'Cancel',
          focus: 'cancel'
        })
      });
      const storeEditorEvent = (event) => {
        if (contentEditorApplying || !contentEditorFieldKey) return;
        const field = currentFields().find((candidate) => candidate.key === contentEditorFieldKey);
        if (!isProtectedContentField(field)) return;
        const content = String(event?.content ?? contentEditor.sync());
        if ([...content].length > 100000) {
          contentEditorApplying = true;
          try { contentEditor.setContent(String(field.settings?.html || ''), { source: 'length-rejected' }); }
          finally { contentEditorApplying = false; }
          setStatus('Protected content is too long. The last change was rejected.', 'error');
          return;
        }
        storeContentHtml(contentEditorFieldKey, content);
      };
      contentEditor.on('change', storeEditorEvent);
      contentEditor.on('modechange', storeEditorEvent);
      contentEditor.on('error', (event) => setStatus(event.error?.message || 'Content editor action failed', 'error'));
    } catch (error) {
      contentEditor = null;
      setStatus(error?.message || 'The content editor could not be loaded', 'error');
    }
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      mountUploadDescriptionEditor();
      mountContentEditor();
    }, { once: true });
  } else {
    mountUploadDescriptionEditor();
    mountContentEditor();
  }
  uploadEditorRoot?.addEventListener('input', () => {
    if (uploadEditorApplying) return;
    window.queueMicrotask(() => syncUploadDescription());
  });
  uploadEditorRoot?.addEventListener('focusout', () => syncUploadDescription());
  contentEditorRoot?.addEventListener('focusout', () => syncContentEditor());
  request('load').then((loaded) => {
    draft = loaded;
    workingDefinition = loaded.definition;
    syncHeader(workingDefinition);
    applyPreview(draft.preview_html);
    renderInspector();
    renderFieldPicker();
    showDraftState();
  })
    .catch(() => setStatus('Draft unavailable', 'error'));

  libraryButtons.forEach((button) => button.addEventListener('click', () => addField(button.dataset.fbvType)));
  newRowLayoutButtons.forEach((button) => button.addEventListener('click', () => {
    const columns = Math.max(1, Math.min(4, Number(button.dataset.newRowColumns) || 1));
    newRowColumns.value = String(columns);
    newRowLayoutButtons.forEach((candidate) => {
      const active = candidate === button;
      candidate.classList.toggle('is-active', active);
      candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    addRowButton.textContent = `Add ${columns}-column row`;
  }));
  addRowButton.addEventListener('click', () => addRow(newRowColumns.value));
  layoutList.addEventListener('change', (event) => {
    if (!event.target.matches('[data-row-columns]')) return;
    const row = event.target.closest('[data-layout-row]');
    if (row) setRowColumns(row.dataset.layoutRow, event.target.value);
  });
  layoutList.addEventListener('click', (event) => {
    const row = event.target.closest('[data-layout-row]');
    if (!row) return;
    const move = event.target.closest('[data-row-move]');
    if (move && !move.disabled) moveRow(row.dataset.layoutRow, move.dataset.rowMove === 'up' ? -1 : 1);
    const remove = event.target.closest('[data-row-delete]');
    if (remove) deleteEmptyRow(row.dataset.layoutRow);
  });
  const bindPreview = () => {
    if (!preview || preview.dataset.fbvBound === '1') return;
    preview.dataset.fbvBound = '1';
    preview.addEventListener('click', (event) => {
      const field = event.target.closest('.fb-field[data-key]');
      if (field && preview.contains(field)) {
        clearFieldGuidance();
        selectedKey = field.dataset.key;
        markSelection();
        renderInspector();
        renderFieldPicker();
        return;
      }
      const column = event.target.closest('.fb-col[data-fbv-col]');
      const row = event.target.closest('.fb-row[data-fbv-row]');
      const structureAction = event.target.closest('[data-fbv-structure-action]')?.dataset.fbvStructureAction || '';
      if (!row || !preview.contains(row)) return;
      if (structureAction === 'empty-column' || (!structureAction && column?.dataset.fbvEmpty === '1')) {
        guideEmptyColumn(row.dataset.fbvRow, column.dataset.fbvCol);
        return;
      }
      clearFieldGuidance();
      const position = column ? layoutColumns().find(({ column: candidate }) => candidate.key === column.dataset.fbvCol) : null;
      highlightLayoutRow(row.dataset.fbvRow, position ? `Column ${position.columnIndex + 1} belongs to Row ${position.rowIndex + 1} - layout controls highlighted` : 'Row layout controls highlighted');
    });
    const clearDropTargets = () => preview.querySelectorAll('.is-drop-target, .is-dragging').forEach((element) => element.classList.remove('is-drop-target', 'is-dragging'));
    preview.addEventListener('dragstart', (event) => {
      const field = event.target.closest('.fb-field[data-key]');
      if (!field) return;
      draggedFieldKey = field.dataset.key;
      field.classList.add('is-dragging');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', draggedFieldKey);
    });
    preview.addEventListener('dragover', (event) => {
      if (!draggedFieldKey) return;
      const field = event.target.closest('.fb-field[data-key]');
      const column = event.target.closest('.fb-col[data-fbv-col]');
      if (!column) return;
      event.preventDefault();
      preview.querySelectorAll('.is-drop-target').forEach((element) => element.classList.remove('is-drop-target'));
      (field && field.dataset.key !== draggedFieldKey ? field : column).classList.add('is-drop-target');
      event.dataTransfer.dropEffect = 'move';
    });
    preview.addEventListener('drop', (event) => {
      if (!draggedFieldKey) return;
      const field = event.target.closest('.fb-field[data-key]');
      const column = event.target.closest('.fb-col[data-fbv-col]');
      event.preventDefault();
      if (column) {
        moveField(draggedFieldKey, column.dataset.fbvCol, field?.dataset.key || null);
        if (column.dataset.fbvCol === targetColumnKey) clearFieldGuidance();
      }
      draggedFieldKey = null;
      clearDropTargets();
    });
    preview.addEventListener('dragend', () => {
      draggedFieldKey = null;
      clearDropTargets();
    });
    preview.addEventListener('submit', (event) => event.preventDefault(), true);
  };
  previewFrame.addEventListener('load', () => {
    const frameDocument = previewFrame.contentDocument;
    preview = frameDocument?.getElementById('fbvPreview') || null;
    if (!preview) { setStatus('Public preview unavailable', 'error'); return; }
    preview.inert = definitionLocked;
    Array.from(frameDocument.body.children).forEach((element) => {
      if (element.id !== 'site-main' && element.tagName !== 'SCRIPT') element.inert = true;
    });
    bindPreview();
    if (draft?.preview_html) applyPreview(draft.preview_html);
  });
  previewFrame.src = previewFrame.dataset.src;
  fieldPicker.addEventListener('change', () => {
    selectedKey = fieldPicker.value || null;
    markSelection();
    renderInspector();
  });
  publishButton.addEventListener('click', async () => {
    if (publishButton.disabled || !draft) return;
    if (!syncUploadDescription() || !syncContentEditor()) return;
    setDefinitionLocked(true);
    if (saveInFlight || pendingDefinition) {
      setStatus('Saving description before publish...', 'saving');
      if (!await waitForSaveIdle()) {
        setDefinitionLocked(false);
        setStatus('Publish paused - resolve the draft save before retrying', 'error');
        return;
      }
    }
    saveInFlight = true;
    setStatus('Publishing draft...', 'saving');
    updateActions();
    try {
      const published = await request('publish', { revision: String(draft.revision) });
      draft = published;
      workingDefinition = published.definition;
      hasUnsavedChanges = false;
      hasPendingControlChanges = false;
      applyPreview(published.preview_html);
      renderInspector();
      syncHeader(workingDefinition);
      setStatus(`Published - revision ${published.revision}`);
    } catch (error) {
      if (error.canonicalConflict) draft.published_changed = true;
      setStatus(error.conflict ? 'Publish conflict - reload required' : error.message, error.conflict ? 'conflict' : 'error');
    } finally {
      saveInFlight = false;
      setDefinitionLocked(false);
    }
  });
  resetButton.addEventListener('click', async () => {
    if (!draft || resetButton.disabled) return;
    const confirmed = await window.FormBuilderConfirm({
      variant: 'danger',
      badgeText: 'Visual Builder',
      title: 'Discard visual draft',
      message: 'Discard the visual draft and reload the latest Classic version?',
      confirmText: 'Discard draft',
      cancelText: 'Cancel',
      focus: 'cancel'
    });
    if (!confirmed || !draft) return;
    setDefinitionLocked(true);
    if (saveInFlight || pendingDefinition) {
      setStatus('Waiting for draft save before reset...', 'saving');
      if (!await waitForSaveIdle()) {
        setDefinitionLocked(false);
        setStatus('Reset paused - resolve the draft save before retrying', 'error');
        return;
      }
    }
    if (saveInFlight) {
      setDefinitionLocked(false);
      return;
    }
    detachUploadDescriptionEditor(false);
    detachContentEditor(false);
    saveInFlight = true;
    setStatus('Reloading Classic version...', 'saving');
    updateActions();
    try {
      const reset = await request('reset', { revision: String(draft.revision) });
      draft = reset;
      workingDefinition = reset.definition;
      syncHeader(workingDefinition);
      selectedKey = null;
      pendingDefinition = null;
      hasUnsavedChanges = false;
      hasPendingControlChanges = false;
      applyPreview(reset.preview_html);
      renderInspector();
      showDraftState();
    } catch (error) {
      setStatus(error.conflict ? 'Reset conflict - reload the page' : error.message, error.conflict ? 'conflict' : 'error');
      renderInspector();
    } finally {
      saveInFlight = false;
      setDefinitionLocked(false);
    }
  });
  inspector.addEventListener('change', (event) => {
    const input = event.target.closest('[data-upload-prop]');
    if (!input) return;
    const field = currentField();
    if (!isUploadField(field)) return;
    const property = input.dataset.uploadProp;
    const restore = (message) => {
      if (property === 'max_files') input.value = String(field.validation?.max_files || 1);
      else if (property === 'max_mb') input.value = String(Math.max(1, Math.min(25, Math.ceil((Number(field.validation?.max_bytes) || 5 * UPLOAD_MIB) / UPLOAD_MIB))));
      else if (property === 'exts') input.value = (field.validation?.exts || (field.type === 'image' ? ['jpg','jpeg','png','webp'] : ['jpg','jpeg','png','webp','pdf'])).join(', ');
      else input.value = ['none','icon','real'].includes(field.settings?.preview_mode) ? field.settings.preview_mode : (field.type === 'image' ? 'real' : 'icon');
      input.setAttribute('aria-invalid', 'true');
      input.focus({ preventScroll: true });
      if (typeof input.select === 'function') input.select();
      setStatus(message, 'error');
    };
    let value;
    if (property === 'max_files' || property === 'max_mb') {
      if (!/^\d+$/.test(input.value)) { restore(property === 'max_files' ? <?= json_encode(__('Max files must be a whole number.')) ?> : <?= json_encode(__('Max size must be a whole number of MB.')) ?>); return; }
      value = Number(input.value);
      const maximum = property === 'max_files' ? UPLOAD_MAX_FILES : 25;
      if (!Number.isSafeInteger(value) || value < 1 || value > maximum) { restore(property === 'max_files' ? <?= json_encode(__('Max files is outside the allowed range.')) ?> : <?= json_encode(__('Max size must be between 1 and 25 MB.')) ?>); return; }
    } else if (property === 'exts') {
      const allowed = field.type === 'image' ? ['jpg','jpeg','png','webp'] : ['jpg','jpeg','png','webp','pdf'];
      const parts = input.value.split(',').map((extension) => extension.trim().replace(/^\.+/, '').toLowerCase());
      if (!parts.length || parts.some((extension) => !extension || !allowed.includes(extension))) { restore(<?= json_encode(__('Allowed extensions contain an unsupported value.')) ?>); return; }
      value = [...new Set(parts)];
      input.value = value.join(', ');
    } else {
      value = input.value;
      if (!['none','icon','real'].includes(value)) { restore(<?= json_encode(__('Preview mode is invalid.')) ?>); return; }
    }
    input.removeAttribute('aria-invalid');
    mutateDefinition((definition) => {
      const target = definition.form.fields.find((candidate) => candidate.key === selectedKey);
      if (!isUploadField(target)) return;
      target.validation = mutableRecord(target.validation);
      target.settings = mutableRecord(target.settings);
      if (property === 'max_files') target.validation.max_files = value;
      else if (property === 'max_mb') target.validation.max_bytes = value * UPLOAD_MIB;
      else if (property === 'exts') target.validation.exts = value;
      else target.settings.preview_mode = value;
    });
  });
  inspector.addEventListener('change', (event) => {
    const keyInput = event.target.closest('[data-field-key]');
    if (!keyInput) return;
    const field = currentField();
    if (!field || TYPES[field.type]?.input !== true) return;
    const oldKey = field.key;
    const nextKey = keyInput.value.trim();
    hasPendingControlChanges = false;
    const reject = (message) => {
      keyInput.value = oldKey;
      keyInput.setAttribute('aria-invalid', 'true');
      keyInput.focus({ preventScroll: true });
      keyInput.select();
      hasUnsavedChanges = Boolean(pendingDefinition || saveInFlight);
      setStatus(message, 'error');
      updateActions();
    };
    if (!/^[a-z0-9][a-z0-9_]{0,79}$/.test(nextKey)) { reject('Field keys must use lowercase letters, numbers, and underscores'); return; }
    if (currentFields().some((candidate) => candidate.key === nextKey && candidate.key !== oldKey)) { reject('Field key must be unique'); return; }
    if (nextKey === oldKey) { keyInput.removeAttribute('aria-invalid'); updateActions(); return; }
    if (!syncUploadDescription()) { reject('Description editor sync failed'); return; }
    mutateDefinition((definition) => {
      const settings = mutableRecord(definition.form.settings);
      const target = definition.form.fields.find((candidate) => candidate.key === oldKey);
      if (!target) return;
      target.key = nextKey;
      definition.form.fields.forEach((candidate) => {
        if (candidate.parent === oldKey) candidate.parent = nextKey;
        candidate.settings = mutableRecord(candidate.settings);
        candidate.validation = mutableRecord(candidate.validation);
        if (candidate.settings.country_field === oldKey) candidate.settings.country_field = nextKey;
        ['after_field', 'before_field'].forEach((rule) => { if (candidate.validation[rule] === oldKey) candidate.validation[rule] = nextKey; });
      });
      settings.columns = (settings.columns || []).map((key) => key === oldKey ? nextKey : key);
      ['confirmation_email_field', 'reply_to_email_field', 'success_detail_field'].forEach((key) => { if (settings[key] === oldKey) settings[key] = nextKey; });
      Object.values(settings.translations || {}).forEach((translation) => {
        const translatedFields = translation?.fields;
        if (!translatedFields || !Object.prototype.hasOwnProperty.call(translatedFields, oldKey)) return;
        Object.defineProperty(translatedFields, nextKey, { value: translatedFields[oldKey], enumerable: true, configurable: true, writable: true });
        delete translatedFields[oldKey];
      });
      selectedKey = nextKey;
    }, true);
    setStatus('Field key changed - checking submission history', 'saving');
  });
  inspector.addEventListener('change', (event) => {
    const countryInput = event.target.closest('[data-country-field]');
    const validationInput = event.target.closest('[data-validation-prop]');
    if (!countryInput && !validationInput) return;
    const field = currentField();
    if (!field) return;
    const reject = (message) => {
      renderInspector();
      hasUnsavedChanges = Boolean(pendingDefinition || saveInFlight);
      setStatus(message, 'error');
      updateActions();
    };
    if (countryInput) {
      const country = currentFields().find((candidate) => candidate.key === countryInput.value && candidate.type === 'country' && !candidate.hidden);
      if (field.type !== 'intl_phone' || !country) { reject('Select a visible Country field'); return; }
      mutateDefinition((definition) => {
        const phone = definition.form.fields.find((candidate) => candidate.key === selectedKey);
        const linkedCountry = definition.form.fields.find((candidate) => candidate.key === countryInput.value);
        if (!phone || !linkedCountry) return;
        phone.settings = mutableRecord(phone.settings);
        phone.settings.country_field = linkedCountry.key;
        if (phone.required) linkedCountry.required = true;
      });
      return;
    }
    hasPendingControlChanges = false;
    const property = validationInput.dataset.validationProp;
    const validation = { ...mutableRecord(field.validation) };
    const rawValue = validationInput.value.trim();
    if (rawValue === '') delete validation[property];
    else if (property === 'maxlength') {
      const value = Number(rawValue);
      if (!Number.isSafeInteger(value) || value < 1 || value > 65536) { reject('Maximum length must be between 1 and 65536'); return; }
      validation.maxlength = value;
    } else if (property === 'pattern') {
      if ([...rawValue].length > 500) { reject('Pattern must not exceed 500 characters'); return; }
      validation.pattern = rawValue;
    } else if (property === 'min' || property === 'max') {
      if (field.type === 'number') {
        const value = Number(rawValue);
        if (!Number.isFinite(value)) { reject('Enter a valid numeric range'); return; }
        validation[property] = value;
        if (validation.min !== undefined && validation.max !== undefined && Number(validation.min) > Number(validation.max)) { reject('Minimum cannot exceed maximum'); return; }
      } else if (field.type === 'date') {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(rawValue)) { reject('Enter a valid date range'); return; }
        validation[property] = rawValue;
        if (validation.min && validation.max && validation.min > validation.max) { reject('Earliest date cannot exceed latest date'); return; }
      } else { return; }
    } else return;
    mutateDefinition((definition) => {
      const target = definition.form.fields.find((candidate) => candidate.key === selectedKey);
      if (target) target.validation = validation;
    });
  });
  inspector.addEventListener('change', (event) => {
    const input = event.target.closest('[data-image-prop]');
    if (!input || !['url', 'width'].includes(input.dataset.imageProp)) return;
    const field = currentField();
    if (!field || field.type !== 'image_block') return;
    const property = input.dataset.imageProp;
    const value = input.value.trim();
    const reject = (message) => {
      renderInspector();
      hasUnsavedChanges = Boolean(pendingDefinition || saveInFlight);
      setStatus(message, 'error');
      updateActions();
    };
    hasPendingControlChanges = false;
    if (property === 'url' && (value.length > 2000 || (value !== '' && ((!value.startsWith('/') && !/^https?:\/\//i.test(value)) || value.startsWith('/private/'))))) {
      reject('Use a root-relative or HTTP(S) image URL');
      return;
    }
    if (property === 'width' && value !== '' && !['25', '50', '75'].includes(value)) {
      reject('Image width is invalid');
      return;
    }
    mutateDefinition((definition) => {
      const target = definition.form.fields.find((candidate) => candidate.key === selectedKey && candidate.type === 'image_block');
      if (!target) return;
      target.settings = mutableRecord(target.settings);
      if (value === '') delete target.settings[property];
      else target.settings[property] = value;
    }, true);
  });
  inspector.addEventListener('input', (event) => {
    if (event.target.matches('[data-field-key]')) {
      hasPendingControlChanges = true;
      hasUnsavedChanges = true;
      setStatus('Unsaved field key', 'saving');
      updateActions();
      return;
    }
    const imageInput = event.target.closest('[data-image-prop]');
    if (imageInput) {
      const property = imageInput.dataset.imageProp;
      if (property === 'url') {
        hasPendingControlChanges = true;
        hasUnsavedChanges = true;
        setStatus('Unsaved image URL', 'saving');
        updateActions();
        return;
      }
      if (!['alt', 'caption'].includes(property)) return;
      const value = imageInput.value;
      if ([...value].length > 2000) return;
      mutateDefinition((definition) => {
        const target = definition.form.fields.find((candidate) => candidate.key === selectedKey && candidate.type === 'image_block');
        if (!target) return;
        target.settings = mutableRecord(target.settings);
        if (value === '') delete target.settings[property];
        else target.settings[property] = value;
      });
      return;
    }
    if (event.target.matches('[data-validation-prop]')) {
      hasPendingControlChanges = true;
      hasUnsavedChanges = true;
      setStatus('Unsaved validation changes', 'saving');
      updateActions();
      return;
    }
    if (event.target.matches('[data-field-parent]')) {
      moveField(selectedKey, event.target.value);
      return;
    }
    const optionCard = event.target.closest('[data-option-index]');
    const optionToggle = event.target.closest('[data-option-toggle]');
    if (optionCard && optionToggle) {
      const field = currentField();
      const index = Number(optionCard.dataset.optionIndex);
      if (!field || !Number.isSafeInteger(index) || !field.options?.[index]) return;
      const feature = optionToggle.dataset.optionToggle;
      const featureInput = optionCard.querySelector(`[data-option-prop="${feature}"]`);
      mutateDefinition((definition) => {
        const option = definition.form.fields.find((candidate) => candidate.key === selectedKey)?.options?.[index];
        if (!option) return;
        if (feature === 'price') option.price = optionToggle.checked ? 1 : 0;
        if (feature === 'capacity' && field.type === 'select') {
          if (optionToggle.checked) option.capacity = 1;
          else delete option.capacity;
        }
      });
      if (featureInput) {
        featureInput.hidden = !optionToggle.checked;
        if (optionToggle.checked) {
          featureInput.value = '1';
          window.requestAnimationFrame(() => featureInput.focus({ preventScroll: true }));
        }
      }
      setStatus(`${feature === 'price' ? 'Price' : 'Registration limit'} ${optionToggle.checked ? 'enabled' : 'disabled'} - unsaved changes`, 'saving');
      return;
    }
    const optionInput = event.target.closest('[data-option-prop]');
    if (optionCard && optionInput) {
      const field = currentField();
      const index = Number(optionCard.dataset.optionIndex);
      const property = optionInput.dataset.optionProp;
      if (!field || !Number.isSafeInteger(index) || !field.options?.[index] || !['label', 'value', 'price', 'capacity'].includes(property)) return;
      const options = structuredClone(field.options);
      const option = options[index];
      const oldValue = option.value;
      const rejectOptionInput = (message, previousValue) => {
        optionInput.value = String(previousValue ?? '');
        optionInput.removeAttribute('aria-invalid');
        optionInput.focus({ preventScroll: true });
        if (typeof optionInput.select === 'function') optionInput.select();
        setStatus(message, 'error');
      };
      if (property === 'label') {
        if (!optionInput.value.trim() || [...optionInput.value].length > 500) {
          rejectOptionInput('Displayed option text must contain 1 to 500 characters', option.label);
          return;
        }
        option.label = optionInput.value;
      } else if (property === 'value') {
        const value = optionInput.value;
        if (!value.trim() || [...value].length > 200 || /[\x00-\x1f\x7f]/.test(value)
            || options.some((candidate, candidateIndex) => candidateIndex !== index && candidate.value === value)) {
          rejectOptionInput('Stored values must be unique and contain 1 to 200 characters', option.value);
          return;
        }
        option.value = value;
      } else if (property === 'price') {
        if (optionInput.value.trim() === '') {
          rejectOptionInput('Price must be a whole number', option.price || 0);
          return;
        }
        const price = Number(optionInput.value);
        if (!Number.isSafeInteger(price) || Math.abs(price) > 1000000000000) {
          rejectOptionInput('Price must be a whole number', option.price || 0);
          return;
        }
        option.price = price;
        if (price === 0) {
          const priceToggle = optionCard.querySelector('[data-option-toggle="price"]');
          if (priceToggle) priceToggle.checked = false;
          optionInput.hidden = true;
          window.requestAnimationFrame(() => priceToggle?.focus({ preventScroll: true }));
        }
      } else {
        const capacity = Number(optionInput.value);
        if (field.type !== 'select' || !Number.isSafeInteger(capacity) || capacity < 1 || capacity > <?= FB_OPTION_CAPACITY_MAX ?>) {
          rejectOptionInput(`Capacity must be between 1 and <?= FB_OPTION_CAPACITY_MAX ?>`, option.capacity || 1);
          return;
        }
        option.capacity = capacity;
      }
      optionInput.removeAttribute('aria-invalid');
      if (property === 'label') {
        const heading = optionCard.querySelector('.fbv-option-card-head strong');
        if (heading) heading.textContent = option.label;
      }
      mutateDefinition((definition) => {
        const target = definition.form.fields.find((candidate) => candidate.key === selectedKey);
        if (!target) return;
        target.options = options;
        const allowedValues = new Set(options.map((candidate) => candidate.value));
        Object.values(definition.form.settings.translations || {}).forEach((translation) => {
          const translated = translation?.fields?.[selectedKey]?.options;
          if (!translated) return;
          if (property === 'value' && oldValue !== option.value && Object.prototype.hasOwnProperty.call(translated, oldValue)) {
            if (!Object.prototype.hasOwnProperty.call(translated, option.value)) {
              Object.defineProperty(translated, option.value, { value: translated[oldValue], enumerable: true, configurable: true, writable: true });
            }
            delete translated[oldValue];
          }
          Object.keys(translated).forEach((value) => { if (!allowedValues.has(value)) delete translated[value]; });
        });
      });
      return;
    }
    const property = event.target.dataset.fieldProp;
    const setting = event.target.dataset.settingProp;
    if (!property && !setting) return;
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
      if (setting) {
        field.settings = mutableRecord(field.settings);
        if (['align', 'valign'].includes(setting) && value === '') delete field.settings[setting];
        else field.settings[setting] = value;
      }
      else {
        field[property] = value;
        if (property === 'hidden' && value && definition.form.settings.success_detail_field === field.key) definition.form.settings.success_detail_field = '';
        if (field.type === 'intl_phone' && property === 'required' && value) {
          const countryField = definition.form.fields.find((candidate) => candidate.key === field.settings?.country_field);
          if (countryField) countryField.required = true;
        }
      }
    });
  });
  inspector.addEventListener('click', (event) => {
    const imagePicker = event.target.closest('[data-image-pick]');
    if (imagePicker) {
      const field = currentField();
      if (!field || field.type !== 'image_block') return;
      if (typeof window.openMediaSelector !== 'function') {
        setStatus('Gallery selector is unavailable', 'error');
        return;
      }
      const fieldKey = field.key;
      imagePicker.disabled = true;
      window.openMediaSelector({
        url: `${ADMIN_BASE}/admin/modal_img/index.php?embedded=1&visibility=public`,
        maxWidth: '980px',
        context: { surface: 'plugin.form-builder.visual', consumer: 'plugin.form-builder', resource_id: String(FORM_ID), field: fieldKey, selection_mode: 'immediate' }
      }).then((detail) => {
        const source = detail?.media && typeof detail.media === 'object' ? detail.media : detail;
        const media = typeof window.normalizeMedia === 'function' ? window.normalizeMedia(detail) : detail;
        if (!media?.url) return;
        const values = { url: String(media.url), alt: String(media.alt || media.title || ''), caption: String(media.caption || '') };
        const accessValues = ['visibility', 'storage_disk', 'access_scope'].map((key) => String(source?.[key] || 'public').toLowerCase());
        if (accessValues.some((value) => value !== 'public') || values.url.startsWith('/private/') || values.url.length > 2000 || (!values.url.startsWith('/') && !/^https?:\/\//i.test(values.url)) || [...values.alt].length > 2000 || [...values.caption].length > 2000) {
          setStatus('Selected image metadata is invalid', 'error');
          return;
        }
        mutateDefinition((definition) => {
          const target = definition.form.fields.find((candidate) => candidate.key === fieldKey && candidate.type === 'image_block');
          if (!target) return;
          target.settings = mutableRecord(target.settings);
          target.settings.url = values.url;
          if (!target.settings.alt && values.alt) target.settings.alt = values.alt;
          if (!target.settings.caption && values.caption) target.settings.caption = values.caption;
        }, true);
      }).catch((error) => setStatus(error?.message || 'Gallery selection failed', 'error'))
        .finally(() => { imagePicker.disabled = false; });
      return;
    }
    const addOption = event.target.closest('[data-option-add]');
    if (addOption) {
      const field = currentField();
      if (!field || !TYPES[field.type]?.options) return;
      if ((field.options || []).length >= 200) { setStatus('This field has reached the 200-option limit', 'error'); return; }
      const addedIndex = field.options.length;
      mutateDefinition((definition) => {
        const target = definition.form.fields.find((candidate) => candidate.key === selectedKey);
        if (!target) return;
        const used = new Set((target.options || []).map((option) => option.value));
        let number = target.options.length + 1;
        while (used.has(`option_${number}`)) number++;
        target.options.push({ value: `option_${number}`, label: `Option ${number}`, price: 0 });
      }, true);
      setStatus('Option added - unsaved changes', 'saving');
      window.requestAnimationFrame(() => inspector.querySelector(`[data-option-index="${addedIndex}"] [data-option-prop="label"]`)?.focus({ preventScroll: true }));
      return;
    }
    const optionCard = event.target.closest('[data-option-index]');
    const optionMove = event.target.closest('[data-option-move]');
    const removeOption = event.target.closest('[data-option-remove]');
    if (optionCard && (optionMove || removeOption)) {
      const index = Number(optionCard.dataset.optionIndex);
      const field = currentField();
      if (!field || !Number.isSafeInteger(index) || !field.options?.[index]) return;
      if (removeOption && field.options.length <= 1) { setStatus('A choice field needs at least one option', 'error'); return; }
      const destination = optionMove ? index + (optionMove.dataset.optionMove === 'up' ? -1 : 1) : Math.min(index, field.options.length - 2);
      const action = removeOption ? 'removed' : 'moved';
      mutateDefinition((definition) => {
        const target = definition.form.fields.find((candidate) => candidate.key === selectedKey);
        if (!target?.options?.[index]) return;
        if (removeOption) target.options.splice(index, 1);
        else {
          if (destination < 0 || destination >= target.options.length) return;
          [target.options[index], target.options[destination]] = [target.options[destination], target.options[index]];
        }
        const allowedValues = new Set(target.options.map((option) => option.value));
        Object.values(definition.form.settings.translations || {}).forEach((translation) => {
          const translated = translation?.fields?.[selectedKey]?.options;
          if (!translated) return;
          Object.keys(translated).forEach((value) => { if (!allowedValues.has(value)) delete translated[value]; });
        });
      }, true);
      setStatus(`Option ${action} - unsaved changes`, 'saving');
      window.requestAnimationFrame(() => inspector.querySelector(`[data-option-index="${destination}"] [data-option-prop="label"]`)?.focus({ preventScroll: true }));
      return;
    }
    const move = event.target.closest('[data-field-move]');
    if (move && !move.disabled) {
      reorderField(selectedKey, move.dataset.fieldMove === 'up' ? -1 : 1);
      return;
    }
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
      ['confirmation_email_field', 'reply_to_email_field', 'success_detail_field'].forEach((key) => { if (settings[key] === selectedKey) settings[key] = ''; });
      Object.values(settings.translations || {}).forEach((translation) => { if (translation?.fields) delete translation.fields[selectedKey]; });
      definition.form.fields.forEach((candidate) => {
        if (candidate.validation?.after_field === selectedKey) delete candidate.validation.after_field;
        if (candidate.validation?.before_field === selectedKey) delete candidate.validation.before_field;
      });
    });
    selectedKey = null;
    renderInspector();
    renderFieldPicker();
    setStatus('Field removed from draft - publish to move it to the Bin', 'saving');
  });

  window.addEventListener('beforeunload', (event) => {
    syncUploadDescription();
    syncContentEditor();
    if (!hasUnsavedChanges && !hasPendingControlChanges && !saveInFlight) return;
    event.preventDefault();
    event.returnValue = '';
  });
  window.addEventListener('pagehide', (event) => {
    if (event.persisted) return;
    syncUploadDescription();
    syncContentEditor();
    if (uploadDescriptionEditor) uploadDescriptionEditor.destroy();
    uploadDescriptionEditor = null;
    if (contentEditor) contentEditor.destroy();
    contentEditor = null;
  });

  devices.forEach((button) => button.addEventListener('click', () => {
    devices.forEach((candidate) => {
      const active = candidate === button;
      candidate.classList.toggle('is-active', active);
      candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    canvas.dataset.device = button.dataset.device;
  }));
})();
</script>
