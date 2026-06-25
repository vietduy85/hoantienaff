const { chromium } = require('playwright');

const CDP_URL = 'http://127.0.0.1:9222';

async function cdpGraphqlTest(productUrl) {
  const browser = await chromium.connectOverCDP(CDP_URL);
  const defaultContext = browser.contexts()[0];
  const page = defaultContext.pages()[0] || await defaultContext.newPage();

  try {
    await page.goto('https://affiliate.shopee.vn/offer/custom_link', {
      waitUntil: 'networkidle',
      timeout: 30000,
    });

    const currentUrl = page.url();
    const title = await page.title();

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
        responseStatus: resp.status,
        responseBody,
      };
    }, productUrl || 'https://shopee.vn/product/123');

    return {
      success: true,
      currentUrl,
      title,
      ...result,
    };
  } catch (err) {
    return { success: false, error: err.message };
  } finally {
    await browser.close();
  }
}

module.exports = { cdpGraphqlTest };
