<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><meta name="referrer" content="same-origin">
<title><?= h($title) ?> · Share Files</title>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/app.css?v=20261002"><script src="/assets/app.js?v=20261002" defer></script>
<noscript><style>.sidebar{position:static;transform:none;width:auto}.workspace-main{margin-left:0}.main-nav{flex-direction:row;flex-wrap:wrap}.sidebar-bottom,.nav-label,.mobile-menu,.mobile-nav-close{display:none!important}.sidebar .brand{margin-bottom:14px}.nav-item{flex:1;justify-content:center}.nav-count{display:none}[data-open-upload]{display:none!important}</style></noscript>
</head>
<body class="workspace page-<?= h($page) ?>">
<a class="skip-link" href="#main-content">跳转到主要内容</a>
<div class="mobile-scrim" data-close-navigation></div>
<aside class="sidebar" id="primary-navigation" aria-label="主导航">
  <a class="brand" href="/admin"><span class="brand-symbol"><?= sf_icon('folder') ?></span><span>Share Files<small>让分享井然有序</small></span></a>
  <button class="icon-button mobile-nav-close" type="button" data-close-navigation aria-label="关闭导航"><?= sf_icon('close') ?></button>
  <div class="nav-label">工作空间</div>
  <nav class="main-nav">
  <?php foreach([['overview','/admin','grid','概览'],['files','/admin/files','folder','文件管理'],['downloads','/admin/downloads','history','下载记录'],['analytics','/admin/analytics','chart','数据分析'],['settings','/admin/settings','settings','设置']]as[$key,$href,$icon,$label]): $active=$page===$key||($page==='file'&&$key==='files'); ?>
  <a href="<?= h($href) ?>" class="nav-item<?= $active?' active':'' ?>" <?= $active?'aria-current="page"':'' ?>><?= sf_icon($icon) ?><span><?= h($label) ?></span><?php if($key==='files'&&isset($summary['active'])): ?><span class="nav-count"><?= sf_number($summary['active']) ?></span><?php endif ?></a>
  <?php endforeach ?>
  </nav>
  <div class="sidebar-bottom">
    <div class="sidebar-note"><?= sf_icon('shield') ?><span>公开下载，清晰可控<small>独立计数 · 完整保留历史</small></span></div>
    <div class="account-row"><span class="account-avatar"><?= sf_icon('user') ?></span><span class="account-name"><?= h($manager['username']??'管理员') ?><small>管理账户</small></span><form method="post" action="/admin/logout"><?php sf_csrf($manager) ?><button class="icon-button" type="submit" aria-label="退出登录" title="退出登录"><?= sf_icon('logout') ?></button></form></div>
  </div>
</aside>
<div class="workspace-main">
  <header class="topbar">
    <div class="breadcrumb"><button class="icon-button mobile-menu" type="button" aria-label="展开导航" aria-controls="primary-navigation" aria-expanded="false" data-navigation-toggle><?= sf_icon('menu') ?></button><span>工作空间</span><?= sf_icon('chevron') ?><span><?= h($page==='file'?'文件详情':$title) ?></span></div>
    <div class="topbar-meta"><span class="timezone-pill"><?= sf_icon('globe') ?><span><?= h($timezone) ?></span></span><span class="private-label"><?= sf_icon('lock') ?><span>仅管理员可见</span></span></div>
  </header>
  <main id="main-content" class="page-content" tabindex="-1">
    <?php if(!empty($manager['message'])||!empty($data['message'])): ?><div class="notice success" role="status"><?= sf_icon('check-circle') ?><span><?= h($data['message']??$manager['message']) ?></span><button class="icon-button" type="button" aria-label="关闭提示" data-dismiss-notice><?= sf_icon('close') ?></button></div><?php endif ?>
    <?php if(!empty($manager['error'])||!empty($data['error'])): ?><div class="notice error" role="alert"><?= sf_icon('alert') ?><span><?= h($data['error']??$manager['error']) ?><?php if(($data['error_code']??'')==='conflict'): ?> <a href="<?= h($_SERVER['REQUEST_URI']??'/admin/files') ?>">刷新并比对最新配置</a><?php endif ?></span></div><?php endif ?>
    <?php require __DIR__.'/'.$page.'.php'; ?>
    <noscript><section class="panel form-panel"><h2>上传文件</h2><form method="post" action="/admin/upload" enctype="multipart/form-data"><?php sf_csrf($manager) ?><label>选择文件（最大 45 MiB）<input type="file" name="file" required></label><button class="button primary" type="submit">上传文件</button></form></section></noscript>
    <footer class="page-footer"><span>Share Files · 每一次分享，都有迹可循</span><span>时间按 <?= h($timezone) ?> 显示</span><a href="https://db-ip.com" target="_blank" rel="noopener noreferrer">IP Geolocation by DB-IP</a></footer>
  </main>
