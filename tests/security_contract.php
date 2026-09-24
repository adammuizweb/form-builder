<?php
declare(strict_types=1);

$root = dirname(__DIR__); $failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n"; if (!$ok) $failures[] = $message; };
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$permissions = array_column($manifest['permissions'] ?? [], null, 'key');
$composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
$lock = json_decode((string)file_get_contents($root . '/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
$lockedPackages = array_column($lock['packages'] ?? [], 'version', 'name');
$check(($manifest['version'] ?? null) === '1.7.9' && ($manifest['requires']['jyavani'] ?? null) === '>=2.3.122' && ($manifest['store']['url'] ?? null) === 'https://jyavani.com/plugin-store', 'release identity, Core requirement, and Store endpoint are exact');
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
$check(is_file($root . '/migrations/0001-baseline.sql') && is_file($root . '/migrations/0002-submission-workflow.php') && is_file($root . '/migrations/0003-visual-builder-drafts.php') && count(glob($root . '/migrations/*') ?: []) === 3, 'only final append-only migration names ship');
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
$check(!preg_match('/(?<!jy_)mail\s*\(/', $submit) && strpos($submit, '$pdo->commit()') < strpos($submit, 'jy_mail_send'), 'Core mail executes only after persistence');
$check(!str_contains($submit, 'HTTP_X_FORWARDED_FOR') && str_contains($submit, 'do_action_isolated'), 'submission path retains trusted IP and isolated observer contracts');
$check(str_contains($submit, 'if ($uploadFields !== [])')
    && str_contains($submit, 'fb_prepare_files_base_dir($form)')
    && str_contains($submit, 'chmod($path, 0640)'),
    'public submission provisions scoped private storage only when validated uploads are present');
$check(!str_contains((string)file_get_contents($root . '/plugin.php'), 'CREATE TABLE') && !str_contains((string)file_get_contents($root . '/plugin.php'), 'ALTER TABLE'), 'runtime entrypoint contains no DDL');
$adminIndex = (string)file_get_contents($root . '/admin/index.php');
$plugin = (string)file_get_contents($root . '/plugin.php');
$settings = (string)file_get_contents($root . '/admin/settings.php');
$visualBuilder = (string)file_get_contents($root . '/admin/visual-builder.php');
$classicBuilder = (string)file_get_contents($root . '/admin/builder.php');
$adminUi = (string)file_get_contents($root . '/admin/_ui.php');
$check(str_contains($adminUi, 'function fb_visual_builder_url(')
    && str_contains($adminIndex, 'fb_js_redirect(fb_visual_builder_url($newId))')
    && str_contains($adminIndex, '>Visual Builder</a>')
    && str_contains($adminIndex, '>Classic Builder</span>')
    && str_contains($classicBuilder, 'Classic Builder:')
    && str_contains($classicBuilder, 'fb_visual_builder_url($formId)'),
    'new forms default to Visual Builder while Classic remains explicitly available');
$check(str_contains($visualBuilder, "adiwira_require_permission(\$pdo, 'plugin.form-builder.workspace.access'")
    && str_contains($visualBuilder, 'fb_can_access_form($pdo, $form, $uid)')
    && str_contains($visualBuilder, 'fb_render_form($pdo, $previewForm)')
    && str_contains($visualBuilder, 'class="fbv-preview" inert')
    && str_contains($visualBuilder, 'data-device="desktop"')
    && str_contains($visualBuilder, 'data-device="mobile"')
    && str_contains($visualBuilder, "const ENDPOINT = '/fb-visual-builder/'")
    && str_contains($visualBuilder, "window.addEventListener('fbv:draft-change'")
    && str_contains($visualBuilder, 'Open Classic Builder'),
    'Visual Builder is authorized, safely rendered, initially inert, responsive, autosave-ready, and Classic-compatible');
$draftHelpers = (string)file_get_contents($root . '/includes/visual-drafts.php');
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
    && str_contains($plugin, 'SELECT GET_LOCK(?, ?)')
    && str_contains((string)file_get_contents($root . '/admin/bin.php'), 'fb_acquire_form_mutation_lock($pdo, $formId)'),
    'transactional publishing preserves the field bin and serializes with Classic edits and restores');
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
    && str_contains($visualBuilder, 'preview.removeAttribute(\'inert\')'),
    'Visual Builder exposes draft-only add, select, inspect, and delete interactions after initialization');
