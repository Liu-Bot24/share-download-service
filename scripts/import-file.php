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
    $target = $store->filesDir . "/" . $name;
    $stage = $store->filesDir . "/.import-" . bin2hex(random_bytes(8));
    if (!copy($source, $stage)) {
        throw new RuntimeException("Cannot stage import.");
    }
    try {
        chmod($stage, 0600);
        if (file_exists($target) || is_link($target) || !link($stage, $target)) {
            throw new RuntimeException("The destination exists; imports never overwrite files.");
        }
    } finally {
        if (is_file($stage)) {
            unlink($stage);
        }
    }
    $store->scan("cli");
    $rows = $store->db->all("SELECT * FROM files WHERE storage_name=? ORDER BY id DESC", [$name]);
    $f = $store->file((int) $rows[0]["id"]);
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
