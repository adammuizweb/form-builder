<?php
declare(strict_types=1);

$useCore = in_array('--core-env', $argv, true);
$dsn = getenv('FB_TEST_MYSQL_DSN') ?: '';
$user = getenv('FB_TEST_MYSQL_USER') ?: '';
$password = getenv('FB_TEST_MYSQL_PASSWORD') ?: '';
if ($useCore) {
    $values = [];
    foreach (file('/var/www/jyavani.lan/cfg/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2); $values[trim($key)] = trim(trim($value), "\"'");
    }
    $dsn = 'mysql:host=' . ($values['DB_HOST'] ?? 'localhost') . ';port=' . ($values['DB_PORT'] ?? '3306') . ';charset=utf8mb4';
    $user = $values['DB_USER'] ?? ''; $password = $values['DB_PASS'] ?? $values['DB_PASSWORD'] ?? '';
}
if ($dsn === '' || (!$useCore && getenv('FB_TEST_MYSQL_ALLOW_CREATE_DATABASE') !== '1')) {
    echo "SKIP MySQL contract (use --core-env or explicit FB_TEST_MYSQL_* opt-in)\n"; exit(0);
}

$database = 'fb_contract_' . getmypid() . '_' . bin2hex(random_bytes(3));
if (preg_match('/\Afb_contract_[a-z0-9_]+\z/', $database) !== 1) throw new RuntimeException('Unsafe test database name.');
$sandbox = sys_get_temp_dir() . '/fb-mysql-' . getmypid(); mkdir($sandbox . '/plugins', 0700, true); mkdir($sandbox . '/private_files', 0700, true);
define('DASHBOARD_CONTEXT', true); define('PLUGIN_PATH', $sandbox . '/plugins');
$GLOBALS['_hooks'] = ['actions'=>[], 'filters'=>[]];
function add_action(string $name, callable $callback, int $priority = 10): void { $GLOBALS['_hooks']['actions'][$name][$priority][] = $callback; }
function add_filter(string $name, callable $callback, int $priority = 10): void { $GLOBALS['_hooks']['filters'][$name][$priority][] = $callback; }
function apply_filters(string $name, mixed $value, mixed ...$args): mixed { return $value; }
function register_frontend_route(string $path, callable|string $handler, array $options = []): bool { return true; }
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string { return $default; }
function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool { return true; }
require dirname(__DIR__) . '/plugin.php';
require '/var/www/jyavani.lan/cfg/helpers/migration_helper.php';

$server = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo = null; $failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n"; if (!$ok) $failures[] = $message; };
try {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    foreach (plugin_migrations_split_sql((string)file_get_contents(dirname(__DIR__) . '/migrations/0001-baseline.sql')) as $sql) $pdo->exec($sql);
    $check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('fb_forms','fb_fields','fb_submissions','fb_rate_limits')")->fetchColumn() === 4, 'baseline creates the complete 1.5.2 schema');
    $rateResults = [];
    foreach ([1,2,3] as $attempt) { $pdo->beginTransaction(); $rateResults[] = fb_rate_limit_check($pdo, '203.0.113.20', 'contract', 3600, 2); $rateResults[array_key_last($rateResults)]['allowed'] ? $pdo->commit() : $pdo->rollBack(); }
    $check($rateResults[0]['allowed'] && $rateResults[1]['allowed'] && !$rateResults[2]['allowed'] && $rateResults[2]['retry_after'] > 0, 'MySQL rate limit increments atomically and denies beyond the limit');
    $pdo->prepare('INSERT INTO fb_forms (slug,title,status,settings_json) VALUES (?,?,?,?)')->execute(['legacy-layout','Legacy','draft',fb_json_encode(fb_default_settings())]);
    $formId = (int)$pdo->lastInsertId();
    $insertField = $pdo->prepare("INSERT INTO fb_fields (form_id,type,label,field_key,width,sort_order) VALUES (?,'text',?,?,?,?)");
    foreach ([['a',6],['b',6],['c',8],['d',4]] as $index => [$key,$width]) $insertField->execute([$formId,strtoupper($key),$key,$width,($index + 1) * 10]);
    $load = static fn(string $path): Closure => require $path;
    $migration = $load(dirname(__DIR__) . '/migrations/0002-submission-workflow.php'); $migration($pdo);
    $rows = (int)$pdo->query("SELECT COUNT(*) FROM fb_fields WHERE form_id = {$formId} AND type = 'row'")->fetchColumn();
    $cols = (int)$pdo->query("SELECT COUNT(*) FROM fb_fields WHERE form_id = {$formId} AND type = 'col'")->fetchColumn();
    $groups = $pdo->query("SELECT f.field_key,r.id row_id FROM fb_fields f JOIN fb_fields c ON c.id=f.parent_id JOIN fb_fields r ON r.id=c.parent_id WHERE f.form_id={$formId} AND f.type='text' ORDER BY f.sort_order,f.id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $check($rows === 2 && $cols === 4 && $groups['a'] === $groups['b'] && $groups['c'] === $groups['d'] && $groups['a'] !== $groups['c'], 'upgrade preserves exact 1.5.2 grouping-by-width layout');
    $migration($pdo);
    $check((int)$pdo->query("SELECT COUNT(*) FROM fb_fields WHERE form_id={$formId} AND type IN ('row','col')")->fetchColumn() === 6, 'upgrade data conversion is idempotent on rerun');
    $check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('fb_import_ledger','fb_submission_imports')")->fetchColumn() === 2, 'definition and submission import ledgers are separate');
    $definitionJson = (string)file_get_contents(__DIR__ . '/fixtures/generic.form.json');
    $definitionFirst = fb_upsert_form_definition($pdo, $definitionJson, null, false);
    $definitionRepeat = fb_upsert_form_definition($pdo, $definitionJson, null, false);
    $definitionFieldCount = (int)$pdo->query('SELECT COUNT(*) FROM fb_fields WHERE form_id = ' . (int)$definitionFirst['form_id'])->fetchColumn();
    $definitionStatus = $pdo->query('SELECT status FROM fb_forms WHERE id = ' . (int)$definitionFirst['form_id'])->fetchColumn();
    $check($definitionFirst['form_id'] === $definitionRepeat['form_id'] && $definitionFieldCount === 5 && $definitionStatus === 'draft', 'generic definition installs deterministically by slug and remains draft');

    $form = fb_get_form($pdo, $formId); $fields = fb_flat_fields(fb_get_fields($pdo, $formId));
    $record = ['schema'=>1,'reference_code'=>'LEGACY-MYSQL-1','workflow_status'=>'reviewing','created_at'=>'2025-01-02 03:04:05','updated_at'=>'2025-01-03 04:05:06','notes'=>[['at'=>'2025-01-03 04:05:06','actor'=>null,'text'=>'Imported note']],'history'=>[['at'=>'2025-01-02 03:04:05','actor'=>null,'from'=>null,'to'=>'submitted','source'=>'legacy']],'source'=>['system'=>'contract'],'data'=>['a'=>'one','b'=>'two'],'files'=>[],'totals'=>[],'ip'=>null,'is_read'=>false,'is_deleted'=>false];
    $first = fb_import_legacy_submission($pdo, $formId, 'contract', 'record-1', $record);
    $repeat = fb_import_legacy_submission($pdo, $formId, 'contract', 'record-1', $record);
    $check($first['reconciled'] === false && $repeat['reconciled'] === true && $first['submission_id'] === $repeat['submission_id'], 'identical legacy import repeats reconcile transactionally');
    $record['data']['a'] = 'changed';
    try { fb_import_legacy_submission($pdo, $formId, 'contract', 'record-1', $record); $divergent = false; } catch (DomainException) { $divergent = true; }
    $check($divergent && (int)$pdo->query('SELECT COUNT(*) FROM fb_submissions')->fetchColumn() === 1, 'divergent legacy import repeat fails closed without duplication');
} finally {
    $pdo = null;
    if (str_starts_with($database, 'fb_contract_')) $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    $remove = static function (string $path) use (&$remove): void { if (is_dir($path)) { foreach (new FilesystemIterator($path) as $entry) $remove($entry->getPathname()); rmdir($path); } else unlink($path); };
    $remove($sandbox);
}
if ($failures) { fwrite(STDERR, 'MySQL contract failed: ' . implode('; ', $failures) . "\n"); exit(1); }
echo "RESULT: ALL PASS\n";
