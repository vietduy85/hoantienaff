const ChromeManager = require('./ChromeManager');
const fs = require('fs');
const path = require('path');

const STORAGE = path.resolve(__dirname, '..', '..', 'storage');

const KEYWORDS = ['offer', 'custom', 'link', 'liên kết', 'affiliate', 'dashboard', 'tools'];

function safeStr(val, max) {
  try {
    if (typeof val === 'string') return max ? val.substring(0, max) : val;
    if (val !== null && val !== undefined && typeof val.toString === 'function') {
      const s = val.toString();
      return max ? s.substring(0, max) : s;
    }
  } catch {}
  return '';
}

function safeClassName(el) {
  try {
    if (typeof el.className === 'string') return el.className;
    if (el.className && typeof el.className.baseVal === 'string') return el.className.baseVal;
    const attr = el.getAttribute('class');
    if (typeof attr === 'string') return attr;
  } catch {}
  return '';
}

function safeDataset(el) {
  const ds = {};
  try {
    if (el.dataset) {
      for (const key of Object.keys(el.dataset)) {
        try {
          ds[key] = typeof el.dataset[key] === 'string' ? el.dataset[key] : String(el.dataset[key]);
        } catch {}
      }
    }
  } catch {}
  return ds;
}

function safeGet(el, prop, max) {
  try {
    const val = el[prop];
    return safeStr(val, max);
  } catch {}
  return '';
}

function safeAttr(el, attr) {
  try {
    const val = el.getAttribute(attr);
    return typeof val === 'string' ? val : '';
  } catch {}
  return '';
}

function extractElement(el) {
  try {
    const rect = el.getBoundingClientRect();
    const visible = rect.width > 0 && rect.height > 0;

    return {
      tag: el.tagName ? el.tagName.toLowerCase() : '',
      text: safeGet(el, 'textContent', 200).trim(),
      href: safeGet(el, 'href') || safeAttr(el, 'href'),
      id: safeGet(el, 'id'),
      className: safeClassName(el).substring(0, 200),
      role: safeAttr(el, 'role'),
      'aria-label': safeAttr(el, 'aria-label'),
      title: safeAttr(el, 'title'),
      dataset: safeDataset(el),
      visible,
      disabled: !!(el.disabled || el.getAttribute('aria-disabled') === 'true'),
      rect: {
        x: Math.round(rect.x),
        y: Math.round(rect.y),
        w: Math.round(rect.width),
        h: Math.round(rect.height),
      },
    };
  } catch {
    return null;
  }
}

