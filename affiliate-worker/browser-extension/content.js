(function () {
  'use strict';

  if (window.__shopeeBulkLinkLoaded) return;
  window.__shopeeBulkLinkLoaded = true;

  const BATCH_SIZE = 5;
  const MIN_DELAY = 1500;
  const MAX_DELAY = 3200;
  const RESULT_TIMEOUT = 18000;

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const rnd = (a, b) => a + Math.floor(Math.random() * (b - a));
  const chunk = (arr, n) => {
    const out = [];
    for (let i = 0; i < arr.length; i += n) out.push(arr.slice(i, i + n));
    return out;
  };

  const mainTextarea = () =>
    [...document.querySelectorAll('textarea.ant-input')].find((t) => !t.closest('.ant-modal'));

  const getLinkButton = () =>
    [...document.querySelectorAll('button')].find((b) => b.innerText.trim().includes('Lấy link'));

  const resultTextarea = () => document.querySelector('.ant-modal textarea');

  const closeModals = () => {
    document.querySelectorAll('.ant-modal-close').forEach((b) => b.click());
  };

  const setReactValue = (el, value) => {
    const setter = Object.getOwnPropertyDescriptor(
      window.HTMLTextAreaElement.prototype,
      'value'
    ).set;
    setter.call(el, value);
    el.dispatchEvent(new Event('input', { bubbles: true }));
  };

  const isCaptcha = () => location.href.includes('verify/captcha');

  const waitForMainTextarea = async (timeout = 5000) => {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
      if (isCaptcha()) throw new Error('CAPTCHA');
      const ta = mainTextarea();
      if (ta) return ta;
      await sleep(50);
    }
    if (isCaptcha()) throw new Error('CAPTCHA');
    throw new Error('NO_FORM');
  };

  const waitForButtonReady = async (timeout = 3000) => {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
      const btn = getLinkButton();
      if (btn && !btn.disabled && !btn.classList.contains('ant-btn-disabled')) return btn;
      await sleep(50);
    }
    const btn = getLinkButton();
    if (btn) return btn;
    throw new Error('NO_BUTTON');
  };

  const waitForResult = async (timeout = 18000) => {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
      if (isCaptcha()) throw new Error('CAPTCHA');
      const m = resultTextarea();
      if (m && m.value && m.value.trim()) return m.value;
      await sleep(50);
    }
    if (isCaptcha()) throw new Error('CAPTCHA');
    throw new Error('TIMEOUT');
  };

  const waitForModalGone = async (timeout = 3000) => {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
      if (!document.querySelector('.ant-modal')) return;
      await sleep(50);
    }
  };

  async function processBatch(urls) {
    closeModals();
    const ta = await waitForMainTextarea();

    setReactValue(ta, urls.join('\n'));
    const btn = await waitForButtonReady();
    btn.click();

    const raw = await waitForResult();

    const links = raw
      .split('\n')
      .map((s) => s.trim())
      .filter(Boolean);

    closeModals();
    await waitForModalGone();

    return urls.map((_, i) => links[i] ?? '');
  }

  async function processAll(urls, onProgress) {
    const results = [];
    const batches = chunk(urls, BATCH_SIZE);
    for (let b = 0; b < batches.length; b++) {
      onProgress(`Lô ${b + 1}/${batches.length}…`);
      const shorts = await processBatch(batches[b]);
      batches[b].forEach((u, i) => results.push({ url: u, short: shorts[i] }));
      onProgress(`Lô ${b + 1}/${batches.length} xong (${results.length}/${urls.length})`);
      if (b < batches.length - 1) await sleep(rnd(MIN_DELAY, MAX_DELAY));
    }
    return results;
  }

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (msg.action !== 'process') return;

    const urls = (msg.urls || []).map((j) => j.original_url ?? j.url ?? j);

    processAll(urls, () => {})
      .then((raw) => {
        const results = raw.map((r, i) => {
          const job = msg.urls[i];
          return {
            id: job?.id ?? null,
            original_url: r.url,
            affiliate_url: r.short || '',
          };
        });
console.log("RETURNING RESULT TRUE");
        sendResponse({ ok: true, results });
      })
      .catch((e) => {
console.log("RETURNING RESULT FALSE");
        sendResponse({ ok: false, error: e.message, results: [] });
      });

    return true;
  });
})();
