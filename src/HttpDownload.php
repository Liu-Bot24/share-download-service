<?php
declare(strict_types=1);

final class HttpDownload
{
    public static function plan(array $file, array $server): array
    {
        $size = (int) $file["bytes"];
        $etag = '"' . $file["sha256"] . '"';
        $modified = (int) $file["mtime"];
        $method = $server["REQUEST_METHOD"] ?? "GET";
        $headers = [
            "Content-Type" => $file["mime_type"] ?: "application/octet-stream",
            "ETag" => $etag,
            "Last-Modified" => gmdate("D, d M Y H:i:s", $modified) . " GMT",
            "Accept-Ranges" => "bytes",
        ];
        $name = (string) $file["name"];
        $fallback = preg_replace('/[^\x20-\x7e]/', "_", $name) ?: "download";
        $headers["Content-Disposition"] =
            'attachment; filename="' .
            addcslashes($fallback, "\\\"") .
            '"; filename*=UTF-8\'\'' .
            rawurlencode($name);
        $none = trim((string) ($server["HTTP_IF_NONE_MATCH"] ?? ""));
        if (
            $none !== "" &&
            ($none === "*" ||
                in_array(
                    $etag,
                    array_map(
                        static fn($v) => preg_replace("/^W\//", "", trim($v)),
                        explode(",", $none),
                    ),
                    true,
                ))
        ) {
            return [
                "status" => 304,
                "offset" => 0,
                "length" => 0,
                "headers" => $headers,
            ];
        }
        if ($none === "" && !empty($server["HTTP_IF_MODIFIED_SINCE"])) {
            $since = strtotime($server["HTTP_IF_MODIFIED_SINCE"]);
            if ($since !== false && $modified <= $since) {
                return [
                    "status" => 304,
                    "offset" => 0,
                    "length" => 0,
                    "headers" => $headers,
                ];
            }
        }
        $range = (string) ($server["HTTP_RANGE"] ?? "");
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;
        // RFC 9110: Range is defined for GET; HEAD describes the complete representation.
        if ($method === "GET" && $range !== "") {
            $ifRange = (string) ($server["HTTP_IF_RANGE"] ?? "");
            $use = true;
            if ($ifRange !== "") {
                $date = strtotime($ifRange);
                $use = $ifRange === $etag || ($date !== false && $modified <= $date);
            }
            if ($use) {
                if (
                    $size === 0 ||
                    !preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $m) ||
                    ($m[1] === "" && $m[2] === "")
                ) {
                    throw new ShareError("range", "请求的下载范围无效", 416);
                }
                if (strlen($m[1]) > 18 || strlen($m[2]) > 18) {
                    throw new ShareError("range", "请求的下载范围无效", 416);
                }
                if ($m[1] === "") {
                    $suffix = (int) $m[2];
                    if ($suffix < 1) {
                        throw new ShareError("range", "请求的下载范围无效", 416);
                    }
                    $start = max(0, $size - $suffix);
                } else {
                    $start = (int) $m[1];
                    $end = $m[2] === "" ? $size - 1 : min($size - 1, (int) $m[2]);
                }
                if ($start >= $size || $end < $start) {
                    throw new ShareError("range", "请求的下载范围无效", 416);
                }
                $status = 206;
                $headers["Content-Range"] = "bytes $start-$end/$size";
            }
        }
        $length = $size === 0 ? 0 : $end - $start + 1;
        $headers["Content-Length"] = (string) $length;
        return [
            "status" => $status,
            "offset" => $start,
            "length" => $length,
            "headers" => $headers,
        ];
    }
    public static function headers(array $plan): void
    {
        http_response_code($plan["status"]);
        foreach ($plan["headers"] as $key => $value) {
            header($key . ": " . $value);
        }
    }
    public static function serve(ShareStore $store, string $token, ?string $binding): void
    {
        method_only(["GET", "HEAD"]);
        ["file" => $f] = $store->session($token, $binding);
        $lock = $store->fileLock($f["id"], LOCK_SH | LOCK_NB);
        if (!$lock) {
            throw new ShareError("busy", "文件正由管理员处理，请稍后重试", 409);
        }
        $handle = null;
        $transfer = null;
        $sent = 0;
        $finished = false;
        try {
            if (!$store->readable($f)) {
                throw new ShareError("changed", "文件已改变或暂时不可读取，请联系分享者", 409);
            }
            $handle = fopen($f["path"], "rb");
            if (!$handle) {
                throw new ShareError("missing", "文件无法读取", 410);
            }
            $s = fstat($handle);
            if (!$s || $s["ino"] !== $f["inode"] || $s["size"] !== $f["bytes"]) {
                throw new ShareError("changed", "文件刚刚发生变更，请重试", 409);
            }
            try {
                $plan = self::plan($f, $_SERVER);
            } catch (ShareError $e) {
                if ($e->httpStatus === 416) {
                    header("Content-Range: bytes */" . $f["bytes"]);
                }
                throw $e;
            }
            if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "HEAD" || $plan["status"] === 304) {
                self::headers($plan);
                return;
            }
            if ($plan["offset"] > 0 && fseek($handle, $plan["offset"]) !== 0) {
                throw new RuntimeException("Cannot seek entity");
            }
            $transfer = $store->beginTransfer(
                $token,
                $plan["status"],
                (string) ($_SERVER["HTTP_RANGE"] ?? ""),
                $plan["length"],
                $binding,
                request_context(),
            );
            self::headers($plan);
            ignore_user_abort(true);
            set_time_limit(0);
            while ($sent < $plan["length"] && !feof($handle)) {
                $chunk = fread($handle, min(65536, $plan["length"] - $sent));
                if ($chunk === false || $chunk === "") {
                    break;
                }
                echo $chunk;
                $sent += strlen($chunk);
                if (function_exists("fastcgi_finish_request")) {
                    flush();
                } else {
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
                if (connection_aborted()) {
                    break;
                }
            }
            $finished = $sent === $plan["length"] && !connection_aborted();
        } finally {
            if ($transfer) {
                try {
                    $store->finishTransfer($transfer["transfer_id"], $sent, $finished);
                } catch (Throwable) {
                    error_log("Share transfer outcome could not be recorded");
                }
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
            fclose($lock);
        }
    }
}
