<?php
declare(strict_types=1);
// This fixture is intentionally synthetic and only writes to the fresh test directory.
require_once __DIR__ . "/../src/bootstrap.php";
$root = getenv("SHARE_STORAGE_DIR") ?: "";
if (!str_contains($root, "share-browser-") || is_file($root . "/manager.json")) {
    throw new RuntimeException("Fresh isolated browser fixture required");
}
$files = getenv("SHARE_FILES_DIR");
foreach (
    [
        "产品设计说明.txt" => "A design document fixture.",
        "品牌素材.zip" => "A synthetic archive fixture.",
        "项目数据.json" => '{"fixture":true}',
        "使用指南.pdf" => "A PDF-labeled fixture.",
        "preview-image.png" => "An image-labeled fixture.",
        "README.md" => "# Fixture",
        "很长的文件名称，用于检查窄屏与特殊字符 <安全> & 排版.txt" => "Long name fixture",
    ]
    as $name => $bytes
) {
    file_put_contents($files . "/" . $name, $bytes);
}
$store = share_store();
file_put_contents(
    $root . "/manager.json",
    json_encode([
        "username" => "browser-test",
        "password_hash" => password_hash(getenv("SHARE_FIXTURE_PASSWORD"), PASSWORD_DEFAULT),
    ]),
);
$items = $store->files();
for ($i = 0; $i < 35; $i++) {
    $f = $items[$i % count($items)];
    $ctx = [
        "ip" => "203.0.113." . (10 + ($i % 6)),
        "ip_source" => "peer",
        "region" => ["中国 / 上海", "中国 / 广东", "日本 / 东京", "未知"][$i % 4],
        "geo_version" => "synthetic-test-only",
        "referrer" => ["example.org/notes", "design.example/review", ""][$i % 3],
    ];
    $token = $store->createSession($f["id"], $ctx);
    $t = $store->beginTransfer($token, 200, "", $f["bytes"]);
    $store->finishTransfer($t["transfer_id"], $f["bytes"], true);
    $at = time() - ($i % 7) * 86400 - $i * 700;
    $store->db->run("UPDATE events SET started_at=?,last_request_at=? WHERE session_id=?", [
        $at,
        $at,
        $t["session_id"],
    ]);
}
$store->db->run(
    "UPDATE files SET last_public_at=(SELECT MAX(started_at) FROM events WHERE file_id=files.id)",
);
$f = $store->resolve("品牌素材.zip");
$store->policy(
    $f["id"],
    [
        "policy_version" => $f["policy_version"],
        "quota_mode" => "remaining",
        "quota_amount" => 3,
        "password_action" => "set",
        "password" => "archive-fixture-password",
    ],
    "fixture",
);
$f = $store->resolve("使用指南.pdf");
$store->policy(
    $f["id"],
    ["policy_version" => $f["policy_version"], "state" => "paused"],
    "fixture",
);
echo json_encode(["files" => $store->files()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
