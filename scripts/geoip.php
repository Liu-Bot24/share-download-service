<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/bootstrap.php";
if (PHP_SAPI !== "cli") {
    exit(1);
}
$license = "https://creativecommons.org/licenses/by/4.0/";
$source = "https://db-ip.com/db/download/ip-to-city-lite";
try {
    $command = $argv[1] ?? "help";
    if ($command === "lookup") {
        $ip = $argv[2] ?? "";
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("Provide a valid test IP address.");
        }
        [$region, $version] = RequestContext::geo($ip, geoip_path());
        echo json_encode(
            [
                "ip" => $ip,
                "approximate_region" => $region,
                "database" => $version,
                "lookup" => "local-only",
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        exit();
    }
    if ($command !== "install" || !in_array("--accept-dbip-license", $argv, true)) {
        fwrite(
            STDERR,
            "DB-IP City Lite is monthly, approximate, free and requires CC BY 4.0 attribution.\nRead $source and $license first.\nAfter you accept: php scripts/geoip.php install --accept-dbip-license\nVerify locally: php scripts/geoip.php lookup 8.8.8.8\nNo visitor IP is submitted to the download service.\n",
        );
        exit(1);
    }
    $autoload = __DIR__ . "/../vendor/autoload.php";
    if (!is_file($autoload)) {
        throw new RuntimeException("Run composer install --no-dev --no-scripts first.");
    }
    require_once $autoload;
    $dir = (getenv("SHARE_STORAGE_DIR") ?: dirname(__DIR__) . "/storage") . "/geoip";
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create private GeoIP directory");
    }
    $lock = fopen($dir . "/update.lock", "c");
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException("A database update is already running.");
    }
    // These official destinations are fixed. No arbitrary URLs, redirects, accounts or tokens.
    $options = [
        "http" => [
            "timeout" => 60,
            "follow_location" => 0,
            "user_agent" => "ShareFiles-GeoIP-Installer/1.0",
        ],
        "ssl" => ["verify_peer" => true, "verify_peer_name" => true],
    ];
    $context = stream_context_create($options);
    $page = file_get_contents($source, false, $context, 0, 2000000);
    if ($page === false) {
        throw new RuntimeException(
            "Cannot read official DB-IP download page. Existing database retained.",
        );
    }
    if (!preg_match("#https?://creativecommons\.org/licenses/by/4\.0/?#", $page)) {
        throw new RuntimeException(
            "The official page no longer advertises the expected CC BY 4.0 license. Review updated terms before continuing.",
        );
    }
    if (
        !preg_match(
            "#https://download\.db-ip\.com/free/dbip-city-lite-([0-9]{4}-[0-9]{2})\.mmdb\.gz#",
            $page,
            $match,
        )
    ) {
        throw new RuntimeException(
            "Official MMDB link not found. Review source page; do not guess a mirror.",
        );
    }
    $release = $match[1];
    $url = $match[0];
    $gz = $dir . "/.download-" . bin2hex(random_bytes(8)) . ".gz";
    $staged = $dir . "/.database-" . bin2hex(random_bytes(8)) . ".mmdb";
    try {
        echo "Downloading DB-IP City Lite $release from its official source...\n";
        $input = fopen($url, "rb", false, $context);
        if (!$input) {
            throw new RuntimeException("Download failed. Existing database retained.");
        }
        $output = fopen($gz, "xb");
        if (!$output) {
            throw new RuntimeException("Cannot stage archive");
        }
        try {
            $bytes = stream_copy_to_stream($input, $output, 268435457);
            if ($bytes === false || $bytes > 268435456 || !feof($input)) {
                throw new RuntimeException("Archive exceeded 256 MiB limit");
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        $input = gzopen($gz, "rb");
        $output = fopen($staged, "xb");
        if (!$input || !$output) {
            throw new RuntimeException("Cannot extract database");
        }
        $total = 0;
        try {
            while (!gzeof($input)) {
                $chunk = gzread($input, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException("Corrupt compressed database");
                }
                $total += strlen($chunk);
                if ($total > 1073741824) {
                    throw new RuntimeException("Expanded database exceeds 1 GiB");
                }
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException("Database write failed");
                }
            }
        } finally {
            gzclose($input);
            fclose($output);
        }
        $reader = new MaxMind\Db\Reader($staged);
        try {
            $metadata = $reader->metadata();
            $sample = $reader->get("8.8.8.8");
            if (!is_array($sample) || empty($sample["country"])) {
                throw new RuntimeException("Database validation failed");
            }
        } finally {
            $reader->close();
        }
        chmod($staged, 0600);
        $target = $dir . "/dbip-city-lite.mmdb";
        if (!rename($staged, $target)) {
            throw new RuntimeException("Cannot publish database");
        }
        file_put_contents(
            $dir . "/source.json",
            json_encode(
                [
                    "provider" => "DB-IP Lite",
                    "release" => $release,
                    "source" => $url,
                    "license" => $license,
                    "sha256" => hash_file("sha256", $target),
                    "installed_at" => gmdate("c"),
                    "database_type" => $metadata->databaseType,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
        echo "Installed and validated locally. CC BY 4.0 attribution is included on the admin pages.\nRun this same command monthly to update. No scheduled task was installed.\n";
    } finally {
        if (is_file($gz)) {
            unlink($gz);
        }
        if (is_file($staged)) {
            unlink($staged);
        }
        fclose($lock);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
