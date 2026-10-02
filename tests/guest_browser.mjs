/** Real browser and curl tests on isolated fixtures; no production accounts or files. */
import { chromium } from 'playwright';
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import net from 'node:net';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
const root=process.cwd(),php=process.env.PHP_BINARY||'php';
const tmp=await fs.mkdtemp(path.join(os.tmpdir(),'share-browser-guest-'));
await fs.mkdir(path.join(tmp,'files'));await fs.mkdir(path.join(tmp,'storage'));
const output=path.join(root,'artifacts/browser/guest');await fs.mkdir(output,{recursive:true});
const port=await new Promise(resolve=>{const server=net.createServer();server.listen(0,'127.0.0.1',()=>{const p=server.address().port;server.close(()=>resolve(p));});});
const base=`http://127.0.0.1:${port}`,password=randomBytes(24).toString('hex');
const env={...process.env,SHARE_FILES_DIR:path.join(tmp,'files'),SHARE_STORAGE_DIR:path.join(tmp,'storage'),SHARE_BASE_URL:base,SHARE_ALLOW_HTTP:'1',SHARE_FIXTURE_PASSWORD:password,PHP_CLI_SERVER_WORKERS:'4'};
const fixture=spawnSync(php,['tests/browser-fixture.php'],{cwd:root,env,encoding:'utf8'});assert.equal(fixture.status,0,fixture.stderr);
const originals=JSON.parse(fixture.stdout).files;
const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t','public','scripts/dev-router.php'],{cwd:root,env,detached:true,stdio:['ignore','pipe','pipe']});
let serverLog='';server.stdout.on('data',c=>serverLog+=c);server.stderr.on('data',c=>serverLog+=c);
let browser;let passed=0;const errors=[];
function check(value,label){assert.ok(value,label);passed++;console.log('PASS',label);}
async function bytes(download){const chunks=[];for await(const chunk of await download.createReadStream())chunks.push(chunk);return Buffer.concat(chunks);}
try{
 for(let i=0;i<100;i++){try{if((await fetch(base+'/')).ok)break;}catch{}await new Promise(r=>setTimeout(r,50));}
 browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE_PATH});
 const admin=await browser.newContext({viewport:{width:1440,height:1000},locale:'zh-CN',acceptDownloads:true});
 const page=await admin.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/admin/login');await page.locator('[name=username]').fill('browser-test');await page.locator('[name=password]').fill(password);await page.getByRole('button',{name:'登录工作空间'}).click();await page.waitForURL(base+'/admin');
 await page.goto(base+'/admin/files');const target=originals.find(f=>f.name==='产品设计说明.txt');
 await page.getByRole('switch',{name:'在首页显示 '+target.name,exact:true}).click();await page.waitForLoadState();
 check(await page.getByRole('switch',{name:'在首页显示 '+target.name,exact:true}).getAttribute('aria-checked')==='false','file-row visibility switch turns off through the real form');
 const visitor=await browser.newContext({viewport:{width:1440,height:1000},locale:'zh-CN',acceptDownloads:true});const guest=await visitor.newPage();guest.on('pageerror',e=>errors.push(e.message));
 await guest.goto(base+'/');check(await guest.locator(`[data-public-file="${target.public_id}"]`).count()===0,'public visitor sees the switched-off file disappear');
 await page.getByRole('switch',{name:'在首页显示 '+target.name,exact:true}).click();await page.waitForLoadState();await guest.reload();check(await guest.locator(`[data-public-file="${target.public_id}"]`).count()===1,'explicitly switching on restores the homepage row');
 await page.goto(base+'/admin/receive');check(await page.getByRole('heading',{name:'收件窗口已关闭'}).isVisible(),'inbox starts with uploads closed');
 await page.locator('[name=minutes]').fill('10');await page.getByRole('button',{name:'开启访客上传',exact:true}).click();await page.waitForLoadState();
 const link=await page.locator('#receive-link').inputValue();check(link.startsWith(base+'/upload/'),'administrator receives a canonical-origin upload link');
 await page.screenshot({path:path.join(output,'desktop-inbox.png'),fullPage:true});
 await guest.goto(link);check(await guest.getByRole('heading',{name:'交付文件',exact:true}).isVisible(),'human guest upload page loads without login');
 check((await guest.locator('[data-window-countdown]').innerText()).includes('剩余'),'guest sees remaining window time');
 check((await guest.locator('pre').innerText()).includes(link) && (await guest.locator('pre').innerText()).includes('Accept: application/json'),'Agent command uses the same timed domain link and requests JSON');
 const payload=Buffer.from('Private browser guest delivery / 中文');
 await guest.locator('[data-guest-file]').setInputFiles({name:'human-delivery.txt',mimeType:'text/plain',buffer:payload});
 const pending=guest.waitForResponse(r=>r.url()===link && r.request().method()==='POST');await guest.getByRole('button',{name:'上传给接收方'}).click();const response=await pending;const receipt=await response.json();
 await guest.locator('[data-guest-receipts] p').waitFor();check(response.status()===201 && receipt.ok && (await guest.locator('[data-guest-receipts]').innerText()).includes(receipt.name),'human browser upload receives a confirmed saved receipt');
 check(!('url' in receipt) && !('download_url' in receipt),'guest receipt supplies no public download capability');
 await guest.screenshot({path:path.join(output,'desktop-guest.png'),fullPage:true});
 await page.reload();const row=page.locator('tr').filter({has:page.getByRole('link',{name:receipt.name,exact:true})});check(await row.isVisible(),'received file appears in administrator inbox');
 const download=page.waitForEvent('download');await row.getByRole('button',{name:'后台下载',exact:true}).click();check((await bytes(await download)).equals(payload),'administrator downloads exact guest-upload bytes');
 const publicHome=await visitor.request.get(base+'/');check(!(await publicHome.text()).includes(receipt.name),'received file is absent from public homepage');
 const denied=await visitor.request.get(base+'/d/'+encodeURIComponent(receipt.name),{maxRedirects:0});check(denied.status()===409,'received file rejects unauthenticated public download');
 const agentFile=path.join(tmp,'agent-delivery.txt');await fs.writeFile(agentFile,'actual curl upload');
 const curl=spawnSync('curl',['--silent','--show-error','--fail-with-body','-H','Accept: application/json','-F',`file=@${agentFile}`,link],{encoding:'utf8'});check(curl.status===0 && JSON.parse(curl.stdout).ok===true,'documented curl multipart upload works with no cookies or login');
 const mobile=await browser.newContext({viewport:{width:390,height:844},locale:'zh-CN'});const phone=await mobile.newPage();phone.on('pageerror',e=>errors.push(e.message));await phone.goto(link);
 check(await phone.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'guest form and Agent command fit mobile width');await phone.screenshot({path:path.join(output,'mobile-guest.png'),fullPage:true});
 await page.reload();await page.getByRole('button',{name:'立即关闭',exact:true}).click();await page.waitForLoadState();check(await page.getByRole('heading',{name:'收件窗口已关闭'}).isVisible(),'administrator can close the active window manually');
 await guest.locator('[data-window-label]').filter({hasText:'收件窗口已关闭'}).waitFor({timeout:10000});check(await guest.getByRole('button',{name:'上传给接收方'}).isDisabled(),'already-open guest page notices closure and disables new uploads');
 const closed=spawnSync('curl',['--silent','--show-error','-H','Accept: application/json','-F',`file=@${agentFile}`,'-w','\n%{http_code}',link],{encoding:'utf8'});check(closed.stdout.trim().endsWith('410') && JSON.parse(closed.stdout.slice(0,closed.stdout.lastIndexOf('\n'))).code==='upload_closed','Agent receives 410 JSON after manual closure');
 await page.locator('[name=minutes]').fill('1');await page.getByRole('button',{name:'开启访客上传',exact:true}).click();await page.waitForLoadState();const replacement=await page.locator('#receive-link').inputValue();check(replacement!==link,'reopening rotates the capability link');
 check((await visitor.request.get(link)).status()===410,'previous link remains revoked after reopening');
 await phone.goto(replacement);const expire=spawnSync(php,['-r',"require 'src/bootstrap.php';share_store()->db->run('UPDATE guest_upload_window SET expires_at=?',[time()-1]);"],{cwd:root,env,encoding:'utf8'});assert.equal(expire.status,0,expire.stderr);
 await phone.locator('[data-window-label]').filter({hasText:'收件窗口已关闭'}).waitFor({timeout:10000});check(await phone.getByRole('button',{name:'上传给接收方'}).isDisabled(),'server expiry closes an already-open mobile upload page');
 await phone.reload();check(await phone.getByRole('heading',{name:'这次收件已结束'}).isVisible() && await phone.locator('[data-guest-upload-form]').count()===0,'expired mobile link renders a closed page with no file controls');await phone.screenshot({path:path.join(output,'mobile-closed.png'),fullPage:true});
 await page.reload();check(await page.getByRole('link',{name:receipt.name,exact:true}).isVisible(),'already-received files remain in inbox after expiry');
 check(errors.length===0,'new admin and guest pages have no uncaught JavaScript errors');
 await fs.writeFile(path.join(output,'results.json'),JSON.stringify({passed,failed:0,errors},null,2));console.log(`${passed} guest browser/curl checks passed; 0 failed`);
}finally{if(browser)await browser.close();try{process.kill(-server.pid,'SIGTERM');}catch{}await new Promise(r=>server.once('exit',r));await fs.rm(tmp,{recursive:true,force:true});}