class SidebarInspector {
  async inspect() {
    console.log('[Inspector] Starting sidebar inspection');
    const page = await ChromeManager.getPage();

    const currentUrl = page.url();
    const title = await page.title();
    console.log('[Inspector] Current URL:', currentUrl);
    console.log('[Inspector] Current Title:', title);

    const viewport = await page.viewportSize();
    console.log('[Inspector] Viewport:', viewport);

    // STEP 1
    console.log('[Inspector] Taking screenshot...');
    await page.screenshot({ path: path.join(STORAGE, 'sidebar-debug.png'), fullPage: true });
    console.log('[Inspector] Saved: sidebar-debug.png');

    // STEP 2
    console.log('[Inspector] Saving HTML...');
    const html = await page.content();
    fs.writeFileSync(path.join(STORAGE, 'sidebar.html'), html);
    console.log('[Inspector] Saved: sidebar.html (' + html.length + ' bytes)');

    // STEP 3
    console.log('[Inspector] Scanning DOM...');
    const allElements = await page.evaluate(() => {
      const tags = ['a', 'button', 'span', 'div', 'li', 'nav', 'aside'];
      const seen = new WeakSet();
      const results = [];

      function safeStr(val, max) {
        try {
          if (typeof val === 'string') return max ? val.substring(0, max) : val;
          if (val !== null && val !== undefined && typeof val.toString === 'function') {
            const s = val.toString();
            return max ? s.substring(0, max) : s;
          }
        } catch {}
        return '';
      }

      function safeClassName(el) {
        try {
          if (typeof el.className === 'string') return el.className;
          if (el.className && typeof el.className.baseVal === 'string') return el.className.baseVal;
          const attr = el.getAttribute('class');
          if (typeof attr === 'string') return attr;
        } catch {}
        return '';
      }

      function safeDataset(el) {
        const ds = {};
        try {
          if (el.dataset) {
            for (const key of Object.keys(el.dataset)) {
              try { ds[key] = typeof el.dataset[key] === 'string' ? el.dataset[key] : String(el.dataset[key]); } catch {}
            }
          }
        } catch {}
        return ds;
      }

      function safeGet(el, prop, max) {
        try {
          const val = el[prop];
          return safeStr(val, max);
        } catch {}
        return '';
      }

      function safeAttr(el, attr) {
        try {
          const val = el.getAttribute(attr);
          return typeof val === 'string' ? val : '';
        } catch {}
        return '';
      }

      function extract(el) {
        if (!el || seen.has(el)) return null;
        try {
          seen.add(el);
          const rect = el.getBoundingClientRect();
          const visible = rect.width > 0 && rect.height > 0;
          return {
            tag: el.tagName ? el.tagName.toLowerCase() : '',
            text: safeGet(el, 'textContent', 200).trim(),
            href: safeGet(el, 'href') || safeAttr(el, 'href'),
            id: safeGet(el, 'id'),
            className: safeClassName(el).substring(0, 200),
            role: safeAttr(el, 'role'),
            'aria-label': safeAttr(el, 'aria-label'),
            title: safeAttr(el, 'title'),
            dataset: safeDataset(el),
            visible,
            disabled: !!(el.disabled || el.getAttribute('aria-disabled') === 'true'),
            rect: {
              x: Math.round(rect.x),
              y: Math.round(rect.y),
              w: Math.round(rect.width),
              h: Math.round(rect.height),
            },
          };
        } catch {
          return null;
        }
      }

      // Tag-specific selectors
      for (const tag of tags) {
        const els = document.querySelectorAll(tag);
        for (const el of els) {
          const data = extract(el);
          if (data) results.push(data);
        }
      }

      // Elements with [role] not yet captured
      const roleEls = document.querySelectorAll('[role]');
      for (const el of roleEls) {
        const data = extract(el);
        if (data) results.push(data);
      }

      // Elements with data-* not yet captured
      const all = document.querySelectorAll('*');
      for (const el of all) {
        if (el.dataset && Object.keys(el.dataset).length > 0) {
          const data = extract(el);
          if (data) results.push(data);
        }
      }

      return results;
    });

    console.log('[Inspector] Total elements found:', allElements.length);

    // STEP 5
    console.log('[Inspector] Saving all elements...');
    fs.writeFileSync(
      path.join(STORAGE, 'sidebar-all.json'),
      JSON.stringify(allElements, null, 2)
    );
    console.log('[Inspector] Saved: sidebar-all.json');

    // STEP 4
    console.log('[Inspector] Filtering for navigation keywords...');
    const filtered = allElements.filter(el => {
      const haystack = (
        el.text + ' ' +
        el.href + ' ' +
        el.id + ' ' +
        el.className + ' ' +
        el.role + ' ' +
        el['aria-label'] + ' ' +
        el.title + ' ' +
        Object.values(el.dataset).join(' ')
      ).toLowerCase();

      return KEYWORDS.some(kw => haystack.includes(kw.toLowerCase()));
    });

    console.log('[Inspector] Filtered candidates:', filtered.length);
    fs.writeFileSync(
      path.join(STORAGE, 'sidebar-filtered.json'),
      JSON.stringify(filtered, null, 2)
    );
    console.log('[Inspector] Saved: sidebar-filtered.json');

    // STEP 6
    console.log('[Inspector] Generating tree...');
    const tree = this._buildTree(filtered, currentUrl, title);
    fs.writeFileSync(path.join(STORAGE, 'sidebar-tree.txt'), tree);
    console.log('[Inspector] Saved: sidebar-tree.txt');

    return {
      currentUrl,
      title,
      viewport,
      totalElements: allElements.length,
      candidateCount: filtered.length,
      files: {
        screenshot: 'storage/sidebar-debug.png',
        html: 'storage/sidebar.html',
        allJson: 'storage/sidebar-all.json',
        filteredJson: 'storage/sidebar-filtered.json',
        tree: 'storage/sidebar-tree.txt',
      },
    };
  }

