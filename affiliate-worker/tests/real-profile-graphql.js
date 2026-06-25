const { chromium } = require('playwright');

const USER_DATA_DIR = 'C:\\Users\\Administrator\\AppData\\Local\\Google\\Chrome\\User Data';
const PROFILE_DIR = 'Profile 4';

async function realProfileGraphql(productUrl) {
  const context = await chromium.launchPersistentContext(USER_DATA_DIR, {
    channel: 'chrome',
    headless: false,
    args: [`--profile-directory=${PROFILE_DIR}`],
  });

  const page = context.pages()[0] || await context.newPage();

  try {
    await page.goto('https://affiliate.shopee.vn/offer/custom_link', {
      waitUntil: 'networkidle',
      timeout: 30000,
    });

    const currentUrl = page.url();
    const title = await page.title();
    console.log('URL:', currentUrl);
    console.log('Title:', title);

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
    await context.close();
  }
}

module.exports = { realProfileGraphql };
