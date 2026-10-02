<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/bootstrap.php";

$roots = [];
$checks = 0;
function verify(bool $condition, string $label): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
function fixture(): array
{
    global $roots;
    $root = sys_get_temp_dir() . "/share-mutation-" . bin2hex(random_bytes(8));
    $roots[] = $root;
    mkdir($root . "/files", 0700, true);
    mkdir($root . "/storage", 0700, true);
    file_put_contents($root . "/files/same.txt", "AAAA");
    file_put_contents($root . "/replacement.txt", "BBBB");
    $store = new ShareStore($root . "/files", $root . "/storage/stats.json");
    return [$store, $store->resolve("same.txt"), $root];
}
function expectReason(callable $call, string $reason): void
{
    try {
        $call();
    } catch (ShareError $error) {
        verify($error->reason === $reason, "operation rejected as $reason");
        return;
    }
    throw new RuntimeException("Expected $reason rejection");
}
function context(string $ip = "203.0.113.8"): array
{
    return [
        "ip" => $ip,
        "ip_source" => "peer",
        "region" => "未知",
        "geo_version" => "fixture",
        "referrer" => "private.example/path",
    ];
}
function trashPath(ShareStore $store, int $id): string
{
    return $store->storageDir . "/trash/" . $store->file($id)["trash_key"] . "/file";
}
function barrierRace(string $root, array $calls): array
{
    $batch = bin2hex(random_bytes(4));
    $children = [];
    foreach ($calls as $i => $call) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $store = new ShareStore($root . "/files", $root . "/storage/stats.json");
                file_put_contents($root . "/$batch-ready-$i", "ready");
                $deadline = microtime(true) + 10;
                while (!is_file($root . "/$batch-go")) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException("Barrier timed out");
                    }
                    usleep(1000);
                }
                $call($store);
                $result = "ok";
            } catch (ShareError $error) {
                $result = $error->reason;
            } catch (Throwable $error) {
                $result = "unexpected:" . $error->getMessage();
            }
            file_put_contents($root . "/$batch-result-$i", $result);
            exit(0);
        }
        if ($pid < 1) {
            throw new RuntimeException("Cannot fork test child");
        }
        $children[] = $pid;
    }
    $deadline = microtime(true) + 10;
    while (count(glob($root . "/$batch-ready-*") ?: []) !== count($calls)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Ready barrier timed out");
        }
        usleep(1000);
    }
    touch($root . "/$batch-go");
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $results = [];
    foreach (array_keys($calls) as $i) {
        $results[] = file_get_contents($root . "/$batch-result-$i");
    }
    return $results;
}

