'use strict';

module.exports = (ctx) => async () => {
  ctx.state.authorized = true;
  ctx.qrCache.qr = null;
  ctx.qrCache.pngBase64 = null;
  ctx.qrCache.expiresAt = 0;
  ctx.codeCache.code = null;
  ctx.codeCache.expiresAt = 0;

  try {
    ctx.state.wid = ctx.client.info?.wid?._serialized || null;
  } catch {
    // ignore
  }

  try {
    await ctx.adminClient.stateChange({ from: ctx.state.lastState, to: 'authorized', reason: 'ready' });
  } catch (err) {
    ctx.logger.warn({ err: err.message }, 'onReady: admin notify failed');
  }
  ctx.state.lastState = 'authorized';

  await ctx.webhookSender.enqueue('stateInstanceChanged',
    ctx.mapper.toStateInstanceChanged('CONNECTED'));

  ctx.logger.info({ wid: ctx.state.wid }, 'onReady: client authorized');
};
