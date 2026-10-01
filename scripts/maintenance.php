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
        if ($target === "" || file_exists($target)) {
            throw new RuntimeException("Provide a new backup filename outside the web root.");
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
