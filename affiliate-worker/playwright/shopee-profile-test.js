const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const PROFILE_DIR = path.resolve(__dirname, '..', 'storage', 'chrome-profile');
const SCREENSHOT_FILE = path.resolve(__dirname, '..', 'storage', 'profile-test.png');
const SHOPEE_AFFILIATE_URL = 'https://affiliate.shopee.vn';

function getLaunchOptions() {
  const options = { headless: false, viewport: null };
  const paths = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
  ];
  for (const p of paths) {
    if (fs.existsSync(p)) {
      options.executablePath = p;
      return options;
    }
  }
  options.channel = 'chrome';
  return options;
}

const LAUNCH_OPTIONS = getLaunchOptions();
const POLL_INTERVAL = 2000;
const LOGIN_TIMEOUT = 600_000;

async function isLandingPage(page) {
  return page.evaluate(() => {
    const text = document.body.innerText;
    if (text.includes('Nhập tại đây')) return true;
    if (text.includes('Đăng ký ngay và gia nhập Shopee Affiliates')) return true;
    return false;
  });
}

async function testProfileAccess() {
  const profileExists = fs.existsSync(PROFILE_DIR);

  if (!profileExists) {
    console.log('Profile not found, opening browser for manual login...');

    const context = await chromium.launchPersistentContext(PROFILE_DIR, LAUNCH_OPTIONS);

    console.log('PROFILE_DIR=', PROFILE_DIR);

    const page = await context.pages()[0] || await context.newPage();

    try {
      await page.goto(SHOPEE_AFFILIATE_URL, {
        waitUntil: 'networkidle',
        timeout: 30000,
      });

      console.log('Waiting for manual login...');

      const startTime = Date.now();
      let userStartedLogin = false;

      while (Date.now() - startTime < LOGIN_TIMEOUT) {
        await page.waitForTimeout(POLL_INTERVAL);

        const hasPasswordField = await page.evaluate(() => {
          return !!document.querySelector('input[type="password"]');
        });

        if (!userStartedLogin && hasPasswordField) {
          userStartedLogin = true;
        }

        if (userStartedLogin && !hasPasswordField) {
          const currentUrl = page.url();

          if (currentUrl.includes('verify/captcha')) {
            return { success: false, message: 'CAPTCHA_REQUIRED', url: currentUrl };
          }

          if (currentUrl.includes('login')) {
            return { success: false, message: 'LOGIN_REQUIRED', url: currentUrl };
          }

          if (await isLandingPage(page)) {
            return { success: false, message: 'LANDING_PAGE', url: currentUrl };
          }

          return { success: true, message: 'PROFILE_VALID', url: currentUrl };
        }
      }

      return { success: false, error: 'Login timeout after 10 minutes' };
    } catch (err) {
      return { success: false, error: err.message };
    } finally {
      await context.close();
    }
  }

  const context = await chromium.launchPersistentContext(PROFILE_DIR, LAUNCH_OPTIONS);

  const page = await context.pages()[0] || await context.newPage();

  try {
    await page.goto(SHOPEE_AFFILIATE_URL, {
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

    await page.screenshot({ path: SCREENSHOT_FILE, fullPage: true });

    if (await isLandingPage(page)) {
      return { success: false, message: 'LANDING_PAGE', url: currentUrl, title: pageTitle };
    }

    return { success: true, message: 'PROFILE_VALID', url: currentUrl, title: pageTitle };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await context.close();
  }
}

async function openProfile() {
  const context = await chromium.launchPersistentContext(PROFILE_DIR, LAUNCH_OPTIONS);

  console.log('PROFILE_DIR=', PROFILE_DIR);

  const page = context.pages()[0] || await context.newPage();

  await page.goto(SHOPEE_AFFILIATE_URL, {
    waitUntil: 'networkidle',
    timeout: 30000,
  });

  return { success: true, profile_dir: PROFILE_DIR };
}

module.exports = { testProfileAccess, openProfile };
