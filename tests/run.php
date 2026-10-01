<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/bootstrap.php";
require_once __DIR__ . "/../src/HttpDownload.php";
$tests = [];
$roots = [];
$assertions = 0;
function ok(bool $v, string $m = "assertion"): void
{
    global $assertions;
    $assertions++;
    if (!$v) {
        throw new RuntimeException($m);
    }
}
function same(mixed $a, mixed $b, string $m = "equal"): void
{
    ok($a === $b, $m . " expected " . var_export($a, true) . " got " . var_export($b, true));
}
function fail(callable $fn, string $reason): void
{
    try {
        $fn();
        throw new RuntimeException("Expected failure " . $reason);
    } catch (ShareError $e) {
        same($reason, $e->reason);
    }
}
function setup(
    string $name = "document.txt",
    string $content = "0123456789",
    array $legacy = [],
): array {
    global $roots;
    $root = sys_get_temp_dir() . "/share-test-" . bin2hex(random_bytes(8));
    $roots[] = $root;
    mkdir($root . "/files", 0700, true);
    mkdir($root . "/storage", 0700, true);
    file_put_contents($root . "/files/" . $name, $content);
    if ($legacy) {
        file_put_contents($root . "/storage/stats.json", json_encode($legacy));
    }
    $s = new ShareStore($root . "/files", $root . "/storage/stats.json");
    return [$s, $s->resolve($name), $root];
}
function ctx(): array
{
    return [
        "ip" => "203.0.113.8",
        "ip_source" => "peer",
        "region" => "未知",
        "geo_version" => "unavailable",
        "referrer" => "example.org/path",
    ];
}
function policy(ShareStore $s, array $f, array $p): array
{
    return $s->policy(
        $f["id"],
        array_merge(
            [
                "policy_version" => $f["policy_version"],
                "state" => "active",
                "auto_destroy" => (string) $f["auto_destroy"],
            ],
            $p,
        ),
        "test",
    );
}
function transfer(
    ShareStore $s,
    string $token,
    int $status = 200,
    int $bytes = 10,
    ?string $binding = null,
): array {
    $r = $s->beginTransfer($token, $status, "", $bytes, $binding);
    $s->finishTransfer($r["transfer_id"], $bytes, true);
    return $r;
}
$tests["imports legacy totals without inventing detailed events and is idempotent"] = function () {
    [$s, $f, $root] = setup("legacy.txt", "abc", [
        "legacy.txt" => [
            "downloads" => 37,
            "last_downloaded_at" => "2026-09-30T12:34:56+08:00",
        ],
        "deleted.txt" => ["downloads" => 2],
    ]);
    same(37, $f["legacy_count"]);
    same(37, $f["public_count"]);
    same(0, count($s->db->all("SELECT * FROM events")));
    same(39, (new QueryService($s))->analytics()["summary"]["lifetime"]);
    $s = new ShareStore($root . "/files", $root . "/storage/stats.json");
    same(39, (new QueryService($s))->analytics()["summary"]["lifetime"]);
};
$tests[
    "canonical aliases survive and same-name replacement does not steal old links"
] = function () {
    [$s, $f, $root] = setup("中文 空格 #.txt");
    same($f["id"], $s->resolve("中文 空格 #.txt")["id"]);
    same($f["id"], $s->resolve($f["public_id"])["id"]);
    $s->trash($f["id"]);
    file_put_contents($root . "/files/" . $f["name"], "new");
    $s->scan();
    same("trashed", $s->resolve($f["name"])["state"]);
    same(2, count($s->files()));
    same(0, $s->files()[0]["public_count"]);
};
$tests["candidate HEAD and conditional responses do not consume quota"] = function () {
    [$s, $f] = setup();
    $token = $s->createSession($f["id"], ctx());
    same(0, $s->file($f["id"])["public_count"]);
    $p = HttpDownload::plan($f, [
        "REQUEST_METHOD" => "HEAD",
        "HTTP_RANGE" => "bytes=0-2",
    ]);
    same(200, $p["status"]);
    same(10, $p["length"]);
    $p = HttpDownload::plan($f, [
        "HTTP_IF_NONE_MATCH" => '"' . $f["sha256"] . '"',
    ]);
    same(304, $p["status"]);
    same(0, $s->file($f["id"])["public_count"]);
};
$tests[
    "one public session counts once across partial retries and preserves last claim time"
] = function () {
    [$s, $f] = setup();
    $token = $s->createSession($f["id"], ctx());
    transfer($s, $token, 206, 4);
    $last = $s->file($f["id"])["last_public_at"];
    transfer($s, $token, 206, 6);
    same(1, $s->file($f["id"])["public_count"]);
    same($last, $s->file($f["id"])["last_public_at"]);
    $e = $s->db->one("SELECT * FROM events");
    same(2, (int) $e["request_count"]);
    same(10, (int) $e["observed_bytes"]);
    same("server_finished", $e["status"]);
    same([], (new QueryService($s))->reconcile());
};
$tests["invalid and multipart range rejection leaves candidates unclaimed"] = function () {
    [$s, $f] = setup();
    foreach (
        ["bytes=99-100", "bytes=7-2", "bytes=-0", "bytes=", "bytes=0-1,3-4", "garbage"]
        as $r
    ) {
        fail(fn() => HttpDownload::plan($f, ["HTTP_RANGE" => $r]), "range");
    }
    same(0, $s->file($f["id"])["public_count"]);
};
$tests["valid range suffix open range and if-range semantics"] = function () {
    [$s, $f] = setup();
    same(3, HttpDownload::plan($f, ["HTTP_RANGE" => "bytes=2-4"])["length"]);
    same(7, HttpDownload::plan($f, ["HTTP_RANGE" => "bytes=-3"])["offset"]);
    same(8, HttpDownload::plan($f, ["HTTP_RANGE" => "bytes=2-"])["length"]);
    same(
        200,
        HttpDownload::plan($f, [
            "HTTP_RANGE" => "bytes=2-4",
            "HTTP_IF_RANGE" => '"old"',
        ])["status"],
    );
    same(
        206,
        HttpDownload::plan($f, [
            "HTTP_RANGE" => "bytes=2-4",
            "HTTP_IF_RANGE" => '"' . $f["sha256"] . '"',
        ])["status"],
    );
};
$tests["password verification hashes and throttles without counting"] = function () {
    [$s, $f] = setup();
    $f = policy($s, $f, [
        "password_action" => "set",
        "password" => "file secret",
    ]);
    ok(!str_contains($f["password_hash"], "file secret"));
    for ($i = 0; $i < 8; $i++) {
        fail(fn() => $s->createSession($f["id"], ctx(), "public", "wrong"), "password");
    }
    fail(fn() => $s->createSession($f["id"], ctx(), "public", "file secret"), "rate_limit");
    same(0, $s->file($f["id"])["public_count"]);
    $other = ctx();
    $other["ip"] = "203.0.113.9";
    $token = $s->createSession($f["id"], $other, "public", "file secret");
    transfer($s, $token);
    same(1, $s->file($f["id"])["public_count"]);
};
$tests[
    "password changes invalidate candidates but retain claimed sessions unless revoked"
] = function () {
    [$s, $f] = setup();
    $old = $s->createSession($f["id"], ctx());
    $running = $s->createSession($f["id"], ctx());
    transfer($s, $running, 206, 2);
    $f = policy($s, $s->file($f["id"]), [
        "password_action" => "set",
        "password" => "new secret",
    ]);
    fail(fn() => $s->session($old), "policy_changed");
    transfer($s, $running, 206, 2);
    $f = policy($s, $f, ["revoke" => "1"]);
    fail(fn() => $s->session($running), "revoked");
    same(1, $f["public_count"]);
};
$tests["quota exhaustion additional allowance and pause never reset totals"] = function () {
    [$s, $f] = setup("quota.txt", "0123456789", [
        "quota.txt" => ["downloads" => 37],
    ]);
    $f = policy($s, $f, ["quota_mode" => "remaining", "quota_amount" => 2]);
    same(39, $f["public_cap"]);
    $t = $s->createSession($f["id"], ctx());
    transfer($s, $t);
    $t2 = $s->createSession($f["id"], ctx());
    transfer($s, $t2);
    same("exhausted", $s->file($f["id"])["state"]);
    fail(fn() => $s->createSession($f["id"], ctx()), "exhausted");
    transfer($s, $t, 206, 1);
    $f = policy($s, $s->file($f["id"]), [
        "quota_mode" => "add",
        "quota_amount" => 10,
    ]);
    same(49, $f["public_cap"]);
    same(10, $f["remaining"]);
    $f = policy($s, $f, ["state" => "paused"]);
    fail(fn() => $s->createSession($f["id"], ctx()), "paused");
    $f = policy($s, $f, ["state" => "active"]);
    same(39, $f["public_count"]);
    same(10, $f["remaining"]);
};
$tests[
    "optimistic policy conflict and missing destruction confirmation fail safely"
] = function () {
    [$s, $f] = setup();
    $new = policy($s, $f, ["quota_mode" => "remaining", "quota_amount" => 1]);
    fail(fn() => policy($s, $f, []), "conflict");
    fail(fn() => policy($s, $new, ["auto_destroy" => "1"]), "confirmation");
    same(0, $s->file($f["id"])["auto_destroy"]);
};
$tests[
    "admin tickets bypass public password paused quotas and do not touch public metrics"
] = function () {
    [$s, $f] = setup();
    $f = policy($s, $f, [
        "state" => "paused",
        "password_action" => "set",
        "password" => "protected",
        "quota_mode" => "remaining",
        "quota_amount" => 1,
    ]);
    $t = $s->createSession($f["id"], ctx(), "admin", null, "binding");
    transfer($s, $t, 200, 10, "binding");
    same(0, $s->file($f["id"])["public_count"]);
    same(null, $s->file($f["id"])["last_public_at"]);
    same(0, count($s->db->all("SELECT * FROM events")));
    fail(fn() => $s->session($t, "other"), "admin_expired");
    ok(count($s->db->all("SELECT * FROM audit WHERE action='download_ticket'")) === 1);
};
$tests["session expiry and request concurrency bounds fail closed"] = function () {
    [$s, $f] = setup();
    $t = $s->createSession($f["id"], ctx());
    for ($i = 0; $i < 3; $i++) {
        $s->beginTransfer($t, 206, "bytes=0-1", 2);
    }
    fail(fn() => $s->beginTransfer($t, 206, "bytes=0-1", 2), "session_limit");
    same(1, $s->file($f["id"])["public_count"]);
    $s->db->run("UPDATE sessions SET expires_at=?", [time() - 1]);
    fail(fn() => $s->session($t), "expired");
};
$tests["atomic last quota slot admits exactly one of twenty processes"] = function () {
    if (!function_exists("pcntl_fork")) {
        throw new RuntimeException("pcntl required for concurrency test");
    }
    [$s, $f, $root] = setup();
    $f = policy($s, $f, ["quota_mode" => "remaining", "quota_amount" => 1]);
    $tokens = [];
    for ($i = 0; $i < 20; $i++) {
        $tokens[] = $s->createSession($f["id"], ctx());
    }
    $children = [];
    foreach ($tokens as $i => $t) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $child = new ShareStore($root . "/files", $root . "/storage/stats.json");
                transfer($child, $t);
                file_put_contents($root . "/result-" . $i, "won");
            } catch (ShareError $e) {
                file_put_contents($root . "/result-" . $i, $e->reason);
            }
            exit(0);
        }
        ok($pid > 0);
        $children[] = $pid;
    }
    foreach ($children as $p) {
        pcntl_waitpid($p, $status);
    }
    $won = 0;
    foreach (glob($root . "/result-*") as $p) {
        if (file_get_contents($p) === "won") {
            $won++;
        }
    }
    same(1, $won);
    same(1, $s->file($f["id"])["public_count"]);
    same(1, count($s->db->all("SELECT * FROM events")));
};
$tests["transaction rollback preserves quota and events after insert failure"] = function () {
    [$s, $f] = setup();
    $t = $s->createSession($f["id"], ctx());
    $s->db->pdo->exec(
        "CREATE TRIGGER test_fail BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT,'test'); END",
    );
    try {
        $s->beginTransfer($t, 200, "", 10);
        throw new RuntimeException("failure not injected");
    } catch (PDOException) {
    }
    same(0, $s->file($f["id"])["public_count"]);
    same(null, $s->session($t)["session"]["claimed_at"]);
    $s->db->pdo->exec("DROP TRIGGER test_fail");
    transfer($s, $t);
    same(1, $s->file($f["id"])["public_count"]);
};
$tests["auto destruction defaults off and protects sessions plus active file locks"] = function () {
    [$s, $f, $root] = setup();
    same(0, $f["auto_destroy"]);
    $f = policy($s, $f, [
        "quota_mode" => "remaining",
        "quota_amount" => 1,
        "auto_destroy" => "1",
        "confirm_destroy" => $f["name"],
    ]);
    $t = $s->createSession($f["id"], ctx());
    transfer($s, $t);
    same("destroy_pending", $s->file($f["id"])["state"]);
    same(0, $s->processJobs()["destroyed"]);
    ok(is_file($f["path"]));
    $s->db->run("UPDATE sessions SET expires_at=?", [time() - 1]);
    $lock = $s->fileLock($f["id"], LOCK_SH);
    same(0, $s->processJobs()["destroyed"]);
    fclose($lock);
    same(1, $s->processJobs()["destroyed"]);
    same("destroyed", $s->file($f["id"])["state"]);
    ok(!file_exists($f["path"]));
    same(1, count($s->db->all("SELECT * FROM events")));
    same(1, $s->file($f["id"])["public_count"]);
    same(0, $s->processJobs()["destroyed"]);
};
$tests[
    "pending destruction cancels on quota extension or disabling and can be re-enabled"
] = function () {
    [$s, $f] = setup();
    $f = policy($s, $f, [
        "quota_mode" => "remaining",
        "quota_amount" => 1,
        "auto_destroy" => "1",
        "confirm_destroy" => $f["name"],
    ]);
    transfer($s, $s->createSession($f["id"], ctx()));
    $f = policy($s, $s->file($f["id"]), [
        "quota_mode" => "add",
        "quota_amount" => 2,
        "confirm_destroy" => $f["name"],
    ]);
    same("active", $f["state"]);
    same("cancelled", $s->db->one("SELECT * FROM jobs")["status"]);
    same(0, $s->processJobs()["destroyed"]);
};
$tests["destruction refuses shared hard-linked or changed entities"] = function () {
    [$s, $f, $root] = setup();
    $f = policy($s, $f, [
        "quota_mode" => "remaining",
        "quota_amount" => 1,
        "auto_destroy" => "1",
        "confirm_destroy" => $f["name"],
    ]);
    transfer($s, $s->createSession($f["id"], ctx()));
    $s->db->run("UPDATE sessions SET expires_at=?", [time() - 1]);
    link($f["path"], $root . "/outside");
    same(1, $s->processJobs()["failed"]);
    ok(is_file($f["path"]));
    same("destroying", $s->file($f["id"])["state"]);
    fail(fn() => $s->createSession($f["id"], ctx(), "admin", null, "binding"), "missing");
    unlink($root . "/outside");
    same(1, $s->processJobs()["destroyed"]);
};
$tests["recoverable deletion and restore retain counts and audit"] = function () {
    [$s, $f, $root] = setup();
    transfer($s, $s->createSession($f["id"], ctx()));
    $s->trash($f["id"]);
    $f = $s->file($f["id"]);
    same("trashed", $f["state"]);
    same("0123456789", file_get_contents($root . "/storage/trash/" . $f["trash_key"] . "/file"));
    $s->restore($f["id"], "test");
    $f = $s->file($f["id"]);
    same("paused", $f["state"]);
    same(1, $f["public_count"]);
    same(1, count($s->db->all("SELECT * FROM events")));
};
$tests["filesystem traversal symlinks hidden names and forged uploads rejected"] = function () {
    [$s, $f, $root] = setup();
    file_put_contents($root . "/outside", "outside");
    symlink($root . "/outside", $root . "/files/link.txt");
    $s->scan();
    same(1, count($s->files()));
    foreach (["../outside", ".env", "folder/file", "line\nbreak", 'folder\\file'] as $n) {
        try {
            ShareStore::validName($n);
            throw new RuntimeException("unsafe name accepted");
        } catch (InvalidArgumentException) {
            ok(true);
        }
    }
    fail(
        fn() => $s->upload([
            "error" => 0,
            "name" => "fake",
            "tmp_name" => $root . "/outside",
        ]),
        "upload",
    );
    same("outside", file_get_contents($root . "/outside"));
};
$tests[
    "IP forwarding only trusts verified right-hand proxy chain and strips source secrets"
] = function () {
    same(
        "198.51.100.1",
        RequestContext::capture([
            "REMOTE_ADDR" => "198.51.100.1",
            "HTTP_X_FORWARDED_FOR" => "1.1.1.1",
        ])["ip"],
    );
    $c = RequestContext::capture(
        [
            "REMOTE_ADDR" => "10.0.0.5",
            "HTTP_X_FORWARDED_FOR" => "2.2.2.2, 198.51.100.4, 10.0.0.4",
        ],
        ["10.0.0.0/8"],
    );
    same("198.51.100.4", $c["ip"]);
    ok(RequestContext::trusted("2001:db8::4", ["2001:db8::/32"]));
    same(
        "example.com/a",
        RequestContext::referrer("https://user:secret@example.com/a?token=private#fragment"),
    );
    same("", RequestContext::referrer("javascript:alert(1)"));
    same("example.com/[download]", RequestContext::referrer("https://example.com/transfer/secret"));
    same("'=IMPORTXML()", RequestContext::csv("=IMPORTXML()"));
};
$tests["local geography provider falls back honestly without a network call"] = function () {
    [$s, $f, $root] = setup();
    same(["未知", "unavailable"], RequestContext::geo("8.8.8.8", null));
    same("内网 / 保留地址", RequestContext::geo("127.0.0.1", null)[0]);
    $p = $root . "/geo.json";
    file_put_contents(
        $p,
        json_encode([
            "version" => "test-v1",
            "networks" => [
                [
                    "cidr" => "8.8.8.0/24",
                    "country" => "Example",
                    "region" => "Region",
                ],
            ],
        ]),
    );
    same(["Example / Region", "test-v1"], RequestContext::geo("8.8.8.8", $p));
};
$tests["timezone second-level display DST boundaries and analytics reconcile"] = function () {
    [$s, $f] = setup();
    $q = new QueryService($s);
    same("2026-01-01 08:00:01", format_time(1767225601, "Asia/Shanghai"));
    $s->saveSettings(["timezone" => "America/New_York", "session_ttl" => 86400], "test");
    $r = $q->range(["start" => "2026-03-08", "end" => "2026-03-08"]);
    same(23 * 3600, $r["until"] - $r["from"]);
    $r = $q->range(["start" => "2026-11-01", "end" => "2026-11-01"]);
    same(25 * 3600, $r["until"] - $r["from"]);
    $s->saveSettings(["timezone" => "Asia/Shanghai", "session_ttl" => 86400], "test");
    transfer($s, $s->createSession($f["id"], ctx()));
    $a = $q->analytics();
    same(1, $a["summary"]["total"]);
    same(1, array_sum(array_column($a["trend"], "count")));
    same(1, array_sum(array_column($a["hours"], "count")));
    same(1, $q->events(["region" => "未知"])["pagination"]["total"]);
    same(1, $a["summary"]["unique_ips"]);
};
$tests[
    "file mutation fails closed until explicit scan and previous version tokens stop"
] = function () {
    [$s, $f, $root] = setup();
    $t = $s->createSession($f["id"], ctx());
    file_put_contents($f["path"], "different length");
    ok(!$s->readable($f));
    fail(fn() => $s->beginTransfer($t, 200, "", 10), "changed");
    $s->scan();
    $next = $s->file($f["id"]);
    same(2, $next["version"]);
    fail(fn() => $s->session($t), "revoked");
    same(0, $next["public_count"]);
};
$tests["pre-upgrade recoverable packets migrate totals and remain UI-restorable"] = function () {
    global $roots;
    $root = sys_get_temp_dir() . "/share-test-" . bin2hex(random_bytes(8));
    $roots[] = $root;
    $key = "20260101-120000-1234567890abcdef";
    mkdir($root . "/files", 0700, true);
    mkdir($root . "/storage/trash/" . $key, 0700, true);
    file_put_contents($root . "/storage/trash/" . $key . "/file", "old recoverable bytes");
    file_put_contents(
        $root . "/storage/trash/" . $key . "/metadata.json",
        json_encode([
            "name" => "old.txt",
            "stats" => [
                "downloads" => 12,
                "last_downloaded_at" => "2026-01-01T12:00:00+08:00",
            ],
        ]),
    );
    $store = new ShareStore($root . "/files", $root . "/storage/stats.json");
    $file = $store->resolve("old.txt");
    same("trashed", $file["state"]);
    same(12, $file["public_count"]);
    same($key, $file["trash_key"]);
    $store = new ShareStore($root . "/files", $root . "/storage/stats.json");
    same(1, count($store->files()));
    $store->restore($file["id"], "test");
    same("old recoverable bytes", file_get_contents($root . "/files/old.txt"));
    same("paused", $store->file($file["id"])["state"]);
};
$tests["direct-source sentinel drills down to only missing referrers"] = function () {
    [$store, $file] = setup();
    $context = ctx();
    $context["referrer"] = "";
    transfer($store, $store->createSession($file["id"], $context));
    transfer($store, $store->createSession($file["id"], ctx()));
    same(
        1,
        (new QueryService($store))->events(["referrer" => "__direct__"])["pagination"]["total"],
    );
};
$tests["real MMDB fixture resolves an address entirely locally"] = function () {
    [$region, $version] = RequestContext::geo(
        "81.2.69.160",
        __DIR__ . "/fixtures/GeoIP2-City-Test.mmdb",
    );
    ok(
        str_contains($region, "英国") || str_contains($region, "United Kingdom"),
        "MMDB country lookup",
    );
    ok(str_contains($region, "London"), "MMDB city lookup");
    ok(str_starts_with($version, "GeoIP2-City"), "database type/version retained");
    same("未知", RequestContext::geo("1.1.1.1", __DIR__ . "/fixtures/GeoIP2-City-Test.mmdb")[0]);
};
$failures = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL $name: " . $e->getMessage() . "\n";
    }
}
echo count($tests) . " tests, $assertions assertions, $failures failures\n";
// Only this process removes its explicitly-created isolated fixtures, after fork children finish.
foreach ($roots as $root) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $item) {
        $item->isDir() && !$item->isLink()
            ? rmdir($item->getPathname())
            : unlink($item->getPathname());
    }
    rmdir($root);
}
exit($failures ? 1 : 0);
