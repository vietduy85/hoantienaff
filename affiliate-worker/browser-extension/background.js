const SLEEP_EMPTY = 3000;
const SLEEP_ERROR = 5000;
const SLEEP_DONE = 1000;

let cachedTabId = null;

chrome.runtime.onInstalled.addListener(async () => {
  const { apiUrl, token } = await chrome.storage.sync.get(['apiUrl', 'token']);
  const updates = {};
  if (!apiUrl) updates.apiUrl = 'http://hoantien.xyz';
  if (!token) updates.token = 'hoantien-affiliate-extension-2026';
  if (Object.keys(updates).length) await chrome.storage.sync.set(updates);
  scheduleNext(0);
});
chrome.runtime.onStartup.addListener(() => {
  console.log('[Worker] onStartup');
  scheduleNext(0);
});

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg.action === 'getStatus') {
    sendResponse({ ok: true });
    return false;
  }
});

async function getAffiliateTab() {
  if (cachedTabId != null) {
    try {
      const tab = await chrome.tabs.get(cachedTabId);
      if (tab && tab.url && tab.url.startsWith('https://affiliate.shopee.vn/')) {
        console.log('[BG] Use cached tabId=' + cachedTabId);
        return tab;
      }
    } catch {
      // tab not found
    }
    console.log('[BG] Cached tab invalid');
    cachedTabId = null;
  }

  console.log('[BG] Query affiliate tab...');
  const tabs = await chrome.tabs.query({ url: 'https://affiliate.shopee.vn/*' });
  const target = tabs[0];
  if (target) {
    cachedTabId = target.id;
    console.log('[BG] Cache tabId=' + target.id);
    return target;
  }

  console.log('[BG] Rediscover affiliate tab');
  return null;
}

function scheduleNext(delay) {
  setTimeout(() => { poll(); }, delay);
}

async function poll() {
  console.log('[DEBUG] poll() started');
  try {
    const { apiUrl, token, enabled } = await chrome.storage.sync.get(['apiUrl', 'token', 'enabled']);
    if (enabled === false) {
      scheduleNext(SLEEP_EMPTY);
      return;
    }
    if (!apiUrl) {
      scheduleNext(SLEEP_EMPTY);
      return;
    }

    const params = new URLSearchParams({ token: token || '' });
    const fullUrl = `${apiUrl}/api/extension/jobs?${params}`;

    // [DEBUG] Log URL details before fetch
    console.log('[DEBUG-JOBS] apiUrl:', apiUrl);
    console.log('[DEBUG-JOBS] token:', token);
    console.log('[DEBUG-JOBS] fullUrl:', fullUrl);

    let res;
    try {
      res = await fetch(fullUrl, {
        headers: { Accept: 'application/json' },
      });
    } catch (e) {
      console.error('[DEBUG-JOBS] fetch error:', e.message);
      console.error('[DEBUG-JOBS] fetch stack:', e.stack);
      if (e.message.includes('Failed to fetch')) {
        console.error('[DEBUG-JOBS] => TYPE: Failed to fetch (CORS / network / DNS)');
      } else if (e.message.includes('ERR_CONNECTION_REFUSED')) {
        console.error('[DEBUG-JOBS] => TYPE: Connection refused');
      } else if (e.message.includes('ERR_CERT')) {
        console.error('[DEBUG-JOBS] => TYPE: Certificate error');
      } else if (e.message.includes('ERR_NAME_NOT_RESOLVED') || e.message.includes('ENOTFOUND')) {
        console.error('[DEBUG-JOBS] => TYPE: DNS resolution failed');
      } else if (e.message.includes('timeout') || e.message.includes('TIMEOUT')) {
        console.error('[DEBUG-JOBS] => TYPE: Timeout');
      } else {
        console.error('[DEBUG-JOBS] => TYPE: Unknown');
      }
      scheduleNext(SLEEP_ERROR);
      return;
    }

    // [DEBUG] Log response status
    console.log('[DEBUG-JOBS] response status:', res.status);
    console.log('[DEBUG-JOBS] response statusText:', res.statusText);

    if (!res.ok) {
      scheduleNext(SLEEP_ERROR);
      return;
    }

    let body;
    try {
      body = await res.json();
    } catch {
      console.error('[Worker] Invalid JSON response');
      scheduleNext(SLEEP_ERROR);
      return;
    }

    // [DEBUG] Log response body
    console.log('[DEBUG-JOBS] response body:', JSON.stringify(body));

    const jobs = body.jobs ?? [];
    console.log('[Worker] Jobs:', jobs);
    if (!jobs.length) {
      scheduleNext(SLEEP_EMPTY);
      return;
    }

    console.log('[DEBUG] Found ' + jobs.length + ' jobs, looking for tab...');
    const target = await getAffiliateTab();
    if (!target) {
      console.log("[Worker] Không tìm thấy tab Shopee");
      scheduleNext(SLEEP_EMPTY);
      return;
    }
    console.log('[DEBUG] Tab found: id=' + target.id + ' url=' + target.url);

    console.log('[DEBUG-MSG] target.id:', target.id);
    console.log('[DEBUG-MSG] target.url:', target.url);
    console.log('[DEBUG-MSG] payload:', JSON.stringify({
      action: 'process',
      urls: jobs.map(j => ({ id: j.id, original_url: j.original_url?.substring(0,60), username: j.username }))
    }));

    let response;
    const sendStart = Date.now();
    try {
      console.log('[Worker] Sending to content script...');
      response = await chrome.tabs.sendMessage(target.id, {
        action: "process",
        urls: jobs,
      });
      const elapsed = Date.now() - sendStart;
      console.log('[DEBUG-MSG] sendMessage resolved in ' + elapsed + 'ms');
      console.log('[Worker] Response:', response);
      console.log('[DEBUG-MSG] response.ok:', response?.ok);
      console.log('[DEBUG-MSG] response.results count:', response?.results?.length);
    } catch (e) {
      const elapsed = Date.now() - sendStart;
      console.error('[DEBUG-MSG] sendMessage rejected in ' + elapsed + 'ms');
      console.error("[Worker] sendMessage error:", e);
      console.error('[DEBUG-MSG] error.name:', e.name);
      console.error('[DEBUG-MSG] error.message:', e.message);
      cachedTabId = null;
    }

    let results;
    if (response?.ok && response?.results?.length) {
      results = response.results;
    } else {
      results = jobs.map((j) => ({
        id: j.id,
        affiliate_url: '',
        status: 'failed',
      }));
    }

    // [DEBUG] Log each result before POST
    console.log('[DEBUG] About to POST results:');
    for (const r of results) {
      console.log(`[DEBUG]   id=${r.id} affiliate_url="${r.affiliate_url}" status=${r.status}`);
    }

    let postRes;
    try {
      postRes = await fetch(`${apiUrl}/api/extension/results?${params}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ results }),
      });
      console.log(`[DEBUG] POST /results HTTP ${postRes.status}`);
      try {
        const postBody = await postRes.json();
        console.log(`[DEBUG] POST /results body:`, JSON.stringify(postBody));
      } catch {
        console.log(`[DEBUG] POST /results body: (not JSON)`);
      }
    } catch (e) {
      console.error(`[DEBUG] POST /results fetch error:`, e.message);
      console.error(`[DEBUG] POST /results stack:`, e.stack);
    }

    scheduleNext(SLEEP_DONE);
  } catch (e) {
    console.error('[Worker] poll() unexpected error:', e);
    scheduleNext(SLEEP_EMPTY);
  }
}
console.log('[Worker] Loaded');
scheduleNext(0);
