<?php
// Visitor-facing fields are rendered explicitly. No private store, session or event data is used.
$totalFiles=count($files);
$search=is_string($_GET['q']??null)?trim($_GET['q']):'';
if($search!=='')$files=array_values(array_filter($files,static fn($item)=>stripos((string)$item['name'],$search)!==false));
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><meta name="referrer" content="no-referrer">
<title>共享文件 · Share Files</title>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/app.css?v=20261002"><script src="/assets/app.js?v=20261002" defer></script>
</head>
<body class="public-catalogue">
<a class="skip-link" href="#public-content">跳转到共享文件</a>
<header class="catalogue-header"><a class="brand" href="/"><span class="brand-symbol"><?= sf_icon('folder') ?></span><span>Share Files<small>简单分享，安心交付</small></span></a><a class="button secondary" href="/admin"><?= sf_icon('lock') ?>管理工作台</a></header>
<main class="catalogue-content" id="public-content" tabindex="-1">
  <div class="catalogue-hero"><div><span class="eyebrow">值得分享，轻松抵达</span><h1>共享文件</h1><p>选择文件即可下载，也可以复制链接与他人分享。</p></div><span class="catalogue-total"><?= sf_icon('folder') ?><strong><?= sf_number($totalFiles) ?></strong> 个可下载文件</span></div>
  <section class="panel catalogue-panel" aria-labelledby="catalogue-list-title">
    <div class="catalogue-toolbar"><h2 id="catalogue-list-title">文件列表<?php if($search!==''): ?><span class="small-badge"><?= sf_number(count($files)) ?> 个匹配</span><?php endif ?></h2><form method="get" action="/" role="search" class="catalogue-search"><label class="search-input"><?= sf_icon('search') ?><span class="sr-only">搜索共享文件</span><input id="public-search" type="search" name="q" value="<?= h($search) ?>" maxlength="255" placeholder="搜索文件名…" autocomplete="off"></label><button class="button secondary" type="submit">搜索</button></form></div>
    <?php if($search!==''): ?><div class="active-filters"><span>搜索：<?= h($search) ?></span><a href="/">显示全部文件</a></div><?php endif ?>
    <?php if(!$files): sf_empty($search!==''?'search':'folder',$search!==''?'没有匹配的文件':'暂无可下载的文件',$search!==''?'试试其他文件名，或返回查看全部共享文件。':'文件开放分享后会出现在这里。你也可以向分享者索取链接。',$search!==''?'/':null,$search!==''?'显示全部文件':null); else: ?>
    <div class="table-wrap"><table class="data-table public-files-table"><thead><tr><th>文件名称 / 大小</th><th>SHA256 校验值</th><th class="align-right">公开下载</th><th>最近公开下载</th><th class="align-right">操作</th></tr></thead><tbody>
    <?php foreach($files as$item): $url=public_download_url($item);[$typeIcon,$typeColor,$typeLabel]=sf_file_type($item); ?>
      <tr data-public-file="<?= h($item['public_id']) ?>">
        <td class="public-file-name" data-label="文件"><div class="table-file"><?= sf_file_icon($item) ?><div><a class="file-name" href="<?= h($url) ?>"><?= h($item['name']) ?></a><span class="cell-subtle"><?= h($typeLabel) ?><i class="separator">·</i><?= h(format_bytes((int)$item['bytes'])) ?><?php if($item['has_password']): ?><span class="public-password"><?= sf_icon('lock') ?>需要密码</span><?php endif ?></span></div></div></td>
        <td class="public-file-hash" data-label="SHA256 校验值"><?php if(!empty($item['sha256'])): ?><details class="public-hash-details"><summary><code><?= h(substr($item['sha256'],0,10).'…'.substr($item['sha256'],-6)) ?></code><?= sf_icon('chevron-down') ?><span class="sr-only">查看完整 SHA256</span></summary><div><code data-public-sha><?= h($item['sha256']) ?></code><button class="button secondary small" type="button" data-copy="<?= h($item['sha256']) ?>" data-copy-label="完整 SHA256"><?= sf_icon('copy') ?>复制校验值</button></div></details><?php else: ?><span class="muted">暂无校验值</span><?php endif ?></td>
        <td class="align-right public-file-count" data-label="公开下载"><strong><?= sf_number($item['public_count']) ?></strong><span> 次</span></td>
        <td class="public-file-time" data-label="最近公开下载"><time class="time-value"><?= h(sf_date($item['last_public_at']??null,$timezone)) ?></time></td>
        <td class="public-file-actions"><div class="row-actions"><a class="button primary" href="<?= h($url) ?>" data-public-download><?= sf_icon($item['has_password']?'lock':'download') ?><?= $item['has_password']?'验证并下载':'下载文件' ?></a><button class="button secondary" type="button" data-copy="<?= h($url) ?>" data-copy-label="分享链接"><?= sf_icon('copy') ?>复制链接</button></div></td>
      </tr>
    <?php endforeach ?>
    </tbody></table></div>
    <?php endif ?>
  </section>
  <div class="catalogue-notes"><p><?= sf_icon('info') ?>下载次数按获准开始的公开会话统计，续传不会重复计数。</p><p><?= sf_icon('clock') ?>日期与时间按 <?= h($timezone) ?> 显示</p></div>
  <footer class="catalogue-footer"><span>Share Files</span><span>让分享井然有序</span></footer>
</main>
<div id="toast" class="toast" role="status" aria-live="polite" aria-atomic="true" hidden></div>
</body></html>
