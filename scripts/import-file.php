<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

$source = $argv[1] ?? '';
$publicName = $argv[2] ?? basename($source);
$mimeType = $argv[3] ?? guess_mime_type($source);

if ($source === '') {
    fwrite(STDERR, "Usage: php scripts/import-file.php <source-path> [public-name] [mime-type]\n");
    exit(1);
}

$root = dirname(__DIR__);
$targetDir = $root . '/files';
if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
    fwrite(STDERR, "Could not create files directory.\n");
    exit(1);
}
$targetName = basename(str_replace('\\', '/', $publicName));
$targetPath = $targetDir . '/' . $targetName;
if (!copy($source, $targetPath)) {
    fwrite(STDERR, "Could not copy file.\n");
    exit(1);
}
chmod($targetPath, 0640);
$record = share_store()->get($targetName);
if (!$record) {
    fwrite(STDERR, "Imported file could not be read.\n");
    exit(1);
}
echo json_encode([
    'name' => $record['name'],
    'bytes' => $record['bytes'],
    'sha256' => $record['sha256'],
    'path' => '/d/' . rawurlencode((string) $record['name']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

function guess_mime_type(string $path): string
{
    if (function_exists('mime_content_type') && is_file($path)) {
        $type = mime_content_type($path);
        if (is_string($type) && $type !== '') {
            return $type;
        }
    }
    return str_ends_with(strtolower($path), '.json') ? 'application/json' : 'application/octet-stream';
}
