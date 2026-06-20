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
    // Reset if a session artifact still exists in EITHER place: the GridFS blob OR a
    // local session dir. Checking only the blob meant a partial reset (blob deleted,
    // local rm failed under file locks) disarmed the watchdog and the client QR-looped
    // forever on the surviving corrupt local profile.
    Promise.all([
      ctx.store.sessionExists({ session: 'RemoteAuth-' + ctx.config.idInstance }).catch(() => false),
      typeof ctx.localSessionExists === 'function' ? ctx.localSessionExists().catch(() => false) : Promise.resolve(false),
    ]).then(([blobExists, localExists]) => {
      if (blobExists || localExists) return ctx.resetSession('qr_loop');
    }).catch(() => {});
  }
  ctx.logger.info('onQR: new QR received');
};
