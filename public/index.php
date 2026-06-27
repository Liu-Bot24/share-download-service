<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$store = share_store();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#^/d/(.+)$#', $path, $matches)) {
    serve_download($store, rawurldecode($matches[1]));
    exit;
}

if ($path !== '/') {
    http_response_code(404);
    echo 'Not found';
    exit;
}

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
                <div class="summary"><?= count($files) ?> 个文件，来自宝塔目录 <code>files/</code>；下载时自动计数。</div>
            </div>
            <div class="metric">
                <strong><?= h((string) $totalDownloads) ?></strong>
                <span>总下载次数</span>
            </div>
        </header>

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
