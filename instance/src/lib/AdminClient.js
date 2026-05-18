'use strict';

const { request } = require('undici');

class AdminClient {
  constructor({ baseUrl, adminToken, idInstance, logger, timeoutMs = 10000 }) {
    if (!baseUrl) throw new Error('AdminClient: baseUrl required');
    if (!adminToken) throw new Error('AdminClient: adminToken required');
    if (!idInstance) throw new Error('AdminClient: idInstance required');
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.adminToken = adminToken;
    this.idInstance = idInstance;
    this.logger = logger || console;
    this.timeoutMs = timeoutMs;
  }

  async _req(method, path, body) {
    const url = `${this.baseUrl}/api${path}`;
    const headers = {
      'X-Admin-Token': this.adminToken,
      'Content-Type': 'application/json',
    };

    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        const res = await request(url, {
          method,
          headers,
          body: body ? JSON.stringify(body) : undefined,
          bodyTimeout: this.timeoutMs,
          headersTimeout: this.timeoutMs,
        });

        const text = await res.body.text();
        const data = text ? JSON.parse(text) : null;

        if (res.statusCode >= 500 && attempt === 0) {
          this.logger.warn({ url, status: res.statusCode }, 'AdminClient: 5xx, retry');
          await new Promise((r) => setTimeout(r, 1000));
          continue;
        }

        if (res.statusCode >= 400) {
          throw new Error(`AdminClient ${method} ${path}: ${res.statusCode} ${text}`);
        }
        return data;
      } catch (err) {
        if (attempt === 0) {
          this.logger.warn({ err: err.message }, 'AdminClient: transport error, retry');
          await new Promise((r) => setTimeout(r, 1000));
          continue;
        }
        throw err;
      }
    }
  }

  register({ pid, version, ipv6, state }) {
    return this._req('POST', `/instances/${this.idInstance}/register`, { pid, version, ipv6, state });
  }

  heartbeat({ state, lastEventAt }) {
    return this._req('POST', `/instances/${this.idInstance}/heartbeat`, { state, lastEventAt });
  }

  sendQr({ qr, expiresAt, kind = 'qr' }) {
    return this._req('POST', `/instances/${this.idInstance}/qr`, { qr, expiresAt, kind });
  }

  getConfig() {
    return this._req('GET', `/instances/${this.idInstance}/config`);
  }

  stateChange({ from, to, reason }) {
    return this._req('POST', `/instances/${this.idInstance}/state-change`, { from, to, reason });
  }
}

module.exports = AdminClient;