$check(str_contains($draftHelpers, 'data-fbv-row=')
    && str_contains($draftHelpers, 'data-fbv-col=')
    && str_contains($visualBuilder, 'id="fbvAddRow"')
    && str_contains($visualBuilder, 'const setRowColumns = (rowKey, columnCount)')
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
    && str_contains($submit, "!empty(\$form['deleted_at']) || (\$form['status'] ?? '') !== 'active'"),
    'trashed forms cannot render publicly or accept submissions even if their prior status was active');
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
$check(str_contains($adminIndex, "\$_POST['fb_action'] ?? '') === 'export'")
    && str_contains($submissions, "['xlsx', 'csv']")
    && str_contains($submissions, 'csrf_check((string)($_POST')
    && str_contains($submissions, 'name="action" value="export"')
    && !str_contains($submissions, "(\$_GET['action'] ?? '') === 'export'"),
    'submission exports enter the Core raw-response dispatcher and require POST, CSRF, an allowlisted format, and the scoped route guard');
$check(str_contains($submissions, 'setCellValueExplicit(') && str_contains($submissions, "freezePane('A2')")
    && str_contains($submissions, 'setAutoFilter(') && str_contains($submissions, "setFormatCode('dd/mm/yyyy hh:mm')"),
    'Excel exports preserve text safety and apply staff-friendly worksheet formatting');
$check(str_contains($submissions, 'fba-detail-layout') && !str_contains($submissions, '<div class="fba-overlay" onclick=')
    && !str_contains($submissions, '<main>')
    && !str_contains($submissions, "<?php return; endif; ?>\n?>")
    && str_contains((string)file_get_contents($root . '/admin/_ui.php'), "'workflow' => \$_GET['workflow']"),
    'submission detail is a responsive dedicated view that preserves list filter context without leaking a PHP closing tag');
$menuStart = strpos($adminIndex, '<div class="fba-more-menu">');
$menuEnd = strpos($adminIndex, '</div>', $menuStart);
$menu = $menuStart !== false && $menuEnd !== false ? substr($adminIndex, $menuStart, $menuEnd - $menuStart) : '';
$check($menu !== '' && !preg_match('/[📋⚙⧉🗄🗑]/u', $menu) && str_contains($menu, "svg_ico('clipboard-list')") && str_contains($menu, "svg_ico('trash-2')"), 'overflow actions use Core Lucide icons without emoji symbols');
$triggerStart = strpos($adminIndex, '<summary class="fba-btn sm"');
$triggerEnd = strpos($adminIndex, '</summary>', $triggerStart);
$trigger = $triggerStart !== false && $triggerEnd !== false ? substr($adminIndex, $triggerStart, $triggerEnd - $triggerStart) : '';
$check($trigger !== '' && !str_contains($trigger, "svg_ico('menu'") && substr_count($trigger, '<circle cx=') === 3, 'compact overflow trigger uses a three-dot ellipsis instead of a hamburger');
$check(str_contains($adminIndex, 'firstAction.focus({ preventScroll: true })') && str_contains($adminIndex, "e.key === 'Escape'"), 'portaled overflow actions preserve keyboard access and focus restoration');
$check(str_contains($submissions, 'fba-submission-summary') && str_contains($submissions, 'fba-filter-form') && str_contains($submissions, 'fba-bulk-actions'), 'submissions workspace uses dedicated styled UI primitives');
if ($failures) { fwrite(STDERR, 'Security contract failed: ' . implode('; ', $failures) . "\n"); exit(1); }
echo "RESULT: ALL PASS\n";
