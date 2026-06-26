const $ = (id) => document.getElementById(id);
const log = (msg) => {
  const el = $('log');
  el.textContent = `${new Date().toLocaleTimeString()} ${msg}\n${el.textContent}`.slice(0, 2000);
};

async function refresh() {
  const { apiUrl, token } = await chrome.storage.sync.get(['apiUrl', 'token']);
  $('apiUrl').value = apiUrl || 'http://hoantien.xyz';
  $('token').value = token || '';

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

document.addEventListener('DOMContentLoaded', refresh);
