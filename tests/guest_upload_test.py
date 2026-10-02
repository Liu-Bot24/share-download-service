#!/usr/bin/env python3
"""Isolated real HTTP checks for opt-in discovery and temporary write-only upload links."""
import concurrent.futures
import contextlib
import fcntl
import hashlib
import json
import os
import pathlib
import secrets
import signal
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.parse
from http_test import Client, Inputs

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
CHECKS = 0

def check(value, label):
    global CHECKS
    CHECKS += 1
    if not value: raise AssertionError(label)
    print('PASS', label, flush=True)

def main():
    with tempfile.TemporaryDirectory(prefix='share-guest-') as tmp:
        tmp = pathlib.Path(tmp); (tmp/'files').mkdir(); (tmp/'storage').mkdir()
        names = ['private-a.txt', 'private-b.txt', 'private-c.txt', 'private-d.txt']
        for name in names: (tmp/'files'/name).write_bytes(b'0123456789')
        with contextlib.closing(socket.socket()) as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'; password = secrets.token_urlsafe(24)
        env = {**os.environ, 'SHARE_FILES_DIR':str(tmp/'files'), 'SHARE_STORAGE_DIR':str(tmp/'storage'),
               'SHARE_BASE_URL':base, 'SHARE_ALLOW_HTTP':'1', 'PHP_CLI_SERVER_WORKERS':'4', 'FIXTURE_PASS':password}
        fixture = "require 'src/bootstrap.php';$s=share_store();file_put_contents($s->storageDir.'/manager.json',json_encode(['username'=>'tester','password_hash'=>password_hash(getenv('FIXTURE_PASS'),PASSWORD_DEFAULT)]));"
        subprocess.run([PHP,'-r',fixture],cwd=ROOT,env=env,check=True)
        log = open(tmp/'server.log','w+')
        server = subprocess.Popen([PHP,'-d','upload_max_filesize=50M','-d','post_max_size=52M','-S',f'127.0.0.1:{port}','-t','public','scripts/dev-router.php'],cwd=ROOT,env=env,stdout=log,stderr=log,start_new_session=True)
        db = sqlite3.connect(tmp/'storage/share.sqlite',timeout=10); db.row_factory=sqlite3.Row
        try:
            visitor=Client(base);admin=Client(base)
            for _ in range(100):
                try:
                    if visitor.request('/')[0]==200:break
                except OSError:pass
                time.sleep(.05)
            status,_,home=visitor.request('/')
            check(status==200 and all(n.encode() not in home for n in names), 'existing indexed files default to hidden on the public homepage')
            row=db.execute('SELECT * FROM files WHERE name=?',(names[0],)).fetchone();ident=row['id']
            old_link='/d/'+urllib.parse.quote(names[0])
            status,headers,_=visitor.request(old_link)
            check(status==302, 'hidden homepage entry retains its existing direct share link')
            ticket=headers['Location']
            check(visitor.request(f'/admin/files/{ident}/visibility',method='POST',data={'visible':'1'})[0]==403,'visitor cannot change homepage visibility')
            check(visitor.request('/admin/receive/open',method='POST',data={'minutes':'10'})[0]==403,'visitor cannot open an upload window')
            check(visitor.request('/admin/receive/close',method='POST')[0]==403,'visitor cannot close an upload window')
            check(visitor.request('/admin/receive')[0]==302,'inbox requires administrator authentication')
            _,_,html=admin.request('/admin/login');csrf=Inputs(html.decode()).values['csrf']
            check(admin.request('/admin/login',method='POST',data={'csrf':csrf,'username':'tester','password':password})[0]==303,'existing manager login works')
            _,_,html=admin.request('/admin/receive');csrf=Inputs(html.decode()).values['csrf']
            check(admin.request('/admin/receive/open',method='POST',data={'csrf':'wrong','minutes':'10'})[0]==403,'opening the window requires valid CSRF')
            check(admin.request(f'/admin/files/{ident}/visibility',method='POST',data={'csrf':'wrong','visible':'1'})[0]==403,'visibility changes require valid CSRF')
            for value in ['-1','0','1441','1.5','abc','']:
                check(admin.request('/admin/receive/open',method='POST',data={'csrf':csrf,'minutes':value})[0]==400,'invalid duration rejected: '+repr(value))
            for enabled in [True,False,True,False]:
                check(admin.request(f'/admin/files/{ident}/visibility',method='POST',data={'csrf':csrf,'visible':'1' if enabled else '0'})[0]==303,'explicit visibility state saves')
                _,_,home=visitor.request('/?q='+urllib.parse.quote(names[0]))
                marker=('data-public-file="'+row['public_id']+'"').encode()
                check((marker in home)==enabled,'search cannot return a file whose homepage switch is off')
            check(db.execute('SELECT policy_version FROM files WHERE id=?',(ident,)).fetchone()[0]==row['policy_version'],'visibility does not revoke pre-existing download authorization')
            check(visitor.request(ticket,headers={'Range':'bytes=0-2'})[0]==206,'previous download ticket still works after visibility changes')
            check(all(n.encode() not in visitor.request('/')[2] for n in names),'all four switches off leaves homepage filenames absent')

            def open_window():
                check(admin.request('/admin/receive/open',method='POST',data={'csrf':csrf,'minutes':'10'})[0]==303,'administrator opens a ten-minute window')
                window=db.execute('SELECT * FROM guest_upload_window').fetchone()
                check(595<=window['expires_at']-int(time.time())<=600,'server persists the requested absolute expiry')
                db.execute("DELETE FROM rate_limits WHERE bucket LIKE 'guest:%'");db.commit()
                return '/upload/'+window['token']
            def upload(route, name='delivery.txt', body=b'private guest bytes'):
                boundary='guest-'+secrets.token_hex(12)
                data=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+body+f'\r\n--{boundary}--\r\n'.encode())
                return Client(base).request(route,method='POST',data=data,headers={'Content-Type':'multipart/form-data; boundary='+boundary,'Accept':'application/json'})
            check(b'data-guest-upload-form' not in visitor.request('/upload')[2],'closed generic page exposes no active upload form')
            check(upload('/upload/'+'a'*64)[0]==410,'guessed or inactive upload link cannot upload')
            route=open_window()
            status,headers,html=visitor.request(route)
            check(status==200 and b'curl --fail-with-body' in html and base.encode() in html,'human page includes a domain-derived Agent command')
            check(all(n.encode() not in html for n in names) and b'/www/' not in html and b'60.205.' not in html,'guest page reveals no existing files, server paths or server IP')
            check(headers.get('X-Robots-Tag')=='noindex, nofollow' and headers.get('Referrer-Policy')=='no-referrer','upload capability pages forbid indexing and referrer leakage')
            status,_,body=visitor.request(route+'/status');state=json.loads(body)
            check(status==200 and state['active'] and 'token' not in state,'status returns timing without issuing another capability')
            check(upload(route,'.hidden')[0]==400,'hidden filename rejected')
            check(upload(route,'too-big.bin',b'0'*(45*1024*1024+1))[0]==413,'server enforces original 45 MiB single-file limit')
            status,_,body=upload(route,'delivery.php',b'<?php echo "not executable"; ?>')
            receipt=json.loads(body);received_name=receipt['name']
            check(status==201 and set(receipt)=={'ok','message','name','bytes'},'Agent gets a success receipt with no download URL or private metadata')
            received=db.execute('SELECT * FROM files WHERE name=?',(received_name,)).fetchone()
            check(received['state']=='paused' and received['homepage_visible']==0 and received['upload_origin']=='guest','received files default to private paused inbox entries')
            check(visitor.request('/d/'+urllib.parse.quote(received_name))[0]==409,'guest file bytes cannot be downloaded from a guessed share URL')
            check(received_name.encode() not in visitor.request('/')[2],'guest upload never appears on the public homepage')
            check(received_name.encode() in admin.request('/admin/receive')[2],'administrator inbox lists the received file')
            status,headers,_=admin.request(f'/admin/files/{received["id"]}/download-ticket',method='POST',data={'csrf':csrf})
            check(status==303 and admin.request(headers['Location'])[2]==b'<?php echo "not executable"; ?>','administrator can download exact attachment bytes from paused inbox')
            status,_,body=upload(route,'delivery.php',b'second distinct submission')
            second=json.loads(body)
            check(status==201 and second['name']!=received_name and (tmp/'files'/received_name).read_bytes()==b'<?php echo "not executable"; ?>','same-name submissions are separately preserved and never overwrite')
            # Hold the real catalog lock after HTTP has started staging, then revoke its window.
            for action in ['close','expire','rotate']:
                route=open_window();before=db.execute('SELECT COUNT(*) FROM files').fetchone()[0]
                with (tmp/'storage/locks/catalog.lock').open('a') as lock:
                    fcntl.flock(lock,fcntl.LOCK_EX)
                    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                        future=pool.submit(upload,route,'inflight.txt',b'pending')
                        deadline=time.monotonic()+5
                        while not list((tmp/'files').glob('.upload-*')):
                            if time.monotonic()>deadline:raise RuntimeError('upload did not reach staging')
                            time.sleep(.01)
                        if action=='close': admin.request('/admin/receive/close',method='POST',data={'csrf':csrf})
                        elif action=='expire':db.execute('UPDATE guest_upload_window SET expires_at=?',(int(time.time())-1,));db.commit()
                        else:admin.request('/admin/receive/open',method='POST',data={'csrf':csrf,'minutes':'10'})
                        fcntl.flock(lock,fcntl.LOCK_UN)
                        result=future.result(timeout=10)
                check(result[0]==410,action+' rejects an upload waiting to be published')
                check(db.execute('SELECT COUNT(*) FROM files').fetchone()[0]==before and not list((tmp/'files').glob('.upload-*')) and db.execute('SELECT COUNT(*) FROM file_mutations').fetchone()[0]==0,action+' leaves no published file, staged file or mutation journal')
                check(visitor.request(route+'/status')[0]==410 and upload(route)[0]==410,action+' rejects stale status and subsequent Agent uploads')
            route=open_window()
            db.execute("CREATE TRIGGER guest_fault BEFORE UPDATE OF received_count ON guest_upload_window BEGIN SELECT RAISE(ABORT,'synthetic fault'); END");db.commit()
            before=db.execute('SELECT COUNT(*) FROM files').fetchone()[0]
            status,headers,body=upload(route)
            check(status==503 and json.loads(body)['ok'] is False,'database failure returns an honest JSON failure')
            check(db.execute('SELECT COUNT(*) FROM files').fetchone()[0]==before and db.execute('SELECT received_count FROM guest_upload_window').fetchone()[0]==0,'database failure rolls back both the inbox file and window accounting')
            db.execute('DROP TRIGGER guest_fault');db.commit()
            db.execute('UPDATE guest_upload_window SET received_count=100');db.commit()
            check(upload(route)[0]==409,'window file capacity is enforced without creating extra files')
            db.execute('UPDATE guest_upload_window SET received_count=0,received_bytes=?',(1024**3,));db.commit()
            check(upload(route)[0]==409,'window byte capacity is enforced')
            route=open_window()
            db.execute('INSERT OR REPLACE INTO rate_limits VALUES(?,?,?)',('guest:global',30,int(time.time())+60));db.commit()
            check(upload(route)[0]==429,'aggregate upload rate limit rejects requests')
            check(not db.execute("SELECT 1 FROM rate_limits WHERE bucket LIKE 'guest:ip:%'").fetchone(),'later-bucket rejection rolls back earlier rate reservations')
            for constraint in ['files','bytes','ip-rate','global-rate']:
                route=open_window();before=db.execute('SELECT COUNT(*) FROM files').fetchone()[0]
                payload=b'last available guest upload slot'
                if constraint=='files':db.execute('UPDATE guest_upload_window SET received_count=99')
                elif constraint=='bytes':db.execute('UPDATE guest_upload_window SET received_bytes=?',(1024**3-len(payload),))
                else:
                    key='guest:global' if constraint=='global-rate' else 'guest:ip:'+hashlib.sha256(b'127.0.0.1').hexdigest()
                    db.execute('INSERT OR REPLACE INTO rate_limits VALUES(?,?,?)',(key,29 if constraint=='global-rate' else 9,int(time.time())+60))
                db.commit()
                with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
                    results=list(pool.map(lambda _: upload(route,'contended.txt',payload),range(8)))
                rejected=429 if 'rate' in constraint else 409
                check(sum(r[0]==201 for r in results)==1 and sum(r[0]==rejected for r in results)==7,constraint+' last slot admits exactly one of eight concurrent uploads')
                check(db.execute('SELECT COUNT(*) FROM files').fetchone()[0]==before+1 and not list((tmp/'files').glob('.upload-*')) and db.execute('SELECT COUNT(*) FROM file_mutations').fetchone()[0]==0,constraint+' contention leaves one complete private file and no abandoned publication')
                window=db.execute('SELECT received_count,received_bytes FROM guest_upload_window').fetchone()
                check(window['received_count']==(100 if constraint=='files' else 1) and window['received_bytes']==(1024**3 if constraint=='bytes' else len(payload)),constraint+' final window accounting matches exactly the winning file')
            route=open_window()
            for name in ['文件'*39+'.txt','name.'+'x'*100]:
                status,_,body=upload(route,name,b'long name data');receipt=json.loads(body)
                check(status==201 and len(receipt['name'].encode())<=240 and (tmp/'files'/receipt['name']).read_bytes()==b'long name data','long UTF-8 or extension input safely preserves bytes under a valid inbox name')
            check(upload(route,'文件'*65+'.txt')[0]==400,'filenames beyond the existing 240-byte bound remain rejected')
            check((tmp/'files'/received_name).is_file(),'closing and reopening windows never removes already received files')
            check(all((tmp/'files'/n).read_bytes()==b'0123456789' for n in names),'all pre-existing entity bytes remain unchanged')
            check(db.execute('PRAGMA integrity_check').fetchone()[0]=='ok','database remains intact after all permission and failure checks')
            print(f'{CHECKS} guest visibility/upload checks passed; 0 failed')
        finally:
            with contextlib.suppress(ProcessLookupError):os.killpg(server.pid,signal.SIGTERM)
            server.wait(timeout=5);db.close();log.close()

if __name__=='__main__':main()
