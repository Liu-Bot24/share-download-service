<?php
declare(strict_types=1);

/** A time-bounded upload capability. It never grants read or administrator access. */
final class GuestUploads
{
    public const MAX_FILES = 100;
    public const MAX_BYTES = 1073741824;
    public function __construct(private ShareStore $store) {}

    public function window(): array
    {
        $row = $this->store->db->one("SELECT * FROM guest_upload_window WHERE id=1") ?? [];
        return array_merge($row, [
            "active" =>
                !empty($row) && $row["closed_at"] === null && (int) $row["expires_at"] > time(),
            "server_time" => time(),
        ]);
    }
    public function open(int $minutes, string $actor): void
    {
        if ($minutes < 1 || $minutes > 1440) {
            throw new ShareError("duration", "启用时长应为 1–1440 分钟的整数");
        }
        $this->store->db->transaction(function () use ($minutes, $actor): void {
            $now = time();
            $this->store->db->run(
                "INSERT INTO guest_upload_window(id,token,opened_at,expires_at,closed_at,received_count,received_bytes) VALUES(1,?,?,?,NULL,0,0) ON CONFLICT(id) DO UPDATE SET token=excluded.token,opened_at=excluded.opened_at,expires_at=excluded.expires_at,closed_at=NULL,received_count=0,received_bytes=0",
                [bin2hex(random_bytes(32)), $now, $now + $minutes * 60],
            );
            $this->store->audit($actor, "guest_upload_open", null, ["minutes" => $minutes]);
        });
    }
    public function close(string $actor): void
    {
        $this->store->db->transaction(function () use ($actor): void {
            $this->store->db->run("UPDATE guest_upload_window SET closed_at=? WHERE id=1", [
                time(),
            ]);
            $this->store->audit($actor, "guest_upload_close", null);
        });
    }
    private function requireOpen(string $token): array
    {
        $window = $this->window();
        if (
            !preg_match('/^[a-f0-9]{64}$/D', $token) ||
            !$window["active"] ||
            !hash_equals((string) ($window["token"] ?? ""), $token)
        ) {
            throw new ShareError(
                "upload_closed",
                "此收件链接已关闭或过期，请联系文件接收方重新开启",
                410,
            );
        }
        return $window;
    }
    public function status(string $token): array
    {
        $window = $this->requireOpen($token);
        return [
            "active" => true,
            "expires_at" => (int) $window["expires_at"],
            "server_time" => time(),
            "max_file_bytes" => ShareStore::MAX_UPLOAD_BYTES,
        ];
    }
    private function reserveAttempt(string $token, string $ip): void
    {
        $this->store->db->transaction(function () use ($token, $ip): void {
            $this->requireOpen($token);
            $now = time();
            foreach (
                ["guest:ip:" . hash("sha256", $ip) => 10, "guest:global" => 30]
                as $key => $limit
            ) {
                $row = $this->store->db->one("SELECT * FROM rate_limits WHERE bucket=?", [$key]);
                $live = $row && (int) $row["until_at"] > $now;
                if ($live && (int) $row["attempts"] >= $limit) {
                    throw new ShareError(
                        "guest_rate_limit",
                        "上传请求过于频繁，请一分钟后重试",
                        429,
                    );
                }
                $this->store->db->run(
                    "INSERT INTO rate_limits(bucket,attempts,until_at) VALUES(?,?,?) ON CONFLICT(bucket) DO UPDATE SET attempts=excluded.attempts,until_at=excluded.until_at",
                    [
                        $key,
                        $live ? (int) $row["attempts"] + 1 : 1,
                        $live ? (int) $row["until_at"] : $now + 60,
                    ],
                );
            }
        });
    }
    public function upload(string $token, array $file, string $ip): array
    {
        $this->reserveAttempt($token, $ip);
        if (
            in_array($file["error"] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ||
            (int) ($file["size"] ?? 0) > ShareStore::MAX_UPLOAD_BYTES
        ) {
            throw new ShareError("file_too_large", "单个文件不能超过 45 MiB", 413);
        }
        $name = $this->store->upload($file, "guest", function (int $id, int $bytes) use (
            $token,
        ): void {
            // This runs inside the file publication transaction. Close, expiry, rotation,
            // quotas and SQL failures all reject/roll back the complete publication.
            $window = $this->requireOpen($token);
            if (
                (int) $window["received_count"] >= self::MAX_FILES ||
                (int) $window["received_bytes"] + $bytes > self::MAX_BYTES
            ) {
                throw new ShareError("upload_capacity", "本次收件窗口已满，请联系接收方", 409);
            }
            $free = disk_free_space($this->store->filesDir);
            if ($free === false || $free < 100 * 1024 * 1024) {
                throw new ShareError("storage_full", "接收方存储空间不足，请联系接收方", 503);
            }
            $this->store->db->run(
                "UPDATE guest_upload_window SET received_count=received_count+1,received_bytes=received_bytes+? WHERE id=1",
                [$bytes],
            );
        });
        $saved = $this->store->resolve($name);
        return [
            "ok" => true,
            "message" => "上传成功，接收方可在管理后台下载",
            "name" => $name,
            "bytes" => $saved["bytes"],
        ];
    }
}
