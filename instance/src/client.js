'use strict';

const { Client, RemoteAuth } = require('whatsapp-web.js');

function createClient({ store, idInstance, backupSyncIntervalMs = 60000, executablePath }) {
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
    ],
  };
  if (executablePath || process.env.PUPPETEER_EXECUTABLE_PATH) {
    puppeteer.executablePath = executablePath || process.env.PUPPETEER_EXECUTABLE_PATH;
  }

  return new Client({ authStrategy, puppeteer });
}

module.exports = { createClient };
