const ChromeManager = require('./ChromeManager');
const path = require('path');
const fs = require('fs');

const TIMING_ENABLED = process.env.AFFILIATE_TIMING === 'true';
const STORAGE = path.resolve(__dirname, '..', '..', 'storage');

class AffiliateNavigator {
  async ensureCustomLinkPage() {
    const startTime = TIMING_ENABLED ? Date.now() : null;
    const page = await ChromeManager.getPage();
    const currentUrl = page.url();

    console.log('[Navigator] Current URL:', currentUrl);

    if (currentUrl.includes('offer/custom_link')) {
      const inputReady = await page.$('textarea, input[type="url"], input[type="text"], .ant-input, textarea.ant-input, input.ant-input');
      if (inputReady) {
        console.log('[Navigator] Already on Custom Link page, input ready');
        if (TIMING_ENABLED) {
          console.log(`[Navigator-Timing] Input Ready: ${Date.now() - startTime}ms`);
        }
        return page;
      }
    }

    if (!currentUrl.includes('affiliate.shopee.vn')) {
      console.log('[Navigator] Not on affiliate.shopee.vn, navigating...');
      const navStart = TIMING_ENABLED ? Date.now() : null;
      await page.goto('https://affiliate.shopee.vn', {
        waitUntil: 'networkidle',
        timeout: 30000,
      });
      if (TIMING_ENABLED) {
        console.log(`[Navigator-Timing] Navigate To Shopee: ${Date.now() - navStart}ms`);
      }
      console.log('[Navigator] Landed on:', page.url());
    }

    try {
      await this._ensureSidebarExpanded(page);
      await this._clickMenu(page, startTime);
    } catch (err) {
      console.log('[Navigator] Error:', err.message);
      console.log('[Navigator] Capturing diagnostic files...');
      try {
        await page.screenshot({ path: path.join(STORAGE, 'navigation-fail.png'), fullPage: true });
        const html = await page.content();
        fs.writeFileSync(path.join(STORAGE, 'navigation-fail.html'), html);
        console.log('[Navigator] Saved: navigation-fail.png + navigation-fail.html');
      } catch {}
      throw err;
    }

    if (TIMING_ENABLED) {
      console.log(`[Navigator-Timing] Navigate Custom Link: ${Date.now() - startTime}ms`);
    }
    return page;
  }

  async _ensureSidebarExpanded(page) {
    const sider = await page.$('#aff-sider');
    if (!sider) {
      console.log('[Navigator] #aff-sider not found, assuming no sidebar collapse issue');
      return;
    }

    const isCollapsed = await sider.evaluate(el => {
      const w = el.offsetWidth;
      const cls = el.className || '';
      return w < 100 || cls.includes('ant-layout-sider-collapsed');
    });

    const width = await sider.evaluate(el => el.offsetWidth);
    console.log('[Navigator] Sidebar width:', width);
    console.log('[Navigator] Sidebar collapsed:', isCollapsed);

    if (!isCollapsed) {
      console.log('[Navigator] Sidebar already expanded');
      return;
    }

    const _isExpanded = () => {
      return sider.evaluate(el => el.offsetWidth >= 100 && !el.className.includes('ant-layout-sider-collapsed'));
    };

    const _waitExpanded = (label) => {
      if (!TIMING_ENABLED) {
        return page.waitForFunction(
          () => {
            const el = document.querySelector('#aff-sider');
            if (!el) return true;
            return el.offsetWidth >= 100 && !el.className.includes('ant-layout-sider-collapsed');
          },
          { timeout: 2000 }
        ).then(() => true).catch(() => false);
      }
      const start = Date.now();
      return page.waitForFunction(
        () => {
          const el = document.querySelector('#aff-sider');
          if (!el) return true;
          return el.offsetWidth >= 100 && !el.className.includes('ant-layout-sider-collapsed');
        },
        { timeout: 2000 }
      ).then(() => {
        console.log(`[Navigator-Timing] ${label}: ${Date.now() - start}ms`);
        return true;
      }).catch(() => {
        console.log(`[Navigator-Timing] ${label}: ${Date.now() - start}ms (timeout)`);
        return false;
      });
    };

    // Try clicking the collapse trigger
    const trigger = await page.$('#aff-sider .ant-layout-sider-trigger, #aff-sider .ant-layout-sider-trigger *');
    if (trigger) {
      console.log('[Navigator] Clicking sidebar collapse trigger');
      await trigger.click();
      if (await _waitExpanded('Sidebar Trigger Expand')) {
        console.log('[Navigator] Expanded OK');
        return;
      }
    }

    // Hover sidebar edge to trigger expand
    console.log('[Navigator] Hovering sidebar...');
    const box = await sider.boundingBox();
    if (box) {
      await page.mouse.move(box.x + box.width / 2, box.y + 50);
      if (await _waitExpanded('Sidebar Hover Expand')) {
        console.log('[Navigator] Expanded OK via hover');
        return;
      }
    }

    // Dispatch mousemove as last resort
    console.log('[Navigator] Dispatching mousemove on sider...');
    await sider.evaluate(el => {
      el.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
      el.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
    });
    if (await _waitExpanded('Sidebar Dispatch Expand')) {
      console.log('[Navigator] Expanded OK via mousemove');
      return;
    }

    console.log('[Navigator] Could not expand sidebar via UI, continuing anyway...');
  }

