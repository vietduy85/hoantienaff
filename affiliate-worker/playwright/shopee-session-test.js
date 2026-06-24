const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const STATE_FILE = path.resolve(__dirname, '..', 'storage', 'shopee-state.json');

async function testShopeeSession() {
  if (!fs.existsSync(STATE_FILE)) {
    return { success: false, error: 'Session not found' };
  }

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ storageState: STATE_FILE });
  const page = await context.newPage();

  try {
    await page.goto('https://affiliate.shopee.vn', {
      waitUntil: 'networkidle',
      timeout: 30000,
    });

    const hasPasswordField = await page.evaluate(() => {
      return !!document.querySelector('input[type="password"]');
    });

    if (hasPasswordField) {
      return { success: false, message: 'Session expired' };
    }

    return { success: true, message: 'Session valid' };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await browser.close();
  }
}

module.exports = { testShopeeSession };
