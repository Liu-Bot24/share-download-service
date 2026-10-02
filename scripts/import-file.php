<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/bootstrap.php";
if (PHP_SAPI !== "cli") {
    exit(1);
}
try {
    $source = $argv[1] ?? "";
    $name = $argv[2] ?? basename($source);
    ShareStore::validName($name);
    if (!is_file($source) || is_link($source) || !is_readable($source)) {
        throw new RuntimeException("A readable regular source file is required.");
    }
    $store = share_store();
    $f = $store->importFile($source, $name);
    echo json_encode(
        [
            "id" => $f["id"],
            "name" => $f["name"],
            "bytes" => $f["bytes"],
            "sha256" => $f["sha256"],
            "url" => public_download_url($f),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
