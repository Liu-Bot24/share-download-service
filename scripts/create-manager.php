<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/bootstrap.php";
if (PHP_SAPI !== "cli") {
    exit(1);
}
$store = share_store();
$path = $store->storageDir . "/manager.json";
if (file_exists($path)) {
    fwrite(
        STDERR,
        "Manager already configured. Existing credentials are never overwritten by this command.\n",
    );
    exit(1);
}
$user = $argv[1] ?? "";
if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/D', $user)) {
    fwrite(STDERR, "Usage: php scripts/create-manager.php <username> < password-from-stdin\n");
    exit(1);
}
$password = rtrim((string) stream_get_contents(STDIN), "\r\n");
if (strlen($password) < 12 || strlen($password) > 512) {
    fwrite(STDERR, "Use a 12–512 byte password via stdin, never a command-line argument.\n");
    exit(1);
}
$handle = fopen($path, "x");
if (!$handle) {
    exit(1);
}
chmod($path, 0600);
fwrite(
    $handle,
    json_encode(
        [
            "username" => $user,
            "password_hash" => password_hash(
                $password,
                defined("PASSWORD_ARGON2ID") ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT,
            ),
        ],
        JSON_THROW_ON_ERROR,
    ),
);
fclose($handle);
echo "Manager configured.\n";
