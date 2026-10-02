<?php
declare(strict_types=1);
require_once __DIR__ . "/ShareStore.php";
require_once __DIR__ . "/QueryService.php";
require_once __DIR__ . "/GuestUploads.php";

function share_store(): ShareStore
{
    static $store = null;
    return $store ??= new ShareStore(
        getenv("SHARE_FILES_DIR") ?: dirname(__DIR__) . "/files",
        (getenv("SHARE_STORAGE_DIR") ?: dirname(__DIR__) . "/storage") . "/stats.json",
    );
}
function canonical_base(): string
{
    $base = rtrim(getenv("SHARE_BASE_URL") ?: "https://share.playai.ren", "/");
    $p = parse_url($base);
    if (
        !$p ||
        !in_array($p["scheme"] ?? "", ["https", "http"], true) ||
        empty($p["host"]) ||
        isset($p["user"]) ||
        isset($p["query"]) ||
        isset($p["fragment"]) ||
        !empty($p["path"])
    ) {
        throw new RuntimeException("Invalid SHARE_BASE_URL");
    }
    if (
        $p["scheme"] === "http" &&
        !(
            getenv("SHARE_ALLOW_HTTP") === "1" &&
            in_array($p["host"], ["localhost", "127.0.0.1", "[::1]"], true)
        )
    ) {
        throw new RuntimeException("HTTPS is required outside explicit loopback development");
    }
    return $base;
}
function public_download_url(array $file): string
{
    return canonical_base() . "/d/" . rawurlencode((string) ($file["public_id"] ?? $file["name"]));
}
function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}
function format_bytes(int|float $bytes): string
{
    $s = (float) $bytes;
    foreach (["B", "KiB", "MiB", "GiB", "TiB"] as $u) {
        if ($s < 1024 || $u === "TiB") {
            return $u === "B" ? sprintf("%d %s", $s, $u) : sprintf("%.1f %s", $s, $u);
        }
        $s /= 1024;
    }
    return "0 B";
}
function format_time(int|string|null $value, ?string $tz = null): string
{
    if ($value === null || $value === "") {
        return "尚无记录";
    }
    try {
        $d = is_numeric($value)
            ? new DateTimeImmutable("@" . $value)
            : new DateTimeImmutable($value);
        return $d
            ->setTimezone(new DateTimeZone($tz ?? share_store()->settings()["timezone"]))
            ->format("Y年n月j日 H:i:s");
    } catch (Throwable) {
        return "时间不可用";
    }
}
function csrf_field(array|string $manager): string
{
    return '<input type="hidden" name="csrf" value="' .
        h(is_array($manager) ? $manager["csrf"] : $manager) .
        '">';
}
function geoip_path(): ?string
{
    $configured = getenv("SHARE_GEOIP_FILE");
    if ($configured) {
        return $configured;
    }
    $default =
        (getenv("SHARE_STORAGE_DIR") ?: dirname(__DIR__) . "/storage") .
        "/geoip/dbip-city-lite.mmdb";
    return is_readable($default) ? $default : null;
}
function request_context(): array
{
    return RequestContext::capture(
        $_SERVER,
        array_filter(array_map("trim", explode(",", getenv("SHARE_TRUSTED_PROXIES") ?: ""))),
        geoip_path(),
    );
}
function redirect(string $url, int $status = 303): never
{
    header("Location: " . $url, true, $status);
    exit();
}
function method_only(array $methods): void
{
    if (!in_array($_SERVER["REQUEST_METHOD"] ?? "GET", $methods, true)) {
        header("Allow: " . implode(", ", $methods));
        throw new ShareError("method", "请求方法不受支持", 405);
    }
}
