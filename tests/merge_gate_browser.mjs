/** Additional merge-gate checks against a freshly provisioned synthetic fixture.
 * SHARE_GATE_META points to a local fixture connection JSON (never production).
 */
import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
const meta=JSON.parse(await fs.readFile(process.env.SHARE_GATE_META,'utf8'));
assert.ok(meta.private.includes('share-browser-gate-'),'isolated fixture required');
const base=meta.base;
const output=path.resolve('artifacts/gate/browser-additional');
await fs.mkdir(output,{recursive:true});
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE_PATH});
let passed=0, failed=0;
const errors=[];
function check(ok,label){if(!ok){failed++;throw new Error(label);}passed++;console.log('PASS '+label);}
try {
  for(const [label,width,height] of [['desktop',1440,1000],['mobile',390,844]]){
    const context=await browser.newContext({viewport:{width,height},locale:'zh-CN',acceptDownloads:true});
    const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
    await page.goto(base+'/admin/files');
    check(page.url().endsWith('/admin/login'),label+' anonymous manager page requires login');
    await page.locator('[name=username]').fill(meta.username);
    await page.locator('[name=password]').fill('deliberately-wrong-fixture-password');
    await page.getByRole('button',{name:'登录工作空间'}).click();
    check(await page.getByRole('alert').isVisible(),label+' incorrect login displays error');
    await page.locator('[name=password]').fill(meta.password);
    await page.getByRole('button',{name:'登录工作空间'}).click();
    await page.waitForURL(base+'/admin');
    check(await page.getByRole('heading',{name:'概览',exact:true}).isVisible(),label+' login loads overview');
    const name='merge-gate-'+label+'-'+randomBytes(5).toString('hex')+'-中文.txt';
    const bytes=Buffer.from('Isolated browser upload / '+label+' / 中文');
    await page.goto(base+'/admin/files');
    await page.locator('[data-open-upload]').first().click();
    await page.locator('[data-file-input]').setInputFiles({name,mimeType:'text/plain',buffer:bytes});
    await page.locator('#upload-dialog').getByRole('button',{name:'上传文件',exact:true}).click();
    await page.waitForURL(base+'/admin/files');
    const fileLink=page.getByRole('link',{name,exact:true});
    await fileLink.waitFor();
    check(await fileLink.isVisible(),label+' browser multipart upload appears in file list');
    const detail=await fileLink.getAttribute('href');
    await page.locator('[data-open-upload]').first().click();
    await page.locator('[data-file-input]').setInputFiles({name,mimeType:'text/plain',buffer:Buffer.from('replacement must be rejected')});
    await page.locator('#upload-dialog').getByRole('button',{name:'上传文件',exact:true}).click();
    await page.waitForURL(base+'/admin/upload');
    check((await page.locator('.error-description').innerText()).includes('同名'),label+' duplicate upload reports conflict');
    await page.goto(base+detail);
    const downloadPromise=page.waitForEvent('download');
    await page.locator('[data-download-form] button').click();
    const download=await downloadPromise;
    const saved=path.join(output,label+'-download.txt');await download.saveAs(saved);
    check((await fs.readFile(saved)).equals(bytes),label+' original bytes survive duplicate upload and admin download');
    const entry=await page.locator('.link-box p').innerText();
    await page.locator('.danger-zone summary').click();
    await page.locator('[name=confirmation]').fill('wrong-name');
    await page.getByRole('button',{name:'确认移入回收目录'}).click();
    check(page.url()===base+detail,label+' wrong deletion confirmation does not delete');
    await page.locator('[name=confirmation]').fill(name);
    await page.getByRole('button',{name:'确认移入回收目录'}).click();
    await page.getByRole('button',{name:'恢复文件',exact:true}).waitFor();
    check(await page.getByRole('button',{name:'恢复文件',exact:true}).isVisible(),label+' browser deletion shows recoverable state');
    check((await context.request.get(entry,{maxRedirects:0})).status()===410,label+' deleted public link is unavailable');
    await page.getByRole('button',{name:'恢复文件',exact:true}).click();
    await page.locator('.badge-paused').waitFor();
    check(await page.locator('.badge-paused').isVisible(),label+' browser restore remains paused');
    await page.goto(base+detail+'?tab=sharing');
    await page.locator('[name=state][value=active]').check();
    await page.getByRole('button',{name:'保存分享设置'}).click();
    await page.waitForURL(base+detail+'?tab=sharing');
    check(await page.locator('.badge-active').isVisible(),label+' restored original share can be reopened');
    const visitor=await browser.newContext({viewport:{width,height},locale:'zh-CN',acceptDownloads:true});
    const publicPage=await visitor.newPage();
    const publicDownload=publicPage.waitForEvent('download');
    await publicPage.goto(entry).catch(e=>{
      if(!e.message.includes('Download is starting')&&!e.message.includes('net::ERR_ABORTED'))throw e;
    });
    const d=await publicDownload;await d.saveAs(path.join(output,label+'-public.txt'));
    check((await fs.readFile(path.join(output,label+'-public.txt'))).equals(bytes),label+' unauthenticated original-style entry downloads restored bytes');
    await visitor.close();
    await page.goto(base+detail);
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+' final restored detail has no horizontal overflow');
    await page.screenshot({path:path.join(output,label+'-restored.png'),fullPage:true});
    await page.goto(base+detail+'?tab=sharing');
    await page.locator('[name=password_action]').selectOption('set');
    await page.locator('[name=password]').fill('browser-gate-password');
    await page.locator('[name=quota_mode]').selectOption('remaining');
    await page.locator('[name=quota_amount]').fill('1');
    await page.getByRole('button',{name:'保存分享设置'}).click();
    await page.waitForURL(base+detail+'?tab=sharing');
    check((await page.locator('.small-badge').innerText()).includes('已设置'),label+' browser saves new password and quota');
    await context.close();
  }
  check(errors.length===0,'no uncaught browser JavaScript errors');
} catch(e){failed=Math.max(1,failed);console.error(e.stack);}
finally{await browser.close();await fs.writeFile(path.join(output,'results.json'),JSON.stringify({passed,failed,errors},null,2));}
console.log(`${passed} passed, ${failed} failed`);if(failed)process.exitCode=1;
