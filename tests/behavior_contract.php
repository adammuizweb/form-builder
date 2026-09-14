<?php
declare(strict_types=1);

$sandbox = sys_get_temp_dir() . '/fb-contract-' . getmypid();
mkdir($sandbox . '/plugins/form-builder/migrations', 0700, true);
mkdir($sandbox . '/private_files', 0700, true);
foreach (glob(dirname(__DIR__) . '/migrations/*') ?: [] as $migration) copy($migration, $sandbox . '/plugins/form-builder/migrations/' . basename($migration));
define('DASHBOARD_CONTEXT', true);
define('PLUGIN_PATH', $sandbox . '/plugins');
$GLOBALS['_hooks'] = ['actions'=>[], 'filters'=>[]]; $GLOBALS['_routes'] = [];
function add_action(string $name, callable $callback, int $priority = 10): void { $GLOBALS['_hooks']['actions'][$name][$priority][] = $callback; }
function add_filter(string $name, callable $callback, int $priority = 10): void { $GLOBALS['_hooks']['filters'][$name][$priority][] = $callback; }
function apply_filters(string $name, mixed $value, mixed ...$args): mixed { foreach ($GLOBALS['_hooks']['filters'][$name] ?? [] as $callbacks) foreach ($callbacks as $callback) $value = $callback($value, ...$args); return $value; }
function register_frontend_route(string $path, callable|string $handler, array $options = []): bool { $GLOBALS['_routes'][$path] = $options; return true; }
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string { return $GLOBALS['_settings'][$key] ?? $default; }
function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool { $GLOBALS['_settings'][$key] = $value; return true; }
function stateless_csrf_token(): string { return 'core-stateless-token'; }
function stateless_csrf_check(?string $token, int $ttl = 300): bool { return $token === 'core-stateless-token'; }
require dirname(__DIR__) . '/plugin.php';
require '/var/www/jyavani.lan/cfg/helpers/migration_helper.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n"; if (!$ok) $failures[] = $message; };
$rejects = static function (callable $callback): bool { try { $callback(); return false; } catch (InvalidArgumentException|JsonException) { return true; } };

$check(fb_project_root() === realpath($sandbox) && fb_files_base_dir([]) === realpath($sandbox) . '/private_files/form-builder', 'storage resolves to PROJECT_ROOT/private_files/form-builder');
$check(($GLOBALS['_routes']['form-submit']['match'] ?? null) === 'exact' && ($GLOBALS['_routes']['form-submit']['methods'] ?? null) === ['POST'] && ($GLOBALS['_routes']['fb-builder']['methods'] ?? null) === ['POST'], 'runtime route registration is exact and method-aware');
$serverBackup = $_SERVER; $_SERVER['REMOTE_ADDR'] = '203.0.113.10'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
$context = fb_public_ctx(new PDO('sqlite::memory:')); $_SERVER = $serverBackup;
$check($context === ['csrf'=>'core-stateless-token','ip'=>'203.0.113.10'] && fb_csrf_check('', 'core-stateless-token'), 'public context uses Core stateless CSRF and ignores untrusted forwarding headers');
$token = fb_started_token(new PDO('sqlite::memory:'), 7, 'fr-ca');
$check(fb_started_check(new PDO('sqlite::memory:'), 7, $token, 0, 'fr-ca') && !fb_started_check(new PDO('sqlite::memory:'), 7, $token, 0, 'ja'), 'signed start token binds any valid rendered locale');
$migrations = plugin_migrations_discover($sandbox . '/plugins/form-builder');
$check(array_keys($migrations) === ['0001-baseline.sql','0002-submission-workflow.php'], 'Core discovers the final append-only migration filenames');

