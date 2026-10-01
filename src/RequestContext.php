<?php
declare(strict_types=1);

final class RequestContext
{
    public static function capture(
        array $server,
        array $trustedProxies = [],
        ?string $geoFile = null,
    ): array {
        $peer = self::normalIp((string) ($server["REMOTE_ADDR"] ?? ""));
        $ip = $peer;
        $source = "peer";
        if (self::trusted($peer, $trustedProxies)) {
            $chain = explode(
                ",",
                substr((string) ($server["HTTP_X_FORWARDED_FOR"] ?? ""), 0, 2048),
            );
            $chain[] = $peer;
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $candidate = self::normalIp(trim($chain[$i]));
                if ($candidate === "unknown") {
                    break;
                }
                $ip = $candidate;
                $source = "trusted-proxy";
                if (!self::trusted($candidate, $trustedProxies)) {
                    break;
                }
            }
        }
        [$region, $version] = self::geo($ip, $geoFile);
        return [
            "ip" => $ip,
            "ip_source" => $source,
            "region" => $region,
            "geo_version" => $version,
            "referrer" => self::referrer((string) ($server["HTTP_REFERER"] ?? "")),
        ];
    }
    public static function normalIp(string $ip): string
    {
        $packed = @inet_pton($ip);
        return $packed === false ? "unknown" : (string) inet_ntop($packed);
    }
    public static function trusted(string $ip, array $cidrs): bool
    {
        $a = @inet_pton($ip);
        if ($a === false) {
            return false;
        }
        foreach ($cidrs as $cidr) {
            $parts = explode("/", trim((string) $cidr), 2);
            $b = @inet_pton($parts[0]);
            if ($b === false || strlen($a) !== strlen($b)) {
                continue;
            }
            $bits =
                isset($parts[1]) && preg_match('/^[0-9]+$/D', $parts[1])
                    ? (int) $parts[1]
                    : strlen($a) * 8;
            if ($bits < 0 || $bits > strlen($a) * 8) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (
                substr($a, 0, $bytes) === substr($b, 0, $bytes) &&
                (!$rest || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (255 << 8 - $rest)) === 0)
            ) {
                return true;
            }
        }
        return false;
    }
    public static function referrer(string $value): string
    {
        if (strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            return "";
        }
        $p = parse_url($value);
        if (
            !is_array($p) ||
            !in_array(strtolower($p["scheme"] ?? ""), ["http", "https"], true) ||
            empty($p["host"])
        ) {
            return "";
        }
        // Query, fragment, userinfo and any transfer token are never retained.
        $path = $p["path"] ?? "/";
        if (preg_match("#^/(transfer|d)/#", $path)) {
            $path = "/[download]";
        }
        return substr(strtolower($p["host"]) . $path, 0, 512);
    }
    public static function geo(string $ip, ?string $file): array
    {
        if ($ip === "unknown") {
            return ["未知", "unavailable"];
        }
        if (
            !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )
        ) {
            return ["内网 / 保留地址", "local"];
        }
        // Standard local MMDB or a small custom CIDR JSON dataset. No visitor IP leaves this host.
        if ($file && str_ends_with(strtolower($file), ".mmdb") && is_readable($file)) {
            try {
                $autoload = __DIR__ . "/../vendor/autoload.php";
                if (is_file($autoload)) {
                    require_once $autoload;
                }
                if (!class_exists("MaxMind\\Db\\Reader")) {
                    return ["未知", "reader-unavailable"];
                }
                $reader = new \MaxMind\Db\Reader($file);
                try {
                    $record = $reader->get($ip);
                    $meta = $reader->metadata();
                    $version = substr(
                        $meta->databaseType . " " . gmdate("Y-m-d", $meta->buildEpoch),
                        0,
                        80,
                    );
                    if (!is_array($record)) {
                        return ["未知", $version];
                    }
                    $name = static fn(array $part): string => (string) ($part["names"]["zh-CN"] ??
                        ($part["names"]["en"] ?? ($part["iso_code"] ?? "")));
                    $parts = array_filter([
                        $name($record["country"] ?? []),
                        $name($record["subdivisions"][0] ?? []),
                        $name($record["city"] ?? []),
                    ]);
                    return [substr(implode(" / ", $parts), 0, 160) ?: "未知", $version];
                } finally {
                    $reader->close();
                }
            } catch (Throwable) {
                error_log("Share local MMDB database could not be read");
                return ["未知", "unavailable"];
            }
        }
        if (!$file || !is_readable($file)) {
            return ["未知", "unavailable"];
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            foreach ($data["networks"] ?? [] as $entry) {
                if (self::trusted($ip, [(string) ($entry["cidr"] ?? "")])) {
                    $parts = array_filter([
                        $entry["country"] ?? "",
                        $entry["region"] ?? "",
                        $entry["city"] ?? "",
                    ]);
                    return [
                        substr(implode(" / ", $parts), 0, 160) ?: "未知",
                        substr((string) ($data["version"] ?? "local"), 0, 80),
                    ];
                }
            }
        } catch (Throwable) {
            error_log("Share local GeoIP database could not be read");
        }
        return ["未知", "unavailable"];
    }
    public static function csv(string $value): string
    {
        return preg_match('/^[\s]*[=+@\-\t\r]/u', $value) ? "'" . $value : $value;
    }
}
