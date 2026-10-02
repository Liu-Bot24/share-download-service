/** Real browser regression suite. Requires npm install --no-save playwright@1.55.1
 * and npx playwright install chromium. Starts only synthetic local fixtures.
 * Optional PHP_BINARY and CHROMIUM_EXECUTABLE_PATH support developer toolchains.
 */
import assert from "node:assert/strict";
import { spawn, spawnSync } from "node:child_process";
import fs from "node:fs/promises";
import net from "node:net";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";
import { randomBytes } from "node:crypto";
const require = createRequire(import.meta.url);
const { chromium } = require("playwright");
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const tmp = await fs.mkdtemp(path.join(os.tmpdir(), "share-browser-"));
const artifacts = path.join(root, "artifacts", "browser");
await fs.mkdir(artifacts, { recursive: true });
await fs.mkdir(path.join(tmp, "files"));
await fs.mkdir(path.join(tmp, "storage"));
const port = await new Promise((resolve, reject) => {
    const s = net.createServer();
    s.on("error", reject);
    s.listen(0, "127.0.0.1", () => {
        const p = s.address().port;
        s.close(() => resolve(p));
    });
});
const base = `http://127.0.0.1:${port}`;
const password = randomBytes(24).toString("base64url");
const env = {
    ...process.env,
    SHARE_BASE_URL: base,
    SHARE_ALLOW_HTTP: "1",
    SHARE_FILES_DIR: path.join(tmp, "files"),
    SHARE_STORAGE_DIR: path.join(tmp, "storage"),
    SHARE_FIXTURE_PASSWORD: password,
    PHP_CLI_SERVER_WORKERS: "4",
};
const php = process.env.PHP_BINARY || "php";
const fixture = spawnSync(php, ["tests/browser-fixture.php"], {
    cwd: root,
    env,
    encoding: "utf8",
});
assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
const files = JSON.parse(fixture.stdout).files;
const editable = files.find((f) => f.name === "产品设计说明.txt");
const protectedFile = files.find((f) => f.name === "品牌素材.zip");
const server = spawn(php, ["-S", `127.0.0.1:${port}`, "-t", "public", "scripts/dev-router.php"], {
    cwd: root,
    env,
    detached: true,
    stdio: ["ignore", "pipe", "pipe"],
});
let logs = "";
server.stdout.on("data", (d) => (logs += d));
server.stderr.on("data", (d) => (logs += d));
let browser;
const results = [];
function pass(name) {
    results.push(name);
    console.log("PASS", name);
}
try {
    let ready = false;
    for (let i = 0; i < 100; i++) {
        try {
            const r = await fetch(base + "/admin/login");
            if (r.status === 200) {
                ready = true;
                break;
            }
        } catch {}
        await new Promise((r) => setTimeout(r, 50));
    }
    assert.ok(ready, "local PHP server ready");
    browser = await chromium.launch({
        headless: true,
        ...(process.env.CHROMIUM_EXECUTABLE_PATH
            ? { executablePath: process.env.CHROMIUM_EXECUTABLE_PATH }
            : {}),
    });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
        locale: "zh-CN",
        reducedMotion: "reduce",
    });
    const page = await context.newPage();
    const jsErrors = [];
    page.on("pageerror", (e) => jsErrors.push(e.message));
    await page.goto(base + "/admin/login");
    await page.locator("input[name=username]").fill("browser-test");
    await page.locator("input[name=password]").fill(password);
    await page.getByRole("button", { name: "登录工作空间" }).click();
    await page.waitForURL(base + "/admin");
    pass("manager login lands on overview");
    const pages = [
        ["overview", "/admin"],
        ["files", "/admin/files"],
        ["detail", `/admin/files/${editable.id}`],
        ["sharing", `/admin/files/${editable.id}?tab=sharing`],
        ["downloads", "/admin/downloads"],
        ["analytics", "/admin/analytics"],
        ["settings", "/admin/settings"],
    ];
    for (const [name, url] of pages) {
        await page.goto(base + url);
        assert.equal(await page.locator("main h1").count(), 1, `${name} has one primary heading`);
        assert.ok(await page.locator("h1").isVisible());
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth + 1,
            ),
            `${name} desktop overflow`,
        );
        await page.screenshot({
            path: path.join(artifacts, `desktop-${name}.png`),
            fullPage: true,
        });
        pass(`desktop ${name} renders without horizontal overflow`);
    }
    await page.goto(base + "/admin/files");
    assert.match(
        await page.locator(".files-table .time-value").first().innerText(),
        /\d{4}年\d{1,2}月\d{1,2}日\s+\d{2}:\d{2}:\d{2}/,
    );
    pass("download timestamps use readable Chinese dates with seconds");
    // Real navigation, interruption, browser history and keyboard dismissal.
    await page.goto(base + "/admin/files?q=" + encodeURIComponent("产品设计"));
    const listUrl = page.url();
    await page.locator("a[data-file-drawer]").first().click();
    await page.locator("#detail-drawer[open] #detail-page-content").waitFor();
    await page.locator("#detail-drawer [data-close-dialog]").click();
    await page.waitForURL(listUrl);
    assert.equal(await page.locator("#detail-drawer").getAttribute("open"), null);
    pass("detail drawer close restores filtered list and URL");
    await page.locator("a[data-file-drawer]").first().click();
    await page.locator("#detail-drawer[open] #detail-page-content").waitFor();
    await page.keyboard.press("Escape");
    await page.waitForURL(listUrl);
    pass("Escape dismisses drawer and preserves list context");
    await page.locator("a[data-file-drawer]").first().click();
    await page.locator("#detail-drawer[open] #detail-page-content").waitFor();
    await page.goBack();
    await page.waitForURL(listUrl);
    assert.equal(await page.locator("#detail-drawer").getAttribute("open"), null);
    await page.goForward();
    await page.locator("#detail-drawer[open] #detail-page-content").waitFor();
    pass("drawer Back and Forward restore the expected screen");
    await page.goto(base + "/admin/files");
    await page.locator("[data-open-upload]").first().click();
    await page.locator("#upload-dialog[open]").waitFor();
    await page.locator("#upload-dialog").getByRole("button", { name: "取消", exact: true }).click();
    assert.equal(await page.locator("#upload-dialog").getAttribute("open"), null);
    assert.equal(page.url(), base + "/admin/files");
    pass("upload cancel closes without losing navigation");
    await page.locator("[data-select-file]").first().check();
    await page.locator("[data-bulk-toolbar]").waitFor({ state: "visible" });
    await page.locator("[data-clear-selection]").click();
    assert.equal(await page.locator("[data-select-file]:checked").count(), 0);
    pass("selection clear is repeatable");
    await page.locator("#file-search").fill("no-such-file-unique");
    await page.locator(".file-toolbar button[type=submit]").click();
    await page.waitForURL(/q=no-such-file/);
    assert.ok(await page.getByText("没有匹配的文件", { exact: true }).isVisible());
    await page.reload();
    assert.equal(await page.locator("#file-search").inputValue(), "no-such-file-unique");
    pass("empty filter state and refresh preserve query");
    // Interrupted detail fetches must be retryable and must never reopen after dismissal.
    await page.goto(base + "/admin/files?q=" + encodeURIComponent("产品设计"));
    const interruptedList = page.url();
    const detailPattern = "**/admin/files/" + editable.id;
    await page.route(detailPattern, (route) => route.abort());
    await page.locator("a[data-file-drawer]").first().click();
    await page.locator("#detail-drawer").getByText("详情暂不可用", { exact: true }).waitFor();
    await page.unroute(detailPattern);
    await page.locator("#detail-drawer").getByRole("button", { name: "重试", exact: true }).click();
    await page.locator("#detail-drawer[open] #detail-page-content").waitFor();
    await page.locator("#detail-drawer [data-close-dialog]").click();
    await page.waitForURL(interruptedList);
    pass("interrupted drawer request shows recoverable error and retry succeeds");
    await page.route(detailPattern, async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 400));
        try {
            await route.continue();
        } catch {}
    });
    await page.locator("a[data-file-drawer]").first().click();
    await page.locator("#detail-drawer[open]").waitFor();
    await page.locator("#detail-drawer [data-close-dialog]").click();
    await page.waitForURL(interruptedList);
    await new Promise((resolve) => setTimeout(resolve, 500));
    assert.equal(await page.locator("#detail-drawer").getAttribute("open"), null);
    assert.equal(page.url(), interruptedList);
    await page.unroute(detailPattern);
    pass("closing during detail loading ignores late responses");
    // Actual policy change with preview, and independent administrator transfer.
    await page.goto(base + `/admin/files/${editable.id}?tab=sharing`);
    await page.locator("[name=quota_mode]").selectOption("remaining");
    await page.locator("[name=quota_amount]").fill("5");
    assert.match(await page.locator("[data-quota-preview-text]").innerText(), /5/);
    await page.getByRole("button", { name: "保存分享设置" }).click();
    await page.waitForURL(base + `/admin/files/${editable.id}?tab=sharing`);
    assert.ok(await page.getByText("分享设置已保存", { exact: true }).isVisible());
    pass("quota preview and real policy save");
    await page.locator("[data-auto-destroy]").check();
    await page.locator("[data-confirm-destroy]").waitFor({ state: "visible" });
    assert.ok(
        await page
            .locator("[data-destruction-confirmation]")
            .innerText()
            .then((t) => t.includes("无法通过本应用恢复")),
    );
    await page.locator("[data-auto-destroy]").uncheck();
    pass("destruction option presents explicit irreversible confirmation and can be cancelled");
    const downloadPromise = page.waitForEvent("download");
    await page.locator("[data-download-form] button").click();
    const download = await downloadPromise;
    assert.equal(download.suggestedFilename(), editable.name);
    await download.saveAs(path.join(tmp, "admin-download.txt"));
    pass("administrator dedicated download works in browser");
    await page.locator("[data-download-form] button:not([disabled])").waitFor();
    const secondDownloadPromise = page.waitForEvent("download");
    await page.locator("[data-download-form] button").click();
    assert.equal((await secondDownloadPromise).suggestedFilename(), editable.name);
    pass("administrator attachment button recovers for another download");
    // Public password page on a separate unauthenticated browser context.
    const visitor = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
        locale: "zh-CN",
    });
    const publicPage = await visitor.newPage();
    publicPage.on("pageerror", (e) => jsErrors.push(e.message));
    const publicListResponse = await publicPage.goto(base + "/");
    assert.equal(publicListResponse.status(), 200, "visitor home is a public file list");
    assert.equal(publicListResponse.headers()["set-cookie"], undefined, "public list starts no session");
    assert.deepEqual(await visitor.cookies(), [], "anonymous list does not create a cookie");
    assert.ok(await publicPage.getByRole("heading", { name: "共享文件", exact: true }).isVisible());
    const publicHtml = await publicListResponse.text();
    for (const privateValue of ["203.0.113.", "example.org/notes", "design.example/review", "password_hash", "storage_name", 'name="csrf"', "data-download-form", "data-policy-form"]) {
        assert.ok(!publicHtml.includes(privateValue), `public HTML excludes private value: ${privateValue}`);
    }
    assert.equal(await publicPage.locator('a[href="/admin"]').count(), 1, "public list has a management entry");
    assert.equal(await publicPage.locator('[data-open-upload],[data-select-file],.sidebar,.analytics-metrics').count(), 0);
    assert.equal(await publicPage.locator('[data-public-file]').filter({ hasText: '使用指南.pdf' }).count(), 0, "paused fixture is not publicly listed");
    const publicEditable = publicPage.locator(`[data-public-file="${editable.public_id}"]`);
    const publicProtected = publicPage.locator(`[data-public-file="${protectedFile.public_id}"]`);
    assert.ok(await publicEditable.isVisible());
    assert.ok(await publicProtected.getByText("需要密码", { exact: true }).isVisible());
    assert.match(await publicEditable.locator('.public-file-time').innerText(), /\d{4}年\d{1,2}月\d{1,2}日\s+\d{2}:\d{2}:\d{2}/);
    await publicPage.screenshot({ path: path.join(artifacts, "desktop-public-files.png"), fullPage: true });
    pass("anonymous public catalogue exposes only safe metadata and starts no session");
    await publicEditable.locator('.public-hash-details > summary').click();
    assert.equal(await publicEditable.locator('[data-public-sha]').innerText(), editable.sha256);
    await visitor.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: base });
    await publicEditable.getByRole('button', { name: '复制校验值', exact: true }).click();
    assert.equal(await publicPage.evaluate(() => navigator.clipboard.readText()), editable.sha256);
    await publicEditable.getByRole('button', { name: '复制链接', exact: true }).click();
    assert.equal(await publicPage.evaluate(() => navigator.clipboard.readText()), base + '/d/' + editable.public_id);
    pass("public full SHA256 and share link copy use the real browser clipboard");
    const directDownloadPromise = publicPage.waitForEvent('download');
    await publicEditable.locator('[data-public-download]').click();
    const directDownload = await directDownloadPromise;
    assert.equal(directDownload.suggestedFilename(), editable.name);
    const publicDownloadPath = path.join(tmp, 'public-direct-download.txt');
    await directDownload.saveAs(publicDownloadPath);
    assert.equal(await fs.readFile(publicDownloadPath, 'utf8'), await fs.readFile(path.join(tmp, 'files', editable.name), 'utf8'));
    assert.equal(publicPage.url(), base + '/', 'unprotected public download needs no intermediate page');
    pass("unprotected file starts a real attachment directly from the visitor list");
    const privateResponse = await visitor.request.get(base + '/admin/files', { maxRedirects: 0 });
    assert.equal(privateResponse.status(), 302);
    assert.equal(privateResponse.headers().location, '/admin/login');
    pass("visitor catalogue does not authorize private administration");
    await publicProtected.locator('[data-public-download]').click();

    assert.ok(await publicPage.getByRole("heading", { name: "输入分享密码" }).isVisible());
    await publicPage.screenshot({
        path: path.join(artifacts, "desktop-password.png"),
        fullPage: true,
    });
    await publicPage.locator("input[name=password]").fill("wrong");
    await publicPage.getByRole("button", { name: "验证并下载" }).click();
    assert.ok(await publicPage.getByRole("alert").isVisible());
    await publicPage.locator("input[name=password]").fill("archive-fixture-password");
    const publicDownloadPromise = publicPage.waitForEvent("download");
    await publicPage.getByRole("button", { name: "验证并下载" }).click();
    const publicDownload = await publicDownloadPromise;
    assert.equal(publicDownload.suggestedFilename(), protectedFile.name);
    pass("password error then successful public attachment download");
    await publicPage.setViewportSize({ width: 390, height: 844 });
    await publicPage.goto(base + '/');
    assert.ok(await publicPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    const phoneHash = publicPage.locator('.public-hash-details').first();
    await phoneHash.locator('summary').click();
    assert.ok(await phoneHash.locator('[data-public-sha]').isVisible());
    assert.ok(await publicPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'expanded SHA256 fits a phone');
    await publicPage.screenshot({ path: path.join(artifacts, 'mobile-public-files.png'), fullPage: true });
    await publicPage.locator('#public-search').fill('no-public-file-matches-this');
    await publicPage.getByRole('button', { name: '搜索', exact: true }).click();
    await publicPage.waitForURL(/q=no-public-file-matches-this/);
    assert.ok(await publicPage.getByText('没有匹配的文件', { exact: true }).isVisible());
    await publicPage.reload();
    assert.equal(await publicPage.locator('#public-search').inputValue(), 'no-public-file-matches-this');
    await publicPage.getByRole('link', { name: '显示全部文件', exact: true }).first().click();
    assert.ok(await publicPage.locator('[data-public-file]').count() >= 2);
    pass('visitor phone catalogue, full hash and refresh-safe search remain usable');
    await visitor.close();
    // Upload success, duplicate rejection and recovery use the real PHP HTTP endpoint.
    await page.goto(base + '/admin/files');
    const uploadBody = 'Real browser upload fixture.\n' + 'payload '.repeat(200);
    const uploadName = 'browser-upload-success.txt';
    const uploadDialog = page.locator('#upload-dialog');
    async function selectUpload(name, content = uploadBody) {
        if (!(await uploadDialog.isVisible())) await page.locator('[data-open-upload]').first().click();
        await page.locator('[data-file-input]').setInputFiles({ name, mimeType: 'text/plain', buffer: Buffer.from(content) });
    }
    async function realUpload(name, content = uploadBody) {
        await selectUpload(name, content);
        const responsePromise = page.waitForResponse(response => response.url() === base + '/admin/upload' && response.request().method() === 'POST');
        await page.locator('[data-upload-submit]').click();
        const response = await responsePromise;
        assert.equal(response.request().resourceType(), 'xhr');
        assert.equal(response.status(), 200);
        assert.equal((await response.json()).ok, true);
        await page.getByText('上传成功：' + name, { exact: true }).waitFor();
        assert.equal(await fs.readFile(path.join(tmp, 'files', name), 'utf8'), content);
    }
    await realUpload(uploadName);
    pass('XHR upload saves actual file bytes over real authenticated PHP HTTP');
    await selectUpload(uploadName, 'must not replace existing bytes');
    const duplicateResponsePromise = page.waitForResponse(response => response.url() === base + '/admin/upload' && response.request().method() === 'POST');
    await page.locator('[data-upload-submit]').click();
    assert.equal((await duplicateResponsePromise).status(), 409);
    await page.locator('[data-upload-error]').waitFor({ state: 'visible' });
    assert.match(await page.locator('[data-upload-error]').innerText(), /同名/);
    assert.ok(await page.locator('[data-upload-submit]').isEnabled());
    assert.ok(await page.locator('[data-file-input]').isEnabled());
    assert.equal(await fs.readFile(path.join(tmp, 'files', uploadName), 'utf8'), uploadBody);
    await realUpload('browser-upload-retry.txt');
    pass('real server rejection preserves existing bytes and permits a successful upload retry');

    // Deterministic XHR event stubs below test only progress/interruption UI. They save no file
    // and never simulate a successful upload. All success assertions above/below use real HTTP.
    await page.evaluate(() => {
        window.__shareNativeXHR = window.XMLHttpRequest;
        window.__shareTestUploads = [];
        class UploadEventFixture extends EventTarget {
            constructor() { super(); this.upload = new EventTarget(); this.status = 0; this.response = null; this.headers = {}; this.aborted = false; }
            open(method, url) { this.method = method; this.url = url; }
            setRequestHeader(name, value) { this.headers[name] = value; }
            send(body) { this.body = body; window.__shareTestUploads.push(this); }
            abort() { this.aborted = true; this.dispatchEvent(new Event('abort')); }
        }
        window.XMLHttpRequest = UploadEventFixture;
    });
    await selectUpload('browser-upload-interrupted.txt');
    await page.locator('[data-upload-submit]').click();
    await page.evaluate(() => {
        const xhr = window.__shareTestUploads.at(-1);
        xhr.upload.dispatchEvent(new ProgressEvent('progress', { lengthComputable: true, loaded: 25, total: 100 }));
    });
    assert.equal(await page.locator('[data-upload-progressbar]').getAttribute('aria-valuenow'), '25');
    assert.equal(await page.locator('[data-upload-percent]').innerText(), '25%');
    assert.equal(await page.evaluate(() => window.__shareTestUploads[0].headers.Accept), 'application/json');
    await page.locator('[data-upload-form]').evaluate(form => {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    assert.equal(await page.evaluate(() => window.__shareTestUploads.length), 1, 'duplicate submits do not create another request');
    await page.screenshot({ path: path.join(artifacts, 'desktop-upload-progress-stub.png'), fullPage: true });
    await page.evaluate(() => window.__shareTestUploads[0].upload.dispatchEvent(new ProgressEvent('progress', { lengthComputable: true, loaded: 100, total: 100 })));
    assert.equal(await page.locator('[data-upload-progressbar]').getAttribute('aria-valuenow'), '100');
    assert.equal(await page.locator('[data-upload-progress]').getAttribute('data-phase'), 'saving');
    assert.match(await page.locator('[data-upload-status]').innerText(), /正在保存/);
    assert.match(await page.locator('[data-upload-detail]').innerText(), /尚不能认定上传成功/);
    assert.ok(await page.locator('[data-upload-submit]').isDisabled());
    await page.screenshot({ path: path.join(artifacts, 'desktop-upload-saving-stub.png'), fullPage: true });
    pass('deterministic upload progress is observed, 100% waits for saving, repeated submit is suppressed');
    await page.evaluate(() => window.__shareTestUploads.at(-1).dispatchEvent(new Event('timeout')));
    await page.locator('[data-upload-error]').waitFor({ state: 'visible' });
    assert.match(await page.locator('[data-upload-error]').innerText(), /超时/);
    assert.match(await page.locator('[data-upload-detail]').innerText(), /无法确认/);
    assert.ok(await page.locator('[data-upload-check]').isVisible());
    assert.ok(await page.locator('[data-upload-submit]').isEnabled());
    pass('deterministic timeout reports uncertain save outcome and enables recovery');
    await page.locator('[data-upload-submit]').click();
    await page.evaluate(() => window.__shareTestUploads.at(-1).upload.dispatchEvent(new ProgressEvent('progress', { lengthComputable: false, loaded: 42, total: 0 })));
    assert.equal(await page.locator('[data-upload-progressbar]').getAttribute('aria-valuenow'), null);
    assert.equal(await page.locator('[data-upload-percent]').innerText(), '发送中');
    await page.evaluate(() => window.__shareTestUploads.at(-1).dispatchEvent(new Event('error')));
    assert.match(await page.locator('[data-upload-error]').innerText(), /连接中断/);
    assert.ok(await page.locator('[data-upload-submit]').isEnabled());
    pass('deterministic unknown-length and network failure never invent a percentage or saved result');
    await page.locator('[data-upload-submit]').click();
    await page.locator('[data-upload-abort]').click();
    assert.equal(await page.evaluate(() => window.__shareTestUploads.at(-1).aborted), true);
    assert.equal(await page.locator('[data-upload-progress]').getAttribute('data-phase'), 'cancelled');
    assert.ok(await uploadDialog.isVisible());
    assert.ok(await page.locator('[data-upload-submit]').isEnabled());
    assert.equal(await page.locator('[data-file-input]').evaluate(input => input.files[0].name), 'browser-upload-interrupted.txt');
    pass('deterministic cancellation aborts transport and keeps the selected file available for retry');
    await page.locator('[data-upload-submit]').click();
    await uploadDialog.getByRole('button', { name: '关闭上传窗口', exact: true }).click();
    await page.locator('[data-upload-confirm-close]').waitFor({ state: 'visible' });
    assert.equal(await page.evaluate(() => window.__shareTestUploads.at(-1).aborted), false);
    await page.locator('[data-upload-keep]').click();
    assert.ok(await page.locator('[data-upload-confirm-close]').isHidden());
    await page.keyboard.press('Escape');
    await page.locator('[data-upload-confirm-close]').waitFor({ state: 'visible' });
    await page.screenshot({ path: path.join(artifacts, 'desktop-upload-cancel-stub.png'), fullPage: true });
    await page.locator('[data-upload-abort-close]').click();
    assert.equal(await page.evaluate(() => window.__shareTestUploads.at(-1).aborted), true);
    assert.ok(await uploadDialog.isHidden());
    const afterUploadCancellation = page.url();
    // A stale successful callback is a simulated race, not a claimed successful upload.
    await page.evaluate(() => {
        const stale = window.__shareTestUploads.at(-1);
        stale.status = 200; stale.response = { ok: true };
        stale.dispatchEvent(new Event('load'));
        window.XMLHttpRequest = window.__shareNativeXHR;
    });
    assert.equal(page.url(), afterUploadCancellation);
    assert.ok(await uploadDialog.isHidden());
    await assert.rejects(fs.access(path.join(tmp, 'files', 'browser-upload-interrupted.txt')));
    pass('close and Escape allow continuing or cancelling, and stale callbacks cannot redirect or reopen');
    await realUpload('browser-upload-after-cancel.txt');
    pass('real HTTP upload succeeds after timeout, cancellation and dialog reopening');

    // Small phone layout including full detail/settings, with no horizontal panning.
    await page.setViewportSize({ width: 390, height: 844 });
    for (const [name, url] of pages) {
        await page.goto(base + url);
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth + 1,
            ),
            `${name} mobile overflow`,
        );
        if (name === "files") {
            const collisions = await page.evaluate(
                () =>
                    Array.from(document.querySelectorAll("[data-file-row]")).filter((row) => {
                        const title = row.querySelector(".file-primary .file-name");
                        const checkbox = row.querySelector(".selection-cell .checkbox-label");
                        if (!title || !checkbox) return false;
                        const range = document.createRange();
                        range.selectNodeContents(title);
                        const box = checkbox.getBoundingClientRect();
                        return Array.from(range.getClientRects()).some(
                            (rect) =>
                                rect.top < box.bottom &&
                                rect.bottom > box.top &&
                                rect.right > box.left - 4,
                        );
                    }).length,
            );
            assert.equal(collisions, 0, "phone filenames do not overlap selection controls");
            pass("mobile long filenames clear their selection controls");
        }
        await page.screenshot({
            path: path.join(artifacts, `mobile-${name}.png`),
            fullPage: true,
        });
        pass(`mobile ${name} fits 390px`);
    }
    await page.locator("[data-navigation-toggle]").click();
    assert.equal(
        await page.locator("[data-navigation-toggle]").getAttribute("aria-expanded"),
        "true",
    );
    await page.keyboard.press("Escape");
    assert.equal(
        await page.locator("[data-navigation-toggle]").getAttribute("aria-expanded"),
        "false",
    );
    pass("mobile navigation opens and keyboard closes");
    assert.deepEqual(jsErrors, [], "no uncaught frontend exceptions");
    pass("no uncaught JavaScript errors");
    await fs.writeFile(
        path.join(artifacts, "results.json"),
        JSON.stringify(
            {
                passed: results.length,
                checks: results,
                viewports: ["1440x1000", "390x844"],
                data: "synthetic isolated fixtures only",
                upload_http: "Successful uploads and duplicate rejection use actual authenticated PHP HTTP and verified file bytes",
                upload_progress_interruptions: "Deterministic XMLHttpRequest event fixtures; they do not save files",
            },
            null,
            2,
        ),
    );
    console.log(`${results.length} browser checks passed; screenshots in artifacts/browser`);
} catch (e) {
    await fs.writeFile(path.join(artifacts, "failure.txt"), String(e.stack || e));
    throw e;
} finally {
    if (browser) await browser.close();
    try {
        process.kill(-server.pid, "SIGTERM");
    } catch {}
    await fs.rm(tmp, { recursive: true, force: true });
}
