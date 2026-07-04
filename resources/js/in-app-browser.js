function detectInAppBrowser() {
  const ua = navigator.userAgent.toLowerCase()

  const patterns = [
    { name: 'Zalo', regex: /zalo/i },
    { name: 'Facebook', regex: /fb_iab|fban|fbav/i },
    { name: 'Messenger', regex: /messenger/i },
    { name: 'Instagram', regex: /instagram/i },
    { name: 'Telegram', regex: /telegram/i },
    { name: 'TikTok', regex: /tiktok/i },
  ]

  for (const p of patterns) {
    if (p.regex.test(ua)) return p.name
  }

  return null
}

function detectOS() {
  const ua = navigator.userAgent.toLowerCase()
  if (/iphone|ipad|ipod/.test(ua)) return 'ios'
  if (/android/.test(ua)) return 'android'
  return 'desktop'
}

function openInChrome(url) {
  const clean = url.replace(/^https?:\/\//, '')
  const intent = `intent://${clean}#Intent;scheme=https;action=android.intent.action.VIEW;package=com.android.chrome;end`
  window.location.href = intent
}

function openInBrowser(url) {
  if (detectOS() === 'android') {
    openInChrome(url)
  } else {
    const win = window.open(url, '_blank')
    if (!win || win.closed || typeof win.closed === 'undefined') {
      window.location.href = url
    }
  }
}

function copyText(text) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    return navigator.clipboard.writeText(text)
  }
  const el = document.createElement('textarea')
  el.value = text
  el.style.position = 'fixed'
  el.style.opacity = '0'
  document.body.appendChild(el)
  el.select()
  document.execCommand('copy')
  document.body.removeChild(el)
  return Promise.resolve()
}

const detected = detectInAppBrowser()
const os = detectOS()

window.__inAppBrowser = detected
window.__os = os
window.__isMobile = os !== 'desktop'
window.__isIOS = os === 'ios'
window.__isAndroid = os === 'android'

window.__openInBrowser = openInBrowser

export { detectInAppBrowser, detectOS, openInChrome, openInBrowser, copyText }
