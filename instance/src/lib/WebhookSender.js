'use strict';

const crypto = require('crypto');
const { request } = require('undici');

// Retry schedule: 1s, 5s, 30s, 2m, 10m, 30m, 1h x4, 2h x3, 4h x2
// Total ≈ 24 hours of automatic retries before marking 'failed'.
const BACKOFF_SECONDS = [
    1, 5, 30, 120, 600,                 // first ~13 minutes
    1800, 3600,                          // +30m, +1h
    3600, 3600, 3600, 3600,             // +4h
    7200, 7200, 7200,                   // +6h
    14400, 14400,                        // +8h
];
const MAX_ATTEMPTS = BACKOFF_SECONDS.length;  // 16 attempts ≈ 24h

class WebhookSender {
  constructor({ db, idInstance, getWebhookUrl, getWebhookSecret, logger, tickIntervalMs = 1000 }) {
    if (!db) throw new Error('WebhookSender: db required');
    this.outbox = db.collection('webhook_outbox');
    this.log = db.collection('webhook_log');
    this.idInstance = String(idInstance);
    this.getWebhookUrl = getWebhookUrl;
    this.getWebhookSecret = getWebhookSecret || (() => null);
    this.logger = logger || console;
    this.tickIntervalMs = tickIntervalMs;
    this.timer = null;
    this._busy = false;
  }

  async start() {
    await this.outbox.createIndex({ idInstance: 1, status: 1, nextAttemptAt: 1 }).catch(() => {});
    this.timer = setInterval(() => this._tick(), this.tickIntervalMs);
    this.timer.unref?.();
  }

  async stop() {
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
  }

  async drain(maxMs = 5000) {
    const deadline = Date.now() + maxMs;
    while (Date.now() < deadline) {
      const pending = await this.outbox.countDocuments({ idInstance: this.idInstance, status: 'pending' });
      if (pending === 0) return;
      await this._tick();
      await new Promise((r) => setTimeout(r, 100));
    }
  }

  /**
   * Snapshot of outbox/log state for /health and admin UI badges.
   */
  async getQueueStats() {
    const [pending, failed, lastSent] = await Promise.all([
      this.outbox.countDocuments({ idInstance: this.idInstance, status: 'pending' }),
      this.outbox.countDocuments({ idInstance: this.idInstance, status: 'failed' }),
      this.outbox.findOne(
        { idInstance: this.idInstance, status: 'sent' },
        { sort: { sentAt: -1 }, projection: { sentAt: 1 } },
      ),
    ]);
    return {
      pending,
      failed,
      lastSentAt: lastSent?.sentAt ? Math.floor(lastSent.sentAt.getTime() / 1000) : null,
      maxAttempts: MAX_ATTEMPTS,
    };
  }

  async enqueue(typeWebhook, payload) {
    const doc = {
      idInstance: this.idInstance,
      typeWebhook,
      payload,
      status: 'pending',
      attempts: 0,
      nextAttemptAt: new Date(),
      createdAt: new Date(),
    };
    const { insertedId } = await this.outbox.insertOne(doc);
    return insertedId;
  }

  async _tick() {
    if (this._busy) return;
    this._busy = true;
    try {
      const now = new Date();
      const items = await this.outbox.find({
        idInstance: this.idInstance,
        status: 'pending',
        nextAttemptAt: { $lte: now },
      }).limit(10).toArray();

      for (const item of items) {
        await this._send(item);
      }
    } catch (err) {
      this.logger.error({ err: err.message }, 'WebhookSender: tick error');
    } finally {
      this._busy = false;
    }
  }

  async _send(item) {
    const url = await this.getWebhookUrl();
    if (!url) {
      // no webhook configured — drop after marking as skipped
      await this.outbox.updateOne({ _id: item._id }, { $set: { status: 'skipped', updatedAt: new Date() } });
      return;
    }

    const body = JSON.stringify(item.payload);
    const headers = { 'Content-Type': 'application/json' };
    const secret = await this.getWebhookSecret();
    if (secret) {
      const sig = crypto.createHmac('sha256', secret).update(body).digest('hex');
      headers['X-Webhook-Signature'] = `sha256=${sig}`;
    }

    try {
      const res = await request(url, {
        method: 'POST',
        headers,
        body,
        bodyTimeout: 10000,
        headersTimeout: 10000,
      });
      const text = await res.body.text().catch(() => '');

      if (res.statusCode >= 200 && res.statusCode < 300) {
        await this.outbox.updateOne({ _id: item._id }, { $set: { status: 'sent', sentAt: new Date(), httpCode: res.statusCode } });
        await this.log.insertOne({
          idInstance: this.idInstance,
          type: item.typeWebhook,
          payload: item.payload,
          sentAt: new Date(),
          status: 'sent',
          httpCode: res.statusCode,
          attempts: item.attempts + 1,
        }).catch(() => {});
        return;
      }

      throw new Error(`HTTP ${res.statusCode}: ${text.slice(0, 200)}`);
    } catch (err) {
      const attempts = (item.attempts ?? 0) + 1;
      if (attempts >= MAX_ATTEMPTS) {
        await this.outbox.updateOne({ _id: item._id }, {
          $set: { status: 'failed', attempts, lastError: err.message, updatedAt: new Date() },
        });
        await this.log.insertOne({
          idInstance: this.idInstance,
          type: item.typeWebhook,
          payload: item.payload,
          sentAt: new Date(),
          status: 'failed',
          attempts,
          error: err.message,
        }).catch(() => {});
        this.logger.error({ id: item._id, type: item.typeWebhook, err: err.message }, 'WebhookSender: gave up');
      } else {
        const delaySec = BACKOFF_SECONDS[attempts - 1] || 600;
        await this.outbox.updateOne({ _id: item._id }, {
          $set: {
            attempts,
            nextAttemptAt: new Date(Date.now() + delaySec * 1000),
            lastError: err.message,
            updatedAt: new Date(),
          },
        });
        this.logger.warn({ id: item._id, attempts, delaySec, err: err.message }, 'WebhookSender: retry scheduled');
      }
    }
  }
}

module.exports = WebhookSender;
