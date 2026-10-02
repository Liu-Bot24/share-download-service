#!/usr/bin/env python3
"""Isolated HTTP integration checks. No production directories or credentials are used."""
import concurrent.futures
import contextlib
import http.cookiejar
import hashlib
import threading
import json
import os
import pathlib
import re
import secrets
import signal
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')

class Inputs(HTMLParser):
    def __init__(self, html):
        super().__init__(); self.values = {}; self.feed(html)
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('name'):
            self.values[attrs['name']] = attrs.get('value', '')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
    def request(self, path, method='GET', data=None, headers=None):
        headers = dict(headers or {})
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data).encode(); headers['Content-Type'] = 'application/x-www-form-urlencoded'
        request = urllib.request.Request(self.base + path, data=data, headers=headers, method=method)
        try: response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error: response = error
        return response.status, response.headers, response.read()

def main():
    checks = 0
    def check(condition, label):
        nonlocal checks
        checks += 1
        if not condition: raise AssertionError(label)
        print('PASS', label)
    with tempfile.TemporaryDirectory(prefix='share-http-') as tmp:
        tmp = pathlib.Path(tmp); (tmp/'files').mkdir(); (tmp/'storage').mkdir()
        for name in ['public 中文.txt', 'protected.txt', 'quota.txt', 'throttle.txt']:
            (tmp/'files'/name).write_bytes(b'0123456789')
        (tmp/'files'/'<img src=x onerror=alert(1)>.txt').write_bytes(b'escaped')
        with contextlib.closing(socket.socket()) as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'; password = secrets.token_urlsafe(24)
        env = {**os.environ, 'SHARE_FILES_DIR': str(tmp/'files'), 'SHARE_STORAGE_DIR': str(tmp/'storage'), 'SHARE_BASE_URL': base, 'SHARE_ALLOW_HTTP': '1', 'FIXTURE_PASS': password, 'PHP_CLI_SERVER_WORKERS': '4'}
        fixture = r'''require 'src/bootstrap.php'; $s=share_store(); file_put_contents($s->storageDir.'/manager.json',json_encode(['username'=>'tester','password_hash'=>password_hash(getenv('FIXTURE_PASS'),PASSWORD_DEFAULT)])); $f=$s->resolve('protected.txt');$s->policy($f['id'],['policy_version'=>$f['policy_version'],'password_action'=>'set','password'=>'file-password'],'fixture');$f=$s->resolve('quota.txt');$s->policy($f['id'],['policy_version'=>$f['policy_version'],'quota_mode'=>'remaining','quota_amount'=>1],'fixture');'''
        fixture += "foreach($s->files() as $listed){$s->setHomepageVisibility($listed['id'],true,'fixture');}"
        subprocess.run([PHP, '-r', fixture], cwd=ROOT, env=env, check=True)
        log = open(tmp/'server.log', 'w+')
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', 'public', 'scripts/dev-router.php'], cwd=ROOT, env=env, stdout=log, stderr=log, start_new_session=True)
        db = sqlite3.connect(tmp/'storage'/'share.sqlite'); db.row_factory = sqlite3.Row
        def row(name): return dict(db.execute('SELECT * FROM files WHERE name=? ORDER BY id DESC', (name,)).fetchone())
        def count(name): return row(name)['public_count']
        try:
            client = Client(base)
            for _ in range(100):
                try:
                    if client.request('/admin/login')[0] == 200: break
                except OSError: pass
                time.sleep(.05)
            else:
                log.seek(0); raise RuntimeError('Server did not start: '+log.read())
            status, _, homepage = client.request('/')
            check(status == 200 and 'public 中文.txt'.encode() in homepage and b'protected.txt' in homepage, 'anonymous homepage preserves the public visitor catalog')
            check(b'password_hash' not in homepage and b'private.example' not in homepage and b'data-upload-form' not in homepage, 'visitor catalog excludes credentials and private administrator controls')
            check(client.request('/', method='HEAD')[0] == 200 and client.request('/', method='HEAD')[2] == b'', 'public catalog HEAD has no body')
            check(client.request('/', method='POST')[0] == 405, 'public catalog is read-only')
            check(client.request('/admin/downloads')[0] == 302, 'private history requires authentication')
            check(client.request('/admin/files', headers={'Host':'attacker.test'})[0] == 400, 'unrecognized Host rejected')
            for path in ['/files/public%20%E4%B8%AD%E6%96%87.txt','/storage/manager.json','/src/ShareStore.php','/.git/config']:
                check(client.request(path)[0] == 404, 'private path denied: '+path)
            public = '/d/'+urllib.parse.quote('public 中文.txt')
            check(client.request(public, method='HEAD')[0] == 200 and count('public 中文.txt') == 0, 'HEAD no claim or count')
            check(client.request(public, method='POST')[0] == 405 and count('public 中文.txt') == 0, 'unsupported method no claim')
            status, headers, _ = client.request(public, headers={'Referer':'https://example.org/from?secret=redacted#frag'})
            check(status == 302 and headers['Location'].startswith('/transfer/'), 'old Unicode name transparently redirects')
            token = headers['Location']; etag = client.request(token, method='HEAD')[1]['ETag']
            check(client.request(token, headers={'If-None-Match':etag})[0] == 304 and count('public 中文.txt') == 0, 'conditional 304 does not claim')
            check(client.request(token, headers={'Range':'bytes=90-100'})[0] == 416 and count('public 中文.txt') == 0, 'invalid range does not claim')
            status, headers, body = client.request(token, headers={'Range':'bytes=0-3','X-Forwarded-For':'8.8.8.8'})
            check(status == 206 and body == b'0123' and headers['Content-Range'] == 'bytes 0-3/10', 'first valid partial transfer')
            status, _, body = client.request(token, headers={'Range':'bytes=4-'})
            check(status == 206 and body == b'456789' and count('public 中文.txt') == 1, 'resumed range uses same count')
            event = dict(db.execute('SELECT * FROM events WHERE file_id=?',(row('public 中文.txt')['id'],)).fetchone())
            check(event['ip'] == '127.0.0.1' and event['referrer'] == 'example.org/from', 'actual peer IP and sanitized original source retained')
            check(event['request_count'] == 2 and event['observed_bytes'] == 10, 'request diagnostics sum partial bytes')
            homepage = client.request('/')[2]
            check(b'example.org/from' not in homepage and b'127.0.0.1' not in homepage.replace(base.encode(), b''), 'public homepage never includes visitor IP or source history')
            check('no-store' in headers['Cache-Control'] and headers['Referrer-Policy'] == 'no-referrer', 'download caching and token referrer restrictions')
            # curl follows the direct entrance and resumes its captured final URL.
            partial = tmp/'curl-resume.bin'
            final_url = subprocess.check_output(['curl','--fail','--silent','--show-error','-L','--range','0-3','-o',str(partial),'-w','%{url_effective}',base+public],text=True)
            check(partial.read_bytes() == b'0123' and count('public 中文.txt') == 2, 'curl -L follows legacy direct link')
            subprocess.run(['curl','--fail','--silent','--show-error','-C','-','-o',str(partial),final_url],check=True)
            check(partial.read_bytes() == b'0123456789' and count('public 中文.txt') == 2, 'curl resumes final URL without consuming another slot')
            # Protected flow, including retry and original source retention.
            status, _, body = client.request('/d/protected.txt', headers={'Referer':'https://origin.example/article?token=secret'})
            inputs = Inputs(body.decode()).values
            check(status == 200 and 'flow' in inputs and count('protected.txt') == 0, 'password interstitial does not count')
            status, _, body = client.request('/d/protected.txt/unlock', 'POST', {**inputs,'password':'wrong'})
            check(status == 401 and count('protected.txt') == 0, 'wrong password rejected without count')
            inputs = Inputs(body.decode()).values
            status, headers, _ = client.request('/d/protected.txt/unlock', 'POST', {**inputs,'password':'file-password'})
            check(status == 303 and 'password' not in headers['Location'], 'password verification redirects without exposing password')
            check(client.request(headers['Location'])[2] == b'0123456789' and count('protected.txt') == 1, 'protected entity downloads')
            ref = db.execute('SELECT referrer FROM events WHERE file_id=?',(row('protected.txt')['id'],)).fetchone()[0]
            check(ref == 'origin.example/article', 'password retries retain entrance source')
            # Independent redirected candidates compete for one available slot.
            candidates = [client.request('/d/quota.txt')[1]['Location'] for _ in range(20)]
            with concurrent.futures.ThreadPoolExecutor(max_workers=20) as pool:
                statuses = list(pool.map(lambda path: Client(base).request(path)[0], candidates))
            check(statuses.count(200) == 1 and count('quota.txt') == 1, '20 concurrent HTTP candidates claim exactly one quota slot')
            check(client.request('/d/quota.txt')[0] == 409, 'exhausted entrance rejects new sessions')
            # Manager login and existing upload/trash capabilities.
            status, _, body = client.request('/admin/login'); csrf = Inputs(body.decode()).values['csrf']
            check(client.request('/admin/login','POST',{'csrf':'forged','username':'tester','password':password})[0] == 403, 'login CSRF required')
            check(client.request('/admin/login','POST',{'csrf':csrf,'username':'tester','password':password})[0] == 303, 'existing manager hash login succeeds')
            status, _, body = client.request('/admin/files'); csrf = Inputs(body.decode()).values['csrf']
            check(status == 200 and b'&lt;img' in body and b'<img src=x' not in body, 'filenames escaped in manager UI')
            before = [dict(r) for r in db.execute('SELECT id,public_count,last_public_at FROM files')]
            status, headers, _ = client.request(f"/admin/files/{row('quota.txt')['id']}/download-ticket",'POST',{'csrf':csrf})
            check(status == 303 and client.request(headers['Location'])[2] == b'0123456789', 'administrator downloads exhausted entity')
            admin_token = headers['Location']
            after = [dict(r) for r in db.execute('SELECT id,public_count,last_public_at FROM files')]
            check(before == after, 'admin download does not change any public counter or time')
            check(client.request('/admin/settings','POST',{'csrf':'bad','timezone':'UTC','session_ttl':86400})[0] == 403, 'settings CSRF required')
            check(client.request('/admin/settings','POST',{'csrf':csrf,'timezone':'UTC','session_ttl':3600})[0] == 303, 'timezone and resume settings save')
            boundary = 'ShareTest'+secrets.token_hex(6)
            data = (f'--{boundary}\r\nContent-Disposition: form-data; name="csrf"\r\n\r\n{csrf}\r\n--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="upload.txt"\r\nContent-Type: text/plain\r\n\r\nfixture upload\r\n--{boundary}--\r\n').encode()
            status, _, _ = client.request('/admin/upload','POST',data,{'Content-Type':f'multipart/form-data; boundary={boundary}'})
            check(status == 303 and (tmp/'files'/'upload.txt').read_bytes() == b'fixture upload', 'authenticated multipart upload remains supported')
            f=row('upload.txt')
            check(client.request(f"/admin/files/{f['id']}/trash",'POST',{'csrf':csrf,'confirmation':'wrong'})[0] == 400, 'trash exact filename confirmation enforced')
            check(client.request(f"/admin/files/{f['id']}/trash",'POST',{'csrf':csrf,'confirmation':f['name']})[0] == 303 and row('upload.txt')['state'] == 'trashed', 'recoverable trash retains record')
            check(client.request(f"/admin/files/{f['id']}/restore",'POST',{'csrf':csrf})[0] == 303 and row('upload.txt')['state'] == 'paused', 'restore is deliberately paused')
            # Two actual multipart HTTP uploads compete for one filename.
            def upload_body(name, content):
                return (f'--{boundary}\r\nContent-Disposition: form-data; name="csrf"\r\n\r\n{csrf}\r\n--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + content + f'\r\n--{boundary}--\r\n'.encode())
            gate = threading.Barrier(2)
            def race_upload(content):
                gate.wait(timeout=5)
                return client.request('/admin/upload', 'POST', upload_body('race-upload.txt', content), {'Content-Type':f'multipart/form-data; boundary={boundary}'})[0]
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                upload_statuses = list(pool.map(race_upload, [b'first payload', b'second payload']))
            check(sorted(upload_statuses) == [303, 409], 'simultaneous multipart uploads publish exactly one same-name entity')
            winner = row('race-upload.txt'); winner_bytes = (tmp/'files'/'race-upload.txt').read_bytes()
            check(winner_bytes in [b'first payload', b'second payload'] and winner['sha256'] == hashlib.sha256(winner_bytes).hexdigest() and db.execute("SELECT COUNT(*) FROM files WHERE name='race-upload.txt'").fetchone()[0] == 1, 'winning upload has one record and a matching hash')
            check(not list((tmp/'files').glob('.upload-*')) and db.execute('SELECT COUNT(*) FROM file_mutations').fetchone()[0] == 0, 'concurrent upload leaves no staged entity or pending journal')
            # The exact reported restore conflict, through authenticated upload/scan/restore routes.
            name = 'restore-collision.txt'
            check(client.request('/admin/upload', 'POST', upload_body(name, b'AAAA'), {'Content-Type':f'multipart/form-data; boundary={boundary}'})[0] == 303, 'recovery conflict fixture A uploads')
            first = row(name)
            check(client.request(f"/admin/files/{first['id']}/trash", 'POST', {'csrf':csrf, 'confirmation':name})[0] == 303, 'recovery conflict fixture A moves to trash')
            packet = dict(db.execute('SELECT * FROM files WHERE id=?', (first['id'],)).fetchone())
            source = tmp/'storage'/'trash'/packet['trash_key']/'file'
            check(client.request('/admin/upload', 'POST', upload_body(name, b'BBBB'), {'Content-Type':f'multipart/form-data; boundary={boundary}'})[0] == 303, 'same-name fixture B uploads as a distinct record')
            second = row(name); (tmp/'files'/name).unlink()
            check(client.request('/admin/scan', 'POST', {'csrf':csrf})[0] == 303 and row(name)['state'] == 'missing', 'scan marks physically absent B as missing')
            response = client.request(f"/admin/files/{first['id']}/restore", 'POST', {'csrf':csrf})
            check(response[0] == 409 and source.read_bytes() == b'AAAA' and not (tmp/'files'/name).exists(), 'restore refuses missing-record name ownership before moving A')
            check(client.request(f"/admin/files/{first['id']}/restore", 'POST', {'csrf':csrf})[0] == 409 and source.read_bytes() == b'AAAA', 'repeated conflict remains safely recoverable')
            status, headers, body=client.request('/admin/downloads/export')
            check(status == 200 and headers['Content-Type'].startswith('text/csv') and 'origin.example/article' in body.decode('utf-8-sig'), 'authenticated CSV exports same session source')
            throttled = Client(base)
            candidates = [throttled.request('/d/throttle.txt') for _ in range(40)]
            check(all(response[0] == 302 for response in candidates), 'documented candidate creation allowance is usable')
            attempts_before = db.execute('SELECT COUNT(*) FROM attempts').fetchone()[0]
            status, headers, _ = throttled.request('/d/throttle.txt')
            check(status == 429 and headers.get('Retry-After') == '60' and count('throttle.txt') == 0, 'excess candidate creation returns retry guidance without consuming quota')
            check(db.execute('SELECT COUNT(*) FROM attempts').fetchone()[0] == attempts_before, 'allocation throttling does not amplify per-request database diagnostics')
            token = candidates[0][1]['Location']
            check(throttled.request(token, headers={'Range':'bytes=0-3'})[0] == 206 and throttled.request(token, headers={'Range':'bytes=4-'})[0] == 206 and count('throttle.txt') == 1, 'valid range session still starts and resumes while new allocation is throttled')
            check(client.request('/admin/logout','POST',{'csrf':csrf})[0] == 303, 'manager logout succeeds')
            check(client.request(admin_token)[0] in (403,410), 'logout invalidates administrator ticket')
            print(f'{checks} HTTP checks passed')
        except Exception:
            log.flush(); log.seek(0); print(log.read()); raise
        finally:
            db.close(); os.killpg(server.pid, signal.SIGTERM)
            with contextlib.suppress(subprocess.TimeoutExpired): server.wait(timeout=3)
            log.close()
if __name__ == '__main__': main()
