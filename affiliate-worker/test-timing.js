const http = require('http');

const TEST_URL = 'https://shopee.vn/N%C6%B0%E1%BB%9Bc-c%C3%A2n-b%E1%BA%B1ng-Caryophy-300ml-(Caryophy-Portulaca-Toner-300ml)-i.219269566.6415343375';
const CDP_URL = 'http://127.0.0.1:9222';

function checkCdp() {
  return new Promise((resolve) => {
    const req = http.get(`${CDP_URL}/json/version`, (res) => {
      let data = '';
      res.on('data', c => data += c);
      res.on('end', () => resolve(true));
    });
    req.on('error', (err) => resolve(false));
    req.setTimeout(3000, () => { req.destroy(); resolve(false); });
  });
}

async function run() {
  console.log('========================================');
  console.log('  Affiliate Link Timing Test');
  console.log('========================================\n');
  console.log('URL:', TEST_URL, '\n');

  // Step 1: Check Chrome CDP
  console.log('[1/4] Checking Chrome CDP on port 9222...');
  const cdpOk = await checkCdp();
  if (!cdpOk) {
    console.error('\n========================================');
    console.error('  ERROR: Chrome CDP not available');
    console.error('========================================');
    console.error('');
    console.error('  Chrome is not running with');
    console.error('  --remote-debugging-port=9222');
    console.error('');
    console.error('  Please run start-affiliate-system.bat');
    console.error('  or start Chrome manually:');
    console.error('');
    console.error('  chrome.exe --remote-debugging-port=9222');
    console.error('    --user-data-dir=...\\shopee-chrome-profile');
    console.error('');
    process.exit(1);
  }
  console.log('  [OK] Chrome CDP connected\n');

  // Step 2: Load worker
  console.log('[2/4] Loading CustomLinkWorker...');
  let CustomLinkWorker;
  try {
    CustomLinkWorker = require('./playwright/cdp/CustomLinkWorker');
    console.log('  [OK]\n');
  } catch (err) {
    console.error('  [FAIL]', err.message);
    process.exit(1);
  }

  // Step 3: Run
  console.log('[3/4] Creating affiliate link...\n');
  try {
    const result = await CustomLinkWorker.createAffiliateLink(TEST_URL);

    console.log('\n========================================');
    console.log('  RESULT');
    console.log('========================================');
    console.log('');
    console.log('  Affiliate URL    :', result.shortLink);
    console.log('  Elapsed          :', result.elapsed + 'ms');
    console.log('  GraphQL Found    :', result.graphqlFound);
    console.log('  UI Fallback      :', result.uiFallback);
    console.log('  Debug Folder     :', result.debugFolder);
    console.log('');
  } catch (err) {
    console.error('\n========================================');
    console.error('  ERROR');
    console.error('========================================');
    console.error('');
    console.error(' ', err.message);
    console.error('');
  }

  // Step 4: Done
  console.log('[4/4] Done\n');
}

run().catch(err => {
  console.error('Unhandled error:', err);
  process.exit(1);
});
