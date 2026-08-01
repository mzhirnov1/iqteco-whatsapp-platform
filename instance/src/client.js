'use strict';

const { Client, RemoteAuth } = require('whatsapp-web.js');

// Must line up with the Chromium actually shipped in the image (120, Debian).
// The library default claims "Macintosh; Intel Mac OS X 10_14_0 ... Chrome/101"
// — macOS Mojave and a browser from April 2022 — while the engine underneath is
// Chromium 120 on Linux. Platform, version and age all contradict the real
// runtime, and every instance we run sends that same string from one IP.
const DEFAULT_USER_AGENT =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// wppconnect-team/wa-version mirrors released WhatsApp Web builds as HTML.
const WA_VERSION_MIRROR = 'https://raw.githubusercontent.com/wppconnect-team/wa-version/main/html';

function createClient({
  store,
  idInstance,
  backupSyncIntervalMs = 60000,
  executablePath,
  waWebVersion = '',
  userAgent = DEFAULT_USER_AGENT,
}) {
  const authStrategy = new RemoteAuth({
    store,
    clientId: String(idInstance),
    backupSyncIntervalMs,
  });

  const puppeteer = {
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--disable-extensions',
      // Memory trim — Chromium under a 1-2g container cgroup OOM-kills itself
      // (Exited 137) during the QR/login spike. Flags below cut the renderer
      // process count and cap the V8 heap so a runaway page crashes the
      // renderer instead of the whole container. Validated against
      // whatsapp-web.js#75 and puppeteer memory threads. We deliberately do
      // NOT use --single-process: it destabilises whatsapp-web.js (closing a
      // context tears down the whole browser).
      '--no-zygote',                                     // no forked zygote helper
      '--no-first-run',
      '--disable-accelerated-2d-canvas',
      '--disable-default-apps',
      '--disable-background-networking',
      '--mute-audio',
      '--disable-features=IsolateOrigins,site-per-process,Translate', // disable site isolation → fewer renderer procs
      '--js-flags=--max-old-space-size=512',             // cap V8 heap ~512MB
      // Drops the navigator.webdriver flag that automated Chrome sets and every
      // bot check reads first. Costs nothing and does not affect behaviour.
      '--disable-blink-features=AutomationControlled',
    ],
  };
  if (executablePath || process.env.PUPPETEER_EXECUTABLE_PATH) {
    puppeteer.executablePath = executablePath || process.env.PUPPETEER_EXECUTABLE_PATH;
  }

  const options = { authStrategy, puppeteer, userAgent };

  // Pinning the web build is opt-in: an unreachable or wrong version would leave
  // the client unable to load WhatsApp at all, so an empty setting keeps the
  // library's "always latest" behaviour.
  if (waWebVersion) {
    options.webVersion = waWebVersion;
    options.webVersionCache = {
      type: 'remote',
      remotePath: `${WA_VERSION_MIRROR}/${waWebVersion}.html`,
    };
  }

  return new Client(options);
}

module.exports = { createClient };
