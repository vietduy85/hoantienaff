const ChromeManager = require('./ChromeManager');

class ModalParser {
  async waitAndParse() {
    const page = await ChromeManager.getPage();

    const modalTextarea = await page.waitForSelector(
      '.ant-modal textarea, .modal textarea, div[role="dialog"] textarea',
      { timeout: 15000 }
    );

    console.log('[Parser] Modal appeared');

    const text = await modalTextarea.inputValue();
    const lines = text.split('\n').filter(l => l.trim().length > 0);

    const results = lines.map(line => {
      const parts = line.trim().split(/\s+/);
      return {
        original: parts[0] || '',
        short: parts[1] || '',
      };
    });

    console.log('[Parser] Found', results.length, 'short link(s)');

    const closeBtn = await page.$(
      '.ant-modal-close, .modal-close, button:has-text("Đóng"), button:has-text("Close")'
    );
    if (closeBtn) {
      await closeBtn.click();
      console.log('[Parser] Modal closed');
    }

    return results;
  }
}

module.exports = new ModalParser();
