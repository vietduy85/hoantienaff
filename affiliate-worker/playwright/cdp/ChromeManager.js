const { chromium } = require('playwright');

const CDP_URL = 'http://127.0.0.1:9222';

class ChromeManager {
  constructor() {
    this._browser = null;
  }

  async connect() {
    if (this._browser && this._browser.isConnected()) {
      console.log('[CDP] Already connected');
      return this._browser;
    }

    console.log('[CDP] Connecting to', CDP_URL);
    this._browser = await chromium.connectOverCDP(CDP_URL);
    console.log('[CDP] Connected');
    return this._browser;
  }

  async getContext() {
    const browser = await this.connect();
    const ctx = browser.contexts()[0];
    if (!ctx) throw new Error('No default context found via CDP');
    return ctx;
  }

  async getPage() {
    const ctx = await this.getContext();
    const pages = ctx.pages();

    let page = pages.find(p => {
      try {
        return p.url().includes('affiliate.shopee.vn');
      } catch { return false; }
    });

    if (!page) {
      console.log('[CDP] No affiliate.shopee.vn tab found, creating new tab');
      page = await ctx.newPage();
      console.log('[CDP] Created new tab');
    } else {
      console.log('[CDP] Found existing affiliate.shopee.vn tab');
    }

    return page;
  }
}

module.exports = new ChromeManager();
