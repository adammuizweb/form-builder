<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    if (!$ok) $failures[] = $message;
};

try {
    $manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $manifest = [];
    $failures[] = 'plugin.json parses';
}

$check(($manifest['requires']['jyavani'] ?? null) === '>=2.3.74', 'manifest requires the default_roles/delegable Core release');
$permissions = [];
foreach ($manifest['permissions'] ?? [] as $permission) $permissions[$permission['key'] ?? ''] = $permission;
$expectedDefaults = [
    'plugin.form-builder.workspace.access' => ['author', 'admin'],
    'plugin.form-builder.forms.manage-any' => ['admin'],
    'plugin.form-builder.global-settings.manage' => ['admin'],
    'plugin.form-builder.bin.manage' => ['admin'],
];
foreach ($expectedDefaults as $key => $roles) {
    $check(($permissions[$key]['default_roles'] ?? null) === $roles, "{$key} has explicit defaults");
}
$check(($permissions['plugin.form-builder.forms.manage-any']['delegable'] ?? null) === true, 'manage-any is delegable');
$check(($permissions['plugin.form-builder.bin.manage']['delegable'] ?? null) === true, 'bin.manage is delegable');

$plugin = (string)file_get_contents($root . '/plugin.php');
$index = (string)file_get_contents($root . '/admin/index.php');
$settings = (string)file_get_contents($root . '/admin/settings.php');
$builder = (string)file_get_contents($root . '/admin/builder.php');
$ajax = (string)file_get_contents($root . '/admin/ajax.php');
$bin = (string)file_get_contents($root . '/admin/bin.php');

$workspaceAt = strpos($plugin, "user_can(\$pdo, \$uid, 'plugin.form-builder.workspace.access')");
$manageAnyAt = strpos($plugin, "user_can(\$pdo, \$uid, 'plugin.form-builder.forms.manage-any')");
$check($workspaceAt !== false && $manageAnyAt !== false && $workspaceAt < $manageAnyAt, 'workspace permission is checked before form ACL elevation');
$check(!str_contains($plugin, "in_array('admin', \$roles") && !str_contains($plugin, "['is_site_owner']"), 'form access has no admin or Site Owner OR bypass');
$check(str_contains($index, "adiwira_require_permission(\$pdo, 'plugin.form-builder.workspace.access'") && str_contains($index, "user_can(\$pdo, \$uid, 'plugin.form-builder.global-settings.manage')"), 'workspace and global settings use permissions');
$check(str_contains($settings, "user_can(\$pdo, \$uid, 'plugin.form-builder.forms.manage-any') || \$access['owner'] === \$uid"), 'owners and manage-any holders may manage ACL');
$check(!str_contains($settings, "\$r === 'admin' ? 'disabled checked'"), 'ACL UI does not force admin');
$check(str_contains($builder, "user_can(\$pdo, \$uid, 'plugin.form-builder.bin.manage')") && str_contains($builder, "user_can(\$pdo, \$uid, 'plugin.form-builder.forms.manage-any')"), 'Bin UI requires bin.manage and manage-any');
$binHookAt = strpos($plugin, "add_filter('bin_items'");
$binHook = $binHookAt === false ? '' : substr($plugin, $binHookAt, 1800);
$check(str_contains($binHook, "user_can(\$pdo, \$uid, 'plugin.form-builder.bin.manage')") && str_contains($binHook, "user_can(\$pdo, \$uid, 'plugin.form-builder.forms.manage-any')"), 'Bin hook requires bin.manage and manage-any');
$ajaxWorkspaceAt = strpos($ajax, 'plugin.form-builder.workspace.access');
$ajaxAclAt = strpos($ajax, 'fb_can_access_form');
$check($ajaxWorkspaceAt !== false && $ajaxAclAt !== false && $ajaxWorkspaceAt < $ajaxAclAt, 'direct builder endpoint checks workspace before form ACL');
$check(str_contains($bin, "adiwira_require_permission(\$pdo, 'plugin.form-builder.bin.manage'") && str_contains($bin, "adiwira_require_permission(\$pdo, 'plugin.form-builder.forms.manage-any'"), 'Bin endpoint requires bin.manage and manage-any');

if ($failures !== []) {
    fwrite(STDERR, 'Form Builder security contract failed: ' . implode('; ', array_unique($failures)) . "\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
