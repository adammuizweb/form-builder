<?php
declare(strict_types=1);

$root = dirname(__DIR__); $failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n"; if (!$ok) $failures[] = $message; };
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$permissions = array_column($manifest['permissions'] ?? [], null, 'key');
$composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
$lock = json_decode((string)file_get_contents($root . '/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
$lockedPackages = array_column($lock['packages'] ?? [], 'version', 'name');
$check(($manifest['version'] ?? null) === '2.4.0' && ($manifest['requires']['jyavani'] ?? null) === '>=2.3.140' && ($manifest['store']['url'] ?? null) === 'https://jyavani.com/plugin-store', 'release identity, Core requirement, and Store endpoint are exact');
$check(in_array('content-editor', $manifest['dependencies']['js'] ?? [], true), 'upload descriptions declare the Core content-editor dependency');
$staticCopies = array_column($manifest['static']['copy'] ?? [], 'to', 'from');
$check(($staticCopies['public/proof.js'] ?? null) === 'static/plugins/form-builder/proof.js', 'submission proof generator publishes only in the plugin-owned static namespace');
$check(($composer['require']['php'] ?? null) === '>=8.1' && ($composer['require']['phpoffice/phpspreadsheet'] ?? null) === '~5.8.1'
    && ($composer['config']['platform']['php'] ?? null) === '8.1.0'
    && ($lockedPackages['phpoffice/phpspreadsheet'] ?? null) === '5.8.1'
    && ($lockedPackages['maennchen/zipstream-php'] ?? null) === '3.1.1'
    && is_file($root . '/vendor/autoload.php'),
    'Excel export ships a locked plugin-local PhpSpreadsheet runtime');
$check(array_diff(['pdo','pdo_mysql','fileinfo','dom','json','mbstring'], $manifest['requires']['extensions'] ?? []) === [] && !in_array('zip', $manifest['requires']['extensions'] ?? [], true), 'runtime extensions are complete without obsolete DOCX zip dependency');
foreach (['submissions.manage','workflow.manage','definitions.manage','unsafe-code.manage'] as $suffix) $check(isset($permissions['plugin.form-builder.' . $suffix]), 'narrow permission ' . $suffix . ' is declared');
$adminPages = array_column($manifest['admin']['pages'] ?? [], null, 'route');
$check(($adminPages['admin/tools/form-builder/editor']['file'] ?? null) === 'admin/visual-builder.php'
    && ($adminPages['admin/tools/form-builder/editor']['permission'] ?? null) === 'plugin.form-builder.workspace.access'
    && ($adminPages['admin/tools/form-builder/editor']['hidden'] ?? false) === true,
    'Visual Builder has a hidden permission-bound dashboard route');
$check(($adminPages['admin/tools/form-builder/settings']['file'] ?? null) === 'admin/global-settings.php'
    && ($adminPages['admin/tools/form-builder/settings']['permission'] ?? null) === 'plugin.form-builder.workspace.access'
    && ($adminPages['admin/tools/form-builder/settings']['hidden'] ?? false) === true,
    'global Settings has a hidden workspace-bound dashboard route');
$check(is_file($root . '/migrations/0001-baseline.sql') && is_file($root . '/migrations/0002-submission-workflow.php') && is_file($root . '/migrations/0003-visual-builder-drafts.php') && is_file($root . '/migrations/0004-option-capacity.php') && count(glob($root . '/migrations/*') ?: []) === 4, 'only final append-only migration names ship');
$builder = (string)file_get_contents($root . '/tools/build-package.php');
$check(!str_contains($builder, "'form-builder/' .") && str_contains($builder, "str_replace(DIRECTORY_SEPARATOR, '/', \$relative)"), 'package builder writes plugin.json at the archive root');
$check(str_contains($builder, "str_starts_with(\$relative, 'tests/')"), 'release package excludes environment-specific test files');
$ajax = (string)file_get_contents($root . '/admin/ajax.php');
$check(!str_contains($ajax, "'Server error: ' . \$e->getMessage()") && str_contains($ajax, 'error_log('), 'admin AJAX logs detail and returns no raw exception');
$visualAjax = (string)file_get_contents($root . '/admin/visual-ajax.php');
$check(str_contains($visualAjax, "user_can(\$pdo, \$uid, 'plugin.form-builder.workspace.access')")
    && str_contains($visualAjax, 'csrf_check(')
    && str_contains($visualAjax, 'fb_can_access_form($pdo, $form, $uid)')
    && str_contains($visualAjax, "user_can(\$pdo, \$uid, 'plugin.form-builder.unsafe-code.manage')")
    && str_contains($visualAjax, 'UnexpectedValueException')
    && str_contains($visualAjax, 'error_log('),
    'Visual draft API requires workspace, CSRF, form scope, unsafe-code capability, and safe conflict handling');
$check(str_contains($visualAjax, "\$action === 'publish' || \$action === 'reset'")
    && str_contains($visualAjax, 'canonical_conflict')
    && str_contains($ajax, 'fb_acquire_form_mutation_lock($pdo, $formId)')
    && str_contains($ajax, "['add_row', 'set_cols', 'add_field', 'move', 'delete', 'save_field']"),
    'Visual publish/reset and every Classic field mutation share a form-scoped lock');
$submit = (string)file_get_contents($root . '/public/submit.php');
$publicHelpers = (string)file_get_contents($root . '/public/_helpers.php');
$publicRenderer = (string)file_get_contents($root . '/public/render.php');
$proofScript = (string)file_get_contents($root . '/public/proof.js');
$plugin = (string)file_get_contents($root . '/plugin.php');
$check(!preg_match('/(?<!jy_)mail\s*\(/', $submit) && strpos($submit, '$pdo->commit()') < strpos($submit, 'jy_mail_send'), 'Core mail executes only after persistence');
$check(!str_contains($submit, 'HTTP_X_FORWARDED_FOR') && str_contains($submit, 'do_action_isolated'), 'submission path retains trusted IP and isolated observer contracts');
$check(substr_count($submit, 'fb_success_redirect($pdo, $return, $form,') === 4
    && str_contains($publicHelpers, 'function fb_success_token(')
    && str_contains($publicHelpers, "hash_hmac('sha256', \"success\\0")
    && str_contains($publicHelpers, 'function fb_success_token_check(')
    && str_contains($publicHelpers, "unset(\$qs['fb_status'], \$qs['fb_ref'], \$qs['fb_form'], \$qs['fb_msg'], \$qs['fb_success'])")
    && str_contains($publicRenderer, 'fb_success_token_check($pdo, $formId, $ref, $successToken)')
    && str_contains($publicRenderer, 'WHERE form_id = ? AND reference_code = ? AND is_deleted = 0 LIMIT 1')
    && str_contains($publicRenderer, 'fb_success_value_text($successField')
    && str_contains($publicRenderer, 'fb_success_notification_text('),
    'dynamic success messages use a short-lived signed PRG lookup instead of exposing submission values in the URL');
$check(str_contains($publicRenderer, 'SELECT data_json,created_at FROM fb_submissions WHERE form_id = ? AND reference_code = ? AND is_deleted = 0 LIMIT 1')
    && str_contains($publicRenderer, "header('Cache-Control: private, no-store")
    && str_contains($publicRenderer, "header('Referrer-Policy: no-referrer')")
    && str_contains($publicRenderer, "header('X-Robots-Tag: noindex, nofollow, noarchive')")
    && str_contains($publicRenderer, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT')
    && str_contains($publicRenderer, 'data-fb-proof-download')
    && str_contains($publicRenderer, 'referrerpolicy="no-referrer"')
    && !str_contains($publicRenderer, 'data_json"=>')
    && !str_contains($submit, 'submission_proof'),
    'submission proof is gated by the verified success row and exposes only a bounded no-store browser model');
$check(str_contains($proofScript, "document.createElement('canvas')")
    && str_contains($proofScript, 'const height = proofHeight(sizingContext, model)')
    && !str_contains($proofScript, 'WORK_HEIGHT')
    && str_contains($proofScript, "ascii('%PDF-1.4\\n')")
    && str_contains($proofScript, 'const sliceHeight = Math.floor((pageHeight - margin * 2) / scale)')
    && str_contains($proofScript, 'const safeBreakpoints = proofBreakpoints.get(canvas) || []')
    && str_contains($proofScript, '/Count ${pages.length}')
    && str_contains($proofScript, "new Blob([concatBytes(parts)], { type: 'application/pdf' })")
    && str_contains($proofScript, "canvasBlob(canvas, 'image/png')")
    && str_contains($proofScript, 'link.download = filename')
    && str_contains($proofScript, 'model.success_message')
    && str_contains($proofScript, 'const source = text(value, 4000)')
    && !str_contains($proofScript, 'detail_message')
    && !str_contains($proofScript, 'detail_value')
    && !preg_match('/\b(?:fetch|XMLHttpRequest|sendBeacon|WebSocket)\b/', $proofScript),
    'browser proof generator creates local PNG/PDF downloads without transmitting submission data');
$check(str_contains($submit, 'if ($uploadFields !== [])')
    && str_contains($submit, 'fb_prepare_files_base_dir($form)')
    && str_contains($submit, 'chmod($path, 0640)'),
    'public submission provisions scoped private storage only when validated uploads are present');
$check(str_contains($submit, 'fb_select_capacity_errors($pdo, $formId, $localizedFields, [$data], $settings)')
    && strpos($submit, 'idempotency_key = ? FOR UPDATE') < strpos($submit, 'fb_select_capacity_errors(')
    && str_contains($plugin, 'function fb_select_capacity_usage(')
    && str_contains($plugin, 'is_deleted = 0')
    && str_contains($plugin, 'function fb_parse_option_lines('),
    'capacity is derived from live submissions and enforced after idempotency under the public form lock');
$capacityUsageStart = strpos($plugin, 'function fb_select_capacity_usage(');
$capacityUsageEnd = strpos($plugin, 'function fb_assert_capacity_configuration(', $capacityUsageStart ?: 0);
$capacityUsageSource = $capacityUsageStart !== false && $capacityUsageEnd !== false ? substr($plugin, $capacityUsageStart, $capacityUsageEnd - $capacityUsageStart) : '';
$check($capacityUsageSource !== '' && substr_count($capacityUsageSource, '$pdo->prepare(') === 1
    && str_contains($capacityUsageSource, 'COALESCE(SUM(')
    && !str_contains($capacityUsageSource, 'while ($row')
    && str_contains($plugin, 'function fb_assert_capacity_configuration(')
    && substr_count($ajax, 'fb_assert_capacity_configuration($pdo, $formId, $currentFields, $nextFields)') >= 2,
    'capacity rendering uses one live-submission query and canonical edits preserve occupied field identities');
$definitions = (string)file_get_contents($root . '/includes/definitions.php');
$draftHelpers = (string)file_get_contents($root . '/includes/visual-drafts.php');
$check(str_contains($plugin, 'function fb_assert_submission_field_key_transition(')
    && str_contains($plugin, "JSON_CONTAINS_PATH(data_json, 'one',")
    && str_contains($plugin, "JSON_CONTAINS_PATH(files_json, 'one',")
    && str_contains($plugin, 'deleted_at IS NOT NULL')
    && str_contains($ajax, "field_key = ?')")
    && str_contains($ajax, 'fb_submission_history_uses_field_keys($pdo, $formId, [$key])')
    && str_contains($ajax, 'fb_assert_submission_field_key_transition($pdo, $formId, $currentFields, $nextFields)')
    && str_contains($draftHelpers, 'fb_assert_submission_field_key_transition(')
    && str_contains($definitions, 'fb_assert_submission_field_key_transition(')
    && str_contains($definitions, 'DELETE FROM fb_fields WHERE form_id = ? AND deleted_at IS NULL'),
    'all canonical field-key mutations protect submission identities and definition replacement preserves the Bin');
$check(!str_contains((string)file_get_contents($root . '/plugin.php'), 'CREATE TABLE') && !str_contains((string)file_get_contents($root . '/plugin.php'), 'ALTER TABLE'), 'runtime entrypoint contains no DDL');
$adminIndex = (string)file_get_contents($root . '/admin/index.php');
$globalSettings = (string)file_get_contents($root . '/admin/global-settings.php');
$settings = (string)file_get_contents($root . '/admin/settings.php');
$visualBuilder = (string)file_get_contents($root . '/admin/visual-builder.php');
$visualPreview = (string)file_get_contents($root . '/admin/visual-preview.php');
$classicBuilder = (string)file_get_contents($root . '/admin/builder.php');
$classicCanvas = (string)file_get_contents($root . '/admin/_canvas.php');
$adminUi = (string)file_get_contents($root . '/admin/_ui.php');
$check(str_contains($adminIndex, 'admin/tools/form-builder/settings')
    && !str_contains($adminIndex, "\$act === 'save_recaptcha'")
    && !str_contains($adminIndex, "\$act === 'import_definition'")
    && !str_contains($adminIndex, 'id="fba-rc"')
    && !str_contains($adminIndex, 'id="fba-import"'),
    'the forms header links one dedicated Settings page without legacy global modals');
$check(str_contains($globalSettings, "adiwira_require_permission(\$pdo, 'plugin.form-builder.workspace.access'")
    && str_contains($globalSettings, "plugin.form-builder.global-settings.manage")
    && str_contains($globalSettings, "plugin.form-builder.definitions.manage")
    && str_contains($globalSettings, 'csrf_check($csrfInput)')
    && str_contains($globalSettings, 'type="password" name="secret"')
    && str_contains($globalSettings, 'value="" autocomplete="new-password"')
    && !str_contains($globalSettings, "value=\"<?= htmlspecialchars(\$keys['secret']")
    && str_contains($globalSettings, 'fba-more-menu')
    && str_contains($globalSettings, 'fba-portal')
    && str_contains($globalSettings, 'Form Lifecycle')
    && str_contains($plugin, 'function fb_recaptcha_configured(')
    && str_contains($plugin, 'function fb_acquire_recaptcha_config_lock(')
    && str_contains($plugin, 'function fb_recaptcha_active_form_count(')
    && str_contains($globalSettings, '$pdo->beginTransaction()')
    && str_contains($globalSettings, 'name="clear_keys"')
    && str_contains($globalSettings, 'fb_acquire_recaptcha_config_lock($pdo)')
    && str_contains($globalSettings, 'fb_recaptcha_active_form_count($pdo)')
    && str_contains($globalSettings, 'Enter the matching secret key when changing the site key.'),
    'global Settings independently authorizes mutations, protects stored secrets, and documents workspace controls');
$check(str_contains($adminUi, '.fba-head h1 { display: inline-flex;')
    && str_contains($adminUi, 'border-left: 4px solid var(--adam-accent)')
    && !str_contains($adminIndex, '<td data-col="updated" style="white-space:nowrap" class="fba-sub">')
    && !str_contains((string)file_get_contents($root . '/admin/submissions.php'), '<td style="white-space:nowrap" class="fba-sub">')
    && !str_contains((string)file_get_contents($root . '/admin/bin.php'), '<td style="white-space:nowrap" class="fba-sub">'),
    'outlined admin headings and nested secondary text preserve symmetric table-cell layout');
$referenceProviderAt = strpos($plugin, "register_editor_reference_provider('form-builder'");
$referenceProviderEnd = strpos($plugin, "if (function_exists('register_theme_section'))", $referenceProviderAt ?: 0);
$referenceProviderSource = $referenceProviderAt !== false && $referenceProviderEnd !== false
    ? substr($plugin, $referenceProviderAt, $referenceProviderEnd - $referenceProviderAt)
    : '';
$check(str_contains($plugin, "register_editor_reference_provider('form-builder'")
    && str_contains($plugin, "'syntax' => 'shortcode'")
    && str_contains($plugin, "'shortcode' => 'form'")
    && str_contains($plugin, "'attribute' => 'slug'")
    && str_contains($plugin, 'fb_can_access_form($pdo, $form, $uid)')
    && str_contains($plugin, 'admin/tools/form-builder/editor')
    && str_contains($plugin, "['page'=>'admin/tools/form-builder','scope'=>'archived']"),
    'Form shortcode references are permission-filtered and target the authorized Visual Builder route');
$check($referenceProviderSource !== ''
    && str_contains($referenceProviderSource, "\$columns = 'id, title, slug, status, created_by, access_json'")
    && !str_contains($referenceProviderSource, 'SELECT *')
    && str_contains($referenceProviderSource, 'array_chunk(array_keys($referencedSlugs), 200)')
    && str_contains($referenceProviderSource, '$scanned < 5000')
    && str_contains($referenceProviderSource, 'LIMIT {$batchSize}')
    && str_contains($referenceProviderSource, 'fb_form_access_granted(')
    && str_contains($referenceProviderSource, "'normalize' => 'exact'")
    && str_contains($referenceProviderSource, "'trim' => false"),
    'Form references prioritize current content, select bounded metadata, and reuse one runtime-equivalent ACL context');
$check(str_contains($adminUi, 'function fb_visual_builder_url(')
    && str_contains($adminIndex, 'fb_js_redirect(fb_visual_builder_url($newId))')
    && str_contains($adminIndex, '>Visual Builder</a>')
    && str_contains($adminIndex, '>Classic Builder</span>')
    && str_contains($adminIndex, "svg_ico('panel-top')")
    && str_contains($classicBuilder, 'Classic Builder:')
    && str_contains($classicBuilder, "svg_ico('panel-top', 'fba-heading-icon')")
    && substr_count($visualBuilder, "svg_ico('panel-top')") === 2
    && !str_contains($adminIndex, "svg_ico('layout-template')")
    && str_contains($classicBuilder, 'fb_visual_builder_url($formId)'),
    'new forms default to Visual Builder while Classic remains explicitly available with a Core icon');
$check(str_contains($settings, 'name="success_message"')
    && str_contains($settings, 'name="success_detail_field"')
    && str_contains($settings, 'name="success_message_case"')
    && str_contains($settings, 'placeholder="Your submission for {value} has been received."')
    && !str_contains($settings, 'Trial' . ' Class')
    && str_contains($settings, '{value}')
    && str_contains($settings, '{label}')
    && str_contains($visualBuilder, "'success_detail_field'")
    && str_contains($ajax, "\$formSettings['success_detail_field']"),
    'one configurable success and proof message preserves valid dynamic field references across both builders');
$check(str_contains($settings, 'name="submission_proof_enabled"')
    && str_contains($settings, 'name="submission_proof_format"')
    && str_contains($settings, '>PNG image</option>')
    && str_contains($settings, '>PDF document</option>'),
    'each form exposes explicit disabled-by-default PNG or PDF proof settings');
$check(str_contains($visualBuilder, "adiwira_require_permission(\$pdo, 'plugin.form-builder.workspace.access'")
    && str_contains($visualBuilder, 'fb_can_access_form($pdo, $form, $uid)')
    && str_contains($visualBuilder, 'id="fbvPreviewFrame"')
    && str_contains($visualBuilder, 'sandbox="allow-same-origin"')
    && !str_contains($visualBuilder, 'allow-scripts')
    && !str_contains($visualBuilder, 'allow-forms')
    && str_contains($visualBuilder, 'data-device="desktop"')
    && str_contains($visualBuilder, 'data-device="mobile"')
    && str_contains($visualBuilder, "const ENDPOINT = '/fb-visual-builder/'")
    && str_contains($visualBuilder, "window.addEventListener('fbv:draft-change'")
    && str_contains($visualBuilder, 'Open Classic Builder'),
    'Visual Builder is authorized, safely rendered, initially inert, responsive, autosave-ready, and Classic-compatible');
$check(str_contains($classicCanvas, 'content_editor_render_mount([')
    && str_contains($classicCanvas, "'name' => 's_upload_description_html'")
    && str_contains($classicBuilder, 'window.JyavaniEditor.mount(root')
    && str_contains($classicBuilder, 'uploadDescriptionEditor.sync()')
    && str_contains($classicBuilder, 'uploadDescriptionEditor.destroy()')
    && str_contains($visualBuilder, 'id="fbvUploadEditorParking"')
    && str_contains($visualBuilder, 'const mountUploadDescriptionEditor = () =>')
    && str_contains($visualBuilder, "document.readyState === 'loading'")
    && str_contains($visualBuilder, "document.addEventListener('DOMContentLoaded', () => {")
    && str_contains($visualBuilder, 'mountUploadDescriptionEditor();')
    && str_contains($visualBuilder, 'mountContentEditor();')
    && str_contains($visualBuilder, 'window.JyavaniEditor.mount(uploadEditorRoot')
    && str_contains($visualBuilder, 'String(event?.content ?? uploadDescriptionEditor.sync())')
    && str_contains($visualBuilder, "const mutableRecord = (value) => value && typeof value === 'object' && !Array.isArray(value) ? value : {}")
    && str_contains($visualBuilder, 'target.settings = mutableRecord(target.settings)')
    && str_contains($visualBuilder, "uploadEditorRoot?.addEventListener('input'")
    && str_contains($visualBuilder, "uploadEditorRoot?.addEventListener('focusout'")
    && str_contains($visualBuilder, "setStatus('Saving description before publish...', 'saving')")
    && str_contains($visualBuilder, 'uploadDescriptionEditor.setContent(')
    && str_contains($visualBuilder, 'uploadDescriptionEditor.destroy()')
    && str_contains($visualBuilder, 'if (event.persisted) return;'),
    'Classic and Visual upload descriptions use dependency-safe scoped Core editor mounts with BFCache-safe lifecycle management');
$check(str_contains($classicCanvas, 'name="v_max_files"')
    && str_contains($classicCanvas, 'name="s_preview_mode"')
    && str_contains($visualBuilder, 'data-upload-prop="max_files"')
    && str_contains($visualBuilder, 'data-upload-prop="preview_mode"')
    && str_contains($plugin, 'function fb_normalize_uploaded_files(')
    && str_contains($submit, 'fb_normalize_stored_attachments($storedMetadata)'),
    'both builders and submission runtime expose the configurable multi-file contract');
$check(str_contains($visualPreview, "user_can(\$pdo, \$uid, 'plugin.form-builder.workspace.access'")
    && str_contains($visualPreview, 'fb_can_access_form($pdo, $form, $uid)')
    && str_contains($visualPreview, "\$previewSettings['unsafe_code_enabled'] = false")
    && str_contains($visualPreview, "\$previewForm['css'] = null")
    && str_contains($visualPreview, "\$previewForm['js'] = null")
    && str_contains($visualPreview, 'fb_render_form($pdo, $previewForm)')
    && str_contains($visualPreview, "add_filter('layout_slot_html'")
    && str_contains($visualPreview, 'require $layoutPath')
    && str_contains($visualPreview, 'Cache-Control: private, no-store')
    && str_contains($visualPreview, 'X-Robots-Tag: noindex, nofollow'),
    'Visual preview uses the authorized Core public layout without executable form customizations');
$check(str_contains($draftHelpers, 'FOR UPDATE')
    && str_contains($draftHelpers, 'revision = ?')
    && str_contains($draftHelpers, 'hash_equals(')
    && str_contains($draftHelpers, 'fb_visual_merge_protected_code(')
    && str_contains($draftHelpers, 'fb_render_field_html('),
    'Visual drafts use row locks, optimistic revisions, canonical hashes, protected-code merging, and the safe public field renderer');
$check(str_contains($draftHelpers, 'function fb_visual_publish_draft(')
    && str_contains($draftHelpers, 'function fb_visual_reset_draft(')
    && str_contains($draftHelpers, 'function fb_visual_canonical_definition(')
    && str_contains($draftHelpers, 'fb_visual_replace_canonical(')
    && str_contains($draftHelpers, 'deleted_at IS NULL')
    && str_contains($draftHelpers, 'Reactivate this form as a draft before publishing it.')
    && str_contains($plugin, 'SELECT GET_LOCK(?, ?)')
    && str_contains((string)file_get_contents($root . '/admin/bin.php'), 'fb_acquire_form_mutation_lock($pdo, $formId)'),
    'transactional publishing preserves the field bin and serializes with Classic edits and restores');
$check(str_contains($draftHelpers, 'function fb_visual_attach_field_identities(')
    && str_contains($draftHelpers, 'function fb_visual_assert_field_identity_transition(')
    && str_contains($draftHelpers, '$currentByIdentity')
    && str_contains($draftHelpers, 'UPDATE fb_fields SET parent_id=')
    && str_contains($draftHelpers, 'UPDATE fb_fields SET deleted_at=NOW()')
    && str_contains($draftHelpers, 'fb_cascade_trashed_field_key_reference_map(')
    && str_contains($visualBuilder, '>Move to Bin</button>')
    && str_contains($visualBuilder, 'Open Field Bin'),
    'Visual deletion keeps historical identities recoverable while surviving canonical rows update in place');
$check(str_contains($submit, 'fb_json_encode((object)$data)')
    && str_contains($submit, 'fb_json_encode((object)$files)')
    && str_contains((string)file_get_contents($root . '/includes/submission-import.php'), 'fb_json_encode((object)$normalized[\'data\'])')
    && str_contains($plugin, "NOT IN ('OBJECT','ARRAY')")
    && str_contains($plugin, "'$[' . \$key . ']'"),
    'numeric field keys persist as JSON objects while legacy array-shaped submission maps remain protected');
$check(str_contains($draftHelpers, 'function fb_visual_definition_json_value(')
    && str_contains($draftHelpers, "\$translation['fields'] = (object)\$translation['fields']")
    && substr_count($visualAjax, 'fb_visual_draft_transport(') === 3,
    'Visual draft storage and transport preserve numeric translation field maps as JSON objects');
$check(str_contains($plugin, 'function fb_field_restore_dependency_error(')
    && str_contains($plugin, "['after_field', 'before_field']")
    && str_contains((string)file_get_contents($root . '/admin/bin.php'), 'fb_field_restore_dependency_error($pdo, $formId, $f)'),
    'field Bin restore requires active Country and Date dependencies before reactivation');
$check(str_contains($draftHelpers, 'function fb_visual_protected_code_hash(')
    && str_contains($draftHelpers, 'Unsafe-code permission is required to publish protected content changes.')
    && str_contains($visualAjax, 'catch (DomainException $error)')
    && str_contains($visualAjax, '], 403)'),
    'unprivileged editors cannot publish protected code changes hidden by draft redaction');
$definitionsSource = (string)file_get_contents($root . '/includes/definitions.php');
$binSource = (string)file_get_contents($root . '/admin/bin.php');
$check(str_contains($definitionsSource, 'fb_acquire_form_mutation_lock($pdo, (int)$lockFormId)')
    && substr_count($plugin, 'fb_acquire_form_mutation_lock($pdo, $fid)') >= 3
    && substr_count($binSource, 'fb_acquire_form_mutation_lock($pdo, $formId)') >= 3
    && str_contains($binSource, 'Field changed before it could be restored.')
    && str_contains($binSource, '$pdo->beginTransaction()'),
    'definition import, form lifecycle, and transactional Bin operations participate in mutation locking');
$submitSource = (string)file_get_contents($root . '/public/submit.php');
$settingsSource = (string)file_get_contents($root . '/admin/settings.php');
$check(substr_count($submitSource, 'fb_acquire_form_mutation_lock($pdo, (int)$formId') === 2
    && str_contains($submitSource, 'Form schema changed during submission.')
    && str_contains($settingsSource, 'fb_acquire_form_mutation_lock($pdo, $formId)')
    && str_contains($settingsSource, 'if ($canUnsafeCode) $settings[\'unsafe_code_enabled\'] = true;')
    && str_contains($binSource, 'A live field already uses this key.')
    && str_contains($binSource, 'AND form_id IN ({$placeholders})'),
    'public submissions detect schema swaps while settings and Bin operations honor form mutation locks');
$check(str_contains($plugin, 'function fb_recover_storage_trash(')
    && str_contains($plugin, 'function fb_merge_storage_tree(')
    && str_contains($plugin, "'/pending-' . \$fid")
    && str_contains($plugin, "'/ready-' . \$fid")
    && str_contains($plugin, 'fb_remove_storage_tree($readyStorage)')
    && str_contains($plugin, 'restore pending form storage after rollback'),
    'hard deletion stages scoped storage and leaves recoverable cleanup tombstones across failures');
$check(str_contains($visualBuilder, 'saveInFlight') && str_contains($visualBuilder, 'pendingDefinition')
    && str_contains($plugin, "DELETE FROM `fb_builder_drafts` WHERE form_id = ?"),
    'autosaves are serialized and hard deletion removes persisted drafts');
$check(str_contains($visualBuilder, 'data-fbv-type=')
    && str_contains($visualBuilder, 'const addField = (type)')
    && str_contains($visualBuilder, 'const renderInspector = ()')
    && str_contains($visualBuilder, 'data-fbv-delete')
    && str_contains($visualBuilder, 'const bindPreview = ()')
    && str_contains($visualBuilder, 'previewFrame.contentDocument')
    && str_contains($visualBuilder, 'element.inert = true'),
    'Visual Builder exposes draft-only add, select, inspect, and delete interactions after initialization');
$check(str_contains($visualBuilder, 'const renderOptionEditor = (field)')
    && str_contains($visualBuilder, 'data-option-prop="label"')
    && str_contains($visualBuilder, 'data-option-prop="value"')
    && str_contains($visualBuilder, 'data-option-toggle="price"')
    && str_contains($visualBuilder, 'data-option-toggle="capacity"')
    && str_contains($visualBuilder, 'data-option-add')
    && str_contains($visualBuilder, 'data-option-remove')
    && str_contains($visualBuilder, 'Kebidanan : "Midwife Challenge"')
    && !str_contains($visualBuilder, 'data-field-options')
    && !str_contains($visualBuilder, 'value|Label|price'),
    'Visual Builder edits labels, values, prices, and optional capacities with structured controls');
$check(str_contains($visualBuilder, 'const renderValidationControls = (field)')
    && str_contains($visualBuilder, 'data-validation-prop="maxlength"')
    && str_contains($visualBuilder, 'data-validation-prop="pattern"')
    && str_contains($visualBuilder, 'const renderCountryFieldControl = (field)')
    && str_contains($visualBuilder, 'data-country-field required')
    && str_contains($visualBuilder, 'const renderAlignmentControls = (field)')
    && str_contains($visualBuilder, 'data-setting-prop="align"')
    && str_contains($visualBuilder, 'data-setting-prop="valign"')
    && str_contains($visualBuilder, "delete field.settings[setting]"),
    'Visual Builder exposes detailed validation, linked Country selection, and reversible alignment controls');
$check(str_contains($visualBuilder, 'const renderImageBlockEditor = (field)')
    && str_contains($visualBuilder, 'data-image-pick')
    && str_contains($visualBuilder, 'data-image-prop="url"')
    && str_contains($visualBuilder, 'data-image-prop="alt"')
    && str_contains($visualBuilder, 'data-image-prop="caption"')
    && str_contains($visualBuilder, 'data-image-prop="width"')
    && str_contains($visualBuilder, 'window.openMediaSelector({')
    && str_contains($visualBuilder, 'visibility=public')
    && str_contains($visualBuilder, "surface: 'plugin.form-builder.visual'")
    && str_contains($visualBuilder, "selection_mode: 'immediate'")
    && str_contains($visualBuilder, 'typeof window.normalizeMedia')
    && str_contains($visualBuilder, "['visibility', 'storage_disk', 'access_scope']")
    && str_contains($visualBuilder, "values.url.startsWith('/private/')")
    && str_contains($visualBuilder, "!/^https?:\\/\\//i.test(values.url)"),
    'Visual Builder edits Image Block media and metadata through the scoped Core Gallery selector');
$check(str_contains($visualBuilder, "'id' => 'fbv-content-editor'")
    && str_contains($visualBuilder, "'initial_mode' => 'codemirror'")
    && str_contains($visualBuilder, 'const renderContentEditorControl = (field)')
    && str_contains($visualBuilder, 'window.JyavaniEditor.mount(contentEditorRoot')
    && str_contains($visualBuilder, "resourceType: 'visual-protected-content'")
    && str_contains($visualBuilder, "await contentEditor.setMode('codemirror')")
    && str_contains($visualBuilder, "field.type === 'richtext' && !contentEditor.isComplex()")
    && str_contains($visualBuilder, "quillMode.disabled = field.type === 'raw_html'")
    && str_contains($visualBuilder, 'definition.form.settings.unsafe_code_enabled = true')
    && str_contains($visualBuilder, 'syncContentEditor()')
    && str_contains($visualBuilder, 'if (contentEditor) contentEditor.destroy()')
    && str_contains($visualBuilder, "setStatus(error.conflict ? 'Reset conflict - reload the page'")
    && str_contains($visualBuilder, "pickFile: () => Promise.reject")
    && str_contains($visualBuilder, 'const pickPublicMedia = (context = {}) =>')
    && str_contains($visualBuilder, 'pickMedia: (request) => pickPublicMedia('),
    'Visual rich/raw fields reuse one permission-bound Core Quill/CodeMirror mount with public media, autosave, and lifecycle safety');
$check(str_contains($ajax, '$validation = fb_field_validation($n);')
    && str_contains($ajax, "unset(\$validation['min'], \$validation['max']);")
    && str_contains($ajax, "unset(\$validation['maxlength'], \$validation['pattern']);"),
    'Classic field saves preserve validation rules that are owned only by Visual Builder');
$check(str_contains($ajax, "if (\$type === 'date' && \$key !== \$n['field_key'])")
    && str_contains($visualBuilder, "event.target.matches('[data-validation-prop]')")
    && str_contains($visualBuilder, 'let hasPendingControlChanges = false')
    && str_contains($visualBuilder, '!pendingDefinition && workingDefinition === definition && !hasPendingControlChanges')
    && str_contains($visualBuilder, 'hasUnsavedChanges = Boolean(pendingDefinition || saveInFlight);'),
    'Classic renames preserve imported date references while Visual validation edits participate safely in unsaved-state protection');
$check(str_contains($visualBuilder, '.fbv-field-picker-shell')
    && str_contains($visualBuilder, '.fbv-field-picker-control::after')
    && str_contains($visualBuilder, 'grid-template-columns: 224px minmax(360px, 1fr) 370px')
    && str_contains($visualBuilder, 'Pick any question directly, including hidden fields.'),
    'Visual Builder field picker and right inspector use dedicated responsive styling');
$check(str_contains($visualBuilder, 'Object.defineProperty(translated, option.value')
    && str_contains($visualBuilder, "optionInput.value = String(previousValue ?? '')")
    && str_contains($visualBuilder, "optionInput.select === 'function'")
    && str_contains($visualBuilder, 'priceToggle?.focus({ preventScroll: true })')
    && substr_count($visualBuilder, 'focus({ preventScroll: true })') >= 3,
    'structured option edits preserve translations, visibly reject invalid input, and restore keyboard focus');
$check(str_contains($visualBuilder, 'data-field-key')
    && str_contains($visualBuilder, "candidate.parent === oldKey")
    && str_contains($visualBuilder, "candidate.settings.country_field === oldKey")
    && str_contains($visualBuilder, "['after_field', 'before_field']")
    && str_contains($visualBuilder, "['confirmation_email_field', 'reply_to_email_field', 'success_detail_field']")
    && str_contains($visualBuilder, 'Object.defineProperty(translatedFields, nextKey')
    && str_contains($visualBuilder, "setStatus('Field key changed - checking submission history'"),
    'Visual field-key edits validate locally and atomically cascade every definition reference before server history checks');
$check(str_contains($visualBuilder, 'id="fbvToggleLeft"')
    && str_contains($visualBuilder, 'id="fbvToggleRight"')
    && str_contains($visualBuilder, 'aria-controls="fbvQuestionLibrary"')
    && str_contains($visualBuilder, 'aria-controls="fbvQuestionProperties"')
    && str_contains($visualBuilder, "svg_ico('chevron-left', 'fbv-panel-icon')")
    && str_contains($visualBuilder, "svg_ico('chevron-right', 'fbv-panel-icon')")
    && !str_contains($visualBuilder, "toggle.textContent = side === 'left'")
    && str_contains($visualBuilder, 'const setPanelHidden = (side, hidden, persist = true)')
    && str_contains($visualBuilder, 'fbv_left_hidden')
    && str_contains($visualBuilder, 'fbv_right_hidden')
    && str_contains($visualBuilder, '--fbv-canvas-width: 1220px'),
    'Visual Builder side panels collapse independently, persist their state, and widen the canvas');
$check(str_contains($draftHelpers, 'data-fbv-row=')
    && str_contains($draftHelpers, 'data-fbv-col=')
    && str_contains($visualBuilder, 'id="fbvAddRow"')
    && str_contains($visualBuilder, 'const setRowColumns = async (rowKey, columnCount)')
    && str_contains($visualBuilder, 'const moveRow = (rowKey, delta)')
    && str_contains($visualBuilder, 'const moveField = (fieldKey, parentKey, beforeKey = null)')
    && str_contains($visualBuilder, "preview.addEventListener('dragstart'")
    && str_contains($visualBuilder, 'data-field-move="up"'),
    'Visual layout supports row/column management, pointer drag-and-drop, and accessible field reordering');
$check(str_contains($visualBuilder, 'pendingDefinition = pendingDefinition || workingDefinition')
    && str_contains($visualBuilder, 'let retryTimer = 0')
    && str_contains($visualBuilder, 'column${count === 1 ? \'\' : \'s\'}${count < 1 || count > 4 ? \' (advanced)\' : \'\'}')
    && str_contains($visualBuilder, 'Convert this ${existingCount}-column advanced row')
    && !str_contains($visualBuilder, '.fbv-status { display: none; }'),
    'failed autosaves retain queued edits while legacy layouts and mobile status remain explicit');
$check(str_contains($visualBuilder, 'let workingDefinition = null')
    && str_contains($visualBuilder, 'workingDefinition === definition')
    && substr_count($visualBuilder, 'definition !== workingDefinition') === 2
    && str_contains($visualBuilder, 'id="fbvFieldPicker"')
    && str_contains($visualBuilder, "window.addEventListener('beforeunload'")
    && !str_contains($visualBuilder, '.fbv-preview form, .fbv-preview button'),
    'Visual editing preserves the live working copy, exposes hidden fields, warns on unsaved navigation, and keeps fields selectable');
$check(str_contains($visualBuilder, 'id="fbvPublish"')
    && str_contains($visualBuilder, "request('publish'")
    && str_contains($visualBuilder, "request('reset'")
    && str_contains($visualBuilder, 'has_unpublished_changes'),
    'Visual Builder enables publishing only for synchronized saved changes and exposes explicit Classic conflict reset');
$check(str_contains($visualBuilder, 'const syncHeader = (definition)')
    && str_contains($visualBuilder, 'id="fbvFormTitle"')
    && str_contains($visualBuilder, 'id="fbvFormSlug"'),
    'publish and Classic reset keep Visual Builder header metadata synchronized');
$check(str_contains($plugin, 'function fb_normalize_slug(')
    && str_contains($plugin, 'function fb_unique_form_slug(')
    && substr_count($adminIndex, 'fb_unique_form_slug(') === 2
    && str_contains($settings, '$slug = fb_unique_form_slug('),
    'form creation, duplication, and settings preserve valid hyphenated slugs');
$check(str_contains($plugin, 'function fb_render_embed(')
    && str_contains($plugin, "'draft' => 'Form Draft'")
    && str_contains($plugin, "'archived' => 'Form Archived'")
    && str_contains($plugin, "'trashed' => 'Form Trashed'")
    && str_contains($plugin, "default => 'Form Not Found'")
    && str_contains($plugin, "user_can(\$pdo, \$uid, 'plugin.form-builder.workspace.access')")
    && str_contains($plugin, "user_can(\$pdo, \$uid, 'plugin.form-builder.forms.manage-any')")
    && substr_count($plugin, 'return fb_render_embed(') === 3,
    'inactive embed diagnostics are editor-only and shared by shortcodes, Theme Sections, and Theme Zones');
$check(str_contains($plugin, "empty(\$form['deleted_at']) && (\$form['status'] ?? '') === 'active'")
    && str_contains($submit, "!empty(\$form['deleted_at']) || (\$form['status'] ?? '') !== 'active'")
    && str_contains($submit, "deleted_at IS NULL AND status = 'active' FOR UPDATE"),
    'trashed forms cannot render publicly or accept submissions even if their prior status was active');
$check(str_contains($plugin, 'function fb_archive_form(')
    && str_contains($plugin, 'function fb_reactivate_form_as_draft(')
    && str_contains($plugin, "status IN ('active','draft')")
    && str_contains($plugin, "status = 'archived'")
    && str_contains($adminIndex, "\$scope === 'archived'")
    && str_contains($adminIndex, 'Reactivate as draft')
    && str_contains($adminIndex, 'fb_reactivate_form_as_draft($pdo, $target)')
    && str_contains($adminIndex, 'fb_archive_form($pdo, $target)')
    && str_contains($settings, 'Use the Archived forms view to reactivate this form safely as a draft.')
    && str_contains($definitionsSource, "\$nextStatus = \$row && (\$row['status'] ?? '') === 'archived' ? 'archived'"),
    'archive is a locked visible lifecycle with a separate archived view and safe draft reactivation');
$check(str_contains($publicRenderer, 'class="g-recaptcha"')
    && str_contains($publicRenderer, 'https://www.google.com/recaptcha/api.js')
    && str_contains($publicRenderer, '$recaptchaScriptPrinted')
    && !str_contains($publicRenderer, 'recaptcha/api.js" async')
    && str_contains($settings, 'Configure both global reCAPTCHA keys before enabling reCAPTCHA for this form.')
    && str_contains($settings, 'Configure both global reCAPTCHA keys before activating this form.')
    && str_contains($definitionsSource, 'Configure both global reCAPTCHA keys before importing an enabled form.')
    && str_contains($draftHelpers, 'Configure both global reCAPTCHA keys before publishing this form.')
    && str_contains($plugin, "\$restoreStatus === 'active' && \$restoreSettings['recaptcha'] === '1'")
    && str_contains($settings, 'fb_acquire_recaptcha_config_lock($pdo)')
    && str_contains($definitionsSource, 'fb_acquire_recaptcha_config_lock($pdo)')
    && str_contains($draftHelpers, 'fb_acquire_recaptcha_config_lock($pdo)')
    && strpos($submit, "'captcha_f' . \$formId") < strpos($submit, 'fb_recaptcha_verify(')
    && strpos($submit, 'SELECT reference_code FROM fb_submissions WHERE form_id = ? AND idempotency_key = ? LIMIT 1') < strpos($submit, 'fb_recaptcha_verify('),
    'configured per-form reCAPTCHA renders one v2 runtime and cannot be enabled without both global keys');
$check(str_contains($plugin, 'function fb_can_view_submissions(')
    && str_contains($plugin, "['submissions']['roles']")
    && str_contains($settings, 'name="submission_users[]"'),
    'per-form submission viewers are distinct from form editors');
$check(str_contains($adminIndex, 'fb_can_view_submissions($pdo, $form, $uid)')
    && !str_contains($adminIndex, "if (\$view === 'submissions' && !user_can"),
    'submission routes enforce the scoped form guard instead of a global-only gate');
$submissions = (string)file_get_contents($root . '/admin/submissions.php');
$check(str_contains($submissions, 'if (!$canManageSubmissions)')
    && str_contains($submissions, 'if ($canManageSubmissions):'),
    'scoped submission viewers cannot invoke or see destructive controls');
$importSource = (string)file_get_contents($root . '/includes/submission-import.php');
$check(str_contains($submissions, "case 'restore':")
    && str_contains($submissions, 'fb_acquire_form_mutation_lock($pdo, $formId)')
    && str_contains($submissions, 'fb_select_capacity_errors($pdo, $formId, $restoreFields, $restoreData, $restoreSettings)')
    && str_contains($importSource, 'fb_acquire_form_mutation_lock($pdo, $formId)')
    && str_contains($importSource, 'fb_select_capacity_errors($pdo, $formId, $fields'),
    'restore and legacy import share transactional capacity enforcement while trash and delete release derived usage');
$check(str_contains($submissions, "\$_POST['fb_action'] ?? \$_POST['fb_bulk_action'] ?? ''")
    && str_contains($submissions, 'name="fb_bulk_action" aria-label="Bulk action"')
    && str_contains($submissions, "preg_match('/\\A(trash|restore|delete):([1-9][0-9]*)\\z/D'")
    && str_contains($submissions, 'name="fb_row_action" value="trash:<?= $sid ?>"')
    && !str_contains($submissions, "this.closest('tr').querySelector('.fba-row-check').checked=true"),
    'per-row submission actions bind an exact row without JavaScript and take precedence over the separate bulk selector');
$check(str_contains($submissions, 'type="button" class="field-help__trigger"')
    && str_contains($submissions, 'aria-describedby="fba-workflow-status-help"')
    && str_contains($submissions, 'aria-controls="fba-workflow-status-help"')
    && substr_count($submissions, 'id="fba-workflow-status-help"') === 1
    && str_contains($submissions, 'class="field-help__tooltip" role="tooltip"')
    && str_contains($submissions, "__('Changes the workflow stage for every checked submission. It does not mark items read, move them to trash, or run the separate Bulk action.')"),
    'workflow bulk action uses the accessible Core tooltip and explains its independent behavior');
$check(str_contains($submissions, '!is_string($csrfInput)') && str_contains($submissions, '!is_string($exportCsrf)')
    && str_contains($submissions, '$act = is_string($actionInput) ? $actionInput :'),
    'submission mutations reject malformed non-scalar CSRF and action inputs');
$check(str_contains($adminIndex, "\$_POST['fb_action'] ?? '') === 'export'")
    && str_contains($submissions, "['xlsx', 'csv']")
    && str_contains($submissions, 'csrf_check($exportCsrf)')
    && str_contains($submissions, 'name="action" value="export"')
    && !str_contains($submissions, "(\$_GET['action'] ?? '') === 'export'"),
    'submission exports enter the Core raw-response dispatcher and require POST, CSRF, an allowlisted format, and the scoped route guard');
$check(str_contains($submissions, 'setCellValueExplicit(') && str_contains($submissions, "freezePane('A2')")
    && str_contains($submissions, 'setAutoFilter(') && str_contains($submissions, "setFormatCode('dd/mm/yyyy hh:mm')")
    && str_contains($submissions, 'getHyperlink()->setUrl($attachmentUrl)')
    && str_contains($submissions, 'admin login required')
    && str_contains($importSource, 'fb_normalize_stored_attachments('),
    'Excel exports preserve text safety, format staff data, and link each private attachment through the authorized admin endpoint');
$check(str_contains($submissions, 'fba-detail-layout') && !str_contains($submissions, '<div class="fba-overlay" onclick=')
    && !str_contains($submissions, '<main>')
    && !str_contains($submissions, "<?php return; endif; ?>\n?>")
    && str_contains((string)file_get_contents($root . '/admin/_ui.php'), "'workflow' => \$_GET['workflow']"),
    'submission detail is a responsive dedicated view that preserves list filter context without leaking a PHP closing tag');
$menuStart = strpos($adminIndex, '<div class="fba-more-menu">');
$menuEnd = strpos($adminIndex, '</div>', $menuStart);
$menu = $menuStart !== false && $menuEnd !== false ? substr($adminIndex, $menuStart, $menuEnd - $menuStart) : '';
$check($menu !== '' && !preg_match('/[📋⚙⧉🗄🗑]/u', $menu) && str_contains($menu, "svg_ico('clipboard-list')") && str_contains($menu, "svg_ico('trash-2')"), 'overflow actions use Core Lucide icons without emoji symbols');
$adminConfirmSource = '';
foreach (glob($root . '/admin/*.php') ?: [] as $adminFile) $adminConfirmSource .= (string)file_get_contents($adminFile);
$adminUi = (string)file_get_contents($root . '/admin/_ui.php');
$check(preg_match('/(?:window\.)?(?:confirm|alert)\s*\(/', $adminConfirmSource) !== 1
    && str_contains($adminUi, 'window.NewNotifConfirm')
    && str_contains($adminUi, 'window.FormBuilderConfirm')
    && str_contains($adminUi, "focus: 'cancel'")
    && str_contains($adminUi, 'if (!component) return false')
    && str_contains($adminUi, 'confirmationQueue = decision.then')
    && str_contains($adminUi, 'var submitter = event.submitter')
    && str_contains($adminUi, 'queueMicrotask(function () { bypassedForms.delete(form); })'),
    'all Form Builder confirmations use the accessible Core component and fail closed without native dialogs');
$check(str_contains($submissions, 'data-fb-confirm-title="Delete submission permanently"')
    && str_contains($submissions, "title: 'Delete submissions permanently'")
    && str_contains($submissions, "submitter.name === 'fb_row_action' || submitter.name === 'fb_action'")
    && str_contains((string)file_get_contents($root . '/admin/bin.php'), 'data-fb-confirm-variant="danger"')
    && str_contains($visualBuilder, 'await window.FormBuilderConfirm(')
    && str_contains($visualBuilder, 'await waitForSaveIdle()'),
    'destructive forms, submission deletion, and Visual Builder decisions use the shared confirmation adapter');
$triggerStart = strpos($adminIndex, '<summary class="fba-btn sm"');
$triggerEnd = strpos($adminIndex, '</summary>', $triggerStart);
$trigger = $triggerStart !== false && $triggerEnd !== false ? substr($adminIndex, $triggerStart, $triggerEnd - $triggerStart) : '';
$check($trigger !== '' && !str_contains($trigger, "svg_ico('menu'") && substr_count($trigger, '<circle cx=') === 3, 'compact overflow trigger uses a three-dot ellipsis instead of a hamburger');
$check(str_contains($adminIndex, 'firstAction.focus({ preventScroll: true })') && str_contains($adminIndex, "e.key === 'Escape'"), 'portaled overflow actions preserve keyboard access and focus restoration');
$check(str_contains($submissions, 'fba-submission-summary') && str_contains($submissions, 'fba-filter-form') && str_contains($submissions, 'fba-bulk-actions'), 'submissions workspace uses dedicated styled UI primitives');
if ($failures) { fwrite(STDERR, 'Security contract failed: ' . implode('; ', $failures) . "\n"); exit(1); }
echo "RESULT: ALL PASS\n";
