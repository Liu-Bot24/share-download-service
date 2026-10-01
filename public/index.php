<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/bootstrap.php";
require_once __DIR__ . "/../src/manager.php";
require_once __DIR__ . "/../src/HttpDownload.php";
require_once __DIR__ . "/../src/View.php";

header("Cache-Control: no-store, private");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer");
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
);
$manager = null;
$store = null;
try {
    ini_set("display_errors", "0");
    ini_set("log_errors", "1");
    foreach ([$_GET, $_POST] as $input) {
        foreach ($input as $value) {
            if (!is_string($value)) {
                throw new ShareError("invalid_input", "请求字段格式无效", 400);
            }
        }
    }
    $expected =
        parse_url(canonical_base(), PHP_URL_HOST) .
        (parse_url(canonical_base(), PHP_URL_PORT)
            ? ":" . parse_url(canonical_base(), PHP_URL_PORT)
            : "");
    if (strtolower((string) ($_SERVER["HTTP_HOST"] ?? "")) !== strtolower($expected)) {
        throw new ShareError("host", "请求域名无效", 400);
    }
    $store = share_store();
    $path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
    if (preg_match('#^/d/([^/]+)(/unlock)?$#D', $path, $m)) {
        $unlock = isset($m[2]);
        method_only($unlock ? ["POST"] : ["GET", "HEAD"]);
        $f = $store->resolve(rawurldecode($m[1]));
        $store->allowNew($f);
        if (!$store->readable($f)) {
            throw new ShareError("missing", "文件暂时缺失或内容已变更，请联系分享者", 410);
        }
        if ($method === "HEAD") {
            if ($f["has_password"]) {
                throw new ShareError("password_required", "需要文件密码", 401);
            }
            HttpDownload::headers(HttpDownload::plan($f, $_SERVER));
            exit();
        }
        $ctx = request_context();
        if ($f["has_password"]) {
            $manager = manager_state($store);
            if ($unlock) {
                check_csrf($manager);
                $flow = (string) ($_POST["flow"] ?? "");
                session_start();
                $saved = $_SESSION["flows"][$flow] ?? null;
                unset($_SESSION["flows"][$flow]);
                session_write_close();
                if (!$saved || $saved["file_id"] !== $f["id"] || $saved["expires"] < time()) {
                    throw new ShareError("flow", "验证页面已过期，请重新打开分享链接", 403);
                }
                $ctx["referrer"] = $saved["referrer"];
                try {
                    $token = $store->createSession(
                        $f["id"],
                        $ctx,
                        "public",
                        (string) ($_POST["password"] ?? ""),
                    );
                    redirect("/transfer/" . $token);
                } catch (ShareError $e) {
                    if (!in_array($e->reason, ["password", "rate_limit"], true)) {
                        throw $e;
                    }
                    $error = $e->getMessage();
                    http_response_code($e->httpStatus);
                }
            }
            $flow = bin2hex(random_bytes(16));
            session_start();
            $_SESSION["flows"] ??= [];
            foreach ($_SESSION["flows"] as $k => $v) {
                if ($v["expires"] < time()) {
                    unset($_SESSION["flows"][$k]);
                }
            }
            if (count($_SESSION["flows"]) > 20) {
                array_shift($_SESSION["flows"]);
            }
            $_SESSION["flows"][$flow] = [
                "file_id" => $f["id"],
                "referrer" => $ctx["referrer"],
                "expires" => time() + 600,
            ];
            session_write_close();
            render_public("password", [
                "file" => $f,
                "csrf" => $manager["csrf"],
                "manager" => $manager,
                "flow" => $flow,
                "unlock_url" => "/d/" . $f["public_id"] . "/unlock",
                "error" => $error ?? "",
                "settings" => $store->settings(),
            ]);
            exit();
        }
        if ($unlock) {
            throw new ShareError("method", "此文件不需要密码验证", 405);
        }
        $token = $store->createSession($f["id"], $ctx);
        redirect("/transfer/" . $token, 302);
    }
    if (preg_match('#^/transfer/([a-f0-9]{64})$#D', $path, $m)) {
        $manager = manager_state($store);
        HttpDownload::serve($store, $m[1], $manager["binding"]);
        exit();
    }
    if ($path === "/") {
        redirect("/admin", 302);
    }
    if (!str_starts_with($path, "/admin") && !str_starts_with($path, "/manage/")) {
        throw new ShareError("not_found", "页面不存在", 404);
    }
    $path = match ($path) {
        "/manage/login" => "/admin/login",
        "/manage/logout" => "/admin/logout",
        "/manage/upload" => "/admin/upload",
        default => $path,
    };
    $manager = manager_state($store);
    $settings = $store->settings();
    $data = [
        "store" => $store,
        "manager" => $manager,
        "settings" => $settings,
        "filters" => $_GET,
    ];
    if ($path === "/admin/login") {
        method_only(["GET", "POST"]);
        if ($method === "POST") {
            check_csrf($manager);
            try {
                manager_login(
                    $store,
                    (string) ($_POST["username"] ?? ""),
                    (string) ($_POST["password"] ?? ""),
                );
                redirect("/admin");
            } catch (ShareError $e) {
                http_response_code($e->httpStatus);
                $data["error"] = $e->getMessage();
            }
        } elseif ($manager["admin"]) {
            redirect("/admin");
        }
        render_public("login", $data);
        exit();
    }
    if (!$manager["admin"]) {
        if ($method === "GET" || $method === "HEAD") {
            redirect("/admin/login", 302);
        }
        throw new ShareError("authentication", "请先登录管理账号", 403);
    }
    if ($method === "POST") {
        check_csrf($manager);
        if ($path === "/admin/logout") {
            manager_logout($store, $manager);
            redirect("/admin/login");
        }
        if ($path === "/admin/scan") {
            $r = $store->scan($manager["username"]);
            flash(
                "目录已更新：新增 " .
                    $r["found"] .
                    "，变更 " .
                    $r["changed"] .
                    "，缺失 " .
                    $r["missing"],
            );
            redirect("/admin/files");
        }
        if ($path === "/admin/upload") {
            $name = $store->upload($_FILES["file"] ?? [], $manager["username"]);
            flash("上传成功：" . $name);
            if (str_contains($_SERVER["HTTP_ACCEPT"] ?? "", "application/json")) {
                header("Content-Type: application/json");
                echo json_encode([
                    "ok" => true,
                    "message" => "上传成功",
                    "redirect" => "/admin/files",
                ]);
                exit();
            }
            redirect("/admin/files");
        }
        if ($path === "/admin/settings") {
            $store->saveSettings($_POST, $manager["username"]);
            flash("设置已保存，历史时间未被改写");
            redirect("/admin/settings");
        }
        if (
            preg_match('#^/admin/files/(\d+)/(policy|download-ticket|trash|restore)$#D', $path, $m)
        ) {
            $id = (int) $m[1];
            $action = $m[2];
            if ($action === "download-ticket") {
                $token = $store->createSession(
                    $id,
                    request_context(),
                    "admin",
                    null,
                    $manager["binding"],
                    $manager["username"],
                );
                redirect("/transfer/" . $token);
            }
            if ($action === "policy") {
                $store->policy($id, $_POST, $manager["username"]);
                flash("分享设置已保存");
            }
            if ($action === "trash") {
                $f = $store->file($id);
                if ((string) ($_POST["confirmation"] ?? "") !== $f["name"]) {
                    throw new ShareError("confirmation", "请确认要移入回收目录的文件名");
                }
                $store->trash($id, $manager["username"]);
                flash("文件已移入回收目录，历史记录已保留");
            }
            if ($action === "restore") {
                $store->restore($id, $manager["username"]);
                flash("文件已恢复为暂停状态，请检查分享设置后开放");
            }
            redirect("/admin/files/" . $id . ($action === "policy" ? "?tab=sharing" : ""));
        }
        throw new ShareError("not_found", "操作不存在", 404);
    }
    method_only(["GET", "HEAD"]);
    $query = new QueryService($store);
    if ($path === "/admin/downloads/export") {
        header("Content-Type: text/csv; charset=utf-8");
        header('Content-Disposition: attachment; filename="share-downloads.csv"');
        $store->audit($manager["username"], "export", null, [
            "filters" => array_intersect_key(
                $_GET,
                array_flip(["start", "end", "days", "file_id", "region", "status"]),
            ),
        ]);
        if ($method === "HEAD") {
            exit();
        }
        $out = fopen("php://output", "w");
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv(
            $out,
            [
                "ID",
                "File",
                "Started (" . $settings["timezone"] . ")",
                "UTC",
                "IP",
                "Approximate region",
                "Referrer (sanitized)",
                "Status",
                "Requests",
                "Observed bytes",
            ],
            ",",
            '"',
            "",
        );
        foreach ($query->export($_GET) as $e) {
            fputcsv(
                $out,
                array_map(fn($v) => RequestContext::csv((string) $v), [
                    $e["id"],
                    $e["filename"],
                    format_time($e["started_at"], $settings["timezone"]),
                    gmdate("c", (int) $e["started_at"]),
                    $e["ip"],
                    $e["region"],
                    $e["referrer"],
                    $e["status"],
                    $e["request_count"],
                    $e["observed_bytes"],
                ]),
                ",",
                '"',
                "",
            );
        }
        fclose($out);
        exit();
    }
    $page = match ($path) {
        "/admin", "/admin/" => "overview",
        "/admin/files" => "files",
        "/admin/downloads" => "downloads",
        "/admin/analytics" => "analytics",
        "/admin/settings" => "settings",
        default => "",
    };
    if (preg_match('#^/admin/files/(\d+)$#D', $path, $m)) {
        $page = "detail";
        $data["file"] = $store->file((int) $m[1]);
        $data = array_merge($data, $query->events(array_merge($_GET, ["file_id" => (int) $m[1]])));
    }
    if ($page === "") {
        throw new ShareError("not_found", "页面不存在", 404);
    }
    if ($method === "HEAD") {
        exit();
    }
    if (in_array($page, ["overview", "analytics"], true)) {
        $data["analytics"] = $query->analytics(
            $page === "overview" ? array_merge($_GET, ["days" => 7]) : $_GET,
        );
        $data["events"] = $query->events(["days" => 30], 1, 6)["events"];
        $data["files"] = $store->files();
    }
    if ($page === "files") {
        $all = $store->files($_GET);
        $total = count($all);
        $pages = max(1, (int) ceil($total / 25));
        $p = max(1, min($pages, (int) ($_GET["page"] ?? 1)));
        $data["files"] = array_slice($all, ($p - 1) * 25, 25);
        $data["pagination"] = [
            "page" => $p,
            "pages" => $pages,
            "total" => $total,
            "per_page" => 25,
        ];
    }
    if ($page === "downloads") {
        $data = array_merge($data, $query->events($_GET, (int) ($_GET["page"] ?? 1)));
        $data["files"] = $store->files();
        if (!empty($_GET["id"])) {
            $data = array_merge($data, $query->event((int) $_GET["id"]));
        }
        if (($_GET["tab"] ?? "") === "attempts") {
            $data["log_tab"] = "attempts";
            $data["attempts"] = $store->db->all(
                "SELECT a.*,f.name filename FROM attempts a LEFT JOIN files f ON f.id=a.file_id ORDER BY occurred_at DESC LIMIT 100",
            );
        }
    }
    if ($page === "settings") {
        $totals = $store->db->one(
            "SELECT COUNT(*) files,COALESCE(SUM(bytes),0) bytes FROM files WHERE state NOT IN ('trashed','destroyed','missing')",
        );
        $geo = geoip_path() ?: "";
        $data["storage"] = array_merge($totals, [
            "database_bytes" => filesize($store->storageDir . "/share.sqlite"),
            "free_bytes" => disk_free_space($store->storageDir),
            "geo_status" =>
                $geo && is_readable($geo) ? "已配置本机地区库" : "未配置，公网地区显示未知",
            "geo_version" => $geo
                ? (str_ends_with(strtolower($geo), ".mmdb")
                    ? "本机 MMDB（DB-IP / MaxMind 兼容）"
                    : "本机 CIDR 数据集")
                : "尚未安装",
            "detail_started_at" => $settings["detail_started_at"],
        ]);
        $data["audit"] = $store->db->all("SELECT * FROM audit ORDER BY id DESC LIMIT 12");
        $data["jobs"] = $store->db->all(
            "SELECT j.*,f.name FROM jobs j JOIN files f ON f.id=j.file_id WHERE j.status IN ('pending','running') ORDER BY j.id DESC",
        );
    }
    render_admin($page, $data);
} catch (ShareError | InvalidArgumentException $e) {
    $status = $e instanceof ShareError ? $e->httpStatus : 400;
    $reason = $e instanceof ShareError ? $e->reason : "invalid";
    http_response_code($status);
    if ($store && str_starts_with($_SERVER["REQUEST_URI"] ?? "", "/d/") && $reason !== "password") {
        try {
            $store->attempt($f["id"] ?? null, request_context()["ip"], $reason, $status);
        } catch (Throwable) {
        }
    }
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "HEAD") {
        exit();
    }
    if (str_contains($_SERVER["HTTP_ACCEPT"] ?? "", "application/json")) {
        header("Content-Type: application/json");
        echo json_encode(
            ["ok" => false, "message" => $e->getMessage(), "code" => $reason],
            JSON_UNESCAPED_UNICODE,
        );
    } else {
        render_public("error", [
            "error" => $e->getMessage(),
            "error_code" => $reason,
            "http_status" => $status,
            "manager" => $manager ?? [],
        ]);
    }
} catch (Throwable $e) {
    $requestId = bin2hex(random_bytes(6));
    error_log("Share request " . $requestId . " failed: " . get_class($e));
    http_response_code(503);
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "HEAD") {
        render_public("error", [
            "error" => "服务暂时不可用，请稍后重试。参考编号：" . $requestId,
            "error_code" => "unavailable",
            "http_status" => 503,
            "manager" => $manager ?? [],
        ]);
    }
}
