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

    let res;
    try {
      res = await fetch(`${apiUrl}/api/extension/jobs?${params}`, {
        headers: { Accept: 'application/json' },
      });
    } catch {
      scheduleNext(SLEEP_ERROR);
      return;
    }
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

    const jobs = body.jobs ?? [];
    console.log('[Worker] Jobs:', jobs);
    if (!jobs.length) {
      scheduleNext(SLEEP_EMPTY);
      return;
    }

    const target = await getAffiliateTab();
    if (!target) {
      console.log("[Worker] Không tìm thấy tab Shopee");
      scheduleNext(SLEEP_EMPTY);
      return;
    }

    let response;
    try {
      console.log('[Worker] Sending to content script...');
      response = await chrome.tabs.sendMessage(target.id, {
        action: "process",
        urls: jobs,
      });
      console.log('[Worker] Response:', response);
    } catch (e) {
      console.error("[Worker] sendMessage error:", e);
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

    try {
      await fetch(`${apiUrl}/api/extension/results?${params}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ results }),
      });
    } catch {
      // silent
    }

    scheduleNext(SLEEP_DONE);
  } catch (e) {
    console.error('[Worker] poll() unexpected error:', e);
    scheduleNext(SLEEP_EMPTY);
  }
}
console.log('[Worker] Loaded');
scheduleNext(0);
