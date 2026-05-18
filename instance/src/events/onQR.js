'use strict';

const qrcode = require('qrcode');

module.exports = (ctx) => async (qr) => {
  const expiresAt = Math.floor(Date.now() / 1000) + 60;
  ctx.qrCache.qr = qr;
  ctx.qrCache.expiresAt = expiresAt;

  try {
    const pngDataUrl = await qrcode.toDataURL(qr, { errorCorrectionLevel: 'M', margin: 1 });
    ctx.qrCache.pngBase64 = pngDataUrl.replace(/^data:image\/png;base64,/, '');
  } catch (err) {
    ctx.logger.warn({ err: err.message }, 'onQR: failed to render PNG');
  }

  try {
    await ctx.adminClient.sendQr({ qr, expiresAt, kind: 'qr' });
  } catch (err) {
    ctx.logger.warn({ err: err.message }, 'onQR: failed to notify admin');
  }
  ctx.logger.info('onQR: new QR received');
};
