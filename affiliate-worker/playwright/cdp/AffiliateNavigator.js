const ChromeManager = require('./ChromeManager');
const path = require('path');
const fs = require('fs');

const STORAGE = path.resolve(__dirname, '..', '..', 'storage');

class AffiliateNavigator {
  async ensureCustomLinkPage() {
    const page = await ChromeManager.getPage();
    const currentUrl = page.url();

    console.log('[Navigator] Current URL:', currentUrl);

    if (currentUrl.includes('offer/custom_link')) {
      console.log('[Navigator] Already on Custom Link page');
      return page;
    }

    if (!currentUrl.includes('affiliate.shopee.vn')) {
      console.log('[Navigator] Not on affiliate.shopee.vn, navigating...');
      await page.goto('https://affiliate.shopee.vn', {
        waitUntil: 'networkidle',
        timeout: 30000,
      });
      console.log('[Navigator] Landed on:', page.url());
    }

    try {
      await this._ensureSidebarExpanded(page);
      await this._clickMenu(page);
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

    // Try clicking the collapse trigger
    const trigger = await page.$('#aff-sider .ant-layout-sider-trigger, #aff-sider .ant-layout-sider-trigger *');
    if (trigger) {
      console.log('[Navigator] Clicking sidebar collapse trigger');
      await trigger.click();
      await page.waitForTimeout(500);
      const stillCollapsed = await sider.evaluate(el => {
        const w = el.offsetWidth;
        const cls = el.className || '';
        return w < 100 || cls.includes('ant-layout-sider-collapsed');
      });
      if (!stillCollapsed) {
        console.log('[Navigator] Expanded OK');
        return;
      }
    }

    // Hover sidebar edge to trigger expand
    console.log('[Navigator] Hovering sidebar...');
    const box = await sider.boundingBox();
    if (box) {
      await page.mouse.move(box.x + box.width / 2, box.y + 50);
      await page.waitForTimeout(800);
      const hoverCollapsed = await sider.evaluate(el => {
        const w = el.offsetWidth;
        const cls = el.className || '';
        return w < 100 || cls.includes('ant-layout-sider-collapsed');
      });
      if (!hoverCollapsed) {
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
    await page.waitForTimeout(500);
    const afterMove = await sider.evaluate(el => {
      const w = el.offsetWidth;
      return w >= 100;
    });
    if (afterMove) {
      console.log('[Navigator] Expanded OK via mousemove');
      return;
    }

    console.log('[Navigator] Could not expand sidebar via UI, continuing anyway...');
  }

  async _clickMenu(page) {
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
      if (parentLi) {
        console.log('[Navigator] Clicked Hoa hồng');
        await hoaHong.click();
        await page.waitForTimeout(500);
      } else {
        console.log('[Navigator] Hoa hồng not in LI, clicking anyway');
        await hoaHong.click();
        await page.waitForTimeout(500);
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
