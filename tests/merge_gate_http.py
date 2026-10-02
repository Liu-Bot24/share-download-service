"""Extra merge gate checks against a fresh synthetic, externally launched fixture.
SHARE_GATE_META must point to the isolated fixture connection JSON.
"""
import os,json,pathlib,sys,uuid,urllib.parse
from http_test import Client, Inputs
meta=json.loads(pathlib.Path(os.environ['SHARE_GATE_META']).read_text())
assert 'share-browser-gate-' in meta['private'], 'synthetic fixture required'
base=meta['base']; anonymous=Client(base); admin=Client(base)
passed=0;failed=0
def check(condition,label):
    global passed,failed
    if condition:passed+=1;print('PASS',label)
    else:failed+=1;print('FAIL',label)
file_id=meta['files'][0]['id']
for path in ['/admin/logout','/admin/upload','/admin/scan','/admin/settings']+[f'/admin/files/{file_id}/{suffix}' for suffix in ['policy','download-ticket','trash','restore']]:
    check(anonymous.request(path,'POST',{'csrf':'forged'})[0]==403,'anonymous modification denied: '+path)
for path in ['/admin','/admin/files','/admin/downloads','/admin/downloads/export','/admin/analytics','/admin/settings',f'/admin/files/{file_id}']:
    check(anonymous.request(path)[0]==302,'anonymous private read redirected: '+path)
for path in ['/d/..%2Fstorage%2Fmanager.json','/d/.env','/d/folder%5Cfile','/d/%00','/admin/files?q[]=array']:
    check(anonymous.request(path)[0] in (400,404),'malformed/traversal input rejected: '+path)
status,_,body=admin.request('/admin/login');token=Inputs(body.decode()).values['csrf']
check(admin.request('/admin/login','POST',{'csrf':token,'username':meta['username'],'password':meta['password']})[0]==303,'existing-style stored hash login')
status,_,body=admin.request('/admin/files');token=Inputs(body.decode()).values['csrf']
for suffix in ['policy','download-ticket','trash','restore']:
    check(admin.request(f'/admin/files/{file_id}/{suffix}','POST',{'csrf':'bad'})[0]==403,'authenticated invalid CSRF denied: '+suffix)
check(admin.request('/admin/settings','POST',{'csrf':token,'timezone':'Invalid/Zone','session_ttl':3600})[0]==400,'invalid timezone rejected')
check(admin.request('/admin/settings','POST',{'csrf':token,'timezone':'UTC','session_ttl':0})[0]==400,'invalid lifetime rejected')
def upload(name,contents):
    boundary='MergeGate'+uuid.uuid4().hex
    body=(f'--{boundary}\r\nContent-Disposition: form-data; name="csrf"\r\n\r\n{token}\r\n--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+contents+f'\r\n--{boundary}--\r\n'.encode()
    return admin.request('/admin/upload','POST',body,{'Content-Type':f'multipart/form-data; boundary={boundary}'})
prefix='merge-gate-http-'+uuid.uuid4().hex[:8]
for suffix,payload in [('.php',b'<?php echo "not executable";?>'),('.png',b'\x89PNG\r\n\x1a\nfixture'),('-中文.txt','中文内容'.encode())]:
    name=prefix+suffix
    check(upload(name,payload)[0]==303,'upload extension: '+suffix)
    st,headers,_=anonymous.request('/d/'+urllib.parse.quote(name))
    response=anonymous.request(headers['Location']) if st==302 else (st,headers,b'')
    check(response[0]==200 and response[2]==payload and response[1].get('Content-Disposition','').startswith('attachment;'),'attachment exact bytes: '+suffix)
    check(upload(name,b'replacement')[0]==409,'duplicate upload rejects overwrite: '+suffix)
check(upload('.hidden',b'fixture')[0]==400,'hidden file upload denied')
check(upload(prefix+'-oversize.bin',b'0'*(45*1024*1024+1))[0]==400,'45MiB+1 rejected by server')
status,headers,body=anonymous.request('/')
check(status==200 and b'<table' in body,'legacy public homepage file list remains accessible')
print(f'{passed} passed, {failed} failed')
sys.exit(1 if failed else 0)
