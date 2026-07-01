const SLEEP_EMPTY = 3000;
const SLEEP_ERROR = 5000;
const SLEEP_DONE = 1000;

let cachedTabId = null;

chrome.tabs.onRemoved.addListener((tabId) => {
  if (cachedTabId === tabId) {
    console.log('[BG] Affiliate tab removed, cache cleared');
    cachedTabId = null;
  }
});

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (cachedTabId === tabId) {
    const url = changeInfo.url || tab.url;
    if (url && !url.startsWith('https://affiliate.shopee.vn/')) {
      console.log('[BG] Affiliate tab navigated away, cache cleared');
      cachedTabId = null;
    }
  }
});

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
        console.log('[BG] Using cached tab id=' + cachedTabId);
        return tab;
      }
    } catch {
      // tab not found
    }
    console.log('[BG] Cached tab invalid, rediscover...');
    cachedTabId = null;
  }

  const tabs = await chrome.tabs.query({ url: 'https://affiliate.shopee.vn/*' });
  const target = tabs[0];
  if (target) {
    cachedTabId = target.id;
    console.log('[BG] Affiliate tab discovered id=' + target.id);
    return target;
  }

  console.log('[BG] Affiliate tab not found');
  return null;
}

function scheduleNext(delay) {
  setTimeout(() => { poll(); }, delay);
}

async function poll() {
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

  const body = await res.json();
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
    console.log("Before sendMessage");

try {
    response = await chrome.tabs.sendMessage(
        target.id,
        {
            action: "process",
            urls: jobs
        }
    );

    console.log("After sendMessage");
    console.log(response);

} catch (e) {
    console.error("sendMessage error:", e);
    cachedTabId = null;
}
console.log('[Worker] Response:', response);
  } catch {
    scheduleNext(SLEEP_EMPTY);
    return;
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
}
console.log('[Worker] Loaded');
scheduleNext(0);
