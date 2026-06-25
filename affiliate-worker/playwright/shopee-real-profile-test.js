const { chromium } = require('playwright');
const path = require('path');

const SCREENSHOT_FILE = path.resolve(__dirname, '..', 'storage', 'real-profile-test.png');
const SHOPEE_AFFILIATE_URL = 'https://affiliate.shopee.vn';
const USER_DATA_DIR = 'C:\\Users\\Administrator\\AppData\\Local\\Google\\Chrome\\User Data';
const PROFILE_DIR = 'Profile 4';

async function testRealProfile() {
  const context = await chromium.launchPersistentContext(USER_DATA_DIR, {
    channel: 'chrome',
    headless: false,
    args: [`--profile-directory=${PROFILE_DIR}`],
  });

  const page = context.pages()[0] || await context.newPage();

  try {
    await page.goto(SHOPEE_AFFILIATE_URL, {
      waitUntil: 'networkidle',
      timeout: 30000,
    });

    const currentUrl = page.url();
    const pageTitle = await page.title();

    await page.screenshot({ path: SCREENSHOT_FILE, fullPage: true });

    return { success: true, url: currentUrl, title: pageTitle };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await context.close();
  }
}

module.exports = { testRealProfile };
