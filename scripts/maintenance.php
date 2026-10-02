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
        if (!class_exists("SQLite3") || !method_exists("SQLite3", "backup")) {
            throw new RuntimeException("PHP SQLite3::backup is required for online backups.");
        }
        // The online backup API also supports SQLite releases older than VACUUM INTO.
        // Keep an incomplete snapshot private and publish only a verified, new target.
        $stage = $parent . DIRECTORY_SEPARATOR . ".share-backup-" . bin2hex(random_bytes(16));
        $handle = fopen($stage, "x+b");
        if (!$handle) {
            throw new RuntimeException("Cannot create a private backup snapshot.");
        }
        $identity = fstat($handle);
        fclose($handle);
        $sourceDb = null;
        $backupDb = null;
        try {
            if (!$identity || !chmod($stage, 0600)) {
                throw new RuntimeException("Cannot protect the backup snapshot.");
            }
            $sourceDb = new SQLite3($store->storageDir . "/share.sqlite", SQLITE3_OPEN_READONLY);
            $sourceDb->enableExceptions(true);
            $sourceDb->busyTimeout(5000);
            $backupDb = new SQLite3($stage, SQLITE3_OPEN_READWRITE);
            $backupDb->enableExceptions(true);
            $backupDb->busyTimeout(5000);
            $backupDb->exec("PRAGMA journal_mode=DELETE; PRAGMA synchronous=FULL");
            if (!$sourceDb->backup($backupDb)) {
                throw new RuntimeException("The online database backup failed.");
            }
            if ($backupDb->querySingle("PRAGMA integrity_check") !== "ok") {
                throw new RuntimeException("The backup snapshot failed its integrity check.");
            }
            $backupDb->close();
            $backupDb = null;
            $sourceDb->close();
            $sourceDb = null;
            // Hard-link publication is atomic and never replaces a competing destination.
            if (!@link($stage, $target)) {
                throw new RuntimeException(
                    "Cannot publish backup; destination exists or is unavailable.",
                );
            }
        } finally {
            try {
                try {
                    if ($backupDb !== null) {
                        $backupDb->close();
                    }
                } finally {
                    if ($sourceDb !== null) {
                        $sourceDb->close();
                    }
                }
            } finally {
                clearstatcache(true, $stage);
                $current = !is_link($stage) && is_file($stage) ? stat($stage) : false;
                if (
                    $current &&
                    $identity &&
                    $current["ino"] === $identity["ino"] &&
                    $current["dev"] === $identity["dev"]
                ) {
                    if (!unlink($stage)) {
                        throw new RuntimeException(
                            "Cannot remove the private backup staging file.",
                        );
                    }
                }
            }
        }
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