$pathBase = fb_files_base_dir([]); mkdir($pathBase, 0700); mkdir($pathBase . '/1', 0700); file_put_contents($pathBase . '/1/test.pdf', '%PDF-contract');
$check(fb_contained_path($pathBase, '1/test.pdf', true) === $pathBase . '/1/test.pdf' && fb_contained_path($pathBase, '../private_files/secret', false) === null && fb_contained_path($pathBase, '/etc/passwd', true) === null, 'private path containment accepts only contained regular paths');
$safeDirectory = fb_ensure_storage_directory($pathBase, ['2', '2026', '09']);
$check($safeDirectory === $pathBase . '/2/2026/09' && is_dir($safeDirectory), 'private upload directories are created one contained component at a time');
$symlinkTarget = $sandbox . '/symlink-target'; mkdir($symlinkTarget, 0700); symlink($symlinkTarget, $pathBase . '/3');
try { fb_ensure_storage_directory($pathBase, ['3', '2026']); $symlinkRejected = false; } catch (RuntimeException) { $symlinkRejected = true; }
$check($symlinkRejected, 'private upload directory creation rejects symlinked components');
$check(fb_rate_limit_check(new PDO('sqlite::memory:'), '203.0.113.10', 'test', 60, 1)['allowed'] === false, 'rate limiting fails closed on storage errors');
$relocatedParent = $sandbox . '/relocated'; mkdir($relocatedParent, 0700);
add_filter('fb_files_base_dir', static fn(string $base): string => $relocatedParent . '/form-builder', 50);
$check(fb_files_base_dir([]) === realpath($relocatedParent) . '/form-builder', 'trusted storage filter preserves a normalized dedicated relocation');

$definitionPath = __DIR__ . '/fixtures/generic.form.json';
$definition = fb_definition_decode((string)file_get_contents($definitionPath));
$fields = $definition['form']['fields'];
$byKey = array_column($fields, null, 'key');
$check(count(fb_country_catalog()) === 249 && fb_country('ID')['dial'] === '62' && fb_country('ZZ') === null, 'bundled catalog contains the ISO 3166-1 alpha-2 countries and calling metadata');
$check($byKey['phone']['settings']['country_field'] === 'country' && array_keys($definition['form']['settings']['translations']) === ['fr','fr-ca','ja'], 'generic definitions link international phones and accept configurable locales');
$checkbox = ['type'=>'checkbox','field_key'=>'consent','label'=>'Consent','required'=>1,'is_hidden'=>0,'options_json'=>fb_json_encode([['value'=>'yes','label'=>'I agree','price'=>0]]),'validation_json'=>null,'settings_json'=>null];
$checkboxHtml = fb_render_field_html($checkbox, 'contract', 'i1', false, fb_default_settings());
$check(str_contains($checkboxHtml, 'name="consent[]"') && str_contains($checkboxHtml, 'required'), 'single-option required checkboxes render without undefined state');
$countryField = ['type'=>'country','field_key'=>'country','label'=>'Country','required'=>1,'is_hidden'=>0,'placeholder'=>'','help_text'=>'','validation_json'=>null,'settings_json'=>null];
$phoneField = ['type'=>'intl_phone','field_key'=>'phone','label'=>'Phone','required'=>1,'is_hidden'=>0,'placeholder'=>'','help_text'=>'','validation_json'=>fb_json_encode(['maxlength'=>25]),'settings_json'=>fb_json_encode(['country_field'=>'country'])];
$countryHtml = fb_render_field_html($countryField, 'contract', 'i1', false, fb_default_settings());
$phoneHtml = fb_render_field_html($phoneField, 'contract', 'i1', false, fb_default_settings());
$check(str_contains($countryHtml, 'data-fb-country-search') && str_contains($countryHtml, 'value="ID"') && !str_contains($countryHtml, 'Indonesia (+62)') && str_contains($phoneHtml, 'data-country-field="country"'), 'country picker keeps labels country-focused while phone rendering declares its country dependency');
$GLOBALS['__APP_LOCALE'] = 'fr-CA';
$localizedSettings = array_merge(fb_default_settings(), $definition['form']['settings']);
$localizedPhone = fb_localized_field(['field_key'=>'phone','label'=>'Phone'], $localizedSettings);
$check(fb_locale() === 'fr-ca' && fb_localized_form(['title'=>'Contact'], $localizedSettings)[0]['title'] === 'Formulaire de contact' && $localizedPhone['label'] === 'Telephone', 'exact locale overlays inherit partial base-language translations');

