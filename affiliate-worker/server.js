const fs = require('fs');
const path = require('path');

// Load AFFILIATE_TIMING from Laravel .env
try {
  const envPath = path.resolve(__dirname, '..', '.env');
  const envContent = fs.readFileSync(envPath, 'utf-8');
  const match = envContent.match(/^AFFILIATE_TIMING=(.+)$/m);
  if (match) {
    process.env.AFFILIATE_TIMING = match[1].trim();
  }
} catch {}

const express = require('express');
const cors = require('cors');

const app = express();
const PORT = 3001;

app.use(cors());
app.use(express.json());

app.get('/diagnostic/profile', async (_req, res) => {
  const { runDiagnostic } = require('./tests/profile-diagnostic');

  try {
    const result = await runDiagnostic();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message, stack: err.stack });
  }
});

app.get('/diagnostic/cdp', async (_req, res) => {
  const AffiliateWorker = require('./playwright/cdp/AffiliateWorker');

  try {
    const result = await AffiliateWorker.diagnostic();
    res.json(result);
  } catch (err) {
    res.status(500).json({ connected: false, error: err.message, stack: err.stack });
  }
});

app.get('/diagnostic/sidebar', async (_req, res) => {
  const Inspector = require('./playwright/cdp/SidebarInspector');

  try {
    const result = await Inspector.inspect();
    res.json({ success: true, ...result });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message, stack: err.stack });
  }
});

app.get('/diagnostic/selectors', async (_req, res) => {
  const Inspector = require('./playwright/cdp/SidebarInspector');

  try {
    const result = await Inspector.testSelectors();
    res.json({ success: true, ...result });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message, stack: err.stack });
  }
});

app.get('/diagnostic/custom-link', async (_req, res) => {
  const AffiliateWorker = require('./playwright/cdp/AffiliateWorker');

  try {
    const result = await AffiliateWorker.diagnosticCustomLink();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message, stack: err.stack });
  }
});

app.post('/shopee/create-link', async (req, res) => {
console.log("############################");
console.log("CREATE LINK REQUEST");
console.log(new Date().toISOString());
console.log(req.body);
console.log("############################");
  const queue = require('./playwright/cdp/Queue');
  const CustomLinkWorker = require('./playwright/cdp/CustomLinkWorker');
  const { url } = req.body;

  if (!url) {
    return res.status(400).json({ success: false, error: 'Missing url in request body' });
  }

  try {
    const result = await queue.enqueue(() => CustomLinkWorker.createAffiliateLink(url));
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message, stack: err.stack });
  }
});

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

app.post('/shopee/cdp-graphql-test', async (req, res) => {
  const { cdpGraphqlTest } = require('./tests/cdp-graphql-test');
  const { url } = req.body;

  try {
    const result = await cdpGraphqlTest(url);
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/shopee/real-profile-graphql', async (req, res) => {
  const { realProfileGraphql } = require('./tests/real-profile-graphql');
  const { url } = req.body;

  try {
    const result = await realProfileGraphql(url);
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/shopee/browser-graphql-test', async (req, res) => {
  const { browserGraphqlTest } = require('./tests/browser-graphql-test');
  const { url } = req.body;

  try {
    const result = await browserGraphqlTest(url);
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/shopee/export-cookies', async (_req, res) => {
  const { exportCookies } = require('./tests/export-cookies');

  try {
    const result = await exportCookies();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/shopee/direct-api-cookie-test', async (req, res) => {
  const { batchCustomLinkWithCookies } = require('./tests/batch-custom-link');
  const { url } = req.body;

  if (!url) {
    return res.status(400).json({ success: false, error: 'Missing url in request body' });
  }

  try {
    const result = await batchCustomLinkWithCookies(url);
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/shopee/direct-api-test', async (req, res) => {
  const { batchCustomLink } = require('./tests/batch-custom-link');
  const { url } = req.body;

  if (!url) {
    return res.status(400).json({ success: false, error: 'Missing url in request body' });
  }

  try {
    const result = await batchCustomLink(url);
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/shopee/custom-link-stealth-test', async (_req, res) => {
  const { testCustomLinkStealth } = require('./playwright/shopee-create-link');

  try {
    const result = await testCustomLinkStealth();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/shopee/custom-link-test', async (_req, res) => {
  const { testCustomLink } = require('./playwright/shopee-create-link');

  try {
    const result = await testCustomLink();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/shopee/create-link-test', async (req, res) => {
  const { openCreateLink } = require('./playwright/shopee-create-link');
  const { url } = req.body;

  try {
    const result = await openCreateLink(url || '');
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.get('/shopee/real-profile-test', async (_req, res) => {
  const { testRealProfile } = require('./playwright/shopee-real-profile-test');

  try {
    const result = await testRealProfile();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.get('/shopee/profile-open', async (_req, res) => {
  const { openProfile } = require('./playwright/shopee-profile-test');

  try {
    const result = await openProfile();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.get('/shopee/profile-test', async (_req, res) => {
  const { testProfileAccess } = require('./playwright/shopee-profile-test');

  try {
    const result = await testProfileAccess();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.get('/shopee/dashboard-test', async (_req, res) => {
  const { testDashboardAccess } = require('./playwright/shopee-dashboard-test');

  try {
    const result = await testDashboardAccess();
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

// DEPRECATED: kept for backward compatibility.
// Use POST /shopee/create-link for the real Playwright-based implementation.
app.post('/create-link', async (req, res) => {
  const { url } = req.body;

  if (!url) {
    return res.status(400).json({
      success: false,
      error: 'Missing url in request body',
    });
  }

  try {
    // Forward to real endpoint
    const result = await new Promise((resolve, reject) => {
      const queue = require('./playwright/cdp/Queue');
      const CustomLinkWorker = require('./playwright/cdp/CustomLinkWorker');

      queue.enqueue(() => CustomLinkWorker.createAffiliateLink(url))
        .then(resolve)
        .catch(reject);
    });

    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.listen(PORT, () => {
  console.log(`[affiliate-worker] running on port ${PORT}`);
});
