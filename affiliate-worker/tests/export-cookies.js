const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const COOKIE_FILE = path.resolve(__dirname, '..', 'storage', 'shopee-cookies.json');
const USER_DATA_DIR = 'C:\\Users\\Administrator\\AppData\\Local\\Google\\Chrome\\User Data';
const PROFILE_DIR = 'Profile 4';

async function exportCookies() {
  const context = await chromium.launchPersistentContext(USER_DATA_DIR, {
    channel: 'chrome',
    headless: false,
    args: [`--profile-directory=${PROFILE_DIR}`],
  });

  const page = context.pages()[0] || await context.newPage();

  try {
    await page.goto('https://affiliate.shopee.vn', {
      waitUntil: 'networkidle',
      timeout: 30000,
    });

    const cookies = await context.cookies();
    const shopeeCookies = cookies.filter(c =>
      c.domain.includes('shopee.vn') || c.domain.includes('affiliate.shopee.vn')
    );

    fs.writeFileSync(COOKIE_FILE, JSON.stringify(shopeeCookies, null, 2));
    console.log('Cookies saved:', COOKIE_FILE);

    const cookieString = shopeeCookies.map(c => `${c.name}=${c.value}`).join('; ');
    console.log('Cookie string:', cookieString.slice(0, 200) + '...');

    return { success: true, count: shopeeCookies.length };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await context.close();
  }
}

module.exports = { exportCookies };
