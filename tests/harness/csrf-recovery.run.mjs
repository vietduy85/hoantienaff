/**
 * T2 v2 REAL SUBMIT-PATH harness (Node, no browser required).
 *
 * Đọc TRỰC TIẾP resources/views/dashboard/partials/link-generator.blade.php,
 * trích nội dung x-data (đúng code được Alpine render), thay các Blade token bằng
 * giá trị mô phỏng, rồi chạy ĐÚNG submit() -> post() -> 419 -> ensureFreshCsrf()
 * -> retry với fetch giả lập. Assert thứ tự + số lần gọi.
 *
 * Chạy: node tests/harness/csrf-recovery.run.mjs
 * (PHPUnit wrapper: Tests\Feature\CsrfRecoveryHarnessRunTest)
 *
 * Test này sẽ FAIL nếu nhánh 419 bị bỏ khỏi submit flow.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BLADE = path.resolve(__dirname, '../../resources/views/dashboard/partials/link-generator.blade.php');

let failures = 0;
let checks = 0;

function ok(cond, msg) {
  checks++;
  if (!cond) {
    failures++;
    console.error('FAIL: ' + msg);
  }
}

function eq(actual, expected, msg) {
  ok(String(actual) === String(expected), `${msg} (expected=${JSON.stringify(expected)}, actual=${JSON.stringify(actual)})`);
}

const flush = () => new Promise((res) => setImmediate(res));

// ---- 1. Extract x-data attribute from the LIVE blade file ----
function extractXData(src) {
  const start = src.indexOf('x-data="');
  ok(start !== -1, 'x-data attribute present');
  if (start === -1) return null;

  // Object literal dùng toàn bộ string đơn, thuộc tính đóng bởi `"` đầu tiên
  // sau nội dung (chi có đúng 1 cặp quote — nội dung JS dùng single-quote).
  const rest = src.slice(start + 8);
  const end = rest.indexOf('"');
  if (end === -1) {
    ok(false, 'x-data closing quote found');
    return null;
  }
  return rest.slice(0, end);
}

function decodeEntities(s) {
  return s
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&apos;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/&gt;/g, '>')
    .replace(/&lt;/g, '<');
}

function replaceBladeTokens(xdata) {
  const tokens = [
    ["{{ route('link-requests.store') }}", '/link-requests'],
    ["{{ route('csrf-token') }}", '/csrf-token'],
    ["{{ route('login') }}", '/login'],
    ['{{ csrf_token() }}', 'tok-server'],
    ["{{ $page_instance_id ?? '' }}", 'PAGE-HARNESS'],
  ];
  for (const [from, to] of tokens) {
    while (xdata.includes(from)) xdata = xdata.replace(from, to);
  }
  // Blade giữ entity &quot; trong attribute; giải mã về quote thường cho JS.
  return decodeEntities(xdata);
}

const bladeSrc = readFileSync(BLADE, 'utf8');
let XDATA = extractXData(bladeSrc);
if (XDATA) {
  XDATA = replaceBladeTokens(XDATA);
} else {
  XDATA = null;
}

// ---- 2. Evaluate component with mocked browser env ----
function makeEnv(fetchMock) {
  const listeners = {};
  const docListeners = {};
  const env = {
    window: {
      __t2Forensic: { page_instance_id: 'PAGE-HARNESS', events: [] },
      __csrfPromise: null,
      crypto: globalThis.crypto,
      location: { pathname: '/dashboard', search: '', href: '' },
      addEventListener: (ev, cb) => { (listeners[`w:${ev}`] = listeners[`w:${ev}`] || []).push(cb); },
      dispatch: (ev) => { (listeners[`w:${ev}`] || []).forEach((cb) => cb()); },
    },
    document: {
      visibilityState: 'visible',
      querySelector: () => null,
      addEventListener: (ev, cb) => { (docListeners[ev] = docListeners[ev] || []).push(cb); },
      dispatch: (ev) => { (docListeners[ev] || []).forEach((cb) => cb()); },
    },
    fetch: fetchMock,
    crypto: globalThis.crypto,
    TextEncoder: globalThis.TextEncoder,
    setTimeout,
    clearTimeout,
    navigator: { clipboard: { writeText: () => {} } },
    console,
  };
  env.document.dispatchVisible = () => { env.document.dispatch('visibilitychange'); };
  return env;
}

function makeComponent(env) {
  // eslint-disable-next-line no-new-func
  const factory = new Function(
    'window', 'document', 'fetch', 'crypto', 'TextEncoder',
    'setTimeout', 'clearTimeout', 'navigator', 'console',
    'return (' + XDATA + ');'
  );
  const comp = factory(
    env.window, env.document, env.fetch, env.crypto, env.TextEncoder,
    env.setTimeout, env.clearTimeout, env.navigator, env.console
  );
  comp.$nextTick = (cb) => cb();
  comp.$refs = { urlInput: { focus() {}, select() {} } };
  comp.$root = {};
  comp.init();
  comp.url = 'https://shopee.vn/product/123';
  return comp;
}

// ---- 3. Fetch mocks ----
function jsonResp(status, body, okStatus) {
  return { ok: okStatus === undefined ? status >= 200 && status < 300 : okStatus, status, json: async () => body };
}

function serverFetch(scenario) {
  let postCount = 0;
  let csrfCount = 0;
  const calls = [];
  const fn = async (url, init = {}) => {
    const method = (init.method || 'GET').toUpperCase();
    const headers = init.headers || {};
    const entry = { method, url, headers };
    calls.push(entry);
    const ret = scenario({ method, url, headers, postCount, csrfCount, entry });
    if (method === 'POST' && url.includes('/link-requests')) postCount++;
    if (method === 'GET' && url.includes('/csrf-token')) csrfCount++;
    return ret;
  };
  fn.calls = calls;
  fn.counts = () => ({ posts: postCount, csrfs: csrfCount });
  return fn;
}

// TESTS
const tests = [];

tests.push('TEST 1: correct token -> 200, no GET /csrf-token', async () => {
  const fetchMock = serverFetch(({ method, url }) => {
    if (url.includes('/api/link-request/')) return jsonResp(200, { status: 'completed', affiliate_url: 'https://s' });
    if (method === 'POST') return jsonResp(200, { success: true, request_id: 11, affiliate_url: 'https://s', platform: 'Shopee' });
    throw new Error('unexpected ' + method + ' ' + url);
  });
  const env = makeEnv(fetchMock);
  const comp = makeComponent(env);
  comp.submit();
  await flush(); await flush();

  eq(fetchMock.counts().posts, 1, 'exactly 1 POST');
  eq(fetchMock.counts().csrfs, 0, 'no GET /csrf-token');
  eq(comp.loading, false, 'loading resolved');
});

tests.push('TEST 2: stale token -> 419 -> GET /csrf-token(1) -> POST retry(2) -> 200', async () => {
  const calls = [];
  const fetchMock = serverFetch(({ method, url, headers, postCount }) => {
    calls.push({ method, url, token: headers['X-CSRF-TOKEN'] || headers['x-csrf-token'] || null });
    if (url.includes('/api/link-request/')) return jsonResp(200, { status: 'completed' });
    if (url.includes('/csrf-token')) return jsonResp(200, { token: 'TOK-NEW' });
    if (method === 'POST') {
      return postCount === 0
        ? jsonResp(419, { message: 'CSRF token mismatch.' })
        : jsonResp(200, { success: true, request_id: 12, affiliate_url: 'https://s', platform: 'Shopee' });
    }
    throw new Error('unexpected ' + method + ' ' + url);
  });
  const env = makeEnv(fetchMock);
  const comp = makeComponent(env);
  comp.submit();
  await flush(); await flush(); await flush();

  eq(fetchMock.counts().posts, 2, 'retry exactly once -> total 2 POSTs');
  eq(fetchMock.counts().csrfs, 1, 'exactly 1 GET /csrf-token');
  const posts = calls.filter((c) => c.url.includes('/link-requests') && c.url.indexOf('/api/') === -1);
  eq(posts.length, 2, '2 POST to /link-requests recorded');
  eq(posts[0].token, 'tok-server', 'initial POST uses old DOM token');
  eq(posts[1].token, 'TOK-NEW', 'retry POST uses refreshed token');
  eq(comp.loading, false, 'flow resolved');
});

tests.push('TEST 3: 419 -> refresh endpoint fails -> showSessionExpired, NO retry', async () => {
  const fetchMock = serverFetch(({ method, url }) => {
    if (url.includes('/csrf-token')) return jsonResp(500, {}, false);
    if (method === 'POST') return jsonResp(419, { message: 'CSRF token mismatch.' });
    throw new Error('unexpected ' + method + ' ' + url);
  });
  const env = makeEnv(fetchMock);
  const comp = makeComponent(env);
  comp.submit();
  await flush(); await flush(); await flush();

  eq(fetchMock.counts().posts, 1, 'NO retry POST after refresh fail');
  eq(fetchMock.counts().csrfs, 1, 'refresh attempted once');
  eq(comp.loading, false, 'loading resolved');
  ok(String(comp.error).includes('hết hạn'), 'session expired message shown');
});

tests.push('TEST 4: 419 -> refresh OK -> retry still 419 -> showSessionExpired, NO POST #3', async () => {
  const fetchMock = serverFetch(({ method, url }) => {
    if (url.includes('/csrf-token')) return jsonResp(200, { token: 'TOK-NEW' });
    if (method === 'POST') return jsonResp(419, { message: 'CSRF token mismatch.' });
    throw new Error('unexpected ' + method + ' ' + url);
  });
  const env = makeEnv(fetchMock);
  const comp = makeComponent(env);
  comp.submit();
  await flush(); await flush(); await flush();

  eq(fetchMock.counts().posts, 2, 'exactly 2 POSTs (no 3rd)');
  eq(fetchMock.counts().csrfs, 1, 'exactly 1 GET /csrf-token');
  ok(String(comp.error).includes('hết hạn'), 'session expired message shown');
});

tests.push('TEST 5: 3 concurrent 419 -> single-flight -> exactly 1 GET /csrf-token', async () => {
  const fetchMock = serverFetch(({ method, url, postCount }) => {
    if (url.includes('/api/link-request/')) return jsonResp(200, { status: 'completed' });
    if (url.includes('/csrf-token')) return jsonResp(200, { token: 'TOK-NEW' });
    if (method === 'POST') {
      // 3 initial 419, sau đó mọi retry 200
      return postCount < 3
        ? jsonResp(419, { message: 'CSRF token mismatch.' })
        : jsonResp(200, { success: true, request_id: 13, affiliate_url: 'https://s', platform: 'Shopee' });
    }
    throw new Error('unexpected ' + method + ' ' + url);
  });
  const env = makeEnv(fetchMock);
  const comp = makeComponent(env);

  // 3 request đang bay song song (post() là hàm thật xử lý 419; loading guard
  // của submit() chỉ chặn double-click, không liên quan requests đang in-flight).
  await Promise.all([comp.post(), comp.post(), comp.post()]);
  await flush(); await flush();

  eq(fetchMock.counts().csrfs, 1, 'CHỈ 1 GET /csrf-token cho 3 request concurrent');
  eq(fetchMock.counts().posts, 6, '3 initial + 3 retry = 6 POSTs');
});

tests.push('TEST 6: 422 -> existing validation, NO refresh, NO retry', async () => {
  const fetchMock = serverFetch(({ method, url }) => {
    if (method === 'POST' && url.includes('/link-requests')) {
      return jsonResp(422, { message: 'Đường dẫn không hợp lệ.', errors: { original_url: ['Đường dẫn không hợp lệ.'] } });
    }
    throw new Error('unexpected ' + method + ' ' + url);
  });
  const env = makeEnv(fetchMock);
  const comp = makeComponent(env);
  comp.submit();
  await flush(); await flush();

  eq(fetchMock.counts().posts, 1, 'no retry on 422');
  eq(fetchMock.counts().csrfs, 0, 'no GET /csrf-token on 422');
  ok(String(comp.error).includes('Đường dẫn không hợp lệ'), 'validation message shown');
});

tests.push('TEST 7: network failure -> existing network error, NO refresh', async () => {
  const fetchMock = serverFetch(({ method, url }) => {
    if (method === 'POST' && url.includes('/link-requests')) throw new TypeError('Failed to fetch');
    throw new Error('unexpected ' + method + ' ' + url);
  });
  const env = makeEnv(fetchMock);
  const comp = makeComponent(env);
  comp.submit();
  await flush(); await flush();

  eq(fetchMock.counts().posts, 0, 'post never counted (fetch threw)');
  eq(fetchMock.counts().csrfs, 0, 'no refresh on network error');
  eq(comp.error, 'Không thể kết nối máy chủ', 'network error message shown');
});

tests.push('TEST 8: /csrf-token 401 (session gone) -> showSessionExpired, NO retry', async () => {
  const fetchMock = serverFetch(({ method, url }) => {
    if (url.includes('/csrf-token')) return jsonResp(401, { message: 'Unauthenticated.' }, false);
    if (method === 'POST') return jsonResp(419, { message: 'CSRF token mismatch.' });
    throw new Error('unexpected ' + method + ' ' + url);
  });
  const env = makeEnv(fetchMock);
  const comp = makeComponent(env);
  comp.submit();
  await flush(); await flush(); await flush();

  eq(fetchMock.counts().posts, 1, 'NO retry when session is gone');
  eq(fetchMock.counts().csrfs, 1, 'refresh attempted');
  ok(String(comp.error).includes('hết hạn'), 'session expired message shown');
});

tests.push('TEST 9: session rotation (old DOM token -> 419 -> current token -> POST 200)', async () => {
  const calls = [];
  const fetchMock = serverFetch(({ method, url, headers, postCount }) => {
    calls.push({ method, url, token: headers['X-CSRF-TOKEN'] || null });
    if (url.includes('/csrf-token')) return jsonResp(200, { token: 'ROTATED-TOKEN' });
    if (method === 'POST') {
      return postCount === 0
        ? jsonResp(419, { message: 'CSRF token mismatch.' })
        : jsonResp(200, { success: true, request_id: 14, affiliate_url: 'https://s', platform: 'Shopee' });
    }
    throw new Error('unexpected ' + method + ' ' + url);
  });
  const env = makeEnv(fetchMock);
  const comp = makeComponent(env);
  comp.submit();
  await flush(); await flush(); await flush();

  const posts = calls.filter((c) => c.method === 'POST' && c.url.includes('/link-requests'));
  eq(posts.length, 2, 'POST → 419 → refresh → POST retry, exactly 2');
  eq(posts[0].token, 'tok-server', 'POST#1 used old DOM token');
  eq(posts[1].token, 'ROTATED-TOKEN', 'POST#2 used current rotated token');
  eq(comp.result && comp.result.request_id, 14, 'success result exposed after retry');
});

tests.push('TEST 10: tab hidden/resume -> visibility probe refresh (throttled), submit uses new token', async () => {
  let csrfGet = 0;
  const fetchMock = serverFetch(({ method, url, headers }) => {
    if (url.includes('/csrf-token')) { csrfGet++; return jsonResp(200, { token: 'RESUME-TOKEN' }); }
    if (method === 'POST') return jsonResp(200, { success: true, request_id: 15, affiliate_url: 'https://s', platform: 'Shopee' });
    throw new Error('unexpected ' + method + ' ' + url);
  });
  const env = makeEnv(fetchMock);
  const comp = makeComponent(env);

  // resume visible
  env.document.dispatchVisible();
  await flush(); await flush();
  eq(csrfGet, 1, 'visibility probe triggered 1 GET /csrf-token');

  // throttle: dispatch lại trong 10s => không refresh thêm
  env.document.dispatchVisible();
  await flush();
  eq(csrfGet, 1, 'probe throttled (no second GET within 10s)');

  // submit sau khi resume phải dùng token mới
  comp.url = 'https://shopee.vn/product/456';
  comp.submit();
  await flush(); await flush();
  const posts = fetchMock.calls.filter((c) => c.method === 'POST' && c.url.includes('/link-requests'));
  eq(posts.length, 1, 'submit after resume issues 1 POST');
  eq(posts[0].headers['X-CSRF-TOKEN'], 'RESUME-TOKEN', 'POST uses the resumed (fresh) token');
});

// ---- 4. Run ----
if (!XDATA) {
  console.error('cannot extract x-data from blade');
  process.exit(2);
}

(async () => {
  for (let i = 0; i < tests.length; i += 2) {
    const name = tests[i];
    const fn = tests[i + 1];
    try {
      await fn();
      console.log('PASS  ' + name);
    } catch (e) {
      failures++;
      console.error('FAIL  ' + name + ' :: ' + (e && e.stack || e));
    }
  }
  console.log(`\nRESULT: ${checks - failures}/${checks} checks passed.`);
  process.exit(failures === 0 ? 0 : 1);
})();