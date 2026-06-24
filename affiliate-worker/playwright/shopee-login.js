const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const STATE_FILE = path.resolve(__dirname, '..', 'storage', 'shopee-state.json');
const SHOPEE_AFFILIATE_URL = 'https://affiliate.shopee.vn';
const POLL_INTERVAL = 2000;
const LOGIN_TIMEOUT = 600_000;

fs.mkdirSync(path.dirname(STATE_FILE), { recursive: true });
console.log('Session file:', STATE_FILE);

async function loginShopee() {
  const browser = await chromium.launch({ headless: false, args: ['--start-maximized'] });
  const context = await browser.newContext({ viewport: null });
  const page = await context.newPage();

  try {
    await page.goto(SHOPEE_AFFILIATE_URL, { waitUntil: 'networkidle', timeout: 30000 });

    const hasLoginForm = await page.evaluate(() => {
      return !!document.querySelector('input[name="loginKey"], input[name="username"]');
    });

    if (hasLoginForm) {
      return { success: false, action: 'manual_login_required' };
    }

    await context.storageState({ path: STATE_FILE });
    return { success: true };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await browser.close();
  }
}

async function loginShopeeInteractive() {
  const browser = await chromium.launch({ headless: false, args: ['--start-maximized'] });
  const context = await browser.newContext({ viewport: null });
  const page = await context.newPage();

  await page.goto(SHOPEE_AFFILIATE_URL, { waitUntil: 'networkidle', timeout: 30000 });

  await page.waitForTimeout(5000);

  console.log('Waiting for manual login...');

  const startTime = Date.now();
  let userStartedLogin = false;

  try {
    while (Date.now() - startTime < LOGIN_TIMEOUT) {
      await page.waitForTimeout(POLL_INTERVAL);

      const hasPasswordField = await page.evaluate(() => {
        return !!document.querySelector('input[type="password"]');
      });

      if (!userStartedLogin && hasPasswordField) {
        userStartedLogin = true;
      }

      if (userStartedLogin && !hasPasswordField) {
        await context.storageState({ path: STATE_FILE });
        console.log('Session saved');
        return { success: true, message: 'Session saved' };
      }
    }

    return { success: false, error: 'Login timeout after 10 minutes' };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await browser.close();
  }
}

module.exports = { loginShopee, loginShopeeInteractive };
