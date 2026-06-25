const ChromeManager = require('./ChromeManager');

class CustomLinkForm {
  async fillUrls(urls) {
    const page = await ChromeManager.getPage();

    const textarea = await page.waitForSelector('textarea.ant-input, textarea', {
      timeout: 10000,
    });

    console.log('[Form] Found textarea');

    const inputText = urls.join('\n');
    await textarea.fill(inputText);
    console.log('[Form] Input', urls.length, 'URL(s)');

    const submitBtn = await page.waitForSelector(
      'button:has-text("Lấy link"), button:has-text("Get Link"), button[type="submit"]',
      { timeout: 10000 }
    );

    console.log('[Form] Clicking submit');
    await submitBtn.click();

    return page;
  }
}

module.exports = new CustomLinkForm();
