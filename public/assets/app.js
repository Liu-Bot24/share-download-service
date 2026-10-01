/* Share Files: progressive enhancements. No analytics, external requests or browser persistence. */
(() => {
  'use strict';
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const toast = $('#toast');
  let toastTimer;
  function announce(message) {
    if (!toast) return;
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.hidden = false;
    toastTimer = setTimeout(() => { toast.hidden = true; }, 3400);
  }
  async function copyText(value, label = '链接') {
    if (!value) { announce('没有可复制的内容'); return false; }
    try {
      if (!navigator.clipboard?.writeText) throw new Error('clipboard unavailable');
      await navigator.clipboard.writeText(value);
    } catch {
      const field = document.createElement('textarea');
      field.value = value;
      field.setAttribute('readonly', '');
      field.style.cssText = 'position:fixed;left:-9999px;top:0;';
      const active = document.activeElement;
      // Put the fallback inside the active dialog, so the focus trap permits selection.
      ($('dialog[open]') || document.body).append(field);
      field.select();
      let copied = false;
      try { copied = document.execCommand('copy'); } catch { /* Report an actionable error. */ }
      field.remove();
      active?.focus({ preventScroll: true });
      if (!copied) { announce('无法访问剪贴板，请在文件详情中选择链接后复制'); return false; }
    }
    announce(`${label}已复制`);
    return true;
  }
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-copy]');
    if (button) {
      copyText(button.dataset.copy, button.dataset.copyLabel || '链接').then(ok => {
        if (!ok || !button.isConnected) return;
        button.classList.add('copied');
        const original = button.getAttribute('aria-label');
        button.setAttribute('aria-label', '已复制');
        setTimeout(() => {
          button.classList.remove('copied');
          if (original) button.setAttribute('aria-label', original);
          else button.removeAttribute('aria-label');
        }, 1800);
      });
    }
    if (event.target.closest('[data-dismiss-notice]')) event.target.closest('.notice')?.remove();
    const passwordToggle = event.target.closest('[data-toggle-password]');
    if (passwordToggle) {
      const input = document.getElementById(passwordToggle.dataset.togglePassword);
      if (!input) return;
      const reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      passwordToggle.setAttribute('aria-label', reveal ? '隐藏密码' : '显示密码');
      passwordToggle.setAttribute('aria-pressed', String(reveal));
    }
    if (event.target.closest('[data-go-back]')) {
      if (history.length > 1) history.back();
      else location.assign('/');
    }
  });

  // The mobile navigation is inert while collapsed, and traps focus while open.
  const navigation = $('#primary-navigation');
  const navToggle = $('[data-navigation-toggle]');
  const workspaceMain = $('.workspace-main');
  const mobile = matchMedia('(max-width: 760px)');
  const focusable = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),summary,[tabindex="0"]';
  function setNavigation(open, moveFocus = true) {
    if (!navigation || !navToggle) return;
    const expanded = mobile.matches && open;
    document.body.classList.toggle('nav-open', expanded);
    navToggle.setAttribute('aria-expanded', String(expanded));
    navToggle.setAttribute('aria-label', expanded ? '关闭导航' : '展开导航');
    navigation.inert = mobile.matches && !expanded;
    if (workspaceMain) workspaceMain.inert = expanded;
    if (expanded) {
      navigation.setAttribute('aria-modal', 'true');
      navigation.setAttribute('role', 'dialog');
      if (moveFocus) $('.nav-item.active', navigation)?.focus();
    } else {
      navigation.removeAttribute('aria-modal');
      navigation.removeAttribute('role');
      if (moveFocus && mobile.matches) navToggle.focus({ preventScroll: true });
    }
  }
  navToggle?.addEventListener('click', () => setNavigation(!document.body.classList.contains('nav-open')));
  $$('[data-close-navigation]').forEach(button => button.addEventListener('click', () => setNavigation(false)));
  navigation?.addEventListener('click', event => {
    if (event.target.closest('a')) setNavigation(false, false);
  });
  mobile.addEventListener('change', () => setNavigation(false, false));
  setNavigation(false, false);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.body.classList.contains('nav-open')) {
      event.preventDefault();
      setNavigation(false);
      return;
    }
    if (event.key === 'Tab' && document.body.classList.contains('nav-open') && navigation) {
      const controls = $$(focusable, navigation).filter(el => el.getClientRects().length);
      const first = controls[0], last = controls.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey &&
        !event.target.closest('input,textarea,select,[contenteditable]') && !$('dialog[open]')) {
      const search = $('#file-search');
      if (search) { event.preventDefault(); search.focus(); }
    }
    if (event.key === 'Escape') $$('.date-picker[open],.advanced-filters[open]').forEach(details => { details.open = false; });
  });

  // Upload remains an ordinary multipart form: progress never invents a percentage.
  const upload = $('#upload-dialog');
  const uploadForm = $('[data-upload-form]');
  const fileInput = $('[data-file-input]');
  function prettyBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KiB`;
    return `${(bytes / 1024 ** 2).toFixed(1)} MiB`;
  }
  function validateUpload() {
    if (!fileInput) return false;
    const file = fileInput.files?.[0];
    const error = $('[data-upload-error]');
    let message = '';
    if (fileInput.files?.length > 1) message = '每次请选择一个文件';
    else if (file && file.size > 45 * 1024 * 1024) message = '文件超过 45 MiB，请选择更小的文件';
    fileInput.setCustomValidity(message);
    if (error) { error.textContent = message; error.hidden = !message; }
    $('[data-upload-label]').textContent = file ? file.name : '选择文件，或拖放到这里';
    $('[data-upload-size]').textContent = file ? `${prettyBytes(file.size)} · ${message || '准备就绪'}` : '每次上传一个文件，最大 45 MiB';
    return !message;
  }
  fileInput?.addEventListener('change', validateUpload);
  const dropzone = $('[data-dropzone]');
  ['dragenter','dragover'].forEach(name => dropzone?.addEventListener(name, event => {
    event.preventDefault();
    dropzone.classList.add('dragover');
  }));
  ['dragleave','drop'].forEach(name => dropzone?.addEventListener(name, event => {
    event.preventDefault();
    dropzone.classList.remove('dragover');
    if (name === 'drop' && event.dataTransfer?.files.length && fileInput) {
      fileInput.files = event.dataTransfer.files;
      validateUpload();
    }
  }));
  upload?.addEventListener('close', () => {
    uploadForm?.reset();
    if (fileInput) validateUpload();
  });
  document.addEventListener('click', event => {
    if (event.target.closest('[data-open-upload]')) {
      if (upload && typeof upload.showModal === 'function') upload.showModal();
      else announce('浏览器不支持上传窗口，请使用页面底部的上传表单');
    }
    const close = event.target.closest('[data-close-dialog]');
    if (close) {
      const dialog = close.closest('dialog');
      if (dialog === drawer) dismissDrawer();
      else dialog?.close();
    }
  });
  $$('dialog').forEach(dialog => dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const box = dialog.getBoundingClientRect();
    if (event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom) return;
    if (dialog === drawer) dismissDrawer();
    else dialog.close();
  }));

  // Client-side selection only copies public entry links; it does not mutate file policy.
  function updateSelection() {
    const files = $$('[data-select-file]');
    const selected = files.filter(input => input.checked);
    const all = $('[data-select-all]');
    if (all) { all.checked = files.length > 0 && selected.length === files.length; all.indeterminate = selected.length > 0 && selected.length < files.length; }
    const toolbar = $('[data-bulk-toolbar]');
    if (toolbar) toolbar.hidden = !selected.length;
    const count = $('[data-selection-count]');
    if (count) count.textContent = selected.length;
    files.forEach(input => input.closest('tr')?.classList.toggle('is-selected', input.checked));
  }
  document.addEventListener('change', event => {
    if (event.target.matches('[data-select-all]')) $$('[data-select-file]').forEach(input => { input.checked = event.target.checked; });
    if (event.target.matches('[data-select-all],[data-select-file]')) updateSelection();
  });
  document.addEventListener('click', event => {
    if (event.target.closest('[data-clear-selection]')) {
      $$('[data-select-file]').forEach(input => { input.checked = false; });
      updateSelection();
    }
    if (event.target.closest('[data-copy-selected]')) {
      const links = $$('[data-select-file]:checked').map(input => input.dataset.publicUrl).filter(Boolean);
      copyText(links.join('\n'), `${links.length} 个分享链接`);
    }
  });

  // Policy previews use current persisted values. The server remains authoritative.
  function setupPolicy(root = document) {
    $$('[data-policy-form]', root).forEach(form => {
      if (form.dataset.enhanced) return;
      form.dataset.enhanced = 'true';
      const count = Number(form.dataset.currentCount) || 0;
      const cap = form.dataset.currentCap === '' ? null : Number(form.dataset.currentCap);
      const previousAuto = form.dataset.currentAuto === '1';
      const quotaMode = $('[data-quota-mode]', form);
      const quotaAmount = $('[data-quota-amount]', form);
      const passwordAction = $('[data-password-action]', form);
      const password = $('[data-new-password]', form);
      const auto = $('[data-auto-destroy]', form);
      const confirm = $('[data-confirm-destroy]', form);
      const preview = $('[data-quota-preview]', form);
      function update() {
        const mode = quotaMode.value;
        const needsAmount = mode === 'add' || mode === 'remaining';
        $('[data-quota-field]', form).hidden = !needsAmount;
        quotaAmount.disabled = !needsAmount;
        quotaAmount.required = needsAmount;
        const amount = Number(quotaAmount.value);
        const amountValid = !needsAmount || (Number.isSafeInteger(amount) && amount >= 1 && amount <= 100000000);
        let nextCap = cap;
        if (mode === 'unlimited') nextCap = null;
        else if (needsAmount && amountValid) nextCap = (mode === 'add' && cap !== null ? Math.max(count, cap) : count) + amount;
        let message;
        if (!amountValid) message = '填写 1–100000000 的整数，即可预览新的剩余名额';
        else if (nextCap === null) message = `保存后不限次数，公开累计 ${count.toLocaleString('zh-CN')} 次保持不变。`;
        else message = `保存后剩余 ${Math.max(0, nextCap - count).toLocaleString('zh-CN')} 次，累计总上限 ${nextCap.toLocaleString('zh-CN')} 次；公开累计 ${count.toLocaleString('zh-CN')} 次保持不变。`;
        $('[data-quota-preview-text]', form).textContent = message;
        preview.classList.toggle('invalid', !amountValid);
        const setPassword = passwordAction.value === 'set';
        $('[data-password-field]', form).hidden = !setPassword;
        password.disabled = !setPassword;
        password.required = setPassword;
        // Never retain a new password after cancelling its operation.
        if (!setPassword) password.value = '';
        $('[data-destruction-confirmation]', form).hidden = !auto.checked;
        const needsConfirmation = auto.checked && (!previousAuto || nextCap !== cap);
        confirm.required = needsConfirmation;
        confirm.disabled = !auto.checked;
        confirm.setCustomValidity('');
        $('[data-destruction-threshold]', form).textContent = !amountValid ? '填写下载名额后预览触发阈值' : (nextCap === null ? '请先设置有限的下载额度' : `达到累计 ${nextCap.toLocaleString('zh-CN')} 次后触发`);
        auto.setCustomValidity(auto.checked && nextCap === null ? '自动销毁需要先设置有限的下载额度' : '');
        form.dataset.needsDestroyConfirmation = String(needsConfirmation);
      }
      form.addEventListener('input', update);
      form.addEventListener('change', update);
      form.addEventListener('submit', event => {
        if (form.dataset.needsDestroyConfirmation === 'true' && confirm.value !== form.dataset.filename) {
          event.preventDefault();
          confirm.setCustomValidity('请输入完整且完全一致的文件名，确认自动永久销毁');
          confirm.reportValidity();
          return;
        }
      });
      update();
    });
  }
  setupPolicy();

  // A deep-linkable detail drawer: Close, Escape and Back always restore the list URL.
  const drawer = $('#detail-drawer');
  const drawerBody = $('[data-drawer-body]');
  let drawerFetch;
  let drawerOrigin;
  let drawerRequest = 0;
  function closeDrawerView() {
    drawerRequest++;
    drawerFetch?.abort();
    if (drawer?.open) drawer.close();
    drawerOrigin?.focus({ preventScroll: true });
  }
  function dismissDrawer() {
    if (!drawer) return;
    if (history.state?.sfDrawer) {
      closeDrawerView();
      history.back();
    } else closeDrawerView();
  }
  async function loadDrawer(url, { push = true, origin = null, replace = false } = {}) {
    if (!drawer || !drawerBody || typeof drawer.showModal !== 'function') { location.assign(url); return; }
    const target = new URL(url, location.href);
    if (target.origin !== location.origin || !/^\/admin\/files\/\d+$/.test(target.pathname)) { location.assign(target.href); return; }
    const relative = target.pathname + target.search;
    if (origin) drawerOrigin = origin;
    if (push) {
      const listUrl = history.state?.sfListUrl || location.pathname + location.search;
      if (!history.state?.sfDrawer) history.replaceState({ ...history.state, sfList: true }, '', location.href);
      const state = { sfDrawer: relative, sfListUrl: listUrl };
      if (replace) history.replaceState(state, '', relative);
      else history.pushState(state, '', relative);
    }
    drawerFetch?.abort();
    drawerFetch = new AbortController();
    const request = ++drawerRequest;
    $('[data-drawer-full]').href = relative;
    $('#drawer-title').textContent = '文件详情';
    drawerBody.setAttribute('aria-busy', 'true');
    drawerBody.innerHTML = '<div role="status" aria-label="正在加载文件详情"><div class="skeleton skeleton-heading"></div><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-panel"></div><div class="skeleton skeleton-panel"></div><span class="sr-only">正在加载文件详情…</span></div>';
    if (!drawer.open) drawer.showModal();
    try {
      const response = await fetch(relative, { signal: drawerFetch.signal, credentials: 'same-origin', headers: { 'X-Requested-With': 'ShareFilesDrawer' } });
      const html = await response.text();
      if (request !== drawerRequest || !drawer.open) return;
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const content = $('#detail-page-content', doc);
      if (!response.ok || !content) {
        const message = $('.error-description', doc)?.textContent || (response.url.includes('/admin/login') ? '管理登录已失效，请重新登录后继续。' : '暂时无法加载文件详情，请重试。');
        throw new Error(message);
      }
      drawerBody.replaceChildren(document.importNode(content, true));
      drawerBody.scrollTop = 0;
      setupPolicy(drawerBody);
      $('#drawer-title').textContent = '文件详情';
    } catch (error) {
      if (error.name === 'AbortError' || request !== drawerRequest || !drawer.open) return;
      const wrapper = document.createElement('div');
      wrapper.className = 'empty-state';
      const heading = document.createElement('h3');
      heading.textContent = '详情暂不可用';
      const message = document.createElement('p');
      message.textContent = error.message || '请检查网络后重试';
      const retry = document.createElement('button');
      retry.type = 'button'; retry.className = 'button secondary'; retry.textContent = '重试';
      retry.addEventListener('click', () => loadDrawer(relative, { push: false }));
      const full = document.createElement('a');
      full.className = 'button ghost'; full.href = relative; full.textContent = '打开完整页面';
      wrapper.append(heading, message, retry, full);
      drawerBody.replaceChildren(wrapper);
    } finally {
      if (request === drawerRequest) drawerBody.removeAttribute('aria-busy');
    }
  }
  drawer?.addEventListener('cancel', event => { event.preventDefault(); dismissDrawer(); });
  document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a');
    if (!link) return;
    if (link.matches('[data-file-drawer]') && matchMedia('(min-width: 1000px)').matches) {
      event.preventDefault(); loadDrawer(link.href, { origin: link });
    } else if (drawer?.open && link.closest('[data-drawer-body]') && /^\/admin\/files\/\d+$/.test(new URL(link.href).pathname) && !link.target) {
      event.preventDefault(); loadDrawer(link.href, { push: true, replace: true });
    }
  });
  window.addEventListener('popstate', event => {
    if (event.state?.sfDrawer) loadDrawer(event.state.sfDrawer, { push: false });
    else closeDrawerView();
  });

  // A timezone preview that changes only presentation, before the settings are saved.
  const timezoneSelect = $('[data-timezone-select]');
  function updateTimezonePreview() {
    if (!timezoneSelect) return;
    try {
      const now = new Date();
      const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezoneSelect.value, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).formatToParts(now);
      const get = type => parts.find(part => part.type === type)?.value || '';
      $('[data-timezone-preview]').textContent = `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')}:${get('second')}`;
      const offset = new Intl.DateTimeFormat('en', { timeZone: timezoneSelect.value, timeZoneName: 'longOffset' }).formatToParts(now).find(part => part.type === 'timeZoneName')?.value || '';
      $('[data-timezone-offset]').textContent = `${offset} · ${timezoneSelect.value}`;
    } catch { $('[data-timezone-offset]').textContent = `${timezoneSelect.value} · 保存后由服务器显示`; }
  }
  timezoneSelect?.addEventListener('change', updateTimezonePreview);
  $$('.settings-navigation a').forEach(link => link.addEventListener('click', () => {
    $$('.settings-navigation a').forEach(item => item.classList.toggle('active', item === link));
  }));

  // Native form submissions keep CSRF, browser validation, redirects and server errors intact.
  function setBusy(form) {
    $$('button[type="submit"]', form).forEach(button => {
      button.dataset.originalHtml ??= button.innerHTML;
      button.disabled = true;
      button.classList.add('is-busy');
      button.setAttribute('aria-busy', 'true');
      if (button.dataset.busyLabel) button.textContent = button.dataset.busyLabel;
    });
    form.dataset.submitting = 'true';
  }
  function clearBusy(form) {
    $$('button[type="submit"]', form).forEach(button => {
      button.disabled = false;
      button.classList.remove('is-busy');
      button.removeAttribute('aria-busy');
      if (button.dataset.originalHtml) button.innerHTML = button.dataset.originalHtml;
    });
    delete form.dataset.submitting;
  }
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    if (form.matches('[data-upload-form]') && !validateUpload()) { event.preventDefault(); fileInput?.reportValidity(); return; }
    if (form.matches('[data-confirm-form]')) {
      const input = $('[name="confirmation"]', form);
      if (input.value !== form.dataset.confirmFilename) {
        event.preventDefault();
        input.setCustomValidity('请输入完整且完全一致的文件名');
        input.reportValidity();
        input.addEventListener('input', () => input.setCustomValidity(''), { once: true });
        return;
      }
    }
    if (form.dataset.submitting) { event.preventDefault(); return; }
    // Attachments do not navigate away. Avoid leaving their buttons permanently disabled.
    const attachment = form.matches('[data-download-form]') || /\/d\/[^/]+\/unlock$/.test(new URL(form.action).pathname);
    setBusy(form);
    if (attachment) setTimeout(() => clearBusy(form), 1400);
  });
  window.addEventListener('pageshow', () => $$('form[data-submitting]').forEach(clearBusy));
  document.addEventListener('click', event => {
    $$('.date-picker[open]').forEach(details => { if (!details.contains(event.target)) details.open = false; });
  });
})();
