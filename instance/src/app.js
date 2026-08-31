'use strict';

const express = require('express');
const { MongoClient } = require('mongodb');
const fs = require('fs');
const path = require('path');

const config = require('./config');
const { createLogger } = require('./lib/Logger');
const MongoStore = require('./lib/MongoStore');
const MediaStore = require('./lib/MediaStore');
const MessageStore = require('./lib/MessageStore');
const { makeS3Client } = require('./lib/S3Client');
const AdminClient = require('./lib/AdminClient');
const WebhookSender = require('./lib/WebhookSender');
const GreenApiMapper = require('./lib/GreenApiMapper');
const Heartbeat = require('./lib/Heartbeat');
const { QrIdleReaper } = require('./lib/QrIdleReaper');
const { createClient } = require('./client');
const { mountRoutes } = require('./routes');

const onQR = require('./events/onQR');
const onCode = require('./events/onCode');
const onReady = require('./events/onReady');
const onAuthFailure = require('./events/onAuthFailure');
const onDisconnected = require('./events/onDisconnected');
const onMessage = require('./events/onMessage');
const onMessageCreate = require('./events/onMessageCreate');
const onMessageAck = require('./events/onMessageAck');
const onChangeState = require('./events/onChangeState');
const onMessageEdit = require('./events/onMessageEdit');
const onMessageRevoke = require('./events/onMessageRevoke');
const onIncomingCall = require('./events/onIncomingCall');
const onGroupEvent = require('./events/onGroupEvent');
const onContactChanged = require('./events/onContactChanged');
const onVoteUpdate = require('./events/onVoteUpdate');
const onBatteryChanged = require('./events/onBatteryChanged');

const logger = createLogger(config.logLevel);

