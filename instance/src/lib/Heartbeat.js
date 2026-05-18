'use strict';

const { mapWAStateToGreen } = require('./StateMap');

const STALE_STATES = new Set(['CONFLICT', 'TIMEOUT', 'UNLAUNCHED']);

class Heartbeat {
  constructor({ client, adminClient, logger, intervalMs = 30000, onConflict }) {
    this.client = client;
    this.adminClient = adminClient;
    this.logger = logger || console;
    this.intervalMs = intervalMs;
    this.onConflict = onConflict || (() => {});
    this.timer = null;
    this._busy = false;
    this._consecutiveErrors = 0;
  }

  start() {
    if (this.timer) return;
    this.timer = setInterval(() => this._tick(), this.intervalMs);
    this.timer.unref?.();
  }

  stop() {
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
  }

  async _tick() {
    if (this._busy) return;
    this._busy = true;
    try {
      const state = await this.client.getState().catch(() => null);
      const greenState = mapWAStateToGreen(state);

      await this.adminClient.heartbeat({ state: greenState, lastEventAt: Math.floor(Date.now() / 1000) })
        .catch((err) => this.logger.warn({ err: err.message }, 'Heartbeat: admin POST failed'));

      if (state == null) {
        this._consecutiveErrors++;
        if (this._consecutiveErrors >= 3) {
          this.logger.error({ state }, 'Heartbeat: state null x3, triggering onConflict');
          this._consecutiveErrors = 0;
          await this.onConflict('state_null');
        }
      } else if (STALE_STATES.has(state)) {
        this.logger.warn({ state }, 'Heartbeat: stale state, triggering onConflict');
        await this.onConflict(state);
      } else {
        this._consecutiveErrors = 0;
      }
    } catch (err) {
      this.logger.error({ err: err.message }, 'Heartbeat: tick error');
    } finally {
      this._busy = false;
    }
  }
}

module.exports = Heartbeat;
