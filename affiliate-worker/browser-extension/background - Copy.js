const SLEEP_EMPTY = 3000;
const SLEEP_ERROR = 5000;
const SLEEP_DONE = 1000;

chrome.runtime.onInstalled.addListener(async () => {
  const { apiUrl, token } = await chrome.storage.sync.get(['apiUrl', 'token']);
  const updates = {};
  if (!apiUrl) updates.apiUrl = 'http://hoantien.xyz';
  if (!token) updates.token = 'hoantien-affiliate-extension-2026';
  if (Object.keys(updates).length) await chrome.storage.sync.set(updates);
  scheduleNext(0);
});

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg.action === 'getStatus') {
    sendResponse({ ok: true });
    return false;
  }
});

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
  if (!jobs.length) {
    scheduleNext(SLEEP_EMPTY);
    return;
  }

  const tabs = await chrome.tabs.query({ url: 'https://affiliate.shopee.vn/offer/custom_link*' });
  if (!tabs.length) {
    scheduleNext(SLEEP_EMPTY);
    return;
  }

  let response;
  try {
    response = await chrome.tabs.sendMessage(tabs[0].id, { action: 'process', urls: jobs });
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
