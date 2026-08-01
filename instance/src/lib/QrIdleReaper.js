'use strict';

/**
 * Stops the instance when a QR has sat unscanned for too long.
 *
 * WHY: whatsapp-web.js emits a fresh `qr` about every 20 seconds for as long as
 * the page stays open, and `qrMaxRetries` defaults to 0 (unlimited). An instance
 * nobody pairs therefore asks WhatsApp for a new QR ~4300 times a day, forever —
 * we had containers doing this for 60+ days. Across the fleet that is tens of
 * thousands of QR requests a day from a single egress IP, which is precisely the
 * pattern WhatsApp blocks numbers for.
 *
 * The timer runs from the FIRST qr of a session, never restarted by the 20s
 * refresh — otherwise it could be postponed indefinitely and never fire. Once
 * someone scans (authenticated/ready) it is disarmed for good.
 */
class QrIdleReaper {
  constructor({ ttlMs = 600000, logger, onExpire } = {}) {
    const parsed = Number(ttlMs);
    this.ttlMs = Number.isFinite(parsed) && parsed > 0 ? parsed : 0; // 0 disables
    this.logger = logger || console;
    this.onExpire = typeof onExpire === 'function' ? onExpire : () => {};
    this.timer = null;
    this.armedAt = 0;
    this.scanned = false;
  }

  /** Call on every `qr` event. Only the first one arms the countdown. */
  noteQr() {
    if (!this.ttlMs || this.timer || this.scanned) return;
    this.armedAt = Date.now();
    this.timer = setTimeout(() => {
      this.timer = null;
      this.logger.warn(
        { ttlMs: this.ttlMs },
        'QR unscanned past TTL — stopping instance so it stops polling WhatsApp'
      );
      Promise.resolve()
        .then(() => this.onExpire())
        .catch((err) => this.logger.error({ err: err && err.message }, 'QR idle: onExpire failed'));
    }, this.ttlMs);
    // Do not hold the event loop open on our own account; the HTTP server does that.
    if (typeof this.timer.unref === 'function') this.timer.unref();
    this.logger.info({ ttlMs: this.ttlMs }, 'QR idle timer armed');
  }

  /** Call on `authenticated` / `ready`: somebody scanned, stop counting. */
  noteScanned() {
    this.scanned = true;
    if (!this.timer) return;
    clearTimeout(this.timer);
    this.timer = null;
    this.logger.info({ waitedMs: Date.now() - this.armedAt }, 'QR scanned — idle timer disarmed');
  }

  /** Call on shutdown / client reboot so a stale timer cannot fire later. */
  stop() {
    if (!this.timer) return;
    clearTimeout(this.timer);
    this.timer = null;
  }

  /** Re-arm after a reboot that landed back on the QR screen. */
  reset() {
    this.stop();
    this.scanned = false;
    this.armedAt = 0;
  }
}

module.exports = { QrIdleReaper };
