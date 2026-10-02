<?php
declare(strict_types=1);

function manager_state(ShareStore $store): array
{
    $dir = $store->storageDir . "/sessions";
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create session storage");
    }
    ini_set("session.use_strict_mode", "1");
    ini_set("session.gc_maxlifetime", "43200");
    ini_set("session.use_only_cookies", "1");
    session_save_path($dir);
    session_name("share_manager");
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => str_starts_with(canonical_base(), "https://"),
        "httponly" => true,
        "samesite" => "Strict",
    ]);
    session_start();
    $_SESSION["csrf"] ??= bin2hex(random_bytes(32));
    $admin = isset($_SESSION["admin_until"]) && $_SESSION["admin_until"] > time();
    $state = [
        "admin" => $admin,
        "csrf" => $_SESSION["csrf"],
        "username" => $admin ? $_SESSION["username"] ?? "manager" : "",
        "binding" => $admin ? hash("sha256", session_id()) : null,
        "message" => $_SESSION["message"] ?? "",
        "error" => "",
    ];
    unset($_SESSION["message"]);
    session_write_close();
    return $state;
}
function check_csrf(array $manager): void
{
    $v = $_POST["csrf"] ?? "";
    if (!is_string($v) || !hash_equals($manager["csrf"], $v)) {
        throw new ShareError("csrf", "页面已过期或请求无效，请刷新后重试", 403);
    }
}
function manager_login(ShareStore $store, string $user, string $pass): void
{
    $ip = request_context()["ip"];
    $keys = ["admin:" . hash("sha256", $ip), "admin:global"];
    $path = $store->storageDir . "/manager.json";
    if (!is_readable($path)) {
        throw new ShareError("setup", "管理账号尚未配置，请先运行账号初始化命令", 503);
    }
    $c = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    $valid = $store->verifyCredentials(
        $keys,
        [8, 100],
        fn(): bool => password_verify($pass, (string) ($c["password_hash"] ?? "")) &&
            hash_equals((string) ($c["username"] ?? ""), $user),
    );
    if (!$valid) {
        throw new ShareError("login", "账号或密码不正确", 401);
    }
    session_start();
    session_regenerate_id(true);
    $_SESSION["admin_until"] = time() + 43200;
    $_SESSION["username"] = $user;
    $_SESSION["csrf"] = bin2hex(random_bytes(32));
    session_write_close();
    $store->audit($user, "login", null);
}
function manager_logout(ShareStore $store, array $manager): void
{
    $store->db->run('UPDATE sessions SET expires_at=? WHERE actor=\'admin\' AND admin_binding=?', [
        time(),
        $manager["binding"],
    ]);
    $store->audit($manager["username"], "logout", null);
    session_start();
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION["csrf"] = bin2hex(random_bytes(32));
    session_write_close();
}
function flash(string $message): void
{
    session_start();
    $_SESSION["message"] = $message;
    session_write_close();
}
