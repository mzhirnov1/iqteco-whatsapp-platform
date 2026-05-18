'use strict';

const express = require('express');
const config = require('./config');
const { createLogger } = require('./lib/Logger');

const logger = createLogger(config.logLevel);

const app = express();
app.use(express.json({ limit: '50mb' }));

app.get('/health', (_req, res) => {
  res.json({
    status: 'ok',
    idInstance: config.idInstance,
    version: config.version,
    ipv6: config.ipv6Addr,
    uptime: process.uptime(),
  });
});

app.get('/waInstance:idInstance/:method/:token', (req, res) => {
  res.status(501).json({ error: 'NotImplemented', method: req.params.method });
});

app.post('/waInstance:idInstance/:method/:token', (req, res) => {
  res.status(501).json({ error: 'NotImplemented', method: req.params.method });
});

const server = app.listen(config.httpPort, '0.0.0.0', () => {
  logger.info({ port: config.httpPort, idInstance: config.idInstance }, 'wa-instance HTTP server started');
});

function shutdown(signal) {
  logger.info({ signal }, 'shutting down');
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(1), 10000).unref();
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
process.on('unhandledRejection', (err) => logger.error({ err }, 'unhandledRejection'));
process.on('uncaughtException', (err) => {
  logger.fatal({ err }, 'uncaughtException');
  process.exit(1);
});
