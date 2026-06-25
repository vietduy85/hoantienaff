const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const STATE_FILE = path.resolve(__dirname, '..', 'storage', 'shopee-state.json');

async function testDashboardAccess() {
  if (!fs.existsSync(STATE_FILE)) {
    return { success: false, error: 'Session not found' };
  }

  const browser = await chromium.launch({ headless: false });
  const context = await browser.newContext({ storageState: STATE_FILE });
  const page = await context.newPage();

  try {
    await page.goto('https://affiliate.shopee.vn', {
      waitUntil: 'networkidle',
      timeout: 30000,
    });

    const currentUrl = page.url();

    if (currentUrl.includes('verify/captcha')) {
      return { success: false, message: 'CAPTCHA_REQUIRED', url: currentUrl };
    }

    if (currentUrl.includes('login')) {
      return { success: false, message: 'LOGIN_REQUIRED', url: currentUrl };
    }

    const pageTitle = await page.title();

    await page.waitForTimeout(5000);

    return {
      success: true,
      message: 'Dashboard accessible',
      url: currentUrl,
      title: pageTitle,
    };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await browser.close();
  }
}

module.exports = { testDashboardAccess };