  async _clickMenu(page, startTime) {
    // Wait for menu text to be visible
    console.log('[Navigator] Waiting for menu text...');
    let menuVisible = false;
    try {
      await page.waitForSelector('text=Custom Link', { timeout: 5000 });
      menuVisible = true;
    } catch {}
    if (!menuVisible) {
      try {
        await page.waitForSelector('text=Hoa hồng', { timeout: 3000 });
        menuVisible = true;
      } catch {}
    }
    console.log('[Navigator] Menu text visible:', menuVisible);

    // Click "Hoa hồng" if submenu is collapsed
    const hoaHong = await page.$('text=Hoa hồng');
    if (hoaHong) {
      const parentLi = await hoaHong.evaluate(el => {
        let p = el.parentElement;
        while (p) {
          if (p.tagName === 'LI') return true;
          p = p.parentElement;
        }
        return false;
      });
      const menuStart = TIMING_ENABLED ? Date.now() : null;
      if (parentLi) {
        console.log('[Navigator] Clicked Hoa hồng');
        await hoaHong.click();
      } else {
        console.log('[Navigator] Hoa hồng not in LI, clicking anyway');
        await hoaHong.click();
      }
      try {
        await page.waitForSelector('a[href*="offer/custom_link"], a[href*="/custom-link"], span:has-text("Custom Link"), div:has-text("Custom Link")', { timeout: 3000 });
        if (TIMING_ENABLED) {
          console.log(`[Navigator-Timing] Menu Ready: ${Date.now() - menuStart}ms`);
        }
      } catch {
        if (TIMING_ENABLED) {
          console.log(`[Navigator-Timing] Menu Ready: ${Date.now() - menuStart}ms (timeout)`);
        }
      }
    }

    // Click Custom Link
    const customLink = await page.$(
      'a[href*="offer/custom_link"], a[href*="/custom-link"], span:has-text("Custom Link"), div:has-text("Custom Link")'
    );

    if (customLink) {
      console.log('[Navigator] Clicked Custom Link');
      await customLink.click();
    } else {
      console.log('[Navigator] Custom Link element not found, trying text selector');
      const byText = await page.$('text=Custom Link');
      if (byText) {
        console.log('[Navigator] Clicked Custom Link by text');
        await byText.click();
      } else {
        // Fallback: try every visible link
        console.log('[Navigator] Fallback: scanning all links...');
        await this._trySidebarNav(page);
        return;
      }
    }

    // Wait for navigation
    try {
      await page.waitForURL('**/offer/custom_link**', { timeout: 15000 });
      console.log('[Navigator] Current URL:', page.url());
    } catch (err) {
      console.log('[Navigator] Timeout waiting for offer/custom_link');
      console.log('[Navigator] Current URL:', page.url());
      throw new Error('Timeout waiting for navigation to offer/custom_link');
    }

    // Confirm input is ready
    try {
      const inputReady = await page.waitForSelector('textarea, input[type="url"], input[type="text"], .ant-input, textarea.ant-input, input.ant-input', { timeout: 5000 });
      if (TIMING_ENABLED) {
        console.log(`[Navigator-Timing] Input Ready: ${Date.now() - startTime}ms`);
      }
    } catch {
      if (TIMING_ENABLED) {
        console.log(`[Navigator-Timing] Input Ready: ${Date.now() - startTime}ms (timeout)`);
      }
    }
  }

  async _trySidebarNav(page) {
    const sidebarItems = await page.$$('aside a, nav a, .sidebar a, li a');
    for (const item of sidebarItems) {
      const href = await item.getAttribute('href').catch(() => null);
      if (href && href.includes('custom')) {
        console.log('[Navigator] Clicking sidebar item:', href);
        await item.click();
        await page.waitForURL('**/offer/custom_link**', { timeout: 15000 });
        return;
      }
    }
    throw new Error('Could not navigate to Custom Link page');
  }
}

module.exports = new AffiliateNavigator();
