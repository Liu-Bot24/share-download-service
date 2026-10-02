(() => {
  'use strict';
  const root = document.querySelector('[data-guest-page], [data-receive-window]');
  if (!root) return;
  const form = document.querySelector('[data-guest-upload-form]');
  const submit = document.querySelector('[data-guest-submit]');
  const error = document.querySelector('[data-guest-error]');
  const receipts = document.querySelector('[data-guest-receipts]');
  const progress = document.querySelector('[data-guest-progress]');
  let deadline = Number(root.dataset.expires) * 1000;
  let offset = Number(root.dataset.serverTime) * 1000 - Date.now();
  let pending = false;
  function closed() {
    deadline = 0;
    document.querySelector('[data-window-label]').textContent = '收件窗口已关闭';
    document.querySelector('[data-window-countdown]').textContent = form ? '请联系接收方重新开启，并获取新的上传链接。' : '填写启用时长，点击开启即可开始收件。';
    document.querySelector('.receive-dot')?.classList.remove('is-open');
    if (submit) submit.disabled = true;
    const link = document.querySelector('[data-active-receive-link]');
    if (link) link.hidden = true;
  }
  function tick() {
    const left = Math.ceil((deadline - Date.now() - offset) / 1000);
    if (left <= 0) { closed(); return; }
    document.querySelector('[data-window-countdown]').textContent = `剩余 ${Math.floor(left / 60)} 分 ${String(left % 60).padStart(2, '0')} 秒 · 结束后自动关闭`;
  }
  tick();
  setInterval(tick, 1000);
  if (root.dataset.statusUrl && deadline) {
    const poll = setInterval(async () => {
      try {
        const response = await fetch(root.dataset.statusUrl, {cache: 'no-store', credentials: 'omit'});
        const status = await response.json();
        if (!status.active) { closed(); clearInterval(poll); return; }
        deadline = status.expires_at * 1000;
        offset = status.server_time * 1000 - Date.now();
        tick();
      } catch { /* The authoritative upload request still checks expiry; retain the countdown. */ }
    }, 5000);
  }
  form?.addEventListener('submit', event => {
    event.preventDefault();
    if (pending) return;
    error.hidden = true;
    const file = document.querySelector('[data-guest-file]').files[0];
    if (!file || file.size > 45 * 1024 * 1024 || Date.now() + offset >= deadline) {
      error.textContent = file?.size > 45 * 1024 * 1024 ? '单个文件不能超过 45 MiB。' : '请选择文件，并确认收件窗口仍在开启中。';
      error.hidden = false; return;
    }
    pending = true; submit.disabled = true; submit.textContent = '正在上传…';
    progress.hidden = false; progress.querySelector('progress').value = 0;
    const text = progress.querySelector('[data-guest-progress-text]');
    text.textContent = '正在发送文件…';
    const request = new XMLHttpRequest();
    request.open('POST', form.action);
    request.setRequestHeader('Accept', 'application/json');
    request.timeout = 600000;
    request.upload.onprogress = event => {
      if (event.lengthComputable) {
        const percent = Math.min(100, Math.floor(event.loaded / event.total * 100));
        progress.querySelector('progress').value = percent;
        text.textContent = percent === 100 ? '发送完成，等待接收方确认保存…' : `正在发送 ${percent}%`;
      }
    };
    function finish(message) {
      pending = false; submit.disabled = Date.now() + offset >= deadline;
      submit.textContent = '上传给接收方';
      if (message) { error.textContent = message; error.hidden = false; text.textContent = '未确认保存'; }
    }
    request.onload = () => {
      let response;
      try { response = JSON.parse(request.responseText); } catch { finish('服务器未返回接收确认，请联系接收方核对后再重试。'); return; }
      if (request.status === 201 && response.ok === true) {
        const item = document.createElement('p'); item.textContent = `已收到：${response.name}。接收方可在后台下载。`; receipts.prepend(item);
        text.textContent = '已确认保存'; form.reset(); finish();
      } else { if (request.status === 410) closed(); finish(response.message || '上传未完成，请稍后重试。'); }
    };
    request.onerror = request.ontimeout = () => finish('连接中断，无法确认是否保存；请让接收方核对后再重试。');
    request.send(new FormData(form));
  });
})();
