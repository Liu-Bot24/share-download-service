<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/ShareStore.php';
require_once __DIR__ . '/../src/QueryService.php';
$roots=[];$passed=0;$failed=0;
function gate_fixture(array $stats): array {
    global $roots;
    $root=sys_get_temp_dir().'/share-merge-migration-'.bin2hex(random_bytes(10));$roots[]=$root;
    mkdir($root.'/files',0700,true);mkdir($root.'/storage',0700,true);
    file_put_contents($root.'/files/old.txt','original legacy bytes');
    file_put_contents($root.'/storage/stats.json',json_encode($stats,JSON_THROW_ON_ERROR));
    file_put_contents($root.'/storage/manager.json',json_encode(['username'=>'liuqi','password_hash'=>password_hash('synthetic-migration-only',PASSWORD_DEFAULT)]));
    return [$root,new ShareStore($root.'/files',$root.'/storage/stats.json')];
}
function gate_ok(bool $value,string $label): void { global $passed,$failed;if($value){$passed++;echo 'PASS '.$label."\n";}else{$failed++;echo 'FAIL '.$label."\n";} }
try {
    [$root,$store]=gate_fixture(['old.txt'=>['downloads'=>512,'last_downloaded_at'=>'2026-09-29T03:15:21+00:00']]);
    $json=file_get_contents($root.'/storage/stats.json');$manager=file_get_contents($root.'/storage/manager.json');$hash=hash_file('sha256',$root.'/files/old.txt');
    $store=new ShareStore($root.'/files',$root.'/storage/stats.json');
    gate_ok($store->resolve('old.txt')['public_count']===512 && count($store->files())===1,'repeated startup preserves legacy count and stable record');
    gate_ok($json===file_get_contents($root.'/storage/stats.json') && $manager===file_get_contents($root.'/storage/manager.json') && $hash===hash_file('sha256',$root.'/files/old.txt'),'migration preserves source JSON, existing manager config, and file bytes');
    $ctx=['ip'=>'127.0.0.1','ip_source'=>'peer','region'=>'unknown','geo_version'=>'test','referrer'=>''];
    $token=$store->createSession($store->resolve('old.txt')['id'],$ctx);$transfer=$store->beginTransfer($token,200,'',strlen('original legacy bytes'));$store->finishTransfer($transfer['transfer_id'],strlen('original legacy bytes'),true);
    gate_ok($store->resolve('old.txt')['public_count']===513 && (new QueryService($store))->reconcile()===[],'new public claim reconciles to imported total plus detailed event');
    $legacy=json_decode(file_get_contents($root.'/storage/stats.json'),true);
    gate_ok($legacy['old.txt']['downloads']===512,'old JSON intentionally remains stale after new claims: code-only rollback requires explicit count reconciliation');
    $bad=$root.'/storage/bad.json';file_put_contents($bad,'{"old.txt":{"downloads":9,"last_downloaded_at":"2026-10-01T12:00:00"}}');
    $isolated=$root.'/bad-storage';mkdir($isolated);copy($bad,$isolated.'/stats.json');$rejected=false;
    try {new ShareStore($root.'/files',$isolated.'/stats.json');}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'offset');}
    gate_ok($rejected && file_get_contents($bad)===file_get_contents($isolated.'/stats.json'),'offset-less legacy timestamps fail closed without rewriting source');
} finally {
    foreach($roots as $root){
        if(!str_starts_with($root,sys_get_temp_dir().'/share-merge-migration-')||is_link($root))throw new RuntimeException('unsafe fixture cleanup');
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $item){$item->isDir()&&!$item->isLink()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($root);
    }
}
echo "$passed passed, $failed failed\n";exit($failed?1:0);
