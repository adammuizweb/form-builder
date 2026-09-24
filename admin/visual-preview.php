<?php
declare(strict_types=1);

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Database not available');
}
$uid = function_exists('current_user_id') ? (int)current_user_id() : 0;
if ($uid <= 0 || !function_exists('user_can') || !user_can($pdo, $uid, 'plugin.form-builder.workspace.access')) {
    http_response_code(404);
    exit('Not found');
}

fb_assert_schema($pdo);
$formId = is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0;
$form = fb_get_form($pdo, $formId);
if ($form === null || ($form['deleted_at'] ?? null) !== null || !fb_can_access_form($pdo, $form, $uid)) {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/public/render.php';
$previewForm = $form;
$previewSettings = fb_form_settings($previewForm);
$previewSettings['unsafe_code_enabled'] = false;
$previewForm['settings_json'] = fb_json_encode($previewSettings);
$previewForm['css'] = null;
$previewForm['js'] = null;
$previewHtml = '<div class="fbv-preview" id="fbvPreview" aria-label="Form preview">'
    . fb_render_form($pdo, $previewForm) . '</div>';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
$GLOBALS['robots_meta'] = 'noindex,nofollow';
$context_for_layout = 'single.page';
$page_title = 'Preview: ' . (string)$form['title'];
$layout_full_width = false;
$enable_sidebar = false;
$layout_data = ['form_builder_preview' => true];

add_filter('layout_slot_html', static function (string $slotHtml, string $slot) use ($previewHtml): string {
    return $slot === 'single.page' ? $previewHtml : $slotHtml;
}, PHP_INT_MAX);
add_action('jy_head', static function (): void { ?>
<style>
body > :not(#site-main):not(script) { opacity: .38; filter: saturate(.3); pointer-events: none !important; user-select: none; }
body > :not(#site-main):not(script) * { pointer-events: none !important; }
.fbv-preview button, .fbv-preview input, .fbv-preview textarea, .fbv-preview select { pointer-events: none; }
.fbv-preview .fb-field { position: relative; border-radius: 10px; cursor: pointer; outline: 2px solid transparent; outline-offset: 6px; transition: outline-color .15s, background .15s; }
.fbv-preview .fb-field[draggable="true"] { cursor: grab; }
.fbv-preview .fb-field.is-dragging { opacity: .35; }
.fbv-preview .fb-col { min-height: 42px; border-radius: 10px; transition: background .15s, outline-color .15s; }
.fbv-preview .fb-col.is-drop-target, .fbv-preview .fb-field.is-drop-target { outline: 2px dashed var(--fb-accent, #2b7a4a); outline-offset: 5px; background: color-mix(in srgb, var(--fb-accent, #2b7a4a) 8%, transparent); }
.fbv-preview .fb-field:hover { outline-color: color-mix(in srgb, var(--fb-accent, #2b7a4a) 36%, transparent); }
.fbv-preview .fb-field.is-selected { outline-color: var(--fb-accent, #2b7a4a); background: color-mix(in srgb, var(--fb-accent, #2b7a4a) 5%, transparent); }
</style>
<?php }, PHP_INT_MAX);

$layoutPath = dirname(PLUGIN_PATH) . '/app/layout.php';
if (!is_file($layoutPath)) {
    http_response_code(500);
    exit('Public layout unavailable');
}
require $layoutPath;
