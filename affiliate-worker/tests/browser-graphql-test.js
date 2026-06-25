const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const STATE_FILE = path.resolve(__dirname, '..', 'storage', 'shopee-state.json');

async function browserGraphqlTest(productUrl) {
  if (!fs.existsSync(STATE_FILE)) {
    return { success: false, error: 'Session not found' };
  }

  const browser = await chromium.launch({ headless: false });
  const context = await browser.newContext({ storageState: STATE_FILE });
  const page = await context.newPage();

  try {
    await page.goto('https://affiliate.shopee.vn/offer/custom_link', {
      waitUntil: 'networkidle',
      timeout: 30000,
    });

    const result = await page.evaluate(async (url) => {
      const payload = {
        operationName: 'batchGetCustomLink',
        query: `
          query batchGetCustomLink($linkParams: [CustomLinkParam!], $sourceCaller: SourceCaller){
            batchCustomLink(linkParams: $linkParams, sourceCaller: $sourceCaller){
              shortLink
              longLink
              failCode
            }
          }
        `,
        variables: {
          linkParams: [
            {
              originalLink: url,
              advancedLinkParams: {},
            },
          ],
          sourceCaller: 'CUSTOM_LINK_CALLER',
        },
      };

      const resp = await fetch(
        'https://affiliate.shopee.vn/api/v3/gql?q=batchCustomLink',
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        }
      );

      const responseBody = await resp.json();

      return {
        url: window.location.href,
        cookieLength: document.cookie.length,
        localStorageKeys: Object.keys(localStorage),
        sessionStorageKeys: Object.keys(sessionStorage),
        responseStatus: resp.status,
        responseBody,
      };
    }, productUrl || 'https://shopee.vn/product/123');

    return { success: true, ...result };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await browser.close();
  }
}

module.exports = { browserGraphqlTest };
