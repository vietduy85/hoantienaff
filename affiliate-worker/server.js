const express = require('express');
const cors = require('cors');

const app = express();
const PORT = 3001;

app.use(cors());
app.use(express.json());

app.get('/health', (_req, res) => {
  res.json({
    success: true,
    service: 'affiliate-worker',
    version: '1.0',
  });
});

app.get('/playwright-test', async (_req, res) => {
  const { runBrowserTest } = require('./playwright/browser-test');

  try {
    const result = await runBrowserTest();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.get('/shopee/session-test', async (_req, res) => {
  const { testShopeeSession } = require('./playwright/shopee-session-test');

  try {
    const result = await testShopeeSession();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/shopee-login', async (req, res) => {
  const { loginShopee } = require('./playwright/shopee-login');

  try {
    const result = await loginShopee();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/shopee-login-interactive', async (_req, res) => {
  const { loginShopeeInteractive } = require('./playwright/shopee-login');

  try {
    const result = await loginShopeeInteractive();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/create-link', (req, res) => {
  const { url } = req.body;

  if (!url) {
    return res.status(400).json({
      success: false,
      error: 'Missing url in request body',
    });
  }

  const platform = detectPlatform(url);

  if (platform === 'unknown') {
    return res.status(400).json({
      success: false,
      error: `Unsupported platform for URL: ${url}`,
    });
  }

  const cashbackMap = {
    shopee: 15000,
    lazada: 12000,
    tiktok: 18000,
    longchau: 8000,
    pharmacity: 7000,
    traveloka: 25000,
    agoda: 30000,
    booking: 35000,
  };

  res.json({
    success: true,
    affiliate_url: `https://${platform}.vn/affiliate/${encodeURIComponent(url)}`,
    estimated_cashback: cashbackMap[platform] ?? 0,
    platform,
  });
});

function detectPlatform(url) {
  const lower = url.toLowerCase();
  const rules = [
    'booking',
    'agoda',
    'traveloka',
    'pharmacity',
    'nhathuoclongchau',
    'longchau',
    'tiktok',
    'lazada',
    'shopee',
  ];

  for (const keyword of rules) {
    if (lower.includes(keyword)) {
      if (keyword === 'nhathuoclongchau' || keyword === 'longchau') return 'longchau';
      return keyword;
    }
  }

  return 'unknown';
}

app.listen(PORT, () => {
  console.log(`[affiliate-worker] running on port ${PORT}`);
});
