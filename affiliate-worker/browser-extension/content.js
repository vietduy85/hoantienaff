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

  const findSubIdInput = (ta) => {
    const container = ta.closest('.ant-form') || ta.parentElement;
    const inputs = [...container.querySelectorAll('input.ant-input')].filter(
      (el) => !el.closest('.ant-modal')
    );
    if (inputs.length === 0) return null;
    if (inputs.length === 1) return inputs[0];
    let el = ta.nextElementSibling;
    while (el) {
      if (el instanceof HTMLInputElement && el.matches('input.ant-input')) return el;
      el = el.nextElementSibling;
    }
    return inputs[0];
  };

  const resultTextarea = () => {
    const modal = [...document.querySelectorAll('.ant-modal')].find(
      (m) => getComputedStyle(m).display !== 'none' && getComputedStyle(m).visibility !== 'hidden'
    );
    return modal ? modal.querySelector('textarea') : null;
  };

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

  const setReactInputValue = (el, value) => {
    if (!(el instanceof HTMLInputElement)) return;
    const setter = Object.getOwnPropertyDescriptor(
      window.HTMLInputElement.prototype,
      'value'
    ).set;
    setter.call(el, value);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
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

  const waitForResult = async (prevValue, timeout = 18000) => {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
      if (isCaptcha()) throw new Error('CAPTCHA');
      const m = resultTextarea();
      if (m && m.value && m.value.trim() && m.value !== prevValue) return m.value;
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

  async function processBatch(urls, username) {
    console.log('[CONTENT-BATCH] start, urls:', urls);

    console.log('[CONTENT-BATCH] closeModals...');
    closeModals();

    console.log('[CONTENT-BATCH] waitForMainTextarea...');
    const ta = await waitForMainTextarea();
    console.log('[CONTENT-BATCH] mainTextarea found:', !!ta, ta?.id || ta?.className || '');

    console.log('[CONTENT-BATCH] setReactValue with:', urls.join('\\n'));
    setReactValue(ta, urls.join('\n'));
    console.log('[CONTENT-BATCH] setReactValue done, now value=', (ta?.value || '').substring(0, 60).replace(/\n/g, '\\n'));

    console.log('[CONTENT-BATCH] findSubIdInput...');
    const subIdInput = findSubIdInput(ta);
    console.log('[CONTENT-BATCH] subIdInput found:', !!subIdInput);
    if (subIdInput && username) {
      console.log('[CONTENT-BATCH] setReactInputValue with username:', username);
      setReactInputValue(subIdInput, username);
      console.log('[CONTENT-BATCH] subIdInput value after set:', subIdInput.value);
    }

    console.log('[CONTENT-BATCH] get prevResult...');
    const prevResult = (resultTextarea()?.value || '');
    console.log('[CONTENT-BATCH] prevResult:', prevResult ? prevResult.substring(0, 40) : '(empty)');

    console.log('[CONTENT-BATCH] waitForButtonReady...');
    const btn = await waitForButtonReady();
    console.log('[CONTENT-BATCH] button found:', !!btn, btn?.innerText?.trim());

    console.log('[CONTENT-BATCH] clicking button...');
    btn.click();
    console.log('[CONTENT-BATCH] clicked, waiting for result...');

    console.log('[CONTENT-BATCH] waitForResult...');
    const raw = await waitForResult(prevResult);
    console.log('[CONTENT-BATCH] result raw:', (raw || '').substring(0, 100).replace(/\n/g, '\\n'));

    console.log('[CONTENT-BATCH] parsing links...');
    const links = raw
      .split('\n')
      .map((s) => s.trim())
      .filter(Boolean);
    console.log('[CONTENT-BATCH] parsed links count:', links.length);

    console.log('[CONTENT-BATCH] closeModals...');
    closeModals();
    console.log('[CONTENT-BATCH] returning ' + urls.length + ' results');

    return urls.map((_, i) => links[i] ?? '');
  }

  async function processAll(urls, onProgress, username) {
    const results = [];
    const batches = chunk(urls, BATCH_SIZE);
    for (let b = 0; b < batches.length; b++) {
      onProgress(`Lô ${b + 1}/${batches.length}…`);
      const shorts = await processBatch(batches[b], username);
      batches[b].forEach((u, i) => results.push({ url: u, short: shorts[i] }));
      onProgress(`Lô ${b + 1}/${batches.length} xong (${results.length}/${urls.length})`);
      if (b < batches.length - 1) await sleep(rnd(MIN_DELAY, MAX_DELAY));
    }
    return results;
  }

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    console.log('[CONTENT] onMessage fired');
    console.log('[CONTENT] msg.action:', msg.action);
    console.log('[CONTENT] msg.urls count:', msg.urls?.length);
    console.log('[CONTENT] msg.urls[0]:', msg.urls?.[0] ? JSON.stringify({ id: msg.urls[0].id, original_url: (msg.urls[0].original_url || '').substring(0, 60), username: msg.urls[0].username }) : 'undefined');
    console.log('[CONTENT] _sender.tab?.id:', _sender.tab?.id);
    console.log('[CONTENT] _sender.tab?.url:', _sender.tab?.url);

    if (msg.action !== 'process') {
      console.log('[CONTENT] wrong action, returning');
      return;
    }

    const urls = (msg.urls || []).map((j) => j.original_url ?? j.url ?? j);
    console.log('[CONTENT] extracted urls:', urls);

    console.log('[CONTENT] calling processAll...');
    processAll(urls, (progress) => { console.log('[CONTENT] progress:', progress); }, msg.urls[0]?.username || '')
      .then((raw) => {
        console.log('[CONTENT] processAll resolved, raw results:', raw);
        const results = raw.map((r, i) => {
          const job = msg.urls[i];
          return {
            id: job?.id ?? null,
            original_url: r.url,
            affiliate_url: r.short || '',
          };
        });
        console.log('[CONTENT] mapped results:', results.map(r => ({ id: r.id, affiliate_url: (r.affiliate_url || '').substring(0, 40) })));
        console.log('[CONTENT] calling sendResponse({ok:true})');
        sendResponse({ ok: true, results });
      })
      .catch((e) => {
        console.error('[CONTENT] processAll rejected:', e.message);
        console.error('[CONTENT] processAll stack:', e.stack);
        console.log('[CONTENT] calling sendResponse({ok:false})');
        sendResponse({ ok: false, error: e.message, results: [] });
      });

    return true;
  });
})();
