<?php
declare(strict_types=1);

$root = dirname(__DIR__); $failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n"; if (!$ok) $failures[] = $message; };
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$permissions = array_column($manifest['permissions'] ?? [], null, 'key');
$check(($manifest['version'] ?? null) === '1.7.3' && ($manifest['requires']['jyavani'] ?? null) === '>=2.3.122' && ($manifest['store']['url'] ?? null) === 'https://jyavani.com/plugin-store', 'release identity, Core requirement, and Store endpoint are exact');
$check(array_diff(['pdo','pdo_mysql','fileinfo','dom','json','mbstring'], $manifest['requires']['extensions'] ?? []) === [] && !in_array('zip', $manifest['requires']['extensions'] ?? [], true), 'runtime extensions are complete without obsolete DOCX zip dependency');
foreach (['submissions.manage','workflow.manage','definitions.manage','unsafe-code.manage'] as $suffix) $check(isset($permissions['plugin.form-builder.' . $suffix]), 'narrow permission ' . $suffix . ' is declared');
$check(is_file($root . '/migrations/0001-baseline.sql') && is_file($root . '/migrations/0002-submission-workflow.php') && count(glob($root . '/migrations/*') ?: []) === 2, 'only final append-only migration names ship');
$builder = (string)file_get_contents($root . '/tools/build-package.php');
$check(!str_contains($builder, "'form-builder/' .") && str_contains($builder, "str_replace(DIRECTORY_SEPARATOR, '/', \$relative)"), 'package builder writes plugin.json at the archive root');
$check(str_contains($builder, "str_starts_with(\$relative, 'tests/')"), 'release package excludes environment-specific test files');
$ajax = (string)file_get_contents($root . '/admin/ajax.php');
$check(!str_contains($ajax, "'Server error: ' . \$e->getMessage()") && str_contains($ajax, 'error_log('), 'admin AJAX logs detail and returns no raw exception');
$submit = (string)file_get_contents($root . '/public/submit.php');
$check(!preg_match('/(?<!jy_)mail\s*\(/', $submit) && strpos($submit, '$pdo->commit()') < strpos($submit, 'jy_mail_send'), 'Core mail executes only after persistence');
$check(!str_contains($submit, 'HTTP_X_FORWARDED_FOR') && str_contains($submit, 'do_action_isolated'), 'submission path retains trusted IP and isolated observer contracts');
$check(!str_contains((string)file_get_contents($root . '/plugin.php'), 'CREATE TABLE') && !str_contains((string)file_get_contents($root . '/plugin.php'), 'ALTER TABLE'), 'runtime entrypoint contains no DDL');
$adminIndex = (string)file_get_contents($root . '/admin/index.php');
$menuStart = strpos($adminIndex, '<div class="fba-more-menu">');
$menuEnd = strpos($adminIndex, '</div>', $menuStart);
$menu = $menuStart !== false && $menuEnd !== false ? substr($adminIndex, $menuStart, $menuEnd - $menuStart) : '';
$check($menu !== '' && !preg_match('/[📋⚙⧉🗄🗑]/u', $menu) && str_contains($menu, "svg_ico('clipboard-list')") && str_contains($menu, "svg_ico('trash-2')"), 'overflow actions use Core Lucide icons without emoji symbols');
$triggerStart = strpos($adminIndex, '<summary class="fba-btn sm"');
$triggerEnd = strpos($adminIndex, '</summary>', $triggerStart);
$trigger = $triggerStart !== false && $triggerEnd !== false ? substr($adminIndex, $triggerStart, $triggerEnd - $triggerStart) : '';
$check($trigger !== '' && !str_contains($trigger, "svg_ico('menu'") && substr_count($trigger, '<circle cx=') === 3, 'compact overflow trigger uses a three-dot ellipsis instead of a hamburger');
$check(str_contains($adminIndex, 'firstAction.focus({ preventScroll: true })') && str_contains($adminIndex, "e.key === 'Escape'"), 'portaled overflow actions preserve keyboard access and focus restoration');
$submissions = (string)file_get_contents($root . '/admin/submissions.php');
$check(str_contains($submissions, 'fba-submission-summary') && str_contains($submissions, 'fba-filter-form') && str_contains($submissions, 'fba-bulk-actions'), 'submissions workspace uses dedicated styled UI primitives');
if ($failures) { fwrite(STDERR, 'Security contract failed: ' . implode('; ', $failures) . "\n"); exit(1); }
echo "RESULT: ALL PASS\n";
