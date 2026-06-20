'use strict';

const qrcode = require('qrcode');

module.exports = (ctx) => async (qr) => {
  const expiresAt = Math.floor(Date.now() / 1000) + 60;
  ctx.qrCache.qr = qr;
  ctx.qrCache.expiresAt = expiresAt;

  try {
    const pngDataUrl = await qrcode.toDataURL(qr, { errorCorrectionLevel: 'M', margin: 1, width: 280 });
    ctx.qrCache.pngBase64 = pngDataUrl.replace(/^data:image\/png;base64,/, '');
  } catch (err) {
    ctx.logger.warn({ err: err.message }, 'onQR: failed to render PNG');
  }

  try {
    // Admin UI renders <img src="data:image/png;base64,..."> so send the PNG, not the raw qr string.
    await ctx.adminClient.sendQr({ qr: ctx.qrCache.pngBase64 || qr, expiresAt, kind: 'qr' });
  } catch (err) {
    ctx.logger.warn({ err: err.message }, 'onQR: failed to notify admin');
  }
  // QR-loop watchdog: repeated QRs while a session blob still EXISTS means restore
  // keeps loading a DEAD session in a loop. Reset it once for a clean re-pair
  // (instead of looping for days). After reset the blob is gone, so this won't refire.
  ctx.qrWatch.streak = (ctx.qrWatch.streak || 0) + 1;
  if (ctx.qrWatch.streak >= 4 && typeof ctx.resetSession === 'function' && ctx.store) {
    ctx.qrWatch.streak = 0;
    // Reset only when a STORED session blob exists: that means restore keeps loading a
    // DEAD session in a loop. A freshly-(re)booted, never-paired instance also emits
    // repeated QRs while waiting for the first scan and has a local session dir, so
    // keying on the local dir here would wipe a perfectly good waiting-to-scan QR every
    // couple minutes (never lets the user pair). The real local-corruption gap is fixed
    // by resetSession now killing the browser + verifying cleanup, not by this watchdog.
    ctx.store.sessionExists({ session: 'RemoteAuth-' + ctx.config.idInstance })
      .then((exists) => { if (exists) return ctx.resetSession('qr_loop'); })
      .catch(() => {});
  }
  ctx.logger.info('onQR: new QR received');
};
