<?php
$window=$data['window'];$active=$window['active'];$token=(string)$data['token'];$receipt=$data['receipt']??null;
$action='/upload/'.$token;$url=canonical_base().$action;
$command='curl --fail-with-body --show-error -H "Accept: application/json" -F "file=@/path/to/file.pdf" "'.$url.'"';
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow"><title>交付文件 · Share Files</title><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/app.css?v=20261002-guest"><script src="/assets/app.js?v=20261002-guest" defer></script><script src="/assets/guest-upload.js?v=20261002-guest" defer></script><link rel="stylesheet" href="/assets/guest-upload.css?v=20261002-guest"></head>
<body class="public-catalogue guest-upload-page"><header class="catalogue-header"><a class="brand" href="/"><span class="brand-symbol"><?= sf_icon('folder') ?></span><span>Share Files<small>把文件交给接收方</small></span></a></header>
<main class="guest-content" data-guest-page data-status-url="<?= h($action) ?>/status" data-expires="<?= $active?(int)$window['expires_at']:0 ?>" data-server-time="<?= time() ?>">
<div class="guest-intro"><span class="eyebrow">访客收件</span><h1>交付文件</h1><p>无需账号。文件只交给接收方，不会自动公开分享。</p></div>
<div class="guest-window-banner" role="status"><span class="receive-dot <?= $active?'is-open':'' ?>"></span><div><strong data-window-label><?= $active?'收件窗口已开启':'收件窗口已关闭' ?></strong><span data-window-countdown><?= $active?'正在读取剩余时间…':'请联系接收方重新开启，并获取新的上传链接。' ?></span></div></div>
<?php if($active): ?>
<div class="guest-columns"><section class="panel guest-upload-panel"><h2><?= sf_icon('upload') ?>选择并上传</h2><p>单个文件最多 45 MiB。请在窗口结束前完成上传。</p>
<form method="post" action="<?= h($action) ?>" enctype="multipart/form-data" data-guest-upload-form>
<label class="guest-file-picker"><span>把文件放进来</span><small>点击选择本地文件</small><input type="file" name="file" required data-guest-file></label>
<button class="button primary full-width" type="submit" data-guest-submit>上传给接收方</button><div class="guest-progress" hidden data-guest-progress><progress max="100" value="0"></progress><span aria-live="polite" data-guest-progress-text></span></div>
<div class="inline-error" role="alert" data-guest-error hidden></div><div class="guest-receipts" role="status" aria-live="polite" data-guest-receipts><?php if($receipt): ?><p>已收到：<?= h($receipt['name']) ?>。接收方可在后台下载。</p><?php endif ?></div>
</form><p class="form-hint">同名上传会另存为独立文件，不会覆盖任何已有文件。发送到 100% 后仍需等待接收确认；到期或手动关闭时尚未保存的文件会被拒绝。</p></section>
<section class="panel guest-agent-panel"><div class="eyebrow">Agent / 命令行</div><h2>用命令直接交付</h2><p>无需登录或 Cookie。将本地文件作为 <code>file</code> 字段发送到当前链接，并请求 JSON 响应。</p><pre><code><?= h($command) ?></code></pre><button class="button secondary" type="button" data-copy="<?= h($command) ?>" data-copy-label="上传命令"><?= sf_icon('copy') ?>复制命令</button>
<p class="form-hint">Windows PowerShell 请用 <code>curl.exe</code>，把 <code>/path/to/file.pdf</code> 换成本地路径。每次请求上传一个文件；如果连接中断，请先让接收方核对收件结果，再决定是否重试。</p>
<dl class="guest-api-notes"><div><dt>201</dt><dd>保存成功，返回 <code>ok</code>、<code>name</code> 和 <code>bytes</code>，不提供公开下载链接。</dd></div><div><dt>410</dt><dd>窗口关闭、过期或链接已替换；联系接收方重新开启。</dd></div><div><dt>413 / 429</dt><dd>文件超过 45 MiB / 请求过于频繁，一分钟后重试。</dd></div><div><dt>400 / 409 / 503</dt><dd>文件字段无效 / 窗口容量已满 / 接收方暂不可用，查看 JSON 中的 <code>message</code>。</dd></div></dl>
<p class="form-hint">每分钟同一来源最多 10 次、本入口合计 30 次请求；每次开启最多接收 100 个文件、合计 1 GiB。</p></section></div>
<?php else: ?><section class="panel guest-closed"><h2>这次收件已结束</h2><p>此页面不会显示已经收到的文件。需要继续上传时，请向接收方索取新链接。</p></section><?php endif ?>
<footer class="catalogue-footer"><span>Share Files</span><span>限时接收 · 默认不公开</span></footer></main><div id="toast" class="toast" role="status" aria-live="polite" hidden></div></body></html>
