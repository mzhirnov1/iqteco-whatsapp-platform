'use strict';

function required(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Missing required env variable: ${name}`);
  }
  return value;
}

function optional(name, defaultValue) {
  const value = process.env[name];
  return value === undefined || value === '' ? defaultValue : value;
}

const config = {
  idInstance: required('IDINSTANCE'),
  apiToken: required('API_TOKEN'),
  mongoUrl: required('MONGO_URL'),
  adminUrl: required('ADMIN_URL'),
  adminToken: required('ADMIN_TOKEN'),
  webhookUrl: optional('WEBHOOK_URL', ''),
  ipv6Addr: optional('IPV6_ADDR', ''),
  logLevel: optional('LOG_LEVEL', 'info'),
  backupIntervalMs: Number(optional('BACKUP_INTERVAL_MS', 60000)),
  httpPort: Number(optional('HTTP_PORT', 8080)),
  version: optional('npm_package_version', '0.1.0'),
  mediaBaseUrl: optional('MEDIA_BASE_URL', ''),
  mediaMaxBytes: Number(optional('MEDIA_MAX_BYTES', 50 * 1024 * 1024)),
};

module.exports = config;