[$normalizedContact, $contactErrors] = fb_validate_submission([$countryField,$phoneField], ['country'=>'id','phone'=>'0812 3456 7890'], [], fb_default_settings());
$check($contactErrors === [] && $normalizedContact === ['country'=>'ID','phone'=>'+6281234567890'], 'country and local phone values normalize to ISO alpha-2 and E.164');
$check(fb_normalize_international_phone('02 9374 4000', 'AU') === '+61293744000' && fb_normalize_international_phone('+6721234567', 'AQ') === '+6721234567', 'normalization supports landlines and explicit E.164 numbers for countries without a unique calling code');
[, $contactErrors] = fb_validate_submission([$countryField,$phoneField], ['country'=>'ID','phone'=>'+49 30 123456'], [], fb_default_settings());
$check($contactErrors !== [], 'international phone validation rejects a calling code that conflicts with the linked country');

$invalid = $definition; $invalid['form']['fields'][3]['validation']['unknown_rule'] = true;
$check($rejects(static fn() => fb_definition_decode($invalid)), 'deep validation rejects unknown nested keys');
$selectDefinition = $definition;
$selectDefinition['form']['fields'][] = ['key'=>'choice','parent'=>'col_main','type'=>'select','label'=>'Choice','placeholder'=>'','help'=>'','required'=>false,'width'=>12,'order'=>40,'hidden'=>false,'options'=>[['value'=>'one','label'=>'One','price'=>0]],'validation'=>[],'settings'=>[]];
$invalid = $selectDefinition; $invalid['form']['fields'][5]['options'][0]['price'] = 'free';
$check($rejects(static fn() => fb_definition_decode($invalid)), 'deep validation rejects malformed option prices');
$invalid = $definition; $invalid['form']['fields'][3]['parent'] = 'row_main';
$check($rejects(static fn() => fb_definition_decode($invalid)), 'deep validation rejects non-container parent relationships');
$invalid = $definition; $invalid['form']['fields'][] = ['key'=>'date','parent'=>'col_main','type'=>'date','label'=>'Date','placeholder'=>'','help'=>'','required'=>false,'width'=>12,'order'=>40,'hidden'=>false,'options'=>[],'validation'=>['before_field'=>'email'],'settings'=>[]];
$check($rejects(static fn() => fb_definition_decode($invalid)), 'deep validation rejects invalid date references');
$invalid = $definition; $invalid['form']['fields'][3]['settings']['country_field'] = 'email';
$check($rejects(static fn() => fb_definition_decode($invalid)), 'deep validation rejects invalid country field references');
$invalid = $definition; $invalid['form']['settings']['confirmation_email_field'] = 'phone';
$check($rejects(static fn() => fb_definition_decode($invalid)), 'deep validation rejects non-email mail references');
$invalid = $selectDefinition; $invalid['form']['settings']['translations']['fr']['fields']['choice']['options']['unknown'] = 'Inconnu';
$check($rejects(static fn() => fb_definition_decode($invalid)), 'deep validation rejects unknown translated options');
$invalid = $definition; $invalid['form']['fields'][] = ['key'=>'attachment','parent'=>'col_main','type'=>'file','label'=>'Attachment','placeholder'=>'','help'=>'','required'=>false,'width'=>12,'order'=>40,'hidden'=>false,'options'=>[],'validation'=>['max_bytes'=>1024,'exts'=>['exe']],'settings'=>[]];
$check($rejects(static fn() => fb_definition_decode($invalid)), 'deep validation rejects unsafe upload extensions');
$invalid = $definition; $invalid['form']['settings']['translations']['not_a_locale'] = [];
$check($rejects(static fn() => fb_definition_decode($invalid)), 'deep validation rejects malformed locale identifiers');
$check($rejects(static fn() => fb_definition_decode(str_repeat('x', FB_DEFINITION_MAX_BYTES + 1))), 'definition input is bounded');

