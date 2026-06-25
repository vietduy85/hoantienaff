const { chromium } = require('./browser');
const path = require('path');
const fs = require('fs');

const STATE_FILE = path.resolve(__dirname, '..', 'storage', 'shopee-state.json');
const SCREENSHOT_FILE = path.resolve(__dirname, '..', 'storage', 'create-link-test.png');
const CUSTOM_LINK_SCREENSHOT = path.resolve(__dirname, '..', 'storage', 'custom-link-test.png');
const STEALTH_SCREENSHOT = path.resolve(__dirname, '..', 'storage', 'custom-link-stealth-test.png');
const SHOPEE_AFFILIATE_URL = 'https://affiliate.shopee.vn';

async function isLandingPage(page) {
  return page.evaluate(() => {
    const text = document.body.innerText;
    if (text.includes('Nhập tại đây')) return true;
    if (text.includes('Đăng ký ngay và gia nhập Shopee Affiliates')) return true;
    return false;
  });
}

async function openCreateLink(productUrl) {
  if (!fs.existsSync(STATE_FILE)) {
    return { success: false, error: 'Session not found' };
  }

  const browser = await chromium.launch({ headless: false });
  const context = await browser.newContext({ storageState: STATE_FILE });
  const page = await context.newPage();

  try {
    await page.goto(SHOPEE_AFFILIATE_URL, {
      waitUntil: 'networkidle',
      timeout: 30000,
    });

    const currentUrl = page.url();
    const pageTitle = await page.title();

    await page.screenshot({ path: SCREENSHOT_FILE, fullPage: true });

    if (currentUrl.includes('verify/captcha')) {
      return { success: false, message: 'CAPTCHA_REQUIRED', url: currentUrl, title: pageTitle };
    }

    if (currentUrl.includes('login')) {
      return { success: false, message: 'LOGIN_REQUIRED', url: currentUrl, title: pageTitle };
    }

    if (await isLandingPage(page)) {
      return { success: false, message: 'LANDING_PAGE', url: currentUrl, title: pageTitle };
    }

    return {
      success: true,
      message: 'PROFILE_VALID',
      url: currentUrl,
      title: pageTitle,
    };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await browser.close();
  }
}

async function testCustomLink() {
  if (!fs.existsSync(STATE_FILE)) {
    return { success: false, error: 'Session not found' };
  }

  const browser = await chromium.launch({ headless: false });
  const context = await browser.newContext({ storageState: STATE_FILE });
  const page = await context.newPage();

  try {
    await page.goto('https://affiliate.shopee.vn/offer/custom_link', {
      waitUntil: 'networkidle',
      timeout: 30000,
    });

    const currentUrl = page.url();
    const pageTitle = await page.title();

    await page.screenshot({ path: CUSTOM_LINK_SCREENSHOT, fullPage: true });

    if (currentUrl.includes('verify/captcha')) {
      return { success: false, message: 'CAPTCHA_REQUIRED', url: currentUrl, title: pageTitle };
    }

    if (currentUrl.includes('login')) {
      return { success: false, message: 'LOGIN_REQUIRED', url: currentUrl, title: pageTitle };
    }

    const onCustomPage = await page.evaluate(() => {
      const text = document.body.innerText;
      if (text.includes('Custom Link')) return true;
      if (document.querySelector('textarea, input[type="url"], input[placeholder*="link" i]')) return true;
      return false;
    });

    if (currentUrl.includes('offer/custom_link') || onCustomPage) {
      return {
        success: true,
        message: 'CUSTOM_LINK_PAGE',
        url: currentUrl,
        title: pageTitle,
      };
    }

    if (await isLandingPage(page)) {
      return { success: false, message: 'LANDING_PAGE', url: currentUrl, title: pageTitle };
    }

    return { success: true, message: 'PROFILE_VALID', url: currentUrl, title: pageTitle };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await browser.close();
  }
}

async function testCustomLinkStealth() {
  if (!fs.existsSync(STATE_FILE)) {
    return { success: false, error: 'Session not found' };
  }

  const browser = await chromium.launch({ headless: false });
  const context = await browser.newContext({
    storageState: STATE_FILE,
    locale: 'vi-VN',
    timezoneId: 'Asia/Ho_Chi_Minh',
    viewport: { width: 1366, height: 768 },
  });

  await context.setExtraHTTPHeaders({
    'Accept-Language': 'vi-VN,vi;q=0.9,en;q=0.8',
  });

  const page = await context.newPage();

  try {
    await page.goto('https://affiliate.shopee.vn/offer/custom_link', {
      waitUntil: 'networkidle',
      timeout: 30000,
    });

    await page.waitForTimeout(1000 + Math.floor(Math.random() * 3000));

    const currentUrl = page.url();
    const pageTitle = await page.title();

    await page.screenshot({ path: STEALTH_SCREENSHOT, fullPage: true });

    if (currentUrl.includes('verify/captcha')) {
      return { success: false, message: 'CAPTCHA_REQUIRED', url: currentUrl, title: pageTitle };
    }

    if (currentUrl.includes('login')) {
      return { success: false, message: 'LOGIN_REQUIRED', url: currentUrl, title: pageTitle };
    }

    const onCustomPage = await page.evaluate(() => {
      const text = document.body.innerText;
      if (text.includes('Custom Link')) return true;
      if (document.querySelector('textarea, input[type="url"], input[placeholder*="link" i]')) return true;
      return false;
    });

    if (currentUrl.includes('offer/custom_link') || onCustomPage) {
      return {
        success: true,
        message: 'CUSTOM_LINK_PAGE',
        url: currentUrl,
        title: pageTitle,
      };
    }

    if (await isLandingPage(page)) {
      return { success: false, message: 'LANDING_PAGE', url: currentUrl, title: pageTitle };
    }

    return { success: true, message: 'PROFILE_VALID', url: currentUrl, title: pageTitle };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await browser.close();
  }
}

module.exports = { openCreateLink, testCustomLink, testCustomLinkStealth };
