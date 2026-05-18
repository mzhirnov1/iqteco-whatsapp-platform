'use strict';

const pino = require('pino');

const SENSITIVE_KEYS = ['apiToken', 'API_TOKEN', 'apiTokenInstance', 'adminToken', 'ADMIN_TOKEN', 'webhookSecret', 'password'];

function mask(value) {
  if (!value || typeof value !== 'string') return value;
  if (value.length <= 8) return '***';
  return value.slice(0, 4) + '***' + value.slice(-2);
}

function createLogger(level) {
  return pino({
    level: level || 'info',
    redact: {
      paths: SENSITIVE_KEYS.flatMap(k => [k, `*.${k}`, `*.*.${k}`]),
      censor: (v) => mask(v),
    },
    timestamp: pino.stdTimeFunctions.isoTime,
    base: { service: 'wa-instance' },
  });
}

module.exports = { createLogger, mask };
