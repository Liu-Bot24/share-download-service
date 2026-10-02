<?php
declare(strict_types=1);
// Invoked only by auth_rate_test.py, with fresh synthetic directories and stdin data.
require_once __DIR__ . "/../src/bootstrap.php";
require_once __DIR__ . "/../src/manager.php";
$task = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$root = $task["root"];
if (!str_starts_with(basename($root), "share-auth-") || !is_dir($root . "/storage")) {
    throw new RuntimeException("Isolated auth fixture required");
}
$store = new ShareStore($root . "/files", $root . "/storage/stats.json");
if ($task["mode"] === "init") {
    $hash = password_hash("synthetic-correct-password", PASSWORD_BCRYPT, ["cost" => 12]);
    file_put_contents(
        $root . "/storage/manager.json",
        json_encode([
            "username" => "synthetic-manager",
            "password_hash" => $hash,
        ]),
    );
    $ids = [];
    foreach (["protected.txt", "other.txt"] as $name) {
        $f = $store->resolve($name);
        $store->db->run("UPDATE files SET password_hash=? WHERE id=?", [$hash, $f["id"]]);
        $ids[] = $f["id"];
    }
    echo json_encode($ids);
    exit();
}
$_SERVER["REMOTE_ADDR"] = $task["ip"] ?? "203.0.113.8";
session_save_path($root . "/storage");
if (isset($task["barrier"])) {
    touch($root . "/" . $task["barrier"] . "-ready-" . $task["index"]);
    $deadline = microtime(true) + 15;
    while (!is_file($root . "/" . $task["barrier"] . "-go")) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Auth barrier timed out");
        }
        usleep(1000);
    }
}
try {
    if ($task["mode"] === "admin") {
        manager_login($store, "synthetic-manager", $task["password"] ?? "incorrect");
    } elseif ($task["mode"] === "file") {
        $store->createSession(
            $task["id"],
            [
                "ip" => $_SERVER["REMOTE_ADDR"],
                "ip_source" => "peer",
                "region" => "未知",
                "geo_version" => "fixture",
                "referrer" => "",
            ],
            "public",
            $task["password"] ?? "incorrect",
        );
    } elseif ($task["mode"] === "verify") {
        $ok = $store->verifyCredentials($task["keys"], $task["limits"], function () use (
            $task,
            $root,
            $store,
        ): bool {
            if (isset($task["marker"])) {
                touch($root . "/" . $task["marker"]);
                $deadline = microtime(true) + 15;
                while (!is_file($root . "/" . $task["marker"] . "-go")) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException("Verifier timed out");
                    }
                    usleep(1000);
                }
            }
            if ($task["outcome"] === "throw") {
                throw new RuntimeException("Synthetic verifier failure");
            }
            return $task["outcome"] === "true";
        });
        echo json_encode(["reason" => $ok ? "ok" : "invalid", "status" => $ok ? 200 : 401]);
        exit();
    } else {
        throw new RuntimeException("Unknown auth fixture mode");
    }
    echo json_encode(["reason" => "ok", "status" => 200]);
} catch (ShareError $e) {
    echo json_encode(["reason" => $e->reason, "status" => $e->httpStatus]);
} catch (Throwable $e) {
    echo json_encode(["reason" => "unexpected", "type" => get_class($e)]);
}
