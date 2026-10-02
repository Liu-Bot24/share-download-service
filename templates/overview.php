<?php
$today=(new DateTimeImmutable('now',new DateTimeZone($timezone)))->format('Y-m-d');
$needsAttention=array_values(array_filter($files,fn($f)=>in_array($f['state'],['exhausted','missing','destroy_pending','destroying'],true)));
?>
<div class="page-heading"><div><div class="eyebrow">你的文件，一目了然</div><h1>概览</h1><p>从一次分享，到每一次抵达。</p></div><button class="button primary" type="button" data-open-upload><?= sf_icon('upload') ?>上传文件</button></div>
<div class="metrics-grid">
<?php foreach([
['今日公开下载',$summary['today']??0,'获准开始的公开下载会话','download',sf_url('/admin/downloads',['start'=>$today,'end'=>$today])],
['近 7 天下载',$summary['last7']??0,'最近 7 个自然日，含今天','chart','/admin/downloads?days=7'],
['分享中文件',$summary['active']??0,'当前开放的文件','folder','/admin/files?state=active'],
['待处理文件',$summary['attention']??0,'到额、缺失或等待销毁','alert','/admin/files?state=attention'],
]as[$label,$value,$hint,$icon,$href]): ?>
<a class="metric-card" href="<?= h($href) ?>"><div class="metric-label"><?= h($label) ?><span class="metric-icon<?= $icon==='alert'&&$value?' amber':'' ?>"><?= sf_icon($icon) ?></span></div><div class="metric-value"><?= sf_number($value) ?><span><?= $icon==='folder'||$icon==='alert'?'个':'次' ?></span></div><div class="metric-bottom"><span><?= h($hint) ?></span><?= sf_icon('arrow') ?></div></a>
<?php endforeach ?>
</div>
<div class="overview-chart-grid">
<section class="panel trend-panel"><div class="panel-heading"><div><h2>下载趋势</h2><p>近 7 天 · 公开下载会话</p></div><a class="text-link" href="/admin/analytics?days=7">完整分析<?= sf_icon('arrow') ?></a></div><div class="chart-summary"><strong><?= sf_number($summary['last7']??0) ?><span>次</span></strong><span class="chart-legend"><i></i>公开下载</span></div><?php sf_line_chart($analytics['trend']??[],sf_range_params($analytics),'overview-trend') ?></section>
<section class="panel sharing-summary"><div class="panel-heading"><div><h2>分享概况</h2><p>完整保留的公开历史</p></div><?= sf_icon('link') ?></div><div class="lifetime-value"><?= sf_number($summary['lifetime']??0) ?><span>全部公开累计</span></div><div class="summary-breakdown"><div><span><i class="dot blue"></i>升级后明细</span><strong><?= sf_number(max(0,($summary['lifetime']??0)-($summary['legacy_total']??0))) ?></strong></div><div><span><i class="dot gray"></i>历史迁入累计</span><strong><?= sf_number($summary['legacy_total']??0) ?></strong></div></div><div class="quiet-note"><?= sf_icon('info') ?><p>累计包含已暂停、回收与销毁文件的历史。后台专用下载不计入。</p></div><a class="button secondary full-width" href="/admin/files">管理所有文件<?= sf_icon('arrow') ?></a></section>
</div>
<?php if($needsAttention): ?>
<section class="attention-strip"><div class="attention-title"><?= sf_icon('alert') ?><div><strong><?= sf_number(count($needsAttention)) ?> 个文件需要留意</strong><span>已有下载仍按原授权继续；累计记录始终保留</span></div></div><div class="attention-items"><?php foreach(array_slice($needsAttention,0,3)as$item): ?><a href="/admin/files/<?= (int)$item['id'] ?>"><?= h($item['name']) ?><span><?= h(sf_status_label($item['state'])) ?></span><?= sf_icon('chevron') ?></a><?php endforeach ?></div></section>
<?php endif ?>
<section class="panel recent-panel"><div class="panel-heading"><div><h2>近期下载</h2><p>近 30 天的最新公开会话</p></div><a class="text-link" href="/admin/downloads?days=30">查看全部<?= sf_icon('arrow') ?></a></div><?php sf_events_table($events,$timezone,true) ?></section>
<div class="definition-note"><?= sf_icon('shield') ?><p>一次公开下载 = 一个获准开始的公开会话。中断不会退还名额；服务器传输结束不代表访客已保存文件。</p></div>
