<?php
declare(strict_types=1);
require_once __DIR__ . "/Database.php";
require_once __DIR__ . "/RequestContext.php";

final class ShareStore
{
    public const MAX_UPLOAD_BYTES = 45 * 1024 * 1024;
    public Database $db;
    public string $storageDir;
    public function __construct(public string $filesDir, string $metadataPath)
    {
        $this->storageDir = dirname($metadataPath);
        foreach (
            [
                $filesDir,
                $this->storageDir,
                $this->storageDir . "/locks",
                $this->storageDir . "/trash",
            ]
            as $dir
        ) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create private storage.");
            }
        }
        $this->filesDir = (string) realpath($filesDir);
        $this->db = new Database($this->storageDir . "/share.sqlite");
        if (!$this->db->one("SELECT 1 FROM settings WHERE key='legacy_migrated'")) {
            $this->migrate($metadataPath);
        }
    }
    private function migrate(string $path): void
    {
        $stats = [];
        if (is_file($path)) {
            $stats = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($stats)) {
                throw new RuntimeException("Invalid legacy statistics.");
            }
        }
        $this->scan("migration", $stats);
        $this->db->transaction(function () use ($stats): void {
            foreach ($stats as $name => $s) {
                if ($this->db->one("SELECT 1 FROM aliases WHERE alias=?", [(string) $name])) {
                    continue;
                }
                self::validName((string) $name);
                $this->insertFile(
                    (string) $name,
                    ["size" => 0, "mtime" => 0, "ino" => 0],
                    "",
                    "application/octet-stream",
                    (array) $s,
                    "missing",
                );
            }
            // Recoverable packets from the synchronized manager are known history too.
            foreach (glob($this->storageDir . "/trash/*/metadata.json") ?: [] as $metadata) {
                $directory = dirname($metadata);
                $key = basename($directory);
                if (
                    !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{16}$/D', $key) ||
                    is_link($directory) ||
                    is_link($metadata)
                ) {
                    continue;
                }
                if ($this->db->one("SELECT 1 FROM files WHERE trash_key=?", [$key])) {
                    continue;
                }
                $packet = json_decode(
                    (string) file_get_contents($metadata),
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
                $name = (string) ($packet["name"] ?? "");
                self::validName($name);
                $entity = $directory . "/file";
                $stat =
                    is_file($entity) && !is_link($entity)
                        ? stat($entity)
                        : ["size" => 0, "mtime" => 0, "ino" => 0];
                $hash =
                    is_file($entity) && !is_link($entity)
                        ? (hash_file("sha256", $entity) ?:
                        "")
                        : "";
                $id = $this->insertFile(
                    $name,
                    $stat,
                    $hash,
                    "application/octet-stream",
                    (array) ($packet["stats"] ?? []),
                    "trashed",
                );
                $this->db->run("UPDATE files SET trash_key=? WHERE id=?", [$key, $id]);
            }
            $this->db->run(
                "INSERT OR IGNORE INTO settings(key,value) VALUES('legacy_migrated',?)",
                [(string) time()],
            );
        });
    }
    public function scan(string $actor = "manager", array $legacy = []): array
    {
        $found = 0;
        $changed = 0;
        $missing = 0;
        foreach (scandir($this->filesDir) ?: [] as $name) {
            try {
                self::validName($name);
            } catch (InvalidArgumentException) {
                continue;
            }
            $path = $this->filesDir . "/" . $name;
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $stat = stat($path);
            if (!$stat) {
                continue;
            }
            $old = $this->db->one(
                "SELECT * FROM files WHERE storage_name=? AND state NOT IN ('trashed','destroyed') ORDER BY id DESC LIMIT 1",
                [$name],
            );
            if (
                $old &&
                (int) $old["bytes"] === $stat["size"] &&
                (int) $old["mtime"] === $stat["mtime"] &&
                (int) $old["inode"] === $stat["ino"] &&
                $old["state"] !== "missing"
            ) {
                continue;
            }
            // Hash only during explicit indexing, under the same lock used by transfers.
            $lock = $old ? $this->fileLock((int) $old["id"], LOCK_EX | LOCK_NB) : null;
            if ($old && !$lock) {
                continue;
            }
            try {
                $hash = hash_file("sha256", $path);
                if ($hash === false) {
                    continue;
                }
                $mime = function_exists("mime_content_type")
                    ? (mime_content_type($path) ?:
                    "application/octet-stream")
                    : "application/octet-stream";
                $this->db->transaction(function () use (
                    $old,
                    $name,
                    $stat,
                    $hash,
                    $mime,
                    $legacy,
                    &$found,
                    &$changed,
                ): void {
                    if (!$old) {
                        $this->insertFile(
                            $name,
                            $stat,
                            $hash,
                            $mime,
                            (array) ($legacy[$name] ?? []),
                        );
                        $found++;
                    } else {
                        // Re-read after hashing: another admin may have changed the lifecycle policy.
                        $old = $this->file((int) $old["id"]);
                        if (in_array($old["state"], ["destroying", "destroy_pending"], true)) {
                            return;
                        }
                        $v = (int) $old["version"] + 1;
                        $this->db->run(
                            "UPDATE files SET bytes=?,mtime=?,inode=?,sha256=?,mime_type=?,version=?,revocation_epoch=revocation_epoch+1,policy_version=policy_version+1,state=CASE WHEN state='missing' THEN 'paused' ELSE state END,updated_at=? WHERE id=?",
                            [
                                $stat["size"],
                                $stat["mtime"],
                                $stat["ino"],
                                $hash,
                                $mime,
                                $v,
                                time(),
                                $old["id"],
                            ],
                        );
                        $this->db->run(
                            "INSERT INTO versions(file_id,version,storage_name,sha256,bytes,created_at) VALUES(?,?,?,?,?,?)",
                            [$old["id"], $v, $name, $hash, $stat["size"], time()],
                        );
                        $changed++;
                    }
                });
            } finally {
                if ($lock) {
                    fclose($lock);
                }
            }
        }
        foreach (
            $this->db->all(
                "SELECT * FROM files WHERE state NOT IN ('trashed','destroyed','destroying','missing')",
            )
            as $row
        ) {
            if (!$this->readable($row)) {
                $this->db->run("UPDATE files SET state='missing',updated_at=? WHERE id=?", [
                    time(),
                    $row["id"],
                ]);
                $missing++;
            }
        }
        $this->audit($actor, "scan", null, [
            "imported" => $found,
            "changed" => $changed,
            "missing" => $missing,
        ]);
        return compact("found", "changed", "missing");
    }
    private function insertFile(
        string $name,
        array $s,
        string $hash,
        string $mime,
        array $legacy = [],
        string $state = "active",
    ): int {
        if ($this->db->one("SELECT 1 FROM files WHERE public_id=?", [$name])) {
            throw new ShareError("reserved_name", "文件名与现有分享标识冲突，请重命名", 409);
        }
        $count = max(0, (int) ($legacy["downloads"] ?? 0));
        $last = null;
        if (!empty($legacy["last_downloaded_at"])) {
            $raw = (string) $legacy["last_downloaded_at"];
            if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $raw)) {
                throw new RuntimeException("Legacy timestamp needs an explicit offset: " . $name);
            }
            try {
                $last = (new DateTimeImmutable($raw))->getTimestamp();
            } catch (Throwable) {
                throw new RuntimeException("Invalid legacy timestamp: " . $name);
            }
        }
        $this->db->run(
            "INSERT INTO files(public_id,name,storage_name,bytes,mtime,inode,sha256,mime_type,state,public_count,legacy_count,last_public_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                bin2hex(random_bytes(12)),
                $name,
                $name,
                $s["size"],
                $s["mtime"],
                $s["ino"],
                $hash,
                $mime,
                $state,
                $count,
                $count,
                $last,
                time(),
                time(),
            ],
        );
        $id = (int) $this->db->pdo->lastInsertId();
        $this->db->run("INSERT OR IGNORE INTO aliases(alias,file_id) VALUES(?,?)", [$name, $id]);
        $this->db->run(
            "INSERT INTO versions(file_id,version,storage_name,sha256,bytes,created_at) VALUES(?,1,?,?,?,?)",
            [$id, $name, $hash, $s["size"], time()],
        );
        return $id;
    }
    public function files(array $filters = []): array
    {
        $where = [];
        $args = [];
        if (($filters["q"] ?? "") !== "") {
            $where[] = "name LIKE ?";
            $args[] = "%" . $filters["q"] . "%";
        }
        if (($filters["state"] ?? "") === "attention") {
            $where[] = "state IN ('exhausted','missing','destroy_pending','destroying')";
        } elseif (($filters["state"] ?? "") !== "") {
            $where[] = "state=?";
            $args[] = $filters["state"];
        }
        if (($filters["password"] ?? "") === "yes") {
            $where[] = "password_hash IS NOT NULL";
        }
        if (($filters["password"] ?? "") === "no") {
            $where[] = "password_hash IS NULL";
        }
        $sort = match ($filters["sort"] ?? "newest") {
            "name" => "name COLLATE NOCASE",
            "downloads" => "public_count DESC,id DESC",
            "size" => "bytes DESC,id DESC",
            default => "id DESC",
        };
        return array_map(
            fn($r) => $this->decorate($r),
            $this->db->all(
                "SELECT * FROM files" .
                    ($where ? " WHERE " . implode(" AND ", $where) : "") .
                    " ORDER BY " .
                    $sort,
                $args,
            ),
        );
    }
    public function file(int $id): array
    {
        $f = $this->db->one("SELECT * FROM files WHERE id=?", [$id]);
        if (!$f) {
            throw new ShareError("not_found", "文件不存在", 404);
        }
        return $this->decorate($f);
    }
    public function resolve(string $link): array
    {
        self::validName($link);
        $f = $this->db->one("SELECT * FROM files WHERE public_id=?", [$link]);
        if (!$f) {
            $f = $this->db->one(
                "SELECT f.* FROM files f JOIN aliases a ON a.file_id=f.id WHERE a.alias=?",
                [$link],
            );
        }
        if (!$f) {
            throw new ShareError("not_found", "未找到这个分享文件", 404);
        }
        return $this->decorate($f);
    }
    private function decorate(array $f): array
    {
        foreach (
            [
                "id",
                "bytes",
                "version",
                "public_count",
                "legacy_count",
                "policy_version",
                "mtime",
                "created_at",
                "updated_at",
                "auto_destroy",
                "revocation_epoch",
                "inode",
            ]
            as $k
        ) {
            $f[$k] = (int) $f[$k];
        }
        $f["public_cap"] = $f["public_cap"] === null ? null : (int) $f["public_cap"];
        $f["remaining"] =
            $f["public_cap"] === null ? null : max(0, $f["public_cap"] - $f["public_count"]);
        $f["has_password"] = $f["password_hash"] !== null;
        $f["downloads"] = $f["public_count"];
        $f["last_downloaded_at"] = $f["last_public_at"]
            ? gmdate("c", (int) $f["last_public_at"])
            : null;
        $f["path"] = $this->filesDir . "/" . $f["storage_name"];
        return $f;
    }
    public function listFiles(): array
    {
        return array_values(
            array_filter(
                $this->files(),
                fn($f) => !in_array($f["state"], ["trashed", "destroyed", "missing"], true),
            ),
        );
    }
    public function get(string $name): ?array
    {
        try {
            return $this->resolve($name);
        } catch (ShareError) {
            return null;
        }
    }
    public function settings(): array
    {
        return array_column($this->db->all("SELECT * FROM settings"), "value", "key");
    }
    public function saveSettings(array $input, string $actor): void
    {
        $tz = (string) ($input["timezone"] ?? "");
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            throw new ShareError("timezone", "请选择有效的 IANA 时区");
        }
        $ttl = filter_var($input["session_ttl"] ?? 86400, FILTER_VALIDATE_INT);
        if (!$ttl || $ttl < 300 || $ttl > 172800) {
            throw new ShareError("ttl", "续传有效期应在 300–172800 秒之间");
        }
        $this->db->transaction(function () use ($tz, $ttl, $actor) {
            foreach (["timezone" => $tz, "session_ttl" => (string) $ttl] as $k => $v) {
                $this->db->run("UPDATE settings SET value=? WHERE key=?", [$v, $k]);
            }
            $this->audit($actor, "settings", null, [
                "timezone" => $tz,
                "session_ttl" => $ttl,
            ]);
        });
    }
    public function policy(int $id, array $in, string $actor): array
    {
        // Expensive hashing is intentionally outside the write transaction.
        $passwordAction = $in["password_action"] ?? "keep";
        $hash = null;
        if ($passwordAction === "set") {
            $p = (string) ($in["password"] ?? "");
            if (strlen($p) < 4 || strlen($p) > 512) {
                throw new ShareError("password", "文件密码应为 4–512 字节");
            }
            $hash = password_hash(
                $p,
                defined("PASSWORD_ARGON2ID") ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT,
            );
        }
        return $this->db->transaction(function () use ($id, $in, $actor, $passwordAction, $hash) {
            $f = $this->file($id);
            if ((int) ($in["policy_version"] ?? 0) !== $f["policy_version"]) {
                throw new ShareError("conflict", "此文件设置已在另一个窗口更改，请刷新后重试", 409);
            }
            if (in_array($f["state"], ["destroying", "destroyed", "trashed"], true)) {
                throw new ShareError("unavailable", "此文件当前不可修改分享策略", 409);
            }
            $cap = $f["public_cap"];
            $mode = $in["quota_mode"] ?? "keep";
            if ($mode === "unlimited") {
                $cap = null;
            } elseif (in_array($mode, ["remaining", "add"], true)) {
                $n = filter_var($in["quota_amount"] ?? null, FILTER_VALIDATE_INT);
                if ($n === false || $n < 1 || $n > 100000000) {
                    throw new ShareError("quota", "名额须为 1–100000000 的整数");
                }
                $cap =
                    ($mode === "add" && $cap !== null
                        ? max($cap, $f["public_count"])
                        : $f["public_count"]) + $n;
            }
            $auto = isset($in["auto_destroy"]) && (string) $in["auto_destroy"] === "1";
            if ($auto && $cap === null) {
                throw new ShareError("destroy_quota", "自动销毁需要先设置下载额度");
            }
            if (
                $auto &&
                (!$f["auto_destroy"] || $cap !== $f["public_cap"]) &&
                (string) ($in["confirm_destroy"] ?? "") !== $f["name"]
            ) {
                throw new ShareError(
                    "confirmation",
                    "请输入完整文件名，确认达到额度后自动永久删除服务器文件",
                );
            }
            $state = ($in["state"] ?? $f["state"]) === "paused" ? "paused" : "active";
            if ($f["state"] === "missing") {
                $state = "missing";
            } elseif ($cap !== null && $f["public_count"] >= $cap) {
                $state = $auto ? "destroy_pending" : "exhausted";
            }
            $pass = match ($passwordAction) {
                "remove" => null,
                "set" => $hash,
                default => $f["password_hash"],
            };
            $epoch = $f["revocation_epoch"] + (!empty($in["revoke"]) ? 1 : 0);
            $this->db->run(
                "UPDATE files SET state=?,public_cap=?,password_hash=?,auto_destroy=?,revocation_epoch=?,policy_version=policy_version+1,updated_at=? WHERE id=?",
                [$state, $cap, $pass, (int) $auto, $epoch, time(), $id],
            );
            if ($state === "destroy_pending") {
                $this->scheduleDestruction($f);
            } else {
                $this->db->run(
                    "UPDATE jobs SET status='cancelled',updated_at=? WHERE file_id=? AND status='pending'",
                    [time(), $id],
                );
            }
            $this->audit($actor, "policy", $id, [
                "state" => $state,
                "cap" => $cap,
                "auto_destroy" => $auto,
                "password_action" => $passwordAction,
                "revoke" => !empty($in["revoke"]),
                "policy_version" => $f["policy_version"] + 1,
            ]);
            return $this->file($id);
        });
    }
    public function allowNew(array $f): void
    {
        if ($f["state"] !== "active") {
            throw new ShareError(
                $f["state"],
                match ($f["state"]) {
                    "paused" => "分享已暂停",
                    "exhausted" => "下载名额已用完",
                    "destroy_pending" => "下载名额已用完，正在等待已有下载结束",
                    "destroying" => "文件正在销毁",
                    "destroyed" => "文件已销毁",
                    "missing" => "文件暂时缺失，请联系分享者",
                    "trashed" => "分享已移入回收目录",
                    default => "分享不可用",
                },
                in_array($f["state"], ["destroyed", "trashed"], true) ? 410 : 409,
            );
        }
        if ($f["public_cap"] !== null && $f["public_count"] >= $f["public_cap"]) {
            throw new ShareError("exhausted", "下载名额已用完", 409);
        }
    }
    public function createSession(
        int $id,
        array $context,
        string $actor = "public",
        ?string $password = null,
        ?string $binding = null,
        string $adminName = "manager",
    ): string {
        $f = $this->file($id);
        $now = time();
        if ($actor === "public") {
            $this->allowNew($f);
            if ($f["has_password"]) {
                $buckets = [
                    "unlock:file:" . $id . ":" . hash("sha256", $context["ip"]),
                    "unlock:ip:" . hash("sha256", $context["ip"]),
                    "unlock:global",
                ];
                $this->rateCheck($buckets, [8, 40, 500]);
                if ($password === null || !password_verify($password, $f["password_hash"])) {
                    $this->rateFailure($buckets);
                    $this->attempt($id, $context["ip"], "password", 401);
                    throw new ShareError("password", "文件密码不正确", 401);
                }
            }
        } elseif ($actor !== "admin" || !$binding) {
            throw new ShareError("actor", "无效的管理授权", 403);
        }
        if (
            in_array($f["state"], ["destroying", "destroyed", "trashed", "missing"], true) ||
            !$this->readable($f)
        ) {
            throw new ShareError("missing", "文件不可用", 410);
        }
        $token = bin2hex(random_bytes(32));
        $this->db->transaction(function () use (
            $id,
            $f,
            $actor,
            $context,
            $binding,
            $token,
            $now,
            $adminName,
        ) {
            $current = $this->file($id);
            if (
                $current["policy_version"] !== $f["policy_version"] ||
                $current["version"] !== $f["version"]
            ) {
                throw new ShareError("conflict", "分享设置刚刚变更，请重试", 409);
            }
            if ($actor === "public") {
                $this->allowNew($current);
            }
            if (
                in_array($current["state"], ["destroying", "destroyed", "trashed", "missing"], true)
            ) {
                throw new ShareError("unavailable", "文件不可用", 410);
            }
            $this->db->run(
                "INSERT INTO sessions(token_hash,file_id,version,policy_version,revocation_epoch,actor,admin_binding,created_at,expires_at,ip,ip_source,region,geo_version,referrer) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    hash("sha256", $token),
                    $id,
                    $f["version"],
                    $f["policy_version"],
                    $f["revocation_epoch"],
                    $actor,
                    $binding,
                    $now,
                    $now + 300,
                    $context["ip"],
                    $context["ip_source"],
                    $context["region"],
                    $context["geo_version"],
                    $context["referrer"],
                ],
            );
            if ($actor === "admin") {
                $this->audit($adminName, "download_ticket", $id, [
                    "version" => $f["version"],
                ]);
            }
        });
        return $token;
    }
    public function session(string $token, ?string $binding = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new ShareError("token", "下载授权无效", 404);
        }
        $s = $this->db->one("SELECT * FROM sessions WHERE token_hash=?", [hash("sha256", $token)]);
        if (!$s || $s["expires_at"] <= time()) {
            throw new ShareError("expired", "下载授权已过期，请重新打开分享链接", 410);
        }
        $f = $this->file((int) $s["file_id"]);
        if (
            (int) $s["revocation_epoch"] !== $f["revocation_epoch"] ||
            (int) $s["version"] !== $f["version"]
        ) {
            throw new ShareError("revoked", "此下载授权已撤销，请重新打开分享链接", 410);
        }
        if (
            $s["actor"] === "admin" &&
            (!$binding || !hash_equals((string) $s["admin_binding"], $binding))
        ) {
            throw new ShareError("admin_expired", "管理登录已失效，请重新登录", 403);
        }
        if (!$s["claimed_at"] && (int) $s["policy_version"] !== $f["policy_version"]) {
            throw new ShareError("policy_changed", "分享设置已更改，请重新打开分享链接", 409);
        }
        if (in_array($f["state"], ["destroying", "destroyed", "trashed", "missing"], true)) {
            throw new ShareError("unavailable", "文件不可用", 410);
        }
        return ["session" => $s, "file" => $f];
    }
    /** Called only after method/condition/range validation and a held shared file lock. */
    public function beginTransfer(
        string $token,
        int $status,
        string $range,
        int $expected,
        ?string $binding = null,
        ?array $claimContext = null,
    ): array {
        return $this->db->transaction(function () use (
            $token,
            $status,
            $range,
            $expected,
            $binding,
            $claimContext,
        ) {
            ["session" => $s, "file" => $f] = $this->session($token, $binding);
            $now = time();
            if (!$this->readable($f)) {
                throw new ShareError("changed", "文件内容已改变或缺失，请重新索引", 409);
            }
            $inflight = (int) $this->db->one(
                "SELECT COUNT(*) n FROM transfers WHERE session_id=? AND status='inflight'",
                [$s["id"]],
            )["n"];
            if (
                $inflight >= 3 ||
                (int) $s["request_count"] >= 128 ||
                (int) $s["observed_bytes"] > max(1, $f["bytes"]) * 3
            ) {
                throw new ShareError(
                    "session_limit",
                    "此下载会话请求过多，请稍后重试或重新打开链接",
                    429,
                );
            }
            if (!$s["claimed_at"]) {
                if ($s["actor"] === "public") {
                    $this->allowNew($f);
                    if ($claimContext) {
                        foreach (["ip", "ip_source", "region", "geo_version"] as $key) {
                            $s[$key] = $claimContext[$key];
                        }
                        $this->db->run(
                            "UPDATE sessions SET ip=?,ip_source=?,region=?,geo_version=? WHERE id=?",
                            [$s["ip"], $s["ip_source"], $s["region"], $s["geo_version"], $s["id"]],
                        );
                    }
                    $this->db->run(
                        "UPDATE files SET public_count=public_count+1,last_public_at=?,updated_at=? WHERE id=?",
                        [$now, $now, $f["id"]],
                    );
                    $this->db->run(
                        "INSERT INTO events(session_id,file_id,version,filename,started_at,last_request_at,ip,ip_source,region,geo_version,referrer) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                        [
                            $s["id"],
                            $f["id"],
                            $f["version"],
                            $f["name"],
                            $now,
                            $now,
                            $s["ip"],
                            $s["ip_source"],
                            $s["region"],
                            $s["geo_version"],
                            $s["referrer"],
                        ],
                    );
                    if ($f["public_cap"] !== null && $f["public_count"] + 1 >= $f["public_cap"]) {
                        $this->db->run("UPDATE files SET state=? WHERE id=?", [
                            $f["auto_destroy"] ? "destroy_pending" : "exhausted",
                            $f["id"],
                        ]);
                        if ($f["auto_destroy"]) {
                            $this->scheduleDestruction($f);
                        }
                    }
                }
                $ttl = (int) $this->settings()["session_ttl"];
                $this->db->run("UPDATE sessions SET claimed_at=?,expires_at=? WHERE id=?", [
                    $now,
                    $now + $ttl,
                    $s["id"],
                ]);
            }
            $this->db->run("UPDATE sessions SET request_count=request_count+1 WHERE id=?", [
                $s["id"],
            ]);
            $this->db->run(
                "UPDATE events SET request_count=request_count+1,last_request_at=?,http_status=?,status=? WHERE session_id=?",
                [$now, $status, "unknown", $s["id"]],
            );
            $this->db->run(
                "INSERT INTO transfers(session_id,started_at,http_status,range_header,expected_bytes) VALUES(?,?,?,?,?)",
                [$s["id"], $now, $status, substr($range, 0, 200), $expected],
            );
            return [
                "transfer_id" => (int) $this->db->pdo->lastInsertId(),
                "file" => $f,
                "session_id" => (int) $s["id"],
            ];
        });
    }
    public function finishTransfer(int $id, int $bytes, bool $complete): void
    {
        $this->db->transaction(function () use ($id, $bytes, $complete) {
            $t = $this->db->one("SELECT * FROM transfers WHERE id=?", [$id]);
            if (!$t || $t["ended_at"]) {
                return;
            }
            $state = $complete ? "server_finished" : "interrupted";
            $this->db->run("UPDATE transfers SET ended_at=?,observed_bytes=?,status=? WHERE id=?", [
                time(),
                $bytes,
                $state,
                $id,
            ]);
            $this->db->run("UPDATE sessions SET observed_bytes=observed_bytes+? WHERE id=?", [
                $bytes,
                $t["session_id"],
            ]);
            $this->db->run(
                "UPDATE events SET observed_bytes=observed_bytes+?,status=? WHERE session_id=?",
                [$bytes, $state, $t["session_id"]],
            );
        });
    }
    public function readable(array $f): bool
    {
        $name = (string) $f["storage_name"];
        try {
            self::validName($name);
        } catch (Throwable) {
            return false;
        }
        $p = $this->filesDir . "/" . $name;
        clearstatcache(true, $p);
        if (
            is_link($p) ||
            !is_file($p) ||
            !is_readable($p) ||
            dirname((string) realpath($p)) !== $this->filesDir
        ) {
            return false;
        }
        $s = stat($p);
        return $s &&
            (int) $f["bytes"] === $s["size"] &&
            (int) $f["mtime"] === $s["mtime"] &&
            (int) $f["inode"] === $s["ino"];
    }
    public function fileLock(int $id, int $mode): mixed
    {
        $h = fopen($this->storageDir . "/locks/" . $id . ".lock", "c");
        if (!$h) {
            throw new RuntimeException("Cannot open transfer lock");
        }
        if (!flock($h, $mode)) {
            fclose($h);
            return false;
        }
        return $h;
    }
    private function scheduleDestruction(array $f): void
    {
        $key = "destroy:" . $f["id"] . ":" . $f["version"];
        $this->db->run(
            "INSERT INTO jobs(job_key,file_id,version,created_at,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(job_key) DO UPDATE SET status='pending',updated_at=excluded.updated_at,error=NULL",
            [$key, $f["id"], $f["version"], time(), time()],
        );
    }
    public function processJobs(): array
    {
        $out = ["destroyed" => 0, "waiting" => 0, "failed" => 0];
        foreach ($this->db->all("SELECT * FROM jobs WHERE status IN ('pending','running')") as $j) {
            $lock = $this->fileLock((int) $j["file_id"], LOCK_EX | LOCK_NB);
            if (!$lock) {
                $out["waiting"]++;
                continue;
            }
            try {
                $f = $this->db->transaction(function () use ($j) {
                    $f = $this->file((int) $j["file_id"]);
                    if (
                        $f["version"] !== (int) $j["version"] ||
                        !$f["auto_destroy"] ||
                        $f["public_cap"] === null ||
                        $f["public_count"] < $f["public_cap"] ||
                        !in_array($f["state"], ["destroy_pending", "destroying"], true)
                    ) {
                        $this->db->run(
                            "UPDATE jobs SET status='cancelled',updated_at=? WHERE id=?",
                            [time(), $j["id"]],
                        );
                        return null;
                    }
                    $active = $this->db->one(
                        "SELECT COUNT(*) n FROM sessions WHERE file_id=? AND expires_at>?",
                        [$f["id"], time()],
                    );
                    if ((int) $active["n"] > 0) {
                        return null;
                    }
                    // A held exclusive lock proves no PHP transfer owns this entity. Expired crash remnants remain diagnostic unknowns.
                    $this->db->run("UPDATE files SET state='destroying',updated_at=? WHERE id=?", [
                        time(),
                        $f["id"],
                    ]);
                    $this->db->run("UPDATE jobs SET status='running',updated_at=? WHERE id=?", [
                        time(),
                        $j["id"],
                    ]);
                    return $f;
                });
                if (!$f) {
                    $out["waiting"]++;
                    continue;
                }
                $path = $f["path"];
                clearstatcache(true, $path);
                if (file_exists($path) || is_link($path)) {
                    if (!$this->readable($f)) {
                        throw new RuntimeException("Entity changed; destruction deferred.");
                    }
                    $s = stat($path);
                    if (!$s || $s["nlink"] !== 1) {
                        throw new RuntimeException(
                            "Shared or unverifiable entity; destruction deferred.",
                        );
                    }
                    if (!unlink($path)) {
                        throw new RuntimeException("Entity removal failed.");
                    }
                }
                $this->db->transaction(function () use ($f, $j) {
                    $this->db->run(
                        "UPDATE files SET state='destroyed',destroyed_at=?,updated_at=? WHERE id=?",
                        [time(), time(), $f["id"]],
                    );
                    $this->db->run(
                        "UPDATE jobs SET status='done',updated_at=?,error=NULL WHERE id=?",
                        [time(), $j["id"]],
                    );
                    $this->audit("system", "auto_destroy", $f["id"], [
                        "version" => $f["version"],
                        "records_retained" => true,
                    ]);
                });
                $out["destroyed"]++;
            } catch (Throwable $e) {
                $this->db->run("UPDATE jobs SET error=?,updated_at=? WHERE id=?", [
                    substr($e->getMessage(), 0, 200),
                    time(),
                    $j["id"],
                ]);
                error_log("Share destruction job deferred: " . $j["id"]);
                $out["failed"]++;
            } finally {
                fclose($lock);
            }
        }
        return $out;
    }
    public function upload(array $u, string $actor = "manager"): string
    {
        if (($u["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ShareError("upload", "上传失败，请检查文件是否完整且不超过 45 MiB");
        }
        $name = $u["name"] ?? "";
        $temp = $u["tmp_name"] ?? "";
        if (!is_string($name) || !is_string($temp)) {
            throw new ShareError("upload", "无效的上传请求");
        }
        self::validName($name);
        if (!is_uploaded_file($temp) || filesize($temp) > self::MAX_UPLOAD_BYTES) {
            throw new ShareError("upload", "无效的上传文件，或文件超过 45 MiB");
        }
        $target = $this->filesDir . "/" . $name;
        $stage = $this->filesDir . "/.upload-" . bin2hex(random_bytes(12));
        if (!move_uploaded_file($temp, $stage)) {
            throw new RuntimeException("Upload staging failed");
        }
        try {
            chmod($stage, 0600);
            if (file_exists($target) || is_link($target) || !@link($stage, $target)) {
                throw new ShareError("duplicate", "已存在同名文件，请重命名后上传", 409);
            }
        } finally {
            if (is_file($stage)) {
                unlink($stage);
            }
        }
        $this->scan($actor);
        $this->audit($actor, "upload", null, ["filename" => $name]);
        return $name;
    }
    public function trash(string|int $target, string $actor = "manager"): void
    {
        $f = is_int($target) ? $this->file($target) : $this->resolve($target);
        $lock = $this->fileLock($f["id"], LOCK_EX | LOCK_NB);
        if (!$lock) {
            throw new ShareError("inflight", "文件正在传输，请稍后再移入回收目录", 409);
        }
        try {
            $this->db->transaction(function () use ($f, $actor) {
                $current = $this->file($f["id"]);
                if (
                    in_array($current["state"], ["destroying", "destroyed", "trashed"], true) ||
                    !$this->readable($current)
                ) {
                    throw new ShareError("unavailable", "文件不可移入回收目录", 409);
                }
                $key = gmdate("Ymd-His") . "-" . bin2hex(random_bytes(8));
                $dir = $this->storageDir . "/trash/" . $key;
                if (!mkdir($dir, 0700)) {
                    throw new RuntimeException("Cannot create recovery directory");
                }
                $metadata = [
                    "name" => $f["name"],
                    "file_id" => $f["id"],
                    "deleted_at" => gmdate("c"),
                    "stats" => [
                        "downloads" => $f["public_count"],
                        "last_downloaded_at" => $f["last_downloaded_at"],
                    ],
                ];
                if (
                    file_put_contents(
                        $dir . "/metadata.json",
                        json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ) === false
                ) {
                    throw new RuntimeException("Cannot save recovery metadata");
                }
                if (!rename($f["path"], $dir . "/file")) {
                    throw new RuntimeException("Cannot move entity");
                }
                $this->db->run(
                    "UPDATE files SET state='trashed',trash_key=?,revocation_epoch=revocation_epoch+1,updated_at=? WHERE id=?",
                    [$key, time(), $f["id"]],
                );
                $this->db->run(
                    "UPDATE jobs SET status='cancelled' WHERE file_id=? AND status='pending'",
                    [$f["id"]],
                );
                $this->audit($actor, "trash", $f["id"], [
                    "records_retained" => true,
                ]);
            });
        } finally {
            fclose($lock);
        }
    }
    public function restore(int $id, string $actor): void
    {
        $f = $this->file($id);
        if (
            $f["state"] !== "trashed" ||
            !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{16}$/D', (string) $f["trash_key"])
        ) {
            throw new ShareError("restore", "文件不在回收目录", 409);
        }
        $lock = $this->fileLock($id, LOCK_EX);
        try {
            $this->db->transaction(function () use ($f, $actor) {
                $source = $this->storageDir . "/trash/" . $f["trash_key"] . "/file";
                $target = $f["path"];
                if (
                    file_exists($target) ||
                    is_link($target) ||
                    !is_file($source) ||
                    is_link($source)
                ) {
                    throw new ShareError("restore", "同名文件已存在或恢复实体不可用", 409);
                }
                if (!rename($source, $target)) {
                    throw new RuntimeException("Restore failed");
                }
                $s = stat($target);
                $this->db->run(
                    "UPDATE files SET state='paused',trash_key=NULL,mtime=?,inode=?,policy_version=policy_version+1,updated_at=? WHERE id=?",
                    [$s["mtime"], $s["ino"], time(), $f["id"]],
                );
                $this->audit($actor, "restore", $f["id"], [
                    "state" => "paused",
                ]);
            });
        } finally {
            fclose($lock);
        }
    }
    public function audit(string $actor, string $action, ?int $id, array $detail = []): void
    {
        $this->db->run(
            "INSERT INTO audit(occurred_at,actor,action,file_id,detail) VALUES(?,?,?,?,?)",
            [
                time(),
                $actor,
                $action,
                $id,
                json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        );
    }
    public function attempt(?int $id, string $ip, string $reason, int $status): void
    {
        $this->db->run(
            "INSERT INTO attempts(file_id,occurred_at,ip,reason,http_status) VALUES(?,?,?,?,?)",
            [$id, time(), $ip, $reason, $status],
        );
    }
    public function rateCheck(array $keys, array $limits): void
    {
        foreach ($keys as $i => $k) {
            $r = $this->db->one("SELECT * FROM rate_limits WHERE bucket=?", [$k]);
            if ($r && $r["until_at"] > time() && $r["attempts"] >= $limits[$i]) {
                throw new ShareError("rate_limit", "尝试次数过多，请 15 分钟后重试", 429);
            }
        }
    }
    public function rateFailure(array $keys): void
    {
        $this->db->transaction(function () use ($keys) {
            foreach ($keys as $k) {
                $r = $this->db->one("SELECT * FROM rate_limits WHERE bucket=?", [$k]);
                $count = $r && $r["until_at"] > time() ? (int) $r["attempts"] + 1 : 1;
                $until = $r && $r["until_at"] > time() ? (int) $r["until_at"] : time() + 900;
                $this->db->run(
                    "INSERT INTO rate_limits(bucket,attempts,until_at) VALUES(?,?,?) ON CONFLICT(bucket) DO UPDATE SET attempts=excluded.attempts,until_at=excluded.until_at",
                    [$k, $count, $until],
                );
            }
        });
    }
    public static function validName(string $n): void
    {
        if (
            preg_match("//u", $n) !== 1 ||
            $n === "" ||
            str_starts_with($n, ".") ||
            strlen($n) > 240 ||
            preg_match('/[\x00-\x1f\x7f\/\\\\]/', $n)
        ) {
            throw new InvalidArgumentException("文件名无效：不可包含路径、控制字符或以点开头");
        }
    }
}
