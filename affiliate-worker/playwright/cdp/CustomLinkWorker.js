const ChromeManager = require('./ChromeManager');
const AffiliateNavigator = require('./AffiliateNavigator');
const Logger = require('./Logger');
const Timer = require('./Timer');
const path = require('path');
const fs = require('fs');

const TIMING_ENABLED = process.env.AFFILIATE_TIMING === 'true';
const STORAGE = path.resolve(__dirname, '..', '..', 'storage');
const INPUT_SELECTORS = ['textarea', 'input[type="url"]', 'input[type="text"]', '.ant-input', 'textarea.ant-input', 'input.ant-input'];
const BUTTON_SELECTORS = ['button', '.ant-btn', 'button[type="button"]'];
const BUTTON_TEXTS = ['Tạo', 'Generate', 'Lấy link', 'Create', 'Custom Link'];
const SHORT_LINK_RES = [
  /https:\/\/s\.shopee\.vn\/[^\s"'<>]+/,
  /https:\/\/shope\.ee\/[^\s"'<>]+/,
  /https:\/\/affiliate\.shopee\.vn\/[^\s"'<>]+/,
];
const SHORT_LINK_PATTERN = /s\.shopee\.vn|shope\.ee|shp\.ee/;

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
  async createAffiliateLink(productUrl) {
console.log(">>>>>>>>>>>>>>>>>>>>>>>>>>>>");
console.log("ENTER createAffiliateLink()");
console.log(productUrl);
console.log("<<<<<<<<<<<<<<<<<<<<<<<<<<<<");
    const timer = TIMING_ENABLED ? new Timer() : null;
    const debugDir = TIMING_ENABLED ? this._createDebugDir() : null;
    const log = new Logger(debugDir, TIMING_ENABLED ? 'DEBUG' : 'INFO');
    let page = null;
    let requestHandler = null;
    let responseHandler = null;
    let graphqlRequest = null;
    let graphqlError = null;
    let graphqlFound = false;
    let uiFallback = false;

    if (timer) timer.start('Receive HTTP Request');
    if (timer) timer.end('Receive HTTP Request');
    log.info('Worker', `createAffiliateLink: ${productUrl}`);

    try {
      // Resolve Short Link
      if (timer) timer.start('Resolve Short Link');
      if (SHORT_LINK_PATTERN.test(productUrl)) {
        // URL is already a short link; resolution happens upstream in Laravel
      }
      if (timer) timer.end('Resolve Short Link');

      // B1 - Navigate to Custom Link page
      if (timer) timer.start('Open Custom Link Page');
      try {
        page = await AffiliateNavigator.ensureCustomLinkPage();
        log.info('Worker', `Current URL: ${page.url()}`);
      } catch (err) {
        log.error('Worker', `Navigator failed: ${err.message}`);
        await this._saveDebug(debugDir, log, page, 'navigator-fail');
        if (timer) timer.markError();
        throw err;
      }
      if (timer) timer.end('Open Custom Link Page');

      // Wait page ready (after navigation)
      if (timer) timer.start('Wait Page Ready');

      // B2 - find input
      log.info('Worker', `Looking for input field...`);
      let inputLocator = null;
      let inputSelector = null;
      for (const sel of INPUT_SELECTORS) {
        const loc = await this._waitVisibleSelector(page, sel, 3000).catch(() => null);
        if (loc) {
          const visible = await loc.isVisible().catch(() => false);
          if (visible) {
            inputLocator = loc;
            inputSelector = sel;
            break;
          }
        }
      }

      if (!inputLocator) {
        log.error('Worker', `Input field not found`);
        await this._saveDebug(debugDir, log, page, 'input-not-found');
        if (timer) timer.markError();
        throw new Error(`Input field not found on Custom Link page`);
      }
      log.info('Worker', `Input selector found: ${inputSelector}`);
      if (timer) timer.end('Wait Page Ready');

      // B3 - fill input
      if (timer) timer.start('Paste URL');
      log.info('Worker', `Input URL: ${productUrl}`);
      try {
        await inputLocator.fill('');
        await inputLocator.fill(productUrl);
      } catch {
        log.info('Worker', `fill() failed, trying Ctrl+A + Delete`);
        await inputLocator.press('Control+a');
        await inputLocator.press('Delete');
        await inputLocator.fill(productUrl);
      }

      // B4 - find button
      log.info('Worker', `Looking for generate button...`);
      let buttonLocator = null;
      let buttonLabel = '';

      for (const sel of BUTTON_SELECTORS) {
        const locators = page.locator(sel);
        const count = await locators.count().catch(() => 0);
        for (let i = 0; i < count; i++) {
          const loc = locators.nth(i);
          try {
            const visible = await loc.isVisible();
            if (!visible) continue;
            const box = await loc.boundingBox();
            if (!box || box.width === 0) continue;
            const disabled = await loc.isDisabled().catch(() => false);
            if (disabled) continue;
            const text = (await loc.textContent().catch(() => '') || '').trim();
            const match = BUTTON_TEXTS.find(t => text.includes(t));
            if (match) {
              buttonLocator = loc;
              buttonLabel = `${sel} "${text}"`;
              log.info('Worker', `Button found: ${buttonLabel} rect(${Math.round(box.x)},${Math.round(box.y)} ${Math.round(box.width)}x${Math.round(box.height)})`);
              break;
            }
          } catch {}
        }
        if (buttonLocator) break;
      }

      if (!buttonLocator) {
        log.error('Worker', `Generate button not found`);
        await this._saveDebug(debugDir, log, page, 'button-not-found');
        if (timer) timer.markError();
        throw new Error(`Generate button not found on Custom Link page`);
      }
      log.info('Worker', `Button selector: ${buttonLabel}`);
      if (timer) timer.end('Paste URL');

      // B5 - register handlers (with references for cleanup)
      log.info('Worker', `Registering GraphQL listeners...`);
      requestHandler = req => {
        if (req.url().includes('batchCustomLink') && req.method() === 'POST') {
          log.debug('Worker', `GraphQL request captured`);
          graphqlRequest = {
            url: req.url(),
            method: req.method(),
            headers: req.headers(),
            postData: req.postData(),
          };
        }
      };
      responseHandler = res => {
        if (res.url().includes('batchCustomLink') && res.request().method() === 'POST') {
          log.debug('Worker', `GraphQL response captured: ${res.status()}`);
        }
      };
      page.on('request', requestHandler);
      page.on('response', responseHandler);

      // screenshot before click
      if (TIMING_ENABLED) {
        try {
          await page.screenshot({ path: path.join(debugDir, 'before-click.png'), fullPage: true });
        } catch {}
      }

      log.info('Worker', `Clicked Generate`);
      if (timer) timer.start('Click Create Link');
      await buttonLocator.click();
      if (timer) timer.end('Click Create Link');

      // B6 - wait for GraphQL (single call)
      if (timer) timer.start('Wait Shopee Response');
      let graphqlJson = null;

      const graphqlResp = await this._waitGraphQL(page, log, debugDir, timer ? timer.requestId : null);

      try {
        graphqlJson = await graphqlResp.json();
        log.info('Worker', `GraphQL JSON parsed`);
      } catch (e) {
        graphqlError = { parseError: e.message, status: graphqlResp.status() };
        try { graphqlError.text = await graphqlResp.text(); } catch {}
      }
      if (timer) timer.end('Wait Shopee Response');

      // Save meta files
      if (timer) timer.start('Save Meta Files');
      if (TIMING_ENABLED && graphqlRequest) {
        fs.writeFileSync(
          path.join(debugDir, 'graphql-request.json'),
          JSON.stringify(graphqlRequest, null, 2)
        );
        log.debug('Worker', `GraphQL request saved`);
      }

      if (TIMING_ENABLED) {
        const now = Date.now();
        const meta = {
          time: new Date().toISOString(),
          elapsed: timer ? now - timer._startTime : 0,
          status: graphqlResp.status(),
          url: graphqlRequest ? graphqlRequest.url : null,
          contentType: graphqlResp.headers()['content-type'] || '',
          requestSize: graphqlRequest ? JSON.stringify(graphqlRequest).length : 0,
          responseSize: graphqlJson ? JSON.stringify(graphqlJson).length : 0,
        };
        fs.writeFileSync(
          path.join(debugDir, 'graphql-meta.json'),
          JSON.stringify(meta, null, 2)
        );
        log.debug('Worker', `GraphQL meta saved`);
      }

      // Save response + error
      if (TIMING_ENABLED && graphqlJson) {
        fs.writeFileSync(
          path.join(debugDir, 'graphql-response.json'),
          JSON.stringify(graphqlJson, null, 2)
        );
        log.debug('Worker', `GraphQL response saved`);
      }
      if (TIMING_ENABLED && graphqlError) {
        fs.writeFileSync(
          path.join(debugDir, 'graphql-error.json'),
          JSON.stringify(graphqlError, null, 2)
        );
        log.error('Worker', `GraphQL error saved`);
      }

      // Search for shortLinks via DFS
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

      // after-click screenshot
      if (TIMING_ENABLED) {
        try {
          await page.screenshot({ path: path.join(debugDir, 'after-click.png'), fullPage: true });
        } catch {}
      }

      // B7 - UI fallback
      if (timer) timer.start('Read Affiliate URL');
      if (!shortLink && graphqlJson) {
        log.info('Worker', `ShortLink not in GraphQL, reading UI...`);
        uiFallback = true;
        shortLink = await this._readUiLink(page, log);
        if (shortLink) {
          log.info('Worker', `ShortLink found in UI: ${shortLink}`);
        }
      }

      // save page state
      if (TIMING_ENABLED) {
        await this._savePageSnapshot(debugDir, page);
      }
      if (timer) timer.end('Save Meta Files');
      if (timer) timer.end('Read Affiliate URL');

      const elapsed = Date.now() - (timer ? timer._startTime : Date.now());
      log.info('Worker', `Elapsed: ${elapsed}ms`);

      if (shortLink) {
        if (timer) timer.printSummary();
        return {
          success: true,
          shortLink,
          longLink: productUrl,
          elapsed,
          graphqlFound,
          uiFallback,
          debugFolder: debugDir,
        };
      }

      // B8 - fail
      log.error('Worker', `ShortLink not found in GraphQL or UI`);
      await this._saveDebug(debugDir, log, page, 'create-link-fail');
      if (timer) timer.markError();
      throw new Error(`ShortLink not found in GraphQL response or UI`);

    } catch (err) {
      if (timer) timer.markError();
      throw err;
    } finally {
      // always clean up
      if (page && requestHandler) page.off('request', requestHandler);
      if (page && responseHandler) page.off('response', responseHandler);
      if (timer) timer.printSummary();
      log.save();
      log.flush();
    }
  }

  async _waitGraphQL(page, log, debugDir, requestId) {
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
      await this._saveDebug(debugDir, log, page, 'create-link-timeout');
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
    if (!TIMING_ENABLED || !debugDir) return;
    await this._savePageSnapshot(debugDir, page, prefix);
    log.save();
  }

  async _savePageSnapshot(debugDir, page, prefix) {
    if (!TIMING_ENABLED || !page || !debugDir) return;
    const p = prefix ? `${prefix}-` : '';
    try {
      await page.screenshot({ path: path.join(debugDir, `${p}page.png`), fullPage: true });
    } catch {}
    try {
      const html = await page.content();
      fs.writeFileSync(path.join(debugDir, `${p}page.html`), html);
    } catch {}
    try {
      const url = page.url();
      fs.writeFileSync(path.join(debugDir, `${p}page-url.txt`), url);
    } catch {}
    try {
      const title = await page.title();
      fs.writeFileSync(path.join(debugDir, `${p}page-title.txt`), title);
    } catch {}
  }

  async _readUiLink(page, log) {
    log.info('Worker', 'UI fallback: checking modal textarea...');
    const modalTextarea = await this._waitVisibleSelector(page, '.ant-modal textarea, [role="dialog"] textarea, .modal textarea', 3000);
    if (modalTextarea) {
      const text = await modalTextarea.inputValue().catch(() => '');
      for (const re of SHORT_LINK_RES) {
        const match = text.match(re);
        if (match) return match[0];
      }
    }

    log.info('Worker', 'UI fallback: checking any textarea...');
    const anyTextarea = await this._waitVisibleSelector(page, 'textarea', 2000);
    if (anyTextarea) {
      const text = await anyTextarea.inputValue().catch(() => '');
      for (const re of SHORT_LINK_RES) {
        const match = text.match(re);
        if (match) return match[0];
      }
    }

    log.info('Worker', 'UI fallback: checking dialog/modal...');
    const dialog = await this._waitVisibleSelector(page, '.ant-modal, [role="dialog"], .modal', 2000);
    if (dialog) {
      const text = await dialog.textContent().catch(() => '');
      for (const re of SHORT_LINK_RES) {
        const match = text.match(re);
        if (match) return match[0];
      }
    }

    log.info('Worker', 'UI fallback: reading body text...');
    const body = await page.evaluate(() => document.body.innerText).catch(() => '');
    for (const re of SHORT_LINK_RES) {
      const match = body.match(re);
      if (match) return match[0];
    }

    return null;
  }
}

CustomLinkWorker.prototype.findAllShortLinks = findAllShortLinks;

module.exports = new CustomLinkWorker();