try {
    [$store, $a, $root] = fixture();
    $store->trash($a["id"]);
    $source = trashPath($store, $a["id"]);
    $b = $store->importFile($root . "/replacement.txt", "same.txt");
    unlink($b["path"]); // Simulate a missing entity in this isolated fixture.
    $store->scan();
    expectReason(fn() => $store->restore($a["id"], "test"), "duplicate");
    verify(
        is_file($source) && file_get_contents($source) === "AAAA" && !file_exists($a["path"]),
        "missing same-name record rejects restore before moving its source",
    );
    verify(
        $store->file($a["id"])["state"] === "trashed" &&
            $store->file($b["id"])["state"] === "missing",
        "both records retain their original states after the collision",
    );
    expectReason(fn() => $store->restore($a["id"], "test"), "duplicate");
    verify(
        is_file($source) && $store->resolve("same.txt")["id"] === $a["id"],
        "repeated collision preserves the recoverable source and original alias",
    );

    [$store, $a, $root] = fixture();
    $store->trash($a["id"]);
    $source = trashPath($store, $a["id"]);
    file_put_contents($source, "BBBB");
    expectReason(fn() => $store->restore($a["id"], "test"), "restore_integrity");
    verify(
        file_get_contents($source) === "BBBB" &&
            !file_exists($a["path"]) &&
            $store->file($a["id"])["sha256"] === hash("sha256", "AAAA"),
        "equal-length tampering is preserved for inspection and never published with stale SHA",
    );
    file_put_contents($source, "AAAA");
    $store->restore($a["id"], "test");
    $restored = $store->file($a["id"]);
    verify(
        $restored["state"] === "paused" &&
            $store->readable($restored) &&
            $restored["sha256"] === hash_file("sha256", $restored["path"]),
        "verified restore keeps its version/hash and remains paused",
    );

    foreach (["restore", "trash", "publish"] as $kind) {
        [$store, $a, $root] = fixture();
        if ($kind === "restore") {
            $store->trash($a["id"]);
        }
        $source = $kind === "restore" ? trashPath($store, $a["id"]) : $a["path"];
        $name = $kind === "publish" ? "new.txt" : "same.txt";
        $target = $store->filesDir . "/" . $name;
        $observed = false;
        $store->db->pdo->sqliteCreateFunction(
            "observe_publication",
            function () use (&$observed, $source, $target): int {
                $observed = is_file($source) && is_file($target);
                return 1;
            },
            0,
        );
        $action = $kind === "publish" ? "upload" : $kind;
        $store->db->pdo->exec(
            "CREATE TRIGGER inject_failure BEFORE INSERT ON audit WHEN NEW.action='$action' BEGIN SELECT observe_publication(); SELECT RAISE(ABORT,'injected audit failure'); END",
        );
        try {
            if ($kind === "restore") {
                $store->restore($a["id"], "test");
            } elseif ($kind === "trash") {
                $store->trash($a["id"]);
            } else {
                $store->importFile($root . "/replacement.txt", $name);
            }
            throw new RuntimeException("Failure was not injected");
        } catch (PDOException $expected) {
        }
        verify(
            $observed,
            "$kind failure is injected after publication while the source is still available",
        );
        verify(
            is_file($source) && file_get_contents($source) === "AAAA",
            "$kind failure preserves original source bytes",
        );
        verify(
            $kind === "trash" ? is_file($target) : !file_exists($target),
            "$kind failure compensates the filesystem publication",
        );
        verify(
            (int) $store->db->one("SELECT COUNT(*) n FROM file_mutations")["n"] === 0,
            "$kind rollback finishes its recovery journal",
        );
        verify(
            $store->file($a["id"])["state"] === ($kind === "restore" ? "trashed" : "active"),
            "$kind failure rolls back its database state",
        );
        $store->db->pdo->exec("DROP TRIGGER inject_failure");
        if ($kind === "restore") {
            $store->restore($a["id"], "test");
        } elseif ($kind === "trash") {
            $store->trash($a["id"]);
        } else {
            $store->importFile($root . "/replacement.txt", $name);
        }
        verify(true, "$kind can be retried after the injected failure");
    }

    if (!function_exists("pcntl_fork") || !function_exists("posix_kill")) {
        throw new RuntimeException(
            "pcntl and posix are required for deterministic mutation/crash tests",
        );
    }
    foreach (["restore", "trash", "publish"] as $kind) {
        [$store, $a, $root] = fixture();
        if ($kind === "restore") {
            $store->trash($a["id"]);
        }
        $source = $kind === "restore" ? trashPath($store, $a["id"]) : $a["path"];
        $action = $kind === "publish" ? "upload" : $kind;
        $store->db->pdo->exec(
            "CREATE TRIGGER crash_mid_commit BEFORE INSERT ON audit WHEN NEW.action='$action' BEGIN SELECT crash_here(); END",
        );
        $pid = pcntl_fork();
        if ($pid === 0) {
            $child = new ShareStore($root . "/files", $root . "/storage/stats.json");
            $child->db->pdo->sqliteCreateFunction(
                "crash_here",
                function () use ($root): int {
                    file_put_contents(
                        $root . "/crash-checkpoint",
                        "after publication, before commit",
                    );
                    posix_kill(getmypid(), SIGKILL);
                    return 0;
                },
                0,
            );
            if ($kind === "restore") {
                $child->restore($a["id"], "test");
            } elseif ($kind === "trash") {
                $child->trash($a["id"]);
            } else {
                $child->importFile($root . "/replacement.txt", "new.txt");
            }
            exit(4);
        }
        pcntl_waitpid($pid, $status);
        verify(
            pcntl_wifsignaled($status) &&
                pcntl_wtermsig($status) === SIGKILL &&
                is_file($root . "/crash-checkpoint"),
            "$kind process is actually killed between publication and SQLite commit",
        );
        $store = new ShareStore($root . "/files", $root . "/storage/stats.json");
        $store->db->pdo->exec("DROP TRIGGER crash_mid_commit");
        verify(
            (int) $store->db->one("SELECT COUNT(*) n FROM file_mutations WHERE committed=0")[
                "n"
            ] === 1,
            "$kind interrupted operation has a durable uncommitted journal",
        );
        $store->scan();
        verify(file_get_contents($source) === "AAAA", "$kind crash recovery preserves the source");
        verify(
            $kind === "restore"
                ? !file_exists($a["path"])
                : $store->readable($store->file($a["id"])),
            "$kind crash recovery restores a consistent visible catalog",
        );
        verify(
            count($store->files()) === 1 &&
                (int) $store->db->one("SELECT COUNT(*) n FROM file_mutations")["n"] === 0,
            "$kind interrupted publication never becomes an accidental new share",
        );
    }

    [$store, $a, $root] = fixture();
    $store->trash($a["id"]);
    $results = barrierRace(
        $root,
        array_fill(0, 8, fn(ShareStore $s) => $s->restore($a["id"], "race")),
    );
    verify(
        count(array_filter($results, fn($r) => $r === "ok")) === 1 &&
            count(array_filter($results, fn($r) => $r === "restore")) === 7,
        "eight simultaneous restores have exactly one successful owner",
    );
    verify(
        file_get_contents($a["path"]) === "AAAA" && $store->file($a["id"])["state"] === "paused",
        "concurrent restore preserves one correct entity and paused state",
    );

    [$store, $a, $root] = fixture();
    $store->trash($a["id"]);
    $results = barrierRace($root, [
        fn(ShareStore $s) => $s->restore($a["id"], "race"),
        fn(ShareStore $s) => $s->importFile($root . "/replacement.txt", "same.txt", "race"),
        fn(ShareStore $s) => $s->scan("race"),
        fn(ShareStore $s) => $s->trash($a["id"], "race"),
    ]);
    verify(
        !array_filter(
            $results,
            fn($r) => !in_array($r, ["ok", "duplicate", "restore", "unavailable"], true),
        ),
        "restore, publication, scan and trash serialize without SQL or filesystem exceptions",
    );
    $owners = $store->db->all(
        "SELECT id FROM files WHERE storage_name='same.txt' AND state NOT IN ('trashed','destroyed')",
    );
    verify(
        count($owners) <= 1,
        "mixed concurrent mutations leave at most one current filename owner",
    );
    foreach ($store->files() as $file) {
        if ($file["state"] === "trashed") {
            verify(
                hash_file("sha256", trashPath($store, $file["id"])) === $file["sha256"],
                "mixed race keeps every trashed entity intact",
            );
        } else {
            verify(
                $store->readable($file) && hash_file("sha256", $file["path"]) === $file["sha256"],
                "mixed race keeps every current entity and hash consistent",
            );
        }
    }
    verify(
        $store->resolve("same.txt")["id"] === $a["id"],
        "mixed race never steals the original direct-link alias",
    );

    [$store, $a, $root] = fixture();
    $token = $store->createSession($a["id"], context());
    $transfer = $store->beginTransfer($token, 206, "bytes=0-1", 2);
    $store->finishTransfer($transfer["transfer_id"], 2, true);
    for ($i = 0; $i < 38; $i++) {
        $store->createSession($a["id"], context());
    }
    $results = barrierRace(
        $root,
        array_fill(0, 8, fn(ShareStore $s) => $s->createSession($a["id"], context())),
    );
    verify(
        count(array_filter($results, fn($r) => $r === "ok")) === 1 &&
            count(array_filter($results, fn($r) => $r === "candidate_rate_limit")) === 7,
        "candidate allocation rate has one atomic final slot across eight processes",
    );
    $transfer = $store->beginTransfer($token, 206, "bytes=2-3", 2);
    $store->finishTransfer($transfer["transfer_id"], 2, true);
    verify(
        $store->file($a["id"])["public_count"] === 1 &&
            (int) $store->db->one("SELECT COUNT(*) n FROM sessions")["n"] === 40,
        "creation throttling leaves a valid range resume and count unchanged",
    );
    $store->createSession($a["id"], context(), "admin", null, "fixture-binding");
    $store->db->run("UPDATE sessions SET expires_at=?", [time() - 1]);
    $before = $store->db->all("SELECT * FROM events");
    $job = $store->processJobs();
    verify(
        $job["expired_candidates_removed"] === 40 &&
            (int) $store->db->one("SELECT COUNT(*) n FROM sessions")["n"] === 1,
        "maintenance removes only expired unused public/admin candidates",
    );
    verify(
        $store->db->all("SELECT * FROM events") === $before &&
            $store->file($a["id"])["public_count"] === 1,
        "candidate collection preserves claimed history, diagnostics and lifetime quota",
    );
    $store->db->run("UPDATE rate_limits SET until_at=?", [time() - 1]);
    $store->createSession($a["id"], context());
    verify(
        (int) $store->db->one("SELECT COUNT(*) n FROM sessions")["n"] === 2,
        "expired allocation limits permit a new candidate without resetting totals",
    );

    [$store, $a, $root] = fixture();
    $store->policy(
        $a["id"],
        [
            "policy_version" => $a["policy_version"],
            "password_action" => "set",
            "password" => "fixture-password",
        ],
        "test",
    );
    $public = $store->publicFiles();
    verify(
        count($public) === 1 &&
            $public[0]["has_password"] &&
            !array_diff(array_keys($public[0]), [
                "id",
                "public_id",
                "name",
                "bytes",
                "mime_type",
                "sha256",
                "public_count",
                "last_public_at",
                "has_password",
            ]),
        "public listing uses an explicit metadata allowlist and retains password-protected files",
    );
    $a = $store->file($a["id"]);
    $store->policy(
        $a["id"],
        ["policy_version" => $a["policy_version"], "state" => "paused"],
        "test",
    );
    verify($store->publicFiles() === [], "paused shares are absent from the visitor catalog");
    echo "$checks mutation/session checks passed\n";
} finally {
    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
