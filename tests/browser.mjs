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
const server = spawn(
  php,
  ["-S", `127.0.0.1:${port}`, "-t", "public", "scripts/dev-router.php"],
  { cwd: root, env, detached: true, stdio: ["ignore", "pipe", "pipe"] },
);
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
    assert.equal(
      await page.locator("main h1").count(),
      1,
      `${name} has one primary heading`,
    );
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
  await page
    .locator("#upload-dialog")
    .getByRole("button", { name: "取消", exact: true })
    .click();
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
  assert.ok(
    await page.getByText("没有匹配的文件", { exact: true }).isVisible(),
  );
  await page.reload();
  assert.equal(
    await page.locator("#file-search").inputValue(),
    "no-such-file-unique",
  );
  pass("empty filter state and refresh preserve query");
  // Actual policy change with preview, and independent administrator transfer.
  await page.goto(base + `/admin/files/${editable.id}?tab=sharing`);
  await page.locator("[name=quota_mode]").selectOption("remaining");
  await page.locator("[name=quota_amount]").fill("5");
  assert.match(
    await page.locator("[data-quota-preview-text]").innerText(),
    /5/,
  );
  await page.getByRole("button", { name: "保存分享设置" }).click();
  await page.waitForURL(base + `/admin/files/${editable.id}?tab=sharing`);
  assert.ok(
    await page.getByText("分享设置已保存", { exact: true }).isVisible(),
  );
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
  pass(
    "destruction option presents explicit irreversible confirmation and can be cancelled",
  );
  const downloadPromise = page.waitForEvent("download");
  await page.locator("[data-download-form] button").click();
  const download = await downloadPromise;
  assert.equal(download.suggestedFilename(), editable.name);
  await download.saveAs(path.join(tmp, "admin-download.txt"));
  pass("administrator dedicated download works in browser");
  // Public password page on a separate unauthenticated browser context.
  const visitor = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    locale: "zh-CN",
  });
  const publicPage = await visitor.newPage();
  publicPage.on("pageerror", (e) => jsErrors.push(e.message));
  await publicPage.goto(base + "/d/" + protectedFile.public_id);
  assert.ok(
    await publicPage.getByRole("heading", { name: "输入分享密码" }).isVisible(),
  );
  await publicPage.screenshot({
    path: path.join(artifacts, "desktop-password.png"),
    fullPage: true,
  });
  await publicPage.locator("input[name=password]").fill("wrong");
  await publicPage.getByRole("button", { name: "验证并下载" }).click();
  assert.ok(await publicPage.getByRole("alert").isVisible());
  await publicPage
    .locator("input[name=password]")
    .fill("archive-fixture-password");
  const publicDownloadPromise = publicPage.waitForEvent("download");
  await publicPage.getByRole("button", { name: "验证并下载" }).click();
  const publicDownload = await publicDownloadPromise;
  assert.equal(publicDownload.suggestedFilename(), protectedFile.name);
  pass("password error then successful public attachment download");
  await visitor.close();
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
    await page.screenshot({
      path: path.join(artifacts, `mobile-${name}.png`),
      fullPage: true,
    });
    pass(`mobile ${name} fits 390px`);
  }
  await page.locator("[data-navigation-toggle]").click();
  assert.equal(
    await page
      .locator("[data-navigation-toggle]")
      .getAttribute("aria-expanded"),
    "true",
  );
  await page.keyboard.press("Escape");
  assert.equal(
    await page
      .locator("[data-navigation-toggle]")
      .getAttribute("aria-expanded"),
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
      },
      null,
      2,
    ),
  );
  console.log(
    `${results.length} browser checks passed; screenshots in artifacts/browser`,
  );
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
