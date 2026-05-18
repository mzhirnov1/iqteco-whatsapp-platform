'use strict';

module.exports = (ctx) => async (code) => {
  const expiresAt = Math.floor(Date.now() / 1000) + 180;
  ctx.codeCache.code = code;
  ctx.codeCache.expiresAt = expiresAt;
  try {
    await ctx.adminClient.sendQr({ qr: code, expiresAt, kind: 'pairing_code' });
  } catch (err) {
    ctx.logger.warn({ err: err.message }, 'onCode: failed to notify admin');
  }
  ctx.logger.info({ code: code.slice(0, 2) + '***' }, 'onCode: new pairing code');
};
