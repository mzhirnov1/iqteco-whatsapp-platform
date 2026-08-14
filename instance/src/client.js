'use strict';

const fsp = require('fs/promises');
const path = require('path');
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

/**
 * Copy a tree the way a live Chromium profile demands: leveldb compacts while we
 * read, so files and directories appear and vanish mid-walk. fs.cp() (what
 * RemoteAuth uses) plans the whole copy up front and then throws ENOENT on a file
 * that got compacted away, or EEXIST on a directory that appeared — aborting the
 * backup and surfacing as unhandledRejection. Skipping the raced entry is correct:
 * leveldb replays its remaining log on open.
 */
async function copyTreeTolerant(src, dest) {
  let entries;
  try {
    entries = await fsp.readdir(src, { withFileTypes: true });
  } catch (err) {
    if (err.code === 'ENOENT') return; // directory compacted away under us
    throw err;
  }
  await fsp.mkdir(dest, { recursive: true }); // recursive:true is already EEXIST-safe
  for (const entry of entries) {
    const from = path.join(src, entry.name);
    const to = path.join(dest, entry.name);
    try {
      if (entry.isDirectory()) await copyTreeTolerant(from, to);
      // Sockets and lock files in a live profile are not copyable and not needed.
      else if (entry.isFile()) await fsp.copyFile(from, to);
    } catch (err) {
      if (err.code === 'ENOENT' || err.code === 'EEXIST') continue;
      throw err;
    }
  }
}

/**
 * RemoteAuth that (a) survives the live-profile copy above and (b) refuses to
 * back up a session that is not actually paired.
 *
 * (b) is what breaks the QR-loop livelock of 2026-08: a leaked backupSync from a
 * half-destroyed client kept compressing an EMPTY profile and storing it as a
 * ~1.4KB blob. onQR's watchdog reads "a stored session exists while we are still
 * on the QR screen" as a dead session being restored in a loop, so it reset the
 * session — which re-armed the QR idle reaper — every ~3 minutes, forever. The
 * instance never stopped, and nobody could pair it either: each reset killed the
 * browser and voided the Conn.ref while the UI still showed the old QR image.
 */
class ResilientRemoteAuth extends RemoteAuth {
  constructor({ canBackup, logger, ...options }) {
    super(options);
    this.canBackup = typeof canBackup === 'function' ? canBackup : () => true;
    this.log = logger || console;
  }

  async copyByRequiredDirs(from, to) {
    for (const dir of this.requiredDirs) {
      await copyTreeTolerant(path.join(from, dir), path.join(to, dir));
    }
  }

  async storeRemoteSession(options) {
    if (!this.canBackup()) {
      this.log.debug?.({ session: this.sessionName }, 'session backup skipped — not paired');
      return;
    }
    // The library calls this from a bare setInterval with no catch, so anything
    // thrown here lands as unhandledRejection instead of a diagnosable warning.
    try {
      return await super.storeRemoteSession(options);
    } catch (err) {
      this.log.warn({ err: err.message, session: this.sessionName }, 'session backup failed');
    }
  }
}

function createClient({
  store,
  idInstance,
  backupSyncIntervalMs = 60000,
  executablePath,
  waWebVersion = '',
  userAgent = DEFAULT_USER_AGENT,
  canBackup,
  logger,
}) {
  const authStrategy = new ResilientRemoteAuth({
    store,
    clientId: String(idInstance),
    backupSyncIntervalMs,
    canBackup,
    logger,
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

module.exports = { createClient, copyTreeTolerant, ResilientRemoteAuth };
