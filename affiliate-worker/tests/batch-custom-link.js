const axios = require('axios');
const path = require('path');
const fs = require('fs');

const SHOPEE_API = 'https://affiliate.shopee.vn/api/v3/gql?q=batchCustomLink';
const COOKIE_FILE = path.resolve(__dirname, '..', 'storage', 'shopee-cookies.json');

function loadCookieHeader() {
  if (!fs.existsSync(COOKIE_FILE)) return '';
  const cookies = JSON.parse(fs.readFileSync(COOKIE_FILE, 'utf-8'));
  return cookies.map(c => `${c.name}=${c.value}`).join('; ');
}

async function batchCustomLink(productUrl) {
  const headers = {
    'Accept': 'application/json, text/plain, */*',
    'Content-Type': 'application/json',
    'Affiliate-Program-Type': 'affiliate',
    'CSRF-token': '',
    'af-ac-enc-dat': '',
    'af-ac-enc-sz-token': '',
    'x-sap-ri': '',
    'x-sap-sec': '',
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
  };

  const payload = {
    operationName: 'batchGetCustomLink',
    query: `query batchGetCustomLink($linkParams: [LinkParam!]!, $sourceCaller: String) {
  batchGetCustomLink(linkParams: $linkParams, sourceCaller: $sourceCaller) {
    ... on BatchGetCustomLinkResult {
      customLinks {
        ... on CustomLinkResult {
          errorCode
          errorMessage
          originalLink
          shortLink
          webLink
          deeplink
          qrCodeUrl
          __typename
        }
        __typename
      }
      __typename
    }
    ... on BatchGetCustomLinkError {
      errorCode
      errorMessage
      __typename
    }
    __typename
  }
}`,
    variables: {
      linkParams: [
        {
          originalLink: productUrl,
          advancedLinkParams: {},
        },
      ],
      sourceCaller: 'CUSTOM_LINK_CALLER',
    },
  };

  try {
    const response = await axios.post(SHOPEE_API, payload, {
      headers,
      timeout: 15000,
    });

    return {
      status: response.status,
      headers: response.headers,
      body: response.data,
    };
  } catch (err) {
    return {
      status: err.response?.status ?? 0,
      error: err.message,
      body: err.response?.data ?? null,
    };
  }
}

async function batchCustomLinkWithCookies(productUrl) {
  const cookieHeader = loadCookieHeader();

  if (!cookieHeader) {
    return { success: false, error: 'Cookie file not found. Run export-cookies first.' };
  }

  const headers = {
    'Accept': 'application/json, text/plain, */*',
    'Content-Type': 'application/json',
    'Affiliate-Program-Type': 'affiliate',
    'Cookie': cookieHeader,
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
  };

  const payload = {
    operationName: 'batchGetCustomLink',
    query: `query batchGetCustomLink($linkParams: [LinkParam!]!, $sourceCaller: String) {
  batchGetCustomLink(linkParams: $linkParams, sourceCaller: $sourceCaller) {
    ... on BatchGetCustomLinkResult {
      customLinks {
        ... on CustomLinkResult {
          errorCode
          errorMessage
          originalLink
          shortLink
          webLink
          deeplink
          qrCodeUrl
          __typename
        }
        __typename
      }
      __typename
    }
    ... on BatchGetCustomLinkError {
      errorCode
      errorMessage
      __typename
    }
    __typename
  }
}`,
    variables: {
      linkParams: [
        {
          originalLink: productUrl,
          advancedLinkParams: {},
        },
      ],
      sourceCaller: 'CUSTOM_LINK_CALLER',
    },
  };

  try {
    const response = await axios.post(SHOPEE_API, payload, {
      headers,
      timeout: 15000,
    });

    return {
      status: response.status,
      body: response.data,
    };
  } catch (err) {
    return {
      status: err.response?.status ?? 0,
      error: err.message,
      body: err.response?.data ?? null,
    };
  }
}

module.exports = { batchCustomLink, batchCustomLinkWithCookies };
