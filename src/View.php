<?php
declare(strict_types=1);

/** Small server-rendered view layer. No visitor data or application state is stored in the browser. */
function sf_icon(string $name, string $class = ''): string
{
    $paths = [
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'folder'=>'<path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
        'file'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
        'history'=>'<path d="M3 11a9 9 0 1 1 2.5 7.1M3 4v7h7M12 7v5l3 2"/>',
        'chart'=>'<path d="M3 3v18h18M7 14l4-5 4 3 6-8"/>',
        'settings'=>'<path d="m9 3-.6 2.1-1.8 1L4.5 6l-2 3.5L4 11v2l-1.5 1.5 2 3.5 2.1-.1 1.8 1L9 21h6l.6-2.1 1.8-1 2.1.1 2-3.5L20 13v-2l1.5-1.5-2-3.5-2.1.1-1.8-1L15 3Z"/><circle cx="12" cy="12" r="3"/>',
        'arrow'=>'<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'arrow-up'=>'<path d="M12 19V5m-5 5 5-5 5 5"/>',
        'chevron'=>'<path d="m9 5 7 7-7 7"/>',
        'chevron-down'=>'<path d="m6 9 6 6 6-6"/>',
        'back'=>'<path d="M19 12H5m5-5-5 5 5 5"/>',
        'search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'upload'=>'<path d="M12 16V3m-5 5 5-5 5 5M4 15v5a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-5"/>',
        'download'=>'<path d="M12 3v13m-5-5 5 5 5-5M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/>',
        'copy'=>'<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        'link'=>'<path d="m10 13 4-4M8 15l-2 2a4 4 0 0 1-5-5l5-5a4 4 0 0 1 6 0M16 9l2-2a4 4 0 0 0-5-5l-5 5M12 17a4 4 0 0 0 6 0l5-5" transform="translate(1 2) scale(.9)"/>',
        'external'=>'<path d="M14 3h7v7m0-7L10 14M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5"/>',
        'refresh'=>'<path d="M20 7v5h-5M4 17v-5h5M5.5 7a7.5 7.5 0 0 1 12-2l2.5 3M4 16l2.5 3a7.5 7.5 0 0 0 12-2"/>',
        'lock'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'unlock'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.5-2M12 14v3"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'check-circle'=>'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'pause'=>'<path d="M8 5v14M16 5v14"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert'=>'<path d="m10.3 3.9-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3.1l-8-14a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
        'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'close'=>'<path d="m6 6 12 12M18 6 6 18"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'trash'=>'<path d="M3 6h18M8 6V3h8v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>',
        'restore'=>'<path d="M3 4v7h7M3 11a9 9 0 1 1 2.5 7M12 7v5l3 2"/>',
        'logout'=>'<path d="M9 3H4v18h5M9 12h12m-5-5 5 5-5 5"/>',
        'globe'=>'<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18M5 7h14M5 17h14"/>',
        'shield'=>'<path d="M12 2 3 6v6c0 6 9 10 9 10s9-4 9-10V6Z"/><path d="m8 12 3 3 5-6"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'filter'=>'<path d="M4 6h16M7 12h10M10 18h4"/><circle cx="9" cy="6" r="2" fill="currentColor" stroke="none"/>',
        'server'=>'<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01M11 6.5h6M11 17.5h6"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'eye'=>'<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'image'=>'<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1"/><path d="m3 17 5-5 4 4 3-3 6 6"/>',
        'archive'=>'<rect x="3" y="3" width="18" height="5" rx="1"/><path d="M5 8v13h14V8M10 12h4"/>',
        'video'=>'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m10 9 5 3-5 3Z"/>',
        'code'=>'<path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-16-2 20"/>',
        'spark'=>'<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5Z"/>',
    ];
    return '<svg class="icon '.h($class).'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['file']).'</svg>';
}
function sf_number(mixed $n): string { return number_format((int)$n); }
function sf_date(mixed $at, string $timezone): string { return $at ? format_time($at, $timezone) : '暂无公开下载'; }
function sf_url(string $path, array $params = []): string
{
    return $path.($params ? '?'.http_build_query(array_filter($params, static fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986) : '');
}
function sf_query_url(string $path, array $changes = [], ?array $base = null): string
{
    $params = $base ?? array_intersect_key($_GET, array_flip(['q','state','password','sort','page','days','start','end','file_id','region','referrer','ip','status','tab','id']));
    return sf_url($path, array_replace($params, $changes));
}
function sf_csrf(array $manager): void { echo '<input type="hidden" name="csrf" value="'.h($manager['csrf']??'').'">'; }
function sf_status_label(string $state): string
{
    return match($state) {'active'=>'分享中','paused'=>'已暂停','exhausted'=>'名额已用完','missing'=>'文件缺失','destroy_pending'=>'等待下载结束','destroying'=>'正在销毁','destroyed'=>'已永久销毁','trashed'=>'回收目录',default=>'状态未知'};
}
function sf_badge(string $state): string
{
    $icon = match($state) {'active'=>'check-circle','paused'=>'pause','exhausted'=>'clock','missing'=>'alert','destroy_pending'=>'clock','destroying'=>'refresh','destroyed','trashed'=>'archive',default=>'info'};
    return '<span class="badge badge-'.h($state).'">'.sf_icon($icon).h(sf_status_label($state)).'</span>';
}
function sf_transfer_label(string $state): string
{
    return match($state) {'server_finished'=>'服务器传输结束','interrupted'=>'服务器观察到中断','inflight'=>'传输进行中','unknown'=>'传输情况未知',default=>'传输情况未知'};
}
function sf_transfer_badge(string $state): string
{
    return '<span class="badge transfer-'.h($state).'">'.sf_icon($state==='server_finished'?'check-circle':($state==='interrupted'?'alert':'clock')).h(sf_transfer_label($state)).'</span>';
}
function sf_file_type(array $file): array
{
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $mime = (string)($file['mime_type']??'');
    if (str_starts_with($mime,'image/')) return ['image','purple',strtoupper($ext?:'图片')];
    if (str_starts_with($mime,'video/') || str_starts_with($mime,'audio/')) return ['video','pink',strtoupper($ext?:'媒体')];
    if (in_array($ext,['zip','rar','7z','tar','gz','tgz','bz2'],true)) return ['archive','amber',strtoupper($ext)];
    if (in_array($ext,['json','js','php','py','html','css','sh','yml','yaml','xml'],true)) return ['code','teal',strtoupper($ext)];
    if ($ext==='pdf') return ['file','red','PDF'];
    return ['file','blue',strtoupper($ext?:'文件')];
}
function sf_file_icon(array $file, string $size=''): string
{
    [$icon,$color,$type]=sf_file_type($file);
    return '<span class="file-icon '.$color.' '.h($size).'">'.sf_icon($icon).'</span>';
}
function sf_empty(string $icon,string $title,string $body,?string $href=null,?string $label=null): void
{
    echo '<div class="empty-state"><span class="empty-icon">'.sf_icon($icon).'</span><h3>'.h($title).'</h3><p>'.h($body).'</p>';
    if($href) echo '<a class="button secondary" href="'.h($href).'">'.h($label??'返回文件管理').sf_icon('arrow').'</a>';
    echo '</div>';
}
function sf_pagination(array $pagination,string $path): void
{
    $page=max(1,(int)($pagination['page']??1));$pages=max(1,(int)($pagination['pages']??1));$total=(int)($pagination['total']??0);$per=(int)($pagination['per_page']??25);
    echo '<div class="pagination"><span>'.($total?'显示 '.sf_number(($page-1)*$per+1).'–'.sf_number(min($total,$page*$per)).' 条，共 '.sf_number($total).' 条':'共 0 条记录').'</span><div class="pagination-controls">';
    if($page>1)echo '<a class="icon-button" aria-label="上一页" href="'.h(sf_query_url($path,['page'=>$page-1])).'">'.sf_icon('back').'</a>';else echo '<span class="icon-button disabled" aria-hidden="true">'.sf_icon('back').'</span>';
    echo '<span class="page-number">'.sf_number($page).' <span>/ '.sf_number($pages).'</span></span>';
    if($page<$pages)echo '<a class="icon-button" aria-label="下一页" href="'.h(sf_query_url($path,['page'=>$page+1])).'">'.sf_icon('arrow').'</a>';else echo '<span class="icon-button disabled" aria-hidden="true">'.sf_icon('arrow').'</span>';
    echo '</div></div>';
}
function sf_range_params(array $analytics): array
{
    $range=$analytics['range']??[];
    return array_filter(['days'=>$range['days']??($_GET['days']??30),'start'=>$range['start']??($_GET['start']??null),'end'=>$range['end']??($_GET['end']??null),'file_id'=>$_GET['file_id']??null,'region'=>$_GET['region']??null,'referrer'=>$_GET['referrer']??null,'ip'=>$_GET['ip']??null,'status'=>$_GET['status']??null],fn($v)=>$v!==null&&$v!=='');
}
function sf_range_control(string $path,array $analytics,bool $compact=false): void
{
    $range=$analytics['range']??[];$days=(int)($range['days']??($_GET['days']??30));$isCustom=isset($_GET['start'])||isset($_GET['end']);
    echo '<div class="range-control"><div class="segmented" aria-label="时间范围">';
    foreach([7,30,90]as$d)echo '<a class="'.(!$isCustom&&$days===$d?'selected':'').'" '.(!$isCustom&&$days===$d?'aria-current="true"':'').' href="'.h(sf_query_url($path,['days'=>$d,'start'=>null,'end'=>null,'page'=>null,'id'=>null])).'">近 '.$d.' 天</a>';
    echo '</div><details class="date-picker"><summary class="button secondary'.($isCustom?' selected':'').'">'.sf_icon('calendar').'<span>'.($isCustom?h(($range['start']??$_GET['start']??'').' → '.($range['end']??$_GET['end']??'')):'自定义').'</span>'.sf_icon('chevron-down').'</summary><form method="get" action="'.h($path).'" class="date-popover">';
    foreach(['file_id','region','referrer','ip','status','tab']as$key)if(isset($_GET[$key])&&is_scalar($_GET[$key]))echo '<input type="hidden" name="'.h($key).'" value="'.h($_GET[$key]).'">';
    echo '<label>开始日期<input type="date" name="start" required value="'.h($range['start']??$_GET['start']??'').'"></label><label>结束日期<input type="date" name="end" required value="'.h($range['end']??$_GET['end']??'').'"></label><button class="button primary" type="submit">应用范围</button><small>包含开始与结束日期，按当前显示时区统计</small></form></details></div>';
}
function sf_line_chart(array $points,array $params=[],string $id='download-trend'): void
{
    $points=array_values($points);$total=array_sum(array_map(static fn($p)=>(int)($p['count']??0),$points));
    if(!$points){sf_empty('chart','这个时间范围还没有下载记录','公开下载获准开始后，趋势会在这里呈现。历史迁入累计不会被编造成逐次记录。');return;}
    $max=max(1,...array_map(static fn($p)=>(int)($p['count']??0),$points));$step=max(1,(int)ceil($max/4));$top=$step*4;$W=900;$H=240;$left=62;$right=18;$ytop=22;$bottom=208;$n=count($points);$plotW=$W-$left-$right;
    $xy=[];foreach($points as$i=>$p)$xy[]=[$left+($n===1?$plotW/2:$plotW*$i/($n-1)),$bottom-((int)($p['count']??0)/$top)*($bottom-$ytop)];
    $poly=implode(' ',array_map(static fn($p)=>round($p[0],2).','.round($p[1],2),$xy));$area=$left.','.$bottom.' '.$poly.' '.($W-$right).','.$bottom;
    echo '<div class="line-chart"><svg viewBox="0 0 '.$W.' '.$H.'" role="img" aria-labelledby="'.h($id).'-title '.h($id).'-desc"><title id="'.h($id).'-title">公开下载趋势</title><desc id="'.h($id).'-desc">所选范围共 '.sf_number($total).' 次公开下载。下方可展开逐日数据，并进入对应下载记录。</desc><defs><linearGradient id="'.h($id).'-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#0071e3" stop-opacity=".16"/><stop offset="100%" stop-color="#0071e3" stop-opacity=".01"/></linearGradient></defs>';
    for($i=0;$i<=4;$i++){$y=$bottom-$i*($bottom-$ytop)/4;echo '<line x1="'.$left.'" y1="'.$y.'" x2="'.($W-$right).'" y2="'.$y.'" class="chart-grid"/><text x="'.($left-12).'" y="'.($y+4).'" text-anchor="end" class="chart-axis">'.sf_number($step*$i).'</text>';}
    echo '<polygon points="'.$area.'" fill="url(#'.h($id).'-fill)"/><polyline points="'.$poly.'" fill="none" stroke="#0071e3" stroke-width="2.7" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
    $showEvery=max(1,(int)ceil(($n-1)/6));
    foreach($points as$i=>$p){$date=(string)($p['date']??$p['label']??'');$label=(string)($p['label']??$date);$href=sf_url('/admin/downloads',array_replace($params,['start'=>$date,'end'=>$date,'days'=>null]));[$x,$y]=$xy[$i];echo '<a href="'.h($href).'" aria-label="'.h($label.'，'.sf_number($p['count']??0).' 次公开下载').'" class="chart-point"><circle cx="'.$x.'" cy="'.$y.'" r="9" fill="transparent"/><circle cx="'.$x.'" cy="'.$y.'" r="'.($n<=14?3:2).'" fill="#fff" stroke="#0071e3" stroke-width="2"/><title>'.h($label).' · '.sf_number($p['count']??0).' 次</title></a>';if($i%$showEvery===0||$i===$n-1)echo '<text x="'.$x.'" y="234" text-anchor="'.($i===0?'start':($i===$n-1?'end':'middle')).'" class="chart-axis'.($i>0&&$i<$n-1&&intdiv($i,$showEvery)%2===1?' chart-date-optional':'').'">'.h(strlen($label)>7?substr($label,-5):$label).'</text>';}
    echo '</svg></div><details class="chart-data"><summary>查看逐日数据'.sf_icon('chevron-down').'</summary><div class="chart-data-grid">';
    foreach($points as$p){$date=(string)($p['date']??$p['label']??'');echo '<a href="'.h(sf_url('/admin/downloads',array_replace($params,['start'=>$date,'end'=>$date,'days'=>null]))).'"><span>'.h($p['label']??$date).'</span><strong>'.sf_number($p['count']??0).'</strong></a>';}
    echo '</div></details>';
}
function sf_rank_list(array $rows,string $kind,array $params=[],int $limit=6): void
{
    if(!$rows){sf_empty($kind==='files'?'file':'globe','暂无可分析的记录','这个时间范围内还没有公开下载。');return;}
    $limit=max(1,$limit);
    $unknown=null;
    foreach($rows as$index=>&$row){
        $row['_rank']=$index+1;
        if($kind==='regions'&&($row['label']??'')==='未知')$unknown=$row;
    }
    unset($row);
    $rows=array_slice($rows,0,$limit);
    // Unknown is evidence too: reserve a visible row even when it falls outside the top N.
    if($unknown&&!array_filter($rows,static fn($row)=>($row['label']??'')==='未知')){$unknown['_rank']='—';$rows[count($rows)-1]=$unknown;}
    $max=max(1,...array_map(static fn($r)=>(int)($r['count']??0),$rows));
    echo '<ol class="rank-list">';foreach($rows as$i=>$row){$name=(string)($row['label']??$row['name']??'未知');$count=(int)($row['count']??0);$key=match($kind){'files'=>'file_id','regions'=>'region',default=>'referrer'};$value=$kind==='files'?($row['id']??$row['file_id']??''):($row['value']??$row['label']??'');if($kind==='referrers'&&$value==='')$value='__direct__';echo '<li><a href="'.h(sf_url('/admin/downloads',array_replace($params,[$key=>$value]))).'" class="rank-row"><span class="rank-index">'.($row['_rank']??$i+1).'</span><div class="rank-info"><div class="rank-label"><span title="'.h($name).'">'.h($name?:'直接访问 / 未提供来源').'</span><strong>'.sf_number($count).'<small> 次</small></strong></div><div class="rank-bar"><span style="width:'.round($count/$max*100,2).'%"></span></div>';if(isset($row['unique_ips']))echo '<small>'.sf_number($row['unique_ips']).' 个独立 IP'.(isset($row['share'])?' · '.h($row['share']).'%':'').'</small>';echo '</div>'.sf_icon('chevron').'</a></li>'; }echo '</ol>';
}
function sf_hours_chart(array $rows): void
{
    $hours=array_fill(0,24,0);foreach($rows as$row){$h=(int)($row['hour']??0);if($h>=0&&$h<24)$hours[$h]=(int)($row['count']??0);}$max=max(1,...$hours);$peak=array_search(max($hours),$hours,true);
    echo '<div class="hour-chart" role="img" aria-label="按当前时区汇总的 24 小时下载分布，'.(array_sum($hours)?'最多为 '.$peak.' 点，共 '.$hours[$peak].' 次':'当前无下载记录').'">';foreach($hours as$h=>$v)echo '<div class="hour-column"><span class="hour-value">'.$v.'</span><div class="hour-track"><span style="height:'.($v?max(3,$v/$max*100):0).'%"></span></div><span class="hour-label">'.($h%3===0||$h===23?str_pad((string)$h,2,'0',STR_PAD_LEFT):'').'</span></div>';echo '</div><details class="chart-data"><summary>查看时段数据'.sf_icon('chevron-down').'</summary><div class="chart-data-grid">';foreach($hours as$h=>$v)echo '<div><span>'.str_pad((string)$h,2,'0',STR_PAD_LEFT).':00–'.str_pad((string)$h,2,'0',STR_PAD_LEFT).':59</span><strong>'.sf_number($v).'</strong></div>';echo '</div></details>';
}
function sf_events_table(array $events,string $timezone,bool $compact=false): void
{
    if(!$events){sf_empty('history','还没有公开下载记录','访客获准开始下载后，这里会出现真实会话记录。后台下载与未获准的访问不会计入。');return;}
    echo '<div class="table-wrap"><table class="data-table events-table"><thead><tr><th>文件 / 下载会话</th><th>获准开始时间</th><th>近似地区 / 来源</th><th>传输状态</th><th class="align-right">详情</th></tr></thead><tbody>';
    foreach($events as$event){$detail=sf_query_url('/admin/downloads',['id'=>$event['id'],'page'=>null]);echo '<tr><td data-label="文件"><div class="table-file">'.sf_file_icon(['name'=>$event['filename']??'','mime_type'=>'']).'<div><a class="file-name" href="'.h('/admin/files/'.(int)$event['file_id']).'">'.h($event['filename']??'').'</a><span class="cell-subtle">会话 #'.h($event['id']).' · v'.h($event['version']??1).'</span></div></div></td><td data-label="获准开始"><span class="mono time-value">'.h(sf_date($event['started_at']??null,$timezone)).'</span><span class="cell-subtle">'.sf_number($event['request_count']??0).' 次传输请求</span></td><td data-label="地区 / 来源"><span class="region-label">'.sf_icon('globe').h($event['region']?:'未知').'</span><span class="cell-subtle truncate" title="'.h($event['referrer']??'').'">'.h(($event['referrer']??'')?:'直接访问 / 未提供来源').'</span></td><td data-label="传输状态">'.sf_transfer_badge((string)($event['status']??'unknown')).'</td><td class="align-right" data-label="记录详情"><a class="icon-button" aria-label="查看下载会话 '.h($event['id']).'" href="'.h($detail).'">'.sf_icon('chevron').'</a></td></tr>';}
    echo '</tbody></table></div>';
}
function render_admin(string $page,array $data): void
{
    $page=match($page){'index','dashboard'=>'overview','detail','file-detail'=>'file',default=>$page};
    if(!in_array($page,['overview','files','file','downloads','analytics','settings'],true))$page='overview';
    $manager=$data['manager']??[];$settings=$data['settings']??[];$timezone=(string)($settings['timezone']??'Asia/Shanghai');$analytics=$data['analytics']??[];$summary=$analytics['summary']??[];$files=$data['files']??[];$file=$data['file']??null;$events=$data['events']??[];$pagination=$data['pagination']??['page'=>1,'pages'=>1,'total'=>count($page==='files'?$files:$events),'per_page'=>25];$storage=$data['storage']??[];
    $titles=['overview'=>'概览','files'=>'文件管理','file'=>$file['name']??'文件详情','downloads'=>'下载记录','analytics'=>'数据分析','settings'=>'设置'];$title=$titles[$page];
    require dirname(__DIR__).'/templates/layout.php';
}
function render_public(string $page,array $data): void
{
    $manager=$data['manager']??[];$file=$data['file']??null;$error=(string)($data['error']??$manager['error']??'');$errorCode=(string)($data['error_code']??'');$title=match($page){'login'=>'管理登录','password'=>'输入分享密码',default=>'分享暂不可用'};
    require dirname(__DIR__).'/templates/public.php';
}
