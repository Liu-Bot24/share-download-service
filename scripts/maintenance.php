<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/bootstrap.php";
if (PHP_SAPI !== "cli") {
    exit(1);
}
try {
    $store = share_store();
    $command = $argv[1] ?? "help";
    if ($command === "scan") {
        $result = $store->scan("cli");
    } elseif ($command === "jobs") {
        $result = $store->processJobs();
    } elseif ($command === "reconcile") {
        $result = (new QueryService($store))->reconcile();
        if ($result) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            exit(2);
        }
    } elseif ($command === "backup") {
        $target = $argv[2] ?? "";
        if (
            $target === "" ||
            str_contains($target, "\0") ||
            str_ends_with($target, DIRECTORY_SEPARATOR) ||
            file_exists($target) ||
            is_link($target)
        ) {
            throw new RuntimeException(
                "Provide a new backup filename in an existing private directory.",
            );
        }
        $parent = realpath(dirname($target));
        if ($parent === false || !is_dir($parent)) {
            throw new RuntimeException("The backup parent directory must already exist.");
        }
        $publicRoots = [];
        foreach ([__DIR__ . "/../public", $store->filesDir] as $directory) {
            $root = realpath($directory);
            if ($root === false || !is_dir($root)) {
                throw new RuntimeException("Cannot verify the public directory boundaries.");
            }
            $publicRoots[] = $root;
        }
        // Resolve all supplied ancestors, including aliases and '..'. A symlink beneath
        // public/ can expose its private target too, so check more than the final realpath.
        for ($ancestor = dirname($target); ; $ancestor = dirname($ancestor)) {
            $resolved = realpath($ancestor);
            foreach ($publicRoots as $root) {
                if (
                    $resolved === $root ||
                    ($resolved !== false &&
                        str_starts_with(
                            $resolved,
                            rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
                        ))
                ) {
                    throw new RuntimeException(
                        "Backups must stay outside the document root and shared-files directory.",
                    );
                }
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
        // Use the resolved absolute destination for SQLite, never the unchecked input path.
        $target = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($target);
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException("The backup destination already exists.");
        }
        // SQLite online VACUUM INTO yields a consistent snapshot, not an unsafe live file copy.
        $store->db->pdo->exec("VACUUM INTO " . $store->db->pdo->quote($target));
        chmod($target, 0600);
        $result = ["backup_created" => true];
    } else {
        fwrite(
            STDERR,
            "Usage: php scripts/maintenance.php scan|jobs|reconcile|backup <new-backup-path>\n",
        );
        exit(1);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
