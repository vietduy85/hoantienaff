const POLL_INTERVAL_MIN = 1;

let enabled = true;

chrome.runtime.onInstalled.addListener(async () => {
  const { apiUrl, token } = await chrome.storage.sync.get(['apiUrl', 'token']);
  const updates = {};
  if (!apiUrl) updates.apiUrl = 'http://hoantien.xyz';
  if (!token) updates.token = 'hoantien-affiliate-extension-2026';
  if (Object.keys(updates).length) await chrome.storage.sync.set(updates);
  createAlarm();
});

function createAlarm() {
  chrome.alarms.create('pollJobs', { periodInMinutes: POLL_INTERVAL_MIN });
}

chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (alarm.name !== 'pollJobs' || !enabled) return;
  await pollJobs();
});

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg.action === 'getStatus') {
    sendResponse({ enabled });
    return false;
  }
  if (msg.action === 'setEnabled') {
    enabled = msg.enabled;
    sendResponse({ enabled });
    return false;
  }
  if (msg.action === 'jobResult') {
    handleJobResult(msg.results).then(sendResponse).catch(sendResponse);
    return true;
  }
  if (msg.action === 'manualPoll') {
    pollJobs().then(sendResponse).catch(sendResponse);
    return true;
  }
});

async function pollJobs() {
  const { apiUrl } = await chrome.storage.sync.get('apiUrl');
  if (!apiUrl) return { ok: false, error: 'API URL chưa được cấu hình' };

  const { token } = await chrome.storage.sync.get('token');

  let res;
  try {
    const params = new URLSearchParams({ token: token || '' });
    res = await fetch(`${apiUrl}/api/affiliate/jobs?${params}`, {
      method: 'GET',
      headers: { Accept: 'application/json' },
    });
  } catch (e) {
    return { ok: false, error: `Không thể kết nối ${apiUrl}` };
  }
  if (!res.ok) return { ok: false, error: `API trả về ${res.status}` };

  const body = await res.json();
  const jobs = body.jobs ?? body.data ?? [];
  if (!jobs.length) return { ok: true, jobs: 0 };

  const tabs = await chrome.tabs.query({
    url: 'https://affiliate.shopee.vn/offer/custom_link*',
  });
  if (!tabs.length) {
    return { ok: false, error: 'Không tìm thấy tab Custom Link. Mở tab rồi thử lại.' };
  }

  return new Promise(async (resolve) => {

    try {

        await chrome.scripting.executeScript({
            target: { tabId: tabs[0].id },
            func: () => true
        });

    } catch (e) {
        resolve({
            ok:false,
            error:"Không inject được content script: " + e.message
        });
        return;
    }

    chrome.tabs.sendMessage(
        tabs[0].id,
        {
            action:"process",
            urls:jobs
        },
        response => {

            if(chrome.runtime.lastError){

                resolve({
                    ok:false,
                    error:chrome.runtime.lastError.message
                });

                return;
            }

            resolve(response);

        });

});
}

async function handleJobResult(results) {
  const { apiUrl, token } = await chrome.storage.sync.get(['apiUrl', 'token']);
  if (!apiUrl || !results?.length) return { ok: false };

  let res;
  try {
    const params = new URLSearchParams({ token: token || '' });
    res = await fetch(`${apiUrl}/api/affiliate/result?${params}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ results }),
    });
  } catch (e) {
    return { ok: false, error: `Lỗi gửi kết quả: ${e.message}` };
  }
  return { ok: res.ok };
}
