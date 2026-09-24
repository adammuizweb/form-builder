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
$check(is_file($root . '/migrations/0001-baseline.sql') && is_file($root . '/migrations/0002-submission-workflow.php') && count(glob($root . '/migrations/*') ?: []) === 2, 'only final append-only migration names ship');
$builder = (string)file_get_contents($root . '/tools/build-package.php');
$check(!str_contains($builder, "'form-builder/' .") && str_contains($builder, "str_replace(DIRECTORY_SEPARATOR, '/', \$relative)"), 'package builder writes plugin.json at the archive root');
$check(str_contains($builder, "str_starts_with(\$relative, 'tests/')"), 'release package excludes environment-specific test files');
$ajax = (string)file_get_contents($root . '/admin/ajax.php');
$check(!str_contains($ajax, "'Server error: ' . \$e->getMessage()") && str_contains($ajax, 'error_log('), 'admin AJAX logs detail and returns no raw exception');
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
    && str_contains($visualBuilder, 'fb_render_form($pdo, $form)')
    && str_contains($visualBuilder, 'class="fbv-preview" inert')
    && str_contains($visualBuilder, 'data-device="desktop"')
    && str_contains($visualBuilder, 'data-device="mobile"')
    && str_contains($visualBuilder, 'Open Classic Builder'),
    'Visual Builder foundation is authorized, canonical-rendered, inert, responsive, and Classic-compatible');
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
