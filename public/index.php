<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$store = share_store();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#^/d/(.+)$#', $path, $matches)) {
    serve_download($store, rawurldecode($matches[1]));
    exit;
}

if ($path !== '/' && !in_array($path, ['/manage/login', '/manage/logout', '/manage/upload', '/manage/delete'], true)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

require_once __DIR__ . '/../src/manager.php';
$manager = manager_state($store, $path);
$files = $store->listFiles();
$totalDownloads = array_sum(array_map(static fn (array $file): int => (int) $file['downloads'], $files));
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Share Files</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7f9;
            --panel: #ffffff;
            --text: #18202b;
            --muted: #687386;
            --line: #dde3eb;
            --accent: #1f7a5a;
            --accent-dark: #155d45;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: 0;
        }
        .shell { max-width: 1080px; margin: 0 auto; padding: 32px 18px 48px; }
        header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }
        h1 { margin: 0; font-size: 28px; line-height: 1.2; font-weight: 720; }
        .summary { color: var(--muted); margin-top: 7px; }
        .metric {
            min-width: 150px;
            padding: 14px 16px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            text-align: right;
        }
        .metric strong { display: block; font-size: 26px; line-height: 1; }
        .metric span { color: var(--muted); font-size: 13px; }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--line); vertical-align: middle; }
        th { color: var(--muted); font-size: 12px; font-weight: 650; background: #fbfcfd; }
        tr:last-child td { border-bottom: 0; }
        .file-name { font-weight: 650; word-break: break-word; }
        .meta { color: var(--muted); font-size: 12px; margin-top: 3px; word-break: break-all; }
        .count { font-size: 20px; font-weight: 720; }
        .actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
        .button {
            appearance: none;
            border: 1px solid var(--line);
            border-radius: 7px;
            background: #fff;
            color: var(--text);
            padding: 8px 11px;
            font: inherit;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }
        .button.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
        .button.primary:hover { background: var(--accent-dark); }
        .empty { padding: 42px 18px; text-align: center; color: var(--muted); }
        .copied { color: var(--accent); font-size: 12px; min-width: 44px; }
        .management { padding: 20px; margin-bottom: 18px; }
        .management h2 { font-size: 18px; margin: 0 0 8px; }
        .management p { color: var(--muted); margin: 0 0 14px; }
        .form-row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        label { display: grid; gap: 5px; }
        input { font: inherit; max-width: 100%; padding: 9px; border: 1px solid var(--line); border-radius: 6px; }
        input[type=file] { flex: 1; min-width: 0; background: #fbfcfd; }
        summary { cursor: pointer; font-weight: 650; }
        details[open] summary { margin-bottom: 16px; }
        .notice { padding: 12px 16px; background: #e9f6ef; border-radius: 8px; margin-bottom: 16px; overflow-wrap: anywhere; }
        .notice.error { background: #fff0ef; color: #a62b25; }
        .button.danger { color: #b1302b; border-color: #ecc9c7; }
        .button:disabled { opacity: .6; cursor: wait; }
        .management-top { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
        progress { width: 100%; height: 10px; margin-top: 15px; accent-color: var(--accent); }
        #upload-status { margin-top: 8px; overflow-wrap: anywhere; }
        @media (max-width: 760px) {
            header { align-items: stretch; flex-direction: column; }
            .metric { text-align: left; }
            table, tbody, tr, td { display: block; width: 100%; }
            thead { display: none; }
            tr { border-bottom: 1px solid var(--line); padding: 12px 0; }
            tr:last-child { border-bottom: 0; }
            td { border: 0; padding: 6px 14px; }
            .actions { justify-content: flex-start; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header>
            <div>
                <h1>Share Files</h1>
                <div class="summary"><?= count($files) ?> 个分享文件 · 下载时自动计数</div>
            </div>
            <div class="metric">
                <strong><?= h((string) $totalDownloads) ?></strong>
                <span>总下载次数</span>
            </div>
        </header>

        <?php if ($manager['message']): ?><div class="notice" role="status"><?= h($manager['message']) ?></div><?php endif; ?>
        <?php if ($manager['error']): ?><div class="notice error" role="alert"><?= h($manager['error']) ?></div><?php endif; ?>
        <section class="panel management" aria-label="文件管理">
            <?php if ($manager['admin']): ?>
                <div class="management-top">
                    <div><h2>上传文件</h2><p>选择本地文件即可分享。单个文件最多 45 MB，同名文件不会被覆盖。</p></div>
                    <form action="/manage/logout" method="post"><input type="hidden" name="csrf" value="<?= h($manager['csrf']) ?>"><button class="button">退出管理</button></form>
                </div>
                <form id="upload-form" action="/manage/upload" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= h($manager['csrf']) ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= ShareStore::MAX_UPLOAD_BYTES ?>">
                    <div class="form-row"><input type="file" name="file" aria-label="选择要上传的文件" required><button class="button primary" type="submit">上传文件</button></div>
                    <progress id="upload-progress" max="100" value="0" hidden aria-label="上传进度"></progress>
                    <div id="upload-status" role="status" aria-live="polite"></div>
                </form>
            <?php else: ?>
                <details <?= $manager['error'] ? 'open' : '' ?>>
                    <summary>管理登录</summary>
                    <p>登录后可以上传和删除文件。</p>
                    <form action="/manage/login" method="post" class="form-row">
                        <input type="hidden" name="csrf" value="<?= h($manager['csrf']) ?>">
                        <label>账号<input name="username" autocomplete="username" required></label>
                        <label>密码<input type="password" name="password" autocomplete="current-password" required></label>
                        <button class="button primary" type="submit">登录</button>
                    </form>
                </details>
            <?php endif; ?>
        </section>

        <section class="panel">
            <?php if (!$files): ?>
                <div class="empty">当前没有已分享文件。</div>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>文件</th>
                        <th>下载次数</th>
                        <th>最近下载</th>
                        <th style="text-align: right;">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($files as $file): ?>
                        <?php $url = public_download_url($file); ?>
                        <tr>
                            <td>
                                <div class="file-name"><?= h($file['name']) ?></div>
                                <div class="meta"><?= h(format_bytes((int) $file['bytes'])) ?> · SHA256 <?= h($file['sha256']) ?></div>
                            </td>
                            <td><span class="count"><?= h((string) $file['downloads']) ?></span></td>
                            <td><?= $file['last_downloaded_at'] ? h($file['last_downloaded_at']) : '尚未下载' ?></td>
                            <td>
                                <div class="actions">
                                    <a class="button primary" href="<?= h($url) ?>">下载</a>
                                    <button class="button" type="button" data-copy="<?= h($url) ?>">复制链接</button>
                                    <?php if ($manager['admin']): ?>
                                    <form method="post" action="/manage/delete" data-delete="<?= h($file['name']) ?>">
                                        <input type="hidden" name="csrf" value="<?= h($manager['csrf']) ?>">
                                        <input type="hidden" name="name" value="<?= h($file['name']) ?>">
                                        <button class="button danger" type="submit">删除</button>
                                    </form>
                                    <?php endif; ?>
                                    <span class="copied" aria-live="polite"></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
    <script>
        document.querySelectorAll('[data-delete]').forEach(form => form.addEventListener('submit', event => {
            if (!confirm(`确定删除「${form.dataset.delete}」吗？\n删除后分享链接将失效，文件会移入服务器回收目录。`)) event.preventDefault();
        }));
        const uploadForm = document.querySelector('#upload-form');
        uploadForm?.addEventListener('submit', event => {
            event.preventDefault();
            const file = uploadForm.elements.file.files[0];
            const status = document.querySelector('#upload-status');
            const progress = document.querySelector('#upload-progress');
            const button = uploadForm.querySelector('button');
            if (!file) return;
            if (file.size > <?= ShareStore::MAX_UPLOAD_BYTES ?>) { status.textContent = '文件过大，单个文件最多 45 MB。'; return; }
            const request = new XMLHttpRequest();
            request.open('POST', uploadForm.action);
            request.setRequestHeader('Accept', 'application/json');
            request.timeout = 30 * 60 * 1000;
            button.disabled = true;
            progress.hidden = false;
            progress.value = 0;
            status.textContent = '正在上传，请勿关闭页面…';
            request.upload.onprogress = e => {
                if (e.lengthComputable) {
                    progress.value = Math.round(e.loaded / e.total * 100);
                    status.textContent = progress.value === 100 ? '上传完成，正在保存…' : `正在上传 ${progress.value}%`;
                }
            };
            request.onload = () => {
                let result;
                try { result = JSON.parse(request.responseText); } catch { result = {message: request.status === 413 ? '文件过大，请选择 45 MB 以内的文件。' : '上传未完成，请刷新页面确认后重试。'}; }
                if (request.status >= 200 && request.status < 300 && result.ok) { window.location.assign('/'); return; }
                status.textContent = result.message;
                button.disabled = false;
            };
            request.onerror = request.ontimeout = () => { status.textContent = '连接中断，请刷新页面确认上传结果后重试。'; button.disabled = false; };
            request.send(new FormData(uploadForm));
        });
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-copy]');
            if (!button) return;
            const status = button.parentElement.querySelector('.copied');
            try {
                await navigator.clipboard.writeText(button.dataset.copy);
                status.textContent = '已复制';
                setTimeout(() => { status.textContent = ''; }, 1600);
            } catch {
                status.textContent = '复制失败';
            }
        });
    </script>
</body>
</html>
<?php
function serve_download(ShareStore $store, string $id): void
{
    try {
        $file = $store->recordDownload($id);
    } catch (Throwable) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $path = (string) $file['path'];
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(410);
        echo 'File missing';
        return;
    }

    $name = (string) $file['name'];
    header('Content-Type: ' . ((string) $file['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . addcslashes($name, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}