$dateField = static fn(string $key, string $label, array $validation = []): array => ['type'=>'date','field_key'=>$key,'label'=>$label,'required'=>1,'is_hidden'=>0,'validation_json'=>fb_json_encode($validation)];
$settings = fb_default_settings();
[$valid, $errors] = fb_validate_submission([$dateField('start','Start',['before_field'=>'end']),$dateField('end','End',['after_field'=>'start'])], ['start'=>'2027-01-01','end'=>'2027-02-01'], [], $settings);
$check($errors === [] && $valid['start'] === '2027-01-01', 'strict valid date range passes');
[, $errors] = fb_validate_submission([$dateField('start','Start',['before_field'=>'end']),$dateField('end','End',['after_field'=>'start'])], ['start'=>'2027-02-30','end'=>'2027-01-01'], [], $settings);
$check(count($errors) >= 2, 'invalid calendar and cross-field date ranges fail');
$check(fb_csv_cell(' =cmd') === "' =cmd" && fb_csv_cell("\t@cmd") === "'\t@cmd" && fb_csv_cell('ordinary') === 'ordinary', 'CSV formulas including leading whitespace are neutralized');
$check(fb_format_currency(1250, 'EUR') === 'EUR 1,250' && fb_format_currency(1250, 'bad') === 'USD 1,250', 'priced options use a validated configurable currency code');

$dbFields = [];
foreach ($fields as $field) $dbFields[] = ['field_key'=>$field['key'],'type'=>$field['type'],'label'=>$field['label'],'is_hidden'=>0,'options_json'=>fb_json_encode($field['options'] ?? []),'validation_json'=>fb_json_encode($field['validation'] ?? []),'settings_json'=>fb_json_encode($field['settings'] ?? [])];
$form = ['id'=>1,'slug'=>'contact','deleted_at'=>null,'settings_json'=>fb_json_encode(array_merge(fb_default_settings(), $definition['form']['settings']))];
$record = ['schema'=>1,'reference_code'=>'LEGACY-001','workflow_status'=>'reviewing','created_at'=>'2025-01-02 03:04:05','updated_at'=>'2025-01-03 04:05:06','notes'=>[['at'=>'2025-01-03 04:05:06','actor'=>null,'text'=>'Reviewed during migration.']],'history'=>[['at'=>'2025-01-02 03:04:05','actor'=>null,'from'=>null,'to'=>'submitted','source'=>'legacy']],'source'=>['system'=>'legacy-app','record'=>101],'data'=>['country'=>'ID','phone'=>'+6281234567890','email'=>'person@example.test'],'files'=>[],'totals'=>[],'ip'=>null,'is_read'=>true,'is_deleted'=>false];
$normalized = fb_normalize_legacy_submission($record, $form, $dbFields, false);
$check($normalized['reference_code'] === 'LEGACY-001' && $normalized['created_at'] === $record['created_at'] && $normalized['notes'] === $record['notes'] && $normalized['source'] === $record['source'], 'generic legacy import contract preserves reference, timestamps, notes, and provenance');
$divergent = $record; $divergent['data']['email'] = 'changed@example.test';
$check(hash('sha256', fb_json_encode(fb_import_canonicalize($normalized))) !== hash('sha256', fb_json_encode(fb_import_canonicalize(fb_normalize_legacy_submission($divergent, $form, $dbFields, false)))), 'generic import payload hashing detects divergent repeats');

$remove = static function (string $path) use (&$remove): void { if (is_dir($path) && !is_link($path)) { foreach (new FilesystemIterator($path) as $entry) $remove($entry->getPathname()); rmdir($path); } else unlink($path); };
$remove($sandbox);
if ($failures) { fwrite(STDERR, 'Behavior contract failed: ' . implode('; ', $failures) . "\n"); exit(1); }
echo "RESULT: ALL PASS\n";
