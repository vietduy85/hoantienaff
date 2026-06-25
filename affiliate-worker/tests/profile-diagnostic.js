const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const os = require('os');
const { execSync } = require('child_process');

const USER_DATA_DIR = 'C:\\Users\\Administrator\\AppData\\Local\\Google\\Chrome\\User Data';
const PROFILE_DIR = 'Profile 4';
const DIAG_ROOT = 'C:\\PlaywrightDiagnostic';
const CLONE_DIR = path.join(DIAG_ROOT, 'UserData');
const NEW_PROFILE_DIR = path.join(DIAG_ROOT, 'NewProfile');

async function runDiagnostic() {
  const result = {};

  // TEST 1
  result.nodeVersion = process.version;
  result.playwrightVersion = require('playwright/package.json').version;
  result.os = `${os.type()} ${os.release()}`;

  // TEST 2
  const chromePaths = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
  ];
  result.chromePaths = {};
  for (const p of chromePaths) {
    const found = fs.existsSync(p);
    result.chromePaths[p] = found ? 'FOUND' : 'NOT FOUND';
    if (found) {
      try {
        const ver = execSync(`"${p}" --version`, { timeout: 5000 }).toString().trim();
        result.chromeVersion = ver;
      } catch { result.chromeVersion = 'unknown'; }
    }
  }

  // TEST 3
  result.userDataDir = USER_DATA_DIR;
  result.userDataExists = fs.existsSync(USER_DATA_DIR) ? 'FOUND' : 'NOT FOUND';
  const profiles = ['Default', 'Profile 1', 'Profile 2', 'Profile 3', 'Profile 4', 'Profile 5'];
  result.profiles = {};
  for (const prof of profiles) {
    result.profiles[prof] = fs.existsSync(path.join(USER_DATA_DIR, prof)) ? 'FOUND' : 'NOT FOUND';
  }

  // TEST 4
  const singletons = ['SingletonLock', 'SingletonCookie', 'SingletonSocket'];
  result.singletons = {};
  for (const s of singletons) {
    result.singletons[s] = fs.existsSync(path.join(USER_DATA_DIR, s)) ? 'FOUND' : 'NOT FOUND';
  }

  // TEST 5
  try {
    const tasklist = execSync('tasklist /FI "IMAGENAME eq chrome.exe" /NH', { timeout: 5000 }).toString();
    const lines = tasklist.split('\n').filter(l => l.trim().length > 0);
    if (lines.length === 0 || tasklist.includes('No tasks are running')) {
      result.chromeProcesses = [];
      result.chromeRunning = false;
    } else {
      result.chromeProcesses = lines.map(l => l.trim().split(/\s+/)[1]).filter(Boolean);
      result.chromeRunning = true;
    }
  } catch { result.chromeProcesses = []; result.chromeRunning = false; }

  // TEST 6
  if (result.chromeRunning) {
    result.test6 = 'Chrome is still running.';
  } else {
    result.test6 = 'No Chrome running.';
  }

  // TEST 7
  result.test7 = {};
  if (!result.chromeRunning) {
    try {
      const ctx = await chromium.launchPersistentContext(USER_DATA_DIR, {
        channel: 'chrome',
        headless: false,
        args: [`--profile-directory=${PROFILE_DIR}`],
      });
      const p = ctx.pages()[0] || await ctx.newPage();
      await p.goto('about:blank', { timeout: 30000 });
      await ctx.close();
      result.test7.status = 'SUCCESS';
    } catch (err) {
      result.test7.status = 'ERROR';
      result.test7.stack = err.stack || err.message;
    }
  } else {
    result.test7.status = 'SKIPPED (Chrome running)';
  }

  // TEST 8
  result.test8 = {};
  if (result.test7.status === 'ERROR') {
    try {
      if (!fs.existsSync(CLONE_DIR)) {
        copyDirSync(USER_DATA_DIR, CLONE_DIR);
      }
      const ctx = await chromium.launchPersistentContext(CLONE_DIR, {
        channel: 'chrome',
        headless: false,
      });
      const p = ctx.pages()[0] || await ctx.newPage();
      await p.goto('about:blank', { timeout: 30000 });
      await ctx.close();
      result.test8.status = 'SUCCESS_CLONE';
    } catch (err) {
      result.test8.status = 'ERROR';
      result.test8.stack = err.stack || err.message;
    }
  } else {
    result.test8.status = 'SKIPPED';
  }

  // TEST 9
  result.test9 = {};
  if (result.test8.status === 'ERROR') {
    try {
      const ctx = await chromium.launchPersistentContext(NEW_PROFILE_DIR, {
        channel: 'chrome',
        headless: false,
      });
      const p = ctx.pages()[0] || await ctx.newPage();
      await p.goto('about:blank', { timeout: 30000 });
      await ctx.close();
      result.test9.status = 'SUCCESS_EMPTY';
    } catch (err) {
      result.test9.status = 'ERROR';
      result.test9.stack = err.stack || err.message;
    }
  } else {
    result.test9.status = 'SKIPPED';
  }

  // TEST 10
  if (result.test9.status === 'SUCCESS_EMPTY') {
    result.test10 = 'Playwright hoạt động bình thường. Vấn đề nằm ở Profile.';
  }

  // TEST 11
  if (result.test7.status === 'ERROR' && result.test8.status === 'ERROR' && result.test9.status === 'ERROR') {
    result.test11 = 'Playwright hoặc Chrome configuration có vấn đề.';
  }

  // TEST 12 - channel vs executablePath
  result.test12 = {};
  try {
    // A: channel
    try {
      const ctxA = await chromium.launchPersistentContext(USER_DATA_DIR, {
        channel: 'chrome',
        headless: false,
        args: [`--profile-directory=${PROFILE_DIR}`],
      });
      await ctxA.close();
      result.test12.channel = 'SUCCESS';
    } catch (err) {
      result.test12.channel = 'ERROR';
      result.test12.channelStack = err.stack || err.message;
    }

    // B: executablePath
    try {
      const exe = chromePaths.find(p => fs.existsSync(p));
      if (exe) {
        const ctxB = await chromium.launchPersistentContext(USER_DATA_DIR, {
          executablePath: exe,
          headless: false,
          args: [`--profile-directory=${PROFILE_DIR}`],
        });
        await ctxB.close();
        result.test12.executablePath = 'SUCCESS';
      } else {
        result.test12.executablePath = 'SKIPPED (no chrome.exe found)';
      }
    } catch (err) {
      result.test12.executablePath = 'ERROR';
      result.test12.executablePathStack = err.stack || err.message;
    }
  } catch { result.test12.error = 'Test 12 failed'; }

  // TEST 13 - Open Profile 4 with real Chrome first
  result.test13 = {};
  result.test13.note = 'Run manually: chrome.exe --profile-directory="Profile 4", close, wait 10s, then call diagnostic again';

  // Write result file
  const outputFile = path.resolve(__dirname, '..', 'storage', 'profile-diagnostic.json');
  fs.mkdirSync(path.dirname(outputFile), { recursive: true });
  fs.writeFileSync(outputFile, JSON.stringify(result, null, 2));

  return result;
}

function copyDirSync(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  const entries = fs.readdirSync(src, { withFileTypes: true });
  for (const entry of entries) {
    const srcPath = path.join(src, entry.name);
    const destPath = path.join(dest, entry.name);
    if (entry.isDirectory()) {
      copyDirSync(srcPath, destPath);
    } else {
      fs.copyFileSync(srcPath, destPath);
    }
  }
}

module.exports = { runDiagnostic };