  _buildTree(filtered, url, title) {
    const lines = [];
    lines.push('=== SIDEBAR TREE ===');
    lines.push('URL: ' + url);
    lines.push('Title: ' + title);
    lines.push('');

    const navEls = filtered.filter(e => {
      const role = safeStr(e.role).toLowerCase();
      const tag = e.tag;
      const cls = safeStr(e.className).toLowerCase();
      return (
        tag === 'nav' ||
        role === 'navigation' ||
        role === 'menubar' ||
        role === 'menu' ||
        role === 'menuitem' ||
        cls.includes('menu') ||
        cls.includes('nav') ||
        cls.includes('sidebar') ||
        cls.includes('sider')
      );
    });

    if (navEls.length > 0) {
      lines.push('--- Navigation containers ---');
      for (const el of navEls) {
        const cls = safeStr(el.className);
        lines.push(
          '  <' + el.tag + '>' +
          (el.id ? '#' + el.id : '') +
          (cls ? ' .' + cls.replace(/ /g, '.') : '')
        );
        if (el.role) lines.push('    role: ' + el.role);
        if (el['aria-label']) lines.push('    aria-label: ' + el['aria-label']);
      }
      lines.push('');
    }

    const links = filtered.filter(e => e.tag === 'a' && e.visible);
    if (links.length > 0) {
      lines.push('--- Links (candidates) ---');
      for (const link of links) {
        const text = safeStr(link.text, 60) || '(no text)';
        const href = link.href || (link.dataset ? safeStr(link.dataset.href) : '');
        lines.push('  ' + text);
        if (href) lines.push('    href: ' + href);
        lines.push('    tag: <' + link.tag + '> class: ' + safeStr(link.className, 80));
      }
      lines.push('');
    }

    const buttons = filtered.filter(e => e.tag === 'button' && e.visible);
    if (buttons.length > 0) {
      lines.push('--- Buttons (candidates) ---');
      for (const btn of buttons) {
        lines.push('  ' + (safeStr(btn.text, 60) || '(no text)'));
        if (btn.className) lines.push('    class: ' + safeStr(btn.className, 80));
      }
      lines.push('');
    }

    const other = filtered.filter(e => {
      if (e.tag === 'a' || e.tag === 'button' || navEls.includes(e)) return false;
      return e.visible;
    });

    if (other.length > 0) {
      lines.push('--- Other candidates ---');
      for (const el of other) {
        lines.push('  <' + el.tag + '> ' + (safeStr(el.text, 60) || '(no text)'));
        if (el.role) lines.push('    role: ' + el.role);
        if (el.className) lines.push('    class: ' + safeStr(el.className, 80));
      }
    }

    return lines.join('\n');
  }

  async testSelectors() {
    console.log('[Inspector] Testing selectors');
    const page = await ChromeManager.getPage();

    const selectors = [
      'text=Custom Link',
      'text=Custom link',
      'text=Liên kết tùy chỉnh',
      'text=Custom',
      'text=Link',
      '[href*="custom_link"]',
      '[href*="offer"]',
      '[href*="custom"]',
      '[href*="offer/custom_link"]',
      'button',
      'a',
      'nav a',
      'aside a',
      '[role="menuitem"]',
      '.ant-menu-item',
      '.ant-menu-submenu',
    ];

    const results = [];

    for (const sel of selectors) {
      try {
        const count = await page.locator(sel).count();
        results.push({ selector: sel, count });
        console.log('[Inspector] Selector "' + sel + '" → ' + count + ' match(es)');
      } catch (err) {
        results.push({ selector: sel, count: 0, error: err.message });
        console.log('[Inspector] Selector "' + sel + '" → error: ' + err.message);
      }
    }

    const best = results.filter(r => r.count > 0 && !r.error);
    console.log('[Inspector] Working selectors:', best.length);

    if (best.length > 0) {
      const top = best[0];
      console.log('[Inspector] Highlighting:', top.selector);
      try {
        await page.locator(top.selector).first().evaluate(el => {
          el.style.outline = '4px solid red';
          el.style.outlineOffset = '2px';
        });
        await page.screenshot({ path: path.join(STORAGE, 'selector-highlight.png') });
        console.log('[Inspector] Saved: selector-highlight.png');
      } catch (e) {
        console.log('[Inspector] Highlight failed:', e.message);
      }

      try {
        await page.locator(top.selector).first().evaluate(el => {
          el.style.outline = '';
          el.style.outlineOffset = '';
        });
      } catch {}
    }

    return {
      currentUrl: page.url(),
      results,
      bestSelector: best.length > 0 ? best[0].selector : null,
      bestCount: best.length > 0 ? best[0].count : 0,
      highlighted: best.length > 0,
      screenshot: best.length > 0 ? 'storage/selector-highlight.png' : null,
    };
  }
}

module.exports = new SidebarInspector();
