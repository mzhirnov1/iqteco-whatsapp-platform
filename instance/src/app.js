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
  const store = new MongoStore({ db, idInstance: config.idInstance, dataPath: './.wwebjs_auth/' });

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
  let client = createClient({ store, idInstance: config.idInstance, backupSyncIntervalMs: config.backupIntervalMs });

  const ctx = {
    config, logger, db, adminClient, adminConfig, webhookSender, mapper,
    mediaStore, messageStore,
    qrCache, codeCache, outgoingApiIds, state,
    store, qrWatch,
    get client() { return client; },
    rebootClient, resetSession,
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
  });
  heartbeat.start();

  // 10. Shutdown
  let shuttingDown = false;
  async function shutdown(signal) {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info({ signal }, 'shutdown begin');
    heartbeat.stop();
    server.close();
    try { await webhookSender.drain(3000); } catch {}
    try { await webhookSender.stop(); } catch {}
    try { await client.destroy(); } catch {}
    try { await mongo.close(); } catch {}
    logger.info('shutdown complete');
    process.exit(0);
  }
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));

  function attachEvents() {
    client.on('qr', onQR(ctx));
    client.on('code', onCode(ctx));
    client.on('ready', onReady(ctx));
    client.on('authenticated', () => logger.info('client authenticated'));
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
  async function cleanLocalSession() {
    const base = path.resolve('./.wwebjs_auth');
    for (const d of ['RemoteAuth-' + config.idInstance, 'wwebjs_temp_session_' + config.idInstance]) {
      try { await fs.promises.rm(path.join(base, d), { recursive: true, force: true, maxRetries: 4 }); }
      catch (err) { logger.warn({ err: err.message, dir: d }, 'cleanLocalSession: rm failed'); }
    }
  }
  async function resetSession(reason) {
    const now = Date.now();
    if (now - _lastResetAt < 120000) { logger.warn({ reason }, 'resetSession skipped (debounced)'); return; }
    _lastResetAt = now;
    logger.warn({ reason }, 'resetSession: clearing dead session');
    try { await client.destroy(); } catch (err) { logger.warn({ err: err.message }, 'resetSession: destroy failed'); }
    try { await store.delete({ session: 'RemoteAuth-' + config.idInstance }); }
    catch (err) { logger.warn({ err: err.message }, 'resetSession: store.delete failed'); }
    await cleanLocalSession();
    try { await adminClient.stateChange({ from: state.lastState, to: 'notAuthorized', reason: 'needs_relink:' + reason }); } catch { /* ignore */ }
    state.lastState = 'notAuthorized';
    state.authorized = false;
    qrWatch.streak = 0;
    client = createClient({ store, idInstance: config.idInstance, backupSyncIntervalMs: config.backupIntervalMs });
    attachEvents();
    try { await client.initialize(); } catch (err) { logger.error({ err: err.message }, 'resetSession: re-initialize failed'); }
  }

  async function rebootClient(reason) {
    logger.warn({ reason }, 'reboot triggered');
    try {
      await client.destroy();
    } catch (err) {
      logger.warn({ err: err.message }, 'reboot: destroy failed');
    }
    client = createClient({ store, idInstance: config.idInstance, backupSyncIntervalMs: config.backupIntervalMs });
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