async function main() {
  logger.info({ idInstance: config.idInstance, version: config.version, ipv6: config.ipv6Addr }, 'wa-instance starting');

  // 1. MongoDB
  const mongo = new MongoClient(config.mongoUrl, { serverSelectionTimeoutMS: 10000 });
  await mongo.connect();
  const db = mongo.db();
  logger.info({ db: db.databaseName }, 'mongo connected');

  // 2. Admin config
  const adminClient = new AdminClient({
    baseUrl: config.adminUrl,
    adminToken: config.adminToken,
    idInstance: config.idInstance,
    logger,
  });

  let adminConfig = { webhookUrl: config.webhookUrl, webhookSecret: null, settings: {} };
  try {
    const remote = await adminClient.getConfig();
    if (remote) {
      adminConfig.webhookUrl = remote.webhookUrl || adminConfig.webhookUrl;
      adminConfig.webhookSecret = remote.webhookSecret || null;
      adminConfig.settings = remote.settings || {};
    }
  } catch (err) {
    logger.warn({ err: err.message }, 'admin getConfig failed — continuing with env fallback');
  }

  // 3. Session store
  const store = new MongoStore({
    db,
    idInstance: config.idInstance,
    dataPath: './.wwebjs_auth/',
    minSaveBytes: config.sessionMinBytes,
    logger,
  });

  // 3b. Media (Wasabi S3) + message stores
  const s3 = makeS3Client(config.s3);
  const mediaStore = new MediaStore({
    s3,
    bucket: config.s3.bucket,
    keyPrefix: config.s3.keyPrefix,
    idInstance: config.idInstance,
    logger,
  });
  mediaStore.checkReachable()
    .then(() => logger.info({ bucket: config.s3.bucket }, 's3 bucket reachable'))
    .catch((err) => logger.error({ err: err.message, bucket: config.s3.bucket }, 's3 bucket unreachable — media uploads will fail'));
  const messageStore = new MessageStore({ db, idInstance: config.idInstance, ttlDays: config.messagesTtlDays });
  await messageStore.ensureIndexes();

  // 4. Webhook sender
  const webhookSender = new WebhookSender({
    db,
    idInstance: config.idInstance,
    getWebhookUrl: () => adminConfig.webhookUrl,
    getWebhookSecret: () => adminConfig.webhookSecret,
    logger,
  });
  await webhookSender.start();

  // 5. Mapper + state
  const state = { authorized: false, lastState: 'starting', wid: null };
  const mapper = new GreenApiMapper({
    idInstance: config.idInstance,
    apiToken: config.apiToken,
    getWid: () => state.wid,
    mediaBaseUrl: config.mediaBaseUrl,
  });
  const qrCache = { qr: null, pngBase64: null, expiresAt: 0 };
  const codeCache = { code: null, expiresAt: 0 };
  const qrWatch = { streak: 0 }; // consecutive QRs without ready -> dead-session watchdog
  const outgoingApiIds = new Set();

  // 6. WhatsApp client
  function spawnClient() {
    return createClient({
      store,
      idInstance: config.idInstance,
      backupSyncIntervalMs: config.backupIntervalMs,
      waWebVersion: config.waWebVersion,
      // Only a paired client has a session worth storing. Without this gate a
      // backup taken between pairings writes an empty archive, and an empty
      // archive is what onQR's watchdog mistakes for a dead session to reset.
      canBackup: () => state.authorized,
      logger,
    });
  }
  let client = spawnClient();

  const ctx = {
    config, logger, db, adminClient, adminConfig, webhookSender, mapper,
    mediaStore, messageStore,
    qrCache, codeCache, outgoingApiIds, state,
    store, qrWatch,
    get client() { return client; },
    rebootClient, resetSession, localSessionExists,
  };

  // Pre-clean orphaned backup-temp from an interrupted storeRemoteSession
  // (root of the EEXIST in RemoteAuth.compressSession). RemoteAuth-<id> is kept.
  try {
    await fs.promises.rm(path.resolve('./.wwebjs_auth/wwebjs_temp_session_' + config.idInstance),
      { recursive: true, force: true, maxRetries: 4 });
  } catch { /* ignore */ }

  attachEvents();
  await client.initialize().catch((err) => logger.error({ err: err.message }, 'client.initialize failed'));

  // 7. HTTP server
  const app = express();
  app.use(express.json({ limit: '50mb' }));
  app.get('/health', async (_req, res) => {
    const queue = await webhookSender.getQueueStats().catch(() => null);
    res.json({
      status: 'ok',
      idInstance: config.idInstance,
      state: state.lastState,
      authorized: state.authorized,
      wid: state.wid,
      uptime: process.uptime(),
      version: config.version,
      queue,
    });
  });
  mountRoutes(app, ctx);

  const server = app.listen(config.httpPort, '::', () => {
    logger.info({ port: config.httpPort }, 'HTTP listening');
  });

  // 8. Register with admin
  try {
    await adminClient.register({
      pid: process.pid,
      version: config.version,
      ipv6: config.ipv6Addr,
      state: state.lastState,
    });
    logger.info('registered with admin');
  } catch (err) {
    logger.warn({ err: err.message }, 'admin register failed');
  }

  // 9. Heartbeat
  const heartbeat = new Heartbeat({
    getClient: () => client,
    adminClient,
    logger,
    onConflict: () => rebootClient('heartbeat_conflict'),
    // Сессия жива (CONNECTED), а 'ready' так и не пришёл — новый wweb.js
    // умеет проглатывать его на RemoteAuth-восстановлении. Достраиваем сами:
    // без этого каждый маршрут отвечает 466 при честно работающем WhatsApp.
    onConnectedNotReady: async () => {
      if (state.authorized || !ctx.handleReady) return;
      logger.warn('Heartbeat: CONNECTED but ready never fired — invoking onReady by hand');
      await ctx.handleReady();
    },
  });
  heartbeat.start();

  // 9a. QR idle reaper — an unpaired instance must not sit there asking WhatsApp
  // for a fresh QR every 20s indefinitely. Stopping the container is safe: the
  // platform starts it again the moment someone asks to see the QR.
  const qrReaper = new QrIdleReaper({
    ttlMs: config.qrIdleTtlMs,
    hardTtlMs: config.qrHardTtlMs,
    logger,
    onExpire: async () => {
      try {
        await adminClient.stateChange({
          from: state.lastState,
          to: 'stopped',
          reason: 'qr_idle_timeout',
        });
      } catch (err) {
        logger.warn({ err: err.message }, 'QR idle: could not notify admin');
      }
      await shutdown('qr_idle_timeout');
    },
  });

  // 9b. First QR, without waiting for WhatsApp Web's next rotation.
  //
  // whatsapp-web.js emits an "initial qr" the moment injection finishes, but that
  // one never reaches us: measured on three cold boots, the first 'qr' event lands
  // 59.95s after injection — every time, to a tenth of a second — and only then do
  // codes arrive every 20s as normal. The page itself is ready long before that:
  // diagnostics showed a valid Conn.ref (102 chars) with the socket UNPAIRED right
  // after initialize(). So the code exists; nothing is delivering it. Compose it
  // from the same ref and key material the library uses and emit it ourselves,
  // which turns a minute of spinner into a couple of seconds. Runs after the reaper
  // exists because the 'qr' handler arms it.
  try {
    const initialQr = await client.pupPage.evaluate(async () => {
      const state = window.require('WAWebSocketModel').Socket.state;
      if (state !== 'UNPAIRED' && state !== 'UNPAIRED_IDLE') return null;
      const ref = window.require('WAWebConnModel').Conn.ref;
      if (!ref) return null;
      const registrationInfo = await window.require('WAWebSignalStoreApi').waSignalStore.getRegistrationInfo();
      const noiseKeyPair = await window.require('WAWebUserPrefsInfoStore').waNoiseInfo.get();
      const b64 = window.require('WABase64');
      return [
        ref,
        b64.encodeB64(noiseKeyPair.staticKeyPair.pubKey),
        b64.encodeB64(registrationInfo.identityKeyPair.pubKey),
        window.require('WAWebUserPrefsMultiDevice').getADVSecretKey(),
        window.require('WAWebCompanionRegClientUtils').DEVICE_PLATFORM,
      ].join(',');
    });
    if (initialQr) {
      client.emit('qr', initialQr);
      logger.info('emitted initial QR from the page ref');
    }
  } catch (err) {
    logger.warn({ err: err.message }, 'initial QR emit failed');
  }

  // 10. Shutdown
  let shuttingDown = false;
  async function shutdown(signal) {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info({ signal }, 'shutdown begin');
    heartbeat.stop();
    qrReaper.stop();
    server.close();
    try { await webhookSender.drain(3000); } catch {}
    try { await webhookSender.stop(); } catch {}
    await disposeClient(client, 'shutdown');
    try { await mongo.close(); } catch {}
    logger.info('shutdown complete');
    process.exit(0);
  }
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));

  function attachEvents() {
    const handleQr = onQR(ctx);
    const handleReady = onReady(ctx);
    ctx.handleReady = handleReady; // heartbeat достраивает пропущенный 'ready'
    client.on('qr', (qr) => { qrReaper.noteQr(); return handleQr(qr); });
    client.on('code', onCode(ctx));
    client.on('ready', (...args) => { qrReaper.noteScanned(); return handleReady(...args); });
    client.on('authenticated', () => { qrReaper.noteScanned(); logger.info('client authenticated'); });
    client.on('auth_failure', onAuthFailure(ctx));
    client.on('disconnected', onDisconnected(ctx));
    client.on('message', onMessage(ctx));
    client.on('message_create', onMessageCreate(ctx));
    client.on('message_ack', onMessageAck(ctx));
    client.on('change_state', onChangeState(ctx));
    client.on('message_edit', onMessageEdit(ctx));
    client.on('message_revoke_everyone', onMessageRevoke(ctx));
    client.on('call', onIncomingCall(ctx));
    client.on('group_join', onGroupEvent(ctx, 'groupJoin'));
    client.on('group_leave', onGroupEvent(ctx, 'groupLeave'));
    client.on('group_update', onGroupEvent(ctx, 'groupUpdate'));
    client.on('contact_changed', onContactChanged(ctx));
    client.on('vote_update', onVoteUpdate(ctx));
    client.on('battery_changed', onBatteryChanged(ctx));
    client.on('loading_screen', (percent, message) => logger.info({ percent, message }, 'loading_screen'));
    client.on('remote_session_saved', () => logger.info('remote_session_saved'));
  }

  // Self-heal a dead session: stop the client (clears RemoteAuth backupSync),
  // delete the corrupt GridFS blob + local profile, bring up a clean client (fresh QR).
  let _lastResetAt = 0;
  function sessionDirs() {
    const base = path.resolve('./.wwebjs_auth');
    return ['RemoteAuth-' + config.idInstance, 'wwebjs_temp_session_' + config.idInstance]
      .map((d) => path.join(base, d));
  }
  // True if ANY local session artifact survives. The onQR watchdog uses this so a
  // local-only corruption (blob already deleted, but rm failed under file locks)
  // still self-heals instead of QR-looping forever.
  async function localSessionExists() {
    for (const full of sessionDirs()) {
      try { await fs.promises.access(full); return true; } catch { /* gone */ }
    }
    return false;
  }
  // Remove local session dirs, verifying each is actually gone. leveldb locks held
  // by a not-yet-dead Chromium make the first rm fail (ENOTEMPTY/EBUSY); retry a few
  // times so a half-removed (corrupt) profile never survives the reset.
  async function cleanLocalSession() {
    for (const full of sessionDirs()) {
      for (let attempt = 0; attempt < 4; attempt++) {
        try { await fs.promises.rm(full, { recursive: true, force: true, maxRetries: 4 }); }
        catch (err) { logger.warn({ err: err.message, dir: full, attempt }, 'cleanLocalSession: rm failed'); }
        try { await fs.promises.access(full); } catch { break; } // confirmed gone
        await new Promise((r) => setTimeout(r, 1500)); // give the OS time to release locks
      }
    }
  }
  // Retire a client for good. Client.destroy() runs `await browser.close()` BEFORE
  // `authStrategy.destroy()`, so the common 'Target closed' throw skips the
  // clearInterval and leaves RemoteAuth's backupSync ticking against a client
  // nobody owns any more. Those orphans are what kept re-creating the empty
  // session blob (2026-08) and what made one 'authenticated' arrive four times.
  // Kill the interval and the listeners explicitly, never trusting destroy().
  async function disposeClient(prev, tag) {
    if (!prev) return;
    try { await prev.destroy(); }
    catch (err) { logger.warn({ err: err.message }, tag + ': destroy failed'); }
    try { await prev.authStrategy?.destroy?.(); }
    catch (err) { logger.warn({ err: err.message }, tag + ': authStrategy.destroy failed'); }
    // A browser that survived destroy() holds leveldb locks, so cleanLocalSession's
    // rm fails and the corrupt profile outlives the reset.
    try {
      const proc = prev.pupBrowser && typeof prev.pupBrowser.process === 'function'
        ? prev.pupBrowser.process() : null;
      if (proc && !proc.killed) { proc.kill('SIGKILL'); logger.warn(tag + ': SIGKILL stray browser'); }
    } catch (err) { logger.warn({ err: err.message }, tag + ': browser kill failed'); }
    try { prev.removeAllListeners(); } catch { /* ignore */ }
  }

  async function resetSession(reason) {
    const now = Date.now();
    if (now - _lastResetAt < 120000) { logger.warn({ reason }, 'resetSession skipped (debounced)'); return; }
    _lastResetAt = now;
    logger.warn({ reason }, 'resetSession: clearing dead session');
    await disposeClient(client, 'resetSession');
    try { await store.delete({ session: 'RemoteAuth-' + config.idInstance }); }
    catch (err) { logger.warn({ err: err.message }, 'resetSession: store.delete failed'); }
    await cleanLocalSession();
    try { await adminClient.stateChange({ from: state.lastState, to: 'notAuthorized', reason: 'needs_relink:' + reason }); } catch { /* ignore */ }
    state.lastState = 'notAuthorized';
    state.authorized = false;
    qrWatch.streak = 0;
    // Back to square one: the next QR must start a fresh idle countdown, or a
    // once-paired instance would keep emitting QRs forever after being unlinked.
    qrReaper.reset();
    client = spawnClient();
    attachEvents();
    try { await client.initialize(); } catch (err) { logger.error({ err: err.message }, 'resetSession: re-initialize failed'); }
  }

  async function rebootClient(reason) {
    logger.warn({ reason }, 'reboot triggered');
    await disposeClient(client, 'reboot');
    // A reboot that lands back on the QR screen means the session did not
    // survive; restart the countdown so it cannot idle there indefinitely.
    qrReaper.reset();
    client = spawnClient();
    attachEvents();
    try {
      await client.initialize();
    } catch (err) {
      logger.error({ err: err.message }, 'reboot: re-initialize failed');
    }
  }
}

main().catch((err) => {
  logger.fatal({ err: err.message, stack: err.stack }, 'fatal');
  process.exit(1);
});

process.on('unhandledRejection', (err) => logger.error({ err: err?.message, stack: err?.stack }, 'unhandledRejection'));
process.on('uncaughtException', (err) => {
  logger.fatal({ err: err.message, stack: err.stack }, 'uncaughtException');
  process.exit(1);
});
