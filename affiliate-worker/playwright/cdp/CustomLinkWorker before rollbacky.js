const ChromeManager = require('./ChromeManager');
const AffiliateNavigator = require('./AffiliateNavigator');
const Logger = require('./Logger');
const Benchmark = require('./Benchmark');
const path = require('path');
const fs = require('fs');

const STORAGE = path.resolve(__dirname, '..', '..', 'storage');
const INPUT_SELECTORS = ['textarea', 'input[type="url"]', 'input[type="text"]', '.ant-input', 'textarea.ant-input', 'input.ant-input'];
const BUTTON_SELECTORS = ['button', '.ant-btn', 'button[type="button"]'];
const BUTTON_TEXTS = ['Tạo', 'Generate', 'Lấy link', 'Create', 'Custom Link'];
const SHORT_LINK_RES = [
  /https:\/\/s\.shopee\.vn\/[^\s"'<>]+/,
  /https:\/\/shope\.ee\/[^\s"'<>]+/,
  /https:\/\/affiliate\.shopee\.vn\/[^\s"'<>]+/,
];

function findAllShortLinks(obj, depth, visited, results) {
  if (depth > 100) return results;
  if (typeof obj === 'string') {
    for (const re of SHORT_LINK_RES) {
      const matches = obj.match(re);
      if (matches) {
        for (const m of matches) {
          const clean = m.replace(/[^a-zA-Z0-9:/._~-]/g, '');
          if (!results.includes(clean)) results.push(clean);
        }
      }
    }
    return results;
  }
  if (obj !== null && typeof obj === 'object') {
    try { visited.add(obj); } catch {}
    const keys = Object.keys(obj);
    for (const key of keys) {
      let val;
      try { val = obj[key]; } catch { continue; }
      if (val !== null && typeof val === 'object') {
        try { if (visited.has(val)) continue; } catch {}
      }
      findAllShortLinks(val, depth + 1, visited, results);
    }
  }
  return results;
}

class CustomLinkWorker {
  constructor() {
    this._cachedInputLocator = null;
    this._cachedInputSelector = null;
    this._cachedButtonLocator = null;
    this._cachedButtonLabel = null;
    this._requestHandler = null;
    this._responseHandler = null;
    this._handlersRegistered = false;
    this._page = null;
  }

  async createAffiliateLink(productUrl) {
    const benchmark = new Benchmark();
    const debugDir = this._createDebugDir();
    const log = new Logger(debugDir, 'DEBUG');

    log.info('Worker', `createAffiliateLink: ${productUrl}`);

    let page = null;
    let graphqlRequest = null;
    let graphqlError = null;
    let graphqlFound = false;
    let uiFallback = false;
    let clickTime = 0;
    let requestTime = 0;
    let responseTime = 0;

    try {
      // STEP 1: Connect Chrome
      benchmark.start('connectChrome');
      log.info('Worker', 'Connecting to Chrome...');
      try {
        page = this._page || await ChromeManager.getPage();
        this._page = page;
        console.log('==============================');
        console.log('[DEBUG] Page URL:', page.url());
        console.log('[DEBUG] Contains offer/custom_link:', page.url().includes('offer/custom_link'));
        console.log('==============================');
      } finally {
        benchmark.end('connectChrome');
      }

      // STEP 2: Ensure page
      benchmark.start('ensurePage');
      try {
        const currentUrl = page.url();
        log.info('Worker', `Current URL: ${currentUrl}`);
        if (!currentUrl.includes('offer/custom_link')) {
          console.log('[DEBUG] CALLING ensureCustomLinkPage()');
          await AffiliateNavigator.ensureCustomLinkPage();
        } else {
          console.log('[DEBUG] SKIP ensureCustomLinkPage()');
          log.info('Worker', 'Already on Custom Link page');
        }
      } finally {
        benchmark.end('ensurePage');
      }

      // STEP 3: Find input
      benchmark.start('findInput');
      let inputLocator = this._cachedInputLocator;
      let inputSelector = this._cachedInputSelector;
      if (!inputLocator) {
        log.info('Worker', 'Looking for input field...');
        for (const sel of INPUT_SELECTORS) {
          const loc = await this._waitVisibleSelector(page, sel, 3000).catch(() => null);
          if (loc && await loc.isVisible().catch(() => false)) {
            inputLocator = loc;
            inputSelector = sel;
            break;
          }
        }
        if (inputLocator) {
          this._cachedInputLocator = inputLocator;
          this._cachedInputSelector = inputSelector;
        }
      }
      if (!inputLocator) {
        log.error('Worker', 'Input field not found');
        await page.waitForTimeout(5000);
        try { await page.screenshot({ path: path.join(STORAGE, 'input-not-found.png'), fullPage: true }); } catch {}
        try { const html = await page.content(); fs.writeFileSync(path.join(STORAGE, 'input-not-found.html'), html); } catch {}
        try { const text = await page.evaluate(() => document.body.innerText); fs.writeFileSync(path.join(STORAGE, 'input-not-found.txt'), text); } catch {}
        try {
          const stats = await page.evaluate(() => ({
            textarea: document.querySelectorAll('textarea').length,
            input: document.querySelectorAll('input').length,
            button: document.querySelectorAll('button').length,
          }));
          console.log(stats);
        } catch {}
        try {
          const elements = await page.evaluate(() => {
            const all = document.querySelectorAll('textarea, input');
            return Array.from(all).map(el => ({
              tag: el.tagName.toLowerCase(),
              type: el.type || null,
              placeholder: el.placeholder || null,
              ariaLabel: el.getAttribute('aria-label') || null,
              className: (typeof el.className === 'string' ? el.className : '') || null,
              id: el.id || null,
              visible: el.offsetParent !== null,
            }));
          });
          fs.writeFileSync(path.join(STORAGE, 'input-elements.json'), JSON.stringify(elements, null, 2));
        } catch {}
        try {
          const iframes = await page.evaluate(() => {
            const all = document.querySelectorAll('iframe');
            return Array.from(all).map(el => ({
              src: el.src || null,
              id: el.id || null,
              name: el.name || null,
            }));
          });
          if (iframes.length > 0) {
            fs.writeFileSync(path.join(STORAGE, 'iframes.json'), JSON.stringify(iframes, null, 2));
          }
        } catch {}
        await this._saveDebug(debugDir, log, page, 'input-not-found');
        throw new Error('Input field not found');
      }
      log.info('Worker', `Input selector: ${inputSelector}`);
      benchmark.end('findInput');

      // STEP 4: Fill input
      benchmark.start('fillInput');
      log.info('Worker', `Input URL: ${productUrl}`);
      try {
        await inputLocator.fill('');
        await inputLocator.fill(productUrl);
      } catch {
        log.info('Worker', 'fill() failed, trying Ctrl+A + Delete');
        await inputLocator.press('Control+a');
        await inputLocator.press('Delete');
        await inputLocator.fill(productUrl);
      }
      benchmark.end('fillInput');

      // STEP 5: Find button
      benchmark.start('findButton');
      let buttonLocator = this._cachedButtonLocator;
      if (!buttonLocator) {
        log.info('Worker', 'Looking for generate button...');
        for (const sel of BUTTON_SELECTORS) {
          const locators = page.locator(sel);
          const count = await locators.count().catch(() => 0);
          for (let i = 0; i < count; i++) {
            const loc = locators.nth(i);
            try {
              if (!await loc.isVisible()) continue;
              const box = await loc.boundingBox();
              if (!box || box.width === 0) continue;
              if (await loc.isDisabled().catch(() => false)) continue;
              const text = (await loc.textContent().catch(() => '') || '').trim();
              if (BUTTON_TEXTS.find(t => text.includes(t))) {
                buttonLocator = loc;
                this._cachedButtonLabel = `${sel} "${text}"`;
                log.info('Worker', `Button found: ${this._cachedButtonLabel}`);
                break;
              }
            } catch {}
          }
          if (buttonLocator) break;
        }
        if (buttonLocator) this._cachedButtonLocator = buttonLocator;
      }
      if (!buttonLocator) {
        log.error('Worker', 'Generate button not found');
        await this._saveDebug(debugDir, log, page, 'button-not-found');
        throw new Error('Generate button not found');
      }
      log.info('Worker', `Button: ${this._cachedButtonLabel}`);
      benchmark.end('findButton');

      // STEP 6: Register listeners (once)
      if (!this._handlersRegistered && page) {
        this._requestHandler = req => {
          if (req.url().includes('batchCustomLink') && req.method() === 'POST') {
            requestTime = Date.now();
            log.debug('Worker', 'GraphQL request captured');
            graphqlRequest = {
              url: req.url(),
              method: req.method(),
              headers: req.headers(),
              postData: req.postData(),
            };
          }
        };
        this._responseHandler = () => {
          responseTime = Date.now();
          log.debug('Worker', 'GraphQL response captured');
        };
        page.on('request', this._requestHandler);
        page.on('response', this._responseHandler);
        this._handlersRegistered = true;
      }

      // STEP 7: Click
      benchmark.start('clickButton');
      log.info('Worker', 'Clicked Generate');
      clickTime = Date.now();
      await buttonLocator.click();
      benchmark.end('clickButton');

      // STEP 8: Wait GraphQL
      benchmark.start('waitGraphQL');
      const graphqlResp = await this._waitGraphQL(page, log);
      benchmark.end('waitGraphQL');

      // STEP 9: Parse JSON
      benchmark.start('jsonParse');
      let graphqlJson = null;
      try {
        graphqlJson = await graphqlResp.json();
        log.info('Worker', 'GraphQL JSON parsed');
      } catch (e) {
        graphqlError = { parseError: e.message, status: graphqlResp.status() };
        try { graphqlError.text = await graphqlResp.text(); } catch {}
      }
      benchmark.end('jsonParse');

      // Save GraphQL artifacts
      if (graphqlRequest) {
        fs.writeFileSync(path.join(debugDir, 'graphql-request.json'), JSON.stringify(graphqlRequest, null, 2));
        if (graphqlJson) fs.writeFileSync(path.join(debugDir, 'graphql-response.json'), JSON.stringify(graphqlJson, null, 2));
        if (graphqlError) fs.writeFileSync(path.join(debugDir, 'graphql-error.json'), JSON.stringify(graphqlError, null, 2));
        const meta = {
          time: new Date().toISOString(),
          elapsed: Date.now(),
          status: graphqlResp.status(),
          url: graphqlRequest.url,
          contentType: graphqlResp.headers()['content-type'] || '',
          requestSize: JSON.stringify(graphqlRequest).length,
          responseSize: graphqlJson ? JSON.stringify(graphqlJson).length : 0,
        };
        fs.writeFileSync(path.join(debugDir, 'graphql-meta.json'), JSON.stringify(meta, null, 2));
      }

      // STEP 10: Extract shortLink
      benchmark.start('extractShortLink');
      let shortLink = null;
      if (graphqlJson) {
        const visited = new Set();
        const allLinks = findAllShortLinks(graphqlJson, 0, visited, []);
        if (allLinks.length > 0) {
          shortLink = allLinks[0];
          graphqlFound = true;
          log.info('Worker', `Found ${allLinks.length} short link(s), first: ${shortLink}`);
        } else {
          log.warn('Worker', `DFS scanned ${visited.size} nodes, no short links`);
        }
      }
      benchmark.end('extractShortLink');

      // STEP 11: UI fallback
      if (!shortLink && graphqlJson) {
        log.info('Worker', 'ShortLink not in GraphQL, reading UI...');
        uiFallback = true;
        benchmark.start('uiFallback');
        shortLink = await this._readUiLink(page, log);
        benchmark.end('uiFallback');
        if (shortLink) log.info('Worker', `ShortLink found in UI: ${shortLink}`);
      }

      // STEP 12: Save debug
      benchmark.start('debugSave');
      await this._savePageSnapshot(debugDir, page);
      benchmark.end('debugSave');

      const totalMs = benchmark.elapsed('connectChrome') +
        benchmark.elapsed('ensurePage') +
        benchmark.elapsed('findInput') +
        benchmark.elapsed('fillInput') +
        benchmark.elapsed('findButton') +
        benchmark.elapsed('clickButton') +
        benchmark.elapsed('waitGraphQL') +
        benchmark.elapsed('jsonParse') +
        benchmark.elapsed('extractShortLink') +
        benchmark.elapsed('debugSave');

      log.info('Worker', `Total: ${totalMs}ms`);

      if (shortLink) {
        const benchResult = benchmark.toJSON();

        const bm = {
          connectChromeMs: benchResult.connectChrome || 0,
          ensurePageMs: benchResult.ensurePage || 0,
          findInputMs: benchResult.findInput || 0,
          fillInputMs: benchResult.fillInput || 0,
          findButtonMs: benchResult.findButton || 0,
          clickButtonMs: benchResult.clickButton || 0,
          clickToRequestMs: requestTime > 0 ? requestTime - clickTime : 0,
          requestToResponseMs: responseTime > 0 ? responseTime - requestTime : 0,
          jsonParseMs: benchResult.jsonParse || 0,
          extractShortLinkMs: benchResult.extractShortLink || 0,
          debugSaveMs: benchResult.debugSave || 0,
          totalMs,
        };

        // Save benchmark.json
        const benPath = path.join(debugDir, 'benchmark.json');
        fs.writeFileSync(benPath, JSON.stringify(bm, null, 2));

        return {
          success: true,
          shortLink,
          longLink: productUrl,
          elapsed: totalMs,
          benchmark: bm,
        };
      }

      log.error('Worker', 'ShortLink not found');
      await this._saveDebug(debugDir, log, page, 'create-link-fail');
      throw new Error('ShortLink not found in GraphQL response or UI');

    } finally {
      if (log) log.save();
      if (log) log.flush();
    }
  }

  async _waitGraphQL(page, log) {
    const responsePromise = page.waitForResponse(r =>
      r.url().includes('batchCustomLink') && r.request().method() === 'POST',
      { timeout: 20000 }
    );

    try {
      const resp = await responsePromise;
      log.info('Worker', `GraphQL received, status: ${resp.status()}`);
      return resp;
    } catch (err) {
      log.error('Worker', `GraphQL timeout: ${err.message}`);
      log.info('Worker', `Current URL: ${page.url()}`);
      const title = await page.title().catch(() => 'unknown');
      log.info('Worker', `Title: ${title}`);
      const readyState = await page.evaluate(() => document.readyState).catch(() => 'unknown');
      log.info('Worker', `document.readyState: ${readyState}`);
      throw err;
    }
  }

  async _waitVisibleSelector(page, selector, timeout) {
    try {
      const loc = page.locator(selector).first();
      await loc.waitFor({ state: 'visible', timeout });
      return loc;
    } catch {
      return null;
    }
  }

  _createDebugDir() {
    const d = new Date();
    const pad = n => String(n).padStart(2, '0');
    const dirName = `${d.getFullYear()}${pad(d.getMonth()+1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
    const dir = path.join(STORAGE, 'debug', dirName);
    fs.mkdirSync(dir, { recursive: true });
    return dir;
  }

  async _saveDebug(debugDir, log, page, prefix) {
    await this._savePageSnapshot(debugDir, page, prefix);
    log.save();
  }

  async _savePageSnapshot(debugDir, page, prefix) {
    if (!page) return;
    const p = prefix ? `${prefix}-` : '';
    try { await page.screenshot({ path: path.join(debugDir, `${p}page.png`), fullPage: true }); } catch {}
    try { const html = await page.content(); fs.writeFileSync(path.join(debugDir, `${p}page.html`), html); } catch {}
    try { fs.writeFileSync(path.join(debugDir, `${p}page-url.txt`), page.url()); } catch {}
    try { const title = await page.title(); fs.writeFileSync(path.join(debugDir, `${p}page-title.txt`), title); } catch {}
  }

  async _readUiLink(page, log) {
    log.info('Worker', 'UI fallback: checking modal textarea...');
    const modalTextarea = await this._waitVisibleSelector(page, '.ant-modal textarea, [role="dialog"] textarea, .modal textarea', 3000);
    if (modalTextarea) {
      const text = await modalTextarea.inputValue().catch(() => '');
      for (const re of SHORT_LINK_RES) { const match = text.match(re); if (match) return match[0]; }
    }
    log.info('Worker', 'UI fallback: checking any textarea...');
    const anyTextarea = await this._waitVisibleSelector(page, 'textarea', 2000);
    if (anyTextarea) {
      const text = await anyTextarea.inputValue().catch(() => '');
      for (const re of SHORT_LINK_RES) { const match = text.match(re); if (match) return match[0]; }
    }
    log.info('Worker', 'UI fallback: checking dialog/modal...');
    const dialog = await this._waitVisibleSelector(page, '.ant-modal, [role="dialog"], .modal', 2000);
    if (dialog) {
      const text = await dialog.textContent().catch(() => '');
      for (const re of SHORT_LINK_RES) { const match = text.match(re); if (match) return match[0]; }
    }
    log.info('Worker', 'UI fallback: reading body text...');
    const body = await page.evaluate(() => document.body.innerText).catch(() => '');
    for (const re of SHORT_LINK_RES) { const match = body.match(re); if (match) return match[0]; }
    return null;
  }

  resetCache() {
    this._cachedInputLocator = null;
    this._cachedInputSelector = null;
    this._cachedButtonLocator = null;
    this._cachedButtonLabel = null;
  }
}

CustomLinkWorker.prototype.findAllShortLinks = findAllShortLinks;

module.exports = new CustomLinkWorker();
