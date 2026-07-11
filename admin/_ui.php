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
/* builder */
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
</style>
    <?php
}
