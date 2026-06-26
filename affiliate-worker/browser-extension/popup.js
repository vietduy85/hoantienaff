const $ = (id) => document.getElementById(id);
const log = (msg) => {
  const el = $('log');
  el.textContent = `${new Date().toLocaleTimeString()} ${msg}\n${el.textContent}`.slice(0, 2000);
};

async function refresh() {
  const { apiUrl, token, enabled } = await chrome.storage.sync.get(['apiUrl', 'token', 'enabled']);
  $('apiUrl').value = apiUrl || 'http://hoantien.xyz';
  $('token').value = token || '';

  if (enabled === false) {
    $('status').textContent = '⏸ Tạm dừng';
    $('status').className = 'value err';
    $('toggleBtn').textContent = '🟢 Bật';
  }

  const tabs = await chrome.tabs.query({ url: 'https://affiliate.shopee.vn/offer/custom_link*' });
  $('tabStatus').textContent = tabs.length ? `✅ ${tabs.length} tab` : '❌ Không có';
  $('tabStatus').className = tabs.length ? 'value ok' : 'value err';
}

$('saveBtn').onclick = async () => {
  const apiUrl = $('apiUrl').value.trim();
  const token = $('token').value.trim();
  if (!apiUrl) return;
  await chrome.storage.sync.set({ apiUrl, token });
  log(`✅ Đã lưu cấu hình`);
};

$('toggleBtn').onclick = async () => {
  const { enabled } = await chrome.storage.sync.get('enabled');
  const newVal = enabled === false ? true : false;
  await chrome.storage.sync.set({ enabled: newVal });
  chrome.runtime.sendMessage({ action: 'setEnabled', enabled: newVal });
  refresh();
  log(newVal ? '▶ Đã bật polling' : '⏸ Đã tắt polling');
};

$('pollBtn').onclick = async () => {
  log('🔄 Đang poll…');
  $('pollBtn').disabled = true;
  const res = await chrome.runtime.sendMessage({ action: 'manualPoll' });
  $('pollBtn').disabled = false;
  if (res?.ok) {
    log(`✅ OK · ${res.jobs ?? res.results?.length ?? 'xong'} jobs`);
  } else {
    log(`❌ ${res?.error || 'Lỗi không xác định'}`);
  }
  refresh();
};

document.addEventListener('DOMContentLoaded', refresh);
