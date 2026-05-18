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
    ],
  };
  if (executablePath || process.env.PUPPETEER_EXECUTABLE_PATH) {
    puppeteer.executablePath = executablePath || process.env.PUPPETEER_EXECUTABLE_PATH;
  }

  return new Client({ authStrategy, puppeteer });
}

module.exports = { createClient };
