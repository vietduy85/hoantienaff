const ChromeManager = require('./ChromeManager');
const AffiliateNavigator = require('./AffiliateNavigator');
const CustomLinkForm = require('./CustomLinkForm');
const ModalParser = require('./ModalParser');

class AffiliateWorker {
  async createLink(productUrl) {
    console.log('[Worker] Starting createLink for', productUrl);

    await ChromeManager.connect();
    console.log('[Worker] CDP connected');

    const page = await AffiliateNavigator.ensureCustomLinkPage();
    console.log('[Worker] On Custom Link page:', page.url());

    const urls = Array.isArray(productUrl) ? productUrl : [productUrl];
    await CustomLinkForm.fillUrls(urls);
    console.log('[Worker] Form submitted');

    const results = await ModalParser.waitAndParse();
    console.log('[Worker] Got', results.length, 'result(s)');

    return results;
  }

  async diagnostic() {
    await ChromeManager.connect();
    const page = await ChromeManager.getPage();

    const browser = await ChromeManager.connect();
    const version = browser.version();

    const ctx = await ChromeManager.getContext();
    const pages = ctx.pages().map(p => {
      try { return { url: p.url(), title: p.title() }; }
      catch { return { url: 'unknown', title: 'unknown' }; }
    });

    return {
      connected: true,
      browserVersion: version,
      contexts: browser.contexts().length,
      pages,
    };
  }

  async diagnosticCustomLink() {
    await ChromeManager.connect();

    const page = await ChromeManager.getPage();

    const currentUrl = page.url();
    console.log('[Diagnostic] Current URL:', currentUrl);

    if (currentUrl.includes('offer/custom_link')) {
      const title = await page.title();
      await page.screenshot({ path: 'storage/diagnostic-custom-link.png', fullPage: true });
      return {
        status: 'ALREADY_ON_CUSTOM_LINK',
        url: currentUrl,
        title,
        screenshot: 'storage/diagnostic-custom-link.png',
      };
    }

    if (!currentUrl.includes('affiliate.shopee.vn')) {
      console.log('[Diagnostic] Navigating to affiliate.shopee.vn...');
      await page.goto('https://affiliate.shopee.vn', {
        waitUntil: 'networkidle',
        timeout: 30000,
      });
    }

    const targetPage = await AffiliateNavigator.ensureCustomLinkPage();
    const finalUrl = targetPage.url();
    const title = await targetPage.title();
    await targetPage.screenshot({ path: 'storage/diagnostic-custom-link.png', fullPage: true });

    return {
      status: 'NAVIGATED_TO_CUSTOM_LINK',
      url: finalUrl,
      title,
      screenshot: 'storage/diagnostic-custom-link.png',
    };
  }
}

module.exports = new AffiliateWorker();
