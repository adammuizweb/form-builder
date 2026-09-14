<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__));
if ($root === false) throw new RuntimeException('Package root unavailable.');
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
if (($manifest['name'] ?? '') !== 'form-builder' || ($manifest['version'] ?? '') !== '1.7.3') throw new RuntimeException('Unexpected release manifest.');
if (!class_exists('ZipArchive')) throw new RuntimeException('PHP zip extension is required to build the package.');
$output = $argv[1] ?? (dirname($root) . '/form-builder-1.7.3.zip');
$temporary = $output . '.tmp-' . bin2hex(random_bytes(4));
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = $file->getPathname(); $relative = substr($path, strlen($root) + 1);
    if ($file->isLink() || str_starts_with($relative, '.git/') || $relative === '.gitignore' || str_starts_with($relative, 'tools/') || str_starts_with($relative, 'tests/')) continue;
    if (!$file->isFile()) throw new RuntimeException('Unsupported package entry: ' . $relative);
    $files[$relative] = $path;
}
ksort($files, SORT_STRING);
$zip = new ZipArchive();
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create package.');
foreach ($files as $relative => $path) {
    $name = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    if (!$zip->addFile($path, $name)) throw new RuntimeException('Cannot add ' . $relative);
    if (method_exists($zip, 'setMtimeName')) $zip->setMtimeName($name, 946684800);
}
$zip->close();
if (!rename($temporary, $output)) { @unlink($temporary); throw new RuntimeException('Cannot publish package.'); }
echo $output . ' ' . hash_file('sha256', $output) . "\n";