</div>
<div id="toast" class="toast" role="status" aria-live="polite" aria-atomic="true" hidden></div>
<dialog class="upload-dialog" id="upload-dialog" aria-labelledby="upload-title">
  <div class="dialog-heading"><div><span class="eyebrow">添加到工作空间</span><h2 id="upload-title">上传文件</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="关闭上传窗口"><?= sf_icon('close') ?></button></div>
  <form method="post" action="/admin/upload" enctype="multipart/form-data" data-upload-form>
    <?php sf_csrf($manager) ?><input type="hidden" name="MAX_FILE_SIZE" value="47185920">
    <label class="upload-dropzone" for="upload-file" data-dropzone><span class="upload-symbol"><?= sf_icon('upload') ?></span><strong data-upload-label>选择文件，或拖放到这里</strong><span data-upload-size>每次上传一个文件，最大 45 MiB</span><input id="upload-file" name="file" type="file" required data-file-input></label>
    <p class="form-hint">同名文件不会被覆盖。新文件默认开放分享，不设密码和下载额度。</p>
    <section class="upload-progress" data-upload-progress hidden aria-labelledby="upload-status-title">
      <div class="upload-progress-heading"><strong id="upload-status-title" data-upload-status>准备发送</strong><span data-upload-percent>0%</span></div>
      <div class="upload-progress-track" role="progressbar" aria-label="文件发送进度" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-describedby="upload-progress-detail" data-upload-progressbar><span data-upload-progress-fill></span></div>
      <p id="upload-progress-detail" data-upload-detail aria-live="polite">进度来自浏览器实际发送量；发送完成后还需等待服务器保存。</p>
    </section>
    <div class="inline-error" data-upload-error role="alert" hidden></div>
    <a class="upload-check-link text-link" data-upload-check href="/admin/files" hidden>先检查文件列表<?= sf_icon('arrow') ?></a>
    <div class="upload-cancel-confirmation" data-upload-confirm-close hidden role="group" aria-labelledby="upload-close-title">
      <strong id="upload-close-title">要取消正在进行的上传吗？</strong>
      <p>取消会中断当前连接，但无法撤回服务器可能已保存的文件。再次上传前请检查文件列表。</p>
      <div><button class="button secondary" type="button" data-upload-keep>继续上传</button><button class="button danger" type="button" data-upload-abort-close>取消上传并关闭</button></div>
    </div>
    <div class="dialog-actions"><button class="button secondary" type="button" data-close-dialog data-upload-close>取消</button><button class="button ghost upload-abort-button" type="button" data-upload-abort hidden>取消上传</button><button class="button primary" type="submit" data-busy-label="正在发送…" data-upload-submit><?= sf_icon('upload') ?>上传文件</button></div>
  </form>
</dialog>
<dialog class="detail-drawer" id="detail-drawer" aria-labelledby="drawer-title"><div class="drawer-topbar"><h2 id="drawer-title">文件详情</h2><div><a class="icon-button" data-drawer-full href="/admin/files" aria-label="在完整页面中查看"><?= sf_icon('external') ?></a><button class="icon-button" type="button" data-close-dialog aria-label="关闭文件详情"><?= sf_icon('close') ?></button></div></div><div data-drawer-body></div></dialog>
</body></html>
