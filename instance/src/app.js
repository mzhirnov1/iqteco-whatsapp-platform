'use strict';

const express = require('express');
const { MongoClient } = require('mongodb');

const config = require('./config');
const { createLogger } = require('./lib/Logger');
const MongoStore = require('./lib/MongoStore');
const MediaStore = require('./lib/MediaStore');
const MessageStore = require('./lib/MessageStore');
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

  // 3b. Media + message stores
  const mediaStore = new MediaStore({ db, idInstance: config.idInstance });
  await mediaStore.ensureTtl();
  const messageStore = new MessageStore({ db, idInstance: config.idInstance });
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
  const outgoingApiIds = new Set();

  // 6. WhatsApp client
  let client = createClient({ store, idInstance: config.idInstance, backupSyncIntervalMs: config.backupIntervalMs });

  const ctx = {
    config, logger, db, adminClient, adminConfig, webhookSender, mapper,
    mediaStore, messageStore,
    qrCache, codeCache, outgoingApiIds, state,
    get client() { return client; },
    rebootClient,
  };

  attachEvents();
  await client.initialize().catch((err) => logger.error({ err: err.message }, 'client.initialize failed'));

  // 7. HTTP server
  const app = express();
  app.use(express.json({ limit: '50mb' }));
  app.get('/health', (_req, res) => res.json({
    status: 'ok',
    idInstance: config.idInstance,
    state: state.lastState,
    authorized: state.authorized,
    wid: state.wid,
    uptime: process.uptime(),
    version: config.version,
  }));
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
    client,
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
