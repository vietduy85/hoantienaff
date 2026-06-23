const { chromium } = require('playwright');

async function runBrowserTest() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  try {
    await page.goto('https://www.google.com', { waitUntil: 'domcontentloaded', timeout: 15000 });
    const title = await page.title();
    return { success: true, title };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await browser.close();
  }
}

module.exports = { runBrowserTest };
