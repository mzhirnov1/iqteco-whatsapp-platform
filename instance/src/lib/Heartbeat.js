'use strict';

const { mapWAStateToGreen } = require('./StateMap');

const STALE_STATES = new Set(['CONFLICT', 'TIMEOUT', 'UNLAUNCHED']);

class Heartbeat {
  constructor({ getClient, client, adminClient, logger, intervalMs = 30000, onConflict, onConnectedNotReady, maxStaleReboots = 5 }) {
    if (typeof getClient === 'function') {
      this.getClient = getClient;
    } else if (client) {
      this.getClient = () => client;
    } else {
      throw new Error('Heartbeat: getClient or client required');
    }
    this.adminClient = adminClient;
    this.logger = logger || console;
    this.intervalMs = intervalMs;
    this.onConflict = onConflict || (() => {});
    // wweb.js 942d236 may swallow the 'ready' event on a RemoteAuth restore
    // (its duplicate-ready guard arms during the post-login SPA navigation).
    // The session is CONNECTED, but ctx.state.authorized never flips, and every
    // API route answers 466. The heartbeat already polls the true state each tick,
    // so it is the natural place to notice "connected but not marked ready".
    this.onConnectedNotReady = onConnectedNotReady || null;
    // Circuit breaker: a logged-out / corrupt session is NOT fixable by more
    // reboots — it needs a manual QR re-scan. Past this many consecutive
    // stale-state reboots we STOP calling onConflict and just keep
    // heartbeating state, so the container parks quietly instead of hammering
    // re-link (which WhatsApp reads as automation and bans for). Mirrors the
    // external wa-watchdog MAX_PER_HOUR give-up. Resets on any healthy state.
    this.maxStaleReboots = maxStaleReboots;
    this.timer = null;
    this._busy = false;
    this._consecutiveErrors = 0;
    this._staleReboots = 0;
    this._parked = false;
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
      const client = this.getClient();
      const state = await client.getState().catch((err) => {
        this.logger.warn({ err: err?.message }, 'Heartbeat: getState threw');
        return null;
      });
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
        if (this._staleReboots >= this.maxStaleReboots) {
          // Give up: parked, needs manual QR. Stop churning re-link attempts.
          if (!this._parked) {
            this._parked = true;
            this.logger.error({ state, reboots: this._staleReboots },
              'Heartbeat: stale after max reboots — PARKED, needs manual QR (reboot churn stopped)');
          }
        } else {
          this._staleReboots++;
          this.logger.warn({ state, reboot: this._staleReboots },
            'Heartbeat: stale state, triggering onConflict');
          await this.onConflict(state);
        }
      } else {
        this._consecutiveErrors = 0;
        if (this._staleReboots || this._parked) {
          this.logger.info({ state }, 'Heartbeat: healthy again — reboot circuit reset');
        }
        this._staleReboots = 0;
        this._parked = false;
        if (state === 'CONNECTED' && this.onConnectedNotReady) {
          try { await this.onConnectedNotReady(); }
          catch (err) { this.logger.warn({ err: err?.message }, 'Heartbeat: onConnectedNotReady failed'); }
        }
      }
    } catch (err) {
      this.logger.error({ err: err.message }, 'Heartbeat: tick error');
    } finally {
      this._busy = false;
    }
  }
}

module.exports = Heartbeat;
