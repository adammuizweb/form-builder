<?php
// /plugins/form-builder/admin/_ui.php
declare(strict_types=1);

function fb_url(array $over = []): string {
    $q = array_merge([
        'page' => 'admin/tools/form-builder',
        'view' => $_GET['view'] ?? 'forms',
        'id' => $_GET['id'] ?? null,
        'fid' => $_GET['fid'] ?? null,
        'q' => $_GET['q'] ?? null,
        'p' => $_GET['p'] ?? null,
        'df' => $_GET['df'] ?? null,
        'dt' => $_GET['dt'] ?? null,
    ], $over);
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($q);
}

// Admin pages are included AFTER the theme header is printed, so PHP header()
// redirects fail ("headers already sent"). Use a JS redirect + return instead.
function fb_js_redirect(string $url): void {
    echo '<script>location.replace(' . json_encode($url) . ');</script>';
}

function fb_admin_css(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
<style>
.fba { color: var(--adam-text); font-family: inherit; }
.fba-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.2rem; }
.fba-head h1 { font-size: 1.35rem; font-weight: 700; margin: 0; }
.fba-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.fba-btn { display: inline-block; border: 1px solid var(--adam-border); background: var(--adam-card); color: var(--adam-text); border-radius: 9px; padding: .42rem .85rem; font-size: .8rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all .18s; font-family: inherit; }
.fba-btn:hover { border-color: var(--adam-accent); color: var(--adam-accent); }
.fba-btn.primary { background: var(--adam-accent); border-color: var(--adam-accent); color: #fff; }
.fba-btn.primary:hover { filter: brightness(1.08); color: #fff; }
.fba-btn.danger:hover { border-color: var(--adam-danger); color: var(--adam-danger); }
.fba-btn.sm { padding: .28rem .6rem; font-size: .74rem; }
.fba-card { background: var(--adam-card); border: 1px solid var(--adam-border); border-radius: 14px; padding: 1.1rem 1.3rem; margin-bottom: 1rem; }
.fba-table-wrap { overflow-x: auto; border: 1px solid var(--adam-border); border-radius: 12px; background: var(--adam-card); }
.fba-table { width: 100%; border-collapse: collapse; font-size: .86rem; }
.fba-table th { text-align: left; padding: .65rem .85rem; background: var(--adam-bg); border-bottom: 1px solid var(--adam-border); font-size: .68rem; letter-spacing: .08em; text-transform: uppercase; color: var(--adam-muted); white-space: nowrap; }
.fba-table td { padding: .6rem .85rem; border-bottom: 1px solid var(--adam-border-soft, var(--adam-border)); vertical-align: middle; }
.fba-table tr:last-child td { border-bottom: none; }
.fba-table tr.unread td { font-weight: 600; }
.fba-sub { display: block; font-size: .74rem; color: var(--adam-muted); font-weight: 400; }
.fba-mono { font-family: ui-monospace, monospace; font-size: .76rem; }
.fba-code { background: var(--adam-bg); border: 1px solid var(--adam-border); border-radius: 6px; padding: .1rem .45rem; font-family: ui-monospace, monospace; font-size: .74rem; color: var(--adam-accent); user-select: all; }
.fba-badge { display: inline-block; font-size: .66rem; font-weight: 700; padding: .2rem .6rem; border-radius: 999px; letter-spacing: .04em; }
.fba-badge.new { background: rgba(228 152 78 / .18); color: #b06a22; }
.fba-badge.read { background: rgba(43 122 74 / .14); color: #2b7a4a; }
.fba-badge.trash { background: rgba(190 45 45 / .12); color: var(--adam-danger); }
.fba-badge.active { background: rgba(43 122 74 / .14); color: #2b7a4a; }
.fba-badge.draft { background: rgba(120 120 120 / .15); color: var(--adam-muted); }
.fba-badge.arch { background: rgba(190 45 45 / .1); color: var(--adam-danger); }
.fba-flash { border-radius: 10px; padding: .7rem 1rem; margin-bottom: 1rem; font-size: .86rem; font-weight: 600; }
.fba-flash.ok { background: rgba(43 122 74 / .12); border: 1px solid rgba(43 122 74 / .4); color: #2b7a4a; }
.fba-flash.err { background: rgba(190 45 45 / .1); border: 1px solid rgba(190 45 45 / .4); color: var(--adam-danger); }
.fba-toolbar { display: flex; gap: .7rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.fba-toolbar form { display: flex; gap: .45rem; align-items: center; flex-wrap: wrap; }
.fba-empty { text-align: center; padding: 2.5rem 1rem; color: var(--adam-muted); }
.fba-pager { display: flex; gap: .35rem; align-items: center; justify-content: center; margin-top: 1.1rem; flex-wrap: wrap; }
.fba-pager a, .fba-pager span { min-width: 30px; text-align: center; padding: .32rem .55rem; border-radius: 8px; border: 1px solid var(--adam-border); text-decoration: none; font-size: .78rem; color: var(--adam-text); }
.fba-pager a:hover { border-color: var(--adam-accent); }
.fba-pager span.cur { background: var(--adam-accent); border-color: var(--adam-accent); color: #fff; font-weight: 700; }
.fba-overlay { position: fixed; inset: 0; background: rgba(10 20 15 / .55); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); z-index: 9000; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
.fba-modal { background: var(--adam-card); border: 1px solid var(--adam-border); border-radius: 16px; max-width: 660px; width: 100%; max-height: 88vh; overflow-y: auto; box-shadow: 0 30px 80px rgba(0 0 0 / .35); }
.fba-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.3rem; border-bottom: 1px solid var(--adam-border); position: sticky; top: 0; background: var(--adam-card); z-index: 1; }
.fba-modal-head h2 { font-size: 1.02rem; margin: 0; }
.fba-modal-head a { text-decoration: none; font-size: 1.3rem; color: var(--adam-muted); line-height: 1; }
.fba-modal-body { padding: 1.1rem 1.3rem; }
.fba-field { margin-bottom: 1rem; }
.fba-field label { display: block; font-size: .72rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--adam-muted); margin-bottom: .4rem; }
.fba-field input[type=text], .fba-field input[type=email], .fba-field input[type=number], .fba-field input[type=date], .fba-field input[type=search], .fba-field select, .fba-field textarea {
  width: 100%; font: inherit; font-size: .88rem; background: var(--adam-bg); color: var(--adam-text);
  border: 1.5px solid var(--adam-border); border-radius: 9px; padding: .55rem .8rem; outline: none; transition: border-color .2s;
}
.fba-field input:focus, .fba-field select:focus, .fba-field textarea:focus { border-color: var(--adam-accent); }
.fba-field textarea { min-height: 90px; resize: vertical; font-family: ui-monospace, monospace; font-size: .8rem; }
.fba-hint { font-size: .74rem; color: var(--adam-muted); margin-top: .3rem; }
.fba-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; }
.fba-row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .8rem; }
@media (max-width: 640px) { .fba-row2, .fba-row3 { grid-template-columns: 1fr; } }
.fba-check { display: flex; align-items: center; gap: .5rem; font-size: .86rem; }
.fba-checks { display: flex; flex-wrap: wrap; gap: .5rem 1.1rem; }
.fba-sec { font-size: .72rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--adam-accent); margin: 1.2rem 0 .7rem; padding-bottom: .35rem; border-bottom: 1px dashed var(--adam-border); }
.fba-sec:first-child { margin-top: 0; }
/* builder v2: palette + canvas + panel */
.fbb3 { display: grid; grid-template-columns: 200px 1fr; gap: 1.1rem; align-items: start; }
.fbb3.has-panel { grid-template-columns: 200px 1fr 320px; }
@media (max-width: 1100px) {
  .fbb3, .fbb3.has-panel { grid-template-columns: 1fr; }
  /* Mobile UX: edit panel becomes a bottom sheet with backdrop */
  .fbb3 .fbc-panel { position: fixed; left: 0; right: 0; bottom: 0; top: auto; max-height: 88vh; border-radius: 16px 16px 0 0; z-index: 9551; box-shadow: 0 -8px 30px rgba(0 0 0 / .28); }
  .fbb3 .fbc-panel-backdrop { position: fixed; inset: 0; background: rgba(0 0 0 / .45); z-index: 9550; }
}
.fbc-palette { background: var(--adam-card); border: 1px solid var(--adam-border); border-radius: 14px; padding: .9rem; position: sticky; top: 1rem; }
.fbc-palette h3 { font-size: .68rem; letter-spacing: .12em; text-transform: uppercase; color: var(--adam-muted); margin: 0 0 .7rem; }
.fbc-p-chip { display: block; width: 100%; text-align: left; border: 1px solid var(--adam-border); background: var(--adam-bg); color: var(--adam-text); border-radius: 9px; padding: .48rem .7rem; font-size: .8rem; font-weight: 600; cursor: grab; margin-bottom: .38rem; font-family: inherit; transition: all .15s; }
.fbc-p-chip:hover { border-color: var(--adam-accent); color: var(--adam-accent); transform: translateX(3px); }
.fbc-p-chip:active { cursor: grabbing; }
.fbc-canvas { display: flex; flex-direction: column; gap: 1rem; }
.fbc-row { border: 1.5px dashed var(--adam-border); border-radius: 14px; padding: .7rem; background: var(--adam-card); transition: border-color .2s; }
.fbc-row.row-over { border-color: var(--adam-accent); }
.fbc-row-bar { display: flex; align-items: center; gap: .6rem; margin-bottom: .6rem; }
.fbc-grip { cursor: grab; color: var(--adam-muted); font-size: 1rem; user-select: none; padding: 0 .2rem; }
.fbc-grip:active { cursor: grabbing; }
.fbc-row-title { font-size: .7rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--adam-muted); }
.fbc-cols-lbl { font-size: .72rem; color: var(--adam-muted); margin-left: auto; display: flex; align-items: center; gap: .35rem; }
.fbc-cols-sel { border: 1px solid var(--adam-border); background: var(--adam-bg); color: var(--adam-text); border-radius: 7px; padding: .2rem .4rem; font-size: .8rem; }
.fbc-row-del { border: none; background: transparent; color: var(--adam-danger); font-size: 1.05rem; cursor: pointer; padding: 0 .3rem; border-radius: 6px; }
.fbc-row-del:hover { background: rgba(190 45 45 / .1); }
.fbc-cols { display: grid; gap: .7rem; }
.fbc-col { border: 1.5px dashed var(--adam-border-soft, var(--adam-border)); border-radius: 11px; padding: .55rem; min-height: 64px; display: flex; flex-direction: column; gap: .5rem; transition: border-color .2s, background .2s; }
.fbc-col.col-over { border-color: var(--adam-accent); background: rgba(43 122 74 / .06); }
.fbc-empty { font-size: .72rem; color: var(--adam-muted); text-align: center; padding: .9rem .3rem; pointer-events: none; }
.fbc-chip { display: flex; align-items: center; gap: .5rem; background: var(--adam-bg); border: 1px solid var(--adam-border); border-radius: 9px; padding: .42rem .6rem; cursor: grab; font-size: .8rem; transition: box-shadow .15s, opacity .15s; }
.fbc-chip:hover { box-shadow: 0 3px 12px rgba(0 0 0 / .1); }
.fbc-chip:active { cursor: grabbing; }
.fbc-chip.is-hidden { opacity: .5; }
.fbc-chip-type { font-size: .58rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; border: 1px solid var(--adam-border); border-radius: 5px; padding: .1rem .4rem; color: var(--adam-muted); flex-shrink: 0; }
.fbc-chip-lbl { font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fbc-chip-lbl .req { color: var(--adam-danger); }
.fbc-chip-key { font-family: ui-monospace, monospace; font-size: .66rem; color: var(--adam-accent); flex-shrink: 0; }
.fbc-chip-edit, .fbc-chip-del { border: none; background: transparent; cursor: pointer; font-size: .85rem; color: var(--adam-muted); padding: 0 .15rem; border-radius: 5px; flex-shrink: 0; }
.fbc-chip-edit:hover { color: var(--adam-accent); }
.fbc-chip-del:hover { color: var(--adam-danger); }
.fbc-add-row { text-align: center; padding: .4rem 0; }
.fbc-panel { background: var(--adam-card); border: 1px solid var(--adam-border); border-radius: 14px; overflow: hidden; position: sticky; top: 1rem; max-height: 85vh; overflow-y: auto; }
.fbc-panel-head { display: flex; justify-content: space-between; align-items: center; padding: .8rem 1rem; border-bottom: 1px solid var(--adam-border); }
.fbc-panel-head h3 { margin: 0; font-size: .85rem; }
.fbc-panel-head button { border: none; background: transparent; font-size: 1.2rem; color: var(--adam-muted); cursor: pointer; }
.fbc-panel-body { padding: 1rem; }
.fbc-toast { position: fixed; bottom: 1.4rem; right: 1.4rem; background: var(--adam-text); color: var(--adam-bg); border-radius: 10px; padding: .6rem 1.1rem; font-size: .82rem; font-weight: 600; opacity: 0; transform: translateY(12px); transition: all .3s; pointer-events: none; z-index: 9500; }
.fbc-toast.show { opacity: 1; transform: translateY(0); }
.fbc-toast.err { background: var(--adam-danger); color: #fff; }
.fbc-chip.dragging { opacity: .35; }
.fbb-layout { display: grid; grid-template-columns: 230px 1fr; gap: 1.2rem; align-items: start; }
@media (max-width: 900px) { .fbb-layout { grid-template-columns: 1fr; } }
.fbb-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: .8rem; align-items: start; }
.fbb-grid .fbb-field { grid-column: span 12; margin-bottom: 0; }
.fbb-grid .fbb-field.w6 { grid-column: span 6; }
.fbb-grid .fbb-field.w4 { grid-column: span 4; }
.fbb-grid .fbb-field.w3 { grid-column: span 3; }
@media (max-width: 760px) { .fbb-grid .fbb-field { grid-column: span 12 !important; } }
.fbb-palette { background: var(--adam-card); border: 1px solid var(--adam-border); border-radius: 14px; padding: 1rem; position: sticky; top: 1rem; }
.fbb-palette h3 { font-size: .7rem; letter-spacing: .12em; text-transform: uppercase; color: var(--adam-muted); margin: 0 0 .7rem; }
.fbb-palette button { display: block; width: 100%; text-align: left; border: 1px solid var(--adam-border); background: var(--adam-bg); color: var(--adam-text); border-radius: 9px; padding: .5rem .75rem; font-size: .82rem; font-weight: 600; cursor: pointer; margin-bottom: .4rem; font-family: inherit; transition: all .15s; }
.fbb-palette button:hover { border-color: var(--adam-accent); color: var(--adam-accent); transform: translateX(3px); }
.fbb-field { background: var(--adam-card); border: 1px solid var(--adam-border); border-radius: 12px; margin-bottom: .8rem; overflow: hidden; }
.fbb-field.hidden-f { opacity: .55; }
.fbb-field-head { display: flex; align-items: center; gap: .7rem; padding: .7rem .95rem; cursor: pointer; }
.fbb-field-head .type { font-size: .62rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; background: var(--adam-bg); border: 1px solid var(--adam-border); border-radius: 6px; padding: .15rem .5rem; color: var(--adam-muted); }
.fbb-field-head .lbl { font-weight: 600; font-size: .9rem; flex: 1; }
.fbb-field-head .key { font-family: ui-monospace, monospace; font-size: .72rem; color: var(--adam-accent); }
.fbb-field-head .req-star { color: var(--adam-danger); font-weight: 700; }
.fbb-field-body { display: none; padding: 1rem .95rem; border-top: 1px dashed var(--adam-border); }
.fbb-field.open .fbb-field-body { display: block; }
.fbb-order { display: flex; gap: .2rem; }
.fbb-order button { border: 1px solid var(--adam-border); background: var(--adam-bg); border-radius: 6px; width: 26px; height: 26px; cursor: pointer; font-size: .75rem; color: var(--adam-text); }
.fbb-order button:hover { border-color: var(--adam-accent); color: var(--adam-accent); }

/* ---- v1.2: element palette + content editors + bin ---- */
.fbc-p-chip.el { border-style: dashed; color: var(--adam-muted); }
.fbc-p-chip.el:hover { color: var(--adam-accent); border-color: var(--adam-accent); }
.fbc-content-prev { border: 1px solid var(--adam-border); border-radius: 9px; padding: .6rem .7rem; max-height: 140px; overflow: auto; font-size: .8rem; background: var(--adam-bg); }
.fbc-content-prev img { max-width: 100%; height: auto; }
.fbc-img-prev { border: 1px dashed var(--adam-border); border-radius: 9px; padding: .5rem; text-align: center; background: var(--adam-bg); }
.fbc-img-prev img { max-width: 100%; max-height: 140px; border-radius: 7px; }
.fbc-editor-overlay { position: fixed; inset: 0; background: rgba(0 0 0 / .55); z-index: 9600; display: flex; align-items: center; justify-content: center; padding: 2rem; }
.fbc-editor-modal { background: var(--adam-card); border: 1px solid var(--adam-border); border-radius: 16px; width: min(900px, 96vw); max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; }
.fbc-editor-head { display: flex; justify-content: space-between; align-items: center; padding: .85rem 1.1rem; border-bottom: 1px solid var(--adam-border); }
.fbc-editor-head h3 { margin: 0; font-size: .92rem; }
.fbc-editor-head button { border: none; background: transparent; font-size: 1.3rem; color: var(--adam-muted); cursor: pointer; }
.fbc-editor-body { padding: 1rem 1.1rem; overflow: auto; }
.fbc-editor-body .ql-container { font-size: .9rem; }
.fbc-editor-foot { display: flex; justify-content: flex-end; gap: .5rem; padding: .75rem 1.1rem; border-top: 1px solid var(--adam-border); }
.fbc-chip.is-el { background: color-mix(in srgb, var(--adam-accent) 5%, var(--adam-bg)); }
.fbc-chip-al { font-size: .58rem; font-weight: 700; letter-spacing: .04em; color: var(--adam-accent); border: 1px solid var(--adam-border); border-radius: 4px; padding: .05rem .3rem; flex-shrink: 0; }
</style>
    <?php
}
